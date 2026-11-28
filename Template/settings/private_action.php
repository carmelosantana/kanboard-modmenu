<form method="post" class="modmenu-action" style="display:inline"
      action="<?= $this->url->href('ModMenuController', $action, ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
    <?php if (isset($source_id)): ?>
        <input type="hidden" name="source_id" value="<?= $this->text->e($source_id) ?>">
    <?php endif ?>
    <button type="submit" class="<?= $class ?>"><?= $label ?></button>
</form>
