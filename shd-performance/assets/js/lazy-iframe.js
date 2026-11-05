/**
 * SHD Performance: iframes from other sites (embedded apps, maps, forms) start on the
 * visitor's first interaction (scroll, wheel, mouse movement, tap, key press), then
 * load when they are within half a screen of the viewport. Without any interaction
 * they start a while after the page has loaded (window.shdPerfFrames.timeout, ms).
 *
 * An embedded app runs its own scripts; started with the page, the chart app on the
 * industry case study kept a phone's main thread busy for more than half a second
 * while the page loaded (its own tag manager, TikTok pixel and reCAPTCHA). The browser's
 * own loading="lazy" starts iframes up to several screens ahead. The iframe keeps its
 * size, so nothing moves when it loads.
 */
( function ( w, d ) {
	'use strict';

	var frames = d.querySelectorAll( 'iframe[data-shd-src]' );
	var config = w.shdPerfFrames || {};
	var events = [ 'scroll', 'wheel', 'mousemove', 'pointerdown', 'touchstart', 'keydown' ];
	var started = false;
	var timer = 0;
	var i;

	function load( frame ) {
		var src = frame.getAttribute( 'data-shd-src' );
		if ( src ) {
			frame.removeAttribute( 'data-shd-src' );
			frame.src = src;
		}
	}

	function all() {
		for ( var k = 0; k < frames.length; k++ ) {
			load( frames[ k ] );
		}
	}

	function start() {
		if ( started ) {
			return;
		}
		started = true;
		w.clearTimeout( timer );
		for ( var e = 0; e < events.length; e++ ) {
			w.removeEventListener( events[ e ], start, true );
		}
		if ( ! ( 'IntersectionObserver' in w ) ) {
			all();
			return;
		}
		var observer = new w.IntersectionObserver( function ( entries ) {
			for ( var j = 0; j < entries.length; j++ ) {
				if ( entries[ j ].isIntersecting ) {
					observer.unobserve( entries[ j ].target );
					load( entries[ j ].target );
				}
			}
		}, { rootMargin: '400px 0px' } );
		for ( var f = 0; f < frames.length; f++ ) {
			observer.observe( frames[ f ] );
		}
	}

	function later() {
		timer = w.setTimeout( start, config.timeout > 0 ? config.timeout : 10000 );
	}

	if ( ! frames.length ) {
		return;
	}
	for ( i = 0; i < events.length; i++ ) {
		w.addEventListener( events[ i ], start, { capture: true, passive: true } );
	}
	if ( 'complete' === d.readyState ) {
		later();
	} else {
		w.addEventListener( 'load', later );
	}
	// Printing shows the whole page: load every frame first.
	w.addEventListener( 'beforeprint', all );
}( window, document ) );
