<?php
/**
 * Manual tasks: what the plugin cannot change on its own, with instructions,
 * links and an automatic status where it can be detected.
 *
 * @package SHD\Performance
 */

namespace SHD\Performance;

defined( 'ABSPATH' ) || exit;

final class Checklist {

	const OPTION = 'shd_perf_checklist';

	/** @var array<string,array> Statuses computed during this request. */
	private static $statuses = array();

	/**
	 * Task definitions in recommended order.
	 *
	 * @return array<int,array>
	 */
	public static function tasks() {
		$home      = home_url( '/' );
		$psi       = 'https://pagespeed.web.dev/analysis?url=';
		$elementor = admin_url( 'admin.php?page=elementor-settings' );
		$kadence   = admin_url( 'options-general.php?page=swpsp-settings' );

		return array(
			array(
				'id'    => 'backup-staging',
				'group' => __( 'Before you start', 'shd-performance' ),
				'title' => __( 'Make a backup, ideally test on a staging copy', 'shd-performance' ),
				'why'   => __( 'Several tasks change plugins and settings. A backup makes every step reversible.', 'shd-performance' ),
				'steps' => array(
					__( 'Create a full backup (files and database) with your host or a backup plugin.', 'shd-performance' ),
					__( 'If your host offers a one-click staging site, do the tasks there first and push them live afterwards.', 'shd-performance' ),
					__( 'SHD Performance itself is safe to try live: features in “Test” are only visible to you, and deactivating the plugin removes every automatic change.', 'shd-performance' ),
				),
			),
			array(
				'id'    => 'preview-test',
				'group' => __( 'Before you start', 'shd-performance' ),
				'title' => __( 'Check the Test features in the preview, then switch them to Live', 'shd-performance' ),
				'why'   => __( 'The biggest mobile-score gains (deferring and delaying JavaScript) are in Test until you have checked them on this site.', 'shd-performance' ),
				'steps' => array(
					__( 'Open the preview link from the Overview tab in a private window, on a phone and on a computer (or use “Switch to Test view” in the admin bar).', 'shd-performance' ),
					__( 'Check: header menu and mobile menu, every form (send a test message), cookie banner (accept and reject), the homepage video, carousels, blog table of contents, chat widget, the map on /kontakt/ and the timeline on /geo-agentur/.', 'shd-performance' ),
					__( 'Open the browser console (F12) and look for red errors.', 'shd-performance' ),
					__( 'If everything works, press “Switch all Test features to Live” on the Optimizations tab. If one thing breaks, set only that feature to Off and tell us which.', 'shd-performance' ),
					__( 'SHD Performance clears the Kadence Performance cache by itself whenever you save here. Then press “Preload & Refresh All” in Settings → Kadence Performance, so the first visitors also get cached pages.', 'shd-performance' ),
				),
				'links' => array( 'Kadence Performance' => $kadence ),
				'auto'  => array( __CLASS__, 'auto_preview' ),
			),
			array(
				'id'    => 'cache-mobile',
				'group' => __( 'Caching and server', 'shd-performance' ),
				'title' => __( 'Serve the page cache to phones too', 'shd-performance' ),
				'why'   => __( 'Measured: 62 of 63 pages answered a phone’s first request with a cache MISS (2.8 s median) while desktop got a HIT in 0.4 s. Google tests the mobile version. Cause: “Mobile Cache” keeps a separate copy for phones, but the Kadence Performance preloader only fetches pages as a desktop browser, so the phone copy only exists after a phone has visited that page.', 'shd-performance' ),
				'steps' => array(
					__( 'Settings → Kadence Performance: turn off “Mobile Cache” (Store separate caches for mobile devices) and save. Safe here: the site sends phones and computers the same HTML (checked on the homepage; the only difference is an unused Qi Addons body class).', 'shd-performance' ),
					__( 'Kadence Performance clears its cache when this setting changes. Press “Preload & Refresh All” so every page is cached again.', 'shd-performance' ),
					__( 'When the preload has finished, run the health checks on the Tools tab: “Page cache, phone visitors” should show HIT on the first request.', 'shd-performance' ),
				),
				'links' => array( 'Kadence Performance' => $kadence ),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => array( 'kadence_mobile_cache', 'cache_mobile' ),
			),
			array(
				'id'    => 'cache-lazy-images',
				'group' => __( 'Caching and server', 'shd-performance' ),
				'title' => __( 'Turn off “Lazy Load Images” in Kadence Performance', 'shd-performance' ),
				'why'   => __( 'This option only lazy-loads inline background images, which on this site are the cover images of the videos on /google-ads-agentur/ and /web-design-agentur/. It loads them with an extra script after the page, so on phones the video cover in the first screen of /google-ads-agentur/ (your ads landing page) appears late and becomes the slowest element (LCP). Normal images are already lazy-loaded by WordPress, and SHD Performance keeps the main image fast, so nothing else changes.', 'shd-performance' ),
				'steps' => array(
					__( 'Settings → Kadence Performance: turn off “Lazy Load Images” and save.', 'shd-performance' ),
					__( 'Press “Preload & Refresh All”.', 'shd-performance' ),
				),
				'links' => array( 'Kadence Performance' => $kadence ),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'kadence_lazy_images',
			),
			array(
				'id'       => 'cache-query-params',
				'group'    => __( 'Caching and server', 'shd-performance' ),
				'title'    => __( 'Optional: let ad and campaign links use the cache', 'shd-performance' ),
				'optional' => true,
				'why'      => __( 'Kadence Performance never caches an address with a query string, and it has no setting to ignore marketing parameters. So every visitor from Google Ads (?gclid=…) or a campaign (?utm_…) waits for WordPress to build the page (about 2.5 s). PageSpeed tests the plain address, so this does not change the score, but it affects the real-visitor Core Web Vitals of your paid traffic.', 'shd-performance' ),
				'steps'    => array(
					__( 'Do the “Load Google Analytics 4 from one place” task first: MonsterInsights writes the full address, including gclid, into the page on the server, so a cached copy would hide the gclid from it.', 'shd-performance' ),
					__( 'Then ask your host (or use a CDN, see below) to serve cached pages when the query string only contains marketing parameters: utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, gbraid, wbraid, gad_source, gad_campaignid, fbclid, msclkid, li_fat_id.', 'shd-performance' ),
					__( 'Afterwards, check in Google Ads and GA4 that conversions and paid sessions are still attributed correctly.', 'shd-performance' ),
				),
				'auto'     => array( __CLASS__, 'auto_health' ),
				'check' => 'cache_campaign',
			),
			array(
				'id'    => 'server-ttfb',
				'group' => __( 'Caching and server', 'shd-performance' ),
				'title' => __( 'Find out why WordPress needs about 2.5 s per uncached page', 'shd-performance' ),
				'why'   => __( 'Cache misses, logged-in editors, form submissions and the admin all wait for this. A healthy Elementor site builds a page in 0.3–0.8 s.', 'shd-performance' ),
				'steps' => array(
					__( 'Look at the server items in the health checks: PHP version (8.2+), OPcache, persistent object cache (ask the host for Redis), autoloaded options, debug settings.', 'shd-performance' ),
					__( 'On staging, install Query Monitor and open a page with ?nocache=1 in the URL: the “Queries by Component” and “HTTP API Calls” panels show which plugin is slow. External HTTP calls during page load (for example by custom plugins) are a common cause.', 'shd-performance' ),
					__( 'With SSH access, “wp profile stage” and “wp profile hook --spotlight” (WP-CLI profile package) show the slowest hooks.', 'shd-performance' ),
					__( 'Remove or replace the slowest plugin, or move to faster hosting (PHP workers, NVMe, LiteSpeed/nginx).', 'shd-performance' ),
				),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'ttfb_uncached',
			),
			array(
				'id'       => 'cdn',
				'group'    => __( 'Caching and server', 'shd-performance' ),
				'title'    => __( 'Optional: edge caching with a CDN', 'shd-performance' ),
				'optional' => true,
				'why'      => __( 'Google’s test servers and visitors outside Switzerland get the page from a nearby edge server in about 50–100 ms, with Brotli and HTTP/3 included. This also makes PageSpeed results more consistent.', 'shd-performance' ),
				'steps'    => array(
					__( 'Example: Cloudflare (free plan) with “Automatic Platform Optimization” for WordPress (paid add-on), which caches the HTML at the edge and handles logged-in users and cookies correctly.', 'shd-performance' ),
					__( 'Keep CookieFirst and the forms working: test on staging or with a subdomain first.', 'shd-performance' ),
				),
			),
			array(
				'id'    => 'fonts-woff2',
				'group' => __( 'Fonts and icons', 'shd-performance' ),
				'title' => __( 'Upload WOFF2 versions of Fractul Office', 'shd-performance' ),
				'why'   => __( 'The four .ttf files weigh 1.12 MB. The same fonts as WOFF2 are 58 KB each (lossless), and the Latin subsets that German and English text need are 25 KB each, so every first visit loads about 1 MB less.', 'shd-performance' ),
				'steps' => array(
					__( 'Check that your font licence allows web use (the site already serves the font, so this is usually covered).', 'shd-performance' ),
					__( 'Use the 8 files you received with this plugin: Fractul_Office-Regular.woff2 … ExtraBold.woff2 (full fonts) and Fractul_Office-Regular-latin.woff2 … ExtraBold-latin.woff2 (Latin subsets). To make them yourself with the free tool fonttools: “fonttools ttLib.woff2 compress Fractul_Office-Regular.ttf” for the full font, and pyftsubset with --flavor=woff2, --layout-features=* and the --unicodes range shown on the Fonts tab for the subset.', 'shd-performance' ),
					__( 'Upload all 8 on the Fonts tab; they are used immediately (no Elementor change needed).', 'shd-performance' ),
					__( 'On the Fonts tab, keep the weights used in the first screen selected for preloading (Regular and Bold on this site).', 'shd-performance' ),
				),
				'links' => array( __( 'Fonts tab', 'shd-performance' ) => admin_url( 'admin.php?page=shd-performance&tab=fonts' ) ),
				'auto'  => array( __CLASS__, 'auto_fonts' ),
			),
			array(
				'id'    => 'font-icons',
				'group' => __( 'Fonts and icons', 'shd-performance' ),
				'title' => __( 'Switch Elementor icons to inline SVG', 'shd-performance' ),
				'why'   => __( 'Removes 4 Font Awesome stylesheets and about 170 KB of icon fonts from every page.', 'shd-performance' ),
				'steps' => array(
					__( 'Elementor → Settings → Features (or Performance): set “Inline Font Icons” to Active and save.', 'shd-performance' ),
					__( 'Check the icons in the header, footer, buttons and icon lists. Custom CSS that targets “i.fa-…” elements may need to target “svg.e-font-icon-svg” instead.', 'shd-performance' ),
					__( 'Purge the page cache.', 'shd-performance' ),
				),
				'links' => array( __( 'Elementor settings', 'shd-performance' ) => $elementor ),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'font_icons',
			),
			array(
				'id'    => 'qi-addons',
				'group' => __( 'Add-ons and widgets', 'shd-performance' ),
				'title' => __( 'Deactivate Qi Addons for Elementor', 'shd-performance' ),
				'why'   => __( 'No page uses a Qi widget, but the plugin still runs on every request. SHD Performance already removes its files from the pages; deactivating it also saves server time.', 'shd-performance' ),
				'steps' => array(
					__( 'Run “Scan pages” on the Tools tab and confirm Qi Addons is used on 0 documents.', 'shd-performance' ),
					__( 'Deactivate Qi Addons for Elementor (on staging first) and check the carousels: Elementor then loads its own copy of the Swiper library.', 'shd-performance' ),
				),
				'links' => array( __( 'Plugins', 'shd-performance' ) => admin_url( 'plugins.php' ) ),
				'auto'  => array( __CLASS__, 'auto_qi' ),
			),
			array(
				'id'       => 'lastudio',
				'group'    => __( 'Add-ons and widgets', 'shd-performance' ),
				'title'    => __( 'Optional: replace the LA-Studio widgets, then deactivate LA-Studio Kit', 'shd-performance' ),
				'optional' => true,
				'why'      => __( 'LA-Studio Kit provides the timeline on three pages and the Google Map on /kontakt/ (which loads the full Google Maps API). SHD Performance already removes its files elsewhere, so this mainly saves server time and the heavy map.', 'shd-performance' ),
				'steps'    => array(
					__( 'Replace the map on /kontakt/ with Elementor’s own “Google Maps” widget (no API key, loads only when scrolled into view).', 'shd-performance' ),
					__( 'Rebuild the timelines with Elementor containers and the Icon List widget, or keep LA-Studio for them.', 'shd-performance' ),
					__( 'LA-Studio also handles the transparent header; check the header on all breakpoints after deactivating it.', 'shd-performance' ),
					__( 'The pages that use LA-Studio widgets are listed on the Tools tab.', 'shd-performance' ),
				),
				'links'    => array( __( 'Tools tab', 'shd-performance' ) => admin_url( 'admin.php?page=shd-performance&tab=tools' ) ),
			),
			array(
				'id'    => 'hosted-video',
				'group' => __( 'Add-ons and widgets', 'shd-performance' ),
				'title' => __( 'Check the autoplaying self-hosted video', 'shd-performance' ),
				'why'   => __( 'A video that plays automatically downloads during page load (often several MB).', 'shd-performance' ),
				'steps' => array(
					__( 'Keep it short and compressed: H.264 MP4 or WebM, 720p, ideally under 2 MB, muted, with a poster image.', 'shd-performance' ),
					__( 'If it is decorative, an animated WebP/AVIF or a still image is lighter. If it is far down the page, set “Lazy load” in the video widget.', 'shd-performance' ),
				),
				'auto'  => array( __CLASS__, 'auto_hosted_video' ),
			),
			array(
				'id'    => 'images',
				'group' => __( 'Images', 'shd-performance' ),
				'title' => __( 'Replace oversized image files (no visible quality loss)', 'shd-performance' ),
				'why'   => __( 'The /resultate/ page alone loads about 3.5 MB more image data than needed (mockups of 450–740 KB each).', 'shd-performance' ),
				'steps' => array(
					__( 'For each file in the list on the Tools tab: export it at the largest size it is displayed, times two for sharp screens (rarely more than 1600 px wide), as WebP at quality 80–85 (visually lossless).', 'shd-performance' ),
					__( 'Replace the file while keeping its URL (for example with the “Enable Media Replace” plugin), so no page needs editing.', 'shd-performance' ),
					__( 'Keep the originals in your archive.', 'shd-performance' ),
				),
				'links' => array( __( 'Tools tab', 'shd-performance' ) => admin_url( 'admin.php?page=shd-performance&tab=tools' ) ),
				'auto'  => array( __CLASS__, 'auto_images' ),
			),
			array(
				'id'    => 'image-sizes',
				'group' => __( 'Images', 'shd-performance' ),
				'title' => __( 'Use smaller image sizes in widgets, and an SVG logo', 'shd-performance' ),
				'why'   => __( 'The avatars next to “80+ Schweizer Betriebe…” load 768 px files for 34 px circles; the blog grid loads full-size featured images; the logo is a 2,560 px PNG.', 'shd-performance' ),
				'steps' => array(
					__( 'Homepage hero, avatar images: set “Image Size” to Thumbnail (150 × 150).', 'shd-performance' ),
					__( 'Blog page and post-loop templates: set the image size to “Medium Large” (768 px) instead of “Full”. Where the card image is a container background (Background → Image, dynamic “Featured Image”), choose the image size there too.', 'shd-performance' ),
					__( 'Video covers on /google-ads-agentur/ and /web-design-agentur/: in the Video widget, Image Overlay → Image Resolution: “Large” (1024 px) instead of “Full”. The cover is shown at most about 550 px wide, but the full file is 391 KB and is the largest element on phones.', 'shd-performance' ),
					__( 'Image carousels on /resultate/: set “Image Resolution” to “Large” (1024 px); the mockups are 450–740 KB each at full size.', 'shd-performance' ),
					__( 'Logo: upload it as SVG (Safe SVG is installed) or as a PNG/WebP about 480 px wide, and use it in the header template’s Site Logo widget.', 'shd-performance' ),
				),
				'links' => array( __( 'Media library', 'shd-performance' ) => admin_url( 'upload.php' ) ),
			),
			array(
				'id'    => 'tracking-ga',
				'group' => __( 'Tracking and consent', 'shd-performance' ),
				'title' => __( 'Load Google Analytics 4 from one place', 'shd-performance' ),
				'why'   => __( 'MonsterInsights and SHD Tracking Manager both configure the same GA4 property. That is extra JavaScript and can count page views twice. In our test, MonsterInsights also sent a GA4 hit before any cookie choice was made. And on cached pages it reports a wrong address to GA4: it writes the address of the request that filled the cache into the page, which for the Kadence Performance preloader ends in “?swpsp_cache_bust=1&nocache=1”. That splits your page reports.', 'shd-performance' ),
				'steps' => array(
					__( 'Keep the consent-aware setup in SHD Tracking Manager (it also sends the Google Ads conversions) and turn off GA4 in MonsterInsights, or deactivate MonsterInsights if you do not use its reports.', 'shd-performance' ),
					__( 'Check GA4 → Realtime / DebugView that page views arrive once.', 'shd-performance' ),
					__( 'Ask whoever is responsible for privacy to confirm the opt-in behaviour.', 'shd-performance' ),
				),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'ga_duplicate',
			),
			array(
				'id'       => 'trackers',
				'group'    => __( 'Tracking and consent', 'shd-performance' ),
				'title'    => __( 'Review the trackers loaded after consent', 'shd-performance' ),
				'optional' => true,
				'why'      => __( 'After a visitor accepts cookies, SHD Tracking Manager loads Hotjar, LinkedIn, TikTok, OpenAI and Leadinfo. Hotjar in particular costs processor time on phones, which affects the real-visitor INP score.', 'shd-performance' ),
				'steps'    => array(
					__( 'Switch off the ones you no longer use in the tracking manager’s settings.', 'shd-performance' ),
					__( 'Hotjar: limit recording to the pages you analyse, or sample.', 'shd-performance' ),
				),
			),
			array(
				'id'    => 'cookie-banner',
				'group' => __( 'Tracking and consent', 'shd-performance' ),
				'title' => __( 'CookieFirst banner: contrast and size on phones', 'shd-performance' ),
				'why'   => __( 'The privacy link in the banner has a contrast of 1.76:1 (4.5:1 is required) and the banner covers about two thirds of a phone screen.', 'shd-performance' ),
				'steps' => array(
					__( 'In the CookieFirst dashboard, choose a darker link colour on the light-green background (for example the site’s dark blue #001E5A).', 'shd-performance' ),
					__( 'Shorten the banner text on mobile or choose a compact layout.', 'shd-performance' ),
				),
				'links' => array( 'CookieFirst' => 'https://app.cookiefirst.com/' ),
			),
			array(
				'id'       => 'recaptcha',
				'group'    => __( 'Tracking and consent', 'shd-performance' ),
				'title'    => __( 'Optional: replace reCAPTCHA', 'shd-performance' ),
				'optional' => true,
				'why'      => __( 'reCAPTCHA now loads only when a form is used. Elementor Pro’s honeypot field (or Cloudflare Turnstile) avoids Google’s 349 KB script and its cookies entirely.', 'shd-performance' ),
				'steps'    => array(
					__( 'In each form, add a “Honeypot” field and test the spam rate for a few weeks before removing reCAPTCHA.', 'shd-performance' ),
				),
			),
			array(
				'id'    => 'accessibility',
				'group' => __( 'Accessibility', 'shd-performance' ),
				'title' => __( 'Fix the accessibility findings', 'shd-performance' ),
				'why'   => __( 'Found by Lighthouse and the browser’s accessibility tree; they also count in the PageSpeed accessibility score.', 'shd-performance' ),
				'steps' => array(
					__( 'Header: the menu icon and the back-to-top icon are links without a name. In each Icon widget: Advanced → Attributes, add “aria-label|Menü öffnen” and “aria-label|Nach oben”.', 'shd-performance' ),
					__( 'The “Skip to content” link points to #content, which does not exist: give the main content container the CSS ID “content” (Advanced → CSS ID) in the page and single templates.', 'shd-performance' ),
					__( 'Homepage: “80+ Schweizer Betriebe vertrauen auf uns” is an H4 right after the H1; make it an H2 (style it the same) or a paragraph.', 'shd-performance' ),
					__( 'Media library: give the logo a meaningful alternative text (for example “SH Digital”).', 'shd-performance' ),
				),
			),
			array(
				'id'    => 'redirects',
				'group' => __( 'Domain and SEO hygiene', 'shd-performance' ),
				'title' => __( 'Redirect old domains to https://www.sh-digital.ch/ in one step', 'shd-performance' ),
				'why'   => __( 'shdigital.ch currently goes through two redirects (shdigital.ch → sh-digital.ch → www.sh-digital.ch).', 'shd-performance' ),
				'steps' => array(
					__( 'At the host or registrar, point shdigital.ch (and www.shdigital.ch) with a 301 straight to https://www.sh-digital.ch/ (keeping the path).', 'shd-performance' ),
					__( 'Use https://www.sh-digital.ch/ in Google Business Profile, ads and social profiles.', 'shd-performance' ),
				),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'redirects',
			),
			array(
				'id'    => 'robots',
				'group' => __( 'Domain and SEO hygiene', 'shd-performance' ),
				'title' => __( 'Remove the non-www sitemap line from robots.txt', 'shd-performance' ),
				'why'   => __( 'robots.txt lists https://sh-digital.ch/sitemap_index.xml in addition to the www version.', 'shd-performance' ),
				'steps' => array(
					__( 'Yoast SEO → Tools → File editor (or the robots.txt file on the server): delete the line with the non-www sitemap.', 'shd-performance' ),
				),
				'links' => array( __( 'Yoast file editor', 'shd-performance' ) => admin_url( 'admin.php?page=wpseo_tools&tool=file-editor' ) ),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'robots',
			),
			array(
				'id'    => 'compression',
				'group' => __( 'Domain and SEO hygiene', 'shd-performance' ),
				'title' => __( 'Enable Brotli compression (and HTTP/3) at the host', 'shd-performance' ),
				'why'   => __( 'The server uses gzip. Brotli makes text files about 15–20% smaller; HTTP/3 speeds up connections on mobile networks.', 'shd-performance' ),
				'steps' => array(
					__( 'Ask your host to enable mod_brotli (Apache) and HTTP/3, or use a CDN that provides both.', 'shd-performance' ),
				),
				'auto'  => array( __CLASS__, 'auto_health' ),
				'check' => 'compression',
			),
			array(
				'id'    => 'verify-lab',
				'group' => __( 'Verify', 'shd-performance' ),
				'title' => __( 'Verify with PageSpeed Insights (mobile and desktop)', 'shd-performance' ),
				'why'   => __( 'Target: 95+ on both. PageSpeed varies by about ±5 points between runs, so test each page two or three times.', 'shd-performance' ),
				'steps' => array(
					__( 'Purge the page cache, open each page once on a phone (so it is cached), then run PageSpeed Insights.', 'shd-performance' ),
					__( 'If a page stays below target, its report shows the remaining items; most will be covered by the tasks above.', 'shd-performance' ),
				),
				'links' => array(
					__( 'Homepage', 'shd-performance' )           => $psi . rawurlencode( $home ),
					'/geo-agentur/'                              => $psi . rawurlencode( home_url( '/geo-agentur/' ) ),
					'/google-ads-agentur/'                       => $psi . rawurlencode( home_url( '/google-ads-agentur/' ) ),
					'/kontakt/'                                  => $psi . rawurlencode( home_url( '/kontakt/' ) ),
					'/generative-engine-optimization/'           => $psi . rawurlencode( home_url( '/generative-engine-optimization/' ) ),
				),
			),
			array(
				'id'    => 'verify-field',
				'group' => __( 'Verify', 'shd-performance' ),
				'title' => __( 'Verify the real-visitor Core Web Vitals after 2–4 weeks', 'shd-performance' ),
				'why'   => __( 'Core Web Vitals “pass” is decided on real visits (75th percentile: LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1). Google has no such data for this site yet; the Web Vitals tab shows it from your own visitors.', 'shd-performance' ),
				'steps' => array(
					__( 'Check the Web Vitals tab: mobile and desktop should show “good” for LCP, INP and CLS.', 'shd-performance' ),
					__( 'Once Google has enough data, Search Console → Core Web Vitals shows the same assessment.', 'shd-performance' ),
				),
				'links' => array(
					__( 'Web Vitals tab', 'shd-performance' ) => admin_url( 'admin.php?page=shd-performance&tab=vitals' ),
					'Search Console'                         => 'https://search.google.com/search-console/core-web-vitals',
				),
				'auto'  => array( __CLASS__, 'auto_field' ),
			),
		);
	}

