// Scrolls through a page like a visitor (against the local replay only) and lists images,
// CSS backgrounds and lazy sections that are on screen but still not loaded 1.5 s after they
// came into view. Run it before and after a change: the lists must match.
//
//   SITE=https://www.example.ch node browser/scroll-check.cjs <path> [mobile|desktop]
//
// Uses Playwright (installed globally in cloud sessions; elsewhere: npm i -g playwright).
// Every host except the site is blocked; REST and non-GET requests are answered locally.
const { chromium } = require('playwright');
const fs = require('fs');
const SITE = (process.env.SITE || '').replace(/\/$/, '');
const [pagePath, device = 'mobile'] = process.argv.slice(2);
if (!SITE || !pagePath) {
  console.error('usage: SITE=https://www.example.ch node browser/scroll-check.cjs <path> [mobile|desktop]');
  process.exit(1);
}
const host = new URL(SITE).host;
const port = +(process.env.PORT || 8443);
(async () => {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=MAP ${host} 127.0.0.1:${port}`], proxy: { server: 'http://127.0.0.1:9', bypass: host } });
  const desktop = device === 'desktop';
  const ctx = await browser.newContext({ viewport: desktop ? { width: 1350, height: 940 } : { width: 412, height: 823 }, isMobile: !desktop, hasTouch: !desktop, deviceScaleFactor: 1, ignoreHTTPSErrors: true });
  await ctx.route((url) => url.hostname !== host, (r) => r.abort());
  await ctx.route(`${SITE}/**`, (r) => (r.request().method() !== 'GET' || /\/wp-json\/|admin-ajax/.test(r.request().url())) ? r.fulfill({ status: 204, body: '' }) : r.continue());
  const p = await ctx.newPage();
  const resp = await p.goto(SITE + pagePath, { waitUntil: 'load', timeout: 90000 });
  const addr = await resp.serverAddr();
  if (!addr || addr.ipAddress !== '127.0.0.1') { console.log('ABORT: the page did not come from the local replay', addr); process.exit(2); }
  await p.waitForTimeout(800);
  const height = await p.evaluate(() => document.documentElement.scrollHeight);
  const vh = desktop ? 940 : 823;
  const missing = new Map();
  for (let y = 0; y < height; y += Math.round(vh * 0.8)) {
    await p.evaluate((top) => window.scrollTo(0, top), y);
    await p.waitForTimeout(1500);
    const found = await p.evaluate(() => {
      const loaded = new Set(performance.getEntriesByType('resource').map((e) => e.name));
      const out = [];
      for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect();
        if (r.bottom < 0 || r.top > innerHeight || r.width < 20 || r.height < 20) continue;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) continue;
        if (el.tagName === 'IMG') {
          if (!el.complete || el.naturalWidth === 0) out.push('img ' + (el.currentSrc || el.src).split('/').pop());
          continue;
        }
        const m = /url\(["']?([^"')]+)/.exec(cs.backgroundImage);
        if (m && !loaded.has(new URL(m[1], location.href).href)) out.push('bg ' + m[1].split('/').pop());
      }
      return out;
    });
    for (const f of found) if (!missing.has(f)) missing.set(f, y);
  }
  const rows = [...missing.entries()];
  console.log(JSON.stringify({ path: pagePath, device, height, notLoaded: rows.slice(0, 20), count: rows.length }));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
