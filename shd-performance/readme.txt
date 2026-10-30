=== SHD Performance ===
Contributors: shdigital
Tags: performance, core web vitals, elementor, pagespeed
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Speed and Core Web Vitals optimizations and a page cache for the SH Digital Elementor site, with a dashboard checklist for the steps that need a person.

== Description ==

SHD Performance rewrites the finished HTML of each front-end page (after Elementor and all plugins have run) and stores it in its own page cache. Nothing in Elementor, the content or other plugins is changed, so turning a feature off or deactivating the plugin restores the original behaviour immediately.

Automatic optimizations (each one can be Off, Test or Live):

* Content is shown immediately instead of waiting for Elementor's entrance animations.
* The main image of each page is prioritized; the header logo no longer competes with it.
* Lazy-loaded images download at the size they are shown (sizes="auto"); images get dimensions so the layout does not jump.
* Click-to-play previews replace Elementor's YouTube players until someone presses play.
* Autoplaying videos further down the page download when a visitor scrolls near them, not with the page.
* reCAPTCHA loads when a visitor starts using a form.
* Tracking scripts start after the first interaction; the cookie banner no longer blocks the first paint.
* JavaScript is deferred, or started on the first interaction, with taps and form submits replayed.
* Unused Qi Addons / LA-Studio Kit files, the emoji script, duplicate scripts and (optionally) jQuery Migrate are removed.
* Fonts: font-display: swap, uploaded WOFF2 files, preloads and a size-matched fallback font.
* The blog's table of contents is filled in from the start, so it no longer pushes the article down.
* Real-visitor Core Web Vitals (LCP, INP, CLS, FCP, TTFB) are measured without cookies. Automated browsers, such as PageSpeed Insights' test runs, are not counted.

Page cache (replaces Kadence Performance):

* Finished pages are stored as files and sent by Apache through .htaccess rules (or by wp-content/advanced-cache.php) without starting WordPress.
* One copy for phones and computers; every public page is cached again in the background after a change.
* Cleared when content changes, including Elementor saves (pages, templates, site settings) and Elementor's "Clear Files & Data". Works together with Elementor's Element Cache.
* Logged-in users, form submissions, password-protected posts, searches and addresses with parameters always get a fresh page. Ad and campaign links can optionally get the cached page.
* Waits while Kadence Performance (or another page cache plugin) is active, and takes over when it is deactivated.

The dashboard (SHD Performance in the admin menu) also contains health checks, a page scan, a measurement of where the server spends the time to build a page (per plugin, Elementor widget, shortcode, database query and request to other servers), and a step-by-step checklist for everything that has to be changed by hand: cache settings, server, fonts, plugins, images, tracking, accessibility and domain set-up.

== Installation ==

1. Plugins → Add New → Upload Plugin, choose the zip, activate.
2. Deactivate Kadence Performance. SHD Performance installs its page cache at once (check SHD Performance → Page cache).
3. Open SHD Performance → Overview. Live features are already active for visitors; Test features apply only in the Test view.
4. Check the Test view (admin bar or the secret preview link), then switch the Test features to Live on the Optimizations tab.
5. Work through the Checklist tab.

== Frequently Asked Questions ==

= How do I undo a change? =

Set the feature to Off on the Optimizations tab, or deactivate the plugin. The page cache is cleared automatically when you save in the dashboard, and deactivating removes it completely. If the admin is not reachable, add `define( 'SHD_PERFORMANCE_DISABLE', true );` to wp-config.php; to stop stored pages at once, also delete the folder wp-content/cache/shd-performance/pages.

= Does it replace Elementor's Element Cache? =

No, they work together. Element Cache keeps rendered widgets in the database, but WordPress still starts for every visit. The page cache sends the finished page without starting WordPress, and it is cleared whenever Elementor saves a document or clears its files.

= How do I compare with the original page? =

As an administrator, open any page with `?shd-off=1`, or use "Show this page without optimizations" in the admin bar.

= Does the Web Vitals measurement use cookies? =

No. Each page view may send one anonymous beacon with the metric values, the page path and whether the screen is phone-sized. No IP address, user agent or identifier is stored. Data older than 90 days is deleted. Automated browsers (tests, and PageSpeed Insights' lab runs) send nothing, so the numbers come from real visits only.

== Changelog ==

= 1.3.0 =
* Autoplaying self-hosted videos below the first screen (Elementor Video widget) download when a visitor scrolls near them instead of with the page. Their box keeps its size, so nothing moves. New feature "Start autoplaying videos further down when they come into view" (Live).
* Checklist: the autoplaying-video task names the page, section and file and checks Mute and Play On Mobile, without which browsers block the autoplay (iPhones also need Play On Mobile). It turns green after "Scan pages" once both are on. Tools shows which videos wait until they come into view.
* Web Vitals: automated browsers (navigator.webdriver: test tools and PageSpeed Insights' lab runs) are no longer measured. A one-time clean-up removes the samples recorded for /google-ads-agentur/ on 30 October 2026 between 14:02 and 14:12 UTC, which came from an automated test.

= 1.2.1 =
* Build-time report: [elementor-element] (the placeholder of Elementor's Element Cache for widgets it builds again on every visit) is explained with the widgets instead of being listed as a shortcode.

= 1.2.0 =
* Tools → "Where the build time goes": builds one page as a first-time visitor and shows the time per plugin, Elementor widget and shortcode, the database queries, requests to other servers and what to do about the biggest items. Only the measured request is affected.
* The checklist task about slow uncached pages uses the measurement and is optional now that the page cache serves visitors.

= 1.1.0 =
* Page cache that replaces Kadence Performance: .htaccess delivery on Apache, advanced-cache.php elsewhere, background preload, automatic clearing (including Elementor saves and "Clear Files & Data"), optional caching of ad and campaign links.
* New Page cache tab, health check and checklist task for the switch from Kadence Performance.
* Rewritten CSS copies that are still in use are no longer removed by the daily clean-up.

= 1.0.0 =
* First release.