	/**
	 * Stored manual marks: task id => timestamp.
	 *
	 * @return array<string,int>
	 */
	public static function marks() {
		$marks = get_option( self::OPTION, array() );
		return is_array( $marks ) ? $marks : array();
	}

	/**
	 * @param string $id   Task id.
	 * @param bool   $done Done or open.
	 */
	public static function mark( $id, $done ) {
		self::$statuses = array();
		$marks          = self::marks();
		if ( $done ) {
			$marks[ $id ] = time();
		} else {
			unset( $marks[ $id ] );
		}
		update_option( self::OPTION, $marks, false );
	}

	/**
	 * Status of a task: automatic detection first, then the manual mark.
	 *
	 * @param array $task Task.
	 * @return array{status:string,note:string,manual:bool}
	 */
	public static function status( array $task ) {
		if ( isset( self::$statuses[ $task['id'] ] ) ) {
			return self::$statuses[ $task['id'] ];
		}
		$auto = array(
			'status' => 'unknown',
			'note'   => '',
		);
		if ( isset( $task['auto'] ) && is_callable( $task['auto'] ) ) {
			$auto = call_user_func( $task['auto'], $task );
		}
		$marks  = self::marks();
		$manual = isset( $marks[ $task['id'] ] );
		if ( 'done' !== $auto['status'] && $manual ) {
			$auto['status'] = 'done';
			$auto['note']   = trim( $auto['note'] . ' ' . __( '(marked as done)', 'shd-performance' ) );
		}
		$auto['manual'] = $manual;

		self::$statuses[ $task['id'] ] = $auto;
		return $auto;
	}

