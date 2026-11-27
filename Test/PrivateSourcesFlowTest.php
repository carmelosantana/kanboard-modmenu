<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Plugin\ModMenu\Controller\ModMenuController;
use Kanboard\Plugin\ModMenu\Controller\UploadController;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Model\PrivateState;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;

/** The real controller, with a real PluginManager working in the test's folders. */
class PrivateSourcesFlowController extends ModMenuController
{
    public PluginManager $realManager;

    protected function manager(): PluginManager
    {
        return $this->realManager;
    }
}

/** The real controller, damaging the state between the sources page's check and its read. */
class PrivateSourcesFlowRacingController extends PrivateSourcesFlowController
{
    public ?Closure $afterCheck = null;

    protected function privateState(): \Kanboard\Plugin\ModMenu\Model\PrivateState
    {
        $afterCheck = $this->afterCheck;
        return new class ($this->container, $afterCheck) extends \Kanboard\Plugin\ModMenu\Model\PrivateState {
            public function __construct($container, private ?Closure $afterCheck)
            {
                parent::__construct($container);
            }

            public function isDamaged(): bool
            {
                $damaged = parent::isDamaged();
                if ($this->afterCheck !== null) {
                    ($this->afterCheck)();
                    $this->afterCheck = null;
                }
                return $damaged;
            }
        };
    }
}

/** The real upload controller, with a real PluginManager working in the test's folders. */
class PrivateSourcesFlowUploadController extends UploadController
{
    public PluginManager $realManager;

    protected function manager(): PluginManager
    {
        return $this->realManager;
    }
}

/** Keeps every record Kanboard's logger hands it. */
class PrivateSourcesFlowLogRecorder extends \Kanboard\Core\Log\Base
{
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, var_export($context, true)];
    }
}

/** Stands in for Vault's ServiceSecretException: a refusal that carries only a reason. */
class PrivateSourcesFlowVaultRefusal extends \RuntimeException
{
    public function __construct(private string $refusal, string $message = '')
    {
        parent::__construct($message === '' ? 'Vault refused: '.$refusal : $message);
    }

    public function reason(): string
    {
        return $this->refusal;
    }
}

/**
 * Private sources through the controller actions: add, remove, adopt,
 * unbind, dismiss and reset, the sources page and Browse banners, with the
 * real PrivateFetcher behind a fake Vault and a fake GitHub transport.
 */
class PrivateSourcesFlowTest extends Base
{
    private const TOKEN = 'CANARY_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    private const REF = 'vault://7/token';
    private const PUBLIC_URL = 'https://catalog.example/plugins.json';
    private const PRIVATE_URL = 'https://raw.githubusercontent.com/acme/dir/main/plugins.json';

    private string $root;
    private string $active;
    private string $disabled;
    private string $dir;
    private object $vault;
    private PrivateSourcesFlowLogRecorder $log;
    private PluginManager $manager;

    /** @var array<string, callable> URL → handler($write, $headers) returning [status, location] */
    private array $routes = [];
    private array $requests = [];
    private array $publicPlugins = [];
    private array $privatePlugins = [];

    private array $flashes = [];
    private array $redirects = [];
    private array $bodies = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->assertSame(37, strlen(self::TOKEN));
        session_set('user', $this->container['userModel']->getById(1));
        $session = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin'])->getMock();
        $session->method('isAdmin')->willReturn(true);
        $this->container['userSession'] = $session;

