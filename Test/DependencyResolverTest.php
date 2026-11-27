<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\DependencyResolver;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;

class DependencyResolverTest extends Base
{
    private $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyResolver($this->container);
    }

    private function dep(string $plugin, ?string $min = null): array
    {
        $d = ['plugin' => $plugin];
        if ($min !== null) { $d['min_version'] = $min; }
        return $d;
    }

    // ---- isSatisfied ----
    public function testIsSatisfiedTrueWhenActiveNoMin()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $this->assertTrue(DependencyResolver::isSatisfied($this->dep('Cal'), $map));
    }

    public function testIsSatisfiedFalseWhenMissing()
    {
        $this->assertFalse(DependencyResolver::isSatisfied($this->dep('Cal'), []));
    }

    public function testIsSatisfiedFalseWhenDisabled()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'disabled']];
        $this->assertFalse(DependencyResolver::isSatisfied($this->dep('Cal'), $map));
    }

    public function testIsSatisfiedRespectsMinVersion()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $this->assertFalse(DependencyResolver::isSatisfied($this->dep('Cal', '1.1.0'), $map));
        $this->assertTrue(DependencyResolver::isSatisfied($this->dep('Cal', '1.0.0'), $map));
    }

    public function testIsSatisfiedOnlyByActiveSuccessor()
    {
        $map = ['Harbor' => ['version' => '2.0.0', 'status' => 'active', 'replaces' => ['ShadcnTheme']]];
        $dep = $this->dep('ShadcnTheme', '1.0.0');
        $this->assertTrue(DependencyResolver::isSatisfied($dep, $map));
        $map['Harbor']['status'] = 'disabled';
        $this->assertFalse(DependencyResolver::isSatisfied($dep, $map));
    }

    public function testClassifyActiveSuccessorIgnoresOldVersionFloor()
    {
        $map = ['Harbor' => ['version' => '2.0.0', 'status' => 'active', 'replaces' => ['ShadcnTheme']]];
        $dep = $this->dep('ShadcnTheme', '9.0.0');
        $c = DependencyResolver::classify($dep, $map, []);
        $this->assertSame('satisfied', $c['status']);
        $this->assertSame('none', $c['action']);
        $this->assertSame('Harbor', $c['satisfied_by']);

        $map['Harbor']['status'] = 'disabled';
        $c = DependencyResolver::classify($dep, $map, []);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('enable', $c['action']);
        $this->assertSame('Harbor', $c['plugin']);
    }

    public function testClassifyUsesSuccessorDownloadFromEitherCatalogDeclaration()
    {
        $harbor = ['version' => '2.0.0', 'download' => 'https://x/harbor.zip'];
        $old = ['version' => '1.3.0', 'download' => 'https://x/shadcn.zip'];
        $catalogs = [
            ['ShadcnTheme' => $old, 'Harbor' => $harbor + ['replaces' => ['ShadcnTheme']]],
            ['ShadcnTheme' => $old + ['deprecated' => ['superseded_by' => 'Harbor']], 'Harbor' => $harbor],
        ];
        foreach ($catalogs as $catalog) {
            $c = DependencyResolver::classify($this->dep('ShadcnTheme', '9.0.0'), [], $catalog);
            $this->assertSame('missing', $c['status']);
            $this->assertSame('install', $c['action']);
            $this->assertSame('https://x/harbor.zip', $c['download']);
            $this->assertSame('Harbor', $c['satisfied_by']);
        }
    }

    public function testClassifyPrefersTheActiveRequestedPlugin()
    {
        $map = [
            'ShadcnTheme' => ['version' => '1.3.0', 'status' => 'active'],
            'Harbor' => ['version' => '2.0.0', 'status' => 'active', 'replaces' => ['ShadcnTheme']],
        ];
        $dep = $this->dep('ShadcnTheme', '1.0.0');
        $this->assertArrayNotHasKey('satisfied_by', DependencyResolver::classify($dep, $map, []));
        $map['ShadcnTheme']['status'] = 'disabled';
        $this->assertSame('Harbor', DependencyResolver::classify($dep, $map, [])['satisfied_by']);
    }

    public function testSuccessorForIgnoresSelfReferencesAndPrefersInstalledSuccessor()
    {
        $self = ['replaces' => ['X'], 'deprecated' => ['superseded_by' => 'X']];
        $this->assertNull(DependencyResolver::successorFor('X', ['X' => $self], ['X' => $self]));
        $this->assertSame('Installed', DependencyResolver::successorFor(
            'X',
            ['Installed' => ['replaces' => ['X'], 'status' => 'disabled']],
            ['X' => ['deprecated' => ['superseded_by' => 'Listed']], 'Listed' => ['replaces' => ['X']]]
        ));
    }

    public function testDirectoryAnnotationRecognizesInstalledSuccessorFromCatalog()
    {
        $plugins = [
            ['name' => 'Consumer', 'requires' => [$this->dep('ShadcnTheme')]],
            ['name' => 'ShadcnTheme', 'deprecated' => ['superseded_by' => 'Harbor']],
            ['name' => 'Harbor', 'version' => '2.0.0'],
        ];
        $map = ['Harbor' => ['version' => '2.0.0', 'status' => 'active']];
        $client = new DirectoryClient($this->container);
        $state = $client->annotate($plugins, 'https://x/plugins.json', $map)[0]['requires_state'][0];
        $this->assertSame('satisfied', $state['status']);
        $this->assertSame('Harbor', $state['satisfied_by']);
    }

    // ---- classify ----
    public function testClassifyMissingWithCatalogIsInstall()
    {
        $catalog = ['Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), [], $catalog);
        $this->assertSame('missing', $c['status']);
        $this->assertSame('install', $c['action']);
        $this->assertSame('https://x/cal.zip', $c['download']);
    }

    public function testClassifyMissingWithoutCatalogIsUnresolvable()
    {
        $c = DependencyResolver::classify($this->dep('Cal'), [], []);
        $this->assertSame('missing', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
        $this->assertNull($c['download']);
    }

    public function testClassifyDisabledIsEnable()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'disabled']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('enable', $c['action']);
    }

    public function testClassifyOutdatedWithNewerCatalogIsUpdate()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $catalog = ['Cal' => ['version' => '1.2.0', 'download' => 'https://x/cal.zip']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, $catalog);
        $this->assertSame('outdated', $c['status']);
        $this->assertSame('update', $c['action']);
        $this->assertSame('https://x/cal.zip', $c['download']);
    }

    public function testClassifyOutdatedWithoutCatalogIsUnresolvable()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('outdated', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
    }

    public function testClassifySatisfied()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('satisfied', $c['status']);
        $this->assertSame('none', $c['action']);
    }

    public function testClassifyDisabledButTooOldWithNewerCatalogIsUpdate()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'disabled']];
        $catalog = ['Cal' => ['version' => '1.2.0', 'download' => 'https://x/cal.zip']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, $catalog);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('update', $c['action']);
        $this->assertSame('https://x/cal.zip', $c['download']);
    }

    public function testClassifyDisabledButTooOldWithoutCatalogIsUnresolvable()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'disabled']];
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, []);
        $this->assertSame('disabled', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
        $this->assertNull($c['download']);
    }

    public function testClassifyOutdatedWhenCatalogVersionAlsoBelowMinIsUnresolvable()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $catalog = ['Cal' => ['version' => '1.0.5', 'download' => 'https://x/cal.zip']]; // still < 1.1.0
        $c = DependencyResolver::classify($this->dep('Cal', '1.1.0'), $map, $catalog);
        $this->assertSame('outdated', $c['status']);
        $this->assertSame('unresolvable', $c['action']);
        $this->assertNull($c['download']);
    }

    // ---- resolveForward ----
    public function testResolveForwardSatisfiedFlagAndKind()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $out = $this->resolver->resolveForward([$this->dep('Cal', '1.1.0')], 'requires', $map, []);
        $this->assertTrue($out['satisfied']);
        $this->assertSame('requires', $out['deps'][0]['kind']);
    }

    public function testResolveForwardUnsatisfiedWhenAnyUnmet()
    {
        $map = ['Cal' => ['version' => '1.0.0', 'status' => 'active']];
        $out = $this->resolver->resolveForward([$this->dep('Cal', '1.1.0')], 'requires', $map, []);
        $this->assertFalse($out['satisfied']);
    }

    public function testResolveForwardIgnoresMalformedEntries()
    {
        $out = $this->resolver->resolveForward([['no_plugin' => 'x'], 'nonsense', []], 'requires', [], []);
        $this->assertTrue($out['satisfied']); // nothing valid to be unsatisfied
        $this->assertCount(0, $out['deps']);
    }

    // ---- resolveReverse ----
    public function testResolveReverseFindsActiveHardDependent()
    {
        $installedMap = [
            'Cal' => ['version' => '1.1.0', 'status' => 'active'],
            'Dep' => ['version' => '1.0.0', 'status' => 'active'],
        ];
        $depsByPlugin = [
            'Cal' => ['status' => 'active', 'requires' => []],
            'Dep' => ['status' => 'active', 'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']]],
        ];
        $blockers = $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap);
        $this->assertCount(1, $blockers);
        $this->assertSame('Dep', $blockers[0]['plugin']);
    }

    public function testResolveReverseIgnoresDisabledDependent()
    {
        $installedMap = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $depsByPlugin = [
            'Cal' => ['status' => 'active', 'requires' => []],
            'Dep' => ['status' => 'disabled', 'requires' => [['plugin' => 'Cal']]],
        ];
        $this->assertSame([], $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap));
    }

    public function testResolveReverseIgnoresRecommendsOnlyDependent()
    {
        // 'Sched' only recommends Cal (not in its requires) → not a blocker.
        $installedMap = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $depsByPlugin = [
            'Cal'   => ['status' => 'active', 'requires' => []],
            'Sched' => ['status' => 'active', 'requires' => []], // recommends live elsewhere; reverse only reads requires
        ];
        $this->assertSame([], $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap));
    }

    public function testResolveReverseIgnoresUnsatisfiedRequirement()
    {
        // Dep requires Cal >= 2.0.0 but Cal is 1.1.0 → requirement not currently met,
        // so removing Cal doesn't break an already-broken relationship.
        $installedMap = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $depsByPlugin = [
            'Dep' => ['status' => 'active', 'requires' => [['plugin' => 'Cal', 'min_version' => '2.0.0']]],
        ];
        $this->assertSame([], $this->resolver->resolveReverse('Cal', $depsByPlugin, $installedMap));
    }

    // ---- resolveClosure ----
    public function testResolveClosureSingleMissing()
    {
        $catalog = ['Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip']];
        $plan = $this->resolver->resolveClosure([$this->dep('Cal', '1.1.0')], [], $catalog);
        $this->assertCount(1, $plan);
        $this->assertSame('Cal', $plan[0]['plugin']);
        $this->assertSame('install', $plan[0]['action']);
    }

    public function testResolveClosureUsesTheSuccessorsOwnNameAndRequirements()
    {
        $catalog = [
            'Harbor' => [
                'version' => '2.0.0', 'download' => 'https://x/harbor.zip',
                'replaces' => ['ShadcnTheme'], 'requires' => [$this->dep('Cal')],
            ],
            'Cal' => ['version' => '1.0.0', 'download' => 'https://x/cal.zip'],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('ShadcnTheme', '9.0.0'), $this->dep('Harbor')], [], $catalog);
        $this->assertSame(['Cal', 'Harbor'], array_column($plan, 'plugin'));
        $this->assertSame('install', $plan[1]['action']);
        $this->assertNull($plan[1]['min_version']);
    }

    public function testResolveClosureStillEnforcesAHarborVersionFloor()
    {
        $catalog = ['Harbor' => [
            'version' => '2.0.0', 'download' => 'https://x/harbor.zip', 'replaces' => ['ShadcnTheme'],
        ]];
        $map = ['Harbor' => ['version' => '2.0.0', 'status' => 'disabled', 'replaces' => ['ShadcnTheme']]];
        $plan = $this->resolver->resolveClosure([$this->dep('ShadcnTheme'), $this->dep('Harbor', '9.0.0')], $map, $catalog);
        $this->assertSame(['Harbor'], array_column($plan, 'plugin'));
        $this->assertSame('unresolvable', $plan[0]['action']);
    }

    public function testResolveClosureOmitsSatisfied()
    {
        $map = ['Cal' => ['version' => '1.1.0', 'status' => 'active']];
        $plan = $this->resolver->resolveClosure([$this->dep('Cal', '1.1.0')], $map, []);
        $this->assertSame([], $plan);
    }

    public function testResolveClosureIsDepsFirstAndDeduped()
    {
        // Dep(missing) requires Cal(missing). Plan must list Cal before Dep, once each.
        $catalog = [
            'Dep' => ['version' => '1.0.0', 'download' => 'https://x/dep.zip', 'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']]],
            'Cal' => ['version' => '1.1.0', 'download' => 'https://x/cal.zip'],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('Dep')], [], $catalog);
        $order = array_column($plan, 'plugin');
        $this->assertSame(['Cal', 'Dep'], $order);
    }

    public function testResolveClosureMarksUnresolvable()
    {
        // Missing and not in the catalog → a plan step the caller must block on.
        $plan = $this->resolver->resolveClosure([$this->dep('Ghost')], [], []);
        $this->assertCount(1, $plan);
        $this->assertSame('unresolvable', $plan[0]['action']);
    }

    public function testResolveClosureDiamondDedupesSharedDep()
    {
        // Root requires Dep1 and Dep2; both require Cal. Cal must appear exactly once, before both.
        $catalog = [
            'Dep1' => ['version' => '1.0.0', 'download' => 'https://x/dep1.zip', 'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']]],
            'Dep2' => ['version' => '1.0.0', 'download' => 'https://x/dep2.zip', 'requires' => [['plugin' => 'Cal', 'min_version' => '1.1.0']]],
            'Cal'  => ['version' => '1.1.0', 'download' => 'https://x/cal.zip'],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('Dep1'), $this->dep('Dep2')], [], $catalog);
        $plugins = array_column($plan, 'plugin');
        $this->assertCount(1, array_keys($plugins, 'Cal'), 'Cal must be deduped to a single step');
        $this->assertLessThan(array_search('Dep1', $plugins), array_search('Cal', $plugins));
        $this->assertLessThan(array_search('Dep2', $plugins), array_search('Cal', $plugins));
    }

    public function testResolveClosureTerminatesOnCyclicCatalog()
    {
        // Cyclic catalog: A requires B, B requires A. Must terminate; each appears once.
        $catalog = [
            'A' => ['version' => '1.0.0', 'download' => 'https://x/a.zip', 'requires' => [['plugin' => 'B']]],
            'B' => ['version' => '1.0.0', 'download' => 'https://x/b.zip', 'requires' => [['plugin' => 'A']]],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('A')], [], $catalog);
        $plugins = array_column($plan, 'plugin');
        sort($plugins);
        $this->assertSame(['A', 'B'], $plugins, 'each node appears exactly once, no infinite recursion');
    }
    // ---- private source restriction ----
    private const SOURCE_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SOURCE_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function privateEntry(string $source, array $extra = []): array
    {
        return ['version' => '1.0.0', 'download' => 'https://x/p.zip', 'private' => true, 'source_id' => $source] + $extra;
    }

    public function testPrivateDependantResolvesOnlyFromItsOwnSource()
    {
        $catalog = [
            'Public' => ['version' => '1.0.0', 'download' => 'https://x/public.zip'],
            'Other' => $this->privateEntry(self::SOURCE_B),
            'Mine' => $this->privateEntry(self::SOURCE_A),
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('Public'), $this->dep('Other'), $this->dep('Mine')], [], $catalog, self::SOURCE_A);

        $this->assertSame([
            ['plugin' => 'Public', 'action' => 'unresolvable', 'download' => null, 'min_version' => null, 'reason' => 'Install it yourself first'],
            ['plugin' => 'Other', 'action' => 'unresolvable', 'download' => null, 'min_version' => null, 'reason' => 'Install it yourself first'],
            ['plugin' => 'Mine', 'action' => 'install', 'download' => 'https://x/p.zip', 'min_version' => null],
        ], $plan);

        $forward = $this->resolver->resolveForward([$this->dep('Public')], 'requires', [], $catalog, self::SOURCE_A);
        $this->assertSame('unresolvable', $forward['deps'][0]['action']);
        $this->assertNull($forward['deps'][0]['download']);
    }

    public function testInstalledPluginSatisfiesAPrivateDependant()
    {
        $map = ['Public' => ['version' => '2.0.0', 'status' => 'active']];
        $catalog = ['Public' => ['version' => '3.0.0', 'download' => 'https://x/public.zip']];

        $this->assertSame([], $this->resolver->resolveClosure([$this->dep('Public', '1.0.0')], $map, $catalog, self::SOURCE_A));
        $this->assertTrue($this->resolver->resolveForward([$this->dep('Public')], 'requires', $map, $catalog, self::SOURCE_A)['satisfied']);
    }

    public function testPrivateDependencyRestrictsItsOwnRequirementsToItsSource()
    {
        $catalog = [
            'Mine' => $this->privateEntry(self::SOURCE_A, ['requires' => [['plugin' => 'Helper']]]),
            'Helper' => ['version' => '1.0.0', 'download' => 'https://x/helper.zip'],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('Mine')], [], $catalog);

        $this->assertSame(['Helper', 'Mine'], array_column($plan, 'plugin'));
        $this->assertSame(['unresolvable', 'install'], array_column($plan, 'action'));
        $this->assertSame('Install it yourself first', $plan[0]['reason']);
    }
    public function testVerdictDoesNotDependOnRequirementOrder()
    {
        $catalog = [
            'Helper' => ['version' => '1.0.0', 'download' => 'https://x/helper.zip'],
            'Mine' => $this->privateEntry(self::SOURCE_A, ['requires' => [['plugin' => 'Helper']]]),
        ];
        $verdict = function (array $requires) use ($catalog): array {
            $out = [];
            foreach ($this->resolver->resolveClosure($requires, [], $catalog) as $step) {
                $out[$step['plugin']] = $step['action'];
            }
            ksort($out);
            return $out;
        };

        $expected = ['Helper' => 'unresolvable', 'Mine' => 'install'];
        $this->assertSame($expected, $verdict([$this->dep('Helper'), $this->dep('Mine')]));
        $this->assertSame($expected, $verdict([$this->dep('Mine'), $this->dep('Helper')]));
    }

    public function testPrivateEntryWithoutAValidSourceIdResolvesNothing()
    {
        $catalog = [
            'Mine' => ['version' => '1.0.0', 'download' => 'https://x/p.zip', 'private' => true, 'requires' => [['plugin' => 'Helper']]],
            'Helper' => ['version' => '1.0.0', 'download' => 'https://x/helper.zip'],
        ];
        $plan = $this->resolver->resolveClosure([$this->dep('Mine')], [], $catalog);

        $this->assertSame('unresolvable', $plan[0]['action']);
        $this->assertSame('Helper', $plan[0]['plugin']);
    }
}
