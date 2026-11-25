<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;

class DirectoryClientTest extends Base
{
    private $client;

    public function setUp(): void
    {
        parent::setUp();
        $this->client = new DirectoryClient($this->container);
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
            ['url' => $invalid, 'message' => 'Syntax error'],
        ], $result['errors']);
        $this->assertSame(['Alpha'], array_column($result['plugins'], 'name'));
        $this->assertSame([$valid], array_column($result['plugins'], 'source_url'));
        $this->assertSame($before, glob(sys_get_temp_dir() . '/modmenu_dl*'));
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
        ], $this->client->fetchAll());
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
