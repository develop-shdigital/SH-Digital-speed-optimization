// Lighthouse against the local replay (replay/server.mjs) with PageSpeed Insights' settings:
// simulated throttling, Moto G Power on slow 4G for mobile, the desktop profile otherwise.
// Every host except the site is unreachable (a proxy that does not exist), so third-party
// scripts, analytics and consent logs never leave the machine.
//
//   SITE=https://www.example.ch node lighthouse/run.mjs <path> <out.json> [mobile|desktop] [categories]
//   categories: comma list, default "performance" (also: accessibility, best-practices, seo)
//
// Put the page to test into work/replay/current.json first ({ "/path/": "/abs/file.html" }),
// or pass PAGE=/abs/file.html to have it written for you. Prints one JSON line with the scores.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';
import lighthouse from 'lighthouse';
import desktopConfig from 'lighthouse/core/config/desktop-config.js';

const LAB = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const [pagePath, out, ff = 'mobile', cats = 'performance'] = process.argv.slice(2);
const SITE = (process.env.SITE || '').replace(/\/$/, '');
if (!SITE || !pagePath || !out) {
  console.error('usage: SITE=https://www.example.ch node lighthouse/run.mjs <path> <out.json> [mobile|desktop] [categories]');
  process.exit(1);
}
const host = new URL(SITE).host;
const port = +(process.env.PORT || 8443);
const replayDir = process.env.REPLAY_DIR || path.join(LAB, 'work', 'replay');
if (process.env.PAGE) fs.writeFileSync(path.join(replayDir, 'current.json'), JSON.stringify({ [pagePath]: path.resolve(process.env.PAGE) }));
const chrome = process.env.CHROME_PATH || (fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined);
const browser = await puppeteer.launch({
  executablePath: chrome,
  channel: chrome ? undefined : 'chrome',
  headless: true,
  args: ['--no-sandbox', '--headless=new', `--host-resolver-rules=MAP ${host} 127.0.0.1:${port}`, '--ignore-certificate-errors', '--proxy-server=http://127.0.0.1:9', `--proxy-bypass-list=${host}`],
});
try {
  const page = await browser.newPage();
  const onlyCategories = cats.split(',').map((c) => c.trim()).filter(Boolean);
  let config;
  if (ff === 'mobile') {
    await page.emulate({ viewport: { width: 412, height: 823, deviceScaleFactor: 1.75, isMobile: true, hasTouch: true, isLandscape: false }, userAgent: 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36' });
    config = { extends: 'lighthouse:default', settings: { formFactor: 'mobile', screenEmulation: { disabled: true }, emulatedUserAgent: false, onlyCategories, disableFullPageScreenshot: true } };
  } else {
    await page.setViewport({ width: 1350, height: 940, deviceScaleFactor: 1 });
    config = { ...desktopConfig, settings: { ...desktopConfig.settings, screenEmulation: { disabled: true }, emulatedUserAgent: false, onlyCategories, disableFullPageScreenshot: true } };
  }
  const result = await lighthouse(SITE + pagePath, { output: 'json', logLevel: 'error' }, config, page);
  fs.mkdirSync(path.dirname(path.resolve(out)), { recursive: true });
  fs.writeFileSync(out, result.report);
  const a = result.lhr.audits;
  const summary = { scores: Object.fromEntries(Object.entries(result.lhr.categories).map(([k, c]) => [k, Math.round(c.score * 100)])) };
  if (a.metrics && a.metrics.details) {
    const v = (id) => Math.round(a[id].numericValue);
    Object.assign(summary, { fcp: v('first-contentful-paint'), lcp: v('largest-contentful-paint'), tbt: v('total-blocking-time'), cls: +a['cumulative-layout-shift'].numericValue.toFixed(3), si: v('speed-index') });
  }
  console.log(JSON.stringify(summary));
} finally {
  await browser.close();
}
