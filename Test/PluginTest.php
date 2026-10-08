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
        $this->assertSame('1.3.0', $plugin->getPluginVersion());
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

    /** Swap the container logger for one that records every call. */
    private function captureLogger(): object
    {
        $logger = new class extends \Kanboard\Core\Log\Logger {
            public array $records = [];

            public function log($level, $message, array $context = array())
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $this->container['logger'] = $logger;
        return $logger;
    }

    private function criticalMessages(object $logger): string
    {
        return implode("\n", array_map(fn($r) => $r[1], array_filter($logger->records, fn($r) => $r[0] === 'critical')));
    }

    /** initialize() and return what it threw (null when it returned). */
    private function initializeCatching(Plugin $plugin): ?\Throwable
    {
        try {
            $plugin->initialize();
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }

    private function assertLoadFailure(?\Throwable $e): void
    {
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString('ModMenu failed to load: boom', $e->getMessage());
        $this->assertInstanceOf(\Error::class, $e->getPrevious());
        $this->assertSame('boom', $e->getPrevious()->getMessage());
    }

    public function testInitializeRollsBackWhenNewVersionFailsToLoad()
    {
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, true);
        $this->seedCopy("$dir/ModMenu", $plugin->getPluginVersion());
        $this->seedCopy("$dir/.modmenu-previous-ModMenu", '0.9.0');

        $plugin->initialize(); // must not throw

        $this->assertStringContainsString('0.9.0', file_get_contents("$dir/ModMenu/plugin.json"));
        $this->assertDirectoryDoesNotExist("$dir/.modmenu-previous-ModMenu");
        $this->assertStringContainsString($plugin->getPluginVersion(), file_get_contents("$dir/.modmenu-failed-ModMenu/plugin.json"));
    }

    public function testInitializeThrowsRuntimeExceptionWhenNothingToRollBackTo()
    {
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, true);
        $this->seedCopy("$dir/ModMenu", $plugin->getPluginVersion());

        $this->assertLoadFailure($this->initializeCatching($plugin));
    }

    public function testInitializeLogsBothErrorsWhenRollbackThrows()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod-based read-only simulation does not apply to root.');
        }
        $logger = $this->captureLogger();
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, true);
        $this->seedCopy("$dir/ModMenu", $plugin->getPluginVersion());
        $this->seedCopy("$dir/.modmenu-previous-ModMenu", '0.9.0');

        chmod($dir, 0555); // the restore rename fails, so rollback() throws
        try {
            $thrown = $this->initializeCatching($plugin);
        } finally {
            chmod($dir, 0777);
        }
        $this->assertLoadFailure($thrown);

        $critical = $this->criticalMessages($logger);
        $this->assertStringContainsString('boom', $critical);
        $this->assertStringContainsString('Could not restore the previous version of ModMenu', $critical);
    }

    public function testInitializeDoesNotRollBackADifferentVersionOnDisk()
    {
        $dir = $this->tempPluginsDir();
        $plugin = $this->guardedPlugin($dir, true);
        $this->seedCopy("$dir/ModMenu", '9.9.9'); // another request already rolled back / replaced it
        $this->seedCopy("$dir/.modmenu-previous-ModMenu", '0.9.0');

        $this->assertLoadFailure($this->initializeCatching($plugin));

        $this->assertStringContainsString('9.9.9', file_get_contents("$dir/ModMenu/plugin.json"));
        $this->assertDirectoryExists("$dir/.modmenu-previous-ModMenu");
        $this->assertDirectoryDoesNotExist("$dir/.modmenu-failed-ModMenu");
    }
}
