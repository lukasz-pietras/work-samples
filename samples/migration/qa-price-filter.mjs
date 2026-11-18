// Price filter QA (catalog drawer, Woo price slider): node tools/qa-price-filter.mjs <site-url> <webkit|chromium|chromium-desktop|firefox> [shots-dir]
// Checks that each thumb sits on the track and is what a finger hits (44px target, clear of the price fields), that
// dragging a thumb (CDP touch in "chromium", mouse elsewhere) applies min_price/max_price, that a tapped price field
// shows the bare number fully selected and a typed price is applied, and that the drawer fits a (simulated) on-screen
// keyboard. Only GET navigation: safe against production. Exits non-zero on any failure.
// Uses Playwright (WebKit needs Ubuntu libs, so run it in the official image), e.g. from the repo root:
//   d=$(mktemp -d) && npm --prefix "$d" i --no-save playwright@1.56.1 && cp tools/qa-price-filter.mjs "$d"
//   podman run --rm --ipc=host --network=host -v "$d":/work:Z -w /work mcr.microsoft.com/playwright:v1.56.1-noble \
//     node qa-price-filter.mjs https://lunae.pl webkit /work
import { webkit, chromium, firefox, devices } from 'playwright';

const base = process.argv[2];
const mode = process.argv[3];
const outdir = process.argv[4] || '.';
const path = '/kategoria-produktu/bielizna/';
const engines = { webkit, chromium, 'chromium-desktop': chromium, firefox };
const mobile = mode === 'webkit' || mode === 'chromium';
const ctxOpts = mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1440, height: 900 } };
if (mode === 'chromium') delete ctxOpts.defaultBrowserType;

