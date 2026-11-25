<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\PluginArchive;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

class PluginManagerTest extends Base
{
    private $root;
    private $active;
    private $disabled;
    private $manager;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/modmenu-mgr-' . uniqid();
        $this->active = $this->root . '/plugins';
        $this->disabled = $this->root . '/disabled';
        mkdir($this->active, 0777, true);
        mkdir($this->disabled, 0777, true);
        $this->manager = (new PluginManager($this->container))->setDirectories($this->active, $this->disabled);
    }

    public function tearDown(): void
    {
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

    private function seedPlugin(string $dir, string $name, string $version, array $metadata = []): void
    {
        mkdir("$dir/$name", 0777, true);
        file_put_contents("$dir/$name/Plugin.php", "<?php\n");
        file_put_contents("$dir/$name/plugin.json", json_encode([
            'name' => $name, 'version' => $version, 'description' => "$name desc",
            'author' => 'Tester', 'homepage' => 'https://example.com',
        ] + $metadata));
    }

    public function testListInstalledMergesActiveAndDisabled()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->disabled, 'Beta', '2.1.0');

        $list = $this->manager->listInstalled();
        $byName = [];
        foreach ($list as $p) { $byName[$p['name']] = $p; }

        $this->assertSame('active', $byName['Alpha']['status']);
        $this->assertSame('1.0.0', $byName['Alpha']['version']);
        $this->assertSame('disabled', $byName['Beta']['status']);
        $this->assertSame('2.1.0', $byName['Beta']['version']);
    }

    public function testListInstalledSkipsDotDirsLikeKanboardsLoader()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->active, '.modmenu-trash-x', '1.1.0');
        $this->seedPlugin($this->active, '.modmenu-previous-ModMenu', '1.1.0');
        $this->seedPlugin($this->disabled, '.hidden', '2.0.0');

        $names = array_column($this->manager->listInstalled(), 'name');
        $this->assertSame(['Alpha'], $names);
        $this->assertSame(['Alpha'], array_keys($this->manager->installedMap()));
    }

    public function testListInstalledReadsReplacementAndDeprecationMetadata()
    {
        $deprecated = ['superseded_by' => 'Harbor', 'since' => '1.3.0', 'reason' => 'Renamed'];
        $this->seedPlugin($this->active, 'Harbor', '2.0.0', ['replaces' => ['ShadcnTheme']]);
        $this->seedPlugin($this->active, 'Plain', '1.0.0');
        $this->seedPlugin($this->disabled, 'ShadcnTheme', '1.3.0', ['deprecated' => $deprecated]);

        $installed = array_column($this->manager->listInstalled(), null, 'name');
        $this->assertSame(['ShadcnTheme'], $installed['Harbor']['replaces']);
        $this->assertSame($deprecated, $installed['ShadcnTheme']['deprecated']);
        $this->assertSame([], $installed['Plain']['replaces']);
        $this->assertArrayHasKey('deprecated', $installed['Plain']);
        $this->assertNull($installed['Plain']['deprecated']);
    }

    public function testUninstallRefusesDotNameAndLeavesItUntouched()
    {
        $this->seedPlugin($this->active, '.modmenu-trash-x', '1.1.0');
        $this->assertInvalidName(fn() => $this->manager->uninstall('.modmenu-trash-x'));
        $this->assertDirectoryExists("{$this->active}/.modmenu-trash-x");
    }

    public function testDisableRefusesDotNameAndLeavesItUntouched()
    {
        $this->seedPlugin($this->active, '.modmenu-trash-x', '1.1.0');
        $this->assertInvalidName(fn() => $this->manager->disable('.modmenu-trash-x'));
        $this->assertDirectoryExists("{$this->active}/.modmenu-trash-x");
        $this->assertDirectoryDoesNotExist("{$this->disabled}/.modmenu-trash-x");
    }

    public function testEnableRefusesDotNameAndLeavesItUntouched()
    {
        $this->seedPlugin($this->disabled, '.hidden', '2.0.0');
        $this->assertInvalidName(fn() => $this->manager->enable('.hidden'));
        $this->assertDirectoryExists("{$this->disabled}/.hidden");
        $this->assertDirectoryDoesNotExist("{$this->active}/.hidden");
    }

    private function assertInvalidName(callable $fn): void
    {
        try {
            $fn();
        } catch (ModMenuException $e) {
            $this->assertSame('Invalid plugin name.', $e->getMessage());
            return;
        }
        $this->fail('Expected ModMenuException "Invalid plugin name."');
    }

    public function testDisableMovesFolderToDisabledDir()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->manager->disable('Alpha');
        $this->assertDirectoryDoesNotExist("{$this->active}/Alpha");
        $this->assertDirectoryExists("{$this->disabled}/Alpha");
    }

    public function testEnableMovesFolderBack()
    {
        $this->seedPlugin($this->disabled, 'Beta', '2.0.0');
        $this->manager->enable('Beta');
        $this->assertDirectoryExists("{$this->active}/Beta");
        $this->assertDirectoryDoesNotExist("{$this->disabled}/Beta");
    }

    public function testUninstallRemovesFolder()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->manager->uninstall('Alpha');
        $this->assertDirectoryDoesNotExist("{$this->active}/Alpha");
    }

    public function testDisableRefusesSelf()
    {
        $this->seedPlugin($this->active, 'ModMenu', '1.0.0');
        $this->expectException(ModMenuException::class);
        $this->manager->disable('ModMenu');
    }

    public function testUninstallRefusesSelf()
    {
        $this->seedPlugin($this->active, 'ModMenu', '1.0.0');
        $this->expectException(ModMenuException::class);
        $this->manager->uninstall('ModMenu');
    }

    public function testNameGuardRejectsTraversal()
    {
        $this->expectException(ModMenuException::class);
        $this->manager->disable('../evil');
    }

    public function testEnableRefusesTraversal()
    {
        $this->expectException(ModMenuException::class);
        $this->manager->enable('../evil');
    }

    public function testUninstallRemovesDisabledPlugin()
    {
        $this->seedPlugin($this->disabled, 'Gamma', '3.0.0');
        $this->manager->uninstall('Gamma');
        $this->assertDirectoryDoesNotExist("{$this->disabled}/Gamma");
    }

    public function testFolderNameIsIdentityNotJsonName()
    {
        // Folder: WidgetBox, plugin.json "name": "Widget Box Deluxe", "title": "Widget Box"
        $dir = "{$this->active}/WidgetBox";
        mkdir($dir, 0777, true);
        file_put_contents("$dir/Plugin.php", "<?php\n");
        file_put_contents("$dir/plugin.json", json_encode([
            'name'    => 'Widget Box Deluxe',
            'title'   => 'Widget Box',
            'version' => '1.2.3',
        ]));

        $list = $this->manager->listInstalled();
        $byName = [];
        foreach ($list as $p) { $byName[$p['name']] = $p; }

        $this->assertArrayHasKey('WidgetBox', $byName, 'name key must be the folder name');
        $this->assertSame('WidgetBox', $byName['WidgetBox']['name'], 'name must equal folder name');
        $this->assertSame('Widget Box', $byName['WidgetBox']['title'], 'title must come from plugin.json title field');
    }

    public function testDisableRefusesWhenNotInstalled()
    {
        $this->expectException(ModMenuException::class);
        $this->manager->disable('Ghost');
    }

    public function testHasUpdate()
    {
        $this->assertTrue(PluginManager::hasUpdate('1.0.0', '1.0.1'));
        $this->assertFalse(PluginManager::hasUpdate('2.0.0', '2.0.0'));
        $this->assertFalse(PluginManager::hasUpdate('2.0.1', '2.0.0'));
    }

    public function testInstallFromUrlRejectsPlainHttp()
    {
        // Security: plain http:// must be rejected before any network call is made.
        $this->expectException(ModMenuException::class);
        $this->manager->installFromUrl('http://example.com/plugin.zip');
    }

    public function testInstalledMapShape()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->disabled, 'Beta', '2.0.0');
        $map = $this->manager->installedMap();
        $this->assertSame('1.0.0', $map['Alpha']['version']);
        $this->assertSame('active', $map['Alpha']['status']);
        $this->assertSame('disabled', $map['Beta']['status']);
    }

    /**
     * Regression: enable/disable move() falls back to a recursive copy when
     * rename() fails across filesystems (EXDEV — the norm in Docker, where
     * plugins/ and data/ are separate mounts). Exercise copyTree() directly;
     * it is the fallback that made cross-filesystem disable/enable work live.
     */
    public function testCopyTreeCopiesNestedTree()
    {
        $src = $this->root . '/ct-src';
        $dst = $this->root . '/ct-dst';
        mkdir($src . '/sub', 0777, true);
        file_put_contents($src . '/Plugin.php', "<?php // top\n");
        file_put_contents($src . '/sub/quote.php', 'hello harmozi');

        $method = new \ReflectionMethod(PluginManager::class, 'copyTree');
        $method->setAccessible(true);
        $ok = $method->invoke($this->manager, $src, $dst);

        $this->assertTrue($ok, 'copyTree should return true on success');
        $this->assertFileExists($dst . '/Plugin.php');
        $this->assertSame('hello harmozi', file_get_contents($dst . '/sub/quote.php'));
    }

    // Extended seeder: pass optional requires/recommends/conflicts arrays.
    private function seedPluginWithDeps(string $dir, string $name, string $version, array $requires = [], array $recommends = [], array $conflicts = []): void
    {
        mkdir("$dir/$name", 0777, true);
        file_put_contents("$dir/$name/Plugin.php", "<?php\n");
        $json = ['name' => $name, 'version' => $version];
        if ($requires !== [])   { $json['requires'] = $requires; }
        if ($recommends !== []) { $json['recommends'] = $recommends; }
        if ($conflicts !== [])  { $json['conflicts'] = $conflicts; }
        file_put_contents("$dir/$name/plugin.json", json_encode($json));
    }

    public function testReadMetaDefaultsDepsToEmpty()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0'); // existing seeder, no deps
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        $this->assertSame([], $byName['Alpha']['requires']);
        $this->assertSame([], $byName['Alpha']['recommends']);
    }

    public function testReadMetaParsesDeps()
    {
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0',
            [['plugin' => 'Cal', 'min_version' => '1.1.0']],
            [['plugin' => 'Cal', 'min_version' => '1.1.0', 'reason' => 'badges']]);
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        $this->assertSame('Cal', $byName['Dep']['requires'][0]['plugin']);
        $this->assertSame('1.1.0', $byName['Dep']['requires'][0]['min_version']);
        $this->assertSame('badges', $byName['Dep']['recommends'][0]['reason']);
    }

    public function testReadMetaIgnoresMalformedDeps()
    {
        // requires is a string, and an element lacks 'plugin' → both dropped, not fatal.
        $this->seedPluginWithDeps($this->active, 'Bad', '1.0.0');
        $dir = "{$this->active}/Bad";
        file_put_contents("$dir/plugin.json", json_encode([
            'name' => 'Bad', 'version' => '1.0.0',
            'requires' => 'oops', 'recommends' => [['no_plugin' => 'x']],
        ]));
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        $this->assertSame([], $byName['Bad']['requires']);
        $this->assertSame([], $byName['Bad']['recommends']);
    }

    public function testInstalledPluginsDepsShape()
    {
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0', [['plugin' => 'Cal']]);
        $this->seedPlugin($this->disabled, 'Cal', '1.1.0');
        $deps = $this->manager->installedPluginsDeps();
        $this->assertSame('active', $deps['Dep']['status']);
        $this->assertSame('Cal', $deps['Dep']['requires'][0]['plugin']);
        $this->assertSame('disabled', $deps['Cal']['status']);
    }

    public function testUnmetDepsForReturnsOnlyUnsatisfied()
    {
        // Cal active & new enough → satisfied requires drop out; missing recommend stays.
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $catalog = [];
        $unmet = $this->manager->unmetDepsFor(
            [['plugin' => 'Cal', 'min_version' => '1.1.0']],           // satisfied requires
            [['plugin' => 'Extra', 'reason' => 'nice to have']],       // missing recommend
            $catalog
        );
        $this->assertCount(1, $unmet);
        $this->assertSame('Extra', $unmet[0]['plugin']);
        $this->assertSame('recommends', $unmet[0]['kind']);
    }

    public function testRequirementStatesClassifiesEveryRequireIncludingSatisfied()
    {
        $this->seedPlugin($this->active, 'Met', '1.2.0');
        $this->seedPlugin($this->active, 'Old', '0.9.0');
        $this->seedPlugin($this->disabled, 'Off', '1.0.0');
        $this->seedPlugin($this->active, 'Any', '0.1.0');

        $states = $this->manager->requirementStates([
            ['plugin' => 'Met', 'min_version' => '1.0.0'],
            ['plugin' => 'Old', 'min_version' => '1.0.0'],
            ['plugin' => 'Gone', 'min_version' => '1.0.0'],
            ['plugin' => 'Off'],
            ['plugin' => 'Any'],
        ], []);

        $this->assertSame(['Met', 'Old', 'Gone', 'Off', 'Any'], array_column($states, 'plugin'));
        $this->assertSame(['satisfied', 'outdated', 'missing', 'disabled', 'satisfied'], array_column($states, 'status'));
        $this->assertSame('1.2.0', $states[0]['installed_version']);
        $this->assertSame('1.0.0', $states[0]['min_version']);
        $this->assertSame('0.9.0', $states[1]['installed_version']);
        $this->assertNull($states[2]['installed_version']);
        $this->assertNull($states[4]['min_version'], 'no min_version stays null');
        $this->assertSame(['requires'], array_values(array_unique(array_column($states, 'kind'))));
    }

    public function testInstalledSuccessorMeetsRequirementsAndRecommendations()
    {
        $this->seedPlugin($this->active, 'Harbor', '2.0.0', ['replaces' => ['ShadcnTheme']]);
        $this->seedPlugin($this->disabled, 'ShadcnTheme', '1.3.0');
        $deps = [['plugin' => 'ShadcnTheme', 'min_version' => '9.0.0']];
        $map = $this->manager->installedMap();
        $this->assertSame(['ShadcnTheme'], $map['Harbor']['replaces']);
        $this->assertSame([], $this->manager->unmetDepsFor($deps, $deps, [], $map));
    }

    public function testRequirementTemplateNamesTheSuccessor()
    {
        $html = $this->container['template']->render('ModMenu:settings/requirement', [
            'dep' => ['plugin' => 'ShadcnTheme', 'status' => 'satisfied', 'satisfied_by' => 'Harbor'],
            'actions' => false,
            'missing_label' => 'Missing requirement:',
        ]);
        $this->assertStringContainsString('Met by Harbor', $html);
    }

    public function testCannotDisableSuccessorWithAnActiveHardDependent()
    {
        $this->seedPlugin($this->active, 'Harbor', '2.0.0', ['replaces' => ['ShadcnTheme']]);
        $this->seedPluginWithDeps($this->active, 'Consumer', '1.0.0', [['plugin' => 'ShadcnTheme']]);
        $this->expectException(ModMenuException::class);
        $this->expectExceptionMessage('is required by: Consumer');
        $this->manager->disable('Harbor');
    }

    public function testRequirementStatesAndUnmetDepsUseAGivenInstalledMapInsteadOfScanning()
    {
        // Nothing on disk: the passed map is the only source of install state.
        $map = ['Ghost' => ['version' => '1.0.0', 'status' => 'active']];
        $states = $this->manager->requirementStates([['plugin' => 'Ghost', 'min_version' => '1.0.0']], [], $map);
        $this->assertSame('satisfied', $states[0]['status']);
        $this->assertSame([], $this->manager->unmetDepsFor([['plugin' => 'Ghost']], [['plugin' => 'Ghost']], [], $map));
    }

    public function testNormalizeDepsDropsNonScalarFields()
    {
        $this->assertSame(
            [['plugin' => 'Dep', 'min_version' => null, 'reason' => '']],
            PluginManager::normalizeDeps([
                ['plugin' => 'Dep', 'min_version' => ['1.0.0'], 'reason' => ['x']],
                ['plugin' => ['Arr']],
                'junk',
            ])
        );
    }

    public function testNormalizeReplacesKeepsOnlyNonEmptyStrings()
    {
        $this->assertSame(['ShadcnTheme'], PluginManager::normalizeReplaces([5, '', ['Old'], 'ShadcnTheme']));
        $this->assertSame([], PluginManager::normalizeReplaces('ShadcnTheme'));
    }

    public function testNormalizeDeprecatedRequiresANonEmptyStringSuccessor()
    {
        foreach ([false, [], ['superseded_by' => 5], ['superseded_by' => '']] as $invalid) {
            $this->assertNull(PluginManager::normalizeDeprecated($invalid));
        }

        $expected = ['superseded_by' => 'Harbor', 'since' => '', 'reason' => ''];
        $this->assertSame($expected, PluginManager::normalizeDeprecated(['superseded_by' => 'Harbor']));
        $this->assertSame($expected, PluginManager::normalizeDeprecated([
            'superseded_by' => 'Harbor',
            'since' => ['1.3.0'],
            'reason' => 5,
        ]));
    }

    public function testRequirementStatesIsEmptyWithoutRequires()
    {
        $this->assertSame([], $this->manager->requirementStates([], []));
    }

    public function testDisableBlockedByActiveDependent()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0', [['plugin' => 'Cal', 'min_version' => '1.1.0']]);
        $this->expectException(ModMenuException::class);
        $this->manager->disable('Cal');
    }

    public function testUninstallBlockedByActiveDependent()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->active, 'Dep', '1.0.0', [['plugin' => 'Cal']]);
        $this->expectException(ModMenuException::class);
        $this->manager->uninstall('Cal');
    }

    public function testDisableAllowedWhenDependentDisabled()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->disabled, 'Dep', '1.0.0', [['plugin' => 'Cal']]);
        $this->manager->disable('Cal'); // no throw
        $this->assertDirectoryExists("{$this->disabled}/Cal");
    }

    public function testForwardCheckSatisfied()
    {
        $this->seedPlugin($this->active, 'Cal', '1.1.0');
        $check = $this->manager->forwardCheck([['plugin' => 'Cal', 'min_version' => '1.1.0']], []);
        $this->assertTrue($check['satisfied']);
        $this->assertSame([], $check['plan']);
        $this->assertFalse($check['blocked']);
    }

    public function testForwardCheckNeedsConfirm()
    {
        $catalog = ['Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip']];
        $check = $this->manager->forwardCheck([['plugin' => 'Cal', 'min_version' => '1.1.0']], $catalog);
        $this->assertFalse($check['satisfied']);
        $this->assertFalse($check['blocked']);
        $this->assertSame('Cal', $check['plan'][0]['plugin']);
        $this->assertSame('install', $check['plan'][0]['action']);
    }

    public function testForwardCheckBlockedWhenUnresolvable()
    {
        $check = $this->manager->forwardCheck([['plugin' => 'Ghost']], []); // missing, no catalog
        $this->assertFalse($check['satisfied']);
        $this->assertTrue($check['blocked']);
    }

    public function testResolveAndActivateEnablesDepThenTarget()
    {
        // Cal is disabled; Dep is disabled and requires Cal. Enabling Dep must enable Cal first.
        $this->seedPlugin($this->disabled, 'Cal', '1.1.0');
        $this->seedPluginWithDeps($this->disabled, 'Dep', '1.0.0', [['plugin' => 'Cal', 'min_version' => '1.1.0']]);
        $plan = [['plugin' => 'Cal', 'action' => 'enable', 'download' => null, 'min_version' => '1.1.0']];
        $this->manager->resolveAndActivate('Dep', 'enable', '', $plan);
        $this->assertDirectoryExists("{$this->active}/Cal");
        $this->assertDirectoryExists("{$this->active}/Dep");
    }

    public function testResolveAndActivateThrowsOnUnresolvableStepBeforeActing()
    {
        $this->seedPlugin($this->disabled, 'Dep', '1.0.0');
        $plan = [['plugin' => 'Ghost', 'action' => 'unresolvable', 'download' => null, 'min_version' => null]];
        try {
            $this->manager->resolveAndActivate('Dep', 'enable', '', $plan);
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            // Target must NOT have been enabled.
            $this->assertDirectoryExists("{$this->disabled}/Dep");
            $this->assertDirectoryDoesNotExist("{$this->active}/Dep");
        }
    }

    public function testResolveAndActivateAbortsBeforeExecutingAnyResolvableStep()
    {
        // A resolvable 'enable' step precedes an 'unresolvable' one: validate-all-first
        // means NEITHER runs — proves no partial activation when abort is not step-0.
        $this->seedPlugin($this->disabled, 'A', '1.0.0');
        $this->seedPlugin($this->disabled, 'Dep', '1.0.0');
        $plan = [
            ['plugin' => 'A', 'action' => 'enable', 'download' => null, 'min_version' => null],
            ['plugin' => 'Ghost', 'action' => 'unresolvable', 'download' => null, 'min_version' => null],
        ];
        try {
            $this->manager->resolveAndActivate('Dep', 'enable', '', $plan);
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            $this->assertDirectoryExists("{$this->disabled}/A", 'resolvable dep A must NOT have been enabled');
            $this->assertDirectoryDoesNotExist("{$this->active}/A");
            $this->assertDirectoryExists("{$this->disabled}/Dep", 'target must NOT have been enabled');
            $this->assertDirectoryDoesNotExist("{$this->active}/Dep");
        }
    }

    // ── conflicts (the model never blocks; the controller asks) ──────────────────────────────────────

    private function installedByName(): array
    {
        $byName = [];
        foreach ($this->manager->listInstalled() as $p) { $byName[$p['name']] = $p; }
        return $byName;
    }

    public function testReadMetaParsesConflictsDefaultEmpty()
    {
        $this->seedPluginWithDeps($this->active, 'Alpha', '1.0.0', [], [], ['Beta']);
        $this->seedPlugin($this->active, 'Gamma', '1.0.0');
        $byName = $this->installedByName();
        $this->assertSame(['Beta'], $byName['Alpha']['conflicts']);
        $this->assertSame([], $byName['Gamma']['conflicts']);
    }

    public function testReadMetaIgnoresMalformedConflicts()
    {
        $this->seedPlugin($this->active, 'Bad', '1.0.0');
        file_put_contents("{$this->active}/Bad/plugin.json", json_encode([
            'name' => 'Bad', 'version' => '1.0.0', 'conflicts' => ['Beta', ['x'], 3, ''],
        ]));
        $this->assertSame(['Beta'], $this->installedByName()['Bad']['conflicts']);

        file_put_contents("{$this->active}/Bad/plugin.json", json_encode([
            'name' => 'Bad', 'version' => '1.0.0', 'conflicts' => 'Beta',
        ]));
        $this->assertSame([], $this->installedByName()['Bad']['conflicts']);
    }

    public function testActiveConflictsIsSymmetric()
    {
        $this->seedPluginWithDeps($this->active, 'Alpha', '1.0.0', [], [], ['Beta']);
        $this->seedPlugin($this->active, 'Beta', '1.0.0');
        $this->seedPlugin($this->active, 'Gamma', '1.0.0');
        $this->assertSame(['Beta'], $this->manager->activeConflicts('Alpha'));
        $this->assertSame(['Alpha'], $this->manager->activeConflicts('Beta'));
        $this->assertSame([], $this->manager->activeConflicts('Gamma'));
    }

    public function testActiveConflictsIgnoresDisabledPlugins()
    {
        $this->seedPluginWithDeps($this->active, 'Alpha', '1.0.0', [], [], ['Beta']);
        $this->seedPlugin($this->disabled, 'Beta', '1.0.0');
        $this->assertSame([], $this->manager->activeConflicts('Alpha'));
        // A disabled plugin still learns which active plugins it would clash with.
        $this->assertSame(['Alpha'], $this->manager->activeConflicts('Beta'));
    }

    public function testActiveConflictsTreatsReplacementsSymmetrically()
    {
        $this->seedPlugin($this->active, 'ShadcnTheme', '1.3.0');
        $this->seedPlugin($this->active, 'Harbor', '2.0.0', ['replaces' => ['ShadcnTheme']]);

        $this->assertSame(['ShadcnTheme'], $this->manager->activeConflicts('Harbor'));
        $this->assertSame(['Harbor'], $this->manager->activeConflicts('ShadcnTheme'));
    }

    public function testActiveConflictsForUninstalledName()
    {
        // Browse: an uninstalled directory entry an active plugin declares a conflict with.
        $this->seedPluginWithDeps($this->active, 'Alpha', '1.0.0', [], [], ['Beta']);
        $this->assertSame(['Alpha'], $this->manager->activeConflicts('Beta'));
    }

    public function testConflictWarning()
    {
        $this->seedPluginWithDeps($this->active, 'Alpha', '1.0.0', [], [], ['Beta', 'Delta']);
        $this->seedPlugin($this->active, 'Beta', '1.0.0');
        $this->seedPlugin($this->active, 'Delta', '1.0.0');
        $this->seedPlugin($this->active, 'Gamma', '1.0.0');
        $this->assertSame('Conflicts with Beta, Delta: disable one.', $this->manager->conflictWarning('Alpha'));
        $this->assertSame('', $this->manager->conflictWarning('Gamma'));
    }

    public function testConflictsNeverBlockEnable()
    {
        $this->seedPluginWithDeps($this->active, 'Alpha', '1.0.0', [], [], ['Beta']);
        $this->seedPlugin($this->disabled, 'Beta', '1.0.0');
        $this->manager->enable('Beta'); // must not throw
        $this->assertDirectoryExists("{$this->active}/Beta");
        $this->assertSame('Conflicts with Alpha: disable one.', $this->manager->conflictWarning('Beta'));
    }

    public function testConflictsNeverBlockForwardCheck()
    {
        $this->seedPluginWithDeps($this->disabled, 'Beta', '1.0.0', [], [], ['Alpha']);
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $check = $this->manager->forwardCheck($this->manager->installedPluginsDeps()['Beta']['requires'], []);
        $this->assertTrue($check['satisfied']);
        $this->assertFalse($check['blocked']);
    }
    // ── staged, verified installs (PluginSwap) ─────────────────────────────

    private function makeZip(string $name, string $version, ?string $pluginPhp = null): string
    {
        $zipPath = $this->root . '/' . $name . '-' . $version . '-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString("$name/Plugin.php", $pluginPhp ?? "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
        $zip->addFromString("$name/plugin.json", json_encode(['name' => $name, 'version' => $version]));
        $zip->close();
        return $zipPath;
    }

    private function seedNamespaced(string $name, string $version): void
    {
        mkdir("{$this->active}/$name", 0777, true);
        file_put_contents("{$this->active}/$name/Plugin.php", "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
        file_put_contents("{$this->active}/$name/plugin.json", json_encode(['name' => $name, 'version' => $version]));
    }

    private function dotDirs(string $prefix): array
    {
        return array_values(array_filter(scandir($this->active), static fn ($f) => str_starts_with($f, $prefix)));
    }

    public function testNewerModMenuZipInstallsAndKeepsPreviousCopy()
    {
        $this->seedNamespaced('ModMenu', '1.2.1');
        $this->assertFalse($this->manager->isSelfUpdatePending());

        $this->assertSame('ModMenu', $this->manager->installFromFile($this->makeZip('ModMenu', '1.3.0')));

        $this->assertStringContainsString('1.3.0', file_get_contents("{$this->active}/ModMenu/plugin.json"));
        $this->assertDirectoryExists("{$this->active}/.modmenu-previous-ModMenu");
        $this->assertStringContainsString('1.2.1', file_get_contents("{$this->active}/.modmenu-previous-ModMenu/plugin.json"));
        $this->assertTrue($this->manager->isSelfUpdatePending());
        $this->assertSame([], $this->dotDirs('.modmenu-staging-'));
    }

    public function testSameVersionModMenuZipIsRefusedAndInstalledCopyUntouched()
    {
        $this->seedNamespaced('ModMenu', '1.2.1');
        $before = file_get_contents("{$this->active}/ModMenu/Plugin.php");
        try {
            $this->manager->installFromFile($this->makeZip('ModMenu', '1.2.1', "<?php\nnamespace Kanboard\\Plugin\\ModMenu;\n// new\n"));
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            $this->assertStringContainsString('not newer', $e->getMessage());
        }
        $this->assertSame($before, file_get_contents("{$this->active}/ModMenu/Plugin.php"));
        $this->assertDirectoryDoesNotExist("{$this->active}/.modmenu-previous-ModMenu");
        $this->assertFalse($this->manager->isSelfUpdatePending());
        $this->assertSame([], $this->dotDirs('.modmenu-staging-'));
    }

    public function testUpdatingNormalPluginLeavesNoPreviousOrStagingDir()
    {
        $this->seedNamespaced('Alpha', '1.0.0');
        $this->assertSame('Alpha', $this->manager->installFromFile($this->makeZip('Alpha', '1.1.0')));
        $this->assertStringContainsString('1.1.0', file_get_contents("{$this->active}/Alpha/plugin.json"));
        $this->assertSame([], $this->dotDirs('.modmenu-'));
    }

    public function testFreshInstallOfNormalPluginLeavesNoDotDirs()
    {
        $this->assertSame('Alpha', $this->manager->installFromFile($this->makeZip('Alpha', '1.0.0')));
        $this->assertFileExists("{$this->active}/Alpha/Plugin.php");
        $this->assertSame([], $this->dotDirs('.modmenu-'));
    }

    public function testNormalPluginZipWithSyntaxErrorIsRefusedAndOldVersionStaysIntact()
    {
        $this->seedNamespaced('Alpha', '1.0.0');
        $before = file_get_contents("{$this->active}/Alpha/Plugin.php");
        try {
            $this->manager->installFromFile($this->makeZip('Alpha', '1.1.0', "<?php\nnamespace Kanboard\\Plugin\\Alpha;\nfunction (\n"));
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            $this->assertStringContainsString('syntax error', $e->getMessage());
        }
        $this->assertSame($before, file_get_contents("{$this->active}/Alpha/Plugin.php"));
        $this->assertStringContainsString('1.0.0', file_get_contents("{$this->active}/Alpha/plugin.json"));
        $this->assertSame([], $this->dotDirs('.modmenu-'));
    }

    // ── update all ─────────────────────────────────────────────────────────

    /** Give each successful download its own disposable copy of the fixture. */
    private function stubDownloads(array $urlToZipPath): void
    {
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->method('download')->willReturnCallback(function (string $url) use ($urlToZipPath): string {
            if (empty($urlToZipPath[$url])) {
                throw new ModMenuException('Unable to download the plugin archive.');
            }
            $path = tempnam($this->root, 'download-');
            copy($urlToZipPath[$url], $path);
            return $path;
        });
        $this->manager->setArchiveDownloader($downloader);
    }

    public function testMigrateInstallsExpectedArchiveBeforeDisablingOldAndEnablingSuccessor()
    {
        $this->seedPlugin($this->active, 'ShadcnTheme', '1.3.0', ['deprecated' => ['superseded_by' => 'Harbor']]);
        $url = 'https://x/harbor.zip';
        $zip = $this->makeZip('Harbor', '2.0.0');
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->expects($this->once())->method('download')->with($url)->willReturnCallback(function () use ($zip) {
            $this->assertFileExists($this->active . '/ShadcnTheme/Plugin.php');
            $this->assertDirectoryDoesNotExist($this->active . '/Harbor');
            return $zip;
        });
        $manager = $this->getMockBuilder(PluginManager::class)->setConstructorArgs([$this->container])
            ->onlyMethods(['disable', 'enable', 'uninstall'])->getMock();
        $manager->setDirectories($this->active, $this->disabled)->setArchiveDownloader($downloader);
        $manager->expects($this->never())->method('uninstall');
        $manager->expects($this->once())->method('disable')->with('ShadcnTheme', 'Harbor')->willReturnCallback(function ($name, $replacement) {
            $this->assertFileExists($this->disabled . '/Harbor/Plugin.php');
            $this->assertDirectoryDoesNotExist($this->active . '/Harbor');
            $this->manager->disable($name, $replacement);
        });
        $manager->expects($this->once())->method('enable')->with('Harbor')->willReturnCallback(function ($name) {
            $this->assertFileExists($this->disabled . '/ShadcnTheme/Plugin.php');
            $this->assertDirectoryDoesNotExist($this->active . '/ShadcnTheme');
            $this->manager->enable($name);
        });

        $this->assertSame('Harbor', $manager->migrate('ShadcnTheme', ['Harbor' => ['download' => $url]]));
        $this->assertFileExists($this->active . '/Harbor/Plugin.php');
        $this->assertFileExists($this->disabled . '/ShadcnTheme/Plugin.php');
    }

    public function testMigrateKeepsActiveDependentsWorkingThroughTheSuccessorAlias()
    {
        $this->seedPlugin($this->active, 'ShadcnTheme', '1.3.0', ['deprecated' => ['superseded_by' => 'Harbor']]);
        $this->seedPlugin($this->disabled, 'Harbor', '2.0.0');
        $this->seedPlugin($this->active, 'ThemeConsumer', '1.0.0', [
            'requires' => [['plugin' => 'ShadcnTheme', 'min_version' => '1.3.0']],
        ]);

        $this->assertSame('Harbor', $this->manager->migrate('ShadcnTheme', []));
        $installed = $this->manager->installedMap();
        $this->assertSame('active', $installed['ThemeConsumer']['status']);
        $this->assertSame('active', $installed['Harbor']['status']);
        $this->assertSame('disabled', $installed['ShadcnTheme']['status']);
    }

    public function testMigrateRejectsAnArchiveWhoseFolderIsNotTheSuccessor()
    {
        $this->seedPlugin($this->active, 'ShadcnTheme', '1.3.0', ['deprecated' => ['superseded_by' => 'Harbor']]);
        $url = 'https://x/harbor.zip';
        $this->stubDownloads([$url => $this->makeZip('Other', '2.0.0')]);

        try {
            $this->manager->migrate('ShadcnTheme', ['Harbor' => ['download' => $url]]);
            $this->fail('Expected install to fail.');
        } catch (ModMenuException $e) {
            $this->assertSame("Couldn't install Harbor, so ShadcnTheme stays active.", $e->getMessage());
            $this->assertSame(['ShadcnTheme'], array_keys($this->manager->installedMap()));
            $this->assertFileExists($this->active . '/ShadcnTheme/Plugin.php');
        }
    }

    public function testUpdatesAvailableListsActiveOutdatedPluginsWithModMenuLast()
    {
        $this->seedPlugin($this->active, 'ModMenu', '1.0.0');
        $this->seedPlugin($this->active, 'Zeta', '1.0.0');
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->active, 'Current', '2.0.0');
        $this->seedPlugin($this->disabled, 'Parked', '1.0.0');
        $catalog = [
            'ModMenu' => ['version' => '1.1.0', 'download' => 'https://x/mm.zip'],
            'Zeta'    => ['version' => '1.2.0', 'download' => 'https://x/zeta.zip'],
            'Alpha'   => ['version' => '1.0.1', 'download' => 'https://x/alpha.zip'],
            'Current' => ['version' => '2.0.0', 'download' => 'https://x/cur.zip'],
            'Parked'  => ['version' => '9.0.0', 'download' => 'https://x/parked.zip'],
            'Missing' => ['version' => '1.0.0', 'download' => 'https://x/missing.zip'],
        ];

        $updates = $this->manager->updatesAvailable($catalog);

        $this->assertSame(['Alpha', 'Zeta', 'ModMenu'], array_column($updates, 'plugin'), 'active + outdated only; ModMenu last so it swaps itself after the rest');
        $this->assertSame(['plugin' => 'Alpha', 'from' => '1.0.0', 'to' => '1.0.1', 'download' => 'https://x/alpha.zip'], $updates[0]);
    }

    public function testUpdateAllInstallsEachUpdateAndKeepsGoingPastAFailure()
    {
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->active, 'Beta', '1.0.0');
        $this->seedPlugin($this->active, 'Gamma', '1.0.0');
        $this->stubDownloads([
            'https://x/alpha.zip' => $this->makeZip('Alpha', '1.1.0'),
            'https://x/beta.zip'  => '',
            'https://x/gamma.zip' => $this->makeZip('Gamma', '1.1.0'),
        ]);
        $catalog = [
            'Alpha' => ['version' => '1.1.0', 'download' => 'https://x/alpha.zip'],
            'Beta'  => ['version' => '1.1.0', 'download' => 'https://x/beta.zip'],
            'Gamma' => ['version' => '1.1.0', 'download' => 'https://x/gamma.zip'],
        ];

        $result = $this->manager->updateAll($catalog);

        $this->assertSame(['Alpha', 'Gamma'], $result['updated']);
        $this->assertSame(['Beta'], array_keys($result['failed']));
        $versions = array_column($this->manager->listInstalled(), 'version', 'name');
        $this->assertSame(['Alpha' => '1.1.0', 'Beta' => '1.0.0', 'Gamma' => '1.1.0'], $versions);
    }

    // ── conflict gate helpers ──────────────────────────────────────────────

    public function testActiveConflictsAlsoHonoursCatalogDeclaredConflicts()
    {
        // Installing "New" from Browse: the conflict is declared only in the catalog entry.
        $this->seedPlugin($this->active, 'Alpha', '1.0.0');
        $this->seedPlugin($this->disabled, 'Beta', '1.0.0');
        $this->assertSame(['Alpha'], $this->manager->activeConflicts('New', ['Alpha', 'Beta']));
        $this->assertSame([], $this->manager->activeConflicts('New'));
    }

    public function testActiveConflictsIncludesCatalogReplacementsForUninstalledPlugin()
    {
        $this->seedPlugin($this->active, 'BattleLobby', '1.0.0');
        $this->seedPlugin($this->active, 'ShadcnTheme', '1.3.0');
        $this->seedPlugin($this->disabled, 'OldTheme', '1.0.0');

        $this->assertSame(
            ['BattleLobby', 'ShadcnTheme'],
            $this->manager->activeConflicts('Harbor', ['BattleLobby'], ['ShadcnTheme', 'OldTheme'])
        );
    }

    public function testDisableConflictsParksEveryActiveConflict()
    {
        $this->seedPluginWithDeps($this->disabled, 'Theme', '1.0.0', [], [], ['OtherTheme', 'ThirdTheme']);
        $this->seedPlugin($this->active, 'OtherTheme', '1.0.0');
        $this->seedPlugin($this->active, 'ThirdTheme', '1.0.0');
        $this->seedPlugin($this->active, 'Bystander', '1.0.0');

        $this->assertSame(['OtherTheme', 'ThirdTheme'], $this->manager->disableConflicts('Theme'));

        $this->assertSame([], $this->manager->activeConflicts('Theme'));
        $this->assertDirectoryExists("{$this->disabled}/OtherTheme");
        $this->assertDirectoryExists("{$this->disabled}/ThirdTheme");
        $this->assertDirectoryExists("{$this->active}/Bystander");
    }

    // ── concurrent installs ───────────────────────────────────────────────

    private function openHarborInstallLock()
    {
        $lock = fopen($this->root . '/.modmenu-install-' . hash('sha256', 'Harbor') . '.lock', 'c');
        $this->assertIsResource($lock);
        return $lock;
    }

    public function testDisabledInstallRefusesToProceedWhilePluginLockIsHeld()
    {
        $url = 'https://x/harbor.zip';
        $this->stubDownloads([$url => $this->makeZip('Harbor', '2.0.0')]);
        $lock = $this->openHarborInstallLock();

        try {
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try {
                $this->manager->installFromUrl($url, 'Harbor', true);
                $this->fail('Expected the competing install to be refused.');
            } catch (ModMenuException $e) {
                $this->assertSame('"Harbor" is already installed.', html_entity_decode($e->getMessage(), ENT_QUOTES));
            }
            $this->assertDirectoryDoesNotExist($this->disabled . '/Harbor');
        } finally {
            fclose($lock);
        }

        $this->assertSame('Harbor', $this->manager->installFromUrl($url, 'Harbor', true));
        $this->assertFileExists($this->disabled . '/Harbor/Plugin.php');
    }

    /** @dataProvider destinationsOccupiedDuringStaging */
    public function testInstallRechecksDestinationsAfterStaging(bool $installDisabled, string $occupied, string $message)
    {
        $url = 'https://x/harbor.zip';
        $zip = $this->makeZip('Harbor', '2.0.0');
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->method('download')->willReturn($zip);
        $existing = $occupied === 'active' ? $this->active : $this->disabled;
        $other = $occupied === 'active' ? $this->disabled : $this->active;
        $afterStaging = function () use ($existing): void {
            $lock = $this->openHarborInstallLock();
            try {
                $this->assertFalse(flock($lock, LOCK_EX | LOCK_NB, $wouldBlock));
                $this->assertSame(1, $wouldBlock);
                $this->seedPlugin($existing, 'Harbor', '1.0.0');
            } finally {
                fclose($lock);
            }
        };
        $manager = new class ($this->container, $afterStaging) extends PluginManager {
            public function __construct(\Pimple\Container $container, private \Closure $afterStaging)
            {
                parent::__construct($container);
            }

            protected function stageArchive(PluginArchive $archive, string $zipPath, string $staging): void
            {
                parent::stageArchive($archive, $zipPath, $staging);
                ($this->afterStaging)();
            }
        };
        $manager->setDirectories($this->active, $this->disabled)->setArchiveDownloader($downloader);

        try {
            $manager->installFromUrl($url, 'Harbor', $installDisabled);
            $this->fail('Expected the newly occupied destination to block the install.');
        } catch (ModMenuException $e) {
            $this->assertSame($message, html_entity_decode($e->getMessage(), ENT_QUOTES));
        }
        $this->assertDirectoryDoesNotExist($other . '/Harbor');
        $this->assertStringContainsString('1.0.0', file_get_contents($existing . '/Harbor/plugin.json'));
    }

    public static function destinationsOccupiedDuringStaging(): array
    {
        $disabledMessage = '"Harbor" is already installed but disabled. Enable it instead.';
        return [
            'active appears during a disabled install' => [true, 'active', '"Harbor" is already installed.'],
            'disabled appears during a disabled install' => [true, 'disabled', $disabledMessage],
            'disabled appears during an active install' => [false, 'disabled', $disabledMessage],
        ];
    }

    // ── catalog name must match the archive ────────────────────────────────

    public function testInstallFromUrlRefusesArchiveForAnotherPlugin()
    {
        $this->seedNamespaced('Beta', '1.0.0');
        $this->stubDownloads(['https://x/alpha.zip' => $this->makeZip('Beta', '9.0.0')]);
        try {
            $this->manager->installFromUrl('https://x/alpha.zip', 'Alpha');
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            $this->assertStringContainsString('Alpha', $e->getMessage());
        }
        $this->assertStringContainsString('1.0.0', file_get_contents("{$this->active}/Beta/plugin.json"));
        $this->assertDirectoryDoesNotExist("{$this->active}/Alpha");
        $this->assertSame([], $this->dotDirs('.modmenu-'));
    }

    public function testInstallFromUrlAcceptsMatchingNameAndRemovesDownloadedFile()
    {
        $client = $this->createMock(\Kanboard\Core\Http\Client::class);
        $client->expects($this->never())->method('get');
        $this->container['httpClient'] = $client;
        $url = 'https://x/alpha.zip';
        $path = $this->makeZip('Alpha', '1.0.0');
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->expects($this->once())->method('download')->with($url)->willReturn($path);
        $this->manager->setArchiveDownloader($downloader);

        $this->assertSame('Alpha', $this->manager->installFromUrl($url, 'Alpha'));
        $this->assertFileDoesNotExist($path);
    }

    public function testDownloaderFailureLeavesNothingInstalled()
    {
        $client = $this->createMock(\Kanboard\Core\Http\Client::class);
        $client->expects($this->never())->method('get');
        $this->container['httpClient'] = $client;
        $url = 'https://x/alpha.zip';
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->expects($this->once())->method('download')->with($url)
            ->willThrowException(new ModMenuException('The plugin archive is larger than 50 MB.'));
        $this->manager->setArchiveDownloader($downloader);

        try {
            $this->manager->installFromUrl($url, 'Alpha');
            $this->fail('Expected the downloader error.');
        } catch (ModMenuException $e) {
            $this->assertSame('The plugin archive is larger than 50 MB.', $e->getMessage());
        }
        $this->assertSame([], $this->manager->listInstalled());
    }

    public function testUpdateAllRefusesArchiveWhoseFolderDiffersFromCatalogName()
    {
        $this->seedNamespaced('Alpha', '1.0.0');
        $this->seedNamespaced('Beta', '1.0.0');
        $this->stubDownloads(['https://x/alpha.zip' => $this->makeZip('Beta', '9.0.0')]);

        $result = $this->manager->updateAll(['Alpha' => ['version' => '1.1.0', 'download' => 'https://x/alpha.zip']]);

        $this->assertSame([], $result['updated']);
        $this->assertArrayHasKey('Alpha', $result['failed']);
        $this->assertStringContainsString('1.0.0', file_get_contents("{$this->active}/Beta/plugin.json"));
    }

    public function testResolveAndActivatePassesExpectedNames()
    {
        $this->seedNamespaced('Beta', '1.0.0');
        $this->stubDownloads(['https://x/dep.zip' => $this->makeZip('Beta', '9.0.0')]);
        try {
            $this->manager->resolveAndActivate('Target', 'install', 'https://x/t.zip', [
                ['plugin' => 'Dep', 'action' => 'install', 'download' => 'https://x/dep.zip'],
            ]);
            $this->fail('expected ModMenuException');
        } catch (ModMenuException $e) {
            $this->assertStringContainsString('Dep', $e->getMessage());
        }
        $this->assertStringContainsString('1.0.0', file_get_contents("{$this->active}/Beta/plugin.json"));
    }
}
