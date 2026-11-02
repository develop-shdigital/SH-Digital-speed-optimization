=== SHD Consent ===
Contributors: shdigital
Tags: cookie banner, consent, gdpr, consent mode
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Own cookie consent banner: no third-party service, no page-view or domain limits, compact on phones, Google Consent Mode v2, scripts held back until consent, consent log.

== Description ==

* The banner is part of the page (a few kilobytes, no request to another server) and is painted with the first frame, so it neither delays the page nor moves anything. On phones it is a compact card covering about a quarter of the screen.
* “Accept all” and “Necessary only” look the same; “Settings” offers the categories Functional, Statistics and Marketing with their services. The colours are checked for a contrast of at least 4.5:1.
* Google Consent Mode v2: everything is denied until the visitor chooses; the choice updates analytics_storage, ad_storage, ad_user_data and ad_personalization.
* Tracking scripts in the page (Google Analytics including MonsterInsights, Hotjar, Clarity, Google Ads, Meta, LinkedIn, TikTok, Bing, Pinterest, Leadinfo, OpenAI) run only after consent to their category; more can be added.
* Compatible with scripts written for CookieFirst: the choice is also stored as cookiefirst-consent and announced with the cf_consent events, so SHD Tracking Manager and SHD Analytics work unchanged, and choices visitors made in CookieFirst are taken over.
* Withdrawing a category deletes the cookies its services set on the site and reloads the page.
* Consent log as proof: time, random consent ID, categories, banner version and page; no IP address, no browser details. Search by consent ID, CSV export, automatic deletion after a set number of months.
* The choice can be changed at any time: small round button, the shortcode [shd_cookie_settings], or any link to #cookie-einstellungen.
* Waits while another banner plugin (CookieFirst, Complianz, CookieYes, Borlabs, Cookiebot, …) is active; administrators can preview it with ?shd-consent-preview=1.

The texts and the list of services must match your privacy policy; have them checked by whoever advises you on data protection.

== Changelog ==

= 1.0.0 =
* First version.
