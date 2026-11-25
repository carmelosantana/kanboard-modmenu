import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../Assets/js/modmenu.js', import.meta.url), 'utf8');

function browse() {
  const search = { value: '', closest: () => toolbar };
  const count = { dataset: { format: 'Showing {shown} of {total}' } };
  const controls = { '#modmenu-search': search, '.modmenu-toolbar__count': count };
  const toolbar = { querySelector: selector => controls[selector] ?? null };
  const current = {
    dataset: { name: 'Harbor', search: 'harbor theme' },
    classList: { contains: name => name === 'modmenu-card' },
    hidden: false,
  };
  const deprecated = {
    ...current,
    dataset: { name: 'ShadcnTheme', search: 'shadcn theme shadcntheme', deprecated: '1' },
    hidden: true,
  };
  const list = { children: [current, deprecated], appendChild() {} };
  const elements = { '.modmenu-toolbar': toolbar, '.modmenu-cards': list };
  const listeners = new Map();
  const document = {
    readyState: 'complete',
    querySelector: selector => elements[selector] ?? null,
    addEventListener: (type, listener) => listeners.set(type, listener),
  };

  new Function('document', src)(document);
  return {
    current, deprecated, count,
    search(value) {
      search.value = value;
      listeners.get('input')({ target: search });
    },
  };
}

test('Browse only reveals deprecated plugins for an exact case-insensitive folder name', () => {
  const view = browse();
  assert.equal(view.deprecated.hidden, true, 'hidden on initialization');
  assert.equal(view.count.textContent, 'Showing 1 of 1');

  view.search('sHaDcNtHeMe');
  assert.equal(view.deprecated.hidden, false, 'shown for an exact folder name');
  assert.equal(view.count.textContent, 'Showing 1 of 2');

  view.search('shadcn');
  assert.equal(view.deprecated.hidden, true, 'hidden for a partial match');
  view.search('shadcn theme');
  assert.equal(view.deprecated.hidden, true, 'hidden for a matching title');
  view.search('');
  assert.equal(view.deprecated.hidden, true, 'hidden when search is cleared');
  view.search('har');
  assert.equal(view.current.hidden, false, 'active plugins still support partial search');
});
