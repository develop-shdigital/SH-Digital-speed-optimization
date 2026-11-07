# Handoff: speeding up another WordPress site

This repository holds what made www.sh-digital.ch fast (Elementor site; PageSpeed mobile 14–43
before, the 90s after on most pages): the **SHD Performance** plugin, a regression test over
saved pages, and the measurement lab in [`tools/perf-lab/`](tools/perf-lab/README.md). This
file is what a new conversation needs to do the same for another site. **SHD Consent (our
cookie banner) is not part of the next job.**

## 1. Paste this into the new conversation

```text
We're making <SITE URL> (WordPress) fast: PageSpeed 95+ on mobile and desktop and all Core Web
Vitals passing, without losing any function or image quality. Nothing may break.

First add the GitHub repository develop-shdigital/SH-Digital-speed-optimization (read access
is enough), clone it next to this project, read its HANDOFF.md and follow it. The new site's
work goes into <REPOSITORY FOR THE NEW SITE> on the branch this session gives you.
SHD Consent is not needed. Start with the setup check in HANDOFF.md section 2, then phase 1.
```

Replace the two `<…>`. If you have PageSpeed links for the site already, add them to the message.

## 2. Environment and skills (set up once, then check)

### Cloud session (claude.ai/code) — recommended for the lab work

| What | How |
| --- | --- |
| Repositories | The new site's repository (push) and this one (read). |
| Network access | The environment's allowlist must include: the site's domains, `pagespeed.web.dev`, `www.googleapis.com`, `registry.npmjs.org`, `github.com`, `raw.githubusercontent.com`, `codeload.github.com`, `wordpress.org`, `downloads.wordpress.org`, `api.wordpress.org`. |
| Secret (optional) | `PSI_API_KEY`: a PageSpeed Insights API key (Google Cloud console → APIs → PageSpeed Insights API → key). Without it, send stored report links instead (that worked fine). |
| System packages | Cloud images already have Node 22, Playwright and Chromium (`/opt/pw-browsers/chromium`). Check PHP: `php -v` (8.2+) and `php -m` (sqlite3, pdo_sqlite, gd, mbstring, xml, curl, zip, intl). For server-rule tests: `apt-get install -y apache2 libapache2-mod-php8.3`. |
| Lab packages | `cd tools/perf-lab && npm ci` (versions pinned in `package-lock.json`). |

### Skills and MCP

1. **web-perf skill** (Chrome DevTools performance audit; vetted, from this repo): copy
   `.claude/skills/web-perf/` into the new repository's `.claude/skills/`. Project skills load
   automatically when the repository is opened.
2. **Chrome DevTools MCP server** (the skill's tools). Either:
   - **Native:** add `.mcp.json` to the new repository's root (approve it when asked):
     ```json
     {
       "mcpServers": {
         "chrome-devtools": {
           "command": "npx",
           "args": ["-y", "chrome-devtools-mcp@1.12.0", "--headless", "--isolated", "--usageStatistics=false",
                    "--executablePath=/opt/pw-browsers/chromium", "--chromeArg=--no-sandbox"]
         }
       }
     }
     ```
     On a PC with Chrome installed, leave out the last two arguments.
   - **Script client (always works; used in the sh-digital project):**
     `node tools/perf-lab/devtools/audit.mjs <url> mobile|desktop`. When the MCP tools are not
     available, use this instead of stopping (the skill itself says to stop).
3. **claude-mem** (optional memory between sessions): only in Claude Code on your own computer;
   cloud sessions do not load plugins from a repository. Copy `.claude/settings.json` (pinned to
   v15.9.0, telemetry off). Windows needs Node 20+, Bun and Git Bash.

Rule from the owner: **scan any third-party package or skill before installing it** and pin
versions; prefer the tools above.

**Setup check before phase 1:** `node -v`, `php -v`, `npm ci` in `tools/perf-lab`,
`node tools/perf-lab/devtools/audit.mjs https://example.com desktop` runs, and
`python3 -I tools/perf-lab/psi/fetch.py reports …` saves a report.

## 3. Strategy that worked

### Phase 1 — Baseline (what costs points)
- PageSpeed Insights, mobile and desktop, for each page type: homepage, main landing pages, blog
  archive, a post, contact, any page with video, embeds or forms.
- `psi/fetch.py` → `psi/summary.py`: points lost per metric decide the order (Total Blocking
  Time 30, LCP 25, CLS 25, FCP 10, Speed Index 10).
- Save every page's live HTML (from the sitemap) with curl: these become the test fixtures.

### Phase 2 — Diagnosis (why)
- Per page: `psi/detail.py … largest-contentful-paint-element lcp-breakdown-insight
  render-blocking-insight bootup-time long-tasks third-party-summary network-requests`.
- `devtools/audit.mjs` for traces and insights; check the LCP element's discovery (is it only
  named in CSS?), entrance animations hiding content, layout shifts.
