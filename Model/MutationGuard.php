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
 * With no private state at fetch time and no state file at check time it
 * takes no lock and writes nothing (1.6.0 behaviour).
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

        if ($this->holding || $this->isPassThrough($state)) {
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

    /** No private state when fetched and still no state file: no lock is taken. */
    private function isPassThrough(PrivateState $state): bool
    {
        return ! $this->hadFile
            && $this->epoch === self::NO_FILE_EPOCH
            && $this->generation === 0
            && ! $state->hasFile();
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
