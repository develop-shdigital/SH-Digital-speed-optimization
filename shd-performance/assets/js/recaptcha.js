/**
 * SHD Performance: load reCAPTCHA when a form is used.
 *
 * The api.js tag is held as type="shd/recaptcha" and loaded on the first
 * focus/touch/key press inside a form. Elementor Pro polls for grecaptcha every
 * 350 ms and attaches its v3 submit handler once it exists; a click on a submit
 * button before that is held and repeated when reCAPTCHA is ready.
 */
( function ( w, d ) {
	'use strict';

	var loaded = false;
	var loadedAt = 0;

	function load() {
		if ( loaded ) {
			return;
		}
		loaded = true;
		var held = d.querySelector( 'script[type="shd/recaptcha"]' );
		if ( ! held ) {
			return;
		}
		var script = d.createElement( 'script' );
		script.src = held.getAttribute( 'data-shd-src' );
		script.async = true;
		script.onload = function () {
			loadedAt = Date.now();
		};
		held.parentNode.replaceChild( script, held );
	}

	function apiReady() {
		return !! ( w.grecaptcha && w.grecaptcha.render );
	}

	function inForm( event ) {
		return event.target && event.target.closest && event.target.closest( 'form' );
	}

	[ 'focusin', 'pointerdown', 'touchstart', 'keydown' ].forEach( function ( type ) {
		d.addEventListener( type, function ( event ) {
			if ( inForm( event ) ) {
				load();
			}
		}, { capture: true, passive: true } );
	} );

	d.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[type="submit"], button:not([type])' ) : null;
		var form = button && button.closest( 'form' );
		if ( ! form || ! form.querySelector( '.elementor-g-recaptcha' ) || form.getAttribute( 'data-shd-rc' ) ) {
			return;
		}
		if ( apiReady() && loadedAt && Date.now() - loadedAt > 1000 ) {
			form.setAttribute( 'data-shd-rc', '1' );
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		load();
		var waited = 0;
		( function wait() {
			if ( apiReady() || waited > 15000 ) {
				// Give Elementor's 350 ms poll time to attach its submit handler.
				setTimeout( function () {
					form.setAttribute( 'data-shd-rc', '1' );
					button.click();
				}, 450 );
				return;
			}
			waited += 100;
			setTimeout( wait, 100 );
		}() );
	}, true );
}( window, document ) );
