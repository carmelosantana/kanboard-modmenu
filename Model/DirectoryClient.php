<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;

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

    public function merge(array $sourcesData, array $installedMap): array
    {
        $byName = [];
        foreach ($sourcesData as $source) {
            $entries = $this->normalizeEntries($source['plugins'] ?? [], $source['url'] ?? '', $installedMap);
            foreach ($entries as $plugin) {
                $name = $plugin['name'] ?? '';
                if ($name !== '' && ! isset($byName[$name])) {
                    $plugin['source_url'] = $source['url'] ?? '';
                    $byName[$name] = $plugin;
                }
            }
        }
        return $this->annotateRequirements(array_values($byName), $installedMap);
    }

    /**
     * @return array{plugins: array, errors: array}
     */
    public function fetchAll(): array
    {
        $sourceRepo = new SourceRepository($this->container);
        $manager = new PluginManager($this->container);
        $installedMap = $manager->installedMap();
        $downloader = $this->container['modMenuDirectoryDownloader'] ?? new ArchiveDownloader();

        $sourcesData = [];
        $errors = [];

        foreach ($sourceRepo->getSources() as $url) {
            if (! SourceRepository::isSecureUrl($url)) {
                $errors[] = ['url' => $url, 'message' => t('Skipped: directory sources must use https://. Remove this source and add it again over https.')];
                continue;
            }
            try {
                $sourcesData[] = ['url' => $url, 'plugins' => $downloader->fetchJson($url)];
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'message' => $e->getMessage()];
            }
        }

        return [
            'plugins' => $this->merge($sourcesData, $installedMap),
            'errors' => $errors,
        ];
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
