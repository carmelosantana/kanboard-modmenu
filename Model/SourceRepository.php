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
 *
 * Private sources live in PrivateState. Every source change, public or
 * private, runs under the exclusive catalog lock, re-reads both stores fresh,
 * refuses a key that is already present and advances the state generation
 * before the public list is touched. With no state file and no private
 * source, public changes leave the private state alone (1.6.0 behaviour).
 */
class SourceRepository extends Base
{
    const CONFIG_KEY = 'modmenu_sources';
    const DEFAULT_SOURCE = 'https://raw.githubusercontent.com/carmelosantana/kanboard-modmenu-directory/main/plugins.json';

    public const NOT_A_PRIVATE_URL = 'Please enter a raw.githubusercontent.com URL to a .json file.';
    public const NOT_A_TOKEN_REF = 'Please enter a vault:// token reference.';

    private const DUPLICATE = 'Duplicate source; remove and re-add it';

    public function getSources(): array
    {
        $raw = $this->configModel->get(self::CONFIG_KEY, '');

        if ($raw === '' || $raw === null) {
            return [self::DEFAULT_SOURCE];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [self::DEFAULT_SOURCE];
    }

    /**
     * Private sources as [id => ['id', 'url', 'token_ref']].
     *
     * @throws \Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged
     */
    public function getPrivateSources(): array
    {
        $sources = [];

        foreach ($this->privateState()->read()['sources'] as $id => $source) {
            $sources[$id] = ['id' => $id, 'url' => $source['url'], 'token_ref' => $source['token_ref']];
        }

        return $sources;
    }

    /**
     * The duplicate key of a source URL: the GitHub endpoint id when it parses
     * as a raw.githubusercontent.com source, else the id of its canonical
     * form. Null when it is neither.
     */
    public static function sourceKey(string $url): ?string
    {
        $canonical = SourceUrl::canonical($url);
        if ($canonical === null) {
            return null;
        }

        // canonical() refuses everything GithubUrl refuses in the authority and only
        // lowercases scheme/host and drops :443, so parsing it covers the raw URL too.
        $github = GithubUrl::source($canonical);

        return $github !== null ? $github['id'] : SourceUrl::id($canonical);
    }

    /**
     * Keys shared by more than one source across the public list and the
     * private sources; every source with one of these fails closed.
     *
     * @return string[]
     */
    public function duplicateKeys(): array
    {
        return $this->duplicateKeysIn($this->privateState()->read());
    }

    /**
     * duplicateKeys() against an already-read state document; pass
     * ['sources' => []] to check the public list alone.
     *
     * @return string[]
     */
    public function duplicateKeysIn(array $doc): array
    {
        $counts = array_count_values($this->keysOf($this->getSources(), $doc));

        return array_keys(array_filter($counts, static fn (int $count) => $count > 1));
    }

    public static function isSecureUrl(mixed $url): bool
    {
        return is_string($url)
            && preg_match('#^https://#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function addSource(string $url): void
    {
        $url = trim($url);
        $canonical = self::isSecureUrl($url) ? SourceUrl::canonical($url) : null;

        if ($canonical === null) {
            throw new ModMenuException(t('Please enter a valid https:// URL.'));
        }

        $this->changeSources(function (array $sources, array $doc) use ($canonical) {
            $key = self::sourceKey($canonical);

            // Re-adding a public source that is already there, once, changes nothing (as in 1.6.0).
            if (in_array($canonical, $sources, true) && count(array_keys($this->keysOf($sources, $doc), $key, true)) === 1) {
                return null;
            }

            $this->refuseDuplicate($key, $sources, $doc);
            $sources[] = $canonical;

            return $sources;
        });
    }

    public function removeSource(string $url): void
    {
        $this->changeSources(static fn (array $sources) => in_array($url, $sources, true)
            ? array_values(array_filter($sources, static fn ($s) => $s !== $url))
            : null);
    }

    /**
     * Adds (or moves back from removed) a private source and records the
     * plugin keys its first fetch listed, in one state write. The caller has
     * already fetched it successfully, outside any lock.
     *
     * @param string[] $listedKeys
     */
    public function addPrivateSource(string $url, string $tokenRef, array $listedKeys): void
    {
        $source = GithubUrl::source(trim($url));

        if ($source === null) {
            throw new ModMenuException(t(self::NOT_A_PRIVATE_URL));
        }

        $tokenRef = trim($tokenRef);

        if (! PrivateState::isTokenRef($tokenRef)) {
            throw new ModMenuException(t(self::NOT_A_TOKEN_REF));
        }

        foreach ($listedKeys as $key) {
            if (! is_string($key) || PluginName::key($key) !== $key) {
                throw new ModMenuException(t('This private source lists an invalid plugin name.'));
            }
        }

        $id = $source['id'];
        $listedKeys = array_values(array_unique($listedKeys));

        $this->catalogLock()->exclusive(function () use ($id, $source, $tokenRef, $listedKeys) {
            $this->memoryCache->flush();
            $sources = $this->getSources();

            $this->privateState()->mutate(function (array $doc) use ($id, $source, $tokenRef, $listedKeys, $sources) {
                $this->refuseDuplicate($id, $sources, $doc);

                unset($doc['removed'][$id]);
                $doc['sources'][$id] = ['url' => $source['endpoint'], 'token_ref' => $tokenRef];
                $doc['seen'][$id] = $listedKeys;
                $doc['generation']++;

                return $doc;
            });
        });
    }

    /**
     * Moves a private source to removed, dropping its seen and dismissed
     * names and keeping its bindings. Needs no fetch; unknown ids are ignored.
     */
    public function removePrivateSource(string $id): void
    {
        $this->catalogLock()->exclusive(function () use ($id) {
            $state = $this->privateState();

            if (! array_key_exists($id, $state->read()['sources'])) {
                return;
            }

            $state->mutate(function (array $doc) use ($id) {
                if (! array_key_exists($id, $doc['sources'])) {
                    return $doc;
                }

                $doc['removed'][$id] = $doc['sources'][$id]['url'];
                unset($doc['sources'][$id], $doc['seen'][$id], $doc['dismissed'][$id]);
                $doc['generation']++;

                return $doc;
            });
        });
    }

    protected function privateState(): PrivateState
    {
        return new PrivateState($this->container);
    }

    protected function catalogLock(): CatalogLock
    {
        return new CatalogLock(PrivateState::defaultDir($this->container));
    }

    protected function saveSources(array $sources): void
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

    /**
     * A public list change: under the exclusive catalog lock, re-read both
     * stores, let $change return the new list (null for no change), advance
     * the generation first when private state exists, then save the list.
     *
     * @param callable(array, array): ?array $change
     */
    private function changeSources(callable $change): void
    {
        $this->catalogLock()->exclusive(function () use ($change) {
            $this->memoryCache->flush();
            $state = $this->privateState();
            $sources = $change($this->getSources(), $state->read());

            if ($sources === null) {
                return;
            }

            if ($state->hasFile()) {
                $state->mutate(function (array $doc) {
                    $doc['generation']++;
                    return $doc;
                });
            }

            $this->saveSources($sources);
        });
    }

    /**
     * @throws ModMenuException when $key is null or already used by a source
     */
    private function refuseDuplicate(?string $key, array $sources, array $doc): void
    {
        if ($key === null || in_array($key, $this->keysOf($sources, $doc), true)) {
            throw new ModMenuException(t(self::DUPLICATE));
        }
    }

    /**
     * The key of every public string (unparseable ones skipped) and every
     * private source id.
     *
     * @return string[]
     */
    private function keysOf(array $sources, array $doc): array
    {
        $keys = [];

        foreach ($sources as $source) {
            $key = is_string($source) ? self::sourceKey($source) : null;

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return array_merge($keys, array_map('strval', array_keys($doc['sources'])));
    }
}
