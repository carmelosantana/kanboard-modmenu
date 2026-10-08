<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\PluginSwap;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

class PluginSwapTest extends Base
{
    private $root;
    private $plugins;
    private $swap;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/modmenu-swap-' . uniqid();
        $this->plugins = $this->root . '/plugins';
        mkdir($this->plugins, 0777, true);
        $this->swap = new PluginSwap($this->plugins);
    }

    public function tearDown(): void
    {
        @chmod($this->plugins, 0777);
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (! is_dir($d)) { return; }
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = "$d/$f";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($d);
    }

    /** Create a plugin dir at $dir with a real Plugin.php and (optionally) a plugin.json. */
    private function makePlugin(string $dir, string $name, ?array $json = null, ?string $pluginPhp = null): string
    {
        mkdir($dir, 0777, true);
        file_put_contents("$dir/Plugin.php", $pluginPhp ?? "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
        if ($json !== null) {
            file_put_contents("$dir/plugin.json", json_encode($json));
        }
        return $dir;
    }

    /** Stage a plugin under a fresh staging dir; returns the staged plugin dir. */
    private function stage(string $name, string $version, array $extra = []): string
    {
        $staging = $this->swap->createStaging();
        return $this->makePlugin("$staging/$name", $name, ['name' => $name, 'version' => $version] + $extra);
    }

    private function installed(string $name, string $version): string
    {
        return $this->makePlugin("{$this->plugins}/$name", $name, ['name' => $name, 'version' => $version]);
    }

    private function assertThrowsMessage(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (ModMenuException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            return;
        }
        $this->fail('Expected ModMenuException containing: ' . $needle);
    }

    // ---- createStaging / helpers ----------------------------------------

    public function testCreateStagingMakesDotDirInsidePluginsDir()
    {
        $staging = $this->swap->createStaging();
        $this->assertDirectoryExists($staging);
        $this->assertSame($this->plugins, dirname($staging));
        $this->assertMatchesRegularExpression('/^\.modmenu-staging-[0-9a-f]{16}$/', basename($staging));
    }

    public function testPreviousDirIsDotDirInsidePluginsDir()
    {
        $this->assertSame($this->plugins . '/.modmenu-previous-Alpha', $this->swap->previousDir('Alpha'));
    }

    public function testReadVersion()
    {
        $dir = $this->installed('Alpha', '1.2.3');
        $this->assertSame('1.2.3', PluginSwap::readVersion($dir));
        $this->assertNull(PluginSwap::readVersion($this->plugins . '/Nope'));
    }

    public function testRemoveTree()
    {
        $dir = $this->installed('Alpha', '1.0.0');
        mkdir("$dir/Model/Deep", 0777, true);
        file_put_contents("$dir/Model/Deep/X.php", '<?php');
        $this->assertTrue(PluginSwap::removeTree($dir));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertTrue(PluginSwap::removeTree($dir));
    }

    // ---- verify ---------------------------------------------------------

    public function testVerifyHappyPathReturnsVersion()
    {
        $staged = $this->stage('Alpha', '1.3.0');
        mkdir("$staged/Model", 0777, true);
        file_put_contents("$staged/Model/X.php", "<?php\nclass X {}\n");
        $this->assertSame('1.3.0', $this->swap->verify($staged, 'Alpha'));
        $this->assertSame('1.3.0', $this->swap->verify($staged, 'Alpha', '1.2.0'));
    }

    public function testVerifyReturnsEmptyStringWithoutPluginJson()
    {
        $staging = $this->swap->createStaging();
        $staged = $this->makePlugin("$staging/Alpha", 'Alpha');
        $this->assertSame('', $this->swap->verify($staged, 'Alpha'));
    }

    public function testVerifyFailsWithoutPluginPhp()
    {
        $staged = $this->stage('Alpha', '1.3.0');
        unlink("$staged/Plugin.php");
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha'), 'Plugin.php');
    }

    public function testVerifyFailsOnWrongNamespace()
    {
        $staging = $this->swap->createStaging();
        $staged = $this->makePlugin("$staging/Alpha", 'Alpha', ['version' => '1.3.0'], "<?php\nnamespace Kanboard\\Plugin\\Beta;\n");
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha'), 'Kanboard\\Plugin\\Alpha');
    }

    public function testVerifyAcceptsNamespaceWithExtraWhitespace()
    {
        $staging = $this->swap->createStaging();
        $staged = $this->makePlugin("$staging/Alpha", 'Alpha', ['version' => '1.3.0'], "<?php\n\nnamespace   Kanboard\\Plugin\\Alpha ;\n");
        $this->assertSame('1.3.0', $this->swap->verify($staged, 'Alpha'));
    }

    public function testVerifyFailsOnSyntaxErrorInNestedFileWithPath()
    {
        $staged = $this->stage('Alpha', '1.3.0');
        mkdir("$staged/Model", 0777, true);
        file_put_contents("$staged/Model/X.php", "<?php\nclass X {\n  public function (\n}\n");
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha'), 'Model/X.php');
    }

    public function testVerifyFailsOnCompileErrorInNestedFileWithPath()
    {
        $staged = $this->stage('Alpha', '1.3.0');
        mkdir("$staged/Model", 0777, true);
        file_put_contents("$staged/Model/Y.php", "<?php class A { public public \$x; }");
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha'), 'Model/Y.php');
    }

    public function testVerifyFailsWhenPhpVersionTooLow()
    {
        $staged = $this->stage('Alpha', '1.3.0', ['php_version' => '>=99.0']);
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha'), '99.0');
    }

    public function testVerifyAcceptsSatisfiedPhpVersionAndIgnoresOtherForms()
    {
        $staged = $this->stage('Alpha', '1.3.0', ['php_version' => '8.0']);
        $this->assertSame('1.3.0', $this->swap->verify($staged, 'Alpha'));
        $staged2 = $this->stage('Beta', '1.0.0', ['php_version' => '^99.0']);
        $this->assertSame('1.0.0', $this->swap->verify($staged2, 'Beta'));
    }

    public function testVerifyAcceptsCompatibleVersion()
    {
        $staged = $this->stage('Alpha', '1.3.0', ['compatible_version' => '>=1.0.0']);
        $this->assertSame('1.3.0', $this->swap->verify($staged, 'Alpha'));
    }

    public function testVerifyFailsOnIncompatibleKanboardVersion()
    {
        if (\Kanboard\Core\Plugin\Version::isCompatible('>=99.0.0', APP_VERSION)) {
            $this->markTestSkipped('APP_VERSION (' . APP_VERSION . ') is a dev build; Version::isCompatible() always passes.');
        }
        $staged = $this->stage('Alpha', '1.3.0', ['compatible_version' => '>=99.0.0']);
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha'), '99.0.0');
    }

    public function testVerifyFailsWhenNotNewer()
    {
        $staged = $this->stage('Alpha', '1.2.0');
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha', '1.3.0'), '1.2.0');
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha', '1.3.0'), '1.3.0');
    }

    public function testVerifyFailsOnEqualVersion()
    {
        $staged = $this->stage('Alpha', '1.3.0');
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha', '1.3.0'), '1.3.0');
    }

    public function testVerifyFailsWithoutPluginJsonWhenNewerRequired()
    {
        $staging = $this->swap->createStaging();
        $staged = $this->makePlugin("$staging/Alpha", 'Alpha');
        $this->assertThrowsMessage(fn() => $this->swap->verify($staged, 'Alpha', '1.0.0'), 'plugin.json');
    }

    // ---- swap -----------------------------------------------------------

    public function testSwapFreshInstall()
    {
        $staged = $this->stage('Alpha', '1.0.0');
        $this->swap->swap($staged, 'Alpha', true);
        $this->assertSame('1.0.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
        $this->assertDirectoryDoesNotExist($staged);
        $this->assertDirectoryDoesNotExist($this->swap->previousDir('Alpha'));
    }

    public function testSwapReplaceKeepingPrevious()
    {
        $this->installed('Alpha', '1.0.0');
        $staged = $this->stage('Alpha', '1.1.0');
        $this->swap->swap($staged, 'Alpha', true);
        $this->assertSame('1.1.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
        $this->assertSame('1.0.0', PluginSwap::readVersion($this->swap->previousDir('Alpha')));
    }

    public function testSwapReplaceWithoutKeepingPrevious()
    {
        $this->installed('Alpha', '1.0.0');
        $staged = $this->stage('Alpha', '1.1.0');
        $this->swap->swap($staged, 'Alpha', false);
        $this->assertSame('1.1.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
        $this->assertDirectoryDoesNotExist($this->swap->previousDir('Alpha'));
    }

    public function testSwapRemovesStalePrevious()
    {
        $this->installed('Alpha', '1.0.0');
        $this->makePlugin($this->swap->previousDir('Alpha'), 'Alpha', ['version' => '0.1.0']);
        $staged = $this->stage('Alpha', '1.1.0');
        $this->swap->swap($staged, 'Alpha', true);
        $this->assertSame('1.0.0', PluginSwap::readVersion($this->swap->previousDir('Alpha')));
        $this->assertSame('1.1.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
    }

    public function testSwapThrowsAndChangesNothingWhenTargetCannotBeMovedAside()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod-based read-only simulation does not apply to root.');
        }
        $this->installed('Alpha', '1.0.0');
        $staged = $this->stage('Alpha', '1.1.0');
        chmod($this->plugins, 0555);
        try {
            $this->assertThrowsMessage(fn() => $this->swap->swap($staged, 'Alpha', true), 'Nothing was changed');
        } finally {
            chmod($this->plugins, 0777);
        }
        $this->assertSame('1.0.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
        $this->assertSame('1.1.0', PluginSwap::readVersion($staged));
        $this->assertDirectoryDoesNotExist($this->swap->previousDir('Alpha'));
    }

    // ---- finalize -------------------------------------------------------

    public function testFinalizeRemovesPreviousWhenRunningVersionMatchesDisk()
    {
        $this->installed('Alpha', '1.0.0');
        $this->swap->swap($this->stage('Alpha', '1.1.0'), 'Alpha', true);
        $this->assertTrue($this->swap->finalize('Alpha', '1.1.0'));
        $this->assertDirectoryDoesNotExist($this->swap->previousDir('Alpha'));
    }

    public function testFinalizeFalseWithoutPrevious()
    {
        $this->installed('Alpha', '1.1.0');
        $this->assertFalse($this->swap->finalize('Alpha', '1.1.0'));
    }

    public function testFinalizeFalseOnVersionMismatchKeepsPrevious()
    {
        $this->installed('Alpha', '1.0.0');
        $this->swap->swap($this->stage('Alpha', '1.1.0'), 'Alpha', true);
        $this->assertFalse($this->swap->finalize('Alpha', '1.0.0'));
        $this->assertDirectoryExists($this->swap->previousDir('Alpha'));
    }

    // ---- rollback -------------------------------------------------------

    public function testRollbackRestoresPreviousAndParksNewAsFailed()
    {
        $this->installed('Alpha', '1.0.0');
        $this->swap->swap($this->stage('Alpha', '1.1.0'), 'Alpha', true);
        $this->makePlugin("{$this->plugins}/.modmenu-failed-Alpha", 'Alpha', ['version' => '0.0.1']); // stale
        $this->assertTrue($this->swap->rollback('Alpha'));
        $this->assertSame('1.0.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
        $this->assertSame('1.1.0', PluginSwap::readVersion("{$this->plugins}/.modmenu-failed-Alpha"));
        $this->assertDirectoryDoesNotExist($this->swap->previousDir('Alpha'));
    }

    public function testRollbackWithoutPreviousReturnsFalse()
    {
        $this->installed('Alpha', '1.1.0');
        $this->assertFalse($this->swap->rollback('Alpha'));
        $this->assertSame('1.1.0', PluginSwap::readVersion("{$this->plugins}/Alpha"));
    }
}
