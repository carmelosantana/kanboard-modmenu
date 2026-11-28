<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;
use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\CatalogLock;
use Kanboard\Plugin\ModMenu\Model\PrivateState;

class PrivateStateMarkerReadBackFails extends PrivateState
{
    protected function markerReadBack(): string
    {
        return '';
    }
}

class PrivateStateWriteFails extends PrivateState
{
    protected function writeAtomic(string $json): void
    {
        throw new ModMenuException('injected write failure');
    }
}

/** Injected directory-durability failures: open, fsync or close of the state directory. */
class PrivateStateDirSyncFails extends PrivateState
{
    public string $fail = '';
    public array $calls = [];

    protected function openDirectory(string $dir)
    {
        $this->calls[] = 'open';
        return $this->fail === 'open' ? false : parent::openDirectory($dir);
    }

    protected function syncDirectory($handle): bool
    {
        $this->calls[] = 'sync';
        return $this->fail === 'sync' ? false : parent::syncDirectory($handle);
    }

    protected function closeDirectory($handle): bool
    {
        $this->calls[] = 'close';
        $closed = parent::closeDirectory($handle);
        return $this->fail === 'close' ? false : $closed;
    }
}

class PrivateStateTest extends Base
{
    private string $root;
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/modmenu-private-state-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->dir = $this->root.'/modmenu';
    }

    public function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testMissingFileWithoutMarkerIsEmptyState()
    {
        $state = new PrivateState($this->container, $this->dir);
        $doc = $state->read();

        $this->assertTrue(PrivateState::validate($doc));
        $this->assertSame([], $doc['sources']);
        $this->assertSame(0, $doc['generation']);
        $this->assertFalse($state->isDamaged());
        $this->assertFalse($state->hasPrivateSources());
        $this->assertFileDoesNotExist($this->dir.'/private-state.json');
    }

    public function testMissingFileEpochIsStableAndPersistsUntilReset()
    {
        $state = new PrivateState($this->container, $this->dir);
        $zero = str_repeat('0', 32);

        $this->assertSame($zero, $state->read()['epoch']);
        $this->assertSame($zero, $state->read()['epoch']);

        $written = $state->mutate(fn (array $doc) => $this->withSource($doc, 'a'));
        $this->assertSame($zero, $written['epoch']);
        $this->assertSame($zero, $state->read()['epoch']);

        $reset = $state->reset();
        $this->assertNotSame($zero, $reset['epoch']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $reset['epoch']);
    }

    public function testSymlinkedLockIsDamaged()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        rename($this->root.'/modmenu-provenance.lock', $this->root.'/target.lock');
        symlink($this->root.'/target.lock', $this->root.'/modmenu-provenance.lock');

        $this->assertTrue($state->isDamaged());
        $this->expectException(PrivateStateDamaged::class);
        $state->read();
    }

    public function testGroupReadableLockIsDamaged()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        chmod($this->root.'/modmenu-provenance.lock', 0640);

        $this->assertTrue($state->isDamaged());
        $this->assertFalse($state->hasPrivateSources());
    }

    public function testDamagedCatalogLockIsDamagedState()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        touch($this->dir.'/catalog.lock');
        chmod($this->dir.'/catalog.lock', 0640);

        $this->assertTrue($state->isDamaged());

        $this->expectException(PrivateStateDamaged::class);
        (new CatalogLock($this->dir))->shared(fn () => null);
    }

    public function testHealthyCatalogLockLeavesStateUndamaged()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        (new CatalogLock($this->dir))->shared(fn () => null);

        $this->assertFileExists($this->dir.'/catalog.lock');
        $this->assertFalse($state->isDamaged());
    }

    public function testMissingFileWithMarkerIsDamaged()
    {
        $this->container['configModel']->save([PrivateState::MARKER => '1']);
        $this->container['memoryCache']->flush();
        $state = new PrivateState($this->container, $this->dir);

        $this->assertTrue($state->isDamaged());
        $this->assertFalse($state->hasPrivateSources());
        $this->expectException(PrivateStateDamaged::class);
        $this->expectExceptionMessage("ModMenu's private source state is damaged");
        $state->read();
    }

    public function testMarkerSetByAnotherRequestIsSeenPastTheRequestCache()
    {
        $this->container['memoryCache']->flush();
        $state = new PrivateState($this->container, $this->dir);
        $this->assertFalse($state->isDamaged(), 'unset marker, no file: initial state (and cached)');
        $this->assertSame('', (string) $this->container['configModel']->get(PrivateState::MARKER, ''));

        // Another request records the marker, then fails to write the file. save()
        // goes straight to the settings table; this request's cache still says unset.
        $this->container['configModel']->save([PrivateState::MARKER => '1']);
        $this->assertSame('', (string) $this->container['configModel']->get(PrivateState::MARKER, ''), 'cache is stale');

        $this->assertTrue($state->isDamaged());
        $this->expectException(PrivateStateDamaged::class);
        $state->read();
    }

    public function testDeletedFileAfterInitializationIsDamaged()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        unlink($this->dir.'/private-state.json');

        $this->assertTrue($state->isDamaged());
    }

    public function testValidFileWithoutMarkerSetsMarkerOnNextMutate()
    {
        mkdir($this->dir, 0700);
        $doc = PrivateState::emptyDoc();
        $this->writeRaw(PrivateState::encode($doc));
        $state = new PrivateState($this->container, $this->dir);

        $this->assertSame($doc['epoch'], $state->read()['epoch']);
        $this->assertSame('', $this->marker());

        $state->mutate(function (array $doc) {
            $doc['generation']++;
            return $doc;
        });

        $this->assertSame('1', $this->marker());
        $this->assertSame(1, $state->read()['generation']);
    }

    public function testMarkerVerifyFailureWritesNothing()
    {
        $state = new PrivateStateMarkerReadBackFails($this->container, $this->dir);

        try {
            $state->mutate(fn (array $doc) => $this->withSource($doc, 'a'));
            $this->fail('mutate should refuse when the marker cannot be verified');
        } catch (PrivateStateDamaged $e) {
            $this->fail('marker failure is not damage');
        } catch (ModMenuException $e) {
            $this->assertSame('ModMenu could not record its private state', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/private-state.json');
    }

    public function testFileWriteFailureAfterMarkerFreezes()
    {
        $failing = new PrivateStateWriteFails($this->container, $this->dir);

        try {
            $failing->mutate(fn (array $doc) => $this->withSource($doc, 'a'));
            $this->fail('mutate should surface the write failure');
        } catch (ModMenuException $e) {
            $this->assertSame('injected write failure', $e->getMessage());
        }

        $this->assertSame('1', $this->marker());
        $state = new PrivateState($this->container, $this->dir);
        $this->assertTrue($state->isDamaged());
        $this->expectException(PrivateStateDamaged::class);
        $state->mutate(fn (array $doc) => $doc);
    }

    public function testStateWriteSyncsTheDirectory()
    {
        $state = new PrivateStateDirSyncFails($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $this->withSource($doc, 'a'));

        $this->assertSame(['open', 'sync', 'close'], $state->calls);
    }

    /** @dataProvider directoryFailures */
    public function testDirectorySyncFailureIsARecordingFailure(string $step)
    {
        $state = new PrivateStateDirSyncFails($this->container, $this->dir);
        $state->fail = $step;

        try {
            $state->mutate(fn (array $doc) => $this->withSource($doc, 'a'));
            $this->fail('a state write whose directory '.$step.' failed must not report success');
        } catch (PrivateStateDamaged $e) {
            $this->fail('a durability failure is not damage');
        } catch (ModMenuException $e) {
            $this->assertSame('ModMenu could not record its private state', $e->getMessage());
        }

        $this->assertSame([], glob($this->dir.'/.private-state.*'), 'no temp file left behind');
    }

    public static function directoryFailures(): array
    {
        return ['open' => ['open'], 'sync' => ['sync'], 'close' => ['close']];
    }

    public function testEmptyDocRoundTripsThroughDisk()
    {
        $state = new PrivateState($this->container, $this->dir);
        $written = $state->mutate(fn (array $doc) => $doc);
        $raw = file_get_contents($this->dir.'/private-state.json');

        $this->assertStringContainsString('"sources":{}', str_replace([' ', "\n"], '', $raw));
        $this->assertSame($written, $state->read());
        $this->assertTrue(PrivateState::validate(json_decode($raw, true)));
    }

    public function testInvalidResultOfMutationWritesNothing()
    {
        $state = new PrivateState($this->container, $this->dir);

        try {
            $state->mutate(function (array $doc) {
                $doc['extra'] = true;
                return $doc;
            });
            $this->fail('an invalid document must not be written');
        } catch (ModMenuException $e) {
            $this->assertSame('ModMenu could not record its private state', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/private-state.json');
    }

    public function testSchemaRejections()
    {
        $valid = $this->fullDoc();
        $this->assertTrue(PrivateState::validate($valid), 'base document');

        $a = $this->id('a');
        $b = $this->id('b');
        $rows = [
            'extra key' => fn ($d) => $d + ['extra' => 1],
            'missing key' => function ($d) { unset($d['dismissed']); return $d; },
            'wrong version' => fn ($d) => array_replace($d, ['version' => 2]),
            'bad epoch' => fn ($d) => array_replace($d, ['epoch' => str_repeat('A', 32)]),
            'short epoch' => fn ($d) => array_replace($d, ['epoch' => 'abc']),
            'negative generation' => fn ($d) => array_replace($d, ['generation' => -1]),
            'string generation' => fn ($d) => array_replace($d, ['generation' => '1']),
            'id mismatch' => function ($d) use ($a) { $d['sources'][$a]['url'] = $this->url('c'); return $d; },
            'removed id mismatch' => function ($d) use ($b) { $d['removed'][$b] = $this->url('c'); return $d; },
            'bad url' => function ($d) use ($a) { $d['sources'][$a]['url'] = 'https://example.com/a.json'; return $d; },
            'uppercase id' => function ($d) use ($a) {
                $d['sources'][strtoupper($a)] = $d['sources'][$a];
                unset($d['sources'][$a]);
                return $d;
            },
            'extra source field' => function ($d) use ($a) { $d['sources'][$a]['x'] = 1; return $d; },
            'id in both maps' => function ($d) use ($a) { $d['removed'][$a] = $this->url('a'); return $d; },
            'dangling binding' => function ($d) { $d['bindings']['baz'] = $this->id('z'); return $d; },
            'seen for removed id' => function ($d) use ($b) { $d['seen'][$b] = ['bar']; return $d; },
            'dismissed for unknown id' => function ($d) { $d['dismissed'][$this->id('z')] = []; return $d; },
            'duplicate list item' => function ($d) use ($a) { $d['seen'][$a] = ['foo', 'foo']; return $d; },
            'list with keys' => function ($d) use ($a) { $d['seen'][$a] = [1 => 'foo']; return $d; },
            'bad key Foo' => function ($d) use ($a) { $d['bindings']['Foo'] = $a; return $d; },
            'bad list item Foo' => function ($d) use ($a) { $d['seen'][$a] = ['Foo']; return $d; },
            'token_ref http://x' => function ($d) use ($a) { $d['sources'][$a]['token_ref'] = 'http://x'; return $d; },
            '513-byte ref' => function ($d) use ($a) {
                $d['sources'][$a]['token_ref'] = 'vault://'.str_repeat('1', 505);
                return $d;
            },
            'ref with space' => function ($d) use ($a) { $d['sources'][$a]['token_ref'] = 'vault://1/tok en'; return $d; },
            'ref with newline' => function ($d) use ($a) { $d['sources'][$a]['token_ref'] = "vault://1/token\n"; return $d; },
            'sources is a list' => fn ($d) => array_replace($d, ['sources' => ['x']]),
        ];

        foreach ($rows as $label => $break) {
            $this->assertFalse(PrivateState::validate($break($valid)), $label);
        }

        $max = $valid;
        $max['sources'][$a]['token_ref'] = 'vault://'.str_repeat('1', 504);
        $this->assertTrue(PrivateState::validate($max), '512-byte ref');
        $numeric = $valid;
        $numeric['bindings']['123'] = $a;
        $this->assertTrue(PrivateState::validate($numeric), 'all-digit plugin key');
    }

    public function testSchemaInvalidFileIsDamaged()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);

        foreach (['not json', '[]', '{"version":1}', json_encode(['bindings' => ['x']])] as $raw) {
            $this->writeRaw($raw);
            $this->assertTrue($state->isDamaged(), $raw);
        }

        // A JSON array where a map belongs is refused even when it decodes alike.
        $json = PrivateState::encode(PrivateState::emptyDoc());
        $this->assertStringContainsString('"sources":{}', $json);
        $this->writeRaw(str_replace('"sources":{}', '"sources":[]', $json));
        $this->assertTrue($state->isDamaged(), 'list for a map');
    }

    public function testSymlinkedFileRefused()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        rename($this->dir.'/private-state.json', $this->root.'/elsewhere.json');
        symlink($this->root.'/elsewhere.json', $this->dir.'/private-state.json');

        $this->assertTrue($state->isDamaged());
        $this->expectException(PrivateStateDamaged::class);
        $state->mutate(fn (array $doc) => $doc);
    }

    public function testSymlinkedDirectoryRefused()
    {
        mkdir($this->root.'/real', 0700);
        symlink($this->root.'/real', $this->dir);
        $state = new PrivateState($this->container, $this->dir);

        $this->expectException(PrivateStateDamaged::class);
        $state->mutate(fn (array $doc) => $doc);
    }

    public function testSymlinkedLockRefused()
    {
        touch($this->root.'/target.lock');
        chmod($this->root.'/target.lock', 0600);
        symlink($this->root.'/target.lock', $this->root.'/modmenu-provenance.lock');
        $state = new PrivateState($this->container, $this->dir);

        $this->expectException(PrivateStateDamaged::class);
        $state->mutate(fn (array $doc) => $doc);
    }

    public function testGroupReadableFileRefused()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        chmod($this->dir.'/private-state.json', 0640);

        $this->assertTrue($state->isDamaged());
    }

    public function testGroupAccessibleDirectoryRefused()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $doc);
        chmod($this->dir, 0750);

        $this->assertTrue($state->isDamaged());
    }

    public function testTwoMutationsInOneRequestBothPersist()
    {
        $state = new PrivateState($this->container, $this->dir);
        $state->mutate(fn (array $doc) => $this->withSource($doc, 'a'));
        $state->mutate(fn (array $doc) => $this->withSource($doc, 'b'));

        $doc = (new PrivateState($this->container, $this->dir))->read();
        $this->assertArrayHasKey($this->id('a'), $doc['sources']);
        $this->assertArrayHasKey($this->id('b'), $doc['sources']);
        $this->assertTrue($state->hasPrivateSources());
    }

    public function testConcurrentMutationsBothKept()
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
            $this->markTestSkipped('pcntl is not available');
        }

        (new PrivateState($this->container, $this->dir))->mutate(fn (array $doc) => $doc);
        $rounds = 15;
        $pid = pcntl_fork();

        if ($pid === 0) {
            $ok = true;
            try {
                $child = new PrivateState($this->container, $this->dir);
                for ($i = 0; $i < $rounds; $i++) {
                    $child->mutate(fn (array $doc) => $this->withSource($doc, 'child'.$i));
                }
            } catch (\Throwable $e) {
                $ok = false;
            }
            // Replace the child process without running PHPUnit's shutdown.
            pcntl_exec($ok ? '/bin/true' : '/bin/false');
            posix_kill(posix_getpid(), SIGKILL);
        }

        $parent = new PrivateState($this->container, $this->dir);
        for ($i = 0; $i < $rounds; $i++) {
            $parent->mutate(fn (array $doc) => $this->withSource($doc, 'parent'.$i));
        }

        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'child mutations succeeded');

        $doc = $parent->read();
        $this->assertCount(2 * $rounds, $doc['sources']);
        $this->assertSame(2 * $rounds, $doc['generation']);
    }

    public function testResetMovesBadFileAsideAndChangesEpoch()
    {
        $state = new PrivateState($this->container, $this->dir);
        $before = $state->mutate(fn (array $doc) => $this->withSource($doc, 'a'));
        $this->writeRaw('{broken');
        $this->assertTrue($state->isDamaged());

        $after = $state->reset();

        $this->assertNotSame($before['epoch'], $after['epoch']);
        $this->assertSame([], $after['sources']);
        $this->assertFalse($state->isDamaged());
        $this->assertSame($after, $state->read());
        $this->assertSame('1', $this->marker());

        $aside = glob($this->dir.'/private-state.json.damaged-*');
        $this->assertCount(1, $aside);
        $this->assertMatchesRegularExpression('/\.damaged-\d{14}-[0-9a-f]{4}$/', $aside[0]);
        $this->assertSame('{broken', file_get_contents($aside[0]));
    }

    public function testResetWithMissingFileWritesEmptyState()
    {
        $this->container['configModel']->save([PrivateState::MARKER => '1']);
        $this->container['memoryCache']->flush();
        $state = new PrivateState($this->container, $this->dir);
        $this->assertTrue($state->isDamaged());

        $doc = $state->reset();

        $this->assertTrue(PrivateState::validate($doc));
        $this->assertFalse($state->isDamaged());
        $this->assertSame([], glob($this->dir.'/private-state.json.damaged-*'));
    }

    public function testFileModeIs0600AndDir0700()
    {
        $old = umask(0022);
        try {
            (new PrivateState($this->container, $this->dir))->mutate(fn (array $doc) => $doc);
        } finally {
            umask($old);
        }

        clearstatcache();
        $this->assertSame(0700, fileperms($this->dir) & 0777);
        $this->assertSame(0600, fileperms($this->dir.'/private-state.json') & 0777);
        $this->assertSame(0600, fileperms($this->root.'/modmenu-provenance.lock') & 0777);
        $this->assertSame([], glob($this->dir.'/.private-state.*'), 'no temp files left');
    }

    public function testDefaultDirComesFromContainer()
    {
        $this->container['modMenuPrivateStateDir'] = $this->dir;
        (new PrivateState($this->container))->mutate(fn (array $doc) => $doc);

        $this->assertFileExists($this->dir.'/private-state.json');
    }

    private function url(string $name): string
    {
        return 'https://raw.githubusercontent.com/owner/repo/main/'.$name.'.json';
    }

    private function id(string $name): string
    {
        return GithubUrl::source($this->url($name))['id'];
    }

    private function withSource(array $doc, string $name): array
    {
        $doc['sources'][$this->id($name)] = ['url' => $this->url($name), 'token_ref' => 'vault://1/token'];
        $doc['generation']++;
        return $doc;
    }

    private function fullDoc(): array
    {
        $doc = $this->withSource(PrivateState::emptyDoc(), 'a');
        $doc['removed'][$this->id('b')] = $this->url('b');
        $doc['bindings'] = ['foo' => $this->id('a'), 'bar' => $this->id('b')];
        $doc['seen'][$this->id('a')] = ['foo', 'qux'];
        $doc['dismissed'][$this->id('a')] = [];
        return $doc;
    }

    private function writeRaw(string $raw): void
    {
        file_put_contents($this->dir.'/private-state.json', $raw);
        chmod($this->dir.'/private-state.json', 0600);
    }

    private function marker(): string
    {
        $this->container['memoryCache']->flush();
        return (string) $this->container['configModel']->get(PrivateState::MARKER, '');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        chmod($path, 0700);
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }
        rmdir($path);
    }
}
