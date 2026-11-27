<?php

namespace Kanboard\Plugin\ModMenu\Model;

/**
 * The rules for ModMenu's private directory and lock files: a real directory
 * (0700) or regular file (0600), owned by the PHP user, closed to group and
 * world, never a symlink. Callers pass exception factories: $damaged for a
 * path that breaks a rule, $failed for one that could not be created.
 */
final class SecureFile
{
    /**
     * lstat() of $path, or null when absent.
     *
     * @param callable(): \Throwable $damaged
     */
    public static function inspect(string $path, bool $isDir, callable $damaged): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        if ($stat === false) {
            return null;
        }

        $type = $stat['mode'] & 0170000;

        if ($type !== ($isDir ? 0040000 : 0100000)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || ($stat['mode'] & 0077) !== 0) {
            throw $damaged();
        }

        return $stat;
    }

    /**
     * Creates $dir 0700 unless it exists; either way it must pass inspect().
     *
     * @param callable(): \Throwable $damaged
     * @param callable(): \Throwable $failed
     */
    public static function ensureDir(string $dir, callable $damaged, callable $failed): void
    {
        if (self::inspect($dir, true, $damaged) !== null) {
            return;
        }

        $umask = umask(0077);
        @mkdir($dir, 0700);
        umask($umask);

        // Created here or by a concurrent request; either way it must pass.
        if (self::inspect($dir, true, $damaged) === null) {
            throw $failed();
        }
    }

    /**
     * Opens (creating 0600 when absent) a lock file for flock(). An existing
     * file must pass inspect() and still be the same inode once opened.
     *
     * @param callable(): \Throwable $damaged
     * @param callable(): \Throwable $failed
     * @return resource
     */
    public static function openLockFile(string $path, callable $damaged, callable $failed)
    {
        if (self::inspect($path, false, $damaged) === null) {
            $umask = umask(0077);
            $handle = @fopen($path, 'x');
            umask($umask);

            if ($handle !== false) {
                @chmod($path, 0600);
                return $handle;
            }
        }

        // Present (or created by a concurrent request since the check).
        $stat = self::inspect($path, false, $damaged);

        if ($stat === null) {
            throw $failed();
        }

        $handle = @fopen($path, 'r');

        if ($handle === false || ! self::sameFile($handle, $stat)) {
            if ($handle !== false) {
                fclose($handle);
            }
            throw $damaged();
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public static function sameFile($handle, array $stat): bool
    {
        $opened = fstat($handle);

        return $opened !== false && $opened['dev'] === $stat['dev'] && $opened['ino'] === $stat['ino'];
    }
}