        $token = $this->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['validateCSRFToken'])->getMock();
        $token->method('validateCSRFToken')->willReturnCallback(fn ($t) => $t === 'good');
        $this->container['token'] = $token;

        $flash = $this->getMockBuilder(\Kanboard\Core\Session\FlashMessage::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['success', 'failure'])->getMock();
        $flash->method('success')->willReturnCallback(function ($m) { $this->flashes[] = ['success', $m]; });
        $flash->method('failure')->willReturnCallback(function ($m) { $this->flashes[] = ['failure', $m]; });
        $this->container['flash'] = $flash;

        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['redirect', 'html'])->getMock();
        $response->method('redirect')->willReturnCallback(function ($url) { $this->redirects[] = $url; });
        $response->method('html')->willReturnCallback(function ($body) { $this->bodies[] = $body; });
        $this->container['response'] = $response;
        $this->root = sys_get_temp_dir().'/modmenu-private-flow-'.bin2hex(random_bytes(6));
        $this->active = $this->root.'/plugins';
        $this->disabled = $this->root.'/disabled';
        $this->dir = $this->root.'/modmenu';
        mkdir($this->active, 0700, true);
        mkdir($this->disabled, 0700, true);
        $this->container['modMenuPrivateStateDir'] = $this->dir;

        $this->vault = $this->fakeVault();
        $this->container['vaultSecrets'] = $this->vault;
        $this->container['modMenuDirectoryDownloader'] = $this->downloader();
        $this->container['modMenuArchiveDownloader'] = $this->downloader();
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([self::PUBLIC_URL])]);
        $this->container['memoryCache']->flush();
        $this->serveListings();

        $this->manager = (new PluginManager($this->container))->setDirectories($this->active, $this->disabled);
        $this->manager->setArchiveDownloader($this->downloader());

        $this->log = new PrivateSourcesFlowLogRecorder();
        $this->container['logger']->setLogger($this->log);

    }

    public function tearDown(): void
    {
        $_FILES = [];
        $this->removeTree($this->root);
        parent::tearDown();
    }

    // ── add and remove ─────────────────────────────────────────────────────

    public function testAddPrivateSourceRecordsSourceAndSeenNames(): void
    {
        $this->privatePlugin('Secret', '1.0.0');

        $this->call('addPrivateSource', ['url' => self::PRIVATE_URL, 'token_ref' => self::REF]);

        $this->assertSame([['success', 'Private source added.']], $this->flashes);
        $doc = $this->state()->read();
        $this->assertSame([$this->id() => ['url' => self::PRIVATE_URL, 'token_ref' => self::REF]], $doc['sources']);
        $this->assertSame([$this->id() => ['secret']], $doc['seen']);
        $this->assertStringContainsString('action=sources', end($this->redirects));
    }

    public function testAddPrivateSourceRefusedWhenFirstFetchFails(): void
    {
        $this->routes[self::PRIVATE_URL] = static fn () => [401, null];

        $this->call('addPrivateSource', ['url' => self::PRIVATE_URL, 'token_ref' => self::REF]);

        $this->assertSame([['failure', 'GitHub refused the token for this source (check its access to acme/dir)']], $this->flashes);
        $this->assertFalse($this->state()->hasFile());
    }

    public function testAddPrivateSourceRefusesBadInput(): void
    {
        $this->call('addPrivateSource', ['url' => 'https://example.com/plugins.json', 'token_ref' => self::REF]);
        $this->call('addPrivateSource', ['url' => self::PRIVATE_URL, 'token_ref' => 'ghp_notareference']);

        $this->assertSame([
            ['failure', 'Please enter a raw.githubusercontent.com URL to a .json file.'],
            ['failure', 'Please enter a vault:// token reference.'],
        ], $this->flashes);
        $this->assertSame(0, $this->vault->reads);
        $this->assertFalse($this->state()->hasFile());
    }

    public function testAddPrivateSourceRefusedWhileFrozenWithoutAskingVault(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->damage();

        $this->call('addPrivateSource', ['url' => self::PRIVATE_URL, 'token_ref' => self::REF]);

        $this->assertSame([['failure', e("ModMenu's private source state is damaged")]], $this->flashes);
        $this->assertSame(0, $this->vault->reads);
        $this->assertTrue($this->state()->isDamaged());
    }

    public function testSourcesPageShowsFrozenBannerWhenStateIsDamagedAfterTheCheck(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $controller = new PrivateSourcesFlowRacingController($this->container);
        $controller->realManager = $this->manager;
        $controller->afterCheck = fn () => file_put_contents($this->dir.'/private-state.json', 'not json');

        $controller->sources();

        $this->assertCount(1, $this->bodies);
        $this->assertStringContainsString(e("ModMenu's private source state is damaged"), $this->bodies[0]);
        $this->assertStringNotContainsString(self::REF, $this->bodies[0]);
    }

    public function testRemovePrivateSourceWorksWhileDown(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->seed('Secret', '1.0.0');
        $this->bind('Secret');
        $this->routes[self::PRIVATE_URL] = static fn () => [500, null];
        $reads = $this->vault->reads;

        $this->call('removePrivateSource', ['id' => $this->id()]);

        $this->assertSame([['success', 'Private source removed.']], array_slice($this->flashes, -1));
        $this->assertSame($reads, $this->vault->reads, 'removal needs no fetch');
        $doc = $this->state()->read();
        $this->assertSame([], $doc['sources']);
        $this->assertSame([$this->id() => self::PRIVATE_URL], $doc['removed']);
        $this->assertSame(['secret' => $this->id()], $doc['bindings']);

        $this->bodies = [];
        $this->controller()->sources();
        $this->assertStringContainsString('Private source unavailable', $this->bodies[0]);
        $this->assertStringContainsString('name="name" value="Secret"', $this->bodies[0]);
    }

    public function testPublicSourceChangesWhileFrozenFlashTheRefusal(): void
    {
        $this->damage();

        $this->call('addSource', ['url' => 'https://other.example/plugins.json']);
        $this->call('removeSource', ['url' => self::PUBLIC_URL]);

        $this->assertSame([
            ['failure', e("ModMenu's private source state is damaged")],
            ['failure', e("ModMenu's private source state is damaged")],
        ], $this->flashes);
        $this->assertSame([self::PUBLIC_URL], (new SourceRepository($this->container))->getSources());
    }

    // ── sources page ───────────────────────────────────────────────────────

    public function testDescribeWording(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();

        foreach ([
            [(object) ['ok' => true], 'shared with Server'],
            [(object) ['ok' => false, 'reason' => 'not_shared'], 'not shared with Server'],
            [(object) ['ok' => false, 'reason' => 'server_off'], 'Vault off'],
            [null, 'Vault off'],
        ] as [$description, $wording]) {
            if ($description === null) {
                unset($this->container['vaultSecrets']);
            } else {
                $this->vault->description = $description;
            }
            $this->bodies = [];

            $this->controller()->sources();

            $body = $this->bodies[0];
            $this->assertStringContainsString(self::PRIVATE_URL, $body);
            $this->assertStringContainsString(self::REF, $body);
            $this->assertMatchesRegularExpression('#<span class="modmenu-vault[^"]*">'.preg_quote($wording, '#').'</span>#', $body);
        }
    }

    public function testAdoptUnbindAndDismissFromTheSourcesPage(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->privatePlugin('Lifeboat', '1.0.0');
        $this->publicPlugin('Lifeboat', '1.0.0');
        $this->addPrivate();
        $this->seed('Secret', '1.0.0');
        $this->seed('Lifeboat', '1.0.0');

        $this->controller()->sources();
        $body = $this->bodies[0];
        $this->assertStringContainsString('also listed by '.self::PUBLIC_URL, $body);
        $this->assertSame(2, substr_count($body, 'name="source_id" value="'.$this->id().'"') / 2, 'Adopt and Dismiss for each');

        $this->call('adopt', ['name' => 'Lifeboat', 'source_id' => $this->id()]);
        $this->call('dismiss', ['name' => 'Secret', 'source_id' => $this->id()]);

        $doc = $this->state()->read();
        $this->assertSame(['lifeboat' => $this->id()], $doc['bindings']);
        $this->assertSame([$this->id() => ['secret']], $doc['dismissed']);
        $this->assertSame([['success', 'Lifeboat adopted.'], ['success', 'Secret dismissed for this source.']], $this->flashes);

        $this->bodies = [];
        $this->controller()->sources();
        $this->assertMatchesRegularExpression('#Lifeboat.*?name="name" value="Lifeboat".*?Unbind#s', $this->bodies[0]);

        $this->call('unbind', ['name' => 'Lifeboat']);

        $this->assertSame([], $this->state()->read()['bindings']);
        $this->assertSame(['success', 'Lifeboat unbound.'], end($this->flashes));
    }

    public function testAdoptRefusedWhileSourceDown(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->seed('Secret', '1.0.0');
        $this->routes[self::PRIVATE_URL] = static fn () => [500, null];

        $this->call('adopt', ['name' => 'Secret', 'source_id' => $this->id()]);

        $this->assertSame([['failure', 'Private sources must be reachable to change plugins']], $this->flashes);
        $this->assertSame([], $this->state()->read()['bindings']);
    }

    // ── reset and frozen state ─────────────────────────────────────────────

    public function testResetShowsThenResets(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $before = $this->state()->read()['epoch'];
        file_put_contents($this->dir.'/private-state.json', '{"leak":"do-not-render"}');

        $this->controller()->directory();
        $this->assertStringContainsString(e("ModMenu's private source state is damaged"), $this->bodies[0]);
        $this->assertStringContainsString('fix their permissions by hand', $this->bodies[0]);
        $this->assertStringContainsString('action=resetPrivateState', $this->bodies[0]);

        $this->bodies = [];
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET']);
        $this->controller()->resetPrivateState();

        $this->assertCount(1, $this->bodies);
        $this->assertStringContainsString('Reset private state', $this->bodies[0]);
        $this->assertStringContainsString('every private source, binding and dismissal', $this->bodies[0]);
        $this->assertStringNotContainsString('do-not-render', $this->bodies[0]);
        $this->assertTrue($this->state()->isDamaged(), 'GET changes nothing');

        $this->call('resetPrivateState', []);

        $this->assertSame([['success', 'Private state reset.']], $this->flashes);
        $doc = $this->state()->read();
        $this->assertSame([], $doc['sources']);
        $this->assertNotSame($before, $doc['epoch']);
        $this->assertCount(1, glob($this->dir.'/private-state.json.damaged-*'));
    }

    public function testResetConfirmationListsWhatWillBeDropped(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->seed('Secret', '1.0.0');
        $this->bind('Secret');
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET']);

        $this->controller()->resetPrivateState();

        $this->assertStringContainsString(self::PRIVATE_URL, $this->bodies[0]);
        $this->assertStringContainsString('Secret', $this->bodies[0]);
    }

    public function testUninstallAndUnbindRefusedWhileFrozen(): void
    {
        $this->seed('Secret', '1.0.0');
        $this->damage();

        $this->call('uninstall', ['name' => 'Secret']);
        $this->call('unbind', ['name' => 'Secret']);

        $damaged = e("ModMenu's private source state is damaged");
        $this->assertSame([['failure', $damaged], ['failure', $damaged]], $this->flashes);
        $this->assertDirectoryExists($this->active.'/Secret');
    }

    public function testBrowseSaysPrivateSourcesMustBeReachable(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->routes[self::PRIVATE_URL] = static fn () => [500, null];

        $this->controller()->directory();

        $this->assertStringContainsString('Private sources must be reachable to change plugins', $this->bodies[0]);
    }

    public function testUploadRefusedWhilePrivateSourceDownOrFrozen(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->routes[self::PRIVATE_URL] = static fn () => [500, null];
        $this->upload('Alpha', '1.0.0');

        $this->routes[self::PRIVATE_URL] = $this->listingRoute($this->privatePlugins);
        $this->damage();
        $this->upload('Alpha', '1.0.0');

        $this->assertSame([
            ['failure', 'Private sources must be reachable to change plugins'],
            ['failure', e("ModMenu's private source state is damaged")],
        ], $this->flashes);
        $this->assertDirectoryDoesNotExist($this->active.'/Alpha');
    }

    public function testLegacyUrlInstallRefusedWhilePrivateSourceDownOrFrozen(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->routes[self::PRIVATE_URL] = static fn () => [500, null];
        $this->call('install', ['archive_url' => 'https://public.example/Alpha-1.0.0.zip']);

        $this->routes[self::PRIVATE_URL] = $this->listingRoute($this->privatePlugins);
        $this->damage();
        $this->call('install', ['archive_url' => 'https://public.example/Alpha-1.0.0.zip']);

        $this->assertSame([
            ['failure', 'Private sources must be reachable to change plugins'],
            ['failure', e("ModMenu's private source state is damaged")],
        ], $this->flashes);
        $this->assertDirectoryDoesNotExist($this->active.'/Alpha');
        $this->assertSame([], array_filter($this->requests, static fn (array $r) => $r['url'] === 'https://public.example/Alpha-1.0.0.zip'));
    }

    public function testLegacyUrlInstallWorksWithPrivateSourceUp(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();

        $this->call('install', ['archive_url' => 'https://public.example/Alpha-1.0.0.zip']);

        $this->assertSame([['success', 'Plugin installed.']], $this->flashes);
        $this->assertSame('1.0.0', $this->version('Alpha'));
    }

    public function testUploadWithoutPrivateStateFetchesNothing(): void
    {
        $this->upload('Alpha', '1.0.0');

        $this->assertSame([['success', 'Plugin &quot;Alpha&quot; installed.']], $this->flashes);
        $this->assertSame([], $this->requests);
        $this->assertFalse($this->state()->hasFile());
    }

    // ── CSRF ──────────────────────────────────────────────────────────────

    public function testNewPostActionsRefuseMissingAndWrongCsrf(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->addPrivate();
        $this->seed('Secret', '1.0.0');
        $file = $this->dir.'/private-state.json';
        $before = file_get_contents($file);
        $reads = $this->vault->reads;
        $post = ['url' => self::PRIVATE_URL, 'token_ref' => self::REF, 'id' => $this->id(), 'name' => 'Secret', 'source_id' => $this->id()];

        foreach (['addPrivateSource', 'removePrivateSource', 'adopt', 'unbind', 'dismiss', 'resetPrivateState'] as $action) {
            foreach (['missing' => null, 'wrong' => 'bad'] as $case => $csrf) {
                $values = $csrf === null ? $post : $post + ['csrf_token' => $csrf];
                $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], $values);
                try {
                    $this->controller()->$action();
                    $this->fail("$action with $case CSRF token was not refused");
                } catch (AccessForbiddenException $e) {
                }
                $this->assertSame($before, file_get_contents($file), "$action ($case) changed the state");
            }
        }
        $this->assertSame([], $this->flashes);
        $this->assertSame($reads, $this->vault->reads);
    }

    // ── canary ─────────────────────────────────────────────────────────────

    public function testCanaryTokenNeverRendered(): void
    {
        $this->privatePlugin('Secret', '1.0.0');
        $this->publicPlugin('Alpha', '1.0.0');

        // Add, look, install, update all.
        $this->call('addPrivateSource', ['url' => self::PRIVATE_URL, 'token_ref' => self::REF]);
        $this->controller()->sources();
        $this->controller()->directory();
        $this->call('install', ['name' => 'Secret']);
        $this->call('install', ['name' => 'Alpha']);
        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame('1.0.0', $this->version('Alpha'));
        $this->privatePlugins = [];
        $this->privatePlugin('Secret', '1.1.0');
        $this->controller()->directory();
        $this->call('updateAll', []);
        $this->assertSame('1.1.0', $this->version('Secret'));

        // Failures: GitHub refuses, a transport error quoting its headers, a Vault refusal quoting the token.
        $this->routes[self::PRIVATE_URL] = static fn () => [401, null];
        $this->controller()->directory();
        $this->call('updateAll', []);
        $this->call('addPrivateSource', ['url' => 'https://raw.githubusercontent.com/acme/dir/main/more.json', 'token_ref' => self::REF]);
        $this->routes[self::PRIVATE_URL] = static function ($write, array $headers) {
            throw new \RuntimeException('curl failed with '.implode(', ', $headers));
        };
        $this->controller()->directory();
        $this->call('install', ['name' => 'Alpha']);
        $this->routes[self::PRIVATE_URL] = $this->listingRoute($this->privatePlugins);
        $this->vault->token = new PrivateSourcesFlowVaultRefusal('revoked', 'revoked: '.self::TOKEN);
        $this->controller()->directory();
        $this->call('updateAll', []);
        $this->vault->token = self::TOKEN;
        $this->privatePlugins = [];
        $this->privatePlugin('Secret', '1.2.0');
        $this->routes[$this->tagsUrl('Secret', '1.2.0')] = static function ($write, array $headers) {
            throw new \RuntimeException('release failed: '.implode(', ', $headers));
        };
        $this->call('update', ['name' => 'Secret']);
        $this->controller()->sources();

        // The token really travelled, and the failures were reported.
        $sent = array_filter($this->requests, static fn (array $r) => in_array('Authorization: Bearer '.self::TOKEN, $r['headers'], true));
        $this->assertNotEmpty($sent);
        $failures = array_column(array_filter($this->flashes, static fn (array $f) => $f[0] === 'failure'), 1);
        $this->assertContains('Private sources must be reachable to change plugins', $failures);
        $this->assertContains("Couldn't download this private plugin's release.", $failures);
        $this->assertSame('1.1.0', $this->version('Secret'));

        $haystacks = [
            'response bodies' => implode("\n", $this->bodies),
            'flashes' => var_export($this->flashes, true),
            'redirects' => implode("\n", $this->redirects),
            'config table' => var_export($this->container['db']->table('settings')->findAll(), true),
            'log records' => var_export($this->log->records, true),
            'database log' => var_export($this->container['db']->getLogMessages(), true),
        ];
        foreach ($this->filesUnder($this->root) as $path) {
            $haystacks['file '.$path] = (string) file_get_contents($path);
        }
        $this->assertArrayHasKey('file '.$this->dir.'/private-state.json', $haystacks);
        foreach ($haystacks as $where => $text) {
            $this->assertStringNotContainsString('CANARY_', $text, "the token reached the $where");
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function controller(): PrivateSourcesFlowController
    {
        $controller = new PrivateSourcesFlowController($this->container);
        $controller->realManager = $this->manager;
        return $controller;
    }

    private function call(string $action, array $values): void
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], $values + ['csrf_token' => 'good']);
        $this->controller()->$action();
    }

    private function upload(string $name, string $version): void
    {
        $tmp = tempnam($this->root, 'upload-');
        copy($this->zip($name, $version), $tmp);
        $_FILES = ['plugin' => ['name' => "$name.zip", 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], ['csrf_token' => 'good']);
        $controller = new PrivateSourcesFlowUploadController($this->container);
        $controller->realManager = $this->manager;
        $controller->upload();
    }

    private function fakeVault(): object
    {
        return new class (self::TOKEN) {
            public int $reads = 0;
            public mixed $description;

            public function __construct(public string|\Throwable $token)
            {
                $this->description = (object) ['ok' => true];
            }

            public function read(string $consumer, string $ref): object
            {
                $this->reads++;
                if ($this->token instanceof \Throwable) {
                    throw $this->token;
                }
                return new class ($this->token) {
                    public function __construct(private string $value)
                    {
                    }

                    public function reveal(): string
                    {
                        return $this->value;
                    }
                };
            }

            public function describe(string $ref): object
            {
                return $this->description;
            }
        };
    }

    private function downloader(): ArchiveDownloader
    {
        return new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            function ($url, $address, $header, $write, $headers = []) {
                $this->requests[] = ['url' => $url, 'headers' => $headers];
                if (! isset($this->routes[$url])) {
                    return [404, null];
                }
                return ($this->routes[$url])($write, $headers);
            }
        );
    }

    private function listingRoute(array $plugins): callable
    {
        return static function ($write) use ($plugins) {
            $write(null, json_encode($plugins));
            return [200, null];
        };
    }

    private function serveListings(): void
    {
        $this->routes[self::PUBLIC_URL] = $this->listingRoute($this->publicPlugins);
        $this->routes[self::PRIVATE_URL] = $this->listingRoute($this->privatePlugins);
    }

    private function privatePlugin(string $name, string $version): void
    {
        $zip = $this->zip($name, $version);
        $download = 'https://github.com/acme/'.strtolower($name).'/releases/download/v'.$version.'/'.$name.'-'.$version.'.zip';
        $this->privatePlugins[] = ['name' => $name, 'version' => $version, 'sha256' => hash_file('sha256', $zip), 'download' => $download];
        $this->routes[$this->tagsUrl($name, $version)] = $this->listingRoute(['tag_name' => 'v'.$version, 'assets' => [['id' => 77, 'name' => $name.'-'.$version.'.zip']]]);
        $this->routes[GithubUrl::assetUrl('acme', strtolower($name), 77)] = static function ($write) use ($zip) {
            $write(null, (string) file_get_contents($zip));
            return [200, null];
        };
        $this->serveListings();
    }

    private function tagsUrl(string $name, string $version): string
    {
        return GithubUrl::releaseByTagUrl('acme', strtolower($name), 'v'.$version);
    }

    private function publicPlugin(string $name, string $version): void
    {
        $download = 'https://public.example/'.$name.'-'.$version.'.zip';
        $zip = $this->zip($name, $version);
        $this->publicPlugins[] = ['name' => $name, 'version' => $version, 'download' => $download];
        $this->routes[$download] = static function ($write) use ($zip) {
            $write(null, (string) file_get_contents($zip));
            return [200, null];
        };
        $this->serveListings();
    }

    private function addPrivate(): void
    {
        $this->call('addPrivateSource', ['url' => self::PRIVATE_URL, 'token_ref' => self::REF]);
        $this->assertSame(['success', 'Private source added.'], end($this->flashes));
        $this->flashes = [];
        $this->redirects = [];
    }

    private function bind(string $name): void
    {
        $id = $this->id();
        $this->state()->mutate(static function (array $doc) use ($name, $id) {
            $doc['bindings'][strtolower($name)] = $id;
            return $doc;
        });
    }

    private function damage(): void
    {
        $this->state()->mutate(static fn (array $doc) => $doc);
        file_put_contents($this->dir.'/private-state.json', 'not json');
    }

    private function id(): string
    {
        return GithubUrl::source(self::PRIVATE_URL)['id'];
    }

    private function state(): PrivateState
    {
        return new PrivateState($this->container, $this->dir);
    }

    private function zip(string $name, string $version): string
    {
        $path = $this->root.'/'.$name.'-'.$version.'.zip';
        if (! is_file($path)) {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString("$name/Plugin.php", "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
            $zip->addFromString("$name/plugin.json", json_encode(['name' => $name, 'version' => $version]));
            $zip->close();
        }
        return $path;
    }

    private function seed(string $name, string $version): void
    {
        mkdir($this->active.'/'.$name, 0700, true);
        file_put_contents($this->active.'/'.$name.'/Plugin.php', "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
        file_put_contents($this->active.'/'.$name.'/plugin.json', json_encode(['name' => $name, 'version' => $version]));
    }

    private function version(string $name): ?string
    {
        $file = $this->active.'/'.$name.'/plugin.json';
        return is_file($file) ? (json_decode((string) file_get_contents($file), true)['version'] ?? null) : null;
    }

    /** @return string[] */
    private function filesUnder(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
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
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }
        rmdir($path);
    }
}
