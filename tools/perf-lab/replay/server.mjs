// Local HTTPS/HTTP2 stand-in for the live site, for before/after measurements without
// touching the real site's visitors, analytics or forms.
//
//   SITE=https://www.example.ch node replay/server.mjs
//
// - Pages under test come from local HTML files listed in work/replay/current.json:
//   { "/path/": "/abs/file.html" } (write it before each run; tools here do that).
// - LOCAL_MAP (JSON) serves URL prefixes from local folders, for example the plugin under
//   development and the CSS files it writes:
//   [["/wp-content/plugins/shd-performance/", "/repo/shd-performance/"],
//    ["/wp-content/uploads/shd-performance/", "/work/mirror/uploads/shd-performance/"]]
// - Static files (wp-content, wp-includes; css, js, images, fonts, video) are fetched once
//   from the live site and replayed from work/replay/cache. Nothing else is ever fetched:
//   other pages answer 404, the REST API and every non-GET request 204.
// - Text is gzip-compressed like a typical server; DOC_DELAY/STATIC_DELAY (ms) imitate a
//   cached site's response times.
// Browsers reach it with --host-resolver-rules="MAP <host> 127.0.0.1:<PORT>" and must
// ignore certificate errors (self-signed, see make-cert.sh).
import http2 from 'node:http2';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const LAB = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SITE = process.env.SITE;
if (!SITE || !/^https?:\/\/[^/]+$/.test(SITE.replace(/\/$/, ''))) {
  console.error('Set SITE to the live origin, for example SITE=https://www.example.ch');
  process.exit(1);
}
const DIR = process.env.REPLAY_DIR || path.join(LAB, 'work', 'replay');
const PORT = +(process.env.PORT || 8443);
const DOC_DELAY = +(process.env.DOC_DELAY || 120);
const STATIC_DELAY = +(process.env.STATIC_DELAY || 15);
const local = JSON.parse(process.env.LOCAL_MAP || '[]');
const types = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.json': 'application/json', '.html': 'text/html; charset=UTF-8', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg' };
const textual = (type) => /^(text\/|application\/(javascript|json|xml)|image\/svg)/.test(type || '');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
fs.mkdirSync(path.join(DIR, 'cache'), { recursive: true });
if (!fs.existsSync(path.join(DIR, 'current.json'))) fs.writeFileSync(path.join(DIR, 'current.json'), '{}');

async function upstream(url) {
  const key = crypto.createHash('sha1').update(url).digest('hex');
  const meta = path.join(DIR, 'cache', `${key}.json`);
  const body = path.join(DIR, 'cache', `${key}.bin`);
  if (fs.existsSync(meta)) return { ...JSON.parse(fs.readFileSync(meta, 'utf8')), body: fs.readFileSync(body) };
  const res = await fetch(url, { redirect: 'manual', headers: { 'user-agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'accept-encoding': 'gzip' } });
  const buf = Buffer.from(await res.arrayBuffer());
  const rec = { url, status: res.status, type: res.headers.get('content-type') || '', cache: res.headers.get('cache-control') || '', location: res.headers.get('location') || '' };
  fs.writeFileSync(meta, JSON.stringify(rec));
  fs.writeFileSync(body, buf);
  return { ...rec, body: buf };
}

function send(req, res, status, type, body, cache) {
  const headers = { 'content-type': type, 'cache-control': cache || 'public, max-age=31536000', vary: 'Accept-Encoding' };
  if (textual(type) && /gzip/.test(req.headers['accept-encoding'] || '') && body.length > 0) {
    body = zlib.gzipSync(body, { level: 6 });
    headers['content-encoding'] = 'gzip';
  }
  headers['content-length'] = body.length;
  res.writeHead(status, headers);
  res.end(body);
}

const server = http2.createSecureServer({ key: fs.readFileSync(path.join(DIR, 'key.pem')), cert: fs.readFileSync(path.join(DIR, 'cert.pem')), allowHTTP1: true }, async (req, res) => {
  try {
    const url = new URL(req.url, SITE);
    const current = JSON.parse(fs.readFileSync(path.join(DIR, 'current.json'), 'utf8'));
    if (req.method === 'GET' && current[url.pathname] && !url.search) {
      await sleep(DOC_DELAY);
      return send(req, res, 200, 'text/html; charset=UTF-8', fs.readFileSync(current[url.pathname]), 'max-age=0, public');
    }
    for (const [prefix, base] of local) {
      if (url.pathname.startsWith(prefix)) {
        const file = path.join(base, decodeURIComponent(url.pathname.slice(prefix.length)));
        await sleep(STATIC_DELAY);
        if (!file.startsWith(base)) return send(req, res, 404, 'text/plain', Buffer.from('missing'));
        // Files the live site made itself (not in the local folder) are replayed like other static files.
        if (!fs.existsSync(file) && prefix.includes('/uploads/')) break;
        if (!fs.existsSync(file)) return send(req, res, 404, 'text/plain', Buffer.from('missing'));
        return send(req, res, 200, types[path.extname(file)] || 'application/octet-stream', fs.readFileSync(file));
      }
    }
    if (url.pathname.startsWith('/wp-json/') || req.method !== 'GET') {
      res.writeHead(204);
      return res.end();
    }
    // Only static files may ever be fetched from the live site: no pages, no admin-ajax, no tracking.
    const staticFile = /^\/(wp-content|wp-includes)\//.test(url.pathname) && /\.(css|js|png|jpe?g|gif|webp|avif|svg|woff2?|ttf|otf|eot|ico|mp4|webm)$/i.test(url.pathname);
    if (!staticFile) {
      console.error('BLOCKED', req.method, req.url);
      return send(req, res, 404, 'text/plain', Buffer.from('not replayed'));
    }
    const rec = await upstream(url.href);
    await sleep(STATIC_DELAY);
    if (rec.status >= 300 && rec.status < 400 && rec.location) {
      res.writeHead(rec.status, { location: rec.location });
      return res.end();
    }
    return send(req, res, rec.status, rec.type, rec.body, rec.cache);
  } catch (error) {
    console.error('ERR', req.url, error.message);
    res.writeHead(502);
    res.end();
  }
});
server.listen(PORT, '127.0.0.1', () => console.log(`replay of ${SITE} on https://127.0.0.1:${PORT} (files in ${DIR})`));
