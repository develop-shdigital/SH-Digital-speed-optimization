/**
 * SHD Consent, first thing in <head>: reads the stored choice before any other
 * script runs, sets the Google Consent Mode defaults (and the stored choice), and
 * marks <html> so the banner is painted with the first frame, or not at all.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.shdConsentConfig || {};

	function cookie( name ) {
		var parts = d.cookie ? d.cookie.split( '; ' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			if ( 0 === parts[ i ].indexOf( name + '=' ) ) {
				return parts[ i ].slice( name.length + 1 );
			}
		}
		return '';
	}

	function parse( raw ) {
		try {
			return raw ? JSON.parse( decodeURIComponent( raw ) ) : null;
		} catch ( e ) {
			return null;
		}
	}

	// The stored choice, or null. A choice made in CookieFirst counts until the banner version changes.
	function read() {
		var own = parse( cookie( 'shd_consent' ) );
		var cf;
		if ( own && own.v === cfg.v && own.c ) {
			return { functional: true === own.c.functional, performance: true === own.c.performance, advertising: true === own.c.advertising, id: own.id, t: own.t };
		}
		if ( cfg.cf && cfg.importCf ) {
			cf = parse( cookie( 'cookiefirst-consent' ) );
			if ( cf && 'object' === typeof cf ) {
				return { functional: true === cf.functional, performance: true === cf.performance || true === cf.analytics, advertising: true === cf.advertising || true === cf.marketing, imported: true };
			}
		}
		return null;
	}

	function modes( s ) {
		var yes = function ( on ) {
			return on ? 'granted' : 'denied';
		};
		return {
			ad_storage: yes( s.advertising ),
			ad_user_data: yes( s.advertising ),
			ad_personalization: yes( s.advertising ),
			analytics_storage: yes( s.performance ),
			functionality_storage: yes( s.functional ),
			personalization_storage: yes( s.functional ),
			security_storage: 'granted'
		};
	}

	var state = read();
	d.documentElement.className += ' shd-js' + ( state ? ' shd-consent-done' : '' );

	if ( cfg.gcm ) {
		w.dataLayer = w.dataLayer || [];
		w.gtag = w.gtag || function () {
			w.dataLayer.push( arguments );
		};
		var defaults = modes( {} );
		defaults.wait_for_update = 500;
		w.gtag( 'consent', 'default', defaults );
		if ( state ) {
			w.gtag( 'consent', 'update', modes( state ) );
		}
	}

	w.shdConsent = { state: state, read: read, modes: modes, cookie: cookie, categories: [ 'functional', 'performance', 'advertising' ] };
}( window, document ) );
