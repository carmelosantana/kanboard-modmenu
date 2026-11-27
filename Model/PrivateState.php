<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;
use Pimple\Container;

/**
 * The one private-state document: private sources, removed sources, plugin
 * bindings and seen/dismissed names, stored as DATA_DIR/modmenu/private-state.json
 * (0600 in a 0700 directory), never in Kanboard's config table.
 *
 * Every read comes fresh from disk. Writes run under the provenance lock,
 * record the config marker first and replace the file atomically. A file,
 * directory or lock that breaks any rule is damaged state: ModMenu freezes
 * until reset().
 */
class PrivateState extends Base
{
    public const MARKER = 'modmenu_private_initialized';

    private const FILE = 'private-state.json';
    private const KEYS = ['bindings', 'dismissed', 'epoch', 'generation', 'removed', 'seen', 'sources', 'version'];
    private const MAPS = ['sources', 'removed', 'bindings', 'seen', 'dismissed'];
    private const NOT_RECORDED = 'ModMenu could not record its private state';
    private const INITIAL_EPOCH = '00000000000000000000000000000000';

    private string $dir;
    private string $lockPath;

    public function __construct(Container $container, ?string $dir = null)
    {
        parent::__construct($container);
        $this->dir = $dir ?? ($container['modMenuPrivateStateDir'] ?? DATA_DIR.'/modmenu');
        $this->lockPath = dirname($this->dir).'/modmenu-provenance.lock';
    }

    /**
     * The current document, read from disk. A missing file is the empty
     * initial state (fixed all-zero epoch, so snapshots of it stay comparable)
     * only while the marker is unset. A provenance lock that is present but
     * breaks a rule is damage too.
     *
     * @throws PrivateStateDamaged
     */
    public function read(): array
    {
        $file = $this->dir.'/'.self::FILE;
        self::inspect($this->lockPath, false);

        if (self::inspect($this->dir, true) === null || ($stat = self::inspect($file, false)) === null) {
            if ($this->markerValue() !== '') {
                throw new PrivateStateDamaged();
            }

            return array_replace(self::emptyDoc(), ['epoch' => self::INITIAL_EPOCH]);
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new PrivateStateDamaged();
        }

        try {
            $raw = self::sameFile($handle, $stat) ? stream_get_contents($handle) : false;
        } finally {
            fclose($handle);
        }

        $doc = is_string($raw) ? self::decode($raw) : null;

        if ($doc === null || ! self::validate($doc)) {
            throw new PrivateStateDamaged();
        }

        return $doc;
    }

    public function isDamaged(): bool
    {
        try {
            $this->read();
            return false;
        } catch (PrivateStateDamaged $e) {
            return true;
        }
    }

    public function hasPrivateSources(): bool
    {
        try {
            return $this->read()['sources'] !== [];
        } catch (PrivateStateDamaged $e) {
            return false;
        }
    }

    /**
     * Read-modify-write under the provenance lock: $fn receives the fresh
     * document and returns the new one, which must validate. Returns the
     * document written.
     *
     * @param callable(array): array $fn
     * @throws PrivateStateDamaged|ModMenuException
     */
    public function mutate(callable $fn): array
    {
        return $this->withLock(function () use ($fn) {
            $doc = $fn($this->read());

            if (! is_array($doc) || ! self::validate($doc)) {
                throw new ModMenuException(t(self::NOT_RECORDED));
            }

            $this->recordMarker();
            $this->writeAtomic(self::encode($doc));

            return $doc;
        });
    }

    /**
     * Moves a present state file aside and writes an empty document with a
     * new epoch. Works on damaged state; the caller holds the exclusive
     * catalog lock.
     *
     * @throws PrivateStateDamaged|ModMenuException
     */
    public function reset(): array
    {
        return $this->withLock(function () {
            $this->ensureDir();
            $this->recordMarker();

            $file = $this->dir.'/'.self::FILE;
            clearstatcache(true, $file);

            if (@lstat($file) !== false) {
                $aside = $file.'.damaged-'.date('YmdHis').'-'.bin2hex(random_bytes(2));

                if (! @rename($file, $aside)) {
                    throw new ModMenuException(t(self::NOT_RECORDED));
                }
            }

            $doc = self::emptyDoc();
            $this->writeAtomic(self::encode($doc));

            return $doc;
        });
    }

