<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php if (! $is_configured): ?>
    <?= $this->render('ModMenu:settings/not_configured', ['reason' => $not_configured_reason]) ?>
<?php endif ?>

<?php if (! $plugin_installer_enabled): ?>
    <p class="alert alert-info"><?= $this->text->e(t('Kanboard\'s built-in plugin installer is off (PLUGIN_INSTALLER = false, the default). ModMenu is a separate installer for administrators: it keeps working while the plugins folder is writable and the PHP zip extension is loaded.')) ?></p>
<?php endif ?>

<?php if (empty($plugins)): ?>
    <p class="alert"><?= t('No plugins found.') ?></p>
<?php else: ?>
    <?php foreach ($plugins as $p): ?>
        <div class="modmenu-card">
            <strong><?= $this->text->e($p['title']) ?></strong>
            <span class="modmenu-badge modmenu-badge--<?= $p['status'] === 'disabled' ? 'disabled' : 'installed' ?>">
                <?= $p['status'] === 'disabled' ? t('Disabled') : t('Active') ?>
            </span>

            <?php
                $requiresState = $p['requires_state'] ?? [];
                $unmetRecommends = array_values(array_filter($p['unmet_deps'] ?? [], static fn ($d) => ($d['kind'] ?? '') === 'recommends'));
            ?>
            <?php if ($requiresState || $unmetRecommends || ! empty($p['active_conflicts'])): ?>
            <div class="modmenu-card__alerts">
            <?php if ($requiresState || $unmetRecommends): ?>
                <div class="modmenu-deps">
                    <?php foreach ($requiresState as $dep): ?>
                        <?= $this->render('ModMenu:settings/requirement', ['dep' => $dep, 'actions' => true, 'missing_label' => t('Missing requirement:')]) ?>
                    <?php endforeach ?>
                    <?php foreach ($unmetRecommends as $dep): ?>
                        <div class="modmenu-dep modmenu-dep--recommended">
                            <span class="modmenu-dep__label">
                                <?= t('Works better with %s', $this->text->e($dep['plugin'])) ?>
                                <?php if (! empty($dep['min_version'])): ?> (&ge; <?= $this->text->e($dep['min_version']) ?>)<?php endif ?>
                                <?php if (! empty($dep['reason'])): ?> — <?= $this->text->e($dep['reason']) ?><?php endif ?>
                            </span>
                            <?= $this->render('ModMenu:settings/requirement_actions', ['dep' => $dep]) ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if (! empty($p['active_conflicts'])): ?>
                <div class="modmenu-dep modmenu-callout modmenu-dep--conflict">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <span><strong class="modmenu-callout__label"><?= t('Conflict.') ?></strong>
                    <?= t('Conflicts with %s: disable one.', $this->text->e(implode(', ', $p['active_conflicts']))) ?></span>
                </div>
            <?php endif ?>
            </div>
            <?php endif ?>
            <div class="modmenu-card__status">
                <?= $this->text->e($p['name']) ?> · v<?= $this->text->e($p['version']) ?>
                <?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?>
            </div>
            <?php if (! empty($p['description'])): ?>
                <p><?= $this->text->e($p['description']) ?></p>
            <?php endif ?>


            <?php if ($p['name'] !== $self_name): ?>
                <div class="modmenu-actions">
                    <?php if ($p['status'] === 'active'): ?>
                        <form method="post" class="modmenu-action" style="display:inline"
                              action="<?= $this->url->href('ModMenuController', 'disable', ['plugin' => 'ModMenu']) ?>">
                            <?= $this->form->csrf() ?>
                            <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                            <button type="submit" class="btn"><?= t('Disable') ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="modmenu-action" style="display:inline"
                              action="<?= $this->url->href('ModMenuController', 'enable', ['plugin' => 'ModMenu']) ?>">
                            <?= $this->form->csrf() ?>
                            <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                            <button type="submit" class="btn"><?= t('Enable') ?></button>
                        </form>
                    <?php endif ?>

                    <?= $this->url->link(t('Remove'), 'ModMenuController', 'confirm',
                        ['plugin' => 'ModMenu', 'name' => $this->text->e($p['name'])], false, 'js-modal-confirm btn btn-red') ?>
                </div>
            <?php else: ?>
                <div class="modmenu-card__status"><em><?= t('This is ModMenu itself. It cannot be disabled or removed here. Update it from Browse.') ?></em></div>
                <?php if ($self_update_pending): ?>
                    <div class="modmenu-card__status"><?= t('An update was just installed; the previous copy is removed on the next page load.') ?></div>
                <?php endif ?>
            <?php endif ?>
        </div>
    <?php endforeach ?>
<?php endif ?>
