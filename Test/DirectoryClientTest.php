<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\PrivateState;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;

class DirectoryClientStateWriteFails extends PrivateState
{
    protected function writeAtomic(string $json): void
    {
        throw new ModMenuException('injected write failure');
    }
}

/** A client whose private-state store a test can swap. */
class DirectoryClientProbe extends DirectoryClient
{
    public ?PrivateState $state = null;

    protected function privateState(): PrivateState
    {
        return $this->state ?? parent::privateState();
    }
}

/** A stand-in for PrivateFetcher: listings or exceptions per source id. */
class DirectoryClientFakeFetcher
{
    public array $responses = [];
    public array $calls = [];
    /** @var callable|null */
    public $during = null;

    public function fetchDirectory(array $source): array
    {
        $this->calls[] = $source;
        if ($this->during !== null) {
            ($this->during)($source);
        }
        $response = $this->responses[$source['id']] ?? [];
        if ($response instanceof \Throwable) {
            throw $response;
        }
        return $response;
    }
}

class DirectoryClientTest extends Base
{
    private const PUBLIC_URL = 'https://catalog.example/plugins.json';
    private const PRIVATE_URL = 'https://raw.githubusercontent.com/acme/dir/main/plugins.json';
    private const OTHER_PRIVATE_URL = 'https://raw.githubusercontent.com/acme/dir/main/more.json';
    private const REF = 'vault://1/token';
    /** The fetchAll() keys 1.7 adds, as they read with no private source. */
    private const NO_PRIVATE = [
        'private_ok' => true,
        'frozen' => false,
        'snapshot' => ['epoch' => '00000000000000000000000000000000', 'generation' => 0],
        'quarantined' => [],
        'listed' => [],
    ];