const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} [${mode}] ${name}${detail ? ' — ' + detail : ''}`); };

const browser = await engines[mode].launch();
const ctx = await browser.newContext(ctxOpts);
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));

async function openDrawer(url = base + path) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.querySelectorAll('.lunae-consent').forEach((e) => e.remove()));
  await page.locator('[data-lunae-filters-open]').first().click();
  await page.waitForFunction(() => document.querySelector('#lunae-filters-dialog')?.open);
  await page.waitForTimeout(400);
  await page.locator('#lunae-filters-dialog .wc-block-product-filter-price-slider').scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
}

// Geometry of the slider in viewport coordinates, plus where each thumb centre should be.
const geometry = () => page.evaluate(() => {
  const root = document.querySelector('#lunae-filters-dialog .wc-block-product-filter-price-slider');
  const bar = root.querySelector('.range-bar').getBoundingClientRect();
  const min = root.querySelector('input[type=range].min'); const max = root.querySelector('input[type=range].max');
  const lo = +min.min, hi = +min.max;
  const x = (v) => bar.left + (v - lo) / (hi - lo) * bar.width;
  const y = bar.top + bar.height / 2;
  const tMin = root.querySelector('input[type=text].min').getBoundingClientRect();
  const tMax = root.querySelector('input[type=text].max').getBoundingClientRect();
  const hit = (px, py) => { const el = document.elementFromPoint(px, py); return el ? (el.type === 'range' ? 'range.' + el.className : `${el.tagName.toLowerCase()}${el.type ? '[' + el.type + ']' : ''}.${el.className}`) : 'none'; };
  const cs = getComputedStyle(root.querySelector('input[type=text].min'));
  return {
    lo, hi, minVal: +min.value, maxVal: +max.value, y, bar: { left: bar.left, right: bar.right, width: bar.width, top: bar.top },
    minX: x(+min.value), maxX: x(+max.value),
    textTop: Math.min(tMin.top, tMax.top),
    rangeHeight: getComputedStyle(min).height, rangeMinHeight: getComputedStyle(min).minHeight,
    hits: {
      minCentre: hit(x(+min.value), y), maxCentre: hit(x(+max.value), y),
      minBelow18: hit(x(+min.value), y + 18), maxBelow18: hit(x(+max.value), y + 18),
      minAbove18: hit(x(+min.value), y - 18), maxRight14: hit(x(+max.value) + 14, y),
    },
    text: { fontSize: cs.fontSize, inputmode: root.querySelector('input[type=text].min').getAttribute('inputmode'), width: tMin.width, value: root.querySelector('input[type=text].min').value },
    dialogScrollW: document.querySelector('#lunae-filters-dialog').scrollWidth, dialogClientW: document.querySelector('#lunae-filters-dialog').clientWidth,
  };
});

async function drag(fromX, toX, y) {
  if (mode === 'chromium') {
    const cdp = await ctx.newCDPSession(page);
    const tp = (x) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 10, radiusY: 10, force: 1 }];
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: tp(fromX) });
    for (let i = 1; i <= 12; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: tp(fromX + (toX - fromX) * i / 12) }); await page.waitForTimeout(16); }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  } else {
    await page.mouse.move(fromX, y); await page.mouse.down();
    for (let i = 1; i <= 12; i++) { await page.mouse.move(fromX + (toX - fromX) * i / 12, y); await page.waitForTimeout(16); }
    await page.mouse.up();
  }
}

// ---- 1. geometry + hit testing
await openDrawer();
let g = await geometry();
await page.locator('#lunae-filters-dialog .wc-block-product-filter-price-slider').screenshot({ path: `${outdir}/${mode}-slider.png` });
check('range inputs are not stretched by the 44px field rule', g.rangeMinHeight === '0px' || g.rangeMinHeight === 'auto', `min-height ${g.rangeMinHeight}, height ${g.rangeHeight}`);
check('finger on min thumb centre hits the min slider', g.hits.minCentre === 'range.min', g.hits.minCentre);
check('finger on max thumb centre hits the max slider', g.hits.maxCentre === 'range.max', g.hits.maxCentre);
check('18px below a thumb still grabs it (44px target)', g.hits.minBelow18.startsWith('range') && g.hits.maxBelow18.startsWith('range'), `${g.hits.minBelow18} / ${g.hits.maxBelow18}`);
check('18px above a thumb still grabs it', g.hits.minAbove18.startsWith('range'), g.hits.minAbove18);
check('thumb touch area ends above the price fields', g.y + 22 <= g.textTop, `track y ${g.y.toFixed(1)}, fields top ${g.textTop.toFixed(1)}`);
check('price fields: 16px text (no iOS zoom) + numeric keypad', g.text.fontSize === '16px' && g.text.inputmode === 'numeric', `${g.text.fontSize}, inputmode=${g.text.inputmode}, width ${g.text.width}`);
check('no horizontal overflow in the drawer', g.dialogScrollW <= g.dialogClientW, `${g.dialogScrollW} vs ${g.dialogClientW}`);
check('slider shows the full range initially', g.minVal === g.lo && g.maxVal === g.hi, `${g.minVal}–${g.maxVal} of ${g.lo}–${g.hi}`);

// ---- 2. drag the min thumb to ~60% → filter applied on release
const target = g.bar.left + g.bar.width * 0.6;
const nav = page.waitForURL(/min_price=\d+/, { timeout: 8000 }).then(() => true).catch(() => false);
await drag(g.minX, target, g.y);
const navigated = await nav;
const url = new URL(page.url());
const minParam = +url.searchParams.get('min_price');
const expected = g.lo + 0.6 * (g.hi - g.lo);
check(`dragging the min thumb applies a min price (${mode === 'chromium' ? 'touch' : 'mouse'})`, navigated && Math.abs(minParam - expected) <= (g.hi - g.lo) * 0.08, `url min_price=${minParam}, expected ≈${expected.toFixed(0)}`);

// ---- 3. drag the max thumb left on the filtered page
await openDrawer(page.url());
g = await geometry();
check('after reload the min thumb shows the applied price', Math.abs(g.minVal - minParam) <= 1, `slider min ${g.minVal}`);
const nav2 = page.waitForURL(/max_price=\d+/, { timeout: 8000 }).then(() => true).catch(() => false);
await drag(g.maxX, g.bar.left + g.bar.width * 0.85, g.y);
const nav2ok = await nav2;
const maxParam = +new URL(page.url()).searchParams.get('max_price');
check('dragging the max thumb applies a max price', nav2ok && maxParam < g.hi && maxParam > minParam, `max_price=${maxParam}`);

// ---- 4. typing a price
await page.goto(base + path, { waitUntil: 'networkidle' });
await openDrawer();
const field = page.locator('#lunae-filters-dialog .wc-block-product-filter-price-slider input[type=text].min');
if (mobile) await field.tap(); else await field.click();
await page.waitForTimeout(150);
const focused = await field.evaluate((el) => ({ value: el.value, start: el.selectionStart, end: el.selectionEnd, active: document.activeElement === el }));
check('tapping the min field shows the bare number, fully selected', focused.active && /^\d+$/.test(focused.value) && focused.start === 0 && focused.end === focused.value.length, JSON.stringify(focused));
await page.keyboard.press('Tab');
await page.waitForTimeout(150);
const restored = await field.inputValue();
check('leaving it unchanged restores the formatted price', /\d+\s*zł/.test(restored), JSON.stringify(restored));
if (mobile) await field.tap(); else await field.click();
await page.waitForTimeout(150);
const typed = String(Math.round(g.lo + (g.hi - g.lo) / 2));
const nav3 = page.waitForURL(new RegExp(`min_price=${typed}`), { timeout: 8000 }).then(() => true).catch(() => false);
await page.keyboard.type(typed, { delay: 80 });
check(`typing ${typed} applies min_price=${typed}`, await nav3, page.url().replace(base, ''));

// ---- 5. keyboard: dialog fits the visible area and keeps the field centred (visualViewport simulated)
if (mode !== 'firefox') {
  await page.goto(base + path, { waitUntil: 'networkidle' });
  await openDrawer();
  await page.evaluate(() => document.querySelector('#lunae-filters-dialog').scrollTop = 0);
  const kb = await page.evaluate(async () => {
    const dialog = document.querySelector('#lunae-filters-dialog');
    const field = dialog.querySelector('.wc-block-product-filter-price-slider input[type=text].max');
    const vv = window.visualViewport; const visible = Math.round(window.innerHeight * 0.55);
    const proto = Object.getPrototypeOf(vv); const orig = Object.getOwnPropertyDescriptor(proto, 'height');
    Object.defineProperty(vv, 'height', { configurable: true, get: () => visible });
    field.focus({ preventScroll: true });
    vv.dispatchEvent(new Event('resize'));
    await new Promise((r) => setTimeout(r, 400));
    const box = field.getBoundingClientRect(); const frame = dialog.getBoundingClientRect();
    const fitted = { height: dialog.style.height, top: dialog.style.top, fieldTop: Math.round(box.top), fieldBottom: Math.round(box.bottom), frameTop: Math.round(frame.top), frameBottom: Math.round(frame.bottom), visible };
    delete vv.height; if (orig) Object.defineProperty(proto, 'height', orig);
    field.blur();
    vv.dispatchEvent(new Event('resize'));
    await new Promise((r) => setTimeout(r, 100));
    fitted.after = dialog.style.height || '(unset)';
    return fitted;
  });
  check('keyboard up: dialog fits the visible area', kb.height === `${kb.visible}px`, `height ${kb.height}, top ${kb.top}`);
  check('keyboard up: the focused price field is inside the visible dialog', kb.fieldTop >= kb.frameTop && kb.fieldBottom <= kb.frameTop + kb.visible, `field ${kb.fieldTop}–${kb.fieldBottom}, visible ${kb.frameTop}–${kb.frameTop + kb.visible}`);
  check('keyboard down: dialog returns to full height', kb.after === '(unset)', kb.after);
}

// ---- 6. regression: other fields keep their 44px touch height
await page.goto(base + '/?s=figi&post_type=product', { waitUntil: 'networkidle' });
const others = await page.evaluate(() => [...document.querySelectorAll('input[type=search], input[type=email], input[type=text]:not(.wc-block-product-filter-price-slider input)')].slice(0, 4).map((i) => `${i.type}:${getComputedStyle(i).minHeight}`));
check('other text fields keep min-height 44px', others.length && others.every((s) => s.endsWith('44px')), others.join(', '));

check('no JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
const failed = results.filter((r) => !r.ok).length;
console.log(`== ${mode}: ${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
