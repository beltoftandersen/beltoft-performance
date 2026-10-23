// Usage: BP_CHECK_HEADER=<X-BP-Check header> node browser-check.js <playwright-core path> <JSON url list> [JSON cookie object name=>value] [JSON options {mode:nav|usage|flow, product_url}]
// The signed header comes through the environment, never argv (visible to every local user in ps).
// Prints {"pages":[{url,status,errors[]}],"errors":[...]} — JS errors / failed requests with the header's rule active.
const pwPath = process.argv[2] || 'playwright-core';
const { chromium } = require(pwPath);
const { resolveChrome, playwrightPath } = require('./chrome-path.js');
const header = process.env.BP_CHECK_HEADER || '';
const urls = JSON.parse(process.argv[3] || '[]');
const jar = JSON.parse(process.argv[4] || '{}');
const opts = JSON.parse(process.argv[5] || '{}');
const mode = opts.mode || 'nav';
// Layout signature: visible elements, rounded box + a few computed styles. Deterministic enough to diff;
// browser-check callers run two baselines and mask what differs between them (sliders, rotations).
const LAYOUT = () => {
  const out = []; const seen = {}; let n = 0; let truncated = false;
  const cut = (v, l) => String(v || '').slice(0, l);
  for (const el of document.querySelectorAll('html, body, body *')) {
    if (n >= 4000) { truncated = true; break; }
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    if (el.tagName !== 'HTML' && el.tagName !== 'BODY' && (r.width < 1 || r.height < 1 || cs.visibility === 'hidden' || cs.display === 'none')) continue;
    // Auto-scrolling slider/marquee slides sit at a time-dependent offset: keep them, but layoutEntry drops their position (bin/layout-key.js).
    const inTrack = !!el.closest('.splide__list, .swiper-wrapper, .slick-track');
    // Random per-render ids (form-field-xxxxxx, quantity_<13 hex>, bare hex) must not make keys differ between loads.
    const norm = v => String(v).replace(/form-field-[0-9a-f]{6}/g, 'form-field-').replace(/quantity_[0-9a-f]{13}/g, 'quantity_');
    const id = el.id && !(/^[0-9a-f]{6,}$/.test(el.id) && /[a-f]/.test(el.id)) ? norm(el.id) : '';
    const classes = typeof el.className === 'string' && el.className ? norm(el.className.trim()).split(/\s+/) : [];
    const rd = v => Math.round(v / 2) * 2;
    const val = { display: cs.display, color: cs.color, backgroundColor: cs.backgroundColor, fontSize: cs.fontSize, fontWeight: cs.fontWeight, opacity: cs.opacity,
      backgroundImage: cut(cs.backgroundImage, 80), border: cs.borderTopWidth + ' ' + cs.borderTopColor, boxShadow: cut(normShadow(cs.boxShadow), 60),
      fontFamily: (cs.fontFamily || '').split(',')[0].trim(), transform: cs.transform, textTransform: cs.textTransform };
    const st = STYLE_FIELDS.map(f => val[f]);
    for (const ps of ['::before', '::after']) { const c = getComputedStyle(el, ps).content; if (c && c !== 'none' && c !== 'normal') st.push(ps + c); }
    if (el.tagName === 'IMG') st.push((el.currentSrc || '').split('?')[0].replace(/^https?:\/\/[^/]+/, ''), el.complete ? 'complete' : 'loading');
    const e = layoutEntry(el.tagName.toLowerCase(), id, classes, [rd(r.left), rd(r.top + scrollY), rd(r.width), rd(r.height)], st, inTrack, STYLE_FIELDS.indexOf('transform'));
    const key = e.key + '@' + (seen[e.key] = (seen[e.key] === undefined ? 0 : seen[e.key] + 1));
    out.push(key + '|' + e.rest);
    n++;
  }
  return { sig: out, truncated };
};
// Evaluated as a string expression so the helper travels with it (no in-page eval; CDP evaluate).
const { layoutEntry, normShadow, STYLE_FIELDS } = require('./layout-key.js');
const LAYOUT_EXPR = '(() => { const layoutEntry = ' + layoutEntry.toString() + '; const normShadow = ' + normShadow.toString() + '; const STYLE_FIELDS = ' + JSON.stringify(STYLE_FIELDS) + '; return (' + LAYOUT.toString() + ')(); })()';
const FACTS = () => ({
  fields: [...document.querySelectorAll('form.checkout [name]')].map(e => e.getAttribute('name')).filter(n => !/nonce|_wp_http_referer/.test(n)).sort(),
  shipping: [...document.querySelectorAll('#shipping_method li label, .woocommerce-shipping-methods li label, .brx-shipping-options__label')].map(e => e.textContent.replace(/\s+/g, ' ').trim().replace(/[\d,.]+\s*€/, '').trim()).sort(),
  payment: [...document.querySelectorAll('.wc_payment_methods li input[name="payment_method"]')].map(e => e.value).sort(),
  cart_items: document.querySelectorAll('form.checkout .cart_item, .woocommerce-cart-form__cart-item, .wc-block-cart-items__row, #bricks-woo-checkout-order-summary .product-quantity').length,
});
const LISTENER_HOOK = () => {
  Error.stackTraceLimit = 50;
  window.__bpListeners = {};
  const orig = EventTarget.prototype.addEventListener;
  EventTarget.prototype.addEventListener = function (type, fn, o) {
    try {
      const urls = new Set();
      for (const l of (new Error().stack || '').split('\n')) { const m = l.match(/(https?:\/\/[^\s():]+?\.js)(?:\?[^\s):]*)?(?::\d+){0,2}/); if (m && !/jquery(?:-migrate)?(?:\.min)?\.js|browser-check/.test(m[1])) urls.add(m[1]); }
      for (const u of urls) window.__bpListeners[u] = (window.__bpListeners[u] || 0) + 1;
    } catch (e) {}
    return orig.call(this, type, fn, o);
  };
};
const strip = u => u.split('?')[0];
const { cartAddError, restorePlan } = require('./cart-check.js');
// The Store API cart as this browser's session sees it (cookies included), or null when unreadable.
const readCart = p => p.evaluate(async () => {
  try {
    const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'include', headers: { Accept: 'application/json' } });
    if (!r.ok) return null;
    const j = await r.json();
    return { items_count: j.items_count, items: (j.items || []).map(i => ({ key: i.key, quantity: i.quantity })), nonce: r.headers.get('Nonce') || '' };
  } catch (e) { return null; }
}).catch(() => null);
(async () => {
  let exe;
  try {
    exe = resolveChrome({ opt: opts.chrome, env: process.env, pwPath: playwrightPath(chromium) });
  } catch (e) { console.error(e.message); process.exit(1); }
  const b = await chromium.launch({ executablePath: exe });
  const out = { pages: [], errors: [] };
  for (const url of urls) {
    for (const vp of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
      const ctx = await b.newContext({ viewport: vp });
      const origin = new URL(url).origin;
      const host = new URL(url).hostname;
      // The signed header goes to the site's own origin only: never to third parties (no leak, no CORS preflight).
      await ctx.route('**/*', route => {
        const req = route.request();
        let same = false;
        try { same = new URL(req.url()).origin === origin; } catch (e) { same = false; }
        return same ? route.continue({ headers: { ...req.headers(), 'x-bp-check': header } }) : route.continue();
      });
      const ck = Object.entries(jar).map(([name, value]) => ({ name, value: String(value), domain: host, path: '/' }));
      if (ck.length) await ctx.addCookies(ck);
      const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push('pageerror: ' + e.message));
      p.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text()); });
      p.on('requestfailed', r => errs.push('requestfailed: ' + r.url()));
      const u = url + (url.includes('?') ? '&' : '?') + 'bp_check=browser';
      let flowError = '';
      if (mode === 'usage') {
        await p.addInitScript(LISTENER_HOOK);
        await p.coverage.startCSSCoverage({ resetOnNavigation: false });
        await p.coverage.startJSCoverage({ resetOnNavigation: false, reportAnonymousScripts: false });
      }
      if (mode === 'flow' && opts.product_url) {
        // Every flow job (guest, cart, logged_in) adds the product on its product page under this job's header, and
        // proves it through the Store API cart count. Never proceeds past the checkout form, never submits an order.
        try {
          await p.goto(opts.product_url + (opts.product_url.includes('?') ? '&' : '?') + 'bp_check=browser', { waitUntil: 'networkidle', timeout: 45000 });
          const before = await readCart(p);
          const btn = await p.$('form.cart .single_add_to_cart_button');
          if (!btn) flowError = 'no add-to-cart button';
          else {
            await btn.click();
            await p.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
            await p.waitForTimeout(1500);
            const after = await readCart(p);
            flowError = cartAddError(before, after);
            // A cart that already had items (the shared check session) goes back to what it was.
            let nonce = after && after.nonce;
            for (const upd of flowError ? [] : restorePlan(before, after)) {
              const res = await p.evaluate(async ({ upd, nonce }) => {
                const path = upd.quantity ? 'update-item' : 'remove-item';
                const r = await fetch('/wp-json/wc/store/v1/cart/' + path, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Nonce: nonce || '' }, body: JSON.stringify(upd.quantity ? upd : { key: upd.key }) });
                return { ok: r.ok, nonce: r.headers.get('Nonce') || '' };
              }, { upd, nonce }).catch(() => ({ ok: false }));
              if (!res.ok) { flowError = 'cart restore failed'; break; }
              nonce = res.nonce || nonce;
            }
          }
        } catch (e) { flowError = 'add to cart: ' + e.message; }
      }
      const r = await p.goto(u, { waitUntil: 'networkidle', timeout: 45000 }).catch(e => { errs.push('goto: ' + e.message); return null; });
      if (mode === 'flow' && !flowError && await p.$('form.checkout')) {
        // PT test address so shipping methods render. Never submits: no place-order click.
        try {
          await p.evaluate(() => {
            const set = (n, v) => { const e = document.querySelector('[name="' + n + '"]'); if (e) { e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); } };
            const diff = document.querySelector('#ship-to-different-address-checkbox');
            const pre = diff && diff.checked ? ['billing', 'shipping'] : ['billing'];
            for (const x of pre) { set(x + '_country', 'PT'); set(x + '_postcode', '1000-001'); set(x + '_city', 'Lisboa'); set(x + '_address_1', 'Rua de Teste 1'); }
            if (window.jQuery) window.jQuery(document.body).trigger('update_checkout');
          });
          await p.waitForTimeout(500);
          await p.waitForSelector('#shipping_method li, .woocommerce-shipping-methods li, .brx-shipping-options__option', { state: 'attached', timeout: 15000 }).catch(() => { flowError = 'no shipping methods'; });
          await p.waitForFunction(() => !document.querySelector('.blockUI'), null, { timeout: 15000 }).catch(() => {});
        } catch (e) { flowError = 'checkout fill: ' + e.message; }
      }
      await p.waitForTimeout(800); await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {});
      await p.waitForTimeout(800); await p.evaluate(() => window.scrollTo(0, 0)).catch(() => {});
      await p.waitForTimeout(300);
      let layout = [], layoutError = '', layoutTruncated = false;
      try { const lr = await p.evaluate(LAYOUT_EXPR); layout = lr.sig; layoutTruncated = lr.truncated; } catch (e) { layoutError = 'layout: ' + e.message; }
      if (!layout.length && !layoutError) layoutError = 'layout empty';
      const page = { url, final_url: (() => { try { return new URL(p.url()).pathname; } catch (e) { return ''; } })(), viewport: vp.width, status: r ? r.status() : 0, errors: errs, layout, layout_truncated: layoutTruncated, layout_error: layoutError };
      if (mode === 'usage') {
        const css = await p.coverage.stopCSSCoverage(); const js = await p.coverage.stopJSCoverage();
        const listeners = await p.evaluate(() => window.__bpListeners || {}).catch(() => ({}));
        page.coverage = {
          styles: css.filter(e => e.url && e.url.startsWith('http')).map(e => ({ url: strip(e.url), used: e.ranges.reduce((a, x) => a + (x.end - x.start), 0), total: (e.text || '').length })),
          scripts: js.filter(e => e.url && e.url.startsWith('http')).map(e => {
            // Skip only the top-level entry (range covers the whole script); count every other function that ran, anonymous included.
            const len = (e.source || '').length;
            const fns = e.functions || [];
            const top = fns.findIndex(f => f.ranges.length && f.ranges[0].startOffset === 0 && (!len || f.ranges[0].endOffset >= len - 1));
            const rest = fns.filter((f, i) => i !== top);
            return { url: strip(e.url), called: rest.filter(f => f.ranges.some(x => x.count > 0)).length, top_ran: top >= 0 ? fns[top].ranges.some(x => x.count > 0) : false,
              listeners: Object.entries(listeners).filter(([lu]) => strip(lu) === strip(e.url)).reduce((a, [, c]) => a + c, 0) };
          }),
        };
      }
      if (mode === 'flow') {
        page.facts = await p.evaluate(FACTS).catch(e => { flowError = flowError || 'facts: ' + e.message; return { fields: [], shipping: [], payment: [], cart_items: 0 }; });
        page.flow_error = flowError;
      }
      out.pages.push(page);
      out.errors.push(...errs.map(e => vp.width + ' ' + url + ' ' + e));
      await ctx.close();
    }
  }
  await b.close();
  process.stdout.write(JSON.stringify(out));
})().catch(e => { process.stdout.write(JSON.stringify({ pages: [], errors: ['fatal: ' + e.message] })); });
