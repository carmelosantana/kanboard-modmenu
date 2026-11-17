<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * Conflicts render as callouts on the Installed and Browse tabs, and as the
 * confirm page that enable/install shows first.
 */
class ConflictTemplateTest extends Base
{
    public function testInstalledTabShowsActiveConflict()
    {
        $html = $this->container['template']->render('ModMenu:settings/installed', [
            'tab' => 'installed', 'is_configured' => true, 'not_configured_reason' => '', 'self_name' => 'ModMenu',
            'self_update_pending' => false, 'plugin_installer_enabled' => false,
            'plugins' => [[
                'name' => 'BattleLobby', 'title' => 'Battle Lobby', 'version' => '1.0.0', 'author' => '', 'description' => '',
                'status' => 'active', 'unmet_deps' => [], 'active_conflicts' => ['ShadcnTheme'],
            ]],
        ]);
        $this->assertStringContainsString('Conflicts with ShadcnTheme: disable one.', $html);
        $this->assertStringContainsString('modmenu-dep--conflict', $html);
    }

    public function testInstalledTabWithoutConflictShowsNoHint()
    {
        $html = $this->container['template']->render('ModMenu:settings/installed', [
            'tab' => 'installed', 'is_configured' => true, 'not_configured_reason' => '', 'self_name' => 'ModMenu',
            'self_update_pending' => false, 'plugin_installer_enabled' => false,
            'plugins' => [[
                'name' => 'Plain', 'title' => 'Plain', 'version' => '1.0.0', 'author' => '', 'description' => '',
                'status' => 'active', 'unmet_deps' => [], 'active_conflicts' => [],
            ]],
        ]);
        $this->assertStringNotContainsString('modmenu-dep--conflict', $html);
    }

    public function testBrowseEntryShowsConflicts()
    {
        $html = $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [], 'is_configured' => true,
            'plugins' => [[
                'name' => 'BattleLobby', 'version' => '1.0.0', 'status' => 'available',
                'conflicts' => ['ShadcnTheme'],
            ]],
        ]);
        $this->assertStringContainsString('Conflicts with ShadcnTheme: enable only one.', $html);
    }

    public function testBrowseTabShowsUpdateAllWithCountWhenUpdatesExist()
    {
        $html = $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [], 'is_configured' => true, 'plugins' => [],
            'updates' => [['plugin' => 'A', 'from' => '1.0.0', 'to' => '1.1.0', 'download' => 'x'], ['plugin' => 'B', 'from' => '1.0.0', 'to' => '2.0.0', 'download' => 'y']],
        ]);
        $this->assertStringContainsString('updateAll', $html);
        $this->assertStringContainsString('Update all (2)', $html);
    }

    public function testBrowseTabHidesUpdateAllWithoutUpdates()
    {
        $html = $this->container['template']->render('ModMenu:settings/directory', [
            'tab' => 'browse', 'errors' => [], 'is_configured' => true, 'plugins' => [], 'updates' => [],
        ]);
        $this->assertStringNotContainsString('Update all', $html);
    }

    // Kanboard's JS disables submit buttons on submit, which drops a button's own
    // name/value from the post: each decision must travel in a hidden input.
    public function testConflictPromptPostsTheDecisionAsAHiddenField()
    {
        $html = $this->container['template']->render('ModMenu:plugin/conflict', [
            'name' => 'BattleLobby', 'action' => 'enable', 'conflicts' => ['ShadcnTheme'],
        ]);
        $this->assertStringContainsString('BattleLobby conflicts with ShadcnTheme. Disable them and continue?', $html);
        $this->assertSame(2, substr_count($html, '<form method="post"'), 'one form per decision');
        $this->assertStringContainsString('<input type="hidden" name="decision" value="confirm">', $html);
        $this->assertStringContainsString('<input type="hidden" name="decision" value="cancel">', $html);
        $this->assertStringNotContainsString('<button type="submit" name="decision"', $html);
    }
}
