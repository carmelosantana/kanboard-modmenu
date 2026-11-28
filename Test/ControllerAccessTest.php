<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Controller\ModMenuController;
use Kanboard\Core\Controller\AccessForbiddenException;

class ControllerAccessTest extends Base
{
    private function nonAdminController(): ModMenuController
    {
        // No user session => userSession->isAdmin() is false.
        return new ModMenuController($this->container);
    }

    public function testShowForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->show();
    }

    public function testDirectoryForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->directory();
    }

    public function testSourcesForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->sources();
    }

    public function testUploadForbiddenForNonAdmin()
    {
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        (new \Kanboard\Plugin\ModMenu\Controller\UploadController($this->container))->upload();
    }

    public function testMigrateForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->migrate();
    }

    public function testRunMigrateForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->runMigrate();
    }

    public function testResolveForbiddenForNonAdmin()
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->resolve();
    }

    /** @dataProvider privateActions */
    #[\PHPUnit\Framework\Attributes\DataProvider('privateActions')]
    public function testPrivateSourceActionsForbiddenForNonAdmin(string $action)
    {
        $this->expectException(AccessForbiddenException::class);
        $this->nonAdminController()->$action();
    }

    public static function privateActions(): array
    {
        $actions = ['addPrivateSource', 'removePrivateSource', 'adopt', 'unbind', 'dismiss', 'resetPrivateState'];
        return array_combine($actions, array_map(static fn ($a) => [$a], $actions));
    }
}
