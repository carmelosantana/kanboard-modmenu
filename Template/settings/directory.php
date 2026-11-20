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
    <?php $total = count($plugins) ?>
    <div class="modmenu-toolbar" hidden>
        <div class="modmenu-toolbar__field modmenu-toolbar__field--search">
            <label for="modmenu-search" class="modmenu-sr-only"><?= t('Search plugins') ?></label>
            <input type="search" id="modmenu-search" class="modmenu-toolbar__search" placeholder="<?= t('Search plugins') ?>" autocomplete="off">
        </div>
        <div class="modmenu-toolbar__field">
            <label for="modmenu-category"><?= t('Category') ?></label>
            <select id="modmenu-category" class="modmenu-toolbar__category">
                <option value="all"><?= t('All categories (%d)', $total) ?></option>
                <?php foreach ($category_counts ?? [] as $category => $n): ?>
                <option value="<?= $this->text->e((string) $category) ?>"><?= $this->text->e((string) $category) ?> (<?= (int) $n ?>)</option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="modmenu-toolbar__field">
            <label for="modmenu-sort"><?= t('Sort by') ?></label>
            <select id="modmenu-sort" class="modmenu-toolbar__sort">
                <option value="updated" selected><?= t('Last updated') ?></option>
                <option value="added"><?= t('First submitted') ?></option>
                <option value="author"><?= t('Author') ?></option>
            </select>
        </div>
        <p class="modmenu-toolbar__count" aria-live="polite" data-format="<?= $this->text->e(t('Showing %s of %s', '{shown}', '{total}')) ?>"><?= t('Showing %s of %s', $total, $total) ?></p>
    </div><!-- /modmenu-toolbar -->
    <p class="modmenu-empty" hidden><?= t('No plugins match.') ?></p>

    <div class="modmenu-cards">
    <?php foreach ($plugins as $p): ?>
        <?php
            $cardTitle = (string) ($p['title'] ?? $p['name']);
            $cardSearch = mb_strtolower(implode(' ', array_filter(array_map('strval', [
                $cardTitle, $p['name'] ?? '', $p['description'] ?? '', $p['author'] ?? '', $p['category'] ?? '',
            ]), 'strlen')));
        ?>
        <div class="modmenu-card" data-title="<?= $this->text->e($cardTitle) ?>" data-search="<?= $this->text->e($cardSearch) ?>" data-category="<?= $this->text->e((string) ($p['category'] ?? '')) ?>" data-author="<?= $this->text->e((string) ($p['author'] ?? '')) ?>" data-added="<?= $this->text->e((string) ($p['added'] ?? '')) ?>" data-updated="<?= $this->text->e((string) ($p['last_updated'] ?? '')) ?>">
            <strong><?= $this->text->e($cardTitle) ?></strong>
            <?php if (! empty($p['category'])): ?>
                <span class="modmenu-category"><?= $this->text->e($p['category']) ?></span>
            <?php endif ?>
            <?php if ($p['status'] === 'update'): ?>
                <span class="modmenu-badge modmenu-badge--update"><?= t('Update to %s', $this->text->e($p['version'])) ?></span>
            <?php elseif ($p['status'] === 'installed'): ?>
                <span class="modmenu-badge modmenu-badge--installed"><?= t('Installed') ?></span>
            <?php elseif ($p['status'] === 'disabled'): ?>
                <span class="modmenu-badge modmenu-badge--disabled"><?= t('Disabled') ?></span>
            <?php endif ?>

            <?php if (! empty($p['requires_state']) || ! empty($p['conflicts'])): ?>
            <div class="modmenu-card__alerts">
            <?php foreach ($p['requires_state'] ?? [] as $dep): ?>
                <?= $this->render('ModMenu:settings/requirement', ['dep' => $dep, 'actions' => false, 'missing_label' => t('Requires:')]) ?>
            <?php endforeach ?>
            <?php if (! empty($p['conflicts'])): ?>
                <div class="modmenu-dep modmenu-callout modmenu-dep--conflict">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <span><strong class="modmenu-callout__label"><?= t('Conflict.') ?></strong>
                    <?= t('Conflicts with %s: enable only one.', $this->text->e(implode(', ', $p['conflicts']))) ?></span>
                </div>
            <?php endif ?>
            </div>
            <?php endif ?>

            <div class="modmenu-card__status">v<?= $this->text->e($p['version']) ?><?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?><?php if (! empty($p['last_updated'])): ?> · <?= t('Updated %s', $this->text->e($p['last_updated'])) ?><?php endif ?></div>
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
    </div>
<?php endif ?>