	/**
	 * First required task that is not done.
	 *
	 * @return array{task:array,step:int}|null Task and its 1-based step in the guide.
	 */
	public static function next() {
		foreach ( self::tasks() as $index => $task ) {
			if ( empty( $task['optional'] ) && 'done' !== self::status( $task )['status'] ) {
				return array(
					'task' => $task,
					'step' => $index + 1,
				);
			}
		}
		return null;
	}

	/**
	 * @return array{done:int,total:int}
	 */
	public static function progress() {
		$done  = 0;
		$total = 0;
		foreach ( self::tasks() as $task ) {
			if ( ! empty( $task['optional'] ) ) {
				continue;
			}
			++$total;
			if ( 'done' === self::status( $task )['status'] ) {
				++$done;
			}
		}
		return array(
			'done'  => $done,
			'total' => $total,
		);
	}

	/* Automatic status detectors. */

	public static function auto_health( array $task ) {
		$health = Health_Checks::last();
		$result = null;
		// The first check (in the task's order) that has a result decides.
		foreach ( (array) $task['check'] as $check ) {
			if ( null !== $health && isset( $health['results'][ $check ] ) ) {
				$result = $health['results'][ $check ];
				break;
			}
		}
		if ( null === $result ) {
			return array(
				'status' => 'unknown',
				'note'   => __( 'Run the health checks to detect this.', 'shd-performance' ),
			);
		}
		return array(
			'status' => 'good' === $result['status'] ? 'done' : ( 'info' === $result['status'] ? 'unknown' : 'open' ),
			'note'   => $result['value'],
		);
	}

