// Run: node tests/js/cart-check.test.js  (prints "cart-check: N passed", exits 1 on failure)
const assert = require('assert');
const { cartAddError, restorePlan } = require('../../bin/cart-check.js');
let n = 0; const t = (name, fn) => { fn(); n++; };

t('add verified only when items_count increases', () => {
  assert.strictEqual(cartAddError({ items_count: 0 }, { items_count: 1 }), '');
  assert.strictEqual(cartAddError({ items_count: 1 }, { items_count: 2 }), '');
  assert.strictEqual(cartAddError({ items_count: 1 }, { items_count: 1 }), 'add to cart failed');
  assert.strictEqual(cartAddError({ items_count: 0 }, { items_count: 0 }), 'add to cart failed');
});

t('unreadable cart is a failure, never a pass', () => {
  assert.strictEqual(cartAddError(null, { items_count: 1 }), 'add to cart failed: cart unreadable');
  assert.strictEqual(cartAddError({ items_count: 0 }, {}), 'add to cart failed: cart unreadable');
  assert.strictEqual(cartAddError({ items_count: '0' }, { items_count: 1 }), 'add to cart failed: cart unreadable');
});

t('restore plan brings a pre-filled cart back to what it was', () => {
  const before = { items_count: 1, items: [{ key: 'a', quantity: 1 }] };
  assert.deepStrictEqual(restorePlan(before, { items_count: 2, items: [{ key: 'a', quantity: 2 }] }), [{ key: 'a', quantity: 1 }]);
  assert.deepStrictEqual(restorePlan(before, { items_count: 2, items: [{ key: 'a', quantity: 1 }, { key: 'b', quantity: 1 }] }), [{ key: 'b', quantity: 0 }]);
});

t('an empty cart before means nothing to restore (the flow needs the item)', () => {
  assert.deepStrictEqual(restorePlan({ items_count: 0, items: [] }, { items_count: 1, items: [{ key: 'a', quantity: 1 }] }), []);
});

console.log('cart-check: ' + n + ' passed');
