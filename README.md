# SH Digital speed optimization

Tools for making https://www.sh-digital.ch fast: a 95+ PageSpeed score on mobile and desktop and passing Core Web Vitals, without changing how the site looks or works.

| Folder | What it is |
| --- | --- |
| `shd-performance/` | The WordPress plugin. Install it on the site. |
| `tools/build.sh` | Minifies the plugin's front-end scripts and builds `dist/shd-performance-<version>.zip`. |
| `tests/optimize-fixtures.php` | Runs the optimizer over saved pages and checks that nothing visible was lost. |
| `.claude/skills/web-perf/` | The Chrome DevTools performance skill used for the diagnosis. |

## The plugin

SHD Performance rewrites each finished page after Elementor and the other plugins have built it, and before Kadence Performance caches it. It never edits Elementor data, content or other plugins' settings. Deactivating it restores the original site.

### What it fixes automatically

| Problem found on sh-digital.ch | What the plugin does | Default |
| --- | --- | --- |
| Entrance animations hide content and the header until Elementor's JavaScript runs: 2.0 s on desktop and 7.6 s on phones. | Content is shown immediately. | Live |
| The header logo has `fetchpriority="high"`, while the real main image is lazy-loaded. | Picks the largest first-screen image (or the featured image) and prioritizes that instead. | Live |
| Small images download large files, e.g. 34 px avatars load 768 px files. | `sizes="auto"` on lazy images. The browser picks the right srcset file, with no recompression. | Live |
| YouTube widget loads about 1 MB of player code before anyone presses play. | Click-to-play preview. Playback starts with that click. | Live |
| reCAPTCHA v3 (349 KB) loads on every page. | Loaded when a visitor starts using a form. Submits wait for the token. | Live |
| Tracking scripts compete with page load. | Start on the first interaction (or after 10 s). Consent handling is unchanged. | Live |
| CookieFirst `consent.js` blocks the first paint (about 1 s on phones). | Loaded with `defer` and an early connection. | Live |
| Duplicate scripts, emoji script, unused Qi Addons / LA-Studio Kit files. | Removed on pages that do not need them. | Live |
| Four Fractul Office `.ttf` files (1.12 MB) with `font-display: auto`. | `swap`, uploaded WOFF2 files (lossless; 58 KB each, plus 25 KB Latin subsets that cover all the site's text), preloads, and a size-matched Arial fallback. | Live |
| Elementor Pro fills the blog table of contents after loading, which pushes the article down: CLS above 0.1 on 18 of 30 blog pages on phones, up to 0.69. | The same list is written into the page, so nothing moves. It looks and works as before. Optionally it starts collapsed on phones (see below). | Live |
| The cursor-dot script redraws 60×/s forever and leaves a stray dot on phones. | Same effect on mouse devices, idle when the mouse rests, hidden on touch devices. | Live |
| JavaScript blocks rendering and causes about 1 s of Total Blocking Time on mobile. | Defer all scripts, or start them on the first interaction (taps and form submits are replayed). | Test |
| jQuery Migrate. | Removed. | Test |
| No real-visitor data (Google has none for this site yet). | Cookieless Core Web Vitals measurement with a 75th-percentile dashboard. | Live |

#### One design choice

*Table of contents on phones* (Optimizations → Advanced settings) is **Open, as designed** by default. On blog posts with many headings, the open list fills the first screen on phones and pushes the featured image out of it. For first-time visitors the cookie banner then becomes the largest element on the screen. Because the banner loads in 9 steps from CookieFirst's servers, PageSpeed counts a slow LCP: about 4.5 s and a mobile score of about 82 on the 8 affected posts. With **Collapsed, with a button to open it** those posts score about 98. The arrow button opens the list at once, also before the page's other scripts have started. Desktop is never affected.

Every feature can be set to **Off**, **Test** or **Live**:

- **Test** features apply only for logged-in administrators in the Test view, and for anyone using the secret preview link.
- **Live** features apply to every visitor.

### Dashboard

*SHD Performance* in the admin menu has these tabs:

- **Overview**: status, the Test view and preview link, health summary, and real-visitor vitals.
- **Checklist**: a step-by-step guide for everything that needs a person, with automatic status where it can be detected. It covers:
  - Kadence Performance's mobile cache.
  - Server speed.
  - WOFF2 fonts.
  - Inline SVG icons.
  - Unused add-ons.
  - Images.
  - Duplicate Google Analytics.
  - The cookie banner.
  - Accessibility.
  - Redirects, robots.txt and Brotli.
  - Verification.
- **Optimizations**: the feature switches and advanced settings.
- **Fonts**: detected fonts, WOFF2 upload, preloads and the fallback metrics.
- **Web Vitals**: LCP, INP, CLS, FCP and TTFB from real visits, per device and page.
- **Tools**: health checks, a page scan, cache purge, settings export/import and diagnostics.

### Install

1. Build the zip with `tools/build.sh`, or zip the `shd-performance` folder as it is.
2. In WordPress, go to Plugins → Add New → Upload Plugin and activate it.
3. Open *SHD Performance → Checklist* and follow the guide. The first steps are:
   - Check the Test features in the Test view, then switch them to Live.
   - Turn off “Mobile Cache” in Settings → Kadence Performance and press “Preload & Refresh All”.
   - Upload the WOFF2 fonts on the Fonts tab.

### Undo

- To undo one change, set its feature to Off. The Kadence Performance cache is cleared automatically on save.
- To compare against the original, open any page with `?shd-off=1` as an administrator.
- To undo everything, deactivate the plugin.
- If the admin is not reachable, add `define( 'SHD_PERFORMANCE_DISABLE', true );` to `wp-config.php`.

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
