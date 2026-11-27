<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * The catalog lock, DATA_DIR/modmenu/catalog.lock: source changes hold it
 * exclusive; catalog mutations hold it shared through their final checks.
 * Lock order: plugin locks (sorted), then this lock, then the provenance lock.
 *
 * The directory (0700) and file (0600) must be real, owned by the PHP user and
 * closed to group and world; anything else is refused.
 */
class CatalogLock
{
    private const FILE = 'catalog.lock';
    private const REFUSED = 'ModMenu could not lock its source list.';

    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function shared(callable $fn): mixed
    {
        return $this->hold(LOCK_SH, $fn);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function exclusive(callable $fn): mixed
    {
        return $this->hold(LOCK_EX, $fn);
    }

    private function hold(int $mode, callable $fn): mixed
    {
        $handle = $this->open();

        try {
            if (! flock($handle, $mode)) {
                throw new ModMenuException(t(self::REFUSED));
            }

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $this->ensureDir();
        $path = $this->dir.'/'.self::FILE;

        if (self::inspect($path, false) === null) {
            $umask = umask(0077);
            $handle = @fopen($path, 'x');
            umask($umask);

            if ($handle !== false) {
                if (! chmod($path, 0600)) {
                    fclose($handle);
                    throw new ModMenuException(t(self::REFUSED));
                }
                return $handle;
            }
        }

        // Present, or created by a concurrent request since the check.
        $stat = self::inspect($path, false);
        $handle = $stat === null ? false : @fopen($path, 'r');

        if ($handle === false) {
            throw new ModMenuException(t(self::REFUSED));
        }

        $opened = fstat($handle);

        if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
            fclose($handle);
            throw new ModMenuException(t(self::REFUSED));
        }

        return $handle;
    }

    private function ensureDir(): void
    {
        if (self::inspect($this->dir, true) !== null) {
            return;
        }

        $umask = umask(0077);
        @mkdir($this->dir, 0700);
        umask($umask);

        // Created here or by a concurrent request; either way it must pass.
        if (self::inspect($this->dir, true) === null) {
            throw new ModMenuException(t(self::REFUSED));
        }
    }

    /**
     * lstat() of a path that must be a directory or regular file owned by the
     * PHP user with no group/world bits; null when absent.
     *
     * @throws ModMenuException when present but breaking a rule (a symlink included)
     */
    private static function inspect(string $path, bool $directory): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        if ($stat === false) {
            return null;
        }

        $type = $stat['mode'] & 0170000;

        if ($type !== ($directory ? 0040000 : 0100000)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || ($stat['mode'] & 0077) !== 0) {
            throw new ModMenuException(t(self::REFUSED));
        }

        return $stat;
    }
}
