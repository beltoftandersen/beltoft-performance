// Run: node tests/js/layout-key.test.js  (prints "layout-key: N passed", exits 1 on failure)
const assert = require('assert');
const { layoutEntry, normShadow, STYLE_FIELDS } = require('../../bin/layout-key.js');
const fs = require('fs');
let n = 0; const t = (name, fn) => { fn(); n++; };
const STY = ['flex', 'rgb(0, 0, 0)', 'none', '15px']; // transform at index 2

t('outside a track: position, transform and all classes kept', () => {
  const a = layoutEntry('div', 'x', ['card', 'is-active'], [10, 20, 300, 40], STY, false, 2);
  assert.strictEqual(a.key, 'div#x.card.is-active');
  assert.strictEqual(a.rest, '10,20,300,40|flex,rgb(0, 0, 0),none,15px');
});

t('inside a track: same slide at another scroll offset gives the same entry', () => {
  const a = layoutEntry('div', 's1', ['brxe-block', 'splide__slide', 'is-active'], [-422, 6, 360, 16], ['block', 'rgb(1, 1, 1)', 'matrix(1, 0, 0, 1, -422, 0)', '13px'], true, 2);
  const b = layoutEntry('div', 's1', ['brxe-block', 'bricks-lazy-hidden', 'splide__slide'], [-642, 6, 360, 16], ['block', 'rgb(1, 1, 1)', 'matrix(1, 0, 0, 1, -642, 0)', '13px'], true, 2);
  assert.deepStrictEqual(a, b);
  assert.strictEqual(a.key, 'div#s1.brxe-block.splide__slide');
});

t('inside a track: size change still differs', () => {
  const a = layoutEntry('div', 's1', ['splide__slide'], [0, 0, 360, 16], STY, true, 2);
  const b = layoutEntry('div', 's1', ['splide__slide'], [0, 0, 360, 40], STY, true, 2);
  assert.notDeepStrictEqual(a, b);
});

t('inside a track: colour or font change (removed stylesheet) still differs', () => {
  const a = layoutEntry('a', '', ['card'], [0, 0, 300, 40], ['block', 'rgb(0, 0, 0)', 'none', '15px'], true, 2);
  const c = layoutEntry('a', '', ['card'], [0, 0, 300, 40], ['block', 'rgb(255, 0, 0)', 'none', '15px'], true, 2);
  const f = layoutEntry('a', '', ['card'], [0, 0, 300, 40], ['block', 'rgb(0, 0, 0)', 'none', '18px'], true, 2);
  assert.notDeepStrictEqual(a, c);
  assert.notDeepStrictEqual(a, f);
});

t('source is self-contained (injectable into the page)', () => {
  const fn = new Function('return (' + layoutEntry.toString() + ')')();
  assert.deepStrictEqual(fn('p', '', [], [1, 2, 3, 4], STY, true, 2), layoutEntry('p', '', [], [1, 2, 3, 4], STY, true, 2));
});

t('transformAt matches the style order browser-check.js builds', () => {
  assert.ok(Array.isArray(STYLE_FIELDS));
  assert.strictEqual(STYLE_FIELDS.indexOf('transform'), 10);
  const src = fs.readFileSync(__dirname + '/../../bin/browser-check.js', 'utf8');
  assert.ok(/STYLE_FIELDS\.map\(/.test(src), 'st is built from STYLE_FIELDS');
  assert.ok(/STYLE_FIELDS\.indexOf\('transform'\)/.test(src), 'transformAt comes from STYLE_FIELDS');
});

t('box-shadow normalised before the 60-char cut', () => {
  assert.strictEqual(normShadow('rgba(0, 0, 0, 0) 0.00169027px 0.00169027px 1.5px 0px'), 'none');
  assert.strictEqual(normShadow('rgba(0, 0, 0, 0) 5.92711e-08px 5.92711e-08px 0px 0px'), 'none');
  assert.strictEqual(normShadow('rgba(0, 0, 0, 0.1) 1.4px 1.6px 10.2px 0px'), 'rgba(0, 0, 0, 0.1) 1px 2px 10px 0px');
  assert.strictEqual(normShadow('none'), 'none');
  // a long two-layer shadow whose tail used to be cut mid-number: normalised first, so the cut text is stable
  const a = normShadow('rgba(0, 0, 0, 0.1) 0.000123px 1.00001px 10px 0px, rgba(0, 0, 0, 0.2) 0px 2.4px 4px 0px');
  const b = normShadow('rgba(0, 0, 0, 0.1) 0.000456px 0.99998px 10px 0px, rgba(0, 0, 0, 0.2) 0px 2.4px 4px 0px');
  assert.strictEqual(a, b);
});

t('normShadow is self-contained (injectable into the page)', () => {
  const fn = new Function('return (' + normShadow.toString() + ')')();
  assert.strictEqual(fn('rgba(0, 0, 0, 0) 1px 1px 0px'), 'none');
});

console.log('layout-key: ' + n + ' passed');
