<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;

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
    const MIGRATION_PLAN_CHANGED = 1;
    const MIGRATION_PLAN_CHANGED_MESSAGE = 'The plan changed since you confirmed it. Review it again.';
    const MIGRATION_SELF_UPDATE_MESSAGE = "A migration can't update ModMenu itself. Update ModMenu first, then migrate.";
    const PRIVATE_UNAVAILABLE = 'Private source unavailable';
    const ADOPT_OR_DISMISS = 'Listed by a private source: adopt or dismiss';
    const LISTED_AS = 'The directory lists this plugin as "%s", not "%s". Nothing was installed.';
    const INSTALLED_DUPLICATE = 'Two installed folders share the name %s; remove one first';
    const NOT_LISTED = '%s is not listed by this private source';

    /** @var string */
    private $activeDir;

    /** @var string */
    private $disabledDir;

    private ?ArchiveDownloader $archiveDownloader = null;

    private array $catalog = [];

    private ?MutationGuard $guard = null;

    /** @var array<string, resource> Plugin locks held by withPluginLocks, keyed by PluginName::key(). */
    private array $heldLocks = [];

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

    /**
     * The catalog later installs read entries from, and the guard built from
     * the same fetch. Without a guard, catalog mutations run only while there
     * is no private state (MutationGuard::withoutPrivateState()).
     */
    public function setCatalog(array $catalog, ?MutationGuard $guard = null): self
    {
        $this->catalog = $catalog;
        $this->guard = $guard;
        return $this;
    }

    private function guard(): MutationGuard
    {
        return $this->guard ??= MutationGuard::withoutPrivateState($this->container);
    }

    protected function privateState(): PrivateState
    {
        return new PrivateState($this->container);
    }

    /**
     * Installed folder names grouped by PluginName::key(); two names under one
     * key mean neither can be installed, updated, adopted or bound.
     *
     * @return array<string, string[]>
     */
    public function installedKeys(): array
    {
        $keys = [];
        foreach ($this->listInstalled() as $p) {
            $key = PluginName::key((string) $p['name']);
            if ($key !== null) {
                $keys[$key][] = (string) $p['name'];
            }
        }
        return $keys;
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
        $this->withPluginLock($name, fn () => $this->move($name, $this->disabledDir(), $this->activeDir()));
    }

    public function disable(string $name, ?string $replacement = null): void
    {
        $this->guardName($name);
        $this->guardSelf($name);
        $this->withPluginLock($name, function () use ($name, $replacement): void {
            $this->assertNoActiveDependents($name, $replacement);
            $this->move($name, $this->activeDir(), $this->disabledDir());
        });
    }

    /**
     * The successor $old names, spelled as its installed folder, else as its
     * catalog entry ($catalog, or the catalog set on this manager): names
     * compare by PluginName::key().
     */
    public function migrationSuccessor(string $old, ?array $catalog = null): string
    {
        $map = $this->installedMap();
        $successor = $map[$old]['deprecated']['superseded_by'] ?? null;
        if ($successor === null || PluginName::same($successor, $old)) {
            throw new ModMenuException(t('%s has no successor to migrate to.', $old));
        }
        $this->guardName($successor);
        return PluginName::find($map, $successor) ?? PluginName::find($catalog ?? $this->catalog, $successor) ?? $successor;
    }

    /** Check that migration can obtain its successor, without downloading it. */
    public function prepareMigration(string $old, array $catalog): string
    {
        $successor = $this->migrationSuccessor($old, $catalog);
        $this->guardSelf($old);
        if (! isset($this->installedMap()[$successor]) && empty(self::entryIn($catalog, $successor)['download'])) {
            throw new ModMenuException(t('%s is not in the plugin directory, so nothing changed.', $successor));
        }
        return $successor;
    }

    public function migrationCheck(string $old, string $successor, array $catalog): array
    {
        $map = $this->installedMap();
        $install = ! isset($map[$successor]);
        $map[$old]['status'] = 'disabled';
        $entry = self::entryIn($catalog, $successor);
        $requires = $this->installedPluginsDeps()[$successor]['requires']
            ?? self::normalizeDeps($entry['requires'] ?? []);
        $check = $this->forwardCheck($requires, $catalog, $map, self::privateSourceOf($entry));
        $selfUpdate = $install && $successor === self::SELF;
        foreach ($check['plan'] as $step) {
            // Dependencies cannot activate either side ahead of the switch.
            if (in_array($step['plugin'], [$old, $successor], true)) {
                $check['blocked'] = true;
            }
            if ($step['plugin'] === self::SELF && in_array($step['action'], ['install', 'update'], true)) {
                $selfUpdate = true;
            }
        }

        $steps = [];
        if ($install) {
            $steps[] = ['plugin' => $successor, 'action' => 'install', 'download' => $entry['download'] ?? null];
        }
        $steps = array_merge($steps, $check['plan']);
        $steps[] = ['plugin' => $old, 'action' => 'disable'];
        $steps[] = ['plugin' => $successor, 'action' => 'enable'];

        $check['blocked'] = $check['blocked'] || $selfUpdate || ($install && empty($entry['download']));
        return $check + [
            'install' => $install,
            'self_update' => $selfUpdate,
            'plan_digest' => $this->planDigest($steps, $successor, $catalog),
        ];
    }

    public function planDigest(array $plan, string $successor, array $catalog): string
    {
        $steps = [];
        foreach ($plan as $step) {
            $plugin = $step['plugin'];
            $download = $step['download'] ?? null;
            $entry = self::entryIn($catalog, (string) $plugin);
            $steps[] = [
                'plugin' => $plugin,
                'action' => $step['action'],
                'download' => $download,
                'sha256' => $download === null ? null : ($entry['sha256'] ?? null),
                'min_version' => $step['min_version'] ?? null,
                'private' => ! empty($entry['private']),
                'source_id' => $entry['source_id'] ?? null,
            ];
        }
        $entry = self::entryIn($catalog, $successor);
        return hash('sha256', json_encode([
            'successor' => $successor,
            'download' => $entry['download'] ?? null,
            'sha256' => $entry['sha256'] ?? null,
            'private' => ! empty($entry['private']),
            'source_id' => $entry['source_id'] ?? null,
            'steps' => $steps,
        ], JSON_THROW_ON_ERROR));
    }

    public function assertMigrationConflicts(string $old, string $successor, array $catalog): void
    {
        $entry = self::entryIn($catalog, $successor) ?? [];
        $conflicts = array_diff($this->activeConflicts($successor, $entry['conflicts'] ?? [], $entry['replaces'] ?? []), [$old]);
        if ($conflicts !== []) {
            throw new ModMenuException(t('%s conflicts with %s. Disable them first.', $successor, implode(', ', $conflicts)));
        }
    }

    /**
     * Lock the old plugin, its successor and every plugin in the dependency
     * plan up front, then re-check: a plan that now needs another plugin is
     * refused rather than extended.
     */
    public function migrate(string $old, array $catalog, bool $dependenciesConfirmed = false, ?string $confirmedDigest = null, ?MutationGuard $guard = null): string
    {
        $this->setCatalog($catalog, $guard ?? $this->guard);
        $this->guard()->assertCanStart();
        $this->guardName($old);
        $successor = $this->migrationSuccessor($old, $catalog);
        $this->guardSelf($old);
        $plan = $this->migrationCheck($old, $successor, $catalog)['plan'];
        $names = array_merge([$old, $successor], self::planPlugins($plan));

        return $this->withPluginLocks($names, fn (): string => $this->guard()->underCatalog(function () use ($old, $successor, $catalog, $dependenciesConfirmed, $confirmedDigest): string {
            $check = $this->migrationCheck($old, $successor, $catalog);
            if ($this->migrationSuccessor($old, $catalog) !== $successor || ! $this->holdsLocks(self::planPlugins($check['plan']))) {
                throw new ModMenuException(t(self::MIGRATION_PLAN_CHANGED_MESSAGE), self::MIGRATION_PLAN_CHANGED);
            }
            $this->runMigration($old, $successor, $catalog, $dependenciesConfirmed, $confirmedDigest, $check);
            return $successor;
        }));
    }

    private function runMigration(string $old, string $successor, array $catalog, bool $dependenciesConfirmed, ?string $confirmedDigest, array $check): void
    {
        if ($confirmedDigest !== null && ! hash_equals($check['plan_digest'], $confirmedDigest)) {
            throw new ModMenuException(t(self::MIGRATION_PLAN_CHANGED_MESSAGE), self::MIGRATION_PLAN_CHANGED);
        }
        if ($check['self_update']) {
            throw new ModMenuException(e(self::MIGRATION_SELF_UPDATE_MESSAGE));
        }
        $this->prepareMigration($old, $catalog);
        $doc = $this->privateState()->read();
        $this->assertPlanSources($check['plan'], $this->dependencySource($successor, $doc), $doc);
        $this->assertMigrationConflicts($old, $successor, $catalog);
        if ($check['blocked'] || (! $check['satisfied'] && ! $dependenciesConfirmed)) {
            throw new ModMenuException(t('"%s" has unmet requirements.', $successor));
        }

        $this->catalog = $catalog;
        $before = $this->installedMap();
        $journal = [];
        try {
            $disabled = $this->disabledDir();
            if (! is_dir($disabled) && @mkdir($disabled, 0755, true)) {
                $journal[] = [static function () use ($disabled): void {
                    // A different plugin operation may now be using this directory.
                    if (scandir($disabled) === ['.', '..'] && ! @rmdir($disabled)) {
                        throw new ModMenuException(t('Could not remove the target directory.'));
                    }
                }, null];
            }
            if ($check['install']) {
                try {
                    $this->installForMigration((string) (self::entryIn($catalog, $successor)['download'] ?? ''), $successor, $journal);
                } catch (ModMenuException $e) {
                    throw new ModMenuException(
                        e("Couldn't install %s, so %s stays active.", htmlspecialchars($successor), htmlspecialchars($old)) . ' ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
                $this->assertMigrationConflicts($old, $successor, $catalog);
                $this->assertMigrationRequirements($old, $successor, $catalog, $check['plan']);
            }

            $this->resolveDependencies($check['plan'], $journal);
            if ($check['plan'] !== []) {
                $this->assertMigrationRequirements($old, $successor);
                $this->assertMigrationConflicts($old, $successor, $catalog);
            }

            if ($before[$old]['status'] === 'active') {
                $this->disable($old, $successor);
                $journal[] = [fn () => $this->move($old, $this->disabledDir(), $this->activeDir()), null];
            }
            if (($before[$successor]['status'] ?? 'disabled') === 'disabled') {
                $this->enable($successor);
            }
        } catch (\Throwable $e) {
            $errors = [$e->getMessage()];
            foreach (array_reverse($journal) as [$undo]) {
                try {
                    $undo();
                } catch (\Throwable $rollbackError) {
                    $errors[] = $rollbackError->getMessage();
                }
            }
            $message = count($errors) === 1
                ? e("Couldn't migrate %s to %s, so nothing changed.", htmlspecialchars($old), htmlspecialchars($successor))
                : e("Couldn't fully roll back the migration from %s to %s.", htmlspecialchars($old), htmlspecialchars($successor));
            throw new ModMenuException($message . ' ' . implode(' ', $errors), 0, $e);
        }

        foreach ($journal as [, $finish]) {
            if ($finish !== null) {
                $finish();
            }
        }
    }

    private function installForMigration(string $url, string $name, array &$journal, bool $activate = false): void
    {
        $this->downloadAndInstall($url, $name, true);
        $disabled = $this->disabledDir();
        $path = $disabled . '/' . $name;
        $activated = false;
        $journal[] = [function () use ($name, $disabled, $path, &$activated): void {
            if ($activated) {
                $this->move($name, $this->activeDir(), $disabled);
            }
            $this->removePluginCopy($name, $path);
        }, null];

        if ($activate) {
            $this->enable($name);
            $activated = true;
        }
    }

    /** Check the installed metadata against the state the confirmed plan would produce. */
    private function assertMigrationRequirements(string $old, string $successor, array $catalog = [], array $plan = []): void
    {
        $map = $this->installedMap();
        $map[$old]['status'] = 'disabled';
        foreach ($plan as $step) {
            $name = $step['plugin'];
            if ($step['action'] !== 'enable') {
                $map[$name] = self::entryIn($catalog, (string) $name) ?? [];
            }
            $map[$name]['status'] = 'active';
        }
        $requires = $this->installedPluginsDeps()[$successor]['requires'] ?? [];
        $resolver = new DependencyResolver($this->container);
        if (! $resolver->resolveForward($requires, 'requires', $map, [])['satisfied']) {
            throw new ModMenuException(t('"%s" has unmet requirements.', $successor));
        }
    }

    public function uninstall(string $name): void
    {
        $this->guardName($name);
        $this->guardSelf($name);
        $this->withPluginLock($name, function () use ($name): void {
            // Needs no fetch, but a damaged state refuses it like every mutation.
            $doc = $this->privateState()->read();
            $this->assertNoActiveDependents($name);
            foreach ([$this->activeDir(), $this->disabledDir()] as $base) {
                $path = $base . '/' . $name;
                if (is_dir($path)) {
                    $this->beforeCommit($name);
                    $this->removePluginCopy($name, $path);
                    $this->forgetProvenance($name, $doc);
                    return;
                }
            }
            throw new ModMenuException(t('Plugin "%s" is not installed.', $name));
        });
    }

    /**
     * After a plugin's files are gone, drop its binding and dismissals, unless
     * another installed folder still has its key.
     */
    private function forgetProvenance(string $name, array $doc): void
    {
        $key = PluginName::key($name);
        if ($key === null || isset($this->installedKeys()[$key]) || ! self::hasProvenance($key, $doc)) {
            return;
        }
        $this->privateState()->mutate(static function (array $fresh) use ($key): array {
            unset($fresh['bindings'][$key]);
            return self::withoutDismissals($fresh, $key);
        });
    }

    /** $doc with $key removed from every dismissed list (empty lists dropped). */
    private static function withoutDismissals(array $doc, string $key): array
    {
        foreach ($doc['dismissed'] as $id => $keys) {
            $doc['dismissed'][$id] = array_values(array_diff($keys, [$key]));
            if ($doc['dismissed'][$id] === []) {
                unset($doc['dismissed'][$id]);
            }
        }
        return $doc;
    }

    private static function hasProvenance(string $key, array $doc): bool
    {
        if (isset($doc['bindings'][$key])) {
            return true;
        }
        foreach ($doc['dismissed'] as $keys) {
            if (in_array($key, $keys, true)) {
                return true;
            }
        }
        return false;
    }

    private function removePluginCopy(string $name, string $path): void
    {
        if (! $this->removeTree($path)) {
            throw new ModMenuException(t('Could not remove "%s". Its folder may be a bind mount or read-only.', $name));
        }
    }

    /**
     * Download and install a plugin archive. When $expectedName is given (a
     * catalog entry's name), the archive's folder and any catalog SHA-256
     * checksum must match.
     */
    public function installFromUrl(string $url, ?string $expectedName = null, bool $installDisabled = false): string
    {
        return $this->downloadAndInstall($url, $expectedName, $installDisabled);
    }

    private function downloadAndInstall(string $url, ?string $expectedName, bool $installDisabled, ?array &$journal = null): string
    {
        $this->guard()->assertCanStart();
        // Found by key, then (R17) the entry's own name must equal $expectedName
        // byte for byte: a case variant is never installed in either direction.
        $entry = $expectedName === null ? null : $this->catalogEntry($expectedName);
        if ($expectedName !== null) {
            $this->assertListedAs($expectedName);
        }
        $private = self::privateSourceOf($entry) !== null;
        $sha256 = null;
        if ($entry !== null && (array_key_exists('sha256', $entry) || $private)) {
            $sha256 = $entry['sha256'] ?? null;
            if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/i', $sha256) !== 1) {
                throw new ModMenuException(t('The directory lists an invalid checksum for this plugin. Nothing was installed.'));
            }
        }

        // Checked again under the plugin and catalog locks; this saves a download.
        $doc = $this->privateState()->read();
        if ($expectedName !== null) {
            $this->assertSourceAllowed($expectedName, $entry, $doc);
        }

        if ($private) {
            // Auth context comes from the stored source the entry names, never from the request.
            $source = $doc['sources'][$entry['source_id']] ?? null;
            if ($source === null) {
                throw new ModMenuException(e(self::PRIVATE_UNAVAILABLE));
            }
            $tmp = $this->privateFetcher()->downloadRelease(
                ['id' => (string) $entry['source_id'], 'url' => $source['url'], 'token_ref' => $source['token_ref']],
                (string) $entry['download']
            );
        } else {
            $tmp = ($this->archiveDownloader ??= new ArchiveDownloader())->download($url);
        }

        try {
            if ($sha256 !== null) {
                $actual = @hash_file('sha256', $tmp);
                if ($actual === false || ! hash_equals(strtolower($sha256), $actual)) {
                    throw new ModMenuException(t('The downloaded archive does not match the checksum in the directory. Nothing was installed.'));
                }
            }
            return $this->installArchive($tmp, $expectedName, $installDisabled, $journal, $entry);
        } finally {
            @unlink($tmp);
        }
    }

    private function privateFetcher(): object
    {
        return isset($this->container['modMenuPrivateFetcher'])
            ? $this->container['modMenuPrivateFetcher']
            : new PrivateFetcher($this->container);
    }

    /**
     * The source id of a private catalog entry, or null for a public one.
     *
     * @throws ModMenuException for a private entry without a valid source id
     */
    private static function privateSourceOf(?array $entry): ?string
    {
        if (empty($entry['private'])) {
            return null;
        }
        $id = $entry['source_id'] ?? null;
        if (! is_string($id) || preg_match('/\A[0-9a-f]{64}\z/', $id) !== 1) {
            throw new ModMenuException(e(self::PRIVATE_UNAVAILABLE));
        }
        return $id;
    }

    /** @throws ModMenuException when $name may not come from $entry */
    private function assertSourceAllowed(string $name, ?array $entry, array $doc): void
    {
        $problem = $this->sourceProblem($name, $entry, $doc, $this->installedKeys());
        if ($problem !== null) {
            throw new ModMenuException($problem);
        }
    }

    /**
     * Why $name may not be installed or updated from $entry (null: an
     * archive with no catalog entry), or null when it may.
     *  - two installed folders with its key, or one under another spelling;
     *  - a bound plugin comes only from its source's single entry;
     *  - an installed unbound plugin is never bound by an update, nor updated
     *    from a public entry or an unlisted archive (upload, install by URL)
     *    while a private source lists it (seen, not dismissed).
     */
    private function sourceProblem(string $name, ?array $entry, array $doc, array $installedKeys): ?string
    {
        $key = PluginName::key($name);
        if ($key === null) {
            return t('Invalid plugin name.');
        }
        $folders = $installedKeys[$key] ?? [];
        if (count($folders) > 1) {
            return e(self::INSTALLED_DUPLICATE, $name);
        }
        if ($folders !== [] && $folders[0] !== $name) {
            return t('"%s" is already installed.', $folders[0]);
        }

        try {
            $sourceId = self::privateSourceOf($entry);
        } catch (ModMenuException $e) {
            return $e->getMessage();
        }
        $bound = $doc['bindings'][$key] ?? null;
        if ($bound !== null) {
            if ($sourceId !== $bound || ! isset($doc['sources'][$bound]) || $this->catalogCount($key) > 1) {
                return e(self::PRIVATE_UNAVAILABLE);
            }
            return null;
        }
        if ($folders === []) {
            return null;
        }
        if ($sourceId !== null || self::seenUnbound($key, $doc)) {
            return e(self::ADOPT_OR_DISMISS);
        }
        return null;
    }

    private function catalogCount(string $key): int
    {
        $count = 0;
        foreach (array_keys($this->catalog) as $name) {
            if (PluginName::key((string) $name) === $key) {
                $count++;
            }
        }
        return $count;
    }

    /** Whether a private source lists $key and has not been dismissed for it. */
    private static function seenUnbound(string $key, array $doc): bool
    {
        foreach ($doc['seen'] as $id => $keys) {
            if (in_array($key, $keys, true) && ! in_array($key, $doc['dismissed'][$id] ?? [], true)) {
                return true;
            }
        }
        return false;
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
     * Validate the archive, then stage, verify and move it under a per-plugin
     * lock shared by active and disabled installs. If an ACTIVE plugin of
     * the same name exists it is replaced (update path). A DISABLED copy blocks
     * install. ModMenu itself must be newer than the installed copy, and keeps
     * its previous copy until the new version has loaded (Plugin::initialize).
     */
    private function installArchive(string $zipPath, ?string $expectedName = null, bool $installDisabled = false, ?array &$journal = null, ?array $entry = null): string
    {
        $archive = new PluginArchive($this->container);
        $name = $archive->inspect($zipPath);

        $this->guardName($name);

        if ($expectedName !== null && $name !== $expectedName) {
            throw new ModMenuException(t('The archive for "%s" contains "%s". Nothing was installed.', $expectedName, $name));
        }

        $lock = $this->acquirePluginLock($name);
        try {
            $this->guard()->underCatalog(function (array $doc) use ($archive, $zipPath, $name, $installDisabled, &$journal, $entry): void {
                $this->assertSourceAllowed($name, $entry, $doc);
                $this->assertInstallDestinations($name, $installDisabled);
                $destination = $this->activeDir();
                if ($installDisabled) {
                    $this->guardSelf($name);
                    $destination = $this->disabledDir();
                    if (! is_dir($destination) && ! @mkdir($destination, 0755, true) && ! is_dir($destination)) {
                        throw new ModMenuException(t('Could not create the target directory.'));
                    }
                }

                $swap = new PluginSwap($destination);
                $previous = $swap->previousDir($name);
                if ($journal !== null && (file_exists($previous) || is_link($previous))) {
                    throw new ModMenuException(t('The previous copy of "%s" is still pending. Finish that update first.', $name));
                }
                $staging = $swap->createStaging();
                try {
                    $this->stageArchive($archive, $zipPath, $staging);
                    $staged = $staging . '/' . $name;
                    $isSelf = $name === self::SELF;
                    $version = $swap->verify($staged, $name, $isSelf ? $this->installedVersion(self::SELF) : null);
                    $this->assertInstallDestinations($name, $installDisabled);
                    if (! $installDisabled && $journal !== null && ! is_dir($destination . '/' . $name)) {
                        throw new ModMenuException(t('Plugin "%s" was not found.', $name));
                    }
                    // Bound before activation; a failed activation keeps the binding.
                    $this->bind($name, self::privateSourceOf($entry));
                    $this->beforeCommit($name);
                    if ($installDisabled) {
                        if (! @rename($staged, $destination . '/' . $name)) {
                            throw new ModMenuException(t('Could not move the new %s into place.', $name));
                        }
                    } else {
                        $swap->swap($staged, $name, $isSelf || $journal !== null);
                        if ($journal !== null) {
                            $journal[] = [
                                fn () => $this->rollbackMigrationUpdate($name, $previous),
                                $isSelf ? null : static fn () => $swap->finalize($name, $version),
                            ];
                        }
                    }
                } finally {
                    PluginSwap::removeTree($staging);
                }
            });
        } finally {
            if ($lock !== null) {
                fclose($lock);
            }
        }

        return $name;
    }

    /** Record $name's binding to $sourceId (no write for a public install). */
    private function bind(string $name, ?string $sourceId): void
    {
        if ($sourceId === null) {
            return;
        }
        $key = PluginName::key($name);
        $this->privateState()->mutate(static function (array $doc) use ($key, $sourceId): array {
            $doc['bindings'][$key] = $sourceId;
            return $doc;
        });
    }

    /**
     * Test seam: runs at the last point before an operation changes plugin
     * files or provenance, while every lock it needs is held.
     */
    protected function beforeCommit(string $name): void
    {
    }

    private function rollbackMigrationUpdate(string $name, string $previous): void
    {
        $parked = $this->disabledDir() . '/.modmenu-migration-' . bin2hex(random_bytes(8));
        if (! @mkdir($parked, 0755)) {
            throw new ModMenuException(t('Could not create the target directory.'));
        }
        try {
            $this->move($name, $this->activeDir(), $parked);
        } catch (\Throwable $e) {
            @rmdir($parked);
            throw $e;
        }

        $target = $this->activeDir() . '/' . $name;
        if (! @rename($previous, $target)) {
            throw new ModMenuException(t('Could not restore the previous version of %s.', $name));
        }
        PluginSwap::invalidateOpcache($target);
        $this->removePluginCopy($name, $parked);
    }

    private function withPluginLock(string $name, callable $operation): void
    {
        $this->withPluginLocks([$name], $operation);
    }

    /**
     * Run $operation holding every named plugin's lock. Names are deduplicated
     * by PluginName::key() and locked in ascending key order, so two
     * operations never wait on each other in opposite orders; a busy lock is
     * refused, never waited for. Locks already held are reused.
     */
    public function withPluginLocks(array $names, callable $operation): mixed
    {
        $byKey = [];
        foreach ($names as $name) {
            $key = PluginName::key((string) $name);
            if ($key === null) {
                throw new ModMenuException(t('Invalid plugin name.'));
            }
            $byKey[$key] ??= (string) $name;
        }
        ksort($byKey, SORT_STRING);

        $acquired = [];
        try {
            foreach ($byKey as $key => $name) {
                $lock = $this->acquirePluginLock($name);
                if ($lock !== null) {
                    $this->heldLocks[$key] = $lock;
                    $acquired[] = $key;
                }
            }
            return $operation();
        } finally {
            foreach (array_reverse($acquired) as $key) {
                fclose($this->heldLocks[$key]);
                unset($this->heldLocks[$key]);
            }
        }
    }

    private function holdsLocks(array $names): bool
    {
        foreach ($names as $name) {
            if (! isset($this->heldLocks[PluginName::key((string) $name) ?? ''])) {
                return false;
            }
        }
        return true;
    }

    /** Plugins a dependency plan would touch; unresolvable steps touch nothing. */
    private static function planPlugins(array $plan): array
    {
        $names = [];
        foreach ($plan as $step) {
            if (($step['action'] ?? '') !== 'unresolvable') {
                $names[] = (string) ($step['plugin'] ?? '');
            }
        }
        return $names;
    }

    /** @return resource|null A handle to close, or null when this manager already holds the lock. */
    protected function acquirePluginLock(string $name)
    {
        $key = PluginName::key($name);
        if ($key === null) {
            throw new ModMenuException(t('Invalid plugin name.'));
        }
        if (isset($this->heldLocks[$key])) {
            return null;
        }
        // Keep the file in the data directory so overlapping requests always
        // lock the same inode, even after a previous install closes its handle.
        $lockPath = dirname($this->disabledDir()) . '/.modmenu-install-' . hash('sha256', $key) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new ModMenuException(t('Could not lock "%s" for installation.', $name));
        }

        if (! @flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);
            throw new ModMenuException($wouldBlock
                ? t('%s is busy with another ModMenu action. Try again in a moment.', $name)
                : t('Could not lock "%s" for installation.', $name));
        }

        return $lock;
    }

    protected function stageArchive(PluginArchive $archive, string $zipPath, string $staging): void
    {
        $archive->extractTo($zipPath, $staging);
    }

    private function assertInstallDestinations(string $name, bool $installDisabled): void
    {
        clearstatcache(true);
        $disabled = $this->disabledDir() . '/' . $name;
        if (file_exists($disabled) || is_link($disabled)) {
            throw new ModMenuException(t('"%s" is already installed but disabled. Enable it instead.', $name));
        }

        $active = $this->activeDir() . '/' . $name;
        if ($installDisabled && (file_exists($active) || is_link($active))) {
            throw new ModMenuException(t('"%s" is already installed.', $name));
        }
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

        // Across filesystems, stage the copy first. Move the source aside
        // atomically before publishing, so a failed move cannot delete part
        // of the original or leave both copies visible to the plugin manager.
        $staging = (new PluginSwap($to))->createStaging();
        $staged = $staging . '/' . $name;
        $aside = $from . '/.modmenu-move-' . bin2hex(random_bytes(8));
        try {
            if ($this->copyTree($src, $staged) && @rename($src, $aside)) {
                if (! file_exists($dst) && ! is_link($dst) && @rename($staged, $dst)) {
                    PluginSwap::removeTree($aside);
                    return;
                }
                if (! @rename($aside, $src)) {
                    throw new ModMenuException(t('Could not restore "%s". Its original folder is at %s.', $name, $aside));
                }
            }
        } finally {
            PluginSwap::removeTree($staging);
        }

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
        try {
            return $this->updateRows($catalog)['available'];
        } catch (PrivateStateDamaged $e) {
            return [];
        }
    }

    /**
     * Newer catalog versions of active plugins, split into those that may be
     * installed and those their provenance blocks ([name => reason]). A bound
     * plugin whose source has no usable entry is blocked too.
     */
    private function updateRows(array $catalog): array
    {
        $saved = $this->catalog;
        $this->catalog = $catalog;
        try {
            $doc = $this->privateState()->read();
            $installedKeys = $this->installedKeys();
            $out = [];
            $blocked = [];
            $self = null;
            foreach ($this->listInstalled() as $p) {
                $entry = self::entryIn($catalog, (string) $p['name']);
                $key = PluginName::key((string) $p['name']);
                $bound = $key !== null && isset($doc['bindings'][$key]);
                if ($p['status'] !== 'active') {
                    continue;
                }
                if (! is_array($entry) || empty($entry['download'])) {
                    if ($bound && $this->sourceProblem($p['name'], null, $doc, $installedKeys) !== null) {
                        $blocked[$p['name']] = e(self::PRIVATE_UNAVAILABLE);
                    }
                    continue;
                }
                $to = (string) ($entry['version'] ?? '');
                $listed = $this->listedName((string) $p['name']);
                if ($listed !== $p['name']) {
                    if (self::hasUpdate((string) $p['version'], $to)) {
                        $blocked[$p['name']] = t(self::LISTED_AS, $listed, $p['name']);
                    }
                    continue;
                }
                $problem = $this->sourceProblem($p['name'], $entry, $doc, $installedKeys);
                if ($problem !== null && ($bound || self::hasUpdate((string) $p['version'], $to))) {
                    $blocked[$p['name']] = $problem;
                    continue;
                }
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
            return ['available' => $out, 'blocked' => $blocked];
        } finally {
            $this->catalog = $saved;
        }
    }

    /**
     * Install every available update. A failure is recorded and the rest
     * still run; plugins whose provenance blocks an update are reported as
     * failed. Returns ['updated' => [names], 'failed' => [name => message]].
     */
    public function updateAll(array $catalog, ?MutationGuard $guard = null): array
    {
        $this->setCatalog($catalog, $guard ?? $this->guard);
        $this->guard()->assertCanStart();
        $rows = $this->updateRows($catalog);
        $result = ['updated' => [], 'failed' => []];
        foreach ($rows['available'] as $u) {
            try {
                $this->installFromUrl($u['download'], $u['plugin']);
                $result['updated'][] = $u['plugin'];
            } catch (ModMenuException $e) {
                $result['failed'][$u['plugin']] = $e->getMessage();
            }
        }
        $result['failed'] += $rows['blocked'];
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
        // Names compare by PluginName::key(): superseded_by may spell the folder differently.
        $folder = $replacement === null ? null : PluginName::find($map, $replacement);
        $declared = $map[$name]['deprecated']['superseded_by'] ?? null;
        if ($folder !== null && is_string($declared) && PluginName::same($declared, $folder)) {
            // Preserve dependencies that the verified replacement will satisfy after the switch.
            $after = $map;
            $after[$name]['status'] = 'disabled';
            $after[$folder]['status'] = 'active';
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
    public function forwardCheck(array $requires, array $catalog, ?array $installedMap = null, ?string $sourceId = null): array
    {
        $resolver = new DependencyResolver($this->container);
        $map = $installedMap ?? $this->installedMap();
        $forward = $resolver->resolveForward($requires, 'requires', $map, $catalog, $sourceId);
        if ($forward['satisfied']) {
            return ['satisfied' => true, 'plan' => [], 'blocked' => false, 'requires' => $forward['deps']];
        }
        $plan = $resolver->resolveClosure($requires, $map, $catalog, $sourceId);
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
        $this->guard()->assertCanStart();
        if ($action === 'install') {
            // Before any dependency is installed or enabled for it.
            $this->assertListedAs($name);
        }
        $this->withPluginLocks(array_merge([$name], self::planPlugins($plan)), fn () => $this->guard()->underCatalog(function (array $doc) use ($name, $action, $target, $plan): void {
            $this->assertPlanSources($plan, $this->dependencySource($name, $doc), $doc);
            $this->resolveDependencies($plan);
            if ($action === 'install') {
                $this->installFromUrl($target, $name);
            } else {
                $this->enable($name);
            }
        }));
    }

    /**
     * The source a plugin's dependencies must come from: its binding when
     * bound (only its source counts), else its private catalog entry's
     * source, else null (public).
     */
    private function dependencySource(string $name, array $doc): ?string
    {
        $key = PluginName::key($name);
        return ($key === null ? null : ($doc['bindings'][$key] ?? null))
            ?? self::privateSourceOf($this->catalogEntry($name));
    }

    /** The catalog entry that is the same plugin as $name (PluginName::key), or null. */
    private function catalogEntry(string $name): ?array
    {
        return self::entryIn($this->catalog, $name);
    }

    /**
     * R17: a catalog entry found for $name by key must be named $name byte
     * for byte; nothing is installed, enabled or disabled for a case variant.
     *
     * @throws ModMenuException
     */
    private function assertListedAs(string $name): void
    {
        $listed = $this->listedName($name);
        if ($listed !== null && $listed !== $name) {
            throw new ModMenuException(t(self::LISTED_AS, $listed, $name));
        }
    }

    /** The catalog's own spelling of $name: the entry's `name`, else its key. */
    private function listedName(string $name): ?string
    {
        $found = PluginName::find($this->catalog, $name);
        if ($found === null) {
            return null;
        }
        $listed = $this->catalog[$found]['name'] ?? $found;
        return is_string($listed) ? $listed : $found;
    }

    /** The entry of $catalog that is the same plugin as $name (PluginName::key), or null. */
    private static function entryIn(array $catalog, string $name): ?array
    {
        $found = PluginName::find($catalog, $name);
        $entry = $found === null ? null : $catalog[$found];
        return is_array($entry) ? $entry : null;
    }

    /**
     * A plan from the caller may not install a dependency a private plugin
     * must not get from another source: with a private dependant, every
     * install/update step comes from its source; and the requirements of
     * every private step (installed or enabled, by its binding) planned here
     * come from that step's source.
     *
     * @throws ModMenuException (plan changed)
     */
    private function assertPlanSources(array $plan, ?string $dependantSource, array $doc): void
    {
        $installed = [];
        $requires = [];
        $deps = null;
        foreach ($plan as $step) {
            $plugin = (string) ($step['plugin'] ?? '');
            $action = $step['action'] ?? '';
            if (in_array($action, ['install', 'update'], true)) {
                $entry = $this->catalogEntry($plugin);
                $installed[PluginName::key($plugin) ?? $plugin] = self::privateSourceOf($entry);
                $requires[$plugin] = self::normalizeDeps($entry['requires'] ?? []);
            } elseif ($action === 'enable') {
                $deps ??= $this->installedPluginsDeps();
                $requires[$plugin] = $deps[$plugin]['requires'] ?? [];
            }
        }
        $refuse = static fn () => new ModMenuException(t(self::MIGRATION_PLAN_CHANGED_MESSAGE), self::MIGRATION_PLAN_CHANGED);
        foreach ($installed as $source) {
            if ($dependantSource !== null && $source !== $dependantSource) {
                throw $refuse();
            }
        }
        foreach ($requires as $plugin => $list) {
            $source = $this->dependencySource($plugin, $doc);
            if ($source === null) {
                continue;
            }
            foreach ($list as $dep) {
                $depKey = PluginName::key((string) $dep['plugin']) ?? (string) $dep['plugin'];
                if (array_key_exists($depKey, $installed) && $installed[$depKey] !== $source) {
                    throw $refuse();
                }
            }
        }
    }

    // ── provenance actions ───────────────────────────────────────────────

    /**
     * Bind an installed, unbound plugin to a private source that listed it in
     * this request's fetch. Clears its dismissals.
     */
    public function adopt(string $name, string $sourceId, array $fetchResult): void
    {
        $guard = new MutationGuard($this->container, $fetchResult);
        $guard->assertCanStart();
        $this->guardName($name);
        $key = PluginName::key($name);
        if ($key === null) {
            throw new ModMenuException(t('Invalid plugin name.'));
        }

        $this->withPluginLock($name, fn () => $guard->underCatalog(function (array $doc) use ($guard, $name, $key, $sourceId): void {
            $this->assertInstalledUnbound($name, $key, $doc);
            if (! isset($doc['sources'][$sourceId])) {
                throw new ModMenuException(e(self::PRIVATE_UNAVAILABLE));
            }
            if (! in_array($key, $guard->listed()[$sourceId] ?? [], true)) {
                throw new ModMenuException(e(self::NOT_LISTED, $name));
            }
            $this->beforeCommit($name);
            $this->privateState()->mutate(static function (array $fresh) use ($key, $sourceId, $name): array {
                if (isset($fresh['bindings'][$key]) || ! isset($fresh['sources'][$sourceId])) {
                    throw new ModMenuException(e(MutationGuard::CHANGED));
                }
                $fresh['bindings'][$key] = $sourceId;
                return self::withoutDismissals($fresh, $key);
            });
        }));
    }

    /** One installed folder named exactly $name, with no binding. */
    private function assertInstalledUnbound(string $name, string $key, array $doc): void
    {
        $folders = $this->installedKeys()[$key] ?? [];
        if (count($folders) > 1) {
            throw new ModMenuException(e(self::INSTALLED_DUPLICATE, $name));
        }
        if ($folders !== [$name]) {
            throw new ModMenuException(t('Plugin "%s" is not installed.', $name));
        }
        if (isset($doc['bindings'][$key])) {
            throw new ModMenuException(e('%s is already bound to a private source', $name));
        }
    }

    /** Drop a plugin's binding. Needs no fetch; refused while the state is damaged. */
    public function unbind(string $name): void
    {
        $this->guardName($name);
        $key = PluginName::key($name);
        if ($key === null) {
            throw new ModMenuException(t('Invalid plugin name.'));
        }

        $this->withPluginLock($name, function () use ($name, $key): void {
            if (! isset($this->privateState()->read()['bindings'][$key])) {
                throw new ModMenuException(t('%s is not bound to a private source, so there is nothing to unbind.', $name));
            }
            $this->beforeCommit($name);
            $this->privateState()->mutate(static function (array $fresh) use ($key): array {
                unset($fresh['bindings'][$key]);
                return $fresh;
            });
        });
    }

    /**
     * Let public sources update a plugin that a private source lists, by
     * recording a durable dismissal for that source.
     */
    public function dismiss(string $name, string $sourceId, array $fetchResult): void
    {
        $guard = new MutationGuard($this->container, $fetchResult);
        $guard->assertCanStart();
        $this->guardName($name);
        $key = PluginName::key($name);
        if ($key === null) {
            throw new ModMenuException(t('Invalid plugin name.'));
        }

        $this->withPluginLock($name, fn () => $guard->underCatalog(function (array $doc) use ($name, $key, $sourceId): void {
            $this->assertInstalledUnbound($name, $key, $doc);
            $this->privateState()->mutate(static function (array $fresh) use ($name, $key, $sourceId): array {
                if (isset($fresh['bindings'][$key])) {
                    throw new ModMenuException(e(MutationGuard::CHANGED));
                }
                if (! in_array($key, $fresh['seen'][$sourceId] ?? [], true)) {
                    throw new ModMenuException(e(self::NOT_LISTED, $name));
                }
                if (! in_array($key, $fresh['dismissed'][$sourceId] ?? [], true)) {
                    $fresh['dismissed'][$sourceId][] = $key;
                }
                return $fresh;
            });
        }));
    }

    public function resolveDependencies(array $plan, ?array &$journal = null): void
    {
        foreach ($plan as $step) {
            if (($step['action'] ?? '') === 'unresolvable') {
                throw new ModMenuException(t('"%s" cannot be resolved automatically. Install it manually first.', $step['plugin'] ?? '?'));
            }
        }
        foreach ($plan as $step) {
            $name = $step['plugin'];
            switch ($step['action']) {
                case 'install':
                    if ($journal === null) {
                        $this->installFromUrl((string) $step['download'], $name);
                        break;
                    }
                    $this->installForMigration((string) $step['download'], $name, $journal, true);
                    break;
                case 'enable':
                    $this->enable($name);
                    if ($journal !== null) {
                        // Undo only our own activation, regardless of current dependency checks.
                        $journal[] = [fn () => $this->move($name, $this->activeDir(), $this->disabledDir()), null];
                    }
                    break;
                case 'update':
                    if ($journal === null) {
                        $this->installFromUrl((string) $step['download'], $name);
                    } else {
                        $this->downloadAndInstall((string) $step['download'], $name, false, $journal);
                    }
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

    protected function removeTree(string $dir): bool
    {
        return PluginSwap::removeTree($dir);
    }
}
