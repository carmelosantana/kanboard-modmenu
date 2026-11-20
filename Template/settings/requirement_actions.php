<?php /* Installed tab: the button that satisfies one unmet dependency (enable, install, or update via Browse). */ ?>
<?php
// A disabled copy below the minimum needs a newer copy, not just enabling (classify: action update,
// or unresolvable when classified without a catalog as the Installed tab does).
$min = $dep['min_version'] ?? null;
$installed = $dep['installed_version'] ?? null;
$needsUpdate = ($dep['action'] ?? '') === 'update'
    || (! empty($min) && $installed !== null && $installed !== '' && version_compare((string) $installed, (string) $min, '<'));
?>
<?php if ($dep['status'] === 'disabled' && ! $needsUpdate): ?>
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
<?php elseif ($dep['status'] === 'outdated' || $dep['status'] === 'disabled'): ?>
    <?= $this->url->link(t('Update via Browse'), 'ModMenuController', 'directory', ['plugin' => 'ModMenu'], false, 'btn') ?>
<?php endif ?>
