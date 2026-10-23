// Pure helper for the layout signature. browser-check.js injects its source into the page (it must stay
// self-contained: no closures, no requires); tests/js/layout-key.test.js checks it under node.
// Elements inside an auto-scrolling slider track (Splide/Swiper/Slick) sit at a time-dependent offset and toggle
// state classes as they scroll; for those, position (left/top), transform and slide-state classes are dropped,
// while size and every other computed style stay in the signature.
function layoutEntry(tag, id, classes, box, styles, inTrack, transformAt) {
  const STATE = /^(is-active|is-visible|is-next|is-prev|bricks-lazy-hidden|swiper-slide-(active|next|prev|visible|duplicate-active)|slick-(active|current|center))$/;
  const cls = (classes || []).filter(c => c && !(inTrack && STATE.test(c))).slice(0, 2);
  const key = tag + (id ? '#' + id : '') + (cls.length ? '.' + cls.join('.') : '');
  const b = inTrack ? ['~', '~', box[2], box[3]] : box;
  const st = styles.slice();
  if (inTrack && transformAt >= 0 && transformAt < st.length) st[transformAt] = '~';
  return { key, rest: b.join(',') + '|' + st.join(',') };
}
// The computed-style fields of a layout entry, in order (browser-check.js builds st from this list and passes
// STYLE_FIELDS.indexOf('transform') as transformAt, so the two cannot drift apart).
const STYLE_FIELDS = ['display', 'color', 'backgroundColor', 'fontSize', 'fontWeight', 'opacity', 'backgroundImage', 'border', 'boxShadow', 'fontFamily', 'transform', 'textTransform'];
// A header box-shadow mid-transition differs per load (rgba(0, 0, 0, 0) 0.00169027px … / 5.92711e-08px …): round px to
// whole px and turn a fully transparent shadow into none. Runs in the page BEFORE the 60-char cut (self-contained).
function normShadow(v) {
  let s = String(v || '').replace(/-?(?:\d*\.\d+|\d+)(?:e[-+]?\d+)?px/gi, m => Math.round(parseFloat(m)) + 'px');
  s = s.replace(/rgba\(\d+, \d+, \d+, 0\)(?: -?\d+(?:px)?){2,4}(?: inset)?/g, 'none');
  return /^none(?:, none)*$/.test(s) ? 'none' : s;
}
if (typeof module !== 'undefined') module.exports = { layoutEntry, normShadow, STYLE_FIELDS };
