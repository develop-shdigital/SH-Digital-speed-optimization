/**
 * SHD Performance: autoplaying videos further down the page.
 *
 * Elementor video widgets below the first screen are sent without their
 * autoplay attribute and with preload="none", so they download nothing during
 * page load. When one comes within about one screen of the viewport it gets
 * both back and loads, exactly as it would have at page load: it plays where
 * the browser allows autoplay (for example when it is muted).
 */
( function ( w, d ) {
	'use strict';

	var videos = d.querySelectorAll( 'video[data-shd-autoplay]' );
	var i;

	function start( video ) {
		video.removeAttribute( 'data-shd-autoplay' );
		video.preload = video.getAttribute( 'data-shd-preload' ) || 'auto';
		video.autoplay = true;
		video.load();
	}

	if ( ! ( 'IntersectionObserver' in w ) ) {
		for ( i = 0; i < videos.length; i++ ) {
			start( videos[ i ] );
		}
		return;
	}
	var observer = new w.IntersectionObserver( function ( entries ) {
		for ( var j = 0; j < entries.length; j++ ) {
			if ( entries[ j ].isIntersecting ) {
				observer.unobserve( entries[ j ].target );
				start( entries[ j ].target );
			}
		}
	}, { rootMargin: '600px 0px' } );
	for ( i = 0; i < videos.length; i++ ) {
		observer.observe( videos[ i ] );
	}
}( window, document ) );
