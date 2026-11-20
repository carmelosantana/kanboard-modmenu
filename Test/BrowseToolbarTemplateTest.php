<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * Browse toolbar: search, category and sort controls rendered hidden (the JS
 * reveals them), plus the data-* attributes, category badge and updated date
 * on each card that the client-side filter and sort read.
 */
class BrowseToolbarTemplateTest extends Base
{
    private function browse(array $plugins, array $counts = []): string
    {
        return $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [], 'is_configured' => true, 'updates' => [],
            'category_counts' => $counts,
            'plugins' => array_map(fn ($p) => $p + ['version' => '1.0.0', 'status' => 'available', 'category' => 'Other', 'added' => '', 'last_updated' => ''], $plugins),
        ]);
    }

    private function sample(): string
    {
        return $this->browse([
            ['name' => 'Pulse', 'title' => 'Pulse', 'author' => 'Carmelo Santana', 'category' => 'Reporting',
                'description' => 'Activity heatmap', 'added' => '2026-11-18', 'last_updated' => '2026-11-19'],
            ['name' => 'Fun1', 'title' => 'Fun one', 'category' => 'Fun'],
            ['name' => 'Fun2', 'category' => 'Fun'],
        ], ['Fun' => 2, 'Reporting' => 1]);
    }

    private function toolbar(string $html): string
    {
        $this->assertMatchesRegularExpression('#<div class="modmenu-toolbar"[^>]*>#', $html);
        preg_match('#<div class="modmenu-toolbar"[^>]*>.*?<!-- /modmenu-toolbar -->#s', $html, $m);
        $this->assertNotEmpty($m, 'toolbar block found');
        return $m[0];
    }

    public function testToolbarRendersHiddenSoNoJsShowsNoDeadControls()
    {
        $this->assertMatchesRegularExpression('#<div class="modmenu-toolbar"[^>]*\shidden[\s>]#', $this->sample());
    }

    public function testToolbarSitsBelowTheUpdatesPanelAndAboveTheCards()
    {
        $html = $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [], 'is_configured' => true, 'category_counts' => ['Other' => 1],
            'updates' => [['plugin' => 'A', 'from' => '1.0.0', 'to' => '1.1.0']],
            'plugins' => [['name' => 'A', 'version' => '1.1.0', 'status' => 'update', 'category' => 'Other', 'added' => '', 'last_updated' => '']],
        ]);
        $updates = strpos($html, 'modmenu-updates');
        $toolbar = strpos($html, 'modmenu-toolbar');
        $card = strpos($html, 'class="modmenu-card"');
        $this->assertTrue($updates < $toolbar && $toolbar < $card);
    }

    public function testSearchIsALabelledSearchInput()
    {
        $bar = $this->toolbar($this->sample());
        $this->assertMatchesRegularExpression('#<label for="modmenu-search"[^>]*>\s*Search plugins\s*</label>#', $bar);
        $this->assertMatchesRegularExpression('#<input type="search" id="modmenu-search" class="modmenu-toolbar__search"[^>]*placeholder="Search plugins"#', $bar);
    }

    public function testCategorySelectListsAllThenEachCategoryWithCounts()
    {
        $bar = $this->toolbar($this->sample());
        preg_match('#<select id="modmenu-category"[^>]*>(.*?)</select>#s', $bar, $m);
        $this->assertNotEmpty($m);
        preg_match_all('#<option value="([^"]*)"[^>]*>\s*(.*?)\s*</option>#s', $m[1], $opts, PREG_SET_ORDER);
        $this->assertSame(
            [['all', 'All categories (3)'], ['Fun', 'Fun (2)'], ['Reporting', 'Reporting (1)']],
            array_map(fn ($o) => [$o[1], $o[2]], $opts)
        );
        $this->assertMatchesRegularExpression('#<label for="modmenu-category"#', $bar);
    }

    public function testSortSelectDefaultsToLastUpdated()
    {
        $bar = $this->toolbar($this->sample());
        preg_match('#<select id="modmenu-sort"[^>]*>(.*?)</select>#s', $bar, $m);
        $this->assertNotEmpty($m);
        preg_match_all('#<option value="([^"]*)"([^>]*)>\s*(.*?)\s*</option>#s', $m[1], $opts, PREG_SET_ORDER);
        $this->assertSame(
            [['updated', 'Last updated'], ['added', 'First submitted'], ['author', 'Author']],
            array_map(fn ($o) => [$o[1], $o[3]], $opts)
        );
        $this->assertStringContainsString('selected', $opts[0][2]);
        $this->assertMatchesRegularExpression('#<label for="modmenu-sort"#', $bar);
    }

    public function testLiveCountAndHiddenEmptyState()
    {
        $html = $this->sample();
        $this->assertMatchesRegularExpression('#<p class="modmenu-toolbar__count" aria-live="polite" data-format="Showing \{shown\} of \{total\}">\s*Showing 3 of 3\s*</p>#', $html);
        $this->assertMatchesRegularExpression('#<p class="modmenu-empty"[^>]*\shidden[\s>][^<]*No plugins match\.#', $html);
    }

    public function testCardsCarryFilterAndSortAttributes()
    {
        $html = $this->sample();
        $this->assertMatchesRegularExpression(
            '#<div class="modmenu-card" data-title="Pulse" data-search="pulse pulse activity heatmap carmelo santana reporting" data-category="Reporting" data-author="Carmelo Santana" data-added="2026-11-18" data-updated="2026-11-19">#',
            $html
        );
        // Title falls back to name; missing fields render empty.
        $this->assertStringContainsString('data-title="Fun2" data-search="fun2 fun2 fun" data-category="Fun" data-author="" data-added="" data-updated=""', $html);
    }

    public function testDataAttributesAreEscaped()
    {
        $html = $this->browse([[
            'name' => 'X', 'title' => 'A "quoted" <b>title</b>', 'author' => 'O\'Brien & Co',
            'category' => '"><script>', 'description' => 'D',
        ]], ['"><script>' => 1]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>title', $html);
        $this->assertStringContainsString('data-title="A &quot;quoted&quot; &lt;b&gt;title&lt;/b&gt;"', $html);
        $this->assertStringContainsString('data-author="O&#039;Brien &amp; Co"', $html);
        $this->assertStringContainsString('data-category="&quot;&gt;&lt;script&gt;"', $html);
        $this->assertStringContainsString('<option value="&quot;&gt;&lt;script&gt;">', $html);
    }

    public function testCardShowsACategoryBadge()
    {
        $this->assertMatchesRegularExpression('#<span class="modmenu-category">\s*Reporting\s*</span>#', $this->sample());
    }

    public function testStatusLineShowsTheUpdatedDateOnlyWhenKnown()
    {
        $html = $this->sample();
        $this->assertSame(1, substr_count($html, 'Updated 2026-11-19'));
        $this->assertSame(1, substr_count($html, 'Updated '));
    }

    public function testNoToolbarWithoutPlugins()
    {
        $html = $this->browse([]);
        $this->assertStringNotContainsString('modmenu-toolbar', $html);
        $this->assertStringContainsString('No plugins available', $html);
    }

    public function testTemplateCarriesNoInlineScript()
    {
        $html = $this->sample();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $html);
    }
}