- The stack: active plugins (`/wp-json/` namespaces, plugin folders in the HTML), theme, page
  builder, cache plugin, host (RDAP of the server IP), server headers, time to first byte cached
  vs. uncached, compression, HTTP/2/3.
- Third parties: tracking, cookie banners, chat widgets, embedded apps (iframes bring their own
  tag managers), fonts and icon fonts, image weights.

### Phase 3 — Decide
For each finding: automatic in the plugin, a manual step for the owner (checklist with exact
clicks), or the host. Quantify it, and verify claims first ("unused" only after scanning all
pages).

### Phase 4 — Implement
Start from SHD Performance (section 5 says what to adapt). Every feature has a state:
**Live**, **Test** (only for admins with the Test view on, or the secret preview link) or
**Off**. New risky features start in Test. The plugin has: optimizer (HTML pass at output),
page cache with Apache delivery and preloading, health checks, a manual checklist, build-time
profiler, media replacement, real-visitor Web Vitals.

### Phase 5 — Prove it locally (never against live visitors)
- `tests/optimize-fixtures.php` over all saved pages in `live` and `all` mode: 0 failures, and
  pages that should not change are byte-identical to the previous release.
- Replay + Lighthouse before/after (`replay/server.mjs`, `lighthouse/run.mjs`), scroll check
  (`browser/scroll-check.cjs`), screenshots and box measurements (same sizes, same positions).
- Anything written to the server (`.htaccess`, `robots.txt`): test the rules on a real local
  Apache, verify after writing, roll back automatically on a 5xx, keep a backup.

### Phase 6 — Release
Version in the plugin header, the constant, `readme.txt` (Stable tag + changelog) and the README
table → `tools/build.sh` (drop `shd-consent` from its loop for the new site) → commit with the
attribution lines the session gives → push to the session branch → send the zip with install
steps (Plugins → Add New → Upload → "Replace current with uploaded"; the page cache clears itself).

### Phase 7 — Confirm on the live site
New PageSpeed runs (fresh report links), the plugin's health checks, and **Tools → "Where the
build time goes"** for uncached build time. Repeat per page until every page is 95+.

## 4. Lessons that save time

**Measuring**
- The PageSpeed API without a key returns 429 after a few calls: use stored report links or `PSI_API_KEY`.
- PageSpeed simulates the load (Lantern): images that finish before the LCP count against it;
  Speed Index ≈ 1.4 × observed + 0.4 × layout-based (Lighthouse 13.6), at least the FCP, so late
  layout work (an embedded app rendering) inflates it; scripts inside cross-origin iframes count
  in Total Blocking Time. The local replay shows the direction, not PageSpeed's number.
- Uncached build time: the profiler names the plugin; unused plugins still cost (WPForms with no
  form on any page: 0.2 s per build). Startup vs. rendering can be told apart from outside by
  timing `admin-ajax.php` (startup only) against a page with a random query string.

**What made the difference on sh-digital.ch**
- Entrance animations hid content until JavaScript ran (LCP 7.6 s on phones) → show at once.
- 22–33 stylesheets per page → one file, same order (skip `@import`, print styles and the
  styles Elementor loads itself: swiper, lightbox, dialog).
