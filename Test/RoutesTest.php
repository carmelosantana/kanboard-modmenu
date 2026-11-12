<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\ModMenu\Plugin;
use KanboardTests\units\Base;

/** With URL rewriting on, every ModMenu address must reach a ModMenu controller. */
class RoutesTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();
    }

    public function routes(): array
    {
        return [
            ['config/modmenu', 'ModMenuController', 'show'],
            ['config/modmenu/directory', 'ModMenuController', 'directory'],
            ['config/modmenu/sources', 'ModMenuController', 'sources'],
            ['config/modmenu/source/add', 'ModMenuController', 'addSource'],
            ['config/modmenu/source/remove', 'ModMenuController', 'removeSource'],
            ['config/modmenu/plugin/confirm', 'ModMenuController', 'confirm'],
            ['config/modmenu/plugin/resolve', 'ModMenuController', 'resolve'],
            ['config/modmenu/plugin/enable', 'ModMenuController', 'enable'],
            ['config/modmenu/plugin/disable', 'ModMenuController', 'disable'],
            ['config/modmenu/plugin/uninstall', 'ModMenuController', 'uninstall'],
            ['config/modmenu/plugin/install', 'ModMenuController', 'install'],
            ['config/modmenu/plugin/update', 'ModMenuController', 'update'],
            ['config/modmenu/upload', 'UploadController', 'upload'],
        ];
    }

    /** @dataProvider routes */
    public function testPathResolvesToAPluginControllerThatExists(string $path, string $controller, string $action): void
    {
        $route = $this->container['route']->findRoute($path);

        $this->assertSame(['controller' => $controller, 'action' => $action, 'plugin' => 'ModMenu'], $route);
        $this->assertTrue(method_exists('\\Kanboard\\Plugin\\ModMenu\\Controller\\' . $controller, $action));
    }

    /** @dataProvider routes */
    public function testLinksUseTheCleanAddress(string $path, string $controller, string $action): void
    {
        $url = $this->container['helper']->url->to($controller, $action, ['plugin' => 'ModMenu']);

        $this->assertStringEndsWith($path, $url);
    }
}
