/*! ModMenu — CSP-safe, document-delegated. */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form.modmenu-action');
        if (!form) { return; }
        var btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (btn) { btn.setAttribute('disabled', 'disabled'); }
    });

    // ── Browse toolbar: filter and re-sort the cards client-side ──────────
    // Sort rules mirror DirectoryClient::sortPlugins (the PHP tests are the source of truth).

    var ORDERS = ['updated', 'added', 'author'];

    function cmp(a, b) { return a < b ? -1 : (a > b ? 1 : 0); }

    function compare(order) {
        var field = order === 'added' ? 'added' : (order === 'author' ? 'author' : 'updated');
        function key(card) {
            var v = card.dataset[field] || '';
            return order === 'author' ? v.toLowerCase() : v;
        }
        return function (a, b) {
            var ka = key(a);
            var kb = key(b);
            var c;
            if (ka === '' || kb === '') {
                c = (ka === '' ? 1 : 0) - (kb === '' ? 1 : 0);
            } else {
                c = order === 'updated' ? cmp(kb, ka) : cmp(ka, kb);
            }
            if (c === 0) {
                c = cmp((a.dataset.title || '').toLowerCase(), (b.dataset.title || '').toLowerCase());
            }
            return c !== 0 ? c : cmp(a.dataset.name || '', b.dataset.name || '');
        };
    }

    function apply(toolbar) {
        var list = document.querySelector('.modmenu-cards');
        if (!list) { return; }
        var cards = Array.prototype.filter.call(list.children, function (el) { return el.classList.contains('modmenu-card'); });
        var search = toolbar.querySelector('#modmenu-search');
        var category = toolbar.querySelector('#modmenu-category');
        var sort = toolbar.querySelector('#modmenu-sort');
        var terms = (search ? search.value : '').toLowerCase().split(/\s+/).filter(Boolean);
        var cat = category ? category.value : 'all';
        var order = sort && ORDERS.indexOf(sort.value) !== -1 ? sort.value : ORDERS[0];
        var shown = 0;

        cards.forEach(function (card) {
            var text = card.dataset.search || '';
            var match = (cat === 'all' || card.dataset.category === cat) &&
                terms.every(function (t) { return text.indexOf(t) !== -1; });
            card.hidden = !match;
            if (match) { shown++; }
        });
        cards.sort(compare(order)).forEach(function (card) { list.appendChild(card); });

        var count = toolbar.querySelector('.modmenu-toolbar__count');
        if (count && count.dataset.format) {
            count.textContent = count.dataset.format.replace('{shown}', shown).replace('{total}', cards.length);
        }
        var empty = document.querySelector('.modmenu-empty');
        if (empty) { empty.hidden = shown !== 0; }
    }

    function onControl(e) {
        var toolbar = e.target.closest && e.target.closest('.modmenu-toolbar');
        if (toolbar) { apply(toolbar); }
    }
    document.addEventListener('input', onControl);
    document.addEventListener('change', onControl);

    function init() {
        var toolbar = document.querySelector('.modmenu-toolbar');
        if (!toolbar) { return; }
        toolbar.hidden = false;
        apply(toolbar);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
