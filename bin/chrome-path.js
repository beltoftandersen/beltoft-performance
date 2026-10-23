// Finds the Chrome/Chromium binary the browser checks launch. Order: explicit option (--chrome=), BP_CHROME,
// Playwright's own chromium.executablePath(), then common system paths. Pure: the caller passes what it knows.
const fs = require('fs');

const SYSTEM_PATHS = ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
const HINT = 'Pass --chrome=<path> to the wp beltoft-performance command, or set BP_CHROME=<path>.';

function resolveChrome({ opt, env = {}, pwPath, exists = fs.existsSync }) {
  const explicit = opt || env.BP_CHROME;
  if (explicit) {
    if (!exists(explicit)) throw new Error(`Chrome not found at "${explicit}". ${HINT}`);
    return explicit;
  }
  if (pwPath && exists(pwPath)) return pwPath;
  for (const p of SYSTEM_PATHS) if (exists(p)) return p;
  throw new Error(`No Chrome or Chromium found (tried Playwright's browser and ${SYSTEM_PATHS.join(', ')}). ${HINT}`);
}

// Playwright's own build path, or null when it cannot be worked out.
function playwrightPath(chromium) {
  try { return chromium.executablePath(); } catch (e) { return null; }
}

module.exports = { resolveChrome, playwrightPath, SYSTEM_PATHS };
