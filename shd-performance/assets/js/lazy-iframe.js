/**
 * SHD Performance: iframes from other sites (embedded apps, maps, forms) below the
 * first section load when they come within half a screen of the viewport.
 *
 * The browser's own loading="lazy" starts iframes up to several screens ahead, so an
 * embedded app further down still loaded, with all its scripts, while the page itself
 * was loading. The iframe keeps its size, so nothing moves when it loads.
 */
( function ( w, d ) {
	'use strict';

	var frames = d.querySelectorAll( 'iframe[data-shd-src]' );
	var i;

	function load( frame ) {
		var src = frame.getAttribute( 'data-shd-src' );
		if ( src ) {
			frame.removeAttribute( 'data-shd-src' );
			frame.src = src;
		}
	}

	if ( ! frames.length ) {
		return;
	}
	if ( ! ( 'IntersectionObserver' in w ) ) {
		for ( i = 0; i < frames.length; i++ ) {
			load( frames[ i ] );
		}
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
	for ( i = 0; i < frames.length; i++ ) {
		observer.observe( frames[ i ] );
	}
	// Printing shows the whole page: load every frame first.
	w.addEventListener( 'beforeprint', function () {
		for ( var k = 0; k < frames.length; k++ ) {
			load( frames[ k ] );
		}
	} );
}( window, document ) );
