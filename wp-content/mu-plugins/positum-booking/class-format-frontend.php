<?php
/**
 * Enqueues the format styles and script on the front end.
 *
 * The files are tiny, and the booking form is opened from a modal on several
 * pages — working out whether it is present costs more than serving a couple
 * of kilobytes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Format_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {

		$dir = POSITUM_BOOKING_DIR . '/assets';
		$url = WPMU_PLUGIN_URL . '/positum-booking/assets';

		wp_enqueue_style(
			'positum-booking-format',
			$url . '/booking-format.css',
			array(),
			self::version( $dir . '/booking-format.css' )
		);

		wp_enqueue_script(
			'positum-booking-format',
			$url . '/booking-format.js',
			array(),
			self::version( $dir . '/booking-format.js' ),
			true
		);

		wp_localize_script(
			'positum-booking-format',
			'positumBookingFormat',
			array(
				'field'  => Positum_Form_Structure::FORMAT_FIELD,
				'labels' => array(
					Positum_Format_Schedule::OFFICE => Positum_Format_Schedule::label( Positum_Format_Schedule::OFFICE ),
					Positum_Format_Schedule::ONLINE => Positum_Format_Schedule::label( Positum_Format_Schedule::ONLINE ),
				),
				// Default format for a slot available in both variants.
				'defaultFormat'  => Positum_Format_Schedule::ONLINE,
				'summaryCaption' => Positum_Format_Schedule::summary_caption(),
				'summaryTitle'   => Positum_Format_Schedule::summary_title(),
				'names'          => self::localized_names(),
				'pairs'          => Positum_Form_Structure::pairs(),
			)
		);
	}

	/**
	 * Russian titles of services and specialists.
	 *
	 * The calendar substitutes them from its own JS config, which receives the
	 * raw post_title — that is, Estonian. These posts have no Polylang
	 * translation, the Russian title lives in the "rus" meta field. There is no
	 * filter to correct the plugin config, so the map is handed to the front end
	 * and the caption is replaced where the format is drawn.
	 *
	 * @return array services and providers: ID => Russian title.
	 */
	private static function localized_names() {

		$empty = array( 'services' => array(), 'providers' => array() );

		if ( ! function_exists( 'pll_current_language' ) || 'ru' !== pll_current_language() ) {
			return $empty;
		}

		$services = 'services';

		if ( class_exists( '\JET_APB\Plugin' ) ) {
			$from_settings = \JET_APB\Plugin::instance()->settings->get( 'services_cpt' );
			$services      = $from_settings ? $from_settings : $services;
		}

		return array(
			'services'  => self::names_of( $services ),
			'providers' => self::names_of( Positum_Format_Schedule::providers_cpt() ),
		);
	}

	private static function names_of( $post_type ) {

		$names = array();

		if ( ! $post_type ) {
			return $names;
		}

		$ids = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $ids as $id ) {

			$name = get_post_meta( $id, 'rus', true );

			if ( $name ) {
				$names[ $id ] = $name;
			}
		}

		return $names;
	}

	/**
	 * Version from the file modification time: after a deploy browsers pick up
	 * the new file without editing a number by hand.
	 */
	private static function version( $path ) {
		return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
	}
}
