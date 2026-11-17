<div class="page-header"><h2><?= t('Plugin conflict') ?></h2></div>
<div class="confirm">
    <div class="modmenu-callout modmenu-callout--conflict" role="alert">
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
    <form method="post" action="<?= $this->url->href('ModMenuController', 'resolveConflict', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
        <input type="hidden" name="action" value="<?= $this->text->e($action) ?>">
        <div class="form-actions">
            <button type="submit" name="decision" value="confirm" class="btn btn-red"><?= t('Disable and continue') ?></button>
            <button type="submit" name="decision" value="cancel" class="btn"><?= t('Cancel') ?></button>
        </div>
    </form>
</div>
