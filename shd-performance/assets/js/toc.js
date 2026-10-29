/**
 * SHD Performance: table of contents button on phones.
 *
 * With "Table of contents on phones: collapsed", the filled-in list starts
 * collapsed on phones and the arrow buttons in its header open and close it.
 * This works at once, before and independently of Elementor Pro's script,
 * which leaves the box alone (it is not set to "Minimize Box").
 */
( function ( d ) {
	'use strict';

	function toggle( button ) {
		var widget = button.closest( '[data-shd-toc-min]' );
		if ( ! widget ) {
			return;
		}
		var collapsed = widget.classList.toggle( 'elementor-toc--collapsed' );
		var buttons = widget.querySelectorAll( '[data-shd-toc-toggle]' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
		}
		// The pressed button is hidden now; keep the keyboard focus on the visible one.
		var other = widget.querySelector( collapsed ? '.elementor-toc__toggle-button--expand' : '.elementor-toc__toggle-button--collapse' );
		if ( other && d.activeElement === button ) {
			other.focus();
		}
	}

	function target( event ) {
		return event.target && event.target.closest ? event.target.closest( '[data-shd-toc-toggle]' ) : null;
	}

	d.addEventListener( 'click', function ( event ) {
		var button = target( event );
		if ( button ) {
			toggle( button );
		}
	} );

	d.addEventListener( 'keydown', function ( event ) {
		var button = target( event );
		if ( button && ( 'Enter' === event.key || ' ' === event.key ) ) {
			event.preventDefault();
			toggle( button );
		}
	} );
}( document ) );
