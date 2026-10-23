// Pure helpers for the flow's add-to-cart step (Store API cart objects: { items_count, items: [{ key, quantity }] }).
// tests/js/cart-check.test.js checks them under node.

// '' when the add is proven (items_count went up), else the flow error. An unreadable cart never passes.
function cartAddError(before, after) {
  if (!before || !after || typeof before.items_count !== 'number' || typeof after.items_count !== 'number') return 'add to cart failed: cart unreadable';
  return after.items_count > before.items_count ? '' : 'add to cart failed';
}

// Updates that bring a cart that already had items (the shared check session) back to what it was, so the
// session the server-side trials reuse never grows. An empty cart before: nothing (the flow needs the item).
function restorePlan(before, after) {
  if (!before || !before.items_count) return [];
  const was = {};
  for (const i of before.items || []) was[i.key] = i.quantity;
  const out = [];
  for (const i of (after && after.items) || []) {
    const q = was[i.key] === undefined ? 0 : was[i.key];
    if (q !== i.quantity) out.push({ key: i.key, quantity: q });
  }
  return out;
}

if (typeof module !== 'undefined') module.exports = { cartAddError, restorePlan };
