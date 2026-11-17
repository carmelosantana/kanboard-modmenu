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

    // ── Update all ─────────────────────────────────────────────────────────

    public function testUpdateAllFlashesCountAndFailures()
    {
        $m = $this->manager();
        $m->expects($this->once())->method('updateAll')->with(['A' => ['x']])
            ->willReturn(['updated' => ['Alpha', 'Beta'], 'failed' => ['Gamma' => 'checksum mismatch']]);
        $this->post([]);

        $this->controller($m, ['A' => ['x']])->updateAll();

        $this->assertStringContainsString('Updated 2', $this->flashes['success']);
        $this->assertStringContainsString('Alpha, Beta', $this->flashes['success']);
        $this->assertStringContainsString('Gamma', $this->flashes['failure']);
        $this->assertStringContainsString('checksum mismatch', $this->flashes['failure']);
        $this->assertStringContainsString('directory', end($this->redirects));
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
        $catalog = ['BattleLobby' => ['download' => 'https://x/b.zip', 'conflicts' => ['ShadcnTheme'], 'requires' => []]];
        $m = $this->manager();
        $m->expects($this->once())->method('activeConflicts')->with('BattleLobby', ['ShadcnTheme'])->willReturn(['ShadcnTheme']);
        $m->expects($this->never())->method('installFromUrl');
        $this->post(['name' => 'BattleLobby', 'archive_url' => 'https://x/b.zip']);

        $this->controller($m, $catalog)->install();

        $this->assertStringContainsString('BattleLobby conflicts with ShadcnTheme', $this->bodies[0]);
    }

    public function testResolveConflictConfirmInstallPassesCatalogConflicts()
    {
        $catalog = ['BattleLobby' => ['download' => 'https://x/b.zip', 'conflicts' => ['ShadcnTheme'], 'requires' => []]];
        $m = $this->manager();
        $m->expects($this->once())->method('disableConflicts')->with('BattleLobby', ['ShadcnTheme'])->willReturn(['ShadcnTheme']);
        $m->method('forwardCheck')->willReturn(['satisfied' => true, 'blocked' => false, 'plan' => []]);
        $m->method('conflictWarning')->willReturn('');
        $m->expects($this->once())->method('installFromUrl')->with('https://x/b.zip');
        $this->post(['name' => 'BattleLobby', 'action' => 'install', 'decision' => 'confirm']);

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
}
