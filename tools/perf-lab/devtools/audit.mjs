// The web-perf skill's audit for one page: a performance trace with every insight analyzed,
// the largest element (LCP) and the first screen, plus on desktop the network requests, the
// accessibility snapshot and console errors.
//
//   node devtools/audit.mjs <url> [mobile|desktop]
//
// Mobile: Slow 4G, 4x CPU slowdown, 412x823 phone. This loads the real page in Chrome:
// third-party analytics on that page count a visit. For before/after experiments use the
// local replay (replay/server.mjs) and lighthouse/run.mjs instead.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { connect } from './client.mjs';

const [url, mode = 'mobile'] = process.argv.slice(2);
if (!url) {
  console.error('usage: node devtools/audit.mjs <url> [mobile|desktop]');
  process.exit(1);
}
const DIR = path.dirname(fileURLToPath(import.meta.url));
const MOBILE_UA = 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const { call, close } = await connect(mode === 'desktop' ? ['--viewport=1350x940'] : []);
const pages = await call('list_pages', {}, { print: 0 });
const pageId = Number((pages.match(/^\s*(\d+):/m) || [])[1] ?? 1);
if (mode === 'mobile') {
  await call('emulate', { pageId, networkConditions: 'Slow 4G', cpuThrottlingRate: 4, userAgent: MOBILE_UA, viewport: '412x823x1.75,mobile,touch' }, { print: 300 });
}
await call('navigate_page', { pageId, url, timeout: 120000 }, { print: 300 });
const trace = await call('performance_start_trace', { pageId, reload: true, autoStop: true }, { print: 1800, tag: mode });
const setId = (trace.match(/insight set id: (\S+)/) || [])[1];
const insights = [...trace.matchAll(/insight name: (\w+)/g)].map((m) => m[1]);
console.log(`\n>>> insight set ${setId}: ${insights.join(', ')}`);
for (const name of insights) {
  await call('performance_analyze_insight', { pageId, insightSetId: setId, insightName: name }, { print: 2600, tag: `${mode}-${name}` });
}
await call('evaluate_script', { pageId, function: fs.readFileSync(path.join(DIR, 'lcp-probe.js'), 'utf8') }, { print: 7000, tag: `${mode}-lcp-probe` });
if (mode === 'desktop') {
  await call('list_network_requests', { pageId, pageSize: 300 }, { print: 0, tag: 'desktop-all' });
  await call('take_snapshot', { pageId, verbose: true }, { print: 0, tag: 'desktop-a11y' });
  await call('list_console_messages', { pageId, types: ['error', 'warn'] }, { print: 2500, tag: 'desktop' });
}
await close();
