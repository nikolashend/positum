/**
 * Bridge between the ajax booking submit and analytics.
 *
 * The page used to reload to ?status=success after a submit, and the theme
 * fired the Google Ads conversion on that page view. With ajax there is no
 * reload, so the conversion is fired here instead, through the function
 * positumTrackBookingSuccess() declared in the theme next to the counter
 * identifiers. No GTM changes are required.
 *
 * The address bar is left clean on purpose: with ?status=success in it a plain
 * reload would fire the conversion again, and the visitor would land on a page
 * with the modal reopened over an already sent form.
 *
 * A booking_success event is also pushed into dataLayer — a spare handle in
 * case the tracking is ever moved into the GTM container.
 */
( function () {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	window.jQuery( document ).on( 'jet-engine/form/ajax/on-success', function ( event, response, $form ) {

		var formId = $form && $form.data ? $form.data( 'form-id' ) : '';

		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( {
			event: 'booking_success',
			formId: formId,
			bookingStatus: 'success',
		} );

		// The address bar is deliberately left untouched. Putting ?status=success
		// there would make a reload count the conversion a second time, because
		// the theme still fires it on a page render with that parameter.
		var successUrl = new URL( window.location.href );
		successUrl.searchParams.set( 'status', 'success' );

		// The same conversion that used to fire when ?status=success was loaded.
		// The function is declared in the theme, next to the counter identifiers.
		// Metrika counts goals by URL, so it is handed the success address
		// explicitly — without the visitor ever navigating to it.
		if ( 'function' === typeof window.positumTrackBookingSuccess ) {
			window.positumTrackBookingSuccess( successUrl.toString() );
		}

		hideForm( $form );
	} );

	/**
	 * After a successful submit the form is not needed: clear what was typed
	 * and hide it, leaving only the message. Otherwise the filled fields keep
	 * hanging next to the "thank you" and it is unclear whether anything went.
	 */
	function hideForm( $form ) {

		if ( ! $form || ! $form.length ) {
			return;
		}

		var form = $form[ 0 ];

		if ( form.reset ) {
			form.reset();
		}

		// reset() does not touch hidden fields that the script filled in.
		$form.find( 'input[type="hidden"]' ).each( function () {
			if ( 0 !== this.name.indexOf( '_jet_engine' ) && 'page_id' !== this.name ) {
				this.value = '';
			}
		} );

		$form.hide();
	}

	/**
	 * If the modal is opened again — return the form to the first step.
	 */
	window.jQuery( document ).on( 'click', '.exad-modal-image-action, .open-modal', function () {

		var $form = window.jQuery( '.jet-form:hidden' );

		if ( ! $form.length ) {
			return;
		}

		$form.show();
		$form.find( '.jet-form-messages-wrap' ).hide();

		var $pages = $form.find( '.jet-form-page' );

		$pages.addClass( 'jet-form-page--hidden' );
		$pages.first().removeClass( 'jet-form-page--hidden' );

		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			url.searchParams.delete( 'status' );
			window.history.replaceState( {}, '', url.toString() );
		}
	} );
}() );
