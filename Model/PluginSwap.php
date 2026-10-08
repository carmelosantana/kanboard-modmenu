<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Staged, verified, rename-based replacement of a plugin folder.
 *
 * Every working directory lives inside the plugins dir (same filesystem, so
 * rename() is atomic) and starts with a dot (Kanboard's loader skips those):
 *   .modmenu-staging-<16 hex>   staging parent; the archive extracts to <staging>/<Name>
 *   .modmenu-previous-<Name>    the copy that was replaced, kept until finalize()
 *   .modmenu-failed-<Name>      the new copy parked by rollback()
 *
 * Dependency rule: this file may use only PHP builtins, Kanboard's global t(),
 * ModMenuException and (guarded) Kanboard\Core\Plugin\Version. A companion
 * plugin require_once's it out of a *staged* ModMenu while an older ModMenu is
 * the active copy, so it must not extend Kanboard\Core\Base or use any other
 * ModMenu class.
 */
class PluginSwap
{
    private string $pluginsDir;

    public function __construct(string $pluginsDir)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
    }

    /**
     * Create an empty staging parent directory inside the plugins dir.
     *
     * @throws ModMenuException
     */
    public function createStaging(): string
    {
        $dir = $this->pluginsDir . '/.modmenu-staging-' . bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0755)) {
            throw new ModMenuException(t('Unable to create a staging directory in the plugins folder.'));
        }
        return $dir;
    }

    public function previousDir(string $name): string
    {
        return $this->pluginsDir . '/.modmenu-previous-' . $name;
    }

    private function failedDir(string $name): string
    {
        return $this->pluginsDir . '/.modmenu-failed-' . $name;
    }

    private function targetDir(string $name): string
    {
        return $this->pluginsDir . '/' . $name;
    }

    /**
     * Check a staged plugin copy can be loaded safely. Returns its version
     * ('' when it has no plugin.json).
     *
     * @throws ModMenuException on the first failed check
     */
    public function verify(string $stagedDir, string $name, ?string $mustBeNewerThan = null): string
    {
        $stagedDir = rtrim($stagedDir, '/');
        $pluginPhp = $stagedDir . '/Plugin.php';

        if (! is_file($pluginPhp)) {
            throw new ModMenuException(t('The new %s has no Plugin.php.', $name));
        }

        $namespace = 'Kanboard\\Plugin\\' . $name;
        $pattern = '/^\s*namespace\s+' . preg_quote($namespace, '/') . '\s*;/m';
        if (preg_match($pattern, (string) file_get_contents($pluginPhp)) !== 1) {
            throw new ModMenuException(t('The new %s Plugin.php does not declare namespace %s.', $name, $namespace));
        }

        $this->assertPhpParses($stagedDir, $name);

        $meta = $this->readJson($stagedDir);

        $phpConstraint = isset($meta['php_version']) && is_string($meta['php_version']) ? trim($meta['php_version']) : '';
        if ($phpConstraint !== '' && preg_match('/^(>=|>)?\s*(\d+(?:\.\d+)*)$/', $phpConstraint, $m) === 1) {
            $op = $m[1] === '' ? '>=' : $m[1];
            if (! version_compare(PHP_VERSION, $m[2], $op)) {
                throw new ModMenuException(t('The new %s requires PHP %s; this server runs PHP %s.', $name, $phpConstraint, PHP_VERSION));
            }
        }

        $compat = isset($meta['compatible_version']) && is_string($meta['compatible_version']) ? $meta['compatible_version'] : '';
        if ($compat !== '' && class_exists(\Kanboard\Core\Plugin\Version::class) && defined('APP_VERSION')) {
            if (! \Kanboard\Core\Plugin\Version::isCompatible($compat, APP_VERSION)) {
                throw new ModMenuException(t('The new %s requires Kanboard %s; this board runs %s.', $name, $compat, APP_VERSION));
            }
        }

        $staged = self::readVersion($stagedDir);

        if ($mustBeNewerThan !== null) {
            if ($staged === null) {
                throw new ModMenuException(t('The new %s has no plugin.json version to compare with %s.', $name, $mustBeNewerThan));
            }
            if (! version_compare($staged, $mustBeNewerThan, '>')) {
                throw new ModMenuException(t('The new %s (%s) is not newer than the installed version (%s).', $name, $staged, $mustBeNewerThan));
            }
        }

        return $staged ?? '';
    }

    /** @throws ModMenuException with the relative path and line of the first ParseError/CompileError */
    private function assertPhpParses(string $stagedDir, string $name): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagedDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            try {
                token_get_all((string) file_get_contents($path), TOKEN_PARSE);
            } catch (\CompileError $e) { // ParseError extends CompileError; both are load-fatal
                $relative = substr($path, strlen($stagedDir) + 1);
                throw new ModMenuException(t('The new %s has a PHP syntax error in %s on line %d.', $name, $relative, $e->getLine()));
            }
        }
    }

    /**
     * Put the staged copy in place of <pluginsDir>/<Name>, moving any current
     * copy to previousDir().
     *
     * @throws ModMenuException
     */
    public function swap(string $stagedDir, string $name, bool $keepPrevious): void
    {
        $target = $this->targetDir($name);
        $prev = $this->previousDir($name);

        if (file_exists($prev) && ! self::removeTree($prev)) {
            throw new ModMenuException(t('Could not remove the stale previous copy of %s.', $name));
        }

        if (! file_exists($target)) {
            if (! @rename($stagedDir, $target)) {
                throw new ModMenuException(t('Could not move the new %s into place.', $name));
            }
            return;
        }

        if (! @rename($target, $prev)) {
            throw new ModMenuException(t('Could not move the current %s aside (its folder may be a bind mount or read-only). Nothing was changed.', $name));
        }

        if (! @rename($stagedDir, $target)) {
            @rename($prev, $target);
            throw new ModMenuException(t('Could not move the new %s into place; the previous version was restored.', $name));
        }

        if (! $keepPrevious) {
            self::removeTree($prev); // failure is harmless: the dot dir is never loaded
        }
    }

    /**
     * The new copy has loaded: drop the previous copy, but only when the
     * caller is the copy now on disk.
     */
    public function finalize(string $name, string $runningVersion): bool
    {
        $prev = $this->previousDir($name);
        if (! file_exists($prev) || self::readVersion($this->targetDir($name)) !== $runningVersion) {
            return false;
        }
        self::removeTree($prev);
        return true;
    }

    /**
     * Restore previousDir() and park the current copy in .modmenu-failed-<Name>.
     *
     * @throws ModMenuException when the restore rename fails
     */
    public function rollback(string $name): bool
    {
        $prev = $this->previousDir($name);
        if (! file_exists($prev)) {
            return false;
        }

        $target = $this->targetDir($name);
        $failed = $this->failedDir($name);

        if (file_exists($failed)) {
            self::removeTree($failed);
        }
        if (file_exists($target)) {
            @rename($target, $failed);
        }
        if (! @rename($prev, $target)) {
            throw new ModMenuException(t('Could not restore the previous version of %s.', $name));
        }
        return true;
    }

    /** Recursively delete a directory; true when it no longer exists. */
    public static function removeTree(string $dir): bool
    {
        if (is_link($dir) || is_file($dir)) {
            return @unlink($dir);
        }
        if (! is_dir($dir)) {
            return true;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (! self::removeTree($dir . '/' . $f)) {
                return false;
            }
        }
        return @rmdir($dir);
    }

    /** The "version" from <pluginDir>/plugin.json, or null. */
    public static function readVersion(string $pluginDir): ?string
    {
        $file = rtrim($pluginDir, '/') . '/plugin.json';
        if (! is_file($file)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) && isset($meta['version']) && is_scalar($meta['version'])
            ? (string) $meta['version']
            : null;
    }

    private function readJson(string $pluginDir): array
    {
        $file = $pluginDir . '/plugin.json';
        if (! is_file($file)) {
            return [];
        }
        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : [];
    }
}
