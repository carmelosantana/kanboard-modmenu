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

    /** A classified requirement as DependencyResolver::classify returns it. */
    private function dep(string $status, ?string $installed, ?string $min = '1.0.0', string $plugin = 'Dep'): array
    {
        return ['kind' => 'requires', 'plugin' => $plugin, 'status' => $status, 'action' => 'none',
            'installed_version' => $installed, 'min_version' => $min, 'reason' => '', 'download' => null];
    }

    private function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    /** Both tabs, keyed by tab name, rendering one requirement in the given state. */
    private function bothTabs(array $dep): array
    {
        return [
            'installed' => $this->installed(['name' => 'Needy', 'requires_state' => [$dep]]),
            'browse'    => $this->browse(['name' => 'Needy', 'requires_state' => [$dep]]),
        ];
    }

    public function testMetRequirementIsAGreenCheckNotRed()
    {
        foreach ($this->bothTabs($this->dep('satisfied', '1.2.0')) as $tab => $html) {
            $inner = $this->calloutAround($html, 'modmenu-dep--met');
            $this->assertStringContainsString('<i class="fa fa-check-circle" aria-hidden="true"></i>', $inner, $tab);
            $this->assertMatchesRegularExpression('/<strong class="modmenu-callout__label">\s*Requirement met:\s*<\/strong>/', $inner, $tab);
            $this->assertStringContainsString('Dep 1.2.0 (≥ 1.0.0)', preg_replace('/\s+/', ' ', $this->decoded(strip_tags($inner))), $tab);
            $this->assertStringNotContainsString('modmenu-dep--required', $html, $tab);
            $this->assertStringNotContainsString('fa-exclamation-triangle', $html, $tab);
            $this->assertStringNotContainsString('Install Dep', $html, $tab);
        }
    }

    public function testMetRequirementWithoutMinVersionShowsNoBound()
    {
        foreach ($this->bothTabs($this->dep('satisfied', '0.1.0', null)) as $tab => $html) {
            $text = preg_replace('/\s+/', ' ', $this->decoded(strip_tags($this->calloutAround($html, 'modmenu-dep--met'))));
            $this->assertStringContainsString('Dep 0.1.0', $text, $tab);
            $this->assertStringNotContainsString('≥', $text, $tab);
        }
    }

    public function testOutdatedRequirementIsAnAmberWarning()
    {
        foreach ($this->bothTabs($this->dep('outdated', '0.9.0')) as $tab => $html) {
            $inner = $this->calloutAround($html, 'modmenu-dep--outdated');
            $this->assertStringContainsString('<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>', $inner, $tab);
            $this->assertMatchesRegularExpression('/<strong class="modmenu-callout__label">\s*Update needed:\s*<\/strong>/', $inner, $tab);
            $this->assertStringContainsString('Dep — 0.9.0 installed, needs ≥ 1.0.0', preg_replace('/\s+/', ' ', $this->decoded(strip_tags($inner))), $tab);
            $this->assertStringNotContainsString('modmenu-dep--required', $html, $tab);
        }
    }

    public function testOutdatedRequirementOffersUpdateViaBrowseOnlyOnInstalled()
    {
        $tabs = $this->bothTabs($this->dep('outdated', '0.9.0'));
        $this->assertStringContainsString('Update via Browse', $tabs['installed']);
        $this->assertStringNotContainsString('Update via Browse', $tabs['browse']);
    }

    public function testMissingRequirementStaysRedWithInstallOnInstalled()
    {
        $tabs = $this->bothTabs($this->dep('missing', null));
        foreach ($tabs as $tab => $html) {
            $inner = $this->calloutAround($html, 'modmenu-dep--required');
            $this->assertStringContainsString('fa-exclamation-triangle', $inner, $tab);
            $this->assertStringNotContainsString('modmenu-dep--met', $html, $tab);
        }
        $this->assertStringContainsString('Install Dep', $tabs['installed']);
        $this->assertStringNotContainsString('Install Dep', $tabs['browse']);
    }

    public function testDisabledRequirementStaysRedAndSaysSo()
    {
        $tabs = $this->bothTabs($this->dep('disabled', '1.0.0'));
        foreach ($tabs as $tab => $html) {
            $inner = $this->calloutAround($html, 'modmenu-dep--required');
            $this->assertMatchesRegularExpression('/<strong class="modmenu-callout__label">\s*Requirement disabled:\s*<\/strong>/', $inner, $tab);
            $this->assertStringContainsString('Dep', $inner, $tab);
        }
        $this->assertStringContainsString('Enable Dep', $tabs['installed']);
        $this->assertStringNotContainsString('Enable Dep', $tabs['browse']);
    }

    public function testDisabledAndTooOldRequirementOffersUpdateNotEnable()
    {
        // Installed classifies with an empty catalog (action unresolvable); Browse-side resolution says update.
        foreach (['unresolvable', 'update'] as $action) {
            $dep = ['action' => $action] + $this->dep('disabled', '0.9.0');
            $tabs = $this->bothTabs($dep);
            foreach ($tabs as $tab => $html) {
                $text = preg_replace('/\s+/', ' ', $this->decoded(strip_tags($this->calloutAround($html, 'modmenu-dep--required'))));
                $this->assertStringContainsString('Requirement disabled: Dep (0.9.0 installed, needs ≥ 1.0.0)', $text, "$tab/$action");
            }
            $this->assertStringContainsString('Update via Browse', $tabs['installed'], $action);
            $this->assertStringNotContainsString('Enable Dep', $tabs['installed'], $action);
        }
    }

    public function testOutdatedLineEscapesVersions()
    {
        $html = $this->installed(['name' => 'Needy', 'requires_state' => [$this->dep('outdated', '0.9<b>', '1.0&x')]]);
        $this->assertStringContainsString('0.9&lt;b&gt; installed, needs ≥ 1.0&amp;x', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testEachRequirementGetsItsOwnLine()
    {
        $deps = [$this->dep('satisfied', '1.2.0', '1.0.0', 'A'), $this->dep('outdated', '0.1.0', '1.0.0', 'B'), $this->dep('missing', null, null, 'C')];
        foreach ([
            'installed' => $this->installed(['name' => 'Needy', 'requires_state' => $deps]),
            'browse'    => $this->browse(['name' => 'Needy', 'requires_state' => $deps]),
        ] as $tab => $html) {
            $this->assertSame(1, substr_count($html, 'modmenu-dep--met'), $tab);
            $this->assertSame(1, substr_count($html, 'modmenu-dep--outdated'), $tab);
            $this->assertSame(1, substr_count($html, 'modmenu-dep--required'), $tab);
        }
    }

    public function testInstalledRecommendationsStillOnlyShowWhenUnmet()
    {
        $html = $this->installed(['name' => 'Needy', 'requires_state' => [], 'unmet_deps' => []]);
        $this->assertStringNotContainsString('modmenu-dep--recommended', $html);
        $this->assertStringNotContainsString('modmenu-card__alerts', $html);
    }

    public function testInstalledMissingRequirementIsACallout()
    {
        $html = $this->installed(['name' => 'Needy', 'requires_state' => [$this->dep('missing', null)]]);
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

    public function testInstalledDeprecationCalloutHasAnIconAndVisibleLabel(): void
    {
        $html = $this->installed([
            'name' => 'ShadcnTheme',
            'deprecated' => ['superseded_by' => 'Harbor', 'since' => '1.3.0', 'reason' => ''],
        ]);
        $inner = $this->calloutAround($html, 'modmenu-dep--deprecated');
        $this->assertMatchesRegularExpression('/<i class="fa fa-[^"]+" aria-hidden="true"><\/i>/', $inner);
        $this->assertMatchesRegularExpression('/<strong class="modmenu-callout__label">\s*Replaced by Harbor\.\s*<\/strong>/', $inner);
    }

    public function testBrowseRequiresIsACallout()
    {
        $html = $this->browse(['name' => 'Addon', 'requires_state' => [$this->dep('missing', null)]]);
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
