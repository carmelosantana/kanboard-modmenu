<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * Required-plugin and conflict notices render as callouts (icon + bold label),
 * screenshots as linked 16:10 thumbnails.
 */
class CalloutTemplateTest extends Base
{
    private function installed(array $plugin): string
    {
        return $this->container['template']->render('ModMenu:settings/installed', [
            'tab' => 'installed', 'is_configured' => true, 'not_configured_reason' => '', 'self_name' => 'ModMenu',
            'self_update_pending' => false, 'plugin_installer_enabled' => false,
            'plugins' => [$plugin + ['title' => 'X', 'version' => '1.0.0', 'author' => '', 'description' => '', 'status' => 'active', 'unmet_deps' => [], 'active_conflicts' => []]],
        ]);
    }

    private function browse(array $plugin): string
    {
        return $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [], 'is_configured' => true, 'updates' => [],
            'plugins' => [$plugin + ['version' => '1.0.0', 'status' => 'available']],
        ]);
    }

    /** The callout element wrapping $needle: its class attribute and inner HTML. */
    private function calloutAround(string $html, string $modifier): string
    {
        $this->assertMatchesRegularExpression('/class="[^"]*modmenu-callout[^"]*' . $modifier . '/', $html);
        preg_match('/<div class="[^"]*modmenu-callout[^"]*' . $modifier . '[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m);
        return $m[1] ?? '';
    }

    public function testInstalledMissingRequirementIsACallout()
    {
        $html = $this->installed(['name' => 'Needy', 'unmet_deps' => [
            ['kind' => 'requires', 'plugin' => 'Dep', 'min_version' => '1.0.0', 'reason' => '', 'status' => 'missing'],
        ]]);
        $inner = $this->calloutAround($html, 'modmenu-dep--required');
        $this->assertStringContainsString('<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>', $inner);
        $this->assertMatchesRegularExpression('/<strong class="modmenu-callout__label">\s*Missing requirement:\s*<\/strong>/', $inner);
        $this->assertStringContainsString('Dep', $inner);
    }

    public function testInstalledRecommendationIsNotACallout()
    {
        $html = $this->installed(['name' => 'Needy', 'unmet_deps' => [
            ['kind' => 'recommends', 'plugin' => 'Extra', 'min_version' => '', 'reason' => '', 'status' => 'missing'],
        ]]);
        $this->assertStringContainsString('modmenu-dep--recommended', $html);
        $this->assertStringNotContainsString('modmenu-callout', $html);
    }

    public function testInstalledConflictIsAnAmberCallout()
    {
        $html = $this->installed(['name' => 'BattleLobby', 'active_conflicts' => ['ShadcnTheme']]);
        $inner = $this->calloutAround($html, 'modmenu-dep--conflict');
        $this->assertStringContainsString('fa-exclamation-triangle', $inner);
        $this->assertStringContainsString('modmenu-callout__label', $inner);
    }

    public function testBrowseRequiresIsACallout()
    {
        $html = $this->browse(['name' => 'Addon', 'requires' => [['plugin' => 'Dep', 'min_version' => '1.0.0']]]);
        $inner = $this->calloutAround($html, 'modmenu-dep--required');
        $this->assertStringContainsString('fa-exclamation-triangle', $inner);
        $this->assertMatchesRegularExpression('/<strong class="modmenu-callout__label">\s*Requires:\s*<\/strong>/', $inner);
    }

    public function testBrowseConflictIsACallout()
    {
        $html = $this->browse(['name' => 'Addon', 'conflicts' => ['Other']]);
        $this->calloutAround($html, 'modmenu-dep--conflict');
    }

    public function testBrowseScreenshotsAreLinkedThumbnails()
    {
        $html = $this->browse(['name' => 'Addon', 'screenshots' => ['https://x/a/1.webp']]);
        $this->assertMatchesRegularExpression(
            '#<a class="modmenu-shot-link" href="https://x/a/1.webp" target="_blank" rel="noopener">\s*<img class="modmenu-shot" src="https://x/a/1.webp"#',
            $html
        );
        $this->assertStringNotContainsString('onclick', $html);
    }
}
