<div class="page-header"><h2><?= t('Reset private state') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<div class="confirm">
    <p class="alert alert-info">
        <?= t('Reset drops every private source, binding and dismissal. The current state file is kept aside in the data directory, and installed plugins stay as they are. Add your private sources again afterwards.') ?>
    </p>
    <?php if ($frozen): ?>
        <p><?= t('The state file is damaged, so its contents are not shown.') ?></p>
    <?php else: ?>
        <?php if (! empty($urls)): ?>
            <p><?= t('Private sources dropped:') ?></p>
            <ul>
                <?php foreach ($urls as $url): ?><li><code><?= $this->text->e($url) ?></code></li><?php endforeach ?>
            </ul>
        <?php endif ?>
        <?php if (! empty($bound)): ?>
            <p><?= t('Plugins unbound: %s', $this->text->e(implode(', ', $bound))) ?></p>
        <?php endif ?>
    <?php endif ?>
    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'resetPrivateState', ['plugin' => 'ModMenu']) ?>">
        <?= $this->form->csrf() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-red"><?= t('Reset private state') ?></button>
            <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ModMenuController', 'sources', ['plugin' => 'ModMenu']) ?>
        </div>
    </form>
</div>
