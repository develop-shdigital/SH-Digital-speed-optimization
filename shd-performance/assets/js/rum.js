/**
 * SHD Performance: real-user Core Web Vitals.
 *
 * After the page has loaded, loads the web-vitals library and sends one beacon
 * per page view (LCP, INP, CLS, FCP, TTFB, device class and path) when the page
 * is hidden. No cookies are set and nothing identifies the visitor.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.shdPerfRum;
	// Real visitors only: automated browsers (tests, PageSpeed's lab runs) report navigator.webdriver.
	if ( ! cfg || ! w.navigator.sendBeacon || w.navigator.webdriver || Math.random() * 100 >= cfg.sample ) {
		return;
	}

	w.addEventListener( 'load', function () {
		setTimeout( function () {
			var script = d.createElement( 'script' );
			script.src = cfg.lib;
			script.async = true;
			script.onload = function () {
				var vitals = w.webVitals;
				if ( ! vitals ) {
					return;
				}
				var mobile = w.matchMedia && w.matchMedia( '(max-width: 767px)' ).matches;
				var data = { p: w.location.pathname, d: mobile ? 'm' : 'd', m: {} };
				var sent = false;

				function record( metric ) {
					data.m[ metric.name ] = metric.value;
				}

				vitals.onLCP( record );
				vitals.onCLS( record );
				vitals.onINP( record );
				vitals.onFCP( record );
				vitals.onTTFB( record );

				function send() {
					if ( sent || ! Object.keys( data.m ).length ) {
						return;
					}
					sent = true;
					w.navigator.sendBeacon( cfg.url, new Blob( [ JSON.stringify( data ) ], { type: 'application/json' } ) );
				}

				// Registered after web-vitals' own listeners, so final CLS/INP values are recorded first.
				d.addEventListener( 'visibilitychange', function () {
					if ( 'hidden' === d.visibilityState ) {
						send();
					}
				} );
				w.addEventListener( 'pagehide', send );
			};
			d.head.appendChild( script );
		}, 1500 );
	} );
}( window, document ) );
