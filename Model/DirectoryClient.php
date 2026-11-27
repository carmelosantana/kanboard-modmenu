<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;

/**
 * Fetches plugin listings from configured directory sources and annotates each
 * entry with its install status relative to what is on this server, and each
 * declared requirement with its state (requires_state) against the installed map.
 */
class DirectoryClient extends Base
{
    /** Category for entries whose source gives none (forks, third-party directories). */
    public const OTHER_CATEGORY = 'Other';

    /** Browse sort orders; the first is the default. */
    public const SORT_ORDERS = ['updated', 'added', 'author'];

    private const DUPLICATE = 'Duplicate source; remove and re-add it';
    private const NOT_RECORDED = "ModMenu could not record this private source's plugin names";
    private const PRIVATE_FETCH_FAILED = "Couldn't load this private source.";

    /** A YYYY-MM-DD string as given, anything else as ''. */
    private static function isoDate($value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Browse order: 'updated' (last_updated newest first, the default and the
     * fallback for an unknown order), 'added' (oldest first, "First submitted")
     * or 'author' (A–Z, case-insensitive). Entries without the key (date or
     * author) sort last. Ties fall back to title A–Z (title, else name), then
     * to name, so equal titles always land in the same order.
     * Assets/js/modmenu.js mirrors these rules for the client-side re-sort.
     */
    public static function sortPlugins(array $plugins, string $order): array
    {
        $order = in_array($order, self::SORT_ORDERS, true) ? $order : self::SORT_ORDERS[0];
        $field = ['updated' => 'last_updated', 'added' => 'added', 'author' => 'author'][$order];
        $key = static fn (array $p): string => $order === 'author'
            ? mb_strtolower((string) ($p['author'] ?? ''))
            : (string) ($p[$field] ?? '');
        $title = static fn (array $p): string => mb_strtolower((string) ($p['title'] ?? $p['name'] ?? ''));

        usort($plugins, static function (array $a, array $b) use ($order, $key, $title): int {
            $ka = $key($a);
            $kb = $key($b);
            if ($ka === '' || $kb === '') {
                $cmp = ($ka === '') <=> ($kb === '');
            } else {
                $cmp = $order === 'updated' ? strcmp($kb, $ka) : strcmp($ka, $kb);
            }
            if ($cmp === 0) {
                $cmp = strcmp($title($a), $title($b));
            }
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $plugins;
    }

    /** Non-deprecated entries per category, A–Z (case-insensitive) with Other last. */
    public static function categoryCounts(array $plugins): array
    {
        $counts = [];
        foreach ($plugins as $plugin) {
            if (! empty($plugin['deprecated'])) {
                continue;
            }
            $c = (string) ($plugin['category'] ?? '');
            $category = $c === '' ? self::OTHER_CATEGORY : $c;
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }
        uksort($counts, static function ($a, $b): int {
            $a = (string) $a;
            $b = (string) $b;
            $other = ($a === self::OTHER_CATEGORY) <=> ($b === self::OTHER_CATEGORY);
            return $other !== 0 ? $other : strcmp(mb_strtolower($a), mb_strtolower($b));
        });

        return $counts;
    }

    public static function resolveAssetUrl(string $path, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = substr($baseUrl, 0, (int) strrpos($baseUrl, '/'));
        return $base . '/' . ltrim($path, '/');
    }

    public function annotate(array $plugins, string $baseUrl, array $installedMap): array
    {
        $plugins = $this->normalizeEntries($plugins, $baseUrl, $installedMap);
        return $this->annotateRequirements($plugins, $installedMap);
    }

    private function normalizeEntries(array $plugins, string $baseUrl, array $installedMap): array
    {
        foreach ($plugins as &$plugin) {
            $name = $plugin['name'] ?? '';
            $version = (string) ($plugin['version'] ?? '0.0.0');

            if (isset($installedMap[$name])) {
                $installed = $installedMap[$name];
                if ($installed['status'] === 'disabled') {
                    $plugin['status'] = 'disabled';
                } elseif (PluginManager::hasUpdate((string) $installed['version'], $version)) {
                    $plugin['status'] = 'update';
                } else {
                    $plugin['status'] = 'installed';
                }
                $plugin['installed_version'] = $installed['version'];
            } else {
                $plugin['status'] = 'available';
            }

            $category = is_string($plugin['category'] ?? null) ? trim($plugin['category']) : '';
            $plugin['category'] = $category === '' ? self::OTHER_CATEGORY : $category;
            foreach (['added', 'last_updated'] as $field) {
                $plugin[$field] = self::isoDate($plugin[$field] ?? null);
            }

            $plugin['conflicts'] = PluginManager::normalizeConflicts($plugin['conflicts'] ?? []);
            $plugin['replaces'] = PluginManager::normalizeReplaces($plugin['replaces'] ?? []);
            $plugin['deprecated'] = PluginManager::normalizeDeprecated($plugin['deprecated'] ?? null);
            $plugin['requires'] = PluginManager::normalizeDeps($plugin['requires'] ?? []);

            if (! empty($plugin['screenshots']) && is_array($plugin['screenshots'])) {
                $plugin['screenshots'] = array_map(
                    static fn ($s) => self::resolveAssetUrl((string) $s, $baseUrl),
                    $plugin['screenshots']
                );
            }
        }
        unset($plugin);

        return $plugins;
    }

    private function annotateRequirements(array $plugins, array $installedMap): array
    {
        $resolver = new DependencyResolver($this->container);
        $catalog = self::indexByName($plugins);
        foreach ($plugins as &$plugin) {
            $plugin['requires_state'] = $resolver->resolveForward($plugin['requires'], 'requires', $installedMap, $catalog)['deps'];
        }
        return $plugins;
    }

    /**
     * One catalog from every fetched source, keyed by PluginName::key() (each
     * entry keeps its own name). A source with an 'id' is private.
     *
     * The reserved fields 'private' and 'source_id' are stripped from every
     * entry and set only on private ones. Entries with an invalid name, and
     * private entries without a valid sha256 or whose download is not a
     * release in the source's GitHub account, are dropped with a source error.
     * A rejected private entry still counts as a listing (never offered).
     * A key listed more than once is dropped with an error when any listing
     * is private or $privateConfigured; otherwise the first entry wins
     * (1.6.0). For a key bound to a private source only that source's
     * listings count. Quarantined keys are dropped.
     *
     * @param array<string, string> $bindings plugin key => source id
     * @param string[] $quarantined plugin keys
     */
    public function merge(array $sourcesData, array $installedMap, array $bindings = [], array $quarantined = [], array &$errors = [], bool $privateConfigured = false): array
    {
        $candidates = [];
        foreach ($sourcesData as $source) {
            $url = (string) ($source['url'] ?? '');
            $id = isset($source['id']) ? (string) $source['id'] : null;
            $owner = $id === null ? null : (GithubUrl::source($url)['owner'] ?? null);

            foreach ($source['plugins'] ?? [] as $entry) {
                $entry = is_array($entry) ? $entry : [];
                unset($entry['private'], $entry['source_id']);
                $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
                $key = PluginName::key($name);

                if ($key === null) {
                    $errors[] = ['url' => $url, 'message' => e('Skipped an entry with an invalid plugin name: %s', $name)];
                    continue;
                }

                if ($id !== null) {
                    $problem = self::privateEntryProblem($entry, $owner);
                    if ($problem !== null) {
                        $errors[] = ['url' => $url, 'message' => e($problem, $name)];
                        $candidates[$key][] = ['entry' => null, 'name' => $name, 'id' => $id];
                        continue;
                    }
                    $entry['private'] = true;
                    $entry['source_id'] = $id;
                    $entry = self::withoutRelativeScreenshots($entry);
                }

                $entry['source_url'] = $url;
                $candidates[$key][] = ['entry' => $entry, 'name' => $name, 'id' => $id];
            }
        }

        $byKey = [];
        foreach ($candidates as $key => $listed) {
            if (in_array((string) $key, $quarantined, true)) {
                continue;
            }

            $bound = $bindings[$key] ?? null;
            if ($bound !== null) {
                $listed = array_values(array_filter($listed, static fn (array $c) => $c['id'] === $bound));
            }
            if ($listed === []) {
                continue;
            }

            $private = array_filter($listed, static fn (array $c) => $c['id'] !== null) !== [];
            if (count($listed) > 1 && ($private || $privateConfigured)) {
                $errors[] = ['url' => '', 'message' => e('%s is listed by more than one source', $listed[0]['name'])];
                continue;
            }

            $entry = $listed[0]['entry'];
            if ($entry === null) {
                continue;
            }
            $byKey[$key] = $this->normalizeEntries([$entry], $entry['source_url'], $installedMap)[0];
        }

        return $this->annotateRequirements(array_values($byKey), $installedMap);
    }

    /** The error format for a private entry that cannot be offered, or null. */
    private static function privateEntryProblem(array $entry, ?string $owner): ?string
    {
        $sha256 = $entry['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/i', $sha256) !== 1) {
            return 'Skipped %s: a private plugin needs a valid sha256 checksum';
        }

        $download = is_string($entry['download'] ?? null) ? GithubUrl::download($entry['download']) : null;
        if ($download === null || $owner === null || $download['owner'] !== $owner) {
            return "Skipped %s: its download is not a release in this source's GitHub account";
        }

        return null;
    }

    /** Relative screenshots would resolve against the private source URL; keep absolute ones only. */
    private static function withoutRelativeScreenshots(array $entry): array
    {
        if (isset($entry['screenshots']) && is_array($entry['screenshots'])) {
            $entry['screenshots'] = array_values(array_filter(
                $entry['screenshots'],
                static fn ($s) => is_string($s) && preg_match('#^https?://#i', $s) === 1
            ));
        }

        return $entry;
    }

    /**
     * The unique plugin keys a fetched listing names, in listing order.
     *
     * @return string[]
     */
    public static function listedKeys(array $plugins): array
    {
        $keys = [];
        foreach ($plugins as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $key = is_string($name) ? PluginName::key($name) : null;
            if ($key !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Fetches every source (outside any lock), then records the plugin keys
     * each private source listed in one PrivateState::mutate (add-only).
     *
     * private_ok: no private source, or every one fetched and its seen names
     * persisted. frozen: the private state is damaged; only public sources
     * are listed. snapshot: (epoch, generation) of the state this catalog was
     * checked against. quarantined: keys from private responses whose seen
     * names could not be saved. listed: [source id => keys] per private source
     * fetched and recorded.
     *
     * @return array{plugins: array, errors: array, private_ok: bool, frozen: bool, snapshot: array{epoch: ?string, generation: ?int}, quarantined: string[], listed: array<string, string[]>}
     */
    public function fetchAll(): array
    {
        $sourceRepo = new SourceRepository($this->container);
        $manager = new PluginManager($this->container);
        $installedMap = $manager->installedMap();
        $downloader = $this->container['modMenuDirectoryDownloader'] ?? new ArchiveDownloader();
        $state = $this->privateState();

        try {
            $doc = $state->read();
            $duplicates = $sourceRepo->duplicateKeysIn($doc);
        } catch (PrivateStateDamaged $e) {
            $doc = null;
            $duplicates = $sourceRepo->duplicateKeysIn(['sources' => []]);
        }

        $sourcesData = [];
        $errors = [];

        foreach ($sourceRepo->getSources() as $url) {
            if (! SourceRepository::isSecureUrl($url)) {
                $errors[] = ['url' => $url, 'message' => t('Skipped: directory sources must use https://. Remove this source and add it again over https.')];
                continue;
            }
            if (in_array(SourceRepository::sourceKey($url), $duplicates, true)) {
                $errors[] = ['url' => $url, 'message' => e(self::DUPLICATE)];
                continue;
            }
            try {
                $sourcesData[] = ['url' => $url, 'plugins' => $downloader->fetchJson($url)];
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'message' => $e->getMessage()];
            }
        }

        if ($doc === null) {
            return [
                'plugins' => $this->merge($sourcesData, $installedMap, [], [], $errors),
                'errors' => $errors,
                'private_ok' => false,
                'frozen' => true,
                'snapshot' => ['epoch' => null, 'generation' => null],
                'quarantined' => [],
                'listed' => [],
            ];
        }

        $fetched = $this->fetchPrivate($doc['sources'], $duplicates, $errors);
        $snapshot = ['epoch' => $doc['epoch'], 'generation' => $doc['generation']];
        $sources = $doc['sources'];
        $quarantined = [];
        $stale = false;

        if ($fetched !== []) {
            try {
                $written = $state->mutate(static function (array $fresh) use ($fetched) {
                    $fresh['seen'] = array_intersect_key($fresh['seen'], $fresh['sources']);
                    foreach ($fetched as $id => $source) {
                        if (isset($fresh['sources'][$id])) {
                            $seen = $fresh['seen'][$id] ?? [];
                            $fresh['seen'][$id] = array_values(array_unique(array_merge($seen, $source['listed'])));
                        }
                    }
                    return $fresh;
                });
                // Any source change while fetching (its generation moved) makes this catalog stale; the
                // snapshot stays the pre-fetch one so a later mutation refuses it too. Names of a source
                // removed meanwhile are discarded.
                $stale = $written['epoch'] !== $doc['epoch'] || $written['generation'] !== $doc['generation'];
                $sources = $written['sources'];
                $fetched = array_intersect_key($fetched, $sources);
            } catch (\Throwable $e) {
                foreach ($fetched as $source) {
                    $errors[] = ['url' => $source['url'], 'message' => e(self::NOT_RECORDED)];
                    $quarantined = array_merge($quarantined, $source['listed']);
                }
                $quarantined = array_values(array_unique($quarantined));
                $snapshot = ['epoch' => null, 'generation' => null];
                $fetched = [];
            }
        }

        $privateOk = ! $stale && $quarantined === [] && array_diff_key($sources, $fetched) === [];

        return [
            'plugins' => $this->merge(array_merge($sourcesData, array_values($fetched)), $installedMap, $doc['bindings'], $quarantined, $errors, $doc['sources'] !== []),
            'errors' => $errors,
            'private_ok' => $privateOk,
            'frozen' => false,
            'snapshot' => $snapshot,
            'quarantined' => $quarantined,
            'listed' => array_map(static fn (array $source) => $source['listed'], $fetched),
        ];
    }

    /**
     * Fetches each private source not sharing a duplicate key. A fetcher
     * failure that is not a ModMenuException gets the generic message, so
     * nothing unvetted reaches the page.
     *
     * @return array<string, array{id: string, url: string, plugins: array, listed: string[]}>
     */
    private function fetchPrivate(array $sources, array $duplicates, array &$errors): array
    {
        $fetcher = isset($this->container['modMenuPrivateFetcher'])
            ? $this->container['modMenuPrivateFetcher']
            : new PrivateFetcher($this->container);
        $fetched = [];

        foreach ($sources as $id => $source) {
            $id = (string) $id;
            $url = $source['url'];

            if (in_array($id, $duplicates, true)) {
                $errors[] = ['url' => $url, 'message' => e(self::DUPLICATE)];
                continue;
            }

            try {
                $plugins = $fetcher->fetchDirectory(['id' => $id, 'url' => $url, 'token_ref' => $source['token_ref']]);
            } catch (ModMenuException $e) {
                $errors[] = ['url' => $url, 'message' => $e->getMessage()];
                continue;
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'message' => e(self::PRIVATE_FETCH_FAILED)];
                continue;
            }

            $fetched[$id] = ['id' => $id, 'url' => $url, 'plugins' => $plugins, 'listed' => self::listedKeys($plugins)];
        }

        return $fetched;
    }

    /**
     * The whole fetchAll() result with plugins indexed by name, for catalog
     * mutations that need the freshness fields.
     */
    public function catalogResult(): array
    {
        $result = $this->fetchAll();
        $result['plugins'] = self::indexByName($result['plugins']);

        return $result;
    }

    protected function privateState(): PrivateState
    {
        return new PrivateState($this->container);
    }

    /**
     * Merged directory catalog indexed by plugin name, for the dependency
     * resolver/controller. Network wrapper over fetchAll(); returns [] rather
     * than throwing when sources are unreachable (callers degrade gracefully:
     * fewer deps are auto-resolvable, nothing crashes).
     */
    public function catalogMap(): array
    {
        return self::indexByName($this->fetchAll()['plugins']);
    }

    /** Index merged directory entries by plugin name, skipping nameless ones. */
    public static function indexByName(array $plugins): array
    {
        $map = [];
        foreach ($plugins as $entry) {
            if (! empty($entry['name'])) {
                $map[$entry['name']] = $entry;
            }
        }
        return $map;
    }
}
