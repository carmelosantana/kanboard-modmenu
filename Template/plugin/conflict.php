<div class="page-header"><h2><?= t('Plugin conflict') ?></h2></div>
<div class="confirm">
    <div class="modmenu-dep modmenu-callout modmenu-dep--conflict" role="alert">
        <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
        <span><strong class="modmenu-callout__label"><?= t('Conflict.') ?></strong>
        <?= t('%s conflicts with %s. Disable them and continue?', $this->text->e($name), $this->text->e(implode(', ', $conflicts))) ?></span>
    </div>
    <ul class="modmenu-plan">
        <?php foreach ($conflicts as $other): ?>
            <li><?= t('Disable %s', $this->text->e($other)) ?></li>
        <?php endforeach ?>
        <li><strong><?= $action === 'install' ? t('Install %s', $this->text->e($name)) : t('Enable %s', $this->text->e($name)) ?></strong></li>
    </ul>
    <div class="form-actions modmenu-conflict-actions">
        <?php foreach (['confirm' => [t('Disable and continue'), 'btn btn-red'], 'cancel' => [t('Cancel'), 'btn']] as $decision => [$label, $class]): ?>
            <form method="post" action="<?= $this->url->href('ModMenuController', 'resolveConflict', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
                <?= $this->form->csrf() ?>
                <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
                <input type="hidden" name="action" value="<?= $this->text->e($action) ?>">
                <input type="hidden" name="decision" value="<?= $decision ?>">
                <button type="submit" class="<?= $class ?>"><?= $label ?></button>
            </form>
        <?php endforeach ?>
    </div>
</div>
