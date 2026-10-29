/**
 * SHD Performance: click-to-play YouTube facade.
 *
 * Clicking a [data-shd-yt] button replaces it with the YouTube player and starts
 * playback with that click. iOS and Android need the IFrame API to start
 * playback from the same tap, so they get the API player instead of a plain iframe.
 */
( function ( w, d ) {
	'use strict';

	var api;

	function needsApi() {
		var ua = w.navigator.userAgent || '';
		return /iP(ad|hone|od)|Android|Mobi/i.test( ua ) || ( w.navigator.vendor || '' ).indexOf( 'Apple' ) > -1;
	}

	function loadApi() {
		if ( api ) {
			return api;
		}
		api = new Promise( function ( resolve ) {
			if ( w.YT && w.YT.Player ) {
				resolve( w.YT );
				return;
			}
			var previous = w.onYouTubeIframeAPIReady;
			w.onYouTubeIframeAPIReady = function () {
				if ( 'function' === typeof previous ) {
					try {
						previous();
					} catch ( e ) {}
				}
				resolve( w.YT );
			};
			var script = d.createElement( 'script' );
			script.src = 'https://www.youtube.com/iframe_api';
			script.async = true;
			d.head.appendChild( script );
		} );
		return api;
	}

	function parse( query ) {
		var vars = {};
		query.split( '&' ).forEach( function ( pair ) {
			if ( ! pair ) {
				return;
			}
			var parts = pair.split( '=' );
			vars[ decodeURIComponent( parts[ 0 ] ) ] = decodeURIComponent( parts[ 1 ] || '' );
		} );
		return vars;
	}

	function play( button ) {
		var id = button.getAttribute( 'data-shd-yt' );
		var host = button.getAttribute( 'data-shd-yt-host' ) || 'www.youtube.com';
		var query = button.getAttribute( 'data-shd-yt-params' ) || '';
		var title = button.getAttribute( 'data-shd-yt-title' ) || 'YouTube';

		if ( needsApi() ) {
			var holder = d.createElement( 'div' );
			holder.className = 'shd-yt-player';
			button.parentNode.replaceChild( holder, button );
			loadApi().then( function ( YT ) {
				var vars = parse( query );
				vars.autoplay = 1;
				new YT.Player( holder, {
					host: 'https://' + host,
					videoId: id,
					width: '100%',
					height: '100%',
					playerVars: vars,
					events: {
						onReady: function ( event ) {
							event.target.playVideo();
						},
					},
				} );
			} );
			return;
		}

		var frame = d.createElement( 'iframe' );
		frame.src = 'https://' + host + '/embed/' + id + '?autoplay=1' + ( query ? '&' + query : '' );
		frame.title = title;
		frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
		frame.allowFullscreen = true;
		frame.setAttribute( 'frameborder', '0' );
		frame.className = 'shd-yt-player';
		button.parentNode.replaceChild( frame, button );
		frame.focus();
	}

	d.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-shd-yt]' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		play( button );
	} );
}( window, document ) );
