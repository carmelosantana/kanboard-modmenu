<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php foreach ($errors as $err): ?>
    <div class="modmenu-banner"><?= t('Could not load source: %s', $this->text->e($err['url'])) ?> — <?= $this->text->e($err['message']) ?></div>
<?php endforeach ?>

<?php if (! empty($updates) && $is_configured): ?>
    <div class="modmenu-updates">
        <div class="modmenu-updates__head">
            <strong><?= count($updates) === 1 ? t('1 update available') : t('%d updates available', count($updates)) ?></strong>
            <form method="post" action="<?= $this->url->href('ModMenuController', 'updateAll', ['plugin' => 'ModMenu']) ?>">
                <?= $this->form->csrf() ?>
                <button type="submit" class="btn btn-blue"><i class="fa fa-refresh" aria-hidden="true"></i> <?= t('Update all') ?></button>
            </form>
        </div>
        <ul class="modmenu-updates__list">
            <?php foreach ($updates as $u): ?>
                <li>
                    <span class="modmenu-updates__name"><?= $this->text->e($u['plugin']) ?></span>
                    <span class="modmenu-updates__version"><?= $this->text->e($u['from']) ?> &rarr; <strong><?= $this->text->e($u['to']) ?></strong></span>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?php if (empty($plugins)): ?>
    <p class="alert"><?= t('No plugins available from the configured sources.') ?></p>
<?php else: ?>
    <?php foreach ($plugins as $p): ?>
        <div class="modmenu-card">
            <strong><?= $this->text->e($p['title'] ?? $p['name']) ?></strong>
            <?php if ($p['status'] === 'update'): ?>
                <span class="modmenu-badge modmenu-badge--update"><?= t('Update to %s', $this->text->e($p['version'])) ?></span>
            <?php elseif ($p['status'] === 'installed'): ?>
                <span class="modmenu-badge modmenu-badge--installed"><?= t('Installed') ?></span>
            <?php elseif ($p['status'] === 'disabled'): ?>
                <span class="modmenu-badge modmenu-badge--disabled"><?= t('Disabled') ?></span>
            <?php endif ?>

            <?php if (! empty($p['requires']) || ! empty($p['conflicts'])): ?>
            <div class="modmenu-card__alerts">
            <?php if (! empty($p['requires'])): ?>
                <div class="modmenu-dep modmenu-callout modmenu-dep--required">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <span><strong class="modmenu-callout__label"><?= t('Requires:') ?></strong>
                    <?php foreach ($p['requires'] as $i => $r): ?><?= $i ? ', ' : ' ' ?><?= $this->text->e($r['plugin']) ?><?php if (! empty($r['min_version'])): ?> &ge; <?= $this->text->e($r['min_version']) ?><?php endif ?><?php endforeach ?></span>
                </div>
            <?php endif ?>
            <?php if (! empty($p['conflicts'])): ?>
                <div class="modmenu-dep modmenu-callout modmenu-dep--conflict">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <span><strong class="modmenu-callout__label"><?= t('Conflict.') ?></strong>
                    <?= t('Conflicts with %s: enable only one.', $this->text->e(implode(', ', $p['conflicts']))) ?></span>
                </div>
            <?php endif ?>
            </div>
            <?php endif ?>

            <div class="modmenu-card__status">v<?= $this->text->e($p['version']) ?><?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?></div>
            <?php if (! empty($p['description'])): ?><p><?= $this->text->e($p['description']) ?></p><?php endif ?>

            <?php if (! empty($p['recommends'])): ?>
                <div class="modmenu-dep modmenu-dep--recommended">
                    <?= t('Recommends:') ?>
                    <?php foreach ($p['recommends'] as $i => $r): ?><?= $i ? ', ' : ' ' ?><?= $this->text->e($r['plugin']) ?><?php endforeach ?>
                </div>
            <?php endif ?>

            <?php if (! empty($p['screenshots'])): ?>
                <div class="modmenu-shots">
                    <?php foreach ($p['screenshots'] as $shot): ?>
                        <a class="modmenu-shot-link" href="<?= $this->text->e($shot) ?>" target="_blank" rel="noopener">
                            <img class="modmenu-shot" src="<?= $this->text->e($shot) ?>" alt="<?= t('Screenshot of %s', $this->text->e($p['title'] ?? $p['name'])) ?>" loading="lazy">
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <?php if (! empty($p['download'])): ?>
                <?php if ($p['status'] === 'available'): ?>
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Install') ?></button>
                    </form>
                <?php elseif ($p['status'] === 'update'): ?>
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'update', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Update') ?></button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    <?php endforeach ?>
<?php endif ?>
