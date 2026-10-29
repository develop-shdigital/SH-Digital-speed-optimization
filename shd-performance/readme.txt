=== SHD Performance ===
Contributors: shdigital
Tags: performance, core web vitals, elementor, pagespeed
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Speed and Core Web Vitals optimizations for the SH Digital Elementor site, with a dashboard checklist for the steps that need a person.

== Description ==

SHD Performance rewrites the finished HTML of each front-end page (after Elementor and all plugins have run, before the page cache stores it). Nothing in Elementor, the content or other plugins is changed, so turning a feature off or deactivating the plugin restores the original behaviour immediately.

Automatic optimizations (each one can be Off, Test or Live):

* Content is shown immediately instead of waiting for Elementor's entrance animations.
* The main image of each page is prioritized; the header logo no longer competes with it.
* Lazy-loaded images download at the size they are shown (sizes="auto"); images get dimensions so the layout does not jump.
* Click-to-play previews replace Elementor's YouTube players until someone presses play.
* reCAPTCHA loads when a visitor starts using a form.
* Tracking scripts start after the first interaction; the cookie banner no longer blocks the first paint.
* JavaScript is deferred, or started on the first interaction, with taps and form submits replayed.
* Unused Qi Addons / LA-Studio Kit files, the emoji script, duplicate scripts and (optionally) jQuery Migrate are removed.
* Fonts: font-display: swap, uploaded WOFF2 files, preloads and a size-matched fallback font.
* The blog's table of contents is filled in from the start, so it no longer pushes the article down.
* Real-visitor Core Web Vitals (LCP, INP, CLS, FCP, TTFB) are measured without cookies.

The dashboard (SHD Performance in the admin menu) also contains health checks, a page scan, and a step-by-step checklist for everything that has to be changed by hand: cache settings, server, fonts, plugins, images, tracking, accessibility and domain set-up.

== Installation ==

1. Plugins → Add New → Upload Plugin, choose the zip, activate.
2. Open SHD Performance → Overview. Live features are already active for visitors; Test features apply only in the Test view.
3. Check the Test view (admin bar or the secret preview link), then switch the Test features to Live on the Optimizations tab.
4. Work through the Checklist tab.

== Frequently Asked Questions ==

= How do I undo a change? =

Set the feature to Off on the Optimizations tab, or deactivate the plugin. If the admin is not reachable, add `define( 'SHD_PERFORMANCE_DISABLE', true );` to wp-config.php. Clear the page cache afterwards (Kadence Performance is cleared automatically when you save in the dashboard).

= How do I compare with the original page? =

As an administrator, open any page with `?shd-off=1`, or use "Show this page without optimizations" in the admin bar.

= Does the Web Vitals measurement use cookies? =

No. Each page view may send one anonymous beacon with the metric values, the page path and whether the screen is phone-sized. No IP address, user agent or identifier is stored. Data older than 90 days is deleted.

== Changelog ==

= 1.0.0 =
* First release.
