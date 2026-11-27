<?php

namespace Kanboard\Plugin\ModMenu\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Model\PluginSwap;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;
use Kanboard\Plugin\ModMenu\Model\DependencyResolver;
use Kanboard\Plugin\ModMenu\Model\CatalogLock;
use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\MutationGuard;
use Kanboard\Plugin\ModMenu\Model\PluginName;
use Kanboard\Plugin\ModMenu\Model\PrivateFetcher;
use Kanboard\Plugin\ModMenu\Model\PrivateState;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Exception\PrivateStateDamaged;

/**
 * Admin-only plugin-manager UI. Thin: delegates all work to the models and
 * renders the four tabs (Installed / Browse / Upload / Sources).
 */
class ModMenuController extends BaseController
{
    private function requireAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    protected function manager(): PluginManager
    {
        return new PluginManager($this->container);
    }

    private function backToInstalled()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'show', ['plugin' => 'ModMenu']));
    }

    public function show()
    {
        $this->requireAdmin();
        $manager = $this->manager();

        // Classify each installed plugin's deps against install state only
        // (empty catalog → no network on the Installed tab; the Install/Enable
        // buttons re-resolve server-side). Every requirement shows its state
        // (met, outdated, missing, disabled); unmet recommends surface as soft hints.
        // One folder scan for the whole page, shared by every plugin's classification.
        $plugins = $manager->listInstalled();
        $installedMap = $manager->installedMap();
        foreach ($plugins as &$p) {
            $p['requires_state'] = $manager->requirementStates($p['requires'] ?? [], [], $installedMap);
            $p['unmet_deps'] = $manager->unmetDepsFor([], $p['recommends'] ?? [], [], $installedMap);
            $p['active_conflicts'] = $manager->activeConflicts($p['name']);
        }
        unset($p);

        $this->response->html($this->helper->layout->config('ModMenu:settings/installed', [
            'title' => t('ModMenu'),
            'tab' => 'installed',
            'plugins' => $plugins,
            'is_configured' => $manager->isConfigured(),
            'not_configured_reason' => $manager->notConfiguredReason(),
            'self_name' => PluginManager::SELF,
            'self_update_pending' => $manager->isSelfUpdatePending(),
            'plugin_installer_enabled' => (bool) PLUGIN_INSTALLER,
        ]));
    }

    public function directory()
    {
        $this->requireAdmin();
        $result = (new DirectoryClient($this->container))->fetchAll();

        // Show declared conflicts and replacements alongside active conflicts.
        // Install/enable asks before acting on active conflicts.
        $manager = $this->manager();
        foreach ($result['plugins'] as &$p) {
            $declared = $p['conflicts'] ?? [];
            $replaces = $p['replaces'] ?? [];
            $active = $manager->activeConflicts((string) ($p['name'] ?? ''), $declared, $replaces);
            $all = array_values(array_unique(array_merge($declared, $replaces, $active)));
            sort($all);
            $p['conflicts'] = $all;
        }
        unset($p);

        // The rendered order is the no-JS default; the toolbar JS re-sorts client-side.
        $plugins = DirectoryClient::sortPlugins($result['plugins'], 'updated');
        // Updates include final releases of deprecated plugins.
        $updates = $manager->updatesAvailable(DirectoryClient::indexByName($result['plugins']));

        $this->response->html($this->helper->layout->config('ModMenu:settings/directory', [
            'title' => t('ModMenu'),
            'tab' => 'browse',
            'plugins' => $plugins,
            'category_counts' => DirectoryClient::categoryCounts($plugins),
            'errors' => $result['errors'],
            'updates' => $updates,
            'is_configured' => $manager->isConfigured(),
            'frozen' => $result['frozen'],
            'private_ok' => $result['private_ok'],
        ]));
    }

    public function sources()
    {
        $this->requireAdmin();
        $repository = new SourceRepository($this->container);
        $state = $this->privateState();
        $private = ['private_sources' => [], 'removed_sources' => []];
        $frozen = $state->isDamaged();
        if (! $frozen) {
            try {
                // The state can be damaged between the check and this read.
                $private = $this->privateRows($state->read(), $repository);
            } catch (PrivateStateDamaged $e) {
                $frozen = true;
            }
        }

        $this->response->html($this->helper->layout->config('ModMenu:settings/sources', [
            'title' => t('ModMenu'),
            'tab' => 'sources',
            'sources' => $repository->getSources(),
            'default_source' => SourceRepository::DEFAULT_SOURCE,
            'frozen' => $frozen,
        ] + $private));
    }

    protected function privateState(): PrivateState
    {
        return new PrivateState($this->container);
    }

    /**
     * The private part of the sources page, from the local state only (no
     * Vault read): each source with its Vault status, bound plugins and
     * installed unbound plugins it lists; removed sources that still have
     * bindings.
     */
    private function privateRows(array $doc, SourceRepository $repository): array
    {
        $installed = $this->manager()->installedKeys();
        $boundTo = [];
        foreach ($doc['bindings'] as $key => $id) {
            $boundTo[$id][] = self::installedName((string) $key, $installed);
        }

        $sources = [];
        $candidates = [];
        foreach ($doc['sources'] as $id => $source) {
            $unbound = [];
            foreach ($doc['seen'][$id] ?? [] as $key) {
                if (isset($installed[$key]) && count($installed[$key]) === 1 && ! isset($doc['bindings'][$key])) {
                    $unbound[] = [
                        'name' => $installed[$key][0],
                        'key' => $key,
                        'dismissed' => in_array($key, $doc['dismissed'][$id] ?? [], true),
                    ];
                    $candidates[] = $key;
                }
            }
            $sources[] = [
                'id' => (string) $id,
                'url' => $source['url'],
                'token_ref' => $source['token_ref'],
                'vault' => $this->privateFetcher()->describe($source['token_ref']),
                'bound' => $boundTo[$id] ?? [],
                'unbound' => $unbound,
            ];
        }

        $publicListers = $candidates === [] ? [] : $this->publicListers($repository);
        foreach ($sources as &$source) {
            foreach ($source['unbound'] as &$plugin) {
                $plugin['also_listed_by'] = $publicListers[$plugin['key']] ?? [];
            }
            unset($plugin);
        }
        unset($source);

        $removed = [];
        foreach ($doc['removed'] as $id => $url) {
            if (! empty($boundTo[$id])) {
                $removed[] = ['url' => $url, 'bound' => $boundTo[$id]];
            }
        }

        return ['private_sources' => $sources, 'removed_sources' => $removed];
    }

    /** The installed folder name for a key, or the key when nothing is installed under it. */
    private static function installedName(string $key, array $installed): string
    {
        return $installed[$key][0] ?? $key;
    }

    /**
     * Which public sources list each plugin key, for the "also listed by"
     * warning. Best effort: a source that fails to load is skipped.
     *
     * @return array<string, string[]>
     */
    private function publicListers(SourceRepository $repository): array
    {
        $downloader = $this->container['modMenuDirectoryDownloader'] ?? new ArchiveDownloader();
        $listers = [];
        foreach ($repository->getSources() as $url) {
            if (! SourceRepository::isSecureUrl($url)) {
                continue;
            }
            try {
                $keys = DirectoryClient::listedKeys($downloader->fetchJson($url));
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($keys as $key) {
                $listers[$key][] = $url;
            }
        }
        return $listers;
    }

    public function addSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = $this->postValue('url');
        $this->changeSources(fn (SourceRepository $r) => $r->addSource($url), t('Source added.'));
    }

    public function removeSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = $this->postValue('url');
        $this->changeSources(fn (SourceRepository $r) => $r->removeSource($url), t('Source removed.'));
    }

    /**
     * Add a private source: its first fetch must succeed (that fetch's plugin
     * names are recorded with it). The URL and ref are checked before Vault
     * is asked for the token.
     */
    public function addPrivateSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $url = trim($this->postValue('url'));
        $ref = trim($this->postValue('token_ref'));

        $this->changeSources(function (SourceRepository $repository) use ($url, $ref): void {
            $source = GithubUrl::source($url);
            if ($source === null) {
                throw new ModMenuException(t(SourceRepository::NOT_A_PRIVATE_URL));
            }
            if (! PrivateState::isTokenRef($ref)) {
                throw new ModMenuException(t(SourceRepository::NOT_A_TOKEN_REF));
            }
            if ((new PrivateState($this->container))->isDamaged()) {
                throw new PrivateStateDamaged();
            }
            $repository->refuseKnownSource($source['id']);
            try {
                $plugins = $this->privateFetcher()->fetchDirectory(['id' => $source['id'], 'url' => $source['endpoint'], 'token_ref' => $ref]);
            } catch (ModMenuException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ModMenuException(e("Couldn't load this private source."));
            }
            $repository->addPrivateSource($url, $ref, DirectoryClient::listedKeys($plugins));
        }, t('Private source added.'));
    }

    /** Remove a private source. Needs no fetch, so it works while the source is down. */
    public function removePrivateSource()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $id = $this->postValue('id');
        $this->changeSources(fn (SourceRepository $r) => $r->removePrivateSource($id), t('Private source removed.'));
    }

    /** Runs a source-list change, flashes its outcome and returns to the sources page. */
    private function changeSources(callable $change, string $successMessage): void
    {
        try {
            $change(new SourceRepository($this->container));
            $this->flash->success($successMessage);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
        $this->backToSources();
    }

    /** Bind an installed plugin to a private source that lists it (fetched in this request). */
    public function adopt()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name = $this->postValue('name');
        $sourceId = $this->postValue('source_id');
        $this->runAndFlash(
            fn (PluginManager $m) => $m->adopt($name, $sourceId, $this->catalogResult()),
            t('%s adopted.', $name)
        );
        $this->backToSources();
    }

    /** Drop a plugin's binding. Needs no fetch. */
    public function unbind()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name = $this->postValue('name');
        $this->runAndFlash(fn (PluginManager $m) => $m->unbind($name), t('%s unbound.', $name));
        $this->backToSources();
    }

    /** Let public sources update a plugin a private source lists (fetched in this request). */
    public function dismiss()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name = $this->postValue('name');
        $sourceId = $this->postValue('source_id');
        $this->runAndFlash(
            fn (PluginManager $m) => $m->dismiss($name, $sourceId, $this->catalogResult()),
            t('%s dismissed for this source.', $name)
        );
        $this->backToSources();
    }

    /**
     * GET: what a reset drops. POST: under the exclusive catalog lock, move
     * the state file aside and start an empty one with a new epoch.
     */
    public function resetPrivateState()
    {
        $this->requireAdmin();
        $state = $this->privateState();

        if (! $this->request->isPost()) {
            // A damaged file is never read into the page: the reset drops whatever it holds.
            $doc = null;
            if (! $state->isDamaged()) {
                try {
                    // The state can be damaged between the check and this read.
                    $doc = $state->read();
                } catch (PrivateStateDamaged $e) {
                }
            }
            $installed = $doc === null ? [] : $this->manager()->installedKeys();
            $this->response->html($this->helper->layout->config('ModMenu:settings/reset', [
                'title' => t('ModMenu'),
                'tab' => 'sources',
                'frozen' => $doc === null,
                'urls' => $doc === null ? [] : array_merge(array_column($doc['sources'], 'url'), array_values($doc['removed'])),
                'bound' => $doc === null ? [] : array_map(
                    static fn ($key) => self::installedName((string) $key, $installed),
                    array_keys($doc['bindings'])
                ),
            ]));
            return;
        }

        $this->checkCSRFForm();
        try {
            (new CatalogLock(PrivateState::defaultDir($this->container)))->exclusive(static fn () => $state->reset());
            $this->flash->success(t('Private state reset.'));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
        $this->backToSources();
    }

    public function confirm()
    {
        $this->requireAdmin();
        $name = $this->request->getStringParam('name');
        $manager = $this->manager();
        $resolver = new DependencyResolver($this->container);
        $blockers = $resolver->resolveReverse($name, $manager->installedPluginsDeps(), $manager->installedMap());
        $this->response->html($this->template->render('ModMenu:plugin/remove', [
            'name'     => $name,
            'blockers' => array_map(static fn ($b) => $b['plugin'], $blockers),
        ]));
    }

    public function migrate()
    {
        $this->requireAdmin();
        $name = $this->request->getStringParam('name');
        $manager = $this->manager();
        try {
            $catalog = $this->catalog();
            $successor = $manager->migrationSuccessor($name, $catalog);
            $this->renderMigrationConfirmation($name, $successor, $catalog, $manager->migrationCheck($name, $successor, $catalog));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
            $this->backToInstalled();
        }
    }

    private function renderMigrationConfirmation(string $name, string $successor, array $catalog, array $check): void
    {
        $blockedReason = null;
        if ($check['self_update']) {
            $blockedReason = e(PluginManager::MIGRATION_SELF_UPDATE_MESSAGE);
        } elseif ($check['install'] && empty(self::catalogEntry($catalog, $successor)['download'])) {
            $blockedReason = t('%s is not in the plugin directory, so nothing changed.', $successor);
        } elseif ($check['blocked']) {
            $blockedReason = t('"%s" has requirements that cannot be installed automatically. Install them manually first.', $successor);
        }
        $this->response->html($this->helper->layout->config('ModMenu:plugin/migrate', [
            'title' => t('ModMenu'),
            'name' => $name,
            'successor' => $successor,
            'install' => $check['install'],
            'plan' => $check['plan'],
            'plan_digest' => $check['plan_digest'],
            'blocked_reason' => $blockedReason,
        ]));
    }

    public function runMigrate()
    {
        $this->requireAdmin();
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
        $name = $this->postValue('name');
        $manager = $this->manager();

        try {
            [$catalog, $guard] = $this->guardedCatalog();
            $successor = $manager->migrationSuccessor($name, $catalog);
            $check = $manager->migrationCheck($name, $successor, $catalog);
            if ($check['self_update']) {
                throw new ModMenuException(e(PluginManager::MIGRATION_SELF_UPDATE_MESSAGE));
            }
            $digest = $this->request->getRawValue('plan_digest');
            if (! is_string($digest) || ! hash_equals($check['plan_digest'], $digest)) {
                $this->flash->failure(t(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE));
                $this->renderMigrationConfirmation($name, $successor, $catalog, $check);
                return;
            }

            $manager->prepareMigration($name, $catalog);
            $manager->assertMigrationConflicts($name, $successor, $catalog);
            if ($check['blocked']) {
                throw new ModMenuException(t('"%s" has requirements that cannot be installed automatically. Install them manually first.', $successor));
            }
            $dependenciesConfirmed = $this->postValue('action') === 'migrate';
            if (! $check['satisfied'] && ! $dependenciesConfirmed) {
                $this->renderMigrationConfirmation($name, $successor, $catalog, $check);
                return;
            }

            try {
                $successor = $manager->migrate($name, $catalog, $dependenciesConfirmed, $digest, $guard);
            } catch (ModMenuException $e) {
                if ($e->getCode() !== PluginManager::MIGRATION_PLAN_CHANGED) {
                    throw $e;
                }
                $successor = $manager->migrationSuccessor($name, $catalog);
                $this->flash->failure($e->getMessage());
                $this->renderMigrationConfirmation($name, $successor, $catalog, $manager->migrationCheck($name, $successor, $catalog));
                return;
            }
            $this->flash->success(t('Migrated %s to %s. %s stays installed but disabled.', $name, $successor, $name));
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
        $this->backToInstalled();
    }

    public function enable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name = $this->postValue('name');
        if ($this->promptOnConflict($name, 'enable')) {
            return;
        }
        $this->forwardOrConfirm($name, 'enable', '');
    }

    public function disable()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->disable($this->postValue('name')), t('Plugin disabled.'));
        $this->backToInstalled();
    }

    public function uninstall()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $this->runAndFlash(fn (PluginManager $m) => $m->uninstall($this->postValue('name')), t('Plugin removed.'));
        $this->backToInstalled();
    }

    public function install()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name   = $this->postValue('name');
        $target = $this->postValue('archive_url');

        // Legacy path: an install form that posts only archive_url (no name) can't
        // pre-flight deps — install directly, unchanged behavior.
        if ($name === '') {
            $this->runAndFlash(function (PluginManager $m) use ($target) {
                $guard = MutationGuard::forUnlistedInstall($this->container, fn () => $this->catalogResult());
                $guard->assertCanStart();
                return $m->setCatalog([], $guard)->installFromUrl($target);
            }, t('Plugin installed.'), '', true);
            $this->backToDirectory();
            return;
        }
        $guarded = $this->guardedCatalogOrRefuse(true);
        if ($guarded === null) {
            return;
        }
        [$catalog, $guard] = $guarded;
        if ($this->refuseMisspelled($catalog, $name)) {
            return;
        }
        $entry = self::catalogEntry($catalog, $name);
        if ($this->promptOnConflict($name, 'install', $entry['conflicts'] ?? [], $entry['replaces'] ?? [])) {
            return;
        }
        // A named install always takes its download from the catalog.
        $this->forwardOrConfirm($name, 'install', '', $catalog, '', $guard);
    }

    /**
     * Submit of the conflict prompt. Re-derives the conflicts server-side.
     * Confirm: disable every active conflict, then continue into the normal
     * dependency flow. Cancel: the plugin stays inactive.
     */
    public function resolveConflict()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $name    = $this->postValue('name');
        $action  = $this->postValue('action') === 'install' ? 'install' : 'enable';
        $manager = $this->manager();
        $catalog = [];
        $guard = null;
        if ($action === 'install') {
            $result = $this->catalogResult();
            $catalog = $result['plugins'];
            $guard = new MutationGuard($this->container, $result);
            if ($this->refuseMisspelled($catalog, $name)) {
                return;
            }
        }
        $entry    = self::catalogEntry($catalog, $name);
        $declared = $entry['conflicts'] ?? [];
        $replaces = $entry['replaces'] ?? [];

        if ($this->postValue('decision') !== 'confirm') {
            $conflicts = $manager->activeConflicts($name, $declared, $replaces);
            $this->flash->failure(t('%s stays inactive until %s is disabled.', $name, implode(', ', $conflicts)));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        try {
            $guard?->assertCanStart();
            $disabled = $manager->disableConflicts($name, $declared, $replaces);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        $notice = $disabled === [] ? '' : t('Disabled %s.', implode(', ', $disabled));
        $this->forwardOrConfirm($name, $action, '', $action === 'install' ? $catalog : null, $notice, $guard);
    }

    /**
     * Update every active plugin the directory has a newer version of.
     * ModMenu itself goes last, so after it only flash/redirect code runs.
     */
    public function updateAll()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $guarded = $this->guardedCatalogOrRefuse(true);
        if ($guarded === null) {
            return;
        }
        [$catalog, $guard] = $guarded;
        try {
            $result = $this->manager()->updateAll($catalog, $guard);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
            $this->backToDirectory();
            return;
        }
        $updated = $result['updated'];

        $parts = [];
        if ($updated !== []) {
            $parts[] = t('Updated %d: %s.', count($updated), implode(', ', $updated));
        }
        if (in_array(PluginManager::SELF, $updated, true)) {
            $version = (string) PluginSwap::readVersion(PLUGINS_DIR . '/' . PluginManager::SELF);
            $parts[] = t('ModMenu updated to %s. The previous copy is removed once the new version has loaded.', $version);
        }
        if ($result['failed'] !== []) {
            $lines = [];
            foreach ($result['failed'] as $plugin => $message) {
                $lines[] = $plugin . ' (' . $message . ')';
            }
            $parts[] = t('Could not update %s.', implode('; ', $lines));
        }

        // Core renders only one flash (success wins), so a partial run is one failure message.
        if ($parts === []) {
            $this->flash->success(t('Everything is up to date.'));
        } elseif ($result['failed'] !== []) {
            $this->flash->failure(implode(' ', $parts));
        } else {
            $this->flash->success(implode(' ', $parts));
        }

        in_array(PluginManager::SELF, $updated, true) ? $this->backToInstalled() : $this->backToDirectory();
    }

    public function resolve()
    {
        $this->requireAdmin();
        if ($this->request->getRawValue('action') === 'migrate') {
            $this->runMigrate();
            return;
        }
        $this->checkCSRFForm();
        $name   = $this->postValue('name');
        $action = $this->postValue('action') === 'install' ? 'install' : 'enable';

        // Re-derive the plan + URLs server-side from a fresh catalog — never trust the post.
        $guarded = $this->guardedCatalogOrRefuse($action === 'install');
        if ($guarded === null) {
            return;
        }
        [$catalog, $guard] = $guarded;
        if ($action === 'install' && $this->refuseMisspelled($catalog, $name)) {
            return;
        }
        $manager  = $this->manager();
        $requires = $this->requiresFor($name, $action, $catalog, $manager);
        $check    = $manager->forwardCheck($requires, $catalog, null, $this->dependencySource($name, $action, $catalog));
        $entry    = $action === 'install' ? self::catalogEntry($catalog, $name) : [];
        $target   = (string) ($entry['download'] ?? '');

        // The conflict prompt must not be bypassable by posting here directly.
        $conflicts = $manager->activeConflicts($name, $entry['conflicts'] ?? [], $entry['replaces'] ?? []);
        if ($conflicts !== []) {
            $this->flash->failure(t('%s conflicts with %s. Disable them first.', $name, implode(', ', $conflicts)));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        // Guard against the catalog changing between the confirm render and this
        // submit (TOCTOU): if a requirement is no longer auto-resolvable, refuse
        // rather than run a plan that resolveAndActivate would abort on anyway.
        if ($check['blocked']) {
            $this->flash->failure(t('"%s" has requirements that can no longer be installed automatically. Install them manually first.', $name) . self::unresolvableNote($check['plan']));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        $this->runAndFlash(
            function (PluginManager $m) use ($name, $action, $target, $check, $catalog, $guard) {
                $m->setCatalog($catalog, $guard);
                $m->resolveAndActivate($name, $action, $target, $check['plan']);
            },
            $action === 'install' ? t('Plugin and its dependencies installed.') : t('Plugin and its dependencies enabled.'),
            $name
        );
        $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
    }

    public function update()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $expected = $this->postValue('name');
        $guarded = $this->guardedCatalogOrRefuse(true);
        if ($guarded === null) {
            return;
        }
        [$catalog, $guard] = $guarded;
        if ($this->refuseMisspelled($catalog, $expected)) {
            return;
        }
        $url = (string) (self::catalogEntry($catalog, $expected)['download'] ?? '');
        if ($expected === '' || $url === '') {
            $this->flash->failure(t('This plugin is not in the directory.'));
            $this->backToDirectory();
            return;
        }
        $manager = $this->manager();
        try {
            $manager->setCatalog($catalog, $guard);
            $name = $manager->installFromUrl($url, $expected);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
            $this->backToDirectory();
            return;
        }

        if ($name === PluginManager::SELF) {
            // The files on disk are now the new ModMenu: use only classes this
            // request already loaded (no conflict warning, no new models).
            $version = (string) PluginSwap::readVersion(PLUGINS_DIR . '/' . PluginManager::SELF);
            $this->flash->success(t('ModMenu updated to %s. The previous copy is removed once the new version has loaded.', $version));
            $this->backToInstalled();
            return;
        }

        $warning = $manager->conflictWarning($name);
        $this->flash->success($warning === '' ? t('Plugin updated.') : t('Plugin updated.') . ' ' . $warning);
        $this->backToDirectory();
    }

    // ── dependency helpers ──────────────────────────────────────────────────

    /** One fetch of every source with its freshness fields (DirectoryClient::catalogResult()). */
    protected function catalogResult(): array
    {
        return (new DirectoryClient($this->container))->catalogResult();
    }

    /** The catalog for read-only pages. */
    protected function catalog(): array
    {
        return $this->catalogResult()['plugins'];
    }

    /**
     * The catalog and guard for one catalog mutation, from one fetch.
     *
     * @return array{0: array, 1: MutationGuard}
     * @throws ModMenuException when the guard refuses to start
     */
    private function guardedCatalog(): array
    {
        $result = $this->catalogResult();
        $guard = new MutationGuard($this->container, $result);
        $guard->assertCanStart();
        return [$result['plugins'], $guard];
    }

    /** guardedCatalog(), or null after flashing the refusal and redirecting. */
    private function guardedCatalogOrRefuse(bool $toDirectory): ?array
    {
        try {
            return $this->guardedCatalog();
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
            $toDirectory ? $this->backToDirectory() : $this->backToInstalled();
            return null;
        }
    }

    /**
     * The private source a plugin's dependencies must come from: its catalog
     * entry's source when installing, its binding when enabling.
     */
    private function dependencySource(string $name, string $action, array $catalog): ?string
    {
        if ($action === 'install') {
            $entry = self::catalogEntry($catalog, $name);
            return ! empty($entry['private']) && is_string($entry['source_id'] ?? null) ? $entry['source_id'] : null;
        }
        $key = PluginName::key($name);
        try {
            return $key === null ? null : ((new PrivateState($this->container))->read()['bindings'][$key] ?? null);
        } catch (PrivateStateDamaged $e) {
            return null;
        }
    }

    /**
     * The private fetcher (R1). Typed object, as in DirectoryClient and
     * PluginManager: tests inject duck-typed fakes under modMenuPrivateFetcher.
     */
    private function privateFetcher(): object
    {
        return $this->container['modMenuPrivateFetcher'] ?? new PrivateFetcher($this->container);
    }

    /**
     * The catalog entry that is the same plugin as $name (PluginName::key),
     * or []. Lookups only find the entry; the manager refuses an install
     * whose name is not the entry's own name byte for byte (R17).
     */
    private static function catalogEntry(array $catalog, string $name): array
    {
        $found = PluginName::find($catalog, $name);
        $entry = $found === null ? null : $catalog[$found];
        return is_array($entry) ? $entry : [];
    }

    /**
     * R17, before any conflict prompt, conflict disabling or dependency step:
     * a posted name whose catalog entry (found by key) has another `name` is
     * refused with a failure flash and a return to Browse. True when refused.
     */
    private function refuseMisspelled(array $catalog, string $name): bool
    {
        $found = PluginName::find($catalog, $name);
        if ($found === null) {
            return false;
        }
        $listed = $catalog[$found]['name'] ?? $found;
        $listed = is_string($listed) ? $listed : $found;
        if ($listed === $name) {
            return false;
        }
        $this->flash->failure(t(PluginManager::LISTED_AS, $listed, $name));
        $this->backToDirectory();
        return true;
    }

    private function requiresFor(string $name, string $action, array $catalog, PluginManager $manager): array
    {
        if ($action === 'install') {
            return self::catalogEntry($catalog, $name)['requires'] ?? [];
        }
        $deps = $manager->installedPluginsDeps();
        return $deps[$name]['requires'] ?? [];
    }

    /**
     * Forward gate for enable/install: act directly when satisfied, block when a
     * requirement is unresolvable, otherwise render the resolve-plan confirmation.
     */
    private function forwardOrConfirm(string $name, string $action, string $target, ?array $catalog = null, string $notice = '', ?MutationGuard $guard = null): void
    {
        $manager  = $this->manager();
        $catalog  = $catalog ?? $this->catalog();
        $prefix   = $notice === '' ? '' : $notice . ' ';
        $requires = $this->requiresFor($name, $action, $catalog, $manager);

        // Resolve the target's own download URL when installing without one.
        if ($action === 'install' && $target === '') {
            $target = (string) (self::catalogEntry($catalog, $name)['download'] ?? '');
        }

        $check = $manager->forwardCheck($requires, $catalog, null, $this->dependencySource($name, $action, $catalog));

        if ($check['satisfied']) {
            $this->runAndFlash(function (PluginManager $m) use ($name, $action, $target, $catalog, $guard) {
                $m->setCatalog($catalog, $guard);
                $action === 'install' ? $m->installFromUrl($target, $name) : $m->enable($name);
            }, $prefix . ($action === 'install' ? t('Plugin installed.') : t('Plugin enabled.')), $name);
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        if ($check['blocked']) {
            $this->flash->failure($prefix . t('"%s" has requirements that cannot be installed automatically. Install them manually first.', $name) . self::unresolvableNote($check['plan']));
            $action === 'install' ? $this->backToDirectory() : $this->backToInstalled();
            return;
        }

        if ($notice !== '') {
            $this->flash->success($notice);
        }
        $this->response->html($this->helper->layout->config('ModMenu:plugin/resolve', [
            'title'  => t('ModMenu'),
            'name'   => $name,
            'action' => $action,
            'plan'   => $check['plan'],
        ]));
    }

    /**
     * " Needed: A, B (Install it yourself first)." for a plan's unresolvable
     * steps; the reason names a dependency a private plugin's source does not list.
     */
    private static function unresolvableNote(array $plan): string
    {
        $needed = [];
        foreach ($plan as $step) {
            if (($step['action'] ?? null) === 'unresolvable') {
                $needed[] = empty($step['reason']) ? $step['plugin'] : $step['plugin'] . ' (' . t($step['reason']) . ')';
            }
        }
        return $needed === [] ? '' : ' ' . t('Needed: %s.', implode(', ', $needed));
    }

    /**
     * Conflict gate for enable/install: when X conflicts with an active plugin,
     * render the confirm page instead of acting. Returns true when it rendered.
     */
    private function promptOnConflict(string $name, string $action, array $declared = [], array $replaces = []): bool
    {
        $conflicts = $this->manager()->activeConflicts($name, $declared, $replaces);
        if ($conflicts === []) {
            return false;
        }
        $this->response->html($this->helper->layout->config('ModMenu:plugin/conflict', [
            'title'     => t('ModMenu'),
            'name'      => $name,
            'action'    => $action,
            'conflicts' => $conflicts,
        ]));
        return true;
    }

    private function backToDirectory()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'directory', ['plugin' => 'ModMenu']));
    }

    private function backToSources()
    {
        $this->response->redirect($this->helper->url->to('ModMenuController', 'sources', ['plugin' => 'ModMenu']));
    }

    /**
     * Read a value from the CSRF-validated POST body.
     *
     * ModMenu's mutating actions submit their values as POST form fields, so
     * they must be read via getValues() — NOT getStringParam(), which reads
     * ONLY the GET query string and returns '' for a posted field. (Kanboard's
     * core PluginController uses GET install links, which is why it can use
     * getStringParam; ModMenu uses POST forms for its mutations.) checkCSRFForm()
     * has already run and leaves the token in place, so getValues() returns the
     * filtered POST array. The value is already URL-decoded by PHP — applying
     * urldecode() would double-decode a literal '%' (e.g. '%20' in a
     * release-asset filename).
     *
     * getValues() unsets csrf_token from the request as it reads, so a second
     * call returns []. Read the body once per request and reuse it.
     */
    private function postValue(string $key): string
    {
        if ($this->postValues === null) {
            $this->postValues = $this->request->getValues();
        }
        return isset($this->postValues[$key]) ? (string) $this->postValues[$key] : '';
    }

    /** @var array|null */
    private $postValues;

    /**
     * Run a manager operation and flash the outcome. When $warnFor names a
     * plugin (or $warnForResult takes the plugin name the op returns), any
     * conflict still active afterwards is appended to the success message.
     * (Enable/install ask first via promptOnConflict(); this covers updates.)
     */
    private function runAndFlash(callable $op, string $successMessage, string $warnFor = '', bool $warnForResult = false): void
    {
        try {
            $manager = $this->manager();
            $result = $op($manager);
            if ($warnForResult && is_string($result)) {
                $warnFor = $result;
            }
            $warning = $warnFor !== '' ? $manager->conflictWarning($warnFor) : '';
            $this->flash->success($warning === '' ? $successMessage : $successMessage . ' ' . $warning);
        } catch (ModMenuException $e) {
            $this->flash->failure($e->getMessage());
        }
    }
}
