# perf-lab: measuring and testing without touching the live site

Tools from the sh-digital.ch speed project, generic for any WordPress site. Set `SITE` to the
live origin (for example `SITE=https://www.example.ch`). Working files go to `work/` (not
committed). See [HANDOFF.md](../../HANDOFF.md) for when to use which.

```bash
cd tools/perf-lab && npm ci        # lighthouse 13.6.0, puppeteer-core 25.16.0, chrome-devtools-mcp 1.12.0, MCP SDK 1.32.2
```

Chrome: `CHROME_PATH`, otherwise `/opt/pw-browsers/chromium` (cloud sessions), otherwise the
installed Chrome. Playwright (for `browser/`) is installed globally in cloud sessions.

## PageSpeed Insights results — `psi/`

| Command | Does |
| --- | --- |
| `python3 -I psi/fetch.py reports work/lhr <report-link>...` | Saves the mobile and desktop result of stored report links (`https://pagespeed.web.dev/analysis/...`). No key, no quota. |
| `PSI_API_KEY=... python3 -I psi/fetch.py api work/lhr <page-url>...` | Runs PageSpeed Insights for pages (mobile and desktop). Without a key Google allows only a few calls a day. |
| `python3 -I psi/summary.py work/lhr mobile` | Score, the five metrics, points lost per metric, failing audits — per page. |
| `python3 -I psi/detail.py work/lhr mobile <audit-id>...` | The items behind findings (LCP element, render-blocking files, long tasks, third parties…). |

## Chrome DevTools (the web-perf skill's tools) — `devtools/`

`node devtools/audit.mjs <url> mobile|desktop`: performance trace with every insight analysed,
the LCP element and first screen, and on desktop the network list, accessibility snapshot and
console errors. Output in `work/devtools/`. Use it when the chrome-devtools MCP server is not
connected to the session. It opens the real page: analytics on that page count a visit.

## Local replay of the live site — `replay/`, `lighthouse/`, `browser/`

```bash
SITE=https://www.example.ch bash replay/make-cert.sh
SITE=https://www.example.ch LOCAL_MAP='[["/wp-content/plugins/shd-performance/","/abs/repo/shd-performance/"]]' node replay/server.mjs
PAGE=/abs/saved/page.html SITE=https://www.example.ch node lighthouse/run.mjs /page-path/ work/lh/page-mobile.json mobile performance,accessibility
SITE=https://www.example.ch node browser/scroll-check.cjs /page-path/ mobile
```

The replay serves the page under test from a local HTML file (before or after the plugin) and
the site's static files from a cache filled once from the live site. Browsers reach only the
replay: every other host is blocked, the REST API and all non-GET requests are answered
locally. Lighthouse uses PageSpeed Insights' settings; compare before/after on the same machine
(it cannot reproduce the live server's own slowness — confirm on PageSpeed Insights).

## Local WordPress — `local-wp/`

```bash
bash tools/perf-lab/local-wp/setup.sh "$SCRATCH/wp"            # SQLite, Elementor, Hello, SHD Performance (symlink)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8088 -t "$SCRATCH/wp" tools/perf-lab/local-wp/router.php
python3 -I tools/perf-lab/local-wp/mirror-assets.py "$SITE" <saved-pages> "$SCRATCH/wp" "$SCRATCH/mirror/uploads"
MIRROR_SITE="$SITE" MIRROR_UPLOADS="$SCRATCH/mirror/uploads" \
  php tools/perf-lab/work/bin/wp-cli.phar --allow-root --path="$SCRATCH/wp" \
  eval-file tests/optimize-fixtures.php <saved-pages> <output-folder> live
```

With `MIRROR_SITE` and `MIRROR_UPLOADS` set, the local WordPress presents itself under the live
address (mu-plugin `site-mirror.php`), so saved live pages are optimized exactly as on the site
and the result can be measured through the replay (`LOCAL_MAP` the uploads mirror too).
