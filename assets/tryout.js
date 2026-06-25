/* Lightweight client-side validation for the tryout form.
 * The form is novalidate (so we control messaging); server-side validation in
 * inc/handler.php is authoritative. This just catches empties before a round trip. */
( function () {
	'use strict';

	var form = document.querySelector( '.ch-tryout-form' );
	if ( ! form ) {
		return;
	}

	form.addEventListener( 'submit', function ( e ) {
		var firstInvalid = null;

		form.querySelectorAll( '[required]' ).forEach( function ( el ) {
			var field = el.closest( '.ch-tryout-field' );
			var empty = ! el.value || ! el.value.trim();
			if ( field ) {
				field.classList.toggle( 'ch-tryout-field--invalid', empty );
			}
			if ( empty && ! firstInvalid ) {
				firstInvalid = el;
			}
		} );

		if ( firstInvalid ) {
			e.preventDefault();
			firstInvalid.focus();
		}
	} );

	// Clear the invalid state as the user fixes each field.
	form.addEventListener( 'input', function ( e ) {
		var field = e.target.closest( '.ch-tryout-field' );
		if ( field && e.target.value && e.target.value.trim() ) {
			field.classList.remove( 'ch-tryout-field--invalid' );
		}
	} );
} )();
