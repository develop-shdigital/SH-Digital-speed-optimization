/**
 * SHD Performance: delayed-script loader.
 *
 * Scripts rewritten to type="shd/delayed" run in document order on the first
 * user interaction (or after window.shdPerfDelay.timeout ms). Handlers they add
 * for DOMContentLoaded, jQuery ready and load are called, in that order, once they
 * have all run. When replay is on, taps on Elementor controls and form submits
 * made before the scripts are ready are held and repeated afterwards. The site's
 * own files are pre-downloaded a few seconds after the page has loaded, so a later
 * first interaction does not wait for the network.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.shdPerfDelay || {};
	var started = false;
	var waiting = false;
	var ready = false;
	var held = [];
	var triggers = [ 'keydown', 'mousedown', 'mousemove', 'pointerdown', 'touchstart', 'touchmove', 'wheel', 'scroll', 'focusin' ];

	function each( list, fn ) {
		for ( var i = 0; i < list.length; i++ ) {
			fn( list[ i ], i );
		}
	}

	// Browsers also send mousemove when content moves under a resting pointer
	// (during loading, for example); only a real movement counts as interaction.
	function trigger( event ) {
		if ( 'mousemove' === event.type && ! event.movementX && ! event.movementY ) {
			return;
		}
		start();
	}

	function listen( add ) {
		each( triggers, function ( type ) {
			( add ? w.addEventListener : w.removeEventListener ).call( w, type, trigger, { passive: true, capture: true } );
		} );
	}

	function queued() {
		return Array.prototype.slice.call( d.querySelectorAll( 'script[type="shd/delayed"]' ) );
	}

	function replayTarget( event ) {
		var el = event.target;
		// The video preview and the table of contents button have their own, immediate handlers.
		if ( ! el || ! el.closest || ! el.closest( '.elementor, [data-elementor-type]' ) || el.closest( '[data-shd-yt], [data-shd-toc-toggle]' ) ) {
			return null;
		}
		var control = el.closest( 'a, button, [role="button"], input[type="submit"], input[type="button"], summary, .elementor-custom-embed-image-overlay' );
		if ( ! control ) {
			return null;
		}
		if ( 'A' === control.tagName ) {
			var href = control.getAttribute( 'href' ) || '';
			if ( href && '#' !== href.charAt( 0 ) && 0 !== href.indexOf( 'javascript:' ) ) {
				return null;
			}
		}
		return control;
	}

	function onClick( event ) {
		if ( ready || ! cfg.replay ) {
			return;
		}
		var control = replayTarget( event );
		if ( ! control ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( held.indexOf( control ) === -1 ) {
			held.push( control );
		}
		start();
	}

	function onSubmit( event ) {
		if ( ready || ! cfg.replay ) {
			return;
		}
		var form = event.target;
		if ( ! form || ! form.closest || ! form.closest( '.elementor, [data-elementor-type]' ) ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( held.indexOf( form ) === -1 ) {
			held.push( form );
		}
		start();
	}

	function ownFile( src ) {
		var a = d.createElement( 'a' );
		a.href = src;
		return a.host === w.location.host;
	}

	// Fetches a delayed script's file ahead of running it. Only the site's own files:
	// third-party (tracking) files are left until the scripts that load them run.
	function fetchAhead( script, rel ) {
		var src = script.getAttribute( 'data-shd-src' );
		var type = script.getAttribute( 'data-shd-type' );
		if ( ! src || ! ownFile( src ) || ( type && ! /^(text|application)\/(java|ecma)script$/i.test( type ) ) ) {
			return;
		}
		var link = d.createElement( 'link' );
		link.rel = rel;
		link.href = src;
		if ( 'preload' === rel ) {
			link.as = 'script';
			link.setAttribute( 'fetchpriority', 'low' );
		}
		each( [ 'crossorigin', 'integrity', 'referrerpolicy' ], function ( name ) {
			if ( script.hasAttribute( name ) ) {
				link.setAttribute( name, script.getAttribute( name ) );
			}
		} );
		d.head.appendChild( link );
	}

	function prefetch() {
		if ( started ) {
			return;
		}
		each( queued(), function ( script ) {
			fetchAhead( script, 'prefetch' );
		} );
	}

	function makeEvent( name ) {
		try {
			return new Event( name, { bubbles: true } );
		} catch ( e ) {
			var event = d.createEvent( 'Event' );
			event.initEvent( name, true, true );
			return event;
		}
	}

	function call( handler, target, name ) {
		try {
			if ( 'function' === typeof handler ) {
				handler.call( target, makeEvent( name ) );
			} else if ( handler && 'function' === typeof handler.handleEvent ) {
				handler.handleEvent( makeEvent( name ) );
			}
		} catch ( e ) {
			if ( w.console ) {
				w.console.error( e );
			}
		}
	}

	function start() {
		if ( started ) {
			return;
		}
		// An interaction while the page is still downloading: the scripts further down
		// have not been read yet, so start once the whole document is there.
		if ( 'loading' === d.readyState ) {
			if ( ! waiting ) {
				waiting = true;
				d.addEventListener( 'DOMContentLoaded', start );
			}
			return;
		}
		started = true;
		listen( false );

		var domReady = [];
		var winLoad = [];
		var docAdd = d.addEventListener;
		var winAdd = w.addEventListener;
		var holding = true; // jQuery ready handlers wait.
		var loading = true; // Window load handlers wait (also those added by ready handlers).
		var jqueries = [];
		var jqReady = [];

		// Handlers registered for events that already fired are collected and called at the end.
		d.addEventListener = function ( type, handler, options ) {
			if ( 'DOMContentLoaded' === type || 'readystatechange' === type ) {
				domReady.push( [ d, handler, type ] );
				return;
			}
			return docAdd.call( d, type, handler, options );
		};
		w.addEventListener = function ( type, handler, options ) {
			if ( 'DOMContentLoaded' === type ) {
				domReady.push( [ w, handler, type ] );
				return;
			}
			if ( 'load' === type ) {
				winLoad.push( [ w, handler, type ] );
				return;
			}
			return winAdd.call( w, type, handler, options );
		};

		// The window load event has already happened: jQuery handlers added for it now
		// listen for shd-load, which is triggered once the scripts have run.
		function loadAlias( set, args ) {
			args = Array.prototype.slice.call( args );
			if ( ! loading || set[ 0 ] !== w ) {
				return args;
			}
			var rename = function ( types ) {
				return String( types ).replace( /(^|\s)load(?=\.|\s|$)/g, '$1shd-load' );
			};
			if ( 'string' === typeof args[ 0 ] ) {
				args[ 0 ] = rename( args[ 0 ] );
			} else if ( args[ 0 ] && 'object' === typeof args[ 0 ] ) {
				var map = {};
				for ( var types in args[ 0 ] ) {
					if ( Object.prototype.hasOwnProperty.call( args[ 0 ], types ) ) {
						map[ rename( types ) ] = args[ 0 ][ types ];
					}
				}
				args[ 0 ] = map;
			}
			return args;
		}

		// jQuery runs ready handlers at once when the page has already loaded. With the
		// page's own scripts delayed (cfg.all), that would start Elementor before the
		// scripts after it (Elementor Pro, add-ons) are there to hear about it, so ready
		// handlers wait until all delayed scripts have run, as on a normal page load.
		function patchJquery() {
			var $ = w.jQuery;
			if ( ! $ || ! $.fn || ! $.fn.ready || ! $.fn.on ) {
				return;
			}
			for ( var i = 0; i < jqueries.length; i++ ) {
				if ( jqueries[ i ].$ === $ ) {
					return;
				}
			}
			var orig = { $: $, ready: $.fn.ready, on: $.fn.on, one: $.fn.one };
			jqueries.push( orig );
			$.fn.ready = function ( fn ) {
				if ( ! holding ) {
					return orig.ready.apply( this, arguments );
				}
				jqReady.push( [ orig, fn ] );
				return this;
			};
			$.fn.on = function () {
				return orig.on.apply( this, loadAlias( this, arguments ) );
			};
			if ( orig.one ) {
				$.fn.one = function () {
					return orig.one.apply( this, loadAlias( this, arguments ) );
				};
			}
		}

		var scripts = queued();
		var index = 0;

		// Download the files in parallel now; they still run one after another below.
		each( scripts, function ( script ) {
			fetchAhead( script, 'preload' );
		} );

		function next() {
			if ( cfg.all ) {
				patchJquery();
			}
			if ( index >= scripts.length ) {
				finish();
				return;
			}
			var old = scripts[ index++ ];
			var script = d.createElement( 'script' );
			script.shdOwn = true;
			each( old.attributes, function ( attr ) {
				if ( 'type' !== attr.name && 'data-shd-src' !== attr.name && 'data-shd-type' !== attr.name && 'data-shd-async' !== attr.name ) {
					script.setAttribute( attr.name, attr.value );
				}
			} );
			var type = old.getAttribute( 'data-shd-type' );
			if ( type ) {
				script.type = type;
			}
			var src = old.getAttribute( 'data-shd-src' );
			if ( src && old.hasAttribute( 'data-shd-async' ) ) {
				// Was async: load it, but do not hold up the scripts after it.
				script.async = true;
				script.src = src;
				old.parentNode.replaceChild( script, old );
				next();
			} else if ( src ) {
				script.async = false;
				script.onload = script.onerror = next;
				script.src = src;
				old.parentNode.replaceChild( script, old );
			} else {
				script.text = old.text;
				old.parentNode.replaceChild( script, old );
				next();
			}
		}

		// Same order as a normal page load: DOMContentLoaded handlers, jQuery ready
		// handlers (each in its own task, as jQuery runs them), then load handlers.
		function finish() {
			d.addEventListener = docAdd;
			each( domReady, function ( entry ) {
				call( entry[ 1 ], entry[ 0 ], entry[ 2 ] );
			} );
			holding = false;
			var last = null;
			each( jqReady, function ( entry ) {
				last = entry[ 0 ];
				last.ready.call( last.$( d ), entry[ 1 ] );
			} );
			if ( last ) {
				last.ready.call( last.$( d ), loaded );
			} else {
				loaded();
			}
		}

		function loaded() {
			var handlers = winLoad.slice();
			w.addEventListener = winAdd;
			loading = false;
			each( handlers, function ( entry ) {
				call( entry[ 1 ], entry[ 0 ], entry[ 2 ] );
			} );
			each( jqueries, function ( jq ) {
				try {
					jq.$( w ).trigger( 'shd-load' );
				} catch ( e ) {
					if ( w.console ) {
						w.console.error( e );
					}
				}
			} );
			settled( release );
		}

		function release() {
			ready = true;
			d.removeEventListener( 'click', onClick, true );
			d.removeEventListener( 'submit', onSubmit, true );
			var items = held;
			held = [];
			each( items, function ( el ) {
				if ( 'FORM' === el.tagName ) {
					if ( el.requestSubmit ) {
						el.requestSubmit();
					} else {
						el.submit();
					}
				} else {
					el.click();
				}
			} );
			d.dispatchEvent( makeEvent( 'shd:scripts-ready' ) );
		}

		// Elementor loads some widget handlers (the off-canvas menu, for example) as extra
		// script chunks while starting; held taps are replayed once those have loaded.
		var pending = 0;
		var onIdle = null;
		var observer = w.MutationObserver ? new w.MutationObserver( function ( records ) {
			each( records, function ( record ) {
				each( record.addedNodes, function ( node ) {
					if ( 'SCRIPT' === node.tagName && node.src && node.async && ! node.shdOwn ) {
						pending++;
						var settle = function () {
							pending--;
							if ( onIdle ) {
								onIdle();
							}
						};
						node.addEventListener( 'load', settle );
						node.addEventListener( 'error', settle );
					}
				} );
			} );
		} ) : null;
		if ( observer ) {
			observer.observe( d.documentElement, { childList: true, subtree: true } );
		}

		function settled( callback ) {
			var finished = false;
			var quiet = 0;
			var limit = setTimeout( done, 3000 );
			function done() {
				if ( finished ) {
					return;
				}
				finished = true;
				onIdle = null;
				clearTimeout( limit );
				clearTimeout( quiet );
				if ( observer ) {
					observer.disconnect();
				}
				callback();
			}
			function check() {
				clearTimeout( quiet );
				if ( pending <= 0 ) {
					quiet = setTimeout( done, 150 );
				}
			}
			onIdle = check;
			check();
		}


		// Let the browser paint the response to the triggering interaction first.
		if ( d.hidden || ! w.requestAnimationFrame ) {
			setTimeout( next, 0 );
		} else {
			w.requestAnimationFrame( function () {
				setTimeout( next, 0 );
			} );
		}
	}

	listen( true );
	d.addEventListener( 'click', onClick, true );
	d.addEventListener( 'submit', onSubmit, true );

	w.addEventListener( 'load', function () {
		var idle = w.requestIdleCallback || function ( fn ) {
			return setTimeout( fn, 200 );
		};
		var connection = w.navigator.connection;
		// Pre-download well after loading, so it never competes with the page's own
		// images and fonts; skipped when the visitor asked to save data.
		if ( cfg.prefetch && ! ( connection && connection.saveData ) ) {
			setTimeout( function () {
				idle( prefetch );
			}, 5000 );
		}
		if ( cfg.timeout > 0 ) {
			setTimeout( start, cfg.timeout );
		}
	} );
}( window, document ) );