	public static function auto_preview() {
		$test = array();
		foreach ( Settings::features() as $id => $feature ) {
			if ( 'test' === Settings::state( $id ) ) {
				$test[] = $feature['title'];
			}
		}
		return array(
			'status' => empty( $test ) ? 'done' : 'open',
			/* translators: %d: number of features */
			'note'   => empty( $test ) ? __( 'No feature is waiting in Test.', 'shd-performance' ) : sprintf( _n( '%d feature is in Test.', '%d features are in Test.', count( $test ), 'shd-performance' ), count( $test ) ),
		);
	}

	public static function auto_fonts() {
		$faces = Fonts::faces();
		if ( empty( $faces ) ) {
			return array(
				'status' => 'unknown',
				'note'   => __( 'Open the Fonts tab and press “Detect fonts”.', 'shd-performance' ),
			);
		}
		$missing = 0;
		foreach ( $faces as $face ) {
			if ( ! Fonts::has_woff2( $face ) ) {
				++$missing;
			}
		}
		return array(
			'status' => 0 === $missing ? 'done' : 'open',
			/* translators: %d: number of fonts */
			'note'   => 0 === $missing ? __( 'All font files have a WOFF2 version.', 'shd-performance' ) : sprintf( _n( '%d font file still without WOFF2.', '%d font files still without WOFF2.', $missing, 'shd-performance' ), $missing ),
		);
	}

