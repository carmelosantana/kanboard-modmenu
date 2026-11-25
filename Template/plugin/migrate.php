<div class="page-header"><h2><?= $plan === [] ? t('Migrate %s to %s?', $this->text->e($name), $this->text->e($successor)) : t('Resolve dependencies') ?></h2></div>
<div class="confirm">
    <?php if ($plan !== []): ?>
        <p class="alert alert-info">
            <?= t('"%s" needs other plugins. ModMenu will set them up first:', $this->text->e($successor)) ?>
        </p>
    <?php endif ?>
    <ol class="modmenu-plan">
        <?php if ($install): ?>
            <li><?= t('Install %s', $this->text->e($successor)) ?></li>
        <?php endif ?>
        <?php foreach ($plan as $step): ?>
            <li>
                <?php if ($step['action'] === 'install'): ?>
                    <?= t('Install %s', $this->text->e($step['plugin'])) ?>
                <?php elseif ($step['action'] === 'update'): ?>
                    <?= t('Update %s', $this->text->e($step['plugin'])) ?>
                <?php elseif ($step['action'] === 'enable'): ?>
                    <?= t('Enable %s', $this->text->e($step['plugin'])) ?>
                <?php else: ?>
                    <?= t('Install %s manually', $this->text->e($step['plugin'])) ?>
                <?php endif ?>
                <?php if (! empty($step['min_version'])): ?> (&ge; <?= $this->text->e($step['min_version']) ?>)<?php endif ?>
            </li>
        <?php endforeach ?>
        <li><?= t('Disable %s', $this->text->e($name)) ?></li>
        <li><?= t('Enable %s', $this->text->e($successor)) ?></li>
    </ol>
    <p><?= t('%s stays installed but disabled. Remove it later if you like.', $this->text->e($name)) ?></p>
    <?php if ($blocked_reason !== null): ?>
        <p class="alert alert-error"><?= $this->text->e($blocked_reason) ?></p>
        <?= $this->url->link(t('Cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu']) ?>
    <?php else: ?>
        <form method="post" action="<?= $this->url->href('ModMenuController', $plan === [] ? 'runMigrate' : 'resolve', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
            <?= $this->form->csrf() ?>
            <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
            <input type="hidden" name="plan_digest" value="<?= $this->text->e($plan_digest) ?>">
            <?php if ($plan !== []): ?>
                <input type="hidden" name="action" value="migrate">
            <?php endif ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-blue"><?= t('Migrate') ?></button>
                <?= $this->url->link(t('Cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu']) ?>
            </div>
        </form>
    <?php endif ?>
</div>
