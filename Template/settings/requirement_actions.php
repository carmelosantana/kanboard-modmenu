<?php /* Installed tab: the button that satisfies one unmet dependency (enable, install, or update via Browse). */ ?>
<?php if ($dep['status'] === 'disabled'): ?>
    <form method="post" style="display:inline"
          action="<?= $this->url->href('ModMenuController', 'enable', ['plugin' => 'ModMenu']) ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($dep['plugin']) ?>">
        <button type="submit" class="btn btn-blue"><?= t('Enable %s', $this->text->e($dep['plugin'])) ?></button>
    </form>
<?php elseif ($dep['status'] === 'missing'): ?>
    <form method="post" style="display:inline"
          action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($dep['plugin']) ?>">
        <button type="submit" class="btn btn-blue"><?= t('Install %s', $this->text->e($dep['plugin'])) ?></button>
    </form>
<?php elseif ($dep['status'] === 'outdated'): ?>
    <?= $this->url->link(t('Update via Browse'), 'ModMenuController', 'directory', ['plugin' => 'ModMenu'], false, 'btn') ?>
<?php endif ?>
