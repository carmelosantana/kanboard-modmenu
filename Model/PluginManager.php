<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * The ModMenu engine: enumerate installed plugins (active + disabled),
 * enable/disable by moving folders, uninstall, and install from a URL or
 * uploaded file. Every install/update is staged and verified (PluginSwap)
 * before it replaces anything. ModMenu updates itself through that same
 * verified swap, but never disables or removes itself.
 *
 * "Installed" state needs no DB table: a plugin is ACTIVE if its folder is in
 * PLUGINS_DIR (loaded by Kanboard at bootstrap) and DISABLED if its folder is
 * parked in DATA_DIR/modmenu_disabled (outside the scan path).
 */
class PluginManager extends Base
{
    const SELF = 'ModMenu';

    /** @var string */
    private $activeDir;

    /** @var string */
    private $disabledDir;

    private ?ArchiveDownloader $archiveDownloader = null;

    private function activeDir(): string
    {
        return $this->activeDir ?? PLUGINS_DIR;
    }

    private function disabledDir(): string
    {
        return $this->disabledDir ?? (DATA_DIR . DIRECTORY_SEPARATOR . 'modmenu_disabled');
    }

    public function setDirectories(string $activeDir, string $disabledDir): self
    {
        $this->activeDir = $activeDir;
        $this->disabledDir = $disabledDir;
        return $this;
    }

    public function setArchiveDownloader(ArchiveDownloader $downloader): self
    {
        $this->archiveDownloader = $downloader;
        return $this;
    }

    public function isConfigured(): bool
    {
        return is_writable($this->activeDir()) && extension_loaded('zip');
    }

    public function notConfiguredReason(): string
    {
        if (! extension_loaded('zip')) {
            return t('The PHP "zip" extension is not installed, so ModMenu cannot unpack plugins.');
        }
        if (! is_writable($this->activeDir())) {
            return t('The plugins directory is not writable, so ModMenu cannot install or move plugins.');
        }
        return '';
    }

