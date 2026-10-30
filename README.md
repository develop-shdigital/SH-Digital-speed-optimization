# SH Digital speed optimization

Tools for making https://www.sh-digital.ch fast: a 95+ PageSpeed score on mobile and desktop and passing Core Web Vitals, without changing how the site looks or works.

| Folder | What it is |
| --- | --- |
| `shd-performance/` | The WordPress plugin. Install it on the site. |
| `tools/build.sh` | Minifies the plugin's front-end scripts and builds `dist/shd-performance-<version>.zip`. |
| `tests/optimize-fixtures.php` | Runs the optimizer over saved pages and checks that nothing visible was lost. |
| `.claude/skills/web-perf/` | The Chrome DevTools performance skill used for the diagnosis. |

## The plugin

SHD Performance rewrites each finished page after Elementor and the other plugins have built it, then stores it in its own page cache, which replaces Kadence Performance. It never edits Elementor data, content or other plugins' settings. Deactivating it restores the original site.

### What it fixes automatically

| Problem found on sh-digital.ch | What the plugin does | Default |
| --- | --- | --- |
| Entrance animations hide content and the header until Elementor's JavaScript runs: 2.0 s on desktop and 7.6 s on phones. | Content is shown immediately. | Live |
| The header logo has `fetchpriority="high"`, while the real main image is lazy-loaded. | Picks the largest first-screen image (or the featured image) and prioritizes that instead. | Live |
| Small images download large files, e.g. 34 px avatars load 768 px files. | `sizes="auto"` on lazy images. The browser picks the right srcset file, with no recompression. | Live |
| YouTube widget loads about 1 MB of player code before anyone presses play. | Click-to-play preview. Playback starts with that click. | Live |
| The autoplaying chart video in section 4 of /google-ads-agentur/ (735 KB) downloads with the page, far below the first screen. | Autoplaying videos below the first screen load when a visitor scrolls near them. The box keeps its size, so nothing moves. | Live |
| reCAPTCHA v3 (349 KB) loads on every page. | Loaded when a visitor starts using a form. Submits wait for the token. | Live |
| Tracking scripts compete with page load. | Start on the first interaction (or after 10 s). Consent handling is unchanged. | Live |
| CookieFirst `consent.js` blocks the first paint (about 1 s on phones). | Downloaded early without blocking and started at the top of `<body>`. | Live |
| Duplicate scripts, emoji script, unused Qi Addons / LA-Studio Kit files. | Removed on pages that do not need them. | Live |
| Four Fractul Office `.ttf` files (1.12 MB) with `font-display: auto`. | `swap`, uploaded WOFF2 files (lossless; 58 KB each, plus 25 KB Latin subsets that cover all the site's text), preloads, and a size-matched Arial fallback. | Live |
| Elementor Pro fills the blog table of contents after loading, which pushes the article down: CLS above 0.1 on 18 of 30 blog pages on phones, up to 0.69. | The same list is written into the page, so nothing moves. It looks and works as before. Optionally it starts collapsed on phones (see below). | Live |
| The cursor-dot script redraws 60×/s forever and leaves a stray dot on phones. | Same effect on mouse devices, idle when the mouse rests, hidden on touch devices. | Live |
| JavaScript blocks rendering and causes about 1 s of Total Blocking Time on mobile. | Defer all scripts, or start them on the first interaction (taps and form submits are replayed). | Test |
| jQuery Migrate. | Removed. | Test |
| No real-visitor data (Google has none for this site yet). | Cookieless Core Web Vitals measurement with a 75th-percentile dashboard. Automated browsers, including PageSpeed Insights' test runs, are not counted. | Live |
| WordPress needs about 2.5 s to build a page. Kadence Performance's cache missed on 62 of 63 pages for the first phone visitor, and its lazy-loading delayed the video cover on /google-ads-agentur/. | Its own page cache: one copy for phones and computers, sent by Apache in about 1 ms, refilled in the background, and cleared when you save in Elementor (see below). | On; takes over when Kadence Performance is deactivated |

#### One design choice

*Table of contents on phones* (Optimizations → Advanced settings) is **Open, as designed** by default. On blog posts with many headings, the open list fills the first screen on phones and pushes the featured image out of it. For first-time visitors the cookie banner then becomes the largest element on the screen. Because the banner loads in 9 steps from CookieFirst's servers, PageSpeed counts a slow LCP: about 4.5 s and a mobile score of about 80 on the 8 affected posts. With **Collapsed, with a button to open it**, the measured post scored 88–96. The arrow button opens the list at once, also before the page's other scripts have started. Desktop is never affected.

### Page cache

Each finished page (with all optimizations applied) is stored as a file in `wp-content/cache/shd-performance/pages/`, plain and gzipped. The next visitors get that file:

- on Apache, directly from the web server through rules at the top of `.htaccess`, without PHP or WordPress (about 1 ms on the server);
- elsewhere, from `wp-content/advanced-cache.php`, before WordPress loads.

Logged-in users, password-protected posts, form submissions, searches, 404 pages and addresses with parameters always get a fresh page from WordPress. Addresses that only add marketing parameters (`?gclid=…`, `?utm_…`) can get the cached page too; that option is off until Google Analytics is loaded from one place (see the checklist).

Stored pages live for 10 hours at most, because the forms and the newsletter sign-up contain WordPress security tokens that expire after 12–24 hours. They are rebuilt in the background before they expire. Every public page (home, pages, posts, categories) is also cached in the background right after the cache is cleared, so the first visitor and Google's test get a stored page.

The whole cache is cleared when content changes: a post or page is published, updated or deleted; you save in Elementor (pages, templates, header and footer, popups, site settings); Elementor → Tools → “Clear Files & Data” is pressed; menus, widgets, the Customizer, permalinks, plugins or themes change; or settings are saved in SHD Performance. A new comment only clears its own post.

**Elementor's Element Cache** (Elementor → Settings → Performance) keeps the rendered widgets in the database for a day. It makes building a page faster, but WordPress still starts for every visit, so it does not replace a page cache. Both work together. Because a document save or “Clear Files & Data” deletes the CSS files that Elementor generates, the page cache is cleared at the same moment, so a stored page never links to a CSS file that no longer exists.

**Switching from Kadence Performance:** while Kadence Performance is active, the SHD page cache waits. Deactivate Kadence Performance: it removes its rules and its `advanced-cache.php`, and SHD Performance installs its own in the same request, then fills the cache within a few minutes. `WP_CACHE` stays `true` in `wp-config.php`. Leftover Kadence rules in `.htaccess` are removed. Another plugin's page cache or `advanced-cache.php` is never overwritten.

Deactivating SHD Performance removes its `.htaccess` rules, `advanced-cache.php`, the stored pages and the `WP_CACHE` line, if the plugin added that line.

Every feature can be set to **Off**, **Test** or **Live**:

- **Test** features apply only for logged-in administrators in the Test view, and for anyone using the secret preview link.
- **Live** features apply to every visitor.

### Dashboard

*SHD Performance* in the admin menu has these tabs:

- **Overview**: status, the Test view and preview link, health summary, and real-visitor vitals.
- **Checklist**: a step-by-step guide for everything that needs a person, with automatic status where it can be detected. It covers:
  - Switching from Kadence Performance to the SHD page cache, and ad and campaign links.
  - Server speed (with the build-time measurement) and an optional CDN.
  - WOFF2 fonts.
  - Inline SVG icons instead of the Font Awesome icon fonts.
  - Unused add-ons.
  - Oversized image files and image sizes in widgets.
  - The autoplaying video on /google-ads-agentur/: Mute and Play On Mobile, so that it plays for every visitor.
  - The table of contents on phones (optional design choice).
  - Duplicate Google Analytics.
  - The cookie banner.
  - Accessibility.
  - Redirects, robots.txt and Brotli.
  - Verification in PageSpeed Insights and with real-visitor data.
- **Optimizations**: the feature switches and advanced settings.
- **Page cache**: status, stored pages, clear and refill buttons, lifetime, campaign links and exclusions.
- **Fonts**: detected fonts, WOFF2 upload, preloads and the fallback metrics.
- **Web Vitals**: LCP, INP, CLS, FCP and TTFB from real visits, per device and page. Automated browsers are not measured.
- **Tools**: health checks, a page scan, cache purge, settings export/import, diagnostics, and **Where the build time goes**: one click builds a page on the server as a first-time visitor and shows the time per plugin, Elementor widget and shortcode, the database queries and requests to other servers, with what to do about the biggest items. Only that one request is measured.

### Install

1. Build the zip with `tools/build.sh`, or zip the `shd-performance` folder as it is.
2. In WordPress, go to Plugins → Add New → Upload Plugin and activate it.
3. Open *SHD Performance → Checklist* and follow the guide. The first steps are:
   - Check the Test features in the Test view, then switch them to Live.
   - Deactivate Kadence Performance. SHD Performance takes over the page cache automatically.
   - Upload the WOFF2 fonts on the Fonts tab.

### Undo

- To undo one change, set its feature to Off. The page cache is cleared and refilled automatically on save.
- To compare against the original, open any page with `?shd-off=1` as an administrator.
- To undo everything, deactivate the plugin.
- If the admin is not reachable, add `define( 'SHD_PERFORMANCE_DISABLE', true );` to `wp-config.php`. Stored pages stop with the first page WordPress builds after that; to stop them at once, delete `wp-content/cache/shd-performance/pages` by FTP.

## Results

Measured with Lighthouse 13, the engine behind PageSpeed Insights, using its simulated mid-range phone on slow 4G and its desktop profile. Each page was served from a local replay of the live site's files, before and after the plugin. Mobile shows two runs, because the phone score varies by a few points from run to run.

- **Before:** the site as it is today.
- **Live only:** the plugin's defaults right after installing.
- **All:** after switching the Test features to Live.

| Page | Mobile before | Mobile, Live only | Mobile, all | Desktop before | Desktop, all |
| --- | --- | --- | --- | --- | --- |
| Homepage | 43 | 96 | 97–99 | 72 | 99 |
| /resultate/ | 41 | 87 | 93–97 | 71 | 100 |
| /ueber-uns/ | 42 | 97 | 93–96 | 84 | 99 |
| /geo-agentur/ | 36 | 69 | 88–96 | 82 | 98 |
| /was-ist-seo/ | 29 | 91 | 91 | 78 | 98 |
| /kontakt/ | 42 | 67 | 82–86 | 87 | 99 |
| /google-ads-agentur/ | 38 | 60 | 73–80 | 74 | 99 |
| /blog/ | 43 | 71 | 78–79 | 77 | 99 |
| /wie-findet-google-meine-website/ | 14 | 69 | 78–82 (88–96 with the table of contents collapsed on phones) | 74 | 99 |

Layout shift is 0 on all of these pages, down from up to 0.68 on blog posts. Total Blocking Time on phones fell from 0.8–2.1 s to 0.1–0.5 s.

### Why some phone scores are still below 95

What remains is in the checklist, because it needs changes in Elementor, the media library or other plugins:

- **Font Awesome icon fonts:** about 180 KB on every page. They download while the main image loads. Fix: “Switch Elementor icons to inline SVG”.
- **/blog/:** the post cards use full-size featured images as backgrounds, 237 KB for the first card alone. Fix: “Use smaller image sizes in widgets”.
- **/google-ads-agentur/:** the video cover is the full 391 KB image. Kadence Performance also lazy-loaded it with an extra script; switching to the SHD page cache removes that. Fix: set the cover's image resolution to Large.
- **/kontakt/:** a photo saved as a 107 KB PNG. Fix: “Replace oversized image files”.
- **Long blog posts (8 of 30):** the open table of contents fills the first screen on phones, and the cookie banner becomes the slowest element. Fix: the optional collapsed table of contents.

PageSpeed Insights on the live site will differ by a few points, because the network and server differ. The Core Web Vitals report in Search Console needs 28 days of visits after the change.

## Development

```bash
tools/build.sh
```

To run the optimizer over saved pages, use a WordPress install with the plugin active:

```bash
wp eval-file tests/optimize-fixtures.php <folder-with-saved-html> <output-folder> all
```

The fixture test reports, per page, what was changed. It fails if:

- the visible text changes;
- links, images, forms, iframes, videos, SVGs or headings disappear;
- script tags are unbalanced;
- the document no longer parses.
