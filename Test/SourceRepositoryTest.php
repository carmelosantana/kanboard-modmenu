<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\PrivateState;
use Kanboard\Plugin\ModMenu\Model\SourceUrl;

class SourceRepoStateWriteFails extends PrivateState
{
    protected function writeAtomic(string $json): void
    {
        throw new ModMenuException('injected write failure');
    }
}

/** A repository whose state store or public save can be swapped by a test. */
class SourceRepoProbe extends SourceRepository
{
    public ?PrivateState $state = null;
    /** @var callable|null */
    public $beforeSave = null;

    protected function privateState(): PrivateState
    {
        return $this->state ?? parent::privateState();
    }

    protected function saveSources(array $sources): void
    {
        if ($this->beforeSave !== null) {
            ($this->beforeSave)($sources);
        }
        parent::saveSources($sources);
    }
}

class SourceRepositoryTest extends Base
{
    private const PRIVATE_URL = 'https://raw.githubusercontent.com/O/R/%6dain/p.json';
    private const REF = 'vault://1/token';

    private $repo;
    private string $root;
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/modmenu-sources-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->dir = $this->root.'/modmenu';
        $this->container['modMenuPrivateStateDir'] = $this->dir;
        $this->repo = new SourceRepository($this->container);
    }

    public function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testDefaultsToBundledSourceWhenUnset()
    {
        $sources = $this->repo->getSources();
        $this->assertSame([SourceRepository::DEFAULT_SOURCE], $sources);
    }

    public function testAddSourcePersists()
    {
        $this->repo->addSource('https://example.com/plugins.json');
        $this->assertContains('https://example.com/plugins.json', $this->repo->getSources());
    }

    public function testAddSourceIsDeduped()
    {
        $this->repo->addSource('https://example.com/plugins.json');
        $this->repo->addSource('https://example.com/plugins.json');
        $count = count(array_keys($this->repo->getSources(), 'https://example.com/plugins.json'));
        $this->assertSame(1, $count);
    }

    public function testAddSourceRejectsNonHttp()
    {
        $this->expectException(ModMenuException::class);
        $this->repo->addSource('ftp://example.com/x.json');
    }

    public function testAddSourceRejectsPlainHttp()
    {
        $this->expectException(ModMenuException::class);
        $this->expectExceptionMessage('Please enter a valid https:// URL.');
        $this->repo->addSource('http://example.com/plugins.json');
    }

    public function testRemoveSource()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->addSource('https://example.com/b.json');
        $this->repo->removeSource('https://example.com/a.json');
        $sources = $this->repo->getSources();
        $this->assertNotContains('https://example.com/a.json', $sources);
        $this->assertContains('https://example.com/b.json', $sources);
    }

    public function testRemovingAllYieldsEmptyNotDefault()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->removeSource('https://example.com/a.json');
        $this->repo->removeSource(SourceRepository::DEFAULT_SOURCE);
        $this->assertSame([], $this->repo->getSources());
    }

    public function testAddSourceRejectsWhitespace()
    {
        $this->expectException(ModMenuException::class);
        $this->repo->addSource('   ');
    }

    public function testRemoveDefaultAmongOthers()
    {
        $this->repo->addSource('https://example.com/other.json');
        $this->repo->removeSource(SourceRepository::DEFAULT_SOURCE);
        $sources = $this->repo->getSources();
        $this->assertNotContains(SourceRepository::DEFAULT_SOURCE, $sources);
        $this->assertContains('https://example.com/other.json', $sources);
    }

    public function testSourceKeyMatchesAcrossPublicAndPrivateForms()
    {
        $id = GithubUrl::source(self::PRIVATE_URL)['id'];
        $this->assertSame($id, SourceRepository::sourceKey('HTTPS://RAW.githubusercontent.com:443/o/r/main/p.json'));
        $this->assertSame($id, SourceRepository::sourceKey(self::PRIVATE_URL));
        $this->assertSame(SourceUrl::id('https://example.com/p.json'), SourceRepository::sourceKey('https://EXAMPLE.com:443/p.json'));
        $this->assertSame($id, SourceRepository::sourceKey('HTTPS://RAW.GITHUBUSERCONTENT.COM:443/O/R/%6dain/p.json'));
        $this->assertSame($id, SourceRepository::sourceKey('https://RAW.githubusercontent.com/O/R/%6dain/p.json'));
        $this->assertNull(SourceRepository::sourceKey('http://example.com/p.json'));
    }

    public function testAddSourceStoresCanonicalUrl()
    {
        $this->repo->addSource('HTTPS://EXAMPLE.com:443/Plugins.json');
        $this->assertContains('https://example.com/Plugins.json', $this->repo->getSources());
    }

    public function testCanonicalCaseVariantsAreOneDuplicate()
    {
        $this->repo->addSource('HTTPS://RAW.githubusercontent.com:443/o/r/main/p.json');

        try {
            $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, []);
            $this->fail('duplicate private source was accepted');
        } catch (ModMenuException $e) {
            $this->assertSame('Duplicate source; remove and re-add it', $e->getMessage());
        }

        $this->assertSame([], $this->repo->getPrivateSources());
    }

    public function testPublicVariantOfPrivateSourceIsRefused()
    {
        $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, []);
        $before = $this->repo->getSources();

        $this->expectException(ModMenuException::class);
        $this->expectExceptionMessage('Duplicate source; remove and re-add it');
        try {
            $this->repo->addSource('https://raw.githubusercontent.com/o/r/main/p.json');
        } finally {
            $this->assertSame($before, $this->repo->getSources());
        }
    }

    public function testStoredDuplicateFailsClosed()
    {
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([
            'https://example.com/p.json',
            'https://EXAMPLE.com:443/p.json',
            'https://example.com/other.json',
        ])]);
        $this->container['memoryCache']->flush();

        $this->assertSame([SourceUrl::id('https://example.com/p.json')], $this->repo->duplicateKeys());

        $this->expectException(ModMenuException::class);
        $this->expectExceptionMessage('Duplicate source; remove and re-add it');
        $this->repo->addSource('https://example.com:443/p.json');
    }

    public function testStoredPublicPrivateDuplicateIsListed()
    {
        $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, []);
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode(['https://raw.githubusercontent.com/o/r/main/p.json'])]);
        $this->container['memoryCache']->flush();

        $this->assertSame([GithubUrl::source(self::PRIVATE_URL)['id']], $this->repo->duplicateKeys());
    }

    public function testPublicAddAdvancesGenerationFirst()
    {
        $this->state()->mutate(fn (array $doc) => $doc);
        $repo = new SourceRepoProbe($this->container);
        $seen = [];
        $repo->beforeSave = function () use (&$seen) {
            $seen[] = $this->state()->read()['generation'];
        };

        $repo->addSource('https://example.com/p.json');
        $this->assertSame([1], $seen);
        $this->assertSame(1, $this->state()->read()['generation']);

        $repo->removeSource('https://example.com/p.json');
        $this->assertSame([1, 2], $seen);

        $failing = new SourceRepoProbe($this->container);
        $failing->state = new SourceRepoStateWriteFails($this->container, $this->dir);
        $before = $this->container['configModel']->get(SourceRepository::CONFIG_KEY);

        try {
            $failing->addSource('https://example.com/q.json');
            $this->fail('add went ahead after the generation write failed');
        } catch (ModMenuException $e) {
            $this->assertSame('injected write failure', $e->getMessage());
        }

        $this->container['memoryCache']->flush();
        $this->assertSame($before, $this->container['configModel']->get(SourceRepository::CONFIG_KEY));
        $this->assertSame(2, $this->state()->read()['generation']);
    }

    public function testPublicChangeFailingAfterGenerationIsHarmless()
    {
        $this->state()->mutate(fn (array $doc) => $doc);
        $repo = new SourceRepoProbe($this->container);
        $repo->beforeSave = function () {
            throw new ModMenuException('injected save failure');
        };

        try {
            $repo->addSource('https://example.com/p.json');
            $this->fail('save failure was swallowed');
        } catch (ModMenuException $e) {
            $this->assertSame('injected save failure', $e->getMessage());
        }

        $this->assertSame(1, $this->state()->read()['generation']);
        $this->assertFalse($this->state()->isDamaged());
        $this->assertNotContains('https://example.com/p.json', $this->repo->getSources());

        $this->repo->addSource('https://example.com/p.json');
        $this->assertContains('https://example.com/p.json', $this->repo->getSources());
        $this->assertSame(2, $this->state()->read()['generation']);
    }

    public function testAddPrivateWritesSourceAndSeenInOneSave()
    {
        $source = GithubUrl::source(self::PRIVATE_URL);
        $this->repo->addPrivateSource(self::PRIVATE_URL, '  '.self::REF.' ', ['foo', 'bar']);

        $doc = $this->state()->read();
        $this->assertSame(['url' => $source['endpoint'], 'token_ref' => self::REF], $doc['sources'][$source['id']]);
        $this->assertSame(['foo', 'bar'], $doc['seen'][$source['id']]);
        $this->assertSame(1, $doc['generation']);
        $this->assertSame(
            [$source['id'] => ['id' => $source['id'], 'url' => $source['endpoint'], 'token_ref' => self::REF]],
            $this->repo->getPrivateSources()
        );

        $other = 'https://raw.githubusercontent.com/o/r/main/other.json';
        $failing = new SourceRepoProbe($this->container);
        $failing->state = new SourceRepoStateWriteFails($this->container, $this->dir);

        try {
            $failing->addPrivateSource($other, self::REF, ['baz']);
            $this->fail('write failure was swallowed');
        } catch (ModMenuException $e) {
            $this->assertSame('injected write failure', $e->getMessage());
        }

        $this->assertSame($doc, $this->state()->read());
    }

    public function testAddPrivateRefusesBadUrlOrRef()
    {
        $badUrl = 'Please enter a raw.githubusercontent.com URL to a .json file.';
        $badRef = 'Please enter a vault:// token reference.';
        $badKey = 'This private source lists an invalid plugin name.';
        $cases = [
            ['https://example.com/p.json', self::REF, [], $badUrl],
            [self::PRIVATE_URL, 'https://x', [], $badRef],
            [self::PRIVATE_URL, 'vault://1/a b', [], $badRef],
            [self::PRIVATE_URL, self::REF, ['Foo'], $badKey],
            [self::PRIVATE_URL, self::REF, ['foo', 'bad-name'], $badKey],
            [self::PRIVATE_URL, self::REF, [7], $badKey],
        ];

        foreach ($cases as [$url, $ref, $keys, $message]) {
            try {
                $this->repo->addPrivateSource($url, $ref, $keys);
                $this->fail('accepted '.$url.' '.$ref);
            } catch (ModMenuException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertFileDoesNotExist($this->dir.'/private-state.json');
    }

    public function testRemovingAbsentSourceChangesNothing()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->state()->mutate(fn (array $doc) => $doc);
        $repo = new SourceRepoProbe($this->container);
        $saves = 0;
        $repo->beforeSave = function () use (&$saves) {
            $saves++;
        };

        $repo->removeSource('https://example.com/absent.json');

        $this->assertSame(0, $saves);
        $this->assertSame(0, $this->state()->read()['generation']);
        $this->assertContains('https://example.com/a.json', $this->repo->getSources());
    }

    public function testRemovePrivateKeepsBindingsAndStaysValid()
    {
        $source = GithubUrl::source(self::PRIVATE_URL);
        $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, ['foo']);
        $this->state()->mutate(function (array $doc) use ($source) {
            $doc['bindings']['foo'] = $source['id'];
            $doc['dismissed'][$source['id']] = ['bar'];
            return $doc;
        });

        $this->repo->removePrivateSource($source['id']);

        $doc = $this->state()->read();
        $this->assertTrue(PrivateState::validate($doc));
        $this->assertSame([], $doc['sources']);
        $this->assertSame([$source['id'] => $source['endpoint']], $doc['removed']);
        $this->assertSame(['foo' => $source['id']], $doc['bindings']);
        $this->assertSame([], $doc['seen']);
        $this->assertSame([], $doc['dismissed']);
        $this->assertSame(2, $doc['generation']);
        $this->assertSame([], $this->repo->getPrivateSources());
    }

    public function testReaddMovesBackFromRemoved()
    {
        $source = GithubUrl::source(self::PRIVATE_URL);
        $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, ['foo']);
        $this->state()->mutate(function (array $doc) use ($source) {
            $doc['bindings']['foo'] = $source['id'];
            return $doc;
        });
        $this->repo->removePrivateSource($source['id']);

        $this->repo->addPrivateSource('https://raw.githubusercontent.com/o/r/main/p.json', 'vault://2/other', ['foo', 'qux']);

        $doc = $this->state()->read();
        $this->assertSame([], $doc['removed']);
        $this->assertSame(['url' => $source['endpoint'], 'token_ref' => 'vault://2/other'], $doc['sources'][$source['id']]);
        $this->assertSame(['foo', 'qux'], $doc['seen'][$source['id']]);
        $this->assertSame(['foo' => $source['id']], $doc['bindings']);
        $this->assertSame(3, $doc['generation']);
    }

    public function testAddingExistingPrivateSourceIsDuplicate()
    {
        $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, []);

        $this->expectException(ModMenuException::class);
        $this->expectExceptionMessage('Duplicate source; remove and re-add it');
        $this->repo->addPrivateSource(self::PRIVATE_URL, 'vault://2/other', []);
    }

    public function testNoPrivateStateMeansLegacyBehaviour()
    {
        $this->repo->addSource('https://example.com/a.json');
        $this->repo->removeSource('https://example.com/a.json');
        $this->repo->removeSource(SourceRepository::DEFAULT_SOURCE);

        $this->assertSame([], $this->repo->getSources());
        $this->assertFileDoesNotExist($this->dir.'/private-state.json');
        $this->assertSame('', (string) $this->container['configModel']->get(PrivateState::MARKER, ''));
        $this->assertSame([], $this->repo->getPrivateSources());
        $this->assertSame([], $this->repo->duplicateKeys());
    }

    public function testDamagedStateRefusesSourceChanges()
    {
        $this->state()->mutate(fn (array $doc) => $doc);
        file_put_contents($this->dir.'/private-state.json', '{broken');

        $this->expectException(\Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged::class);
        $this->repo->addSource('https://example.com/p.json');
    }

    public function testConcurrentEquivalentAddsLeaveOneSource()
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
            $this->markTestSkipped('pcntl is not available');
        }

        $this->state()->mutate(fn (array $doc) => $doc);
        $go = $this->root.'/go';
        $pid = pcntl_fork();

        if ($pid === 0) {
            $code = 2;
            try {
                for ($i = 0; $i < 500 && ! file_exists($go); $i++) {
                    usleep(1000);
                }
                (new SourceRepository($this->container))->addPrivateSource('https://raw.githubusercontent.com/o/r/main/p.json', self::REF, []);
                $code = 0;
            } catch (ModMenuException $e) {
                $code = $e->getMessage() === 'Duplicate source; remove and re-add it' ? 1 : 2;
            } catch (\Throwable $e) {
                $code = 2;
            }
            // Replace the child process without running PHPUnit's shutdown.
            pcntl_exec($code === 0 ? '/bin/true' : ($code === 1 ? '/bin/false' : '/bin/sh'), $code === 2 ? ['-c', 'exit 2'] : []);
            posix_kill(posix_getpid(), SIGKILL);
        }

        touch($go);
        try {
            $this->repo->addPrivateSource(self::PRIVATE_URL, self::REF, []);
            $parentAdded = true;
        } catch (ModMenuException $e) {
            $this->assertSame('Duplicate source; remove and re-add it', $e->getMessage());
            $parentAdded = false;
        } finally {
            pcntl_waitpid($pid, $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $childCode = pcntl_wexitstatus($status);
        $this->assertContains($childCode, [0, 1], 'child added or was refused as a duplicate');
        $this->assertSame(1, ($parentAdded ? 1 : 0) + ($childCode === 0 ? 1 : 0), 'exactly one add succeeded');
        $doc = $this->state()->read();
        $this->assertCount(1, $doc['sources']);
        $this->assertSame(1, $doc['generation']);
    }

    private function state(): PrivateState
    {
        return new PrivateState($this->container, $this->dir);
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