    public function listInstalled(): array
    {
        $plugins = array_merge(
            $this->scanDir($this->activeDir(), 'active'),
            $this->scanDir($this->disabledDir(), 'disabled')
        );
        usort($plugins, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $plugins;
    }

    public function installedMap(): array
    {
        $map = [];
        foreach ($this->listInstalled() as $p) {
            $map[$p['name']] = [
                'version' => $p['version'],
                'status' => $p['status'],
                'replaces' => $p['replaces'],
                'deprecated' => $p['deprecated'],
            ];
        }
        return $map;
    }

    public function enable(string $name): void
    {
        // guardSelf() is intentionally omitted: ModMenu is always active and can
        // never be in the disabled dir, so re-enabling it is impossible in practice
        // and harmless in theory — no protection is needed here.
        $this->move($name, $this->disabledDir(), $this->activeDir());
    }

    public function disable(string $name, ?string $replacement = null): void
    {
        $this->guardName($name);
        $this->guardSelf($name);
        $this->assertNoActiveDependents($name, $replacement);
        $this->move($name, $this->activeDir(), $this->disabledDir());
    }

    public function migrationSuccessor(string $old): string
    {
        $successor = $this->installedMap()[$old]['deprecated']['superseded_by'] ?? null;
        if ($successor === null || $successor === $old) {
            throw new ModMenuException(t('%s has no successor to migrate to.', $old));
        }
        $this->guardName($successor);
        return $successor;
    }

    /** Install a missing successor without making it visible to the plugin loader. */
    public function prepareMigration(string $old, array $catalog): string
    {
        $successor = $this->migrationSuccessor($old);
        $this->guardSelf($old);
        if (isset($this->installedMap()[$successor])) {
            return $successor;
        }

        $url = $catalog[$successor]['download'] ?? '';
        if ($url === '') {
            throw new ModMenuException(t('%s is not in the plugin directory, so nothing changed.', $successor));
        }
        try {
            $this->installFromUrl($url, $successor, true);
        } catch (ModMenuException $e) {
            throw new ModMenuException(e("Couldn't install %s, so %s stays active.", $successor, $old), 0, $e);
        }
        return $successor;
    }

    public function migrationCheck(string $old, string $successor, array $catalog): array
    {
        $map = $this->installedMap();
        $map[$old]['status'] = 'disabled';
        $requires = $this->installedPluginsDeps()[$successor]['requires'] ?? [];
        $check = $this->forwardCheck($requires, $catalog, $map);
        foreach ($check['plan'] as $step) {
            // Dependencies cannot activate either side ahead of the switch.
            if (in_array($step['plugin'], [$old, $successor], true)) {
                $check['blocked'] = true;
            }
        }
        return $check;
    }

    public function assertMigrationConflicts(string $old, string $successor, array $catalog): void
    {
        $entry = $catalog[$successor] ?? [];
        $conflicts = array_diff($this->activeConflicts($successor, $entry['conflicts'] ?? [], $entry['replaces'] ?? []), [$old]);
        if ($conflicts !== []) {
            throw new ModMenuException(t('%s conflicts with %s. Disable them first.', $successor, implode(', ', $conflicts)));
        }
    }

    public function migrate(string $old, array $catalog): string
    {
        $successor = $this->prepareMigration($old, $catalog);
        if (! $this->migrationCheck($old, $successor, $catalog)['satisfied']) {
            throw new ModMenuException(t('"%s" has unmet requirements.', $successor));
        }
        $this->assertMigrationConflicts($old, $successor, $catalog);

        $installed = $this->installedMap();
        $oldWasActive = $installed[$old]['status'] === 'active';
        if ($oldWasActive) {
            $this->disable($old, $successor);
        }
        if ($installed[$successor]['status'] === 'disabled') {
            try {
                $this->enable($successor);
            } catch (ModMenuException $e) {
                if ($oldWasActive && ($this->installedMap()[$successor]['status'] ?? '') !== 'active') {
                    $this->enable($old);
                }
                throw $e;
            }
        }
        return $successor;
    }

    public function uninstall(string $name): void
    {
        $this->guardName($name);
        $this->guardSelf($name);
        $this->assertNoActiveDependents($name);

        foreach ([$this->activeDir(), $this->disabledDir()] as $base) {
            $path = $base . '/' . $name;
            if (is_dir($path)) {
                if (! $this->removeTree($path)) {
                    throw new ModMenuException(t('Could not remove "%s". Its folder may be a bind mount or read-only.', $name));
                }
                return;
            }
        }
        throw new ModMenuException(t('Plugin "%s" is not installed.', $name));
    }

    /**
     * Download and install a plugin archive. When $expectedName is given (a
     * catalog entry's name), the archive's top-level folder must match it.
     */
    public function installFromUrl(string $url, ?string $expectedName = null, bool $installDisabled = false): string
    {
        $tmp = ($this->archiveDownloader ??= new ArchiveDownloader())->download($url);

        try {
            return $this->installArchive($tmp, $expectedName, $installDisabled);
        } finally {
            @unlink($tmp);
        }
    }

    public function installFromFile(string $tmpPath): string
    {
        if (! is_file($tmpPath)) {
            throw new ModMenuException(t('No uploaded file was received.'));
        }
        return $this->installArchive($tmpPath);
    }

    public static function hasUpdate(string $installed, string $available): bool
    {
        return version_compare($installed, $available, '<');
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Validate the archive, extract it to a staging dir inside the plugins dir,
     * verify the staged copy, then swap it into place. If an ACTIVE plugin of
     * the same name exists it is replaced (update path). A DISABLED copy blocks
     * install. ModMenu itself must be newer than the installed copy, and keeps
     * its previous copy until the new version has loaded (Plugin::initialize).
     */
    private function installArchive(string $zipPath, ?string $expectedName = null, bool $installDisabled = false): string
    {
        $archive = new PluginArchive($this->container);
        $name = $archive->inspect($zipPath);

        $this->guardName($name);

        if ($expectedName !== null && $name !== $expectedName) {
            throw new ModMenuException(t('The archive for "%s" contains "%s". Nothing was installed.', $expectedName, $name));
        }

        if (is_dir($this->disabledDir() . '/' . $name)) {
            throw new ModMenuException(t('"%s" is already installed but disabled. Enable it instead.', $name));
        }

        $destination = $this->activeDir();
        if ($installDisabled) {
            $this->guardSelf($name);
            if (file_exists($destination . '/' . $name)) {
                throw new ModMenuException(t('"%s" is already installed.', $name));
            }
            $destination = $this->disabledDir();
            if (! is_dir($destination) && ! @mkdir($destination, 0755, true) && ! is_dir($destination)) {
                throw new ModMenuException(t('Could not create the target directory.'));
            }
        }

        $swap = new PluginSwap($destination);
        $staging = $swap->createStaging();
        try {
            $archive->extractTo($zipPath, $staging);
            $staged = $staging . '/' . $name;
            $isSelf = $name === self::SELF;
            $swap->verify($staged, $name, $isSelf ? $this->installedVersion(self::SELF) : null);
            if ($installDisabled) {
                // A fresh disabled install must never replace a copy placed by another request.
                if (! @rename($staged, $destination . '/' . $name)) {
                    throw new ModMenuException(t('Could not move the new %s into place.', $name));
                }
            } else {
                $swap->swap($staged, $name, $isSelf);
            }
        } finally {
            PluginSwap::removeTree($staging);
        }

        return $name;
    }

    /** True while ModMenu's previous copy waits for the new version to load. */
    public function isSelfUpdatePending(): bool
    {
        return is_dir((new PluginSwap($this->activeDir()))->previousDir(self::SELF));
    }

    /** Version of an ACTIVE plugin from its plugin.json, '0.0.0' if unreadable. */
    private function installedVersion(string $name): string
    {
        return PluginSwap::readVersion($this->activeDir() . '/' . $name) ?? '0.0.0';
    }

    private function move(string $name, string $from, string $to): void
    {
        $this->guardName($name);
        $src = $from . '/' . $name;
        $dst = $to . '/' . $name;

        if (! is_dir($src)) {
            throw new ModMenuException(t('Plugin "%s" was not found.', $name));
        }
        if (! is_dir($to) && ! mkdir($to, 0755, true)) {
            throw new ModMenuException(t('Could not create the target directory.'));
        }
        if (file_exists($dst)) {
            throw new ModMenuException(t('A copy of "%s" already exists at the destination.', $name));
        }
        if (@rename($src, $dst)) {
            return;
        }

        // rename() fails with EXDEV when $src and $dst are on different
        // filesystems — the norm in Docker, where plugins/ (image layer) and
        // data/ (a volume) are separate mounts. Fall back to a recursive copy
        // followed by removing the source, so enable/disable works across the
        // plugins/data filesystem boundary.
        if ($this->copyTree($src, $dst)) {
            if ($this->removeTree($src)) {
                return;
            }
            throw new ModMenuException(t('Moved "%s" but could not remove the original. Its folder may be a bind mount or read-only.', $name));
        }

        $this->removeTree($dst); // clean up any partial copy
        throw new ModMenuException(t('Could not move "%s". Its folder may be a bind mount or read-only.', $name));
    }

    private function copyTree(string $src, string $dst): bool
    {
        if (! is_dir($src)) { return false; }
        if (! is_dir($dst) && ! @mkdir($dst, 0755, true)) { return false; }

        $entries = scandir($src);
        if ($entries === false) { return false; }

        foreach ($entries as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) {
                if (! $this->copyTree($s, $d)) { return false; }
            } elseif (! @copy($s, $d)) {
                return false;
            }
        }
        return true;
    }

    private function scanDir(string $dir, string $status): array
    {
        if (! is_dir($dir)) { return []; }

        $out = [];
        foreach (scandir($dir) as $entry) {
            // Like Kanboard's loader, skip every dot entry (incl. ModMenu's .modmenu-* working folders).
            if (str_starts_with($entry, '.')) { continue; }
            $path = $dir . '/' . $entry;
            if (! is_dir($path) || ! is_file($path . '/Plugin.php')) { continue; }

            $meta = $this->readMeta($path, $entry);
            $meta['status'] = $status;
            $out[] = $meta;
        }
        return $out;
    }

    private function readMeta(string $path, string $folderName): array
    {
        $meta = [
            'name' => $folderName,
            'title' => $folderName,
            'version' => 'unknown',
            'description' => '',
            'author' => '',
            'homepage' => '',
            'requires' => [],
            'recommends' => [],
            'conflicts' => [],
            'replaces' => [],
            'deprecated' => null,
        ];

        $jsonFile = $path . '/plugin.json';
        if (is_file($jsonFile)) {
            $json = json_decode((string) file_get_contents($jsonFile), true);
            if (is_array($json)) {
                // 'name' is ALWAYS the folder name — it is the operation key used
                // by enable/disable/uninstall. Using plugin.json "name" here would
                // break those operations if the json name differs from the folder.
                $meta['name'] = $folderName;
                $meta['title'] = $json['title'] ?? $json['name'] ?? $folderName;
                $meta['version'] = $json['version'] ?? 'unknown';
                $meta['description'] = $json['description'] ?? '';
                $meta['author'] = $json['author'] ?? '';
                $meta['homepage'] = $json['homepage'] ?? '';
                $meta['requires']   = self::normalizeDeps($json['requires'] ?? []);
                $meta['recommends'] = self::normalizeDeps($json['recommends'] ?? []);
                $meta['conflicts']  = self::normalizeConflicts($json['conflicts'] ?? []);
                $meta['replaces']   = self::normalizeReplaces($json['replaces'] ?? []);
                $meta['deprecated'] = self::normalizeDeprecated($json['deprecated'] ?? null);
            }
        }
        return $meta;
    }

    /**
     * Normalize a raw deps array (plugin.json or a directory entry) into clean dep
     * objects. Non-arrays, elements without a scalar 'plugin', and non-scalar
     * min_version/reason values are dropped (never fatal, never "Array to string").
     */
    public static function normalizeDeps($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) && is_scalar($entry['plugin'] ?? null) && (string) $entry['plugin'] !== '') {
                $min = $entry['min_version'] ?? null;
                $reason = $entry['reason'] ?? '';
                $out[] = [
                    'plugin'      => (string) $entry['plugin'],
                    'min_version' => is_scalar($min) && (string) $min !== '' ? (string) $min : null,
                    'reason'      => is_scalar($reason) ? (string) $reason : '',
                ];
            }
        }
        return $out;
    }

    /**
     * Normalize a raw `conflicts` value into a list of non-empty plugin names.
     * Non-arrays and non-string/empty elements are dropped (never fatal).
     */
    public static function normalizeConflicts($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        return array_values(array_filter($raw, static fn ($c) => is_string($c) && $c !== ''));
    }

    public static function normalizeReplaces($raw): array
    {
        return self::normalizeConflicts($raw);
    }

    public static function normalizeDeprecated($raw): ?array
    {
        $successor = is_array($raw) ? ($raw['superseded_by'] ?? null) : null;
        if (! is_string($successor) || $successor === '') {
            return null;
        }

        return [
            'superseded_by' => $successor,
            'since' => is_string($raw['since'] ?? null) ? $raw['since'] : '',
            'reason' => is_string($raw['reason'] ?? null) ? $raw['reason'] : '',
        ];
    }

    /**
     * Installed, ACTIVE plugins that conflict with $name, declared on either
     * side, including replacements. For Browse entries, $declared and $replaces
     * add the conflict and replacement names listed in the catalog.
     * The model never blocks on conflicts: the controller asks the admin
     * before enabling or installing (see disableConflicts()).
     */
    public function activeConflicts(string $name, array $declared = [], array $replaces = []): array
    {
        $installed = $this->listInstalled();
        $mine = array_merge(self::normalizeConflicts($declared), self::normalizeReplaces($replaces));
        foreach ($installed as $p) {
            if ($p['name'] === $name) {
                $mine = array_merge($mine, $p['conflicts'], $p['replaces']);
            }
        }

        $out = [];
        foreach ($installed as $p) {
            if ($p['status'] !== 'active' || $p['name'] === $name) {
                continue;
            }
            $theirs = array_merge($p['conflicts'], $p['replaces']);
            if (in_array($p['name'], $mine, true) || in_array($name, $theirs, true)) {
                $out[] = $p['name'];
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Disable every active plugin that conflicts with $name, so $name can be
     * activated. Returns the names disabled. Stops on the first failure (for
     * example a conflicting plugin other active plugins require).
     */
    public function disableConflicts(string $name, array $declared = [], array $replaces = []): array
    {
        $conflicts = $this->activeConflicts($name, $declared, $replaces);
        foreach ($conflicts as $other) {
            $this->disable($other);
        }
        return $conflicts;
    }

    /**
     * Active installed plugins the catalog has a newer version of, as
     * [plugin, from, to, download], sorted by name with ModMenu last so it
     * replaces itself only after everything else has updated. Disabled
     * plugins are skipped: an install over a disabled copy is refused.
     */
    public function updatesAvailable(array $catalog): array
    {
        $out = [];
        $self = null;
        foreach ($this->listInstalled() as $p) {
            $entry = $catalog[$p['name']] ?? null;
            if ($p['status'] !== 'active' || ! is_array($entry) || empty($entry['download'])) {
                continue;
            }
            $to = (string) ($entry['version'] ?? '');
            if (! self::hasUpdate((string) $p['version'], $to)) {
                continue;
            }
            $row = ['plugin' => $p['name'], 'from' => (string) $p['version'], 'to' => $to, 'download' => (string) $entry['download']];
            if ($p['name'] === self::SELF) {
                $self = $row;
            } else {
                $out[] = $row;
            }
        }
        if ($self !== null) {
            $out[] = $self;
        }
        return $out;
    }

    /**
     * Install every available update. A failure is recorded and the rest
     * still run. Returns ['updated' => [names], 'failed' => [name => message]].
     */
    public function updateAll(array $catalog): array
    {
        $result = ['updated' => [], 'failed' => []];
        foreach ($this->updatesAvailable($catalog) as $u) {
            try {
                $this->installFromUrl($u['download'], $u['plugin']);
                $result['updated'][] = $u['plugin'];
            } catch (ModMenuException $e) {
                $result['failed'][$u['plugin']] = $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Warning appended to update flashes: '' when $name has no active
     * conflicts, else "Conflicts with A, B: disable one."
     */
    public function conflictWarning(string $name): string
    {
        $conflicts = $this->activeConflicts($name);
        return $conflicts === [] ? '' : t('Conflicts with %s: disable one.', implode(', ', $conflicts));
    }

    /**
     * name => ['status' => 'active'|'disabled', 'requires' => [dep objects]] for
     * every installed plugin — the input the reverse-dependency check needs.
     */
    public function installedPluginsDeps(): array
    {
        $out = [];
        foreach ($this->listInstalled() as $p) {
            $out[$p['name']] = [
                'status'   => $p['status'],
                'requires' => $p['requires'] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Every declared `requires` entry classified against what is installed,
     * satisfied ones included (status satisfied|outdated|missing|disabled), for
     * the Installed tab's per-requirement lines. Pass $installedMap to reuse one
     * folder scan across many plugins.
     */
    public function requirementStates(array $requires, array $catalog, ?array $installedMap = null): array
    {
        return (new DependencyResolver($this->container))
            ->resolveForward($requires, 'requires', $installedMap ?? $this->installedMap(), $catalog)['deps'];
    }

    /**
     * Classified unmet deps (requires + recommends) for one plugin, for display.
     * Satisfied deps are omitted; each entry carries its 'kind'. Pass
     * $installedMap to reuse one folder scan.
     */
    public function unmetDepsFor(array $requires, array $recommends, array $catalog, ?array $installedMap = null): array
    {
        $resolver = new DependencyResolver($this->container);
        $map = $installedMap ?? $this->installedMap();
        $all = array_merge(
            $resolver->resolveForward($requires, 'requires', $map, $catalog)['deps'],
            $resolver->resolveForward($recommends, 'recommends', $map, $catalog)['deps']
        );
        return array_values(array_filter($all, static fn ($d) => $d['status'] !== 'satisfied'));
    }

    private function assertNoActiveDependents(string $name, ?string $replacement = null): void
    {
        $map = $this->installedMap();
        $deps = $this->installedPluginsDeps();
        if ($replacement !== null && isset($map[$replacement])
            && ($map[$name]['deprecated']['superseded_by'] ?? null) === $replacement) {
            // Preserve dependencies that the verified replacement will satisfy after the switch.
            $after = $map;
            $after[$name]['status'] = 'disabled';
            $after[$replacement]['status'] = 'active';
            foreach ($deps as &$plugin) {
                $plugin['requires'] = array_filter($plugin['requires'],
                    static fn ($dep) => ! DependencyResolver::isSatisfied($dep, $after));
            }
            unset($plugin);
        }
        $resolver = new DependencyResolver($this->container);
        $blockers = $resolver->resolveReverse($name, $deps, $map);
        if ($blockers !== []) {
            $names = implode(', ', array_map(static fn ($b) => $b['plugin'], $blockers));
            throw new ModMenuException(t('"%s" is required by: %s. Disable or remove those first.', $name, $names));
        }
    }

    /**
     * Forward verdict for activating a plugin with the given `requires`.
     * satisfied=true → act directly. Otherwise 'plan' is the deps-first closure;
     * 'blocked'=true when a required dep cannot be auto-resolved.
     */
    public function forwardCheck(array $requires, array $catalog, ?array $installedMap = null): array
    {
        $resolver = new DependencyResolver($this->container);
        $map = $installedMap ?? $this->installedMap();
        $forward = $resolver->resolveForward($requires, 'requires', $map, $catalog);
        if ($forward['satisfied']) {
            return ['satisfied' => true, 'plan' => [], 'blocked' => false, 'requires' => $forward['deps']];
        }
        $plan = $resolver->resolveClosure($requires, $map, $catalog);
        $blocked = false;
        foreach ($plan as $step) {
            if ($step['action'] === 'unresolvable') {
                $blocked = true;
                break;
            }
        }
        return ['satisfied' => false, 'plan' => $plan, 'blocked' => $blocked, 'requires' => $forward['deps']];
    }

    /**
     * Execute a resolve plan deps-first, then activate the target. Aborts before
     * any action if the plan contains an unresolvable step (no partial activation).
     *
     * @param string $action  'enable' | 'install'
     * @param string $target  target's own download URL (only used when action='install')
     * @param array  $plan    ordered steps from forwardCheck()
     */
    public function resolveAndActivate(string $name, string $action, string $target, array $plan): void
    {
        $this->resolveDependencies($plan);
        if ($action === 'install') {
            $this->installFromUrl($target, $name);
        } else {
            $this->enable($name);
        }
    }

    public function resolveDependencies(array $plan): void
    {
        foreach ($plan as $step) {
            if (($step['action'] ?? '') === 'unresolvable') {
                throw new ModMenuException(t('"%s" cannot be resolved automatically. Install it manually first.', $step['plugin'] ?? '?'));
            }
        }
        foreach ($plan as $step) {
            switch ($step['action']) {
                case 'enable':
                    $this->enable($step['plugin']);
                    break;
                case 'install':
                case 'update':
                    $this->installFromUrl((string) $step['download'], (string) $step['plugin']);
                    break;
            }
        }
    }

    private function guardName(string $name): void
    {
        if ($name === '' || basename($name) !== $name || str_starts_with($name, '.')) {
            throw new ModMenuException(t('Invalid plugin name.'));
        }
    }

    private function guardSelf(string $name): void
    {
        if ($name === self::SELF) {
            throw new ModMenuException(t('ModMenu cannot disable or remove itself.'));
        }
    }

    private function removeTree(string $dir): bool
    {
        if (! is_dir($dir)) { return true; }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            if (is_dir($p)) {
                if (! $this->removeTree($p)) { return false; }
            } elseif (! @unlink($p)) {
                return false;
            }
        }
        return @rmdir($dir);
    }
}
