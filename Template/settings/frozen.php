<div class="modmenu-banner" role="alert">
    <strong><?= e("ModMenu's private source state is damaged") ?>.</strong>
    <?= t('Nothing can be installed, updated or removed, and Browse shows public sources only.') ?>
    <?= $this->url->link(t('Reset private state'), 'ModMenuController', 'resetPrivateState', ['plugin' => 'ModMenu']) ?>
    <?= t('If the modmenu folder in the data directory or its lock files are damaged (wrong owner or permissions, or a symlink), Reset cannot repair them: fix their permissions by hand (owned by the web server user, folder 0700, files 0600), then reload.') ?>
</div>
