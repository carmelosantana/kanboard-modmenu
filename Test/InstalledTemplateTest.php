<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Http\Response;
use Kanboard\Plugin\ModMenu\Controller\ModMenuController;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use KanboardTests\units\Base;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Installed tab: ModMenu's own card (self-update hints) and the
 * PLUGIN_INSTALLER explanation.
 */
class InstalledTemplateTest extends Base
{
    private const SELF_LINE = 'This is ModMenu itself. It cannot be disabled or removed here. Update it from Browse.';
    private const PENDING_LINE = 'An update was just installed; the previous copy is removed on the next page load.';
    private const INSTALLER_LINE = 'Kanboard\'s built-in plugin installer is off (PLUGIN_INSTALLER = false, the default). ModMenu is a separate installer for administrators: it keeps working while the plugins folder is writable and the PHP zip extension is loaded.';

    private function render(bool $pending, bool $installerEnabled): string
    {
        return $this->container['template']->render('ModMenu:settings/installed', [
            'tab' => 'installed', 'is_configured' => true, 'not_configured_reason' => '', 'self_name' => 'ModMenu',
            'self_update_pending' => $pending,
            'plugin_installer_enabled' => $installerEnabled,
            'plugins' => [[
                'name' => 'ModMenu', 'title' => 'ModMenu', 'version' => '1.3.0', 'author' => '', 'description' => '',
                'status' => 'active', 'unmet_deps' => [], 'active_conflicts' => [],
            ]],
        ]);
    }

    private function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    public function testSelfCardPointsToBrowseForUpdates()
    {
        $html = $this->decoded($this->render(false, true));
        $this->assertStringContainsString(self::SELF_LINE, $html);
        $this->assertStringNotContainsString('This is ModMenu itself and cannot be disabled or removed here.', $html);
    }

    public function testPendingNoteShownOnlyWhileSelfUpdatePending()
    {
        $this->assertStringContainsString(self::PENDING_LINE, $this->decoded($this->render(true, true)));
        $this->assertStringNotContainsString(self::PENDING_LINE, $this->decoded($this->render(false, true)));
    }

    public function testInstallerNoteShownWhenPluginInstallerOff()
    {
        $html = $this->render(false, false);
        $this->assertStringContainsString(self::INSTALLER_LINE, $this->decoded($html));
        $this->assertStringContainsString('alert alert-info', $html);
    }

    public function testInstallerNoteHiddenWhenPluginInstallerOn()
    {
        $this->assertStringNotContainsString(self::INSTALLER_LINE, $this->decoded($this->render(false, true)));
    }

    public static function migrationStates(): array
    {
        return [
            'old active, successor missing' => ['active', null, true],
            'old active, successor active' => ['active', 'active', true],
            'old disabled, successor missing' => ['disabled', null, true],
            'old disabled, successor disabled' => ['disabled', 'disabled', true],
            'migration completed' => ['disabled', 'active', false],
        ];
    }

    /** @dataProvider migrationStates */
    #[DataProvider('migrationStates')]
    public function testInstalledDeprecationUsesLocalMetadata(string $oldStatus, ?string $successorStatus, bool $showMigrate): void
    {
        $root = sys_get_temp_dir() . '/modmenu-installed-' . uniqid();
        $active = "$root/active";
        $disabled = "$root/disabled";
        $oldDir = $oldStatus === 'active' ? $active : $disabled;
        $fixtures = [
            "$oldDir/ShadcnTheme" => ['name' => 'Shadcn Theme', 'deprecated' => ['superseded_by' => 'Harbor']],
            "$active/Plain" => ['name' => 'Plain'],
        ];
        if ($successorStatus !== null) {
            $successorDir = $successorStatus === 'active' ? $active : $disabled;
            $fixtures["$successorDir/Harbor"] = ['name' => 'Harbor Theme'];
        }

        try {
            mkdir($active, 0777, true);
            mkdir($disabled);
            foreach ($fixtures as $path => $metadata) {
                mkdir($path);
                file_put_contents("$path/Plugin.php", "<?php\n");
                file_put_contents("$path/plugin.json", json_encode($metadata));
            }

            session_set('user', $this->container['userModel']->getById(1));
            // The migration task registers this route in the plugin.
            $this->container['route']->enable();
            $this->container['route']->addRoute('config/modmenu/plugin/migrate', 'ModMenuController', 'migrate', 'ModMenu');

            $requests = [];
            $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
                static fn () => ['140.82.112.3'],
                static function ($url) use (&$requests) {
                    $requests[] = $url;
                    return [500, null];
                }
            );

            $body = '';
            $response = $this->createMock(Response::class);
            $response->expects($this->once())->method('html')->willReturnCallback(function ($html) use (&$body) {
                $body = $html;
            });
            $this->container['response'] = $response;

            $manager = (new PluginManager($this->container))->setDirectories($active, $disabled);
            $controller = $this->getMockBuilder(ModMenuController::class)
                ->setConstructorArgs([$this->container])->onlyMethods(['manager', 'catalog'])->getMock();
            $controller->method('manager')->willReturn($manager);
            $controller->expects($this->never())->method('catalog');
            $controller->show();

            $this->assertSame([], $requests);
            $this->assertStringContainsString('<strong>Plain</strong>', $body);
            $this->assertSame(1, substr_count($body, 'Replaced by '));
            $this->assertStringContainsString('Replaced by Harbor.', $body);
            $this->assertSame($showMigrate ? 1 : 0, preg_match_all('~<a href="([^"]*)"[^>]*>Migrate</a>~', $body, $links));
            if ($showMigrate) {
                $this->assertStringEndsWith('config/modmenu/plugin/migrate?name=ShadcnTheme', $links[1][0]);
            }
        } finally {
            foreach (array_keys($fixtures) as $path) {
                unlink("$path/Plugin.php");
                unlink("$path/plugin.json");
                rmdir($path);
            }
            rmdir($active);
            rmdir($disabled);
            rmdir($root);
        }
    }
}
