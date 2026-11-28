<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;

/**
 * The catalog lock, DATA_DIR/modmenu/catalog.lock: source changes hold it
 * exclusive; catalog mutations hold it shared through their final checks.
 * Lock order: plugin locks (sorted), then this lock, then the provenance lock.
 *
 * The directory and file follow SecureFile's rules; one that breaks them is
 * damaged private state (PrivateState::read() checks this file too).
 */
class CatalogLock
{
    public const FILE = 'catalog.lock';

    private const FAILED = 'ModMenu could not lock its source list.';

    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws PrivateStateDamaged|ModMenuException
     */
    public function shared(callable $fn): mixed
    {
        return $this->hold(LOCK_SH, $fn);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws PrivateStateDamaged|ModMenuException
     */
    public function exclusive(callable $fn): mixed
    {
        return $this->hold(LOCK_EX, $fn);
    }

    private function hold(int $mode, callable $fn): mixed
    {
        $damaged = static fn () => new PrivateStateDamaged();
        $failed = static fn () => new ModMenuException(t(self::FAILED));

        SecureFile::ensureDir($this->dir, $damaged, $failed);
        $handle = SecureFile::openLockFile($this->dir.'/'.self::FILE, $damaged, $failed);

        try {
            if (! flock($handle, $mode)) {
                throw $failed();
            }

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
