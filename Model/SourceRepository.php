<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Persists the list of plugin directory source URLs in configModel as a JSON
 * array under CONFIG_KEY. Ships with one bundled default source; the admin can
 * add or remove sources (including the default).
 *
 * Distinguishes "never configured" (→ seed the default) from "configured
 * empty" (→ genuinely no sources) by storing the JSON string only once the
 * admin has touched the list.
 */
class SourceRepository extends Base
{
    const CONFIG_KEY = 'modmenu_sources';
    const DEFAULT_SOURCE = 'https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json';

    public function getSources(): array
    {
        $raw = $this->configModel->get(self::CONFIG_KEY, '');

        if ($raw === '' || $raw === null) {
            return [self::DEFAULT_SOURCE];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [self::DEFAULT_SOURCE];
    }

    /** A catalog names the archives an admin installs, so only https:// sources are fetched. */
    public static function isSecure(string $url): bool
    {
        return (bool) preg_match('#^https://#i', $url);
    }

    /** Stored sources that are fetched: https:// only. */
    public function getSecureSources(): array
    {
        return array_values(array_filter($this->getSources(), [self::class, 'isSecure']));
    }

    /** Stored sources from before https was required; listed so the admin can remove or re-add them. */
    public function getInsecureSources(): array
    {
        return array_values(array_filter($this->getSources(), static fn ($s) => ! self::isSecure((string) $s)));
    }

    public function addSource(string $url): void
    {
        $url = trim($url);
        if (! self::isSecure($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ModMenuException(t('Please enter a valid https:// URL.'));
        }

        $sources = $this->getSources();
        if (! in_array($url, $sources, true)) {
            $sources[] = $url;
        }
        $this->save($sources);
    }

    public function removeSource(string $url): void
    {
        $sources = array_values(array_filter($this->getSources(), static fn ($s) => $s !== $url));
        $this->save($sources);
    }

    private function save(array $sources): void
    {
        $this->configModel->save([self::CONFIG_KEY => json_encode(array_values($sources))]);
        // Kanboard's ConfigModel::get() reads through a proxy memory-cache that is NOT
        // invalidated by configModel->save(). Without a flush, getSources() would return
        // stale data within the same request. A targeted invalidation would require
        // hard-coding the proxy-cache key for ConfigModel::getAll(), which is an
        // implementation detail and not a stable public API. A full flush() is therefore
        // the deliberate, version-robust choice. The cost is at most one extra DB read
        // per request, which is acceptable for these admin-only, low-frequency operations.
        $this->memoryCache->flush();
    }
}
