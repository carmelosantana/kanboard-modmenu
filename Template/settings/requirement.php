<?php
/*
 * One classified `requires` entry as a callout: met (green check), outdated
 * (amber), missing or disabled (red). $actions adds the Installed tab's
 * Install / Enable / Update via Browse buttons; $missing_label names a missing one.
 */
$status = $dep['status'];
$min = $dep['min_version'] ?? null;
$installed = $dep['installed_version'] ?? null;
$modifier = ['satisfied' => 'met', 'outdated' => 'outdated'][$status] ?? 'required';
// Disabled AND below the minimum: enabling alone won't satisfy it.
$tooOld = ! empty($min) && $installed !== null && $installed !== '' && version_compare((string) $installed, (string) $min, '<');
?>
<div class="modmenu-dep modmenu-callout modmenu-dep--<?= $modifier ?>">
    <i class="fa <?= $status === 'satisfied' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>" aria-hidden="true"></i>
    <span class="modmenu-dep__label">
        <?php if ($status === 'satisfied'): ?>
            <strong class="modmenu-callout__label"><?= t('Requirement met:') ?></strong> <?= $this->text->e($dep['plugin']) ?><?php if ($installed !== null && $installed !== ''): ?> <?= $this->text->e($installed) ?><?php endif ?><?php if (! empty($min)): ?> (&ge; <?= $this->text->e($min) ?>)<?php endif ?>
        <?php elseif ($status === 'outdated'): ?>
            <strong class="modmenu-callout__label"><?= t('Update needed:') ?></strong> <?= $this->text->e($dep['plugin']) ?> — <?= t('%s installed, needs ≥ %s', $this->text->e($installed), $this->text->e($min)) ?>
        <?php else: ?>
            <strong class="modmenu-callout__label"><?= $status === 'disabled' ? t('Requirement disabled:') : $missing_label ?></strong> <?= $this->text->e($dep['plugin']) ?><?php if ($status === 'disabled' && $tooOld): ?> (<?= t('%s installed, needs ≥ %s', $this->text->e($installed), $this->text->e($min)) ?>)<?php elseif (! empty($min)): ?> (&ge; <?= $this->text->e($min) ?>)<?php endif ?>
        <?php endif ?>
        <?php if (! empty($dep['reason'])): ?> — <?= $this->text->e($dep['reason']) ?><?php endif ?>
    </span>
    <?php if ($actions): ?><?= $this->render('ModMenu:settings/requirement_actions', ['dep' => $dep]) ?><?php endif ?>
</div>