	public static function auto_qi() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'qi-addons-for-elementor/qi-addons-for-elementor.php' ) ) {
			return array(
				'status' => 'done',
				'note'   => __( 'Qi Addons is not active.', 'shd-performance' ),
			);
		}
		$scan = Site_Scanner::last();
		if ( null === $scan ) {
			return array(
				'status' => 'open',
				'note'   => __( 'Run “Scan pages” on the Tools tab to confirm it is unused.', 'shd-performance' ),
			);
		}
		/* translators: %d: documents */
		return array(
			'status' => 'open',
			'note'   => sprintf( _n( 'Used in %d document.', 'Used in %d documents.', count( $scan['qi'] ), 'shd-performance' ), count( $scan['qi'] ) ),
		);
	}

	public static function auto_hosted_video() {
		$scan = Site_Scanner::last();
		if ( null === $scan ) {
			return array(
				'status' => 'unknown',
				'note'   => __( 'Run “Scan pages” on the Tools tab.', 'shd-performance' ),
			);
		}
		$pages = array();
		foreach ( $scan['videos'] as $video ) {
			if ( 'youtube' !== $video['source'] && $video['autoplay'] ) {
				$pages[] = $video['title'];
			}
		}
		return array(
			'status' => empty( $pages ) ? 'done' : 'open',
			'note'   => empty( $pages ) ? __( 'No autoplaying self-hosted video found.', 'shd-performance' ) : implode( ', ', array_unique( $pages ) ),
		);
	}

	public static function auto_images() {
		$scan = Site_Scanner::last();
		if ( null === $scan ) {
			return array(
				'status' => 'unknown',
				'note'   => __( 'Run “Scan pages” on the Tools tab.', 'shd-performance' ),
			);
		}
		$big = 0;
		foreach ( $scan['images'] as $image ) {
			if ( $image['bytes'] >= 300 * KB_IN_BYTES ) {
				++$big;
			}
		}
		return array(
			'status' => 0 === $big ? 'done' : 'open',
			/* translators: %d: files */
			'note'   => sprintf( _n( '%d image file over 300 KB.', '%d image files over 300 KB.', $big, 'shd-performance' ), $big ),
		);
	}

	public static function auto_field() {
		$summary = Rum::summary();
		$mobile  = $summary['m'];
		if ( $mobile['count'] < 50 ) {
			return array(
				'status' => 'unknown',
				/* translators: %d: page views */
				'note'   => sprintf( __( '%d mobile page views measured so far; about 50 are needed for a stable result.', 'shd-performance' ), $mobile['count'] ),
			);
		}
		$pass = 'good' === Rum::rating( 'lcp', $mobile['lcp'] ) && 'good' === Rum::rating( 'inp', $mobile['inp'] ) && 'good' === Rum::rating( 'cls', $mobile['cls'] );
		return array(
			'status' => $pass ? 'done' : 'open',
			'note'   => $pass ? __( 'Mobile LCP, INP and CLS are all good.', 'shd-performance' ) : __( 'At least one mobile Core Web Vital is not good yet.', 'shd-performance' ),
		);
	}
}
