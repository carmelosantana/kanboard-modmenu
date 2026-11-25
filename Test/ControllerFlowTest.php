<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Controller\ModMenuController;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Controller\AccessForbiddenException;

/** Controller under test with the manager and catalog swapped for fakes. */
class FlowTestController extends ModMenuController
{
    public $fakeManager;
    public $fakeCatalog = [];

    protected function manager(): PluginManager
    {
        return $this->fakeManager;
    }

    protected function catalog(): array
    {
        return $this->fakeCatalog;
    }
}

/**
 * Update-all and the conflict prompt, driven through the real controller
 * actions with an admin session, a valid CSRF token and a fake PluginManager.
 */
class ControllerFlowTest extends Base
{
    private $flashes;
    private $redirects;
    private $bodies;

    public function setUp(): void
    {
        parent::setUp();
        $this->flashes = [];
        $this->redirects = [];
        $this->bodies = [];

        // The page layout renders the header for the logged-in user.
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
        $flash->method('success')->willReturnCallback(function ($m) { $this->flashes['success'] = $m; });
        $flash->method('failure')->willReturnCallback(function ($m) { $this->flashes['failure'] = $m; });
        $this->container['flash'] = $flash;

        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['redirect', 'html'])->getMock();
        $response->method('redirect')->willReturnCallback(function ($url) { $this->redirects[] = $url; });
        $response->method('html')->willReturnCallback(function ($body) { $this->bodies[] = $body; });
        $this->container['response'] = $response;
    }

    private function post(array $values): void
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], $values + ['csrf_token' => 'good']);
    }

    private function controller($manager, array $catalog = []): FlowTestController
    {
        $c = new FlowTestController($this->container);
        $c->fakeManager = $manager;
        $c->fakeCatalog = $catalog;
        return $c;
    }

    private function manager(): PluginManager
    {
        return $this->createMock(PluginManager::class);
    }

    private function migrationFixture(?string $successor = 'Harbor', ?array $harbor = null, ?PluginManager $manager = null): PluginManager
    {
        $root = sys_get_temp_dir() . '/modmenu-migrate-' . bin2hex(random_bytes(8));
        $this->migrationFixtureRoot = $root;
        mkdir($root . '/active/ShadcnTheme', 0755, true);
        file_put_contents($root . '/active/ShadcnTheme/Plugin.php', '<?php');
        file_put_contents($root . '/active/ShadcnTheme/plugin.json', json_encode([
            'deprecated' => ['superseded_by' => $successor],
        ]));
        if ($harbor !== null) {
            mkdir($root . '/disabled/Harbor', 0755, true);
            file_put_contents($root . '/disabled/Harbor/Plugin.php', '<?php');
            file_put_contents($root . '/disabled/Harbor/plugin.json', json_encode($harbor));
        }
        return ($manager ?? new PluginManager($this->container))->setDirectories($root . '/active', $root . '/disabled');
    }

    private ?string $migrationFixtureRoot = null;

    public function tearDown(): void
    {
        if ($this->migrationFixtureRoot !== null) {
            \Kanboard\Plugin\ModMenu\Model\PluginSwap::removeTree($this->migrationFixtureRoot);
        }
        parent::tearDown();
    }

    public function testMigrateShowsInstallDisableAndEnableSteps()
    {
        $this->assertMigrationConfirmation(null, true);
    }

    public function testMigrateOmitsInstallStepForInstalledSuccessor()
    {
        $this->assertMigrationConfirmation([], false);
    }

    private function assertMigrationConfirmation(?array $harbor, bool $showInstall): void
    {
        $manager = $this->migrationFixture('Harbor', $harbor);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['name' => 'ShadcnTheme']);
        $this->controller($manager)->migrate();

        $this->assertCount(1, $this->bodies);
        $body = $this->bodies[0];
        foreach (['Migrate ShadcnTheme to Harbor?', 'Disable ShadcnTheme', 'Enable Harbor',
            'ShadcnTheme stays installed but disabled. Remove it later if you like.', 'runMigrate', 'Cancel'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        $this->assertSame($showInstall, str_contains($body, 'Install Harbor'));
    }

    public function testRunMigrateWithoutCsrfChangesNothing()
    {
        $manager = $this->migrationFixture('Harbor', []);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], ['name' => 'ShadcnTheme']);
        $before = $manager->installedMap();
        try {
            $this->controller($manager)->runMigrate();
            $this->fail('Expected CSRF rejection.');
        } catch (AccessForbiddenException $e) {
            $this->assertSame($before, $manager->installedMap());
        }
    }

    public function testRunMigrateRejectsGetWithValidToken()
    {
        $manager = $this->migrationFixture('Harbor', []);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], [], [
            'name' => 'ShadcnTheme', 'csrf_token' => 'good',
        ]);
        $before = $manager->installedMap();
        try {
            $this->controller($manager)->runMigrate();
            $this->fail('Expected GET rejection.');
        } catch (AccessForbiddenException $e) {
            $this->assertSame($before, $manager->installedMap());
        }
    }

    public function testRunMigrateKeepsOldPluginActiveWhenInstallFails()
    {
        $manager = $this->migrationFixture();
        $client = $this->createMock(\Kanboard\Core\Http\Client::class);
        $client->expects($this->once())->method('get')->with('https://x/harbor.zip')->willReturn('');
        $this->container['httpClient'] = $client;

        $this->assertMigrationFailure($manager, "Couldn't install Harbor, so ShadcnTheme stays active.", [
            'Harbor' => ['download' => 'https://x/harbor.zip'],
        ]);
    }

    public function testRunMigrateWithoutSuccessorChangesNothing()
    {
        $this->assertMigrationFailure($this->migrationFixture(null), 'ShadcnTheme has no successor to migrate to.');
    }

    public function testRunMigrateRejectsSelfReference()
    {
        $this->assertMigrationFailure($this->migrationFixture('ShadcnTheme'), 'ShadcnTheme has no successor to migrate to.');
    }

    public function testRunMigrateWithoutCatalogEntryChangesNothing()
    {
        $this->assertMigrationFailure($this->migrationFixture(), 'Harbor is not in the plugin directory, so nothing changed.');
    }

    private function assertMigrationFailure(PluginManager $manager, string $message, array $catalog = []): void
    {
        $before = $manager->installedMap();
        $this->post(['name' => 'ShadcnTheme']);
        $this->controller($manager, $catalog)->runMigrate();

        $this->assertSame(['failure' => $message], $this->flashes);
        $this->assertSame($before, $manager->installedMap());
    }

    public function testRunMigrateUsesInstalledSuccessorAndRedirectsToInstalled()
    {
        $manager = $this->migrationFixture('Harbor', []);
        $this->post(['name' => 'ShadcnTheme', 'successor' => 'Other', 'archive_url' => 'https://x/other.zip']);
        $this->controller($manager)->runMigrate();

        $this->assertSame(['success' => 'Migrated ShadcnTheme to Harbor. ShadcnTheme stays installed but disabled.'], $this->flashes);
        $this->assertSame([$this->container['helper']->url->to('ModMenuController', 'show', ['plugin' => 'ModMenu'])], $this->redirects);
        $installed = $manager->installedMap();
        $this->assertSame('active', $installed['Harbor']['status']);
        $this->assertSame('disabled', $installed['ShadcnTheme']['status']);
    }

    public function testRunMigratePromptsToResolveSuccessorRequirementsAndContinuesAfterConfirmation()
    {
        $manager = $this->migrationFixture('Harbor', ['requires' => [['plugin' => 'Foundation']]]);
        $foundation = $this->migrationFixtureRoot . '/disabled/Foundation';
        mkdir($foundation);
        file_put_contents($foundation . '/Plugin.php', '<?php');
        $before = $manager->installedMap();
        $this->post(['name' => 'ShadcnTheme']);
        $this->controller($manager)->runMigrate();

        $this->assertCount(1, $this->bodies);
        $this->assertStringContainsString('Resolve dependencies', $this->bodies[0]);
        $this->assertStringContainsString('Enable Foundation', $this->bodies[0]);
        $this->assertSame($before, $manager->installedMap());

        $this->post(['name' => 'ShadcnTheme', 'action' => 'migrate']);
        $this->controller($manager)->resolve();

        $installed = $manager->installedMap();
        $this->assertSame('active', $installed['Foundation']['status']);
        $this->assertSame('active', $installed['Harbor']['status']);
        $this->assertSame('disabled', $installed['ShadcnTheme']['status']);
    }

    public function testRunMigrateRejectsActiveConflictBeforeResolvingDependencies()
    {
        $manager = $this->getMockBuilder(PluginManager::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['resolveDependencies'])->getMock();
        $manager->expects($this->never())->method('resolveDependencies');
        $this->migrationFixture('Harbor', [
            'requires' => [['plugin' => 'Foundation']],
            'conflicts' => ['OtherTheme'],
            'replaces' => ['ShadcnTheme'],
        ], $manager);
        foreach (['disabled/Foundation', 'active/OtherTheme'] as $folder) {
            $path = $this->migrationFixtureRoot . '/' . $folder;
            mkdir($path);
            file_put_contents($path . '/Plugin.php', '<?php');
        }
        $this->assertNotEmpty($manager->migrationCheck('ShadcnTheme', 'Harbor', [])['plan']);
        $before = $manager->installedMap();
        $this->post(['name' => 'ShadcnTheme', 'action' => 'migrate']);
        $this->controller($manager)->runMigrate();

        $this->assertSame(['failure' => 'Harbor conflicts with OtherTheme. Disable them first.'], $this->flashes);
        $this->assertSame($before, $manager->installedMap());
    }

    // ── Installed tab: requirement states ─────────────────────────────────

    public function testInstalledTabShowsAMetRequirementAsAGreenCheck()
    {
        $requires = [['plugin' => 'Dep', 'min_version' => '1.0.0']];
        $m = $this->manager();
        $m->method('listInstalled')->willReturn([[
            'name' => 'Needy', 'title' => 'Needy', 'version' => '1.0.0', 'author' => '', 'description' => '',
            'status' => 'active', 'requires' => $requires, 'recommends' => [],
        ]]);
        $map = ['Dep' => ['version' => '1.2.0', 'status' => 'active']];
        $m->expects($this->once())->method('installedMap')->willReturn($map);
        $m->expects($this->once())->method('requirementStates')->with($requires, [], $map)->willReturn([[
            'kind' => 'requires', 'plugin' => 'Dep', 'status' => 'satisfied', 'action' => 'none',
            'installed_version' => '1.2.0', 'min_version' => '1.0.0', 'reason' => '', 'download' => null,
        ]]);
        // Requires are classified once, by requirementStates; unmetDepsFor only supplies recommends.
        $m->expects($this->once())->method('unmetDepsFor')->with([], [], [], $map)->willReturn([]);
        $m->method('activeConflicts')->willReturn([]);

        $this->controller($m)->show();

        $this->assertCount(1, $this->bodies);
        $this->assertStringContainsString('modmenu-dep--met', $this->bodies[0]);
        $this->assertStringNotContainsString('modmenu-dep--required', $this->bodies[0]);
    }

    // ── Browse tab: default order and categories ──────────────────────────

    public function testBrowseRendersNewestUpdatedFirstWithCategoryCounts()
    {
        $http = $this->getMockBuilder(\Kanboard\Core\Http\Client::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getJson'])->getMock();
        $http->method('getJson')->willReturn([
            ['name' => 'OldOne', 'title' => 'Old one', 'version' => '1.0.0', 'category' => 'Fun', 'last_updated' => '2026-01-02'],
            ['name' => 'NoDate', 'title' => 'No date', 'version' => '1.0.0'],
            ['name' => 'NewOne', 'title' => 'New one', 'version' => '1.0.0', 'category' => 'Fun', 'last_updated' => '2026-11-17'],
        ]);
        $this->container['httpClient'] = $http;

        $m = $this->manager();
        $m->method('activeConflicts')->willReturn([]);
        $m->method('updatesAvailable')->willReturn([]);
        $this->controller($m)->directory();

        $this->assertCount(1, $this->bodies);
        $body = $this->bodies[0];
        $new = strpos($body, 'data-title="New one"');
        $old = strpos($body, 'data-title="Old one"');
        $none = strpos($body, 'data-title="No date"');
        $this->assertNotFalse($new);
        $this->assertTrue($new < $old && $old < $none, 'newest update first, undated last');
        $this->assertMatchesRegularExpression('#<option value="Fun">\s*Fun \(2\)\s*</option>#', $body);
        $this->assertMatchesRegularExpression('#<option value="Other">\s*Other \(1\)\s*</option>#', $body);
    }

    // ── Update all ─────────────────────────────────────────────────────────

    public function testUpdateAllFlashesCountAndFailures()
    {
        $m = $this->manager();
        $m->expects($this->once())->method('updateAll')->with(['A' => ['x']])
            ->willReturn(['updated' => ['Alpha', 'Beta'], 'failed' => ['Gamma' => 'checksum mismatch']]);
        $this->post([]);

        $this->controller($m, ['A' => ['x']])->updateAll();

        // Core shows only the success flash when both are set, so a partial run is one failure message.
        $this->assertArrayNotHasKey('success', $this->flashes);
        $this->assertStringContainsString('Updated 2: Alpha, Beta.', $this->flashes['failure']);
        $this->assertStringContainsString('Gamma (checksum mismatch)', $this->flashes['failure']);
        $this->assertStringContainsString('directory', end($this->redirects));
    }

    public function testUpdateAllWithNothingToDoSaysSo()
    {
        $m = $this->manager();
        $m->method('updateAll')->willReturn(['updated' => [], 'failed' => []]);
        $this->post([]);

        $this->controller($m)->updateAll();

        $this->assertSame('Everything is up to date.', $this->flashes['success']);
    }

    public function testUpdateAllRequiresCsrf()
    {
        $m = $this->manager();
        $m->expects($this->never())->method('updateAll');
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], ['csrf_token' => 'bad']);

        $this->expectException(AccessForbiddenException::class);
        $this->controller($m)->updateAll();
    }

    public function testUpdateAllForbiddenForNonAdmin()
    {
        $id = $this->container['userModel']->create(['username' => 'bob', 'role' => 'app-user']);
        session_set('user', $this->container['userModel']->getById($id));
        $session = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin'])->getMock();
        $session->method('isAdmin')->willReturn(false);
        $this->container['userSession'] = $session;
        $this->post([]);

        $this->expectException(AccessForbiddenException::class);
        $this->controller($this->manager())->updateAll();
    }

    public function testUpdateAllThatUpdatedModMenuGoesBackToInstalled()
    {
        $m = $this->manager();
        $m->method('updateAll')->willReturn(['updated' => ['Alpha', 'ModMenu'], 'failed' => []]);
        $this->post([]);

        $this->controller($m)->updateAll();

        $this->assertStringContainsString('ModMenu updated to', $this->flashes['success']);
        $this->assertStringContainsString('Updated 2', $this->flashes['success']);
        $this->assertStringNotContainsString('directory', end($this->redirects));
    }

    // ── Conflict prompt: enable ────────────────────────────────────────────

    public function testEnableWithActiveConflictRendersPromptAndDoesNotEnable()
    {
        $m = $this->manager();
        $m->method('activeConflicts')->willReturn(['ShadcnTheme']);
        $m->expects($this->never())->method('enable');
        $this->post(['name' => 'BattleLobby']);

        $this->controller($m)->enable();

        $this->assertCount(1, $this->bodies);
        $this->assertStringContainsString('BattleLobby conflicts with ShadcnTheme', $this->bodies[0]);
        $this->assertStringContainsString('resolveConflict', $this->bodies[0]);
        $this->assertSame([], $this->redirects);
    }

    public function testEnableWithoutConflictEnablesDirectly()
    {
        $m = $this->manager();
        $m->method('activeConflicts')->willReturn([]);
        $m->method('installedPluginsDeps')->willReturn([]);
        $m->method('forwardCheck')->willReturn(['satisfied' => true, 'blocked' => false, 'plan' => []]);
        $m->method('conflictWarning')->willReturn('');
        $m->expects($this->once())->method('enable')->with('Plain');
        $this->post(['name' => 'Plain']);

        $this->controller($m)->enable();

        $this->assertSame('Plugin enabled.', $this->flashes['success']);
    }

    public function testEnableSuccessorPromptsToDisableActiveReplacedPlugin()
    {
        $m = $this->getMockBuilder(PluginManager::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['listInstalled', 'enable'])->getMock();
        $m->method('listInstalled')->willReturn([
            ['name' => 'Harbor', 'status' => 'disabled', 'version' => '2.0.0', 'conflicts' => [], 'replaces' => ['ShadcnTheme']],
            ['name' => 'ShadcnTheme', 'status' => 'active', 'version' => '1.3.0', 'conflicts' => [], 'replaces' => []],
        ]);
        $m->expects($this->never())->method('enable');
        $this->post(['name' => 'Harbor']);

        $this->controller($m)->enable();

        $this->assertCount(1, $this->bodies);
        $this->assertStringContainsString('Harbor conflicts with ShadcnTheme', $this->bodies[0]);
        $this->assertStringContainsString('resolveConflict', $this->bodies[0]);
        $this->assertSame([], $this->redirects);
    }

    public function testResolveConflictConfirmDisablesThenEnables()
    {
        $m = $this->manager();
        $m->expects($this->once())->method('disableConflicts')->with('BattleLobby', [])->willReturn(['ShadcnTheme']);
        $m->method('installedPluginsDeps')->willReturn([]);
        $m->method('forwardCheck')->willReturn(['satisfied' => true, 'blocked' => false, 'plan' => []]);
        $m->method('conflictWarning')->willReturn('');
        $m->expects($this->once())->method('enable')->with('BattleLobby');
        $this->post(['name' => 'BattleLobby', 'action' => 'enable', 'decision' => 'confirm']);

        $this->controller($m)->resolveConflict();

        $this->assertStringContainsString('Disabled ShadcnTheme', $this->flashes['success']);
        $this->assertStringContainsString('Plugin enabled.', $this->flashes['success']);
    }

    public function testResolveConflictCancelLeavesPluginInactive()
    {
        $m = $this->manager();
        $m->expects($this->never())->method('disableConflicts');
        $m->expects($this->never())->method('enable');
        $m->method('activeConflicts')->willReturn(['ShadcnTheme']);
        $this->post(['name' => 'BattleLobby', 'action' => 'enable', 'decision' => 'cancel']);

        $this->controller($m)->resolveConflict();

        $this->assertStringContainsString('BattleLobby stays inactive', $this->flashes['failure']);
        $this->assertStringContainsString('ShadcnTheme', $this->flashes['failure']);
    }

    public function testResolveConflictRequiresCsrf()
    {
        $m = $this->manager();
        $m->expects($this->never())->method('disableConflicts');
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], ['name' => 'X', 'decision' => 'confirm', 'csrf_token' => 'bad']);

        $this->expectException(AccessForbiddenException::class);
        $this->controller($m)->resolveConflict();
    }

    // ── Conflict prompt: install ───────────────────────────────────────────

    public function testInstallChecksCatalogDeclaredConflicts()
    {
        $catalog = ['Harbor' => ['download' => 'https://x/h.zip', 'conflicts' => ['BattleLobby'], 'replaces' => ['ShadcnTheme']]];
        $m = $this->manager();
        $m->expects($this->once())->method('activeConflicts')->with('Harbor', ['BattleLobby'], ['ShadcnTheme'])->willReturn(['ShadcnTheme']);
        $m->expects($this->never())->method('installFromUrl');
        $this->post(['name' => 'Harbor', 'archive_url' => 'https://x/h.zip']);

        $this->controller($m, $catalog)->install();

        $this->assertStringContainsString('Harbor conflicts with ShadcnTheme', $this->bodies[0]);
    }

    public function testResolveConflictConfirmInstallPassesCatalogConflicts()
    {
        $catalog = ['Harbor' => ['download' => 'https://x/h.zip', 'conflicts' => ['BattleLobby'], 'replaces' => ['ShadcnTheme']]];
        $m = $this->manager();
        $m->expects($this->once())->method('disableConflicts')->with('Harbor', ['BattleLobby'], ['ShadcnTheme'])->willReturn(['ShadcnTheme']);
        $m->method('forwardCheck')->willReturn(['satisfied' => true, 'blocked' => false, 'plan' => []]);
        $m->method('conflictWarning')->willReturn('');
        $m->expects($this->once())->method('installFromUrl')->with('https://x/h.zip');
        $this->post(['name' => 'Harbor', 'action' => 'install', 'decision' => 'confirm']);

        $this->controller($m, $catalog)->resolveConflict();

        $this->assertStringContainsString('Plugin installed.', $this->flashes['success']);
        $this->assertStringContainsString('directory', end($this->redirects));
    }

    // ── resolve() cannot bypass the gate ───────────────────────────────────

    public function testResolveRefusesWhileAConflictIsActive()
    {
        $m = $this->manager();
        $m->method('activeConflicts')->willReturn(['ShadcnTheme']);
        $m->method('installedPluginsDeps')->willReturn([]);
        $m->method('forwardCheck')->willReturn(['satisfied' => false, 'blocked' => false, 'plan' => [['plugin' => 'Dep', 'action' => 'enable']]]);
        $m->expects($this->never())->method('resolveAndActivate');
        $this->post(['name' => 'BattleLobby', 'action' => 'enable']);

        $this->controller($m)->resolve();

        $this->assertStringContainsString('ShadcnTheme', $this->flashes['failure']);
    }

    // ── catalog-driven install and update ──────────────────────────────────

    public function testUpdateInstallsTheCatalogDownloadForTheNamedPlugin()
    {
        $catalog = ['Alpha' => ['download' => 'https://x/alpha.zip']];
        $m = $this->manager();
        $m->expects($this->once())->method('installFromUrl')->with('https://x/alpha.zip', 'Alpha')->willReturn('Alpha');
        $m->method('conflictWarning')->willReturn('');
        $this->post(['name' => 'Alpha', 'archive_url' => 'https://elsewhere/other.zip']);

        $this->controller($m, $catalog)->update();

        $this->assertSame('Plugin updated.', $this->flashes['success']);
    }

    public function testUpdateRefusesAPluginMissingFromTheCatalog()
    {
        $m = $this->manager();
        $m->expects($this->never())->method('installFromUrl');
        $this->post(['name' => 'Ghost', 'archive_url' => 'https://x/ghost.zip']);

        $this->controller($m, [])->update();

        $this->assertArrayHasKey('failure', $this->flashes);
    }

    public function testInstallByNameUsesTheCatalogDownloadAndExpectedName()
    {
        $catalog = ['Alpha' => ['download' => 'https://x/alpha.zip', 'requires' => []]];
        $m = $this->manager();
        $m->method('activeConflicts')->willReturn([]);
        $m->method('forwardCheck')->willReturn(['satisfied' => true, 'blocked' => false, 'plan' => []]);
        $m->expects($this->once())->method('installFromUrl')->with('https://x/alpha.zip', 'Alpha');
        $this->post(['name' => 'Alpha', 'archive_url' => 'https://elsewhere/other.zip']);

        $this->controller($m, $catalog)->install();

        $this->assertStringContainsString('Plugin installed.', $this->flashes['success']);
    }
}
