<?php

namespace Kanboard\Plugin\ModMenu;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\ModMenu\Model\PluginSwap;

/**
 * ModMenu — a standalone Kanboard plugin manager.
 *
 * Browse/install from directory sources, upload a zip, enable/disable via
 * folder move, detect updates, and uninstall — all admin-only.
 *
 * @author  Carmelo Santana
 * @license MIT
 */
class Plugin extends Base
{
    /**
     * Load guard for self-updates: if this (new) copy fails to register, put
     * the previous copy back so the next request loads it; once it has
     * registered, drop the previous copy. Kanboard's loader only catches
     * Exception, so nothing but a \RuntimeException may leave this method:
     * an \Error would take the whole site down.
     */
    public function initialize()
    {
        try {
            $this->register();
        } catch (\Throwable $e) {
            try {
                // Only roll back the copy that failed: a concurrent request may already have.
                $restored = (new PluginSwap($this->pluginsDir()))->rollback('ModMenu', $this->getPluginVersion());
            } catch (\Throwable $rollbackError) {
                $this->logger->critical('ModMenu: new version failed to load (' . $e->getMessage() . ') and the rollback failed: ' . $rollbackError->getMessage());
                throw new \RuntimeException('ModMenu failed to load: ' . $e->getMessage(), 0, $e);
            }
            if ($restored) {
                $this->logger->critical('ModMenu: new version failed to load, previous version restored: ' . $e->getMessage());
                return;
            }
            throw new \RuntimeException('ModMenu failed to load: ' . $e->getMessage(), 0, $e);
        }

        (new PluginSwap($this->pluginsDir()))->finalize('ModMenu', $this->getPluginVersion());
    }

    protected function pluginsDir(): string
    {
        return PLUGINS_DIR;
    }

    protected function register(): void
    {
        $this->hook->on('template:config:sidebar', ['template' => 'ModMenu:config/sidebar']);

        $this->hook->on('template:layout:css', ['template' => 'plugins/ModMenu/Assets/css/modmenu.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/ModMenu/Assets/js/modmenu.js']);

        $this->route->addRoute('config/modmenu', 'ModMenuController', 'show', 'ModMenu');
        $this->route->addRoute('config/modmenu/directory', 'ModMenuController', 'directory', 'ModMenu');
        $this->route->addRoute('config/modmenu/sources', 'ModMenuController', 'sources', 'ModMenu');
        $this->route->addRoute('config/modmenu/source/add', 'ModMenuController', 'addSource', 'ModMenu');
        $this->route->addRoute('config/modmenu/source/remove', 'ModMenuController', 'removeSource', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/confirm', 'ModMenuController', 'confirm', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/resolve', 'ModMenuController', 'resolve', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/enable', 'ModMenuController', 'enable', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/disable', 'ModMenuController', 'disable', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/uninstall', 'ModMenuController', 'uninstall', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/install', 'ModMenuController', 'install', 'ModMenu');
        $this->route->addRoute('config/modmenu/plugin/update', 'ModMenuController', 'update', 'ModMenu');
        $this->route->addRoute('config/modmenu/upload', 'UploadController', 'upload', 'ModMenu');
    }

    public function getPluginName(): string
    {
        return 'ModMenu';
    }

    public function getPluginDescription(): string
    {
        return 'A standalone plugin manager: browse, install, upload, enable/disable, update, and uninstall Kanboard plugins.';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.3.2';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-modmenu';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }
}
