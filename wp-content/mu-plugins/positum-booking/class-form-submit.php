<?php
/**
 * Booking form submit without a page reload.
 *
 * How it was. The form is inserted with the [jet_engine component="forms"
 * _form_id="…"] shortcode, whose default submit type is reload. After a
 * successful submit the page reloaded to ?status=success, and the modal had
 * to be reopened by a script on the page.
 *
 * How it is now. The type is switched to ajax: the modal stays open and the
 * message is shown in place.
 *
 * ABOUT ANALYTICS. The Google Ads conversion used to be fired by a PHP check
 * of ?status=success in the theme. With ajax there is no such page render, so
 * booking-submit.js calls the same conversion through the function
 * positumTrackBookingSuccess() declared in the theme, and reports a hit to
 * Yandex Metrika as well. No GTM changes are required.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Form_Submit {

	public static function init() {
		add_filter( 'jet-engine/shortcodes/default-atts', array( __CLASS__, 'submit_by_ajax' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Default value for JetEngine shortcodes.
	 * A shortcode with an explicit submit type is not affected.
	 */
	public static function submit_by_ajax( $atts ) {

		if ( isset( $atts['submit_type'] ) ) {
			$atts['submit_type'] = 'ajax';
		}

		return $atts;
	}

	public static function enqueue() {

		$path = POSITUM_BOOKING_DIR . '/assets/booking-submit.js';

		wp_enqueue_script(
			'positum-booking-submit',
			WPMU_PLUGIN_URL . '/positum-booking/assets/booking-submit.js',
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0',
			true
		);
	}
}
