const test = require('node:test');
const assert = require('node:assert');
const { resolveChrome, SYSTEM_PATHS } = require('../../bin/chrome-path.js');

const has = (...ps) => p => ps.includes(p);

test('explicit option wins over everything', () => {
  const r = resolveChrome({ opt: '/x/opt', env: { BP_CHROME: '/x/env' }, pwPath: '/x/pw', exists: has('/x/opt', '/x/env', '/x/pw', '/usr/bin/chromium') });
  assert.strictEqual(r, '/x/opt');
});
test('env BP_CHROME next', () => {
  assert.strictEqual(resolveChrome({ env: { BP_CHROME: '/x/env' }, pwPath: '/x/pw', exists: has('/x/env', '/x/pw') }), '/x/env');
});
test('playwright path when it exists', () => {
  assert.strictEqual(resolveChrome({ env: {}, pwPath: '/x/pw', exists: has('/x/pw', '/usr/bin/chromium') }), '/x/pw');
});
test('playwright path that is missing is skipped, system paths in order', () => {
  assert.strictEqual(resolveChrome({ env: {}, pwPath: '/x/missing', exists: has('/usr/bin/chromium', '/usr/bin/chromium-browser') }), '/usr/bin/chromium');
  assert.deepStrictEqual(SYSTEM_PATHS, ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser']);
});
test('an explicit path that does not exist is an error, not a silent fallback', () => {
  assert.throws(() => resolveChrome({ opt: '/x/nope', env: {}, exists: has('/usr/bin/chromium') }), /--chrome=.*BP_CHROME|BP_CHROME.*--chrome=/);
});
test('nothing found names --chrome= and BP_CHROME', () => {
  assert.throws(() => resolveChrome({ env: {}, pwPath: null, exists: () => false }), /--chrome=.*BP_CHROME/);
});
