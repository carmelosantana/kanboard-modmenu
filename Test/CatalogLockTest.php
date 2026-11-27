<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;
use Kanboard\Plugin\ModMenu\Model\CatalogLock;

class CatalogLockTest extends Base
{
    private string $root;
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/modmenu-catalog-'.uniqid();
        mkdir($this->root, 0700);
        $this->dir = $this->root.'/modmenu';
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->tree($this->root)) as $path) {
            is_dir($path) && ! is_link($path) ? rmdir($path) : unlink($path);
        }
        parent::tearDown();
    }

    private function tree(string $dir): array
    {
        $paths = [$dir];
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            $paths = is_dir($path) && ! is_link($path) ? array_merge($paths, $this->tree($path)) : array_merge($paths, [$path]);
        }
        return $paths;
    }

    private function assertFree(): void
    {
        $handle = fopen($this->dir.'/catalog.lock', 'r');
        try {
            $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'the catalog lock was released');
        } finally {
            fclose($handle);
        }
    }

    public function testSharedAndExclusiveReturnTheValueAndRelease(): void
    {
        $lock = new CatalogLock($this->dir);

        $this->assertSame(42, $lock->shared(fn () => 42));
        $this->assertFree();
        $this->assertSame('x', $lock->exclusive(fn () => 'x'));
        $this->assertFree();

        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertSame(0600, fileperms($this->dir.'/catalog.lock') & 0777);
    }

    public function testReleasesWhenTheCallableThrows(): void
    {
        $lock = new CatalogLock($this->dir);

        try {
            $lock->exclusive(function () {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFree();
    }

    public function testExclusiveHolderBlocksOthers(): void
    {
        (new CatalogLock($this->dir))->exclusive(function () {
            $other = fopen($this->dir.'/catalog.lock', 'r');
            try {
                $this->assertFalse(flock($other, LOCK_SH | LOCK_NB));
            } finally {
                fclose($other);
            }
        });
    }

    /** @dataProvider refusedLayouts */
    public function testRefusesAnUnsafeLockFileOrDirectory(string $layout): void
    {
        switch ($layout) {
            case 'symlinked file':
                mkdir($this->dir, 0700);
                touch($this->root.'/elsewhere');
                chmod($this->root.'/elsewhere', 0600);
                symlink($this->root.'/elsewhere', $this->dir.'/catalog.lock');
                break;
            case 'readable file':
                mkdir($this->dir, 0700);
                touch($this->dir.'/catalog.lock');
                chmod($this->dir.'/catalog.lock', 0640);
                break;
            case 'directory as file':
                mkdir($this->dir, 0700);
                mkdir($this->dir.'/catalog.lock', 0700);
                break;
            case 'symlinked directory':
                mkdir($this->root.'/real', 0700);
                symlink($this->root.'/real', $this->dir);
                break;
            case 'readable directory':
                mkdir($this->dir, 0755);
                chmod($this->dir, 0755);
                break;
            case 'file as directory':
                touch($this->dir);
                chmod($this->dir, 0600);
                break;
        }

        foreach (['shared', 'exclusive'] as $mode) {
            try {
                (new CatalogLock($this->dir))->$mode(fn () => $this->fail('Ran under an unsafe lock.'));
                $this->fail('Expected the '.$layout.' to be refused.');
            } catch (PrivateStateDamaged $e) {
                $this->assertSame("ModMenu's private source state is damaged", $e->getMessage());
            }
        }
    }

    public static function refusedLayouts(): array
    {
        $layouts = ['symlinked file', 'readable file', 'directory as file', 'symlinked directory', 'readable directory', 'file as directory'];
        return array_combine($layouts, array_map(fn ($layout) => [$layout], $layouts));
    }

    public function testSharedLocksCoexistExclusiveWaits(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
            $this->markTestSkipped('pcntl is not available');
        }

        $parentHolds = $this->root.'/parent-holds';
        $childShared = $this->root.'/child-shared';
        $childExclusive = $this->root.'/child-exclusive';
        (new CatalogLock($this->dir))->shared(fn () => null);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $ok = false;
            try {
                for ($i = 0; $i < 500 && ! file_exists($parentHolds); $i++) {
                    usleep(10000);
                }
                $lock = new CatalogLock($this->dir);
                $lock->shared(fn () => touch($childShared));
                $lock->exclusive(fn () => file_put_contents($childExclusive, (string) hrtime(true)));
                $ok = true;
            } catch (\Throwable $e) {
                $ok = false;
            }
            // Replace the child process without running PHPUnit's shutdown.
            pcntl_exec($ok ? '/bin/true' : '/bin/false');
            posix_kill(posix_getpid(), SIGKILL);
        }

        try {
            $released = (new CatalogLock($this->dir))->shared(function () use ($parentHolds, $childShared, $childExclusive) {
                touch($parentHolds);
                for ($i = 0; $i < 500 && ! file_exists($childShared); $i++) {
                    usleep(10000);
                }
                $this->assertFileExists($childShared, 'a second shared holder got in');
                usleep(300000);
                $this->assertFileDoesNotExist($childExclusive, 'exclusive waited for the shared holder');
                return hrtime(true);
            });
        } finally {
            // Always reap the child, even when an assertion above failed.
            touch($parentHolds);
            pcntl_waitpid($pid, $status);
        }

        $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'child locked successfully');
        $this->assertGreaterThanOrEqual($released, (int) file_get_contents($childExclusive), 'exclusive ran after the shared holder released (monotonic ns)');
    }
}
