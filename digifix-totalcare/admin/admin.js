/**
 * Digifix TotalCare admin pages.
 *
 * data-digifix-tc-confirm on a form or button: ask before submitting.
 * data-digifix-tc-target on a submit button:   form target for that button.
 * data-digifix-tc-print on a button:           print the page.
 */
( function () {
	'use strict';

	function closest( el, selector ) {
		while ( el && el.nodeType === 1 ) {
			if ( el.matches( selector ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( closest( e.target, '[data-digifix-tc-print]' ) ) {
			e.preventDefault();
			window.print();
			return;
		}
		var button = closest( e.target, 'button[data-digifix-tc-target], button[data-digifix-tc-confirm]' );
		if ( ! button || ! button.form ) {
			return;
		}
		if ( button.hasAttribute( 'data-digifix-tc-target' ) ) {
			button.form.target = button.getAttribute( 'data-digifix-tc-target' );
		}
		var message = button.getAttribute( 'data-digifix-tc-confirm' );
		if ( message && ! window.confirm( message ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var message = e.target.getAttribute && e.target.getAttribute( 'data-digifix-tc-confirm' );
		if ( message && ! window.confirm( message ) ) {
			e.preventDefault();
		}
	} );
}() );
