<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php if (! empty($frozen)): ?>
    <?= $this->render('ModMenu:settings/frozen') ?>
<?php endif ?>

<p><?= t('ModMenu fetches plugin listings from these directory sources. Add your own to install from another repository.') ?></p>

<ul class="modmenu-sources">
    <?php foreach ($sources as $src): ?>
        <li class="modmenu-card">
            <code><?= $this->text->e($src) ?></code>
            <?php if ($src === $default_source): ?> <em>(<?= t('default') ?>)</em><?php endif ?>
            <form method="post" class="modmenu-action" style="display:inline"
                  action="<?= $this->url->href('ModMenuController', 'removeSource', ['plugin' => 'ModMenu']) ?>">
                <?= $this->form->csrf() ?>
                <input type="hidden" name="url" value="<?= $this->text->e($src) ?>">
                <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
            </form>
        </li>
    <?php endforeach ?>
</ul>

<form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'addSource', ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Directory URL (plugins.json)'), 'url') ?>
    <?= $this->form->text('url', [], [], ['placeholder' => 'https://example.com/plugins.json']) ?>
    <button type="submit" class="btn btn-blue"><?= t('Add source') ?></button>
</form>

<h3><?= t('Private sources') ?></h3>
<p><?= t('A private source is a plugins.json in a private GitHub repository. ModMenu reads its GitHub token from Vault for each request; the token is never stored by ModMenu. Any plugin running on this server could ask Vault for the same token, so use a read-only fine-grained token limited to the directory and plugin repositories.') ?></p>

<?php $vaultWording = ['shared' => t('shared with Server'), 'not_shared' => t('not shared with Server'), 'vault_off' => t('Vault off')] ?>
<ul class="modmenu-sources">
    <?php foreach ($private_sources as $src): ?>
        <li class="modmenu-card">
            <span class="modmenu-badge modmenu-badge--private"><?= t('Private') ?></span>
            <code><?= $this->text->e($src['url']) ?></code>
            <form method="post" class="modmenu-action" style="display:inline"
                  action="<?= $this->url->href('ModMenuController', 'removePrivateSource', ['plugin' => 'ModMenu']) ?>">
                <?= $this->form->csrf() ?>
                <input type="hidden" name="id" value="<?= $this->text->e($src['id']) ?>">
                <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
            </form>
            <div class="modmenu-card__status">
                <?= t('Token:') ?> <code><?= $this->text->e($src['token_ref']) ?></code> ·
                <span class="modmenu-vault modmenu-vault--<?= $this->text->e($src['vault']) ?>"><?= $vaultWording[$src['vault']] ?? t('Vault off') ?></span>
            </div>

            <?php if (! empty($src['bound'])): ?>
                <p><?= t('Bound plugins (they update only from this source):') ?></p>
                <ul>
                    <?php foreach ($src['bound'] as $name): ?>
                        <li>
                            <?= $this->text->e($name) ?>
                            <?= $this->render('ModMenu:settings/private_action', ['action' => 'unbind', 'name' => $name, 'label' => t('Unbind'), 'class' => 'btn']) ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>

            <?php if (! empty($src['unbound'])): ?>
                <p><?= t('Installed plugins this source lists. Adopt one to update it only from this source; dismiss it to keep updating it from public sources.') ?></p>
                <ul>
                    <?php foreach ($src['unbound'] as $plugin): ?>
                        <li>
                            <?= $this->text->e($plugin['name']) ?>
                            <?php if (! empty($plugin['also_listed_by'])): ?>
                                <em><?= t('also listed by %s', $this->text->e(implode(', ', $plugin['also_listed_by']))) ?></em>
                            <?php endif ?>
                            <?php if ($plugin['dismissed']): ?><em>(<?= t('dismissed') ?>)</em><?php endif ?>
                            <?= $this->render('ModMenu:settings/private_action', ['action' => 'adopt', 'name' => $plugin['name'], 'source_id' => $src['id'], 'label' => t('Adopt'), 'class' => 'btn btn-blue']) ?>
                            <?php if (! $plugin['dismissed']): ?>
                                <?= $this->render('ModMenu:settings/private_action', ['action' => 'dismiss', 'name' => $plugin['name'], 'source_id' => $src['id'], 'label' => t('Dismiss'), 'class' => 'btn']) ?>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </li>
    <?php endforeach ?>

    <?php foreach ($removed_sources as $src): ?>
        <li class="modmenu-card">
            <code><?= $this->text->e($src['url']) ?></code> — <?= e('Private source unavailable') ?>
            <p><?= t('This source was removed. Its plugins do not update until you add it again or unbind them.') ?></p>
            <ul>
                <?php foreach ($src['bound'] as $name): ?>
                    <li>
                        <?= $this->text->e($name) ?>
                        <?= $this->render('ModMenu:settings/private_action', ['action' => 'unbind', 'name' => $name, 'label' => t('Unbind'), 'class' => 'btn']) ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </li>
    <?php endforeach ?>
</ul>

<?php if (empty($frozen)): ?>
<form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'addPrivateSource', ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Private directory URL (raw.githubusercontent.com)'), 'private_url') ?>
    <input type="text" name="url" id="form-private_url" placeholder="https://raw.githubusercontent.com/owner/repo/main/plugins.json">
    <?= $this->form->label(t('Vault reference to the GitHub token'), 'token_ref') ?>
    <?= $this->form->text('token_ref', [], [], ['placeholder' => 'vault://…']) ?>
    <button type="submit" class="btn btn-blue"><?= t('Add private source') ?></button>
</form>
<?php endif ?>
<p><?= $this->url->link(t('Reset private state'), 'ModMenuController', 'resetPrivateState', ['plugin' => 'ModMenu']) ?></p>