    public static function emptyDoc(): array
    {
        return [
            'version' => 1,
            'epoch' => bin2hex(random_bytes(16)),
            'generation' => 0,
            'sources' => [],
            'removed' => [],
            'bindings' => [],
            'seen' => [],
            'dismissed' => [],
        ];
    }

    /**
     * The exact schema: eight keys, version 1, 32-hex epoch, non-negative
     * generation, 64-hex ids whose url parses and hashes to the id, shaped
     * token refs, an id in at most one of sources/removed, bindings into
     * either, seen/dismissed only for live sources, unique lists of plugin keys.
     */
    public static function validate(array $doc): bool
    {
        $keys = array_keys($doc);
        sort($keys);

        if ($keys !== self::KEYS
            || $doc['version'] !== 1
            || ! is_string($doc['epoch'])
            || preg_match('/^[0-9a-f]{32}$/D', $doc['epoch']) !== 1
            || ! is_int($doc['generation'])
            || $doc['generation'] < 0) {
            return false;
        }

        foreach (self::MAPS as $map) {
            if (! is_array($doc[$map])) {
                return false;
            }
        }

        foreach ($doc['sources'] as $id => $source) {
            if (! is_array($source)) {
                return false;
            }

            $fields = array_keys($source);
            sort($fields);

            if ($fields !== ['token_ref', 'url']
                || ! self::urlHasId($source['url'], $id)
                || ! self::isTokenRef($source['token_ref'])) {
                return false;
            }
        }

        foreach ($doc['removed'] as $id => $url) {
            if (! self::urlHasId($url, $id) || array_key_exists($id, $doc['sources'])) {
                return false;
            }
        }

        foreach ($doc['bindings'] as $key => $id) {
            if (! self::isKey($key)
                || ! is_string($id)
                || ! (array_key_exists($id, $doc['sources']) || array_key_exists($id, $doc['removed']))) {
                return false;
            }
        }

        foreach (['seen', 'dismissed'] as $map) {
            foreach ($doc[$map] as $id => $list) {
                if (! is_string($id) || ! array_key_exists($id, $doc['sources']) || ! self::isKeyList($list)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Compact JSON of a valid document; maps always encode as objects.
     */
    public static function encode(array $doc): string
    {
        $doc['sources'] = array_map(fn ($source) => (object) $source, $doc['sources']);

        foreach (self::MAPS as $map) {
            $doc[$map] = (object) $doc[$map];
        }

        try {
            return json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModMenuException(t(self::NOT_RECORDED));
        }
    }

    /**
     * Saves the marker unless already set, then verifies it from the database.
     * Nothing has been written when this throws.
     */
    private function recordMarker(): void
    {
        $this->memoryCache->flush();

        if ($this->markerValue() === '1') {
            return;
        }

        try {
            $saved = $this->configModel->save([self::MARKER => '1']);
        } catch (\Exception $e) {
            $saved = false;
        }

        $this->memoryCache->flush();

        if (! $saved || $this->markerReadBack() !== '1') {
            throw new ModMenuException(t(self::NOT_RECORDED));
        }
    }

    /** The marker as re-read from the database after a save. */
    protected function markerReadBack(): string
    {
        return (string) $this->configModel->get(self::MARKER, '');
    }

    private function markerValue(): string
    {
        return (string) $this->configModel->get(self::MARKER, '');
    }

    /**
     * Writes $json to a fresh 0600 temp file in the state directory, syncs it,
     * renames it over the state file and syncs the directory.
     *
     * @throws PrivateStateDamaged|ModMenuException
     */
    protected function writeAtomic(string $json): void
    {
        $this->ensureDir();
        $temp = $this->dir.'/.private-state.'.bin2hex(random_bytes(6));

        $umask = umask(0077);
        $handle = @fopen($temp, 'x');
        umask($umask);

        if ($handle === false) {
            throw new ModMenuException(t(self::NOT_RECORDED));
        }

        $ok = @chmod($temp, 0600)
            && fwrite($handle, $json) === strlen($json)
            && fflush($handle)
            && fsync($handle);
        $ok = fclose($handle) && $ok;

        if (! $ok || ! @rename($temp, $this->dir.'/'.self::FILE)) {
            @unlink($temp);
            throw new ModMenuException(t(self::NOT_RECORDED));
        }

        $dirHandle = @fopen($this->dir, 'r');

        if ($dirHandle !== false) {
            @fsync($dirHandle);
            fclose($dirHandle);
        }
    }

    /**
     * Runs $fn holding the provenance lock exclusively.
     */
    private function withLock(callable $fn): array
    {
        $handle = $this->openLock();

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new ModMenuException(t(self::NOT_RECORDED));
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
    private function openLock()
    {
        if (self::inspect($this->lockPath, false) === null) {
            $umask = umask(0077);
            $handle = @fopen($this->lockPath, 'x');
            umask($umask);

            if ($handle !== false) {
                chmod($this->lockPath, 0600);
                return $handle;
            }
        }

        // Present (or created by a concurrent request since the check).
        $stat = self::inspect($this->lockPath, false);

        if ($stat === null) {
            throw new ModMenuException(t(self::NOT_RECORDED));
        }

        $handle = @fopen($this->lockPath, 'r');

        if ($handle === false || ! self::sameFile($handle, $stat)) {
            if ($handle !== false) {
                fclose($handle);
            }
            throw new PrivateStateDamaged();
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
            throw new ModMenuException(t(self::NOT_RECORDED));
        }
    }

    /**
     * lstat() of a path that must be a directory or regular file owned by the
     * PHP user with no group/world bits; null when absent.
     *
     * @throws PrivateStateDamaged when present but breaking a rule (a symlink included)
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
            throw new PrivateStateDamaged();
        }

        return $stat;
    }

    /**
     * @param resource $handle
     */
    private static function sameFile($handle, array $stat): bool
    {
        $opened = fstat($handle);

        return $opened !== false && $opened['dev'] === $stat['dev'] && $opened['ino'] === $stat['ino'];
    }

    /**
     * Decodes JSON into the document shape, refusing a JSON list where a map
     * (or a source object) belongs. Null when it is not that shape.
     */
    private static function decode(string $raw): ?array
    {
        try {
            $data = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        if (! $data instanceof \stdClass) {
            return null;
        }

        $doc = (array) $data;

        foreach (self::MAPS as $map) {
            if (! isset($doc[$map]) || ! $doc[$map] instanceof \stdClass) {
                return null;
            }

            $doc[$map] = (array) $doc[$map];
        }

        foreach ($doc['sources'] as $id => $source) {
            if (! $source instanceof \stdClass) {
                return null;
            }

            $doc['sources'][$id] = (array) $source;
        }

        return $doc;
    }

    private static function urlHasId(mixed $url, int|string $id): bool
    {
        if (! is_string($url) || ! is_string($id) || preg_match('/^[0-9a-f]{64}$/D', $id) !== 1) {
            return false;
        }

        $source = GithubUrl::source($url);

        return $source !== null && $source['id'] === $id;
    }

    private static function isTokenRef(mixed $ref): bool
    {
        return is_string($ref)
            && str_starts_with($ref, 'vault://')
            && strlen($ref) <= 512
            && preg_match('/[\x00-\x20\x7F]/', $ref) === 0
            && preg_match('/[\s\p{Cc}\p{Cf}\p{Z}]/u', $ref) === 0;
    }

    /**
     * A PluginName::key() output. PHP turns an all-digit array key into an
     * int, so such keys are compared as strings.
     */
    private static function isKey(mixed $key): bool
    {
        if (is_int($key)) {
            $key = (string) $key;
        }

        return is_string($key) && PluginName::key($key) === $key;
    }

    private static function isKeyList(mixed $list): bool
    {
        if (! is_array($list) || ! array_is_list($list)) {
            return false;
        }

        foreach ($list as $key) {
            if (! is_string($key) || ! self::isKey($key)) {
                return false;
            }
        }

        return count(array_unique($list)) === count($list);
    }
}
