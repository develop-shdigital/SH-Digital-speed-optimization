/**
 * SHD Consent: the banner, storing the choice, and starting the scripts that wait for it.
 *
 * The choice is stored in the cookie shd_consent and, for scripts written for
 * CookieFirst (SHD Tracking Manager, SHD Analytics), also as cookiefirst-consent
 * with the cf_consent / cf_consent_loaded events. Each choice is logged without
 * any personal data (random ID, categories, banner version, page).
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.shdConsentConfig;
	var api = w.shdConsent;
	var box = d.getElementById( 'shd-consent' );
	if ( ! cfg || ! api || ! box ) {
		return;
	}

	var html = d.documentElement;
	var details = box.querySelector( '.shd-consent__details' );
	var moreButton = box.querySelector( '[data-shd-action="details"]' );
	var saveButton = box.querySelector( '[data-shd-action="save"]' );
	var returnFocus = null;

	// First-party cookies of the services, deleted when their category is withdrawn.
	var COOKIES = {
		functional: /^(?!)/,
		performance: /^(_ga|_gid|_gat|_hj|_clck|_clsk|_pk_)/,
		advertising: /^(_gcl|_fbp|_fbc|_uet|_ttp|_tt_|li_|_li|_pin_|_scid|_rdt|_lfa)/
	};

	function uuid() {
		if ( w.crypto && w.crypto.randomUUID ) {
			return w.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = Math.random() * 16 | 0;
			return ( 'x' === c ? r : ( r & 3 | 8 ) ).toString( 16 );
		} );
	}

	// The registrable domain (www.example.ch -> example.ch), or '' for IP addresses and local names.
	function siteDomain() {
		var host = w.location.hostname;
		return /^[\d.]+$|^\[|^localhost$/.test( host ) || host.indexOf( '.' ) < 0 ? '' : host.replace( /^www\./, '' );
	}

	function write( name, value, days, domain ) {
		d.cookie = name + '=' + value + '; Max-Age=' + Math.round( days * 86400 ) + '; Path=/; SameSite=Lax' + ( domain ? '; Domain=' + domain : '' ) + ( 'https:' === w.location.protocol ? '; Secure' : '' );
	}

	// CookieFirst may have stored its cookie for the whole domain or for www only: remove both, then write one.
	function writeShared( name, value, days ) {
		var domain = siteDomain();
		write( name, '', -1 );
		if ( domain ) {
			write( name, '', -1, domain );
		}
		write( name, value, days, domain );
	}

	function notify( s, type ) {
		var detail = { necessary: true, functional: s.functional, performance: s.performance, advertising: s.advertising };
		var names = [ 'shd_consent' ].concat( cfg.cf ? [ type ] : [] );
		[ w, d ].forEach( function ( target ) {
			names.forEach( function ( name ) {
				target.dispatchEvent( new CustomEvent( name, { detail: detail } ) );
			} );
		} );
		// WordPress Consent API, if a plugin provides it.
		if ( 'function' === typeof w.wp_set_consent ) {
			w.wp_consent_type = 'optin';
			w.wp_set_consent( 'functional', 'allow' );
			w.wp_set_consent( 'preferences', s.functional ? 'allow' : 'deny' );
			w.wp_set_consent( 'statistics', s.performance ? 'allow' : 'deny' );
			w.wp_set_consent( 'statistics-anonymous', 'allow' );
			w.wp_set_consent( 'marketing', s.advertising ? 'allow' : 'deny' );
		}
	}

	// Start the held-back scripts of the allowed categories, in page order.
	function release( s ) {
		var waiting = Array.prototype.filter.call( d.querySelectorAll( 'script[type="text/plain"][data-shd-consent]' ), function ( el ) {
			return true === s[ el.getAttribute( 'data-shd-consent' ) ];
		} );
		( function next() {
			var old = waiting.shift();
			var el;
			var i;
			if ( ! old ) {
				return;
			}
			el = d.createElement( 'script' );
			for ( i = 0; i < old.attributes.length; i++ ) {
				if ( -1 === [ 'type', 'data-shd-consent', 'data-shd-type' ].indexOf( old.attributes[ i ].name ) ) {
					el.setAttribute( old.attributes[ i ].name, old.attributes[ i ].value );
				}
			}
			if ( old.getAttribute( 'data-shd-type' ) ) {
				el.setAttribute( 'type', old.getAttribute( 'data-shd-type' ) );
			}
			if ( old.hasAttribute( 'src' ) && ! old.hasAttribute( 'async' ) ) {
				// Keeps the original order: the next script waits for this one.
				el.async = false;
				el.onload = el.onerror = next;
				old.parentNode.replaceChild( el, old );
				return;
			}
			if ( ! old.hasAttribute( 'src' ) ) {
				el.text = old.text;
			}
			old.parentNode.replaceChild( el, old );
			next();
		}() );
	}

	// A withdrawn category: delete the cookies its services set on this site; the page reloads.
	function forget( previous, s ) {
		var withdrawn = api.categories.filter( function ( c ) {
			return previous && true === previous[ c ] && true !== s[ c ];
		} );
		if ( ! withdrawn.length ) {
			return false;
		}
		var domain = siteDomain();
		( d.cookie ? d.cookie.split( '; ' ) : [] ).forEach( function ( part ) {
			var name = part.split( '=' )[ 0 ];
			withdrawn.forEach( function ( c ) {
				if ( COOKIES[ c ].test( name ) ) {
					write( name, '', -1 );
					if ( domain ) {
						write( name, '', -1, domain );
						write( name, '', -1, '.' + domain );
					}
				}
			} );
		} );
		return true;
	}

	function log( value, method ) {
		if ( ! cfg.log ) {
			return;
		}
		var body = JSON.stringify( { id: value.id, v: value.v, c: value.c, m: method, p: w.location.pathname } );
		if ( w.navigator.sendBeacon ) {
			w.navigator.sendBeacon( cfg.log, body );
		} else if ( w.fetch ) {
			w.fetch( cfg.log, { method: 'POST', body: body, keepalive: true } );
		}
	}

	function choose( c, method ) {
		var previous = api.state;
		var now = Math.floor( Date.now() / 1000 );
		var s = {
			functional: true === c.functional,
			performance: true === c.performance,
			advertising: true === c.advertising,
			id: ( previous && previous.id ) || uuid(),
			t: now
		};
		var value = { v: cfg.v, id: s.id, t: now, c: { functional: s.functional, performance: s.performance, advertising: s.advertising } };
		write( 'shd_consent', encodeURIComponent( JSON.stringify( value ) ), cfg.days );
		if ( cfg.cf ) {
			writeShared( 'cookiefirst-consent', encodeURIComponent( JSON.stringify( { necessary: true, functional: s.functional, performance: s.performance, advertising: s.advertising, timestamp: now, type: 'category', version: 'shd-' + cfg.v } ) ), cfg.days );
		}
		api.state = s;
		if ( -1 === ( ' ' + html.className + ' ' ).indexOf( ' shd-consent-done ' ) ) {
			html.className += ' shd-consent-done';
		}
		close();
		log( value, method );
		if ( forget( previous, s ) ) {
			w.location.reload();
			return;
		}
		if ( cfg.gcm && 'function' === typeof w.gtag ) {
			w.gtag( 'consent', 'update', api.modes( s ) );
		}
		notify( s, 'cf_consent' );
		release( s );
	}

	function showDetails( on ) {
		details.hidden = ! on;
		saveButton.hidden = ! on;
		moreButton.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		if ( on ) {
			var s = api.state || {};
			api.categories.forEach( function ( c ) {
				var input = details.querySelector( 'input[name="' + c + '"]' );
				if ( input ) {
					input.checked = true === s[ c ];
				}
			} );
		}
	}

	function open( withDetails ) {
		returnFocus = d.activeElement;
		box.classList.add( 'is-open' );
		showDetails( withDetails );
		var first = withDetails ? details.querySelector( 'input:not([disabled])' ) : box.querySelector( 'button' );
		( first || box ).focus();
	}

	function close() {
		box.classList.remove( 'is-open' );
		showDetails( false );
		if ( returnFocus && returnFocus.focus && d.body.contains( returnFocus ) ) {
			returnFocus.focus();
		}
		returnFocus = null;
	}

	box.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-shd-action]' );
		if ( ! button ) {
			return;
		}
		var action = button.getAttribute( 'data-shd-action' );
		if ( 'accept' === action ) {
			var all = {};
			( cfg.cats || api.categories ).forEach( function ( name ) {
				all[ name ] = true;
			} );
			choose( all, 'all' );
		} else if ( 'reject' === action ) {
			choose( {}, 'necessary' );
		} else if ( 'details' === action ) {
			showDetails( details.hidden );
			if ( ! details.hidden ) {
				( details.querySelector( 'input:not([disabled])' ) || details ).focus();
			}
		} else if ( 'save' === action ) {
			var c = {};
			api.categories.forEach( function ( name ) {
				var input = details.querySelector( 'input[name="' + name + '"]' );
				c[ name ] = !! ( input && input.checked );
			} );
			choose( c, 'custom' );
		}
	} );

	box.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		if ( ! details.hidden ) {
			showDetails( false );
			moreButton.focus();
		} else if ( api.state ) {
			close();
		}
	} );

	// "Cookie settings" links anywhere on the page, and the round button.
	d.addEventListener( 'click', function ( e ) {
		var link = e.target.closest && e.target.closest( 'a[href$="#cookie-einstellungen"], a[href$="#cookie-settings"], .shd-consent-open, [data-shd-consent-open]' );
		if ( link ) {
			e.preventDefault();
			open( true );
		}
	} );
	if ( '#cookie-einstellungen' === w.location.hash || '#cookie-settings' === w.location.hash ) {
		open( true );
	} else if ( cfg.preview ) {
		open( false );
	}

	// A stored choice: start the waiting scripts once the page has loaded, as before.
	if ( api.state ) {
		var start = function () {
			notify( api.state, 'cf_consent_loaded' );
			release( api.state );
		};
		if ( 'complete' === d.readyState ) {
			start();
		} else {
			w.addEventListener( 'load', start );
		}
	}

	api.open = open;
	api.choose = choose;
}( window, document ) );
