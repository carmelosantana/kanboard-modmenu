<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * Installed tab: ModMenu's own card (self-update hints) and the
 * PLUGIN_INSTALLER explanation.
 */
class InstalledTemplateTest extends Base
{
    private const SELF_LINE = 'This is ModMenu itself. It cannot be disabled or removed here. Update it from Browse.';
    private const PENDING_LINE = 'An update was just installed; the previous copy is removed on the next page load.';
    private const INSTALLER_LINE = 'Kanboard\'s built-in plugin installer is off (PLUGIN_INSTALLER = false, the default). ModMenu is a separate installer for administrators: it keeps working while the plugins folder is writable and the PHP zip extension is loaded.';

    private function render(bool $pending, bool $installerEnabled): string
    {
        return $this->container['template']->render('ModMenu:settings/installed', [
            'tab' => 'installed', 'is_configured' => true, 'not_configured_reason' => '', 'self_name' => 'ModMenu',
            'self_update_pending' => $pending,
            'plugin_installer_enabled' => $installerEnabled,
            'plugins' => [[
                'name' => 'ModMenu', 'title' => 'ModMenu', 'version' => '1.3.0', 'author' => '', 'description' => '',
                'status' => 'active', 'unmet_deps' => [], 'active_conflicts' => [],
            ]],
        ]);
    }

    private function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    public function testSelfCardPointsToBrowseForUpdates()
    {
        $html = $this->decoded($this->render(false, true));
        $this->assertStringContainsString(self::SELF_LINE, $html);
        $this->assertStringNotContainsString('This is ModMenu itself and cannot be disabled or removed here.', $html);
    }

    public function testPendingNoteShownOnlyWhileSelfUpdatePending()
    {
        $this->assertStringContainsString(self::PENDING_LINE, $this->decoded($this->render(true, true)));
        $this->assertStringNotContainsString(self::PENDING_LINE, $this->decoded($this->render(false, true)));
    }

    public function testInstallerNoteShownWhenPluginInstallerOff()
    {
        $html = $this->render(false, false);
        $this->assertStringContainsString(self::INSTALLER_LINE, $this->decoded($html));
        $this->assertStringContainsString('alert alert-info', $html);
    }

    public function testInstallerNoteHiddenWhenPluginInstallerOn()
    {
        $this->assertStringNotContainsString(self::INSTALLER_LINE, $this->decoded($this->render(false, true)));
    }
}