    private $client;
    private string $root;
    private string $dir;
    private DirectoryClientFakeFetcher $fetcher;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/modmenu-directory-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->dir = $this->root.'/modmenu';
        $this->container['modMenuPrivateStateDir'] = $this->dir;
        $this->fetcher = new DirectoryClientFakeFetcher();
        $this->container['modMenuPrivateFetcher'] = $this->fetcher;
        $this->client = new DirectoryClient($this->container);
    }

    public function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    // ── Private sources ────────────────────────────────────────────────────

    public function testForgedPrivateAndSourceIdStripped(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([
            ['name' => 'Alpha', 'private' => true, 'source_id' => $id],
        ]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret', ['private' => false, 'source_id' => 'forged'])];

        $result = $this->client->fetchAll();
        $plugins = DirectoryClient::indexByName($result['plugins']);

        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey('private', $plugins['Alpha']);
        $this->assertArrayNotHasKey('source_id', $plugins['Alpha']);
        $this->assertTrue($plugins['Secret']['private']);
        $this->assertSame($id, $plugins['Secret']['source_id']);
        $this->assertSame([['id' => $id, 'url' => GithubUrl::source(self::PRIVATE_URL)['endpoint'], 'token_ref' => self::REF]], $this->fetcher->calls);
        $this->assertTrue($result['private_ok']);
    }

    public function testCollisionAcrossSourcesRemovedAndReported(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Foo'], ['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('foo'), $this->privateEntry('Secret')];

        $result = $this->client->fetchAll();

        $this->assertSame(['Alpha', 'Secret'], array_column($result['plugins'], 'name'));
        $this->assertSame([['url' => '', 'message' => 'Foo is listed by more than one source']], $result['errors']);
    }

    public function testDuplicateInOnePrivateSourceRemoved(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Dup'), $this->privateEntry('Secret'), $this->privateEntry('DUP')];

        $result = $this->client->fetchAll();

        $this->assertSame(['Alpha', 'Secret'], array_column($result['plugins'], 'name'));
        $this->assertSame([['url' => '', 'message' => 'Dup is listed by more than one source']], $result['errors']);
        $this->assertSame([$id => ['dup', 'secret']], $result['listed']);
    }

    public function testBoundPluginIgnoresPublicSameName(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $gone = GithubUrl::source(self::OTHER_PRIVATE_URL)['id'];
        $this->state()->mutate(function (array $doc) use ($id, $gone) {
            $doc['removed'][$gone] = GithubUrl::source(self::OTHER_PRIVATE_URL)['endpoint'];
            $doc['bindings'] = ['secret' => $id, 'orphan' => $gone];
            return $doc;
        });
        $this->publicListing([['name' => 'Secret', 'version' => '9.9.9'], ['name' => 'Orphan'], ['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret')];

        $result = $this->client->fetchAll();
        $plugins = DirectoryClient::indexByName($result['plugins']);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['Secret', 'Alpha'], array_keys($plugins));
        $this->assertSame('1.0.0', $plugins['Secret']['version']);
        $this->assertSame($id, $plugins['Secret']['source_id']);
    }

    public function testSeenMergedAddOnly(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL, ['old']);
        $this->publicListing([]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret'), $this->privateEntry('Other')];

        $first = $this->client->fetchAll();
        $doc = $this->state()->read();

        $this->assertSame(['old', 'secret', 'other'], $doc['seen'][$id]);
        $this->assertSame(['epoch' => $doc['epoch'], 'generation' => $doc['generation']], $first['snapshot']);
        $this->assertSame([$id => ['secret', 'other']], $first['listed']);
        $this->assertTrue($first['private_ok']);

        $this->fetcher->responses[$id] = [$this->privateEntry('Newer')];
        $second = $this->client->fetchAll();

        $this->assertSame(['old', 'secret', 'other', 'newer'], $this->state()->read()['seen'][$id]);
        $this->assertSame(['Newer'], array_column($second['plugins'], 'name'));
        $this->assertSame([], $second['quarantined']);
    }

    public function testSeenForRemovedIdDiscarded(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $kept = $this->addPrivate(self::OTHER_PRIVATE_URL);
        $before = $this->state()->read();
        $this->publicListing([['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret')];
        $this->fetcher->responses[$kept] = [$this->privateEntry('Kept')];
        $this->fetcher->during = function (array $source) use ($id) {
            if ($source['id'] === $id) {
                (new SourceRepository($this->container))->removePrivateSource($id);
            }
        };

        $result = $this->client->fetchAll();
        $doc = $this->state()->read();

        $this->assertSame([$kept => ['kept']], $doc['seen']);
        $this->assertSame([$kept => ['kept']], $result['listed']);
        $this->assertSame(['Alpha', 'Kept'], array_column($result['plugins'], 'name'));
        $this->assertFalse($result['private_ok']);
        $this->assertSame(['epoch' => $before['epoch'], 'generation' => $before['generation']], $result['snapshot']);
        $this->assertNotSame($before['generation'], $doc['generation']);
    }

    public function testFailedSeenWriteQuarantinesNamesAndSetsPrivateNotOk(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $before = $this->state()->read();
        $this->publicListing([['name' => 'Alpha'], ['name' => 'SECRET']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret'), ['name' => 'Bad', 'sha256' => 'nope']];
        $client = new DirectoryClientProbe($this->container);
        $client->state = new DirectoryClientStateWriteFails($this->container, $this->dir);

        $result = $client->fetchAll();

        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertSame(['secret', 'bad'], $result['quarantined']);
        $this->assertFalse($result['private_ok']);
        $this->assertFalse($result['frozen']);
        $this->assertSame([], $result['listed']);
        $this->assertSame(['epoch' => null, 'generation' => null], $result['snapshot']);
        $this->assertSame([[
            'url' => GithubUrl::source(self::PRIVATE_URL)['endpoint'],
            'message' => "ModMenu could not record this private source's plugin names",
        ]], $result['errors']);
        $this->assertStringNotContainsString('injected', json_encode($result));
        $this->assertSame($before, $this->state()->read());
    }

    public function testPrivateSourceDownMeansPrivateNotOkButPublicListed(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $other = $this->addPrivate(self::OTHER_PRIVATE_URL);
        $this->publicListing([['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = new ModMenuException('GitHub refused the token for this source (check its access to acme/dir)');
        $this->fetcher->responses[$other] = new \RuntimeException('Authorization: token ghp_SECRETSECRETSECRETSECRET');

        $result = $this->client->fetchAll();

        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertFalse($result['private_ok']);
        $this->assertSame([
            ['url' => GithubUrl::source(self::PRIVATE_URL)['endpoint'], 'message' => 'GitHub refused the token for this source (check its access to acme/dir)'],
            ['url' => GithubUrl::source(self::OTHER_PRIVATE_URL)['endpoint'], 'message' => "Couldn't load this private source."],
        ], $result['errors']);
        $this->assertStringNotContainsString('ghp_', json_encode($result));
        $this->assertSame([], $result['listed']);
    }

    public function testFrozenStateRendersPublicOnly(): void
    {
        $this->addPrivate(self::PRIVATE_URL);
        file_put_contents($this->dir.'/private-state.json', '{"broken":"DAMAGED-CONTENT"');
        $this->publicListing([['name' => 'Alpha']]);

        $result = $this->client->fetchAll();

        $this->assertSame([], $this->fetcher->calls);
        $this->assertTrue($result['frozen']);
        $this->assertFalse($result['private_ok']);
        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertSame([], $result['errors']);
        $this->assertSame(['epoch' => null, 'generation' => null], $result['snapshot']);
        $this->assertStringNotContainsString('DAMAGED-CONTENT', json_encode($result));
        $this->assertSame('{"broken":"DAMAGED-CONTENT"', file_get_contents($this->dir.'/private-state.json'));
    }

    public function testPrivateEntryWithoutSha256Dropped(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Alpha']]);
        $missing = $this->privateEntry('Missing');
        unset($missing['sha256']);
        $this->fetcher->responses[$id] = [
            $missing,
            $this->privateEntry('Short', ['sha256' => str_repeat('a', 63)]),
            $this->privateEntry('Typed', ['sha256' => ['x']]),
            $this->privateEntry('Upper', ['sha256' => str_repeat('A', 64)]),
        ];

        $result = $this->client->fetchAll();
        $url = GithubUrl::source(self::PRIVATE_URL)['endpoint'];
        $message = static fn ($n) => ['url' => $url, 'message' => "Skipped $n: a private plugin needs a valid sha256 checksum"];

        $this->assertSame(['Alpha', 'Upper'], array_column($result['plugins'], 'name'));
        $this->assertSame([$message('Missing'), $message('Short'), $message('Typed')], $result['errors']);
        $this->assertSame([$id => ['missing', 'short', 'typed', 'upper']], $result['listed']);
    }

    public function testPrivateDownloadOwnerMismatchDropped(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([]);
        $this->fetcher->responses[$id] = [
            $this->privateEntry('Theirs', ['download' => 'https://github.com/mallory/x/releases/download/v1/X.zip']),
            $this->privateEntry('Archive', ['download' => 'https://github.com/acme/x/archive/refs/tags/v1.zip']),
            $this->privateEntry('Absent', ['download' => null]),
            $this->privateEntry('Cased', ['download' => 'https://github.com/ACME/x/releases/download/v1/X.zip']),
        ];

        $result = $this->client->fetchAll();
        $url = GithubUrl::source(self::PRIVATE_URL)['endpoint'];
        $message = static fn ($n) => ['url' => $url, 'message' => "Skipped $n: its download is not a release in this source's GitHub account"];

        $this->assertSame(['Cased'], array_column($result['plugins'], 'name'));
        $this->assertSame([$message('Theirs'), $message('Archive'), $message('Absent')], $result['errors']);
    }

    public function testInvalidNameDropped(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Bad-Name'], ['name' => ''], ['version' => '1.0.0'], 'scalar', ['name' => "<b>O'Hara"], ['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry(str_repeat('x', 65)), $this->privateEntry('Secret')];

        $result = $this->client->fetchAll();
        $message = static fn ($url, $n) => ['url' => $url, 'message' => "Skipped an entry with an invalid plugin name: $n"];

        $this->assertSame(['Alpha', 'Secret'], array_column($result['plugins'], 'name'));
        $this->assertSame([
            $message(self::PUBLIC_URL, 'Bad-Name'),
            $message(self::PUBLIC_URL, ''),
            $message(self::PUBLIC_URL, ''),
            $message(self::PUBLIC_URL, ''),
            $message(self::PUBLIC_URL, "<b>O'Hara"),
            $message(GithubUrl::source(self::PRIVATE_URL)['endpoint'], str_repeat('x', 65)),
        ], $result['errors']);
        $this->assertSame([$id => ['secret']], $result['listed']);
    }

    public function testNoPrivateSourcesIsUnchanged(): void
    {
        $this->publicListing([['name' => 'Foo', 'version' => '1.0.0'], ['name' => 'Foo', 'version' => '2.0.0'], ['name' => 'Bar']]);

        $result = $this->client->fetchAll();

        $this->assertSame(['plugins', 'errors', 'private_ok', 'frozen', 'snapshot', 'quarantined', 'listed'], array_keys($result));
        $this->assertSame(['Foo', 'Bar'], array_column($result['plugins'], 'name'));
        $this->assertSame('1.0.0', $result['plugins'][0]['version']);
        $this->assertSame([self::PUBLIC_URL, self::PUBLIC_URL], array_column($result['plugins'], 'source_url'));
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['private_ok']);
        $this->assertFalse($result['frozen']);
        $this->assertSame(['epoch' => str_repeat('0', 32), 'generation' => 0], $result['snapshot']);
        $this->assertSame([], $result['quarantined']);
        $this->assertSame([], $result['listed']);
        $this->assertSame([], $this->fetcher->calls);
        $this->assertFileDoesNotExist($this->dir.'/private-state.json');
    }

    public function testDuplicateSourceFailsClosedWithoutFetching(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $public = 'https://raw.githubusercontent.com/ACME/dir/main/plugins.json';
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([$public])]);
        $this->container['memoryCache']->flush();
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            function () {
                $this->fail('a duplicate source was fetched');
            }
        );

        $result = $this->client->fetchAll();

        $this->assertSame([], $this->fetcher->calls);
        $this->assertSame([
            ['url' => $public, 'message' => 'Duplicate source; remove and re-add it'],
            ['url' => GithubUrl::source(self::PRIVATE_URL)['endpoint'], 'message' => 'Duplicate source; remove and re-add it'],
        ], $result['errors']);
        $this->assertFalse($result['private_ok']);
        $this->assertSame([], $result['plugins']);
        $this->assertArrayHasKey($id, $this->state()->read()['sources']);
    }

    public function testPublicDuplicatesCollideWhileAPrivateSourceIsConfigured(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $other = 'https://other.example/plugins.json';
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([self::PUBLIC_URL, $other])]);
        $this->container['memoryCache']->flush();
        $listings = [
            self::PUBLIC_URL => [['name' => 'Shared'], ['name' => 'Alpha'], ['name' => 'Twice'], ['name' => 'twice']],
            $other => [['name' => 'SHARED']],
        ];
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            static function ($url, $address, $header, $write) use ($listings) {
                $write(null, json_encode($listings[$url]));
                return [200, null];
            }
        );
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret')];

        $result = $this->client->fetchAll();

        $this->assertSame(['Alpha', 'Secret'], array_column($result['plugins'], 'name'));
        $this->assertSame([
            ['url' => '', 'message' => 'Shared is listed by more than one source'],
            ['url' => '', 'message' => 'Twice is listed by more than one source'],
        ], $result['errors']);
    }

    public function testRejectedPrivateEntryStillCollides(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Foo'], ['name' => 'Bar'], ['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [
            $this->privateEntry('Foo', ['sha256' => 'bad']),
            $this->privateEntry('Bar', ['download' => 'https://github.com/mallory/bar/releases/download/v1/Bar.zip']),
            $this->privateEntry('Lonely', ['sha256' => 'bad']),
        ];

        $result = $this->client->fetchAll();
        $url = GithubUrl::source(self::PRIVATE_URL)['endpoint'];

        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertSame([
            ['url' => $url, 'message' => 'Skipped Foo: a private plugin needs a valid sha256 checksum'],
            ['url' => $url, 'message' => "Skipped Bar: its download is not a release in this source's GitHub account"],
            ['url' => $url, 'message' => 'Skipped Lonely: a private plugin needs a valid sha256 checksum'],
            ['url' => '', 'message' => 'Foo is listed by more than one source'],
            ['url' => '', 'message' => 'Bar is listed by more than one source'],
        ], $result['errors']);
    }

    public function testSourceChangeDuringFetchMakesTheResultStale(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $before = $this->state()->read();
        $this->publicListing([['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret')];
        $this->fetcher->during = function () {
            (new SourceRepository($this->container))->removeSource(self::PUBLIC_URL);
        };

        $result = $this->client->fetchAll();

        $this->assertFalse($result['private_ok']);
        $this->assertSame(['epoch' => $before['epoch'], 'generation' => $before['generation']], $result['snapshot']);
        $this->assertNotSame($before['generation'], $this->state()->read()['generation']);
    }

    public function testFrozenStateStillFailsDuplicatePublicSourcesClosed(): void
    {
        $this->addPrivate(self::PRIVATE_URL);
        file_put_contents($this->dir.'/private-state.json', '{broken');
        $dup = 'https://catalog.example/plugins.json';
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([$dup, 'https://CATALOG.example:443/plugins.json'])]);
        $this->container['memoryCache']->flush();
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            function () {
                $this->fail('a duplicate source was fetched');
            }
        );

        $result = $this->client->fetchAll();

        $this->assertTrue($result['frozen']);
        $this->assertSame(
            ['Duplicate source; remove and re-add it', 'Duplicate source; remove and re-add it'],
            array_column($result['errors'], 'message')
        );
    }

    public function testListedKeysAreUniqueValidKeysInListingOrder(): void
    {
        $this->assertSame(
            ['secret', 'alpha'],
            DirectoryClient::listedKeys([['name' => 'Secret'], 'x', ['name' => 'Bad-Name'], ['name' => 'ALPHA'], ['name' => 'secret'], ['name' => 5]])
        );
    }

    public function testCatalogResultIndexesPluginsByName(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Alpha']]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret')];

        $result = $this->client->catalogResult();

        $this->assertSame(['Alpha', 'Secret'], array_keys($result['plugins']));
        $this->assertTrue($result['private_ok']);
    }

    public function testPrivateEntryRequirementsResolveOnlyFromItsOwnSource(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([['name' => 'Helper', 'version' => '1.0.0', 'download' => 'https://x.com/Helper.zip']]);
        $this->fetcher->responses[$id] = [
            $this->privateEntry('Secret', ['requires' => [['plugin' => 'Helper'], ['plugin' => 'Sibling']]]),
            $this->privateEntry('Sibling'),
        ];

        $plugins = $this->client->catalogResult()['plugins'];

        $state = array_column($plugins['Secret']['requires_state'], 'action', 'plugin');
        $this->assertSame(['Helper' => 'unresolvable', 'Sibling' => 'install'], $state);
    }

    public function testPublicEntryRequirementsStillResolveFromEverySource(): void
    {
        $id = $this->addPrivate(self::PRIVATE_URL);
        $this->publicListing([
            ['name' => 'Alpha', 'version' => '1.0.0', 'download' => 'https://x.com/Alpha.zip', 'requires' => [['plugin' => 'Helper']]],
            ['name' => 'Helper', 'version' => '1.0.0', 'download' => 'https://x.com/Helper.zip'],
        ]);
        $this->fetcher->responses[$id] = [$this->privateEntry('Secret')];

        $plugins = $this->client->catalogResult()['plugins'];

        $this->assertSame('install', $plugins['Alpha']['requires_state'][0]['action']);
    }

    public function testBrowseShowsPrivateTagWithoutSourceDetails(): void
    {
        $id = str_repeat('e', 64);
        $html = $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [['url' => '', 'message' => 'Foo is listed by more than one source']],
            'is_configured' => true, 'updates' => [], 'category_counts' => ['Other' => 2],
            'plugins' => [
                ['name' => 'Secret', 'version' => '1.0.0', 'status' => 'available', 'category' => 'Other', 'added' => '', 'last_updated' => '',
                    'private' => true, 'source_id' => $id, 'source_url' => self::PRIVATE_URL],
                ['name' => 'Alpha', 'version' => '1.0.0', 'status' => 'available', 'category' => 'Other', 'added' => '', 'last_updated' => ''],
            ],
        ]);

        $this->assertSame(1, substr_count($html, 'modmenu-badge--private'));
        $this->assertMatchesRegularExpression('#data-name="Secret".*?<span class="modmenu-badge modmenu-badge--private">Private</span>#s', $html);
        $this->assertStringNotContainsString($id, $html);
        $this->assertStringNotContainsString('raw.githubusercontent.com', $html);
        $this->assertStringContainsString('Foo is listed by more than one source', $html);
        $this->assertStringNotContainsString('Could not load source:  —', $html);
    }

    private function addPrivate(string $url, array $seen = []): string
    {
        (new SourceRepository($this->container))->addPrivateSource($url, self::REF, $seen);
        return GithubUrl::source($url)['id'];
    }

    private function privateEntry(string $name, array $extra = []): array
    {
        return array_replace([
            'name' => $name,
            'version' => '1.0.0',
            'sha256' => str_repeat('a', 64),
            'download' => 'https://github.com/acme/'.strtolower($name).'/releases/download/v1.0.0/'.$name.'-1.0.0.zip',
        ], $extra);
    }

    /** One public source, PUBLIC_URL, serving $plugins. */
    private function publicListing(array $plugins): void
    {
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([self::PUBLIC_URL])]);
        $this->container['memoryCache']->flush();
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            static function ($url, $address, $header, $write) use ($plugins) {
                $write(null, json_encode($plugins));
                return [200, null];
            }
        );
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

    public function testFetchAllReportsEachStoredHttpSourceWithoutFetchingIt()
    {
        $insecure = ['http://old.example/plugins.json', 'http://legacy.example/plugins.json'];
        $secure = 'https://current.example/plugins.json';
        $sha256 = str_repeat('a', 64);
        $this->container['configModel']->save([
            SourceRepository::CONFIG_KEY => json_encode([$insecure[0], $secure, $insecure[1]]),
        ]);
        $this->container['memoryCache']->flush();
        $fetched = [];
        $this->container['httpClient']->expects($this->never())->method('getJson');
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            static function ($url, $address, $header, $write) use (&$fetched, $sha256) {
                $fetched[] = $url;
                $write(null, json_encode([['name' => 'Alpha', 'sha256' => $sha256]]));
                return [200, null];
            }
        );

        $result = $this->client->fetchAll();

        $this->assertSame([$secure], $fetched);
        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertSame([$sha256], array_column($result['plugins'], 'sha256'));
        $this->assertSame(array_map(static fn ($url) => [
            'url' => $url,
            'message' => 'Skipped: directory sources must use https://. Remove this source and add it again over https.',
        ], $insecure), $result['errors']);
        $this->assertSame([$insecure[0], $secure, $insecure[1]], (new SourceRepository($this->container))->getSources());
    }

    public function testFetchAllChecksRedirectHopsAndContinuesAfterSourceFailures(): void
    {
        $http = 'https://catalog.example/http';
        $private = 'https://catalog.example/private';
        $loop = 'https://catalog.example/loop';
        $invalid = 'https://catalog.example/invalid';
        $valid = 'https://catalog.example/valid';
        $cdn = 'https://assets.example/plugins.json';
        $this->container['configModel']->save([
            SourceRepository::CONFIG_KEY => json_encode([$http, $private, $loop, $invalid, $valid]),
        ]);
        $this->container['memoryCache']->flush();
        $this->container['httpClient']->expects($this->never())->method('getJson');
        $addresses = [
            'catalog.example' => '140.82.112.3',
            'assets.example' => '2606:50c0:8000::153',
            'private.example' => '127.0.0.1',
        ];
        $requests = [];
        $before = glob(sys_get_temp_dir() . '/modmenu_dl*');
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn ($host) => [$addresses[$host]],
            static function ($url, $address, $header, $write) use (&$requests, $http, $private, $loop, $invalid, $valid, $cdn) {
                $requests[] = [$url, $address];
                switch ($url) {
                    case $http:
                        return [302, 'http://assets.example/plugins.json'];
                    case $private:
                        return [302, 'https://private.example/plugins.json'];
                    case $loop:
                        return [302, $loop];
                    case $valid:
                        return [302, $cdn];
                    case $invalid:
                        $write(null, '{');
                        return [200, null];
                    case $cdn:
                        $write(null, '[{"name":"Alpha"}]');
                        return [200, null];
                }
                return [500, null];
            }
        );

        $result = $this->client->fetchAll();

        $this->assertSame([
            [$http, $addresses['catalog.example']],
            [$private, $addresses['catalog.example']],
            ...array_fill(0, 6, [$loop, $addresses['catalog.example']]),
            [$invalid, $addresses['catalog.example']],
            [$valid, $addresses['catalog.example']],
            [$cdn, $addresses['assets.example']],
        ], $requests);
        $this->assertSame([
            ['url' => $http, 'message' => 'The download URL must be a valid https:// URL.'],
            ['url' => $private, 'message' => 'The download was refused: private.example is not a public address.'],
            ['url' => $loop, 'message' => 'Too many redirects while downloading the plugin archive.'],
            ['url' => $invalid, 'message' => 'This directory listing is not valid JSON.'],
        ], $result['errors']);
        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertSame([$valid], array_column($result['plugins'], 'source_url'));
        $this->assertSame($before, glob(sys_get_temp_dir() . '/modmenu_dl*'));
    }

    public function testFetchAllReportsDirectoryErrorsForFailedOrEmptyResponses(): void
    {
        $responses = [
            'https://catalog.example/missing' => [404, 'Not found'],
            'https://catalog.example/unavailable' => [500, 'Server error'],
            'https://catalog.example/transfer' => [0, '[{"name":'],
            'https://catalog.example/empty' => [200, ''],
        ];
        $urls = array_keys($responses);
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode($urls)]);
        $this->container['memoryCache']->flush();
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            static function ($url, $address, $header, $write) use ($responses) {
                [$status, $body] = $responses[$url];
                if ($body !== '') {
                    $write(null, $body);
                }
                return [$status, null];
            }
        );

        $this->assertSame([
            'plugins' => [],
            'errors' => array_map(static fn ($url) => [
                'url' => $url,
                'message' => "Couldn't load this directory listing.",
            ], $urls),
        ] + self::NO_PRIVATE, $this->client->fetchAll());
    }

    public function testFetchAllRejectsAnOversizedListingWhileStreaming(): void
    {
        $url = 'https://catalog.example/plugins.json';
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([$url])]);
        $this->container['memoryCache']->flush();
        $this->container['httpClient']->expects($this->never())->method('getJson');
        $before = glob(sys_get_temp_dir() . '/modmenu_dl*');
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            function ($url, $address, $header, $write) use ($before) {
                $paths = array_values(array_diff(glob(sys_get_temp_dir() . '/modmenu_dl*'), $before));
                $this->assertCount(1, $paths);
                $chunk = str_repeat(' ', 1024 * 1024);
                for ($i = 0; $i < 5; $i++) {
                    $this->assertSame(strlen($chunk), $write(null, $chunk));
                }
                $this->assertSame(0, $write(null, 'x'));
                $this->assertSame(5 * 1024 * 1024, filesize($paths[0]));
                return [0, null];
            }
        );

        $this->assertSame([
            'plugins' => [],
            'errors' => [['url' => $url, 'message' => 'The directory listing is larger than 5 MB.']],
        ] + self::NO_PRIVATE, $this->client->fetchAll());
        $this->assertSame($before, glob(sys_get_temp_dir() . '/modmenu_dl*'));
    }

    public function testResolveAssetUrlKeepsAbsolute()
    {
        $this->assertSame(
            'https://cdn.example.com/a.png',
            DirectoryClient::resolveAssetUrl('https://cdn.example.com/a.png', 'https://x.com/dir/plugins.json')
        );
    }

    public function testResolveAssetUrlJoinsRelative()
    {
        $this->assertSame(
            'https://x.com/dir/assets/a.png',
            DirectoryClient::resolveAssetUrl('assets/a.png', 'https://x.com/dir/plugins.json')
        );
    }

    public function testAnnotateMarksAvailable()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);
        $this->assertSame('available', $out[0]['status']);
    }

    public function testAnnotateMarksInstalled()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('installed', $out[0]['status']);
    }

    public function testAnnotateMarksUpdate()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.1.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('update', $out[0]['status']);
    }

    public function testAnnotateMarksDisabled()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0']];
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'disabled']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('disabled', $out[0]['status']);
    }

    public function testAnnotateDisabledWinsOverUpdate()
    {
        $plugins = [['name' => 'Foo', 'version' => '2.0.0']]; // newer in the listing
        $map = ['Foo' => ['version' => '1.0.0', 'status' => 'disabled']];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map);
        $this->assertSame('disabled', $out[0]['status']); // disabled wins, not 'update'
    }

    public function testAnnotateResolvesScreenshots()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0', 'screenshots' => ['assets/s1.png']]];
        $out = $this->client->annotate($plugins, 'https://x.com/dir/plugins.json', []);
        $this->assertSame(['https://x.com/dir/assets/s1.png'], $out[0]['screenshots']);
    }

    public function testMergeDedupesByNameFirstSourceWins()
    {
        $sourcesData = [
            ['url' => 'https://a.com/plugins.json', 'plugins' => [['name' => 'Foo', 'version' => '1.0.0']]],
            ['url' => 'https://b.com/plugins.json', 'plugins' => [['name' => 'Foo', 'version' => '9.9.9']]],
        ];
        $merged = $this->client->merge($sourcesData, []);
        $this->assertCount(1, $merged);
        $this->assertSame('1.0.0', $merged[0]['version']);
    }

    public function testAnnotatePreservesDependencyFields()
    {
        $plugins = [[
            'name' => 'Dep', 'version' => '1.0.0',
            'requires'   => [['plugin' => 'Cal', 'min_version' => '1.1.0']],
            'recommends' => [['plugin' => 'Cal']],
        ]];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);
        $this->assertSame('Cal', $out[0]['requires'][0]['plugin']);
        $this->assertSame('1.1.0', $out[0]['requires'][0]['min_version']);
        $this->assertSame('Cal', $out[0]['recommends'][0]['plugin']);
    }

    public function testMergePreservesDependencyFields()
    {
        $sourcesData = [[
            'url' => 'https://a.com/plugins.json',
            'plugins' => [[
                'name' => 'Dep', 'version' => '1.0.0',
                'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']],
            ]],
        ]];
        $merged = $this->client->merge($sourcesData, []);
        $this->assertSame('Cal', $merged[0]['requires'][0]['plugin']);
    }

    public function testAnnotateCarriesConflicts()
    {
        $plugins = [
            ['name' => 'BattleLobby', 'version' => '1.0.0', 'conflicts' => ['ShadcnTheme', ['bad'], '']],
            ['name' => 'Plain', 'version' => '1.0.0'],
        ];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);
        $this->assertSame(['ShadcnTheme'], $out[0]['conflicts']);
        $this->assertSame([], $out[1]['conflicts']);
    }

    public function testMergeCarriesConflicts()
    {
        $sourcesData = [[
            'url' => 'https://a.com/plugins.json',
            'plugins' => [['name' => 'BattleLobby', 'version' => '1.0.0', 'conflicts' => ['ShadcnTheme']]],
        ]];
        $merged = $this->client->merge($sourcesData, []);
        $this->assertSame(['ShadcnTheme'], $merged[0]['conflicts']);
    }

    public function testAnnotateNormalizesReplacementAndDeprecationMetadata()
    {
        $plugins = [
            ['name' => 'Harbor', 'replaces' => [5, 'ShadcnTheme', ''], 'deprecated' => ['superseded_by' => 5]],
            ['name' => 'ShadcnTheme', 'replaces' => 'Old', 'deprecated' => ['superseded_by' => 'Harbor', 'since' => '1.3.0']],
            ['name' => 'Plain'],
        ];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', []);

        $this->assertSame([['ShadcnTheme'], [], []], array_column($out, 'replaces'));
        $this->assertSame(
            [null, ['superseded_by' => 'Harbor', 'since' => '1.3.0', 'reason' => ''], null],
            array_column($out, 'deprecated')
        );
    }

    public function testAnnotateClassifiesEachRequirementAgainstTheInstalledMap()
    {
        $plugins = [[
            'name' => 'Addon', 'version' => '1.0.0',
            'requires' => [
                ['plugin' => 'Met', 'min_version' => '1.0.0'],
                ['plugin' => 'Old', 'min_version' => '2.0.0'],
                ['plugin' => 'Gone'],
                ['plugin' => 'Off', 'min_version' => '1.0.0'],
                ['plugin' => 'Any'],
            ],
        ]];
        $map = [
            'Met' => ['version' => '1.2.0', 'status' => 'active'],
            'Old' => ['version' => '1.5.0', 'status' => 'active'],
            'Off' => ['version' => '1.0.0', 'status' => 'disabled'],
            'Any' => ['version' => '0.1.0', 'status' => 'active'],
        ];
        $state = $this->client->annotate($plugins, 'https://x.com/plugins.json', $map)[0]['requires_state'];

        $this->assertSame(['Met', 'Old', 'Gone', 'Off', 'Any'], array_column($state, 'plugin'));
        $this->assertSame(['satisfied', 'outdated', 'missing', 'disabled', 'satisfied'], array_column($state, 'status'));
        $this->assertSame('1.5.0', $state[1]['installed_version']);
        $this->assertSame('2.0.0', $state[1]['min_version']);
        $this->assertNull($state[4]['min_version']);
    }

    public function testAnnotateGivesAnEmptyRequiresStateWithoutRequires()
    {
        $out = $this->client->annotate([['name' => 'Foo', 'version' => '1.0.0', 'requires' => 'oops']], 'https://x.com/plugins.json', []);
        $this->assertSame([], $out[0]['requires_state']);
    }

    // ── Browse metadata: category and dates ────────────────────────────────

    public function testAnnotateTrimsCategoryAndFilesMissingOnesUnderOther()
    {
        $out = $this->client->annotate([
            ['name' => 'A', 'version' => '1.0.0', 'category' => '  Fun  '],
            ['name' => 'B', 'version' => '1.0.0'],
            ['name' => 'C', 'version' => '1.0.0', 'category' => '   '],
            ['name' => 'D', 'version' => '1.0.0', 'category' => ['AI']],
        ], 'https://x.com/plugins.json', []);
        $this->assertSame(['Fun', 'Other', 'Other', 'Other'], array_column($out, 'category'));
    }

    public function testAnnotateKeepsOnlyIsoDates()
    {
        $out = $this->client->annotate([
            ['name' => 'A', 'version' => '1.0.0', 'added' => '2026-07-03', 'last_updated' => '2026-11-17'],
            ['name' => 'B', 'version' => '1.0.0', 'added' => '07/03/2026', 'last_updated' => '2026-11-17T10:00:00Z'],
            ['name' => 'C', 'version' => '1.0.0'],
            ['name' => 'D', 'version' => '1.0.0', 'added' => 20260703, 'last_updated' => ['2026-11-17']],
        ], 'https://x.com/plugins.json', []);
        $this->assertSame(['2026-07-03', '', '', ''], array_column($out, 'added'));
        $this->assertSame(['2026-11-17', '', '', ''], array_column($out, 'last_updated'));
    }

    private function entry(string $name, string $added = '', string $updated = '', string $author = '', ?string $title = null): array
    {
        $e = ['name' => $name, 'added' => $added, 'last_updated' => $updated, 'author' => $author];
        if ($title !== null) {
            $e['title'] = $title;
        }
        return $e;
    }

    public function testSortByLastUpdatedIsNewestFirstWithTiesByTitleAndUndatedLast()
    {
        $plugins = [
            $this->entry('Undated', '2026-01-01', ''),
            $this->entry('Old', '2026-01-01', '2026-03-01'),
            $this->entry('Zeta', '2026-01-01', '2026-11-17'),
            $this->entry('alpha', '2026-01-01', '2026-11-17'),
            $this->entry('AlsoUndated', '2026-01-01', '', '', 'Aardvark'),
        ];
        $this->assertSame(
            ['alpha', 'Zeta', 'Old', 'AlsoUndated', 'Undated'],
            array_column(DirectoryClient::sortPlugins($plugins, 'updated'), 'name')
        );
    }

    public function testSortByAddedIsOldestFirstWithUndatedLast()
    {
        $plugins = [
            $this->entry('New', '2026-10-08'),
            $this->entry('Undated', ''),
            $this->entry('Beta', '2026-07-03'),
            $this->entry('Alpha', '2026-07-03'),
        ];
        $this->assertSame(
            ['Alpha', 'Beta', 'New', 'Undated'],
            array_column(DirectoryClient::sortPlugins($plugins, 'added'), 'name')
        );
    }

    public function testSortByAuthorIsCaseInsensitiveWithTiesByTitle()
    {
        $plugins = [
            $this->entry('Z1', '', '', 'zed'),
            $this->entry('B', '', '', 'Carmelo Santana', 'Beta'),
            $this->entry('A', '', '', 'carmelo santana', 'Alpha'),
            $this->entry('M', '', '', 'Mallory'),
        ];
        $this->assertSame(
            ['A', 'B', 'M', 'Z1'],
            array_column(DirectoryClient::sortPlugins($plugins, 'author'), 'name')
        );
    }

    public function testSortTitleFallsBackToNameAndIgnoresCase()
    {
        $plugins = [
            $this->entry('Zed', '', '2026-01-01', '', 'apple'),
            $this->entry('banana', '', '2026-01-01'),
            $this->entry('Cherry', '', '2026-01-01'),
        ];
        $this->assertSame(
            ['Zed', 'banana', 'Cherry'],
            array_column(DirectoryClient::sortPlugins($plugins, 'updated'), 'name')
        );
    }

    public function testSortUnknownOrderFallsBackToLastUpdated()
    {
        $plugins = [$this->entry('Old', '', '2026-01-01'), $this->entry('New', '', '2026-11-01')];
        $this->assertSame(
            array_column(DirectoryClient::sortPlugins($plugins, 'updated'), 'name'),
            array_column(DirectoryClient::sortPlugins($plugins, 'bogus'), 'name')
        );
        $this->assertSame(['New', 'Old'], array_column(DirectoryClient::sortPlugins($plugins, ''), 'name'));
    }

    public function testSortReturnsAList()
    {
        $out = DirectoryClient::sortPlugins(['x' => $this->entry('B', '', '2026-01-01'), 'y' => $this->entry('A', '', '2026-02-01')], 'updated');
        $this->assertSame([0, 1], array_keys($out));
    }

    public function testCategoryCountsAreAlphabeticalWithOtherLast()
    {
        $plugins = [
            ['name' => 'a', 'category' => 'Other'],
            ['name' => 'b', 'category' => 'Fun'],
            ['name' => 'c', 'category' => 'AI'],
            ['name' => 'd', 'category' => 'Fun'],
            ['name' => 'e', 'category' => 'administration'],
            ['name' => 'f'],
        ];
        $this->assertSame(
            ['administration' => 1, 'AI' => 1, 'Fun' => 2, 'Other' => 2],
            DirectoryClient::categoryCounts($plugins)
        );
    }

    public function testCategoryCountsExcludeDeprecatedEntries()
    {
        $deprecation = ['superseded_by' => 'Harbor', 'since' => '1.3.0', 'reason' => ''];
        $plugins = [
            ['name' => 'Harbor', 'category' => 'Theme', 'deprecated' => null],
            ['name' => 'ShadcnTheme', 'category' => 'Theme', 'deprecated' => $deprecation],
            ['name' => 'LegacyTheme', 'category' => 'Legacy', 'deprecated' => $deprecation],
        ];

        $this->assertSame(['Theme' => 1], DirectoryClient::categoryCounts($plugins));
    }

    public function testAnnotateCleansThirdPartyRequiresBeforeClassifying()
    {
        $plugins = [['name' => 'Foo', 'version' => '1.0.0', 'requires' => [
            ['plugin' => 'Dep', 'min_version' => ['1.0.0'], 'reason' => ['why']],
            ['plugin' => ['Arr']],
        ]]];
        $out = $this->client->annotate($plugins, 'https://x.com/plugins.json', ['Dep' => ['version' => '0.1.0', 'status' => 'active']]);
        $this->assertSame([['plugin' => 'Dep', 'min_version' => null, 'reason' => '']], $out[0]['requires']);
        $this->assertCount(1, $out[0]['requires_state']);
        $this->assertSame('satisfied', $out[0]['requires_state'][0]['status']);
    }

    public function testCategoryCountsKeepAZeroCategory()
    {
        $counts = DirectoryClient::categoryCounts([['category' => '0'], ['category' => ''], ['category' => 'Fun']]);
        $this->assertSame(['0', 'Fun', 'Other'], array_map('strval', array_keys($counts)));
        $this->assertSame([1, 1, 1], array_values($counts));
    }

    public function testSortByAuthorPutsEntriesWithoutAnAuthorLast()
    {
        $plugins = [
            $this->entry('NoAuthor', '', '', ''),
            $this->entry('Zed', '', '', 'zed'),
            $this->entry('AlsoNone', '', '', '', 'Aardvark'),
            $this->entry('Alice', '', '', 'Alice'),
        ];
        $this->assertSame(
            ['Alice', 'Zed', 'AlsoNone', 'NoAuthor'],
            array_column(DirectoryClient::sortPlugins($plugins, 'author'), 'name')
        );
    }

    public function testEqualTitlesTieBreakOnNameWhateverTheInputOrder()
    {
        $a = $this->entry('Beta', '2026-01-01', '2026-01-01', 'X', 'Same');
        $b = $this->entry('Alpha', '2026-01-01', '2026-01-01', 'X', 'Same');
        foreach (['updated', 'added', 'author'] as $order) {
            $this->assertSame(['Alpha', 'Beta'], array_column(DirectoryClient::sortPlugins([$a, $b], $order), 'name'), $order);
            $this->assertSame(['Alpha', 'Beta'], array_column(DirectoryClient::sortPlugins([$b, $a], $order), 'name'), $order);
        }
    }
}
