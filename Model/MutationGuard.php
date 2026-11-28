<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;
use Pimple\Container;

/**
 * The freshness rules for one catalog mutation, built from the
 * DirectoryClient::fetchAll() (or catalogResult()) of this request.
 *
 * assertCanStart() refuses while the private state is damaged, a private
 * source was unreachable or a seen-name write failed. underCatalog() holds
 * the catalog lock shared, re-reads the private state and refuses when its
 * (epoch, generation) differs from the fetch's snapshot.
 *
 * The shared lock is held even with no private state, so a first private
 * source cannot be added between the final check and activation; it creates
 * DATA_DIR/modmenu/catalog.lock (R10) but never the state file or marker.
 */
class MutationGuard extends Base
{
    public const UNREACHABLE = 'Private sources must be reachable to change plugins';
    public const CHANGED = 'Sources changed; try again';

    private const NO_FILE_EPOCH = '00000000000000000000000000000000';

    private bool $frozen;
    private bool $privateOk;
    private array $quarantined;
    private ?string $epoch;
    private ?int $generation;
    private array $listed;
    private bool $hadFile;
    private bool $holding = false;

    public function __construct(Container $container, array $fetchResult)
    {
        parent::__construct($container);
        $this->frozen = (bool) ($fetchResult['frozen'] ?? true);
        $this->privateOk = (bool) ($fetchResult['private_ok'] ?? false);
        $this->quarantined = (array) ($fetchResult['quarantined'] ?? []);
        $epoch = $fetchResult['snapshot']['epoch'] ?? null;
        $generation = $fetchResult['snapshot']['generation'] ?? null;
        $this->epoch = is_string($epoch) ? $epoch : null;
        $this->generation = is_int($generation) ? $generation : null;
        $this->listed = is_array($fetchResult['listed'] ?? null) ? $fetchResult['listed'] : [];
        $this->hadFile = $this->state()->hasFile();
    }

    /**
     * The guard for a call that brought no fetch result: it lets a mutation
     * run only while there is no private state at all.
     */
    public static function withoutPrivateState(Container $container): self
    {
        return new self($container, [
            'frozen' => false,
            'private_ok' => true,
            'quarantined' => [],
            'snapshot' => ['epoch' => self::NO_FILE_EPOCH, 'generation' => 0],
            'listed' => [],
        ]);
    }

    /**
     * The guard for an install with no catalog entry (an upload, a legacy
     * install by URL): with no state file nothing is fetched (1.6.0
     * behaviour, and a file that appears later is refused as a change);
     * otherwise $fetch() supplies this request's fetch result, so bound
     * plugins, reachability and freezing are enforced as for catalog installs.
     *
     * @param callable(): array $fetch
     */
    public static function forUnlistedInstall(Container $container, callable $fetch): self
    {
        if (! (new PrivateState($container))->hasFile()) {
            return self::withoutPrivateState($container);
        }

        return new self($container, $fetch());
    }

    /** @throws ModMenuException */
    public function assertCanStart(): void
    {
        if ($this->frozen) {
            throw new PrivateStateDamaged();
        }
        if (! $this->privateOk || $this->quarantined !== []) {
            throw new ModMenuException(e(self::UNREACHABLE));
        }
    }

    /**
     * Plugin keys each private source listed in this request's fetch.
     *
     * @return array<string, string[]>
     */
    public function listed(): array
    {
        return $this->listed;
    }

    /**
     * Runs $fn($doc) holding the catalog lock shared, with $doc the private
     * state read fresh under it. Re-entrant: a nested call reuses the held
     * lock but still re-reads and re-checks the state.
     *
     * @template T
     * @param callable(array): T $fn
     * @return T
     * @throws ModMenuException
     */
    public function underCatalog(callable $fn): mixed
    {
        $this->assertCanStart();
        $state = $this->state();

        if ($this->holding) {
            return $fn($this->checkedDoc($state));
        }

        return (new CatalogLock(PrivateState::defaultDir($this->container)))->shared(function () use ($state, $fn) {
            $this->holding = true;
            try {
                return $fn($this->checkedDoc($state));
            } finally {
                $this->holding = false;
            }
        });
    }

    private function checkedDoc(PrivateState $state): array
    {
        $doc = $state->read();
        if (! $this->hadFile && $state->hasFile()) {
            throw new ModMenuException(e(self::CHANGED));
        }
        if ($doc['epoch'] !== $this->epoch || $doc['generation'] !== $this->generation) {
            throw new ModMenuException(e(self::CHANGED));
        }

        return $doc;
    }

    protected function state(): PrivateState
    {
        return new PrivateState($this->container);
    }
}
