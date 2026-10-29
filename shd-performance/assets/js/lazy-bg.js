/**
 * SHD Performance: lazy background images.
 *
 * Elements marked data-shd-lazy-bg="view" get their background images (CSS
 * keeps them off until .shd-bg-in is added) when they come within about one
 * screen of the viewport; lazy carousel slides inside them (data-shd-lazy-img)
 * switch to eager loading at the same time. "idle" elements (the mobile menu,
 * popups) are hidden when the page loads; they get theirs shortly after loading
 * or on the first tap.
 */
( function ( w, d ) {
	'use strict';

	var marked = d.querySelectorAll( '[data-shd-lazy-bg]' );
	var view = [];
	var idle = [];
	var i;

	function show( el ) {
		el.classList.add( 'shd-bg-in' );
		// Carousel slides marked lazy load together once their carousel is near.
		var images = el.querySelectorAll( 'img[data-shd-lazy-img]' );
		for ( var k = 0; k < images.length; k++ ) {
			images[ k ].loading = 'eager';
		}
	}

	for ( i = 0; i < marked.length; i++ ) {
		( 'idle' === marked[ i ].getAttribute( 'data-shd-lazy-bg' ) ? idle : view ).push( marked[ i ] );
	}

	if ( 'IntersectionObserver' in w ) {
		var observer = new w.IntersectionObserver( function ( entries ) {
			for ( var j = 0; j < entries.length; j++ ) {
				if ( entries[ j ].isIntersecting ) {
					show( entries[ j ].target );
					observer.unobserve( entries[ j ].target );
				}
			}
		}, { rootMargin: '600px 0px' } );
		for ( i = 0; i < view.length; i++ ) {
			observer.observe( view[ i ] );
		}
	} else {
		view.forEach( show );
	}

	var done = false;
	function later() {
		if ( done ) {
			return;
		}
		done = true;
		idle.forEach( show );
	}
	[ 'pointerdown', 'touchstart', 'keydown' ].forEach( function ( type ) {
		w.addEventListener( type, later, { capture: true, passive: true, once: true } );
	} );
	if ( 'complete' === d.readyState ) {
		setTimeout( later, 2000 );
	} else {
		w.addEventListener( 'load', function () {
			setTimeout( later, 2000 );
		} );
	}
}( window, document ) );
