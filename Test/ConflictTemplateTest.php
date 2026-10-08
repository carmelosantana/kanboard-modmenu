<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * Conflicts render as non-blocking hints on the Installed and Browse tabs.
 */
class ConflictTemplateTest extends Base
{
    public function testInstalledTabShowsActiveConflict()
    {
        $html = $this->container['template']->render('ModMenu:settings/installed', [
            'tab' => 'installed', 'is_configured' => true, 'not_configured_reason' => '', 'self_name' => 'ModMenu',
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
}
