<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\ModMenu\Plugin;
use KanboardTests\units\Base;

class PluginTest extends Base
{
    public function testPluginNameValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('ModMenu', $plugin->getPluginName());
    }

    public function testPluginVersionValue()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('1.2.1', $plugin->getPluginVersion());
    }

    public function testCompatibleVersion()
    {
        $plugin = new Plugin($this->container);
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testInitializeRegistersRoutesWithoutError()
    {
        $plugin = new Plugin($this->container);
        $plugin->initialize();
        $this->assertNotEmpty($plugin->getPluginDescription());
    }
    // ── load guard: finalize / rollback a self-update ──────────────────────

    private function tempPluginsDir(): string
    {
        $dir = sys_get_temp_dir() . '/modmenu-guard-' . uniqid();
        mkdir($dir, 0777, true);
        $this->guardDirs[] = $dir;
        return $dir;
    }

    private array $guardDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->guardDirs as $dir) {
            \Kanboard\Plugin\ModMenu\Model\PluginSwap::removeTree($dir);
        }
        parent::tearDown();
    }

    private function seedCopy(string $dir, string $version): void
    {
        mkdir($dir, 0777, true);
        file_put_contents("$dir/Plugin.php", "<?php\nnamespace Kanboard\\Plugin\\ModMenu;\n");
        file_put_contents("$dir/plugin.json", json_encode(['name' => 'ModMenu', 'version' => $version]));
    }

    private function guardedPlugin(string $pluginsDir, bool $failing): Plugin
    {
        return new class($this->container, $pluginsDir, $failing) extends Plugin {
            public function __construct($container, private string $dir, private bool $failing)
            {
                parent::__construct($container);
            }

            protected function pluginsDir(): string
            {
                return $this->dir;
            }

            protected function register(): void
            {
                if ($this->failing) {
                    throw new \Error('boom');
                }
                parent::register();
            }
        };
    }

    public function testInitializeFinalizesPendingSelfUpdate()
    {
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, false);
        $this->seedCopy("$dir/ModMenu", $plugin->getPluginVersion());
        $this->seedCopy("$dir/.modmenu-previous-ModMenu", '0.9.0');

        $plugin->initialize();

        $this->assertDirectoryDoesNotExist("$dir/.modmenu-previous-ModMenu");
        $this->assertDirectoryExists("$dir/ModMenu");
    }

    public function testInitializeRollsBackWhenNewVersionFailsToLoad()
    {
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, true);
        $this->seedCopy("$dir/ModMenu", '9.9.9');
        $this->seedCopy("$dir/.modmenu-previous-ModMenu", '0.9.0');

        $plugin->initialize(); // must not throw

        $this->assertStringContainsString('0.9.0', file_get_contents("$dir/ModMenu/plugin.json"));
        $this->assertDirectoryDoesNotExist("$dir/.modmenu-previous-ModMenu");
        $this->assertStringContainsString('9.9.9', file_get_contents("$dir/.modmenu-failed-ModMenu/plugin.json"));
    }

    public function testInitializeRethrowsWhenNothingToRollBackTo()
    {
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, true);
        $this->seedCopy("$dir/ModMenu", '9.9.9');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('boom');
        $plugin->initialize();
    }
}