- Section background images found only in Elementor's post CSS → preload, per device.
- The real main image lazy-loaded while the logo had high priority → swap.
- Third-party cookie banner (CookieFirst): LCP element on some pages and last thing to appear
  everywhere; a deactivated banner plugin can still be loaded by another plugin with its key.
- Tracking scripts → start on the first interaction (or after 10 s).
- YouTube → click-to-play preview with local WebP thumbnails; embedded apps from other sites →
  start on the first interaction.
- Fonts: TTF → WOFF2, `font-display: swap`, size-matched fallback, preload one or two weights.
  An icon font for six icons (IcoFont, 525 KB) → inline SVG drawings.
- Page cache served by Apache from `.htaccess` (21–52 ms) with preloading after purges.
- Accessibility found by Lighthouse: skip link target, main landmark, heading levels (aria-level,
  look unchanged), logo alt = file name, iframe titles, icon-only links.

**Pitfalls**
- `.htaccess` rules run again after WordPress's rewrite to `index.php`: test conditions on
  `%{THE_REQUEST}`. `%{REQUEST_URI}` is decoded. Apache ≥ 2.4.56 answers 403 when a "?" comes
  from a backreference: let Apache append the query. Exclude POST, REST, AJAX, cron and
  `/.well-known/` from redirects.
- Redirects configured at the host (domain redirect in the host's panel) never reach WordPress.
- `robots.txt` may be a real file (served with ETag/Last-Modified) or built by WordPress.
- `WP_HTML_Tag_Processor`: at most 10 bookmarks; use `next_token()` for text.
- IntersectionObserver respects overflow clipping: carousel slides outside the window never
  "intersect".
- LA-Studio Kit loads styles for AJAX templates when `#<handle>-css` is missing; Elementor's
  asset loader checks `link[href="<exact url>"]` — keep inert placeholders when joining CSS.

## 5. Adapting SHD Performance to the new site

**Generic — reuse as it is:** the optimizer core (animations, main image, background preloads,
lazy backgrounds, joined CSS, fonts, delayed scripts, YouTube previews, lazy iframes,
accessibility), page cache, health checks, profiler, media replace, Web Vitals.

**Written for sh-digital.ch — review or rewrite:**
- `includes/admin/class-checklist.php`: every task's text, numbers and page names. Rewrite it
  from the new site's diagnosis (and remove the SHD Consent / CookieFirst tasks).
- `includes/class-settings.php` defaults: `tracking_patterns`, `delay_exclusions`,
  `keep_inline`, `consent_scripts` (CookieFirst), `redirect_domains`, `youtube_label`,
  `fonts.preload`; feature descriptions quote sh-digital pages.
- `includes/class-icons.php`: SVG drawings for the IcoFont icons of sh-digital.ch (keep the
  mechanism, draw the new site's icons).
- `includes/class-page-cache.php` (Kadence Performance migration), `class-domain-rules.php`
  (uses `redirect_domains`), texts with measured numbers in `admin/class-health-checks.php`
  and `admin/class-admin.php`.
- Heuristics tuned to Elementor + Hello + LA-Studio Kit (`dlicon`, `lakit`) and the CookieFirst
  loader: check them against the new site's stack.

Copy `shd-performance/`, `tests/` and `tools/` into the new site's repository, adapt there, and
keep this repository as the reference.

## 6. Rules carried over from this project

- In tests, never send analytics hits, consent logs or form posts to live services: the replay
  and the scripts block every other host and answer the REST API and non-GET requests locally.
- Live measurements: PageSpeed Insights (Google's servers) and curl for headers and timings.
  Opening the live site in a real browser counts as a visit in its analytics.
- Public repositories: no tracking IDs, API keys, font files or saved site HTML. Send site
  assets to the owner as files.
- Develop and push only on the session's branch; no pull request unless asked.
- Everything automatic must leave the page looking and working the same: same text, same sizes
  and positions, all images loaded after scrolling. Prove it before release.
