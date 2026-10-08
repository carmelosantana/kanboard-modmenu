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
 *   .modmenu-trash-<16 hex>     an old copy that could not be deleted (e.g. root-owned
 *                               files); moved aside so it never blocks a later update
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

    /**
     * Syntax-check every .php file without executing it: token_get_all(TOKEN_PARSE)
     * when the tokenizer extension is loaded, else OPcache's compiler when it is
     * enabled for this SAPI, else no check (the load guard in Plugin.php still
     * rolls back an \Error thrown while the new copy registers).
     *
     * @throws ModMenuException with the relative path and line of the first ParseError/CompileError
     */
    private function assertPhpParses(string $stagedDir, string $name): void
    {
        $useTokenizer = $this->hasTokenizer();
        if (! $useTokenizer && ! $this->canOpcacheCompile()) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagedDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            try {
                if ($useTokenizer) {
                    token_get_all((string) file_get_contents($path), TOKEN_PARSE);
                } else {
                    @opcache_compile_file($path); // false (e.g. opcache.restrict_api) = could not check, not a failure
                }
            } catch (\CompileError $e) { // ParseError extends CompileError; both are load-fatal
                $relative = substr($path, strlen($stagedDir) + 1);
                throw new ModMenuException(t('The new %s has a PHP syntax error in %s on line %d.', $name, $relative, $e->getLine()));
            }
        }
    }

    /** Whether the tokenizer extension is loaded (the official Kanboard Docker image lacks it). */
    protected function hasTokenizer(): bool
    {
        return function_exists('token_get_all');
    }

    /** Whether opcache_compile_file() exists and OPcache is enabled for this SAPI. */
    protected function canOpcacheCompile(): bool
    {
        return function_exists('opcache_compile_file')
            && (bool) ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable');
    }

    /**
     * Put the staged copy in place of <pluginsDir>/<Name>, moving any current
     * copy to previousDir().
     *
     * @throws ModMenuException
     */
    public function swap(string $stagedDir, string $name, bool $keepPrevious): void
    {
        // Load the exception class now: once the current copy is moved aside,
        // autoloading it from <pluginsDir>/<Name> would fail.
        class_exists(ModMenuException::class);

        $target = $this->targetDir($name);
        $prev = $this->previousDir($name);

        if (file_exists($prev) && ! $this->discard($prev)) {
            throw new ModMenuException(t('Could not remove the stale previous copy of %s.', $name));
        }

        if (! file_exists($target)) {
            if (! @rename($stagedDir, $target)) {
                throw new ModMenuException(t('Could not move the new %s into place.', $name));
            }
            self::invalidateOpcache($target);
            return;
        }

        if (! @rename($target, $prev)) {
            throw new ModMenuException(t('Could not move the current %s aside (its folder may be a bind mount or read-only). Nothing was changed.', $name));
        }

        if (! @rename($stagedDir, $target)) {
            if (! @rename($prev, $target)) {
                throw new ModMenuException(t('Could not move the new %s into place, and the previous version could NOT be restored: it is in %s. Rename that folder back to %s.', $name, basename($prev), $name));
            }
            throw new ModMenuException(t('Could not move the new %s into place; the previous version was restored.', $name));
        }

        self::invalidateOpcache($target);

        if (! $keepPrevious) {
            $this->discard($prev); // the swap succeeded; a leftover dot dir is never loaded and is discarded by the next swap
        }
    }

    /**
     * The new copy has loaded: drop the previous copy, but only when the
     * caller is the copy now on disk. True only when previousDir() is gone
     * (deleted, or moved to a trash dir).
     */
    public function finalize(string $name, string $runningVersion): bool
    {
        $prev = $this->previousDir($name);
        if (! file_exists($prev) || self::readVersion($this->targetDir($name)) !== $runningVersion) {
            return false;
        }
        return $this->discard($prev);
    }

    /**
     * Restore previousDir() and park the current copy in .modmenu-failed-<Name>.
     * With $failingVersion, do nothing unless that is the version on disk (a
     * concurrent request may already have rolled back or replaced it).
     *
     * @throws ModMenuException when the restore rename fails
     */
    public function rollback(string $name, ?string $failingVersion = null): bool
    {
        $prev = $this->previousDir($name);
        if (! file_exists($prev)) {
            return false;
        }

        $target = $this->targetDir($name);
        if ($failingVersion !== null && self::readVersion($target) !== $failingVersion) {
            return false;
        }

        $failed = $this->failedDir($name);

        if (file_exists($failed)) {
            $this->discard($failed);
        }
        if (file_exists($target)) {
            @rename($target, $failed);
        }
        if (! @rename($prev, $target)) {
            throw new ModMenuException(t('Could not restore the previous version of %s.', $name));
        }
        self::invalidateOpcache($target);
        return true;
    }

    /**
     * Delete $dir; when that fails (e.g. a root-owned subdirectory), move it
     * to a unique .modmenu-trash-<16 hex> dir in the plugins dir, which needs
     * write access to the plugins dir only. True when $dir no longer exists.
     */
    private function discard(string $dir): bool
    {
        if (self::removeTree($dir)) {
            return true;
        }
        return @rename($dir, $this->pluginsDir . '/.modmenu-trash-' . bin2hex(random_bytes(8)));
    }

    /**
     * Drop every .php file under $dir from opcache so the next request runs
     * the copy now on disk. Returns the number of .php files visited.
     */
    public static function invalidateOpcache(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $canInvalidate = function_exists('opcache_invalidate');
        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $count++;
            if ($canInvalidate) {
                @opcache_invalidate($file->getPathname(), true); // @: opcache.restrict_api may forbid it
            }
        }
        return $count;
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
