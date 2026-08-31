<?php
/**
 * Three consultation types instead of six services.
 *
 * The site has six services: every type exists twice, in person and online.
 * Per the spec the client picks a type, and the format later in the calendar.
 *
 * The services are NOT merged. The list shows only the in-person versions —
 * they play the role of the type. When the client picks a format on a slot,
 * the paired service is substituted on submit. No migration is needed: past
 * bookings, prices and reporting stay as they are.
 *
 * Durations inside a pair match (50/30/80 minutes, 10 minute buffer), so the
 * substitution changes neither the slot boundaries nor availability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Form_Structure {

	/** Name of the hidden form field that carries the chosen format. */
	const FORMAT_FIELD = 'cons_place';

	/** Booking forms: Russian and Estonian. */
	public static function form_ids() {
		return array( 1055, 2026 );
	}

	/**
	 * Service pairs: in-person version => online one.
	 * The in-person one is primary — it is the one shown as the consultation type.
	 */
	public static function pairs() {
		return array(
			1060 => 1063, // Individual, 50 min, 65 EUR
			1048 => 1061, // Couple / family, 80 min, 95 EUR
		);
	}

	/*
	 * The 30 minute "with a child" type (1049 / 1062) is deliberately absent:
	 * the centre stopped offering it. The posts are left in place so that past
	 * bookings still resolve their titles — returning the type is a matter of
	 * adding the pair back here.
	 */

	/**
	 * Consultation place — what the client sees in the email as the line
	 * "Место консультации: …". It used to be filled by the conditional logic of
	 * the form based on the chosen category; after the first step was removed
	 * there is nobody left to run it, so we substitute it ourselves. The texts
	 * are kept verbatim so the emails read exactly as before.
	 */
	public static function places() {
		return array(
			1055 => array( // русская форма
				Positum_Format_Schedule::OFFICE => 'F.R.Kreutzwaldi 24',
				Positum_Format_Schedule::ONLINE => 'онлайн-платформа',
			),
			2026 => array( // эстонская форма
				Positum_Format_Schedule::OFFICE => 'F.R.Kreutzwaldi 24',
				Positum_Format_Schedule::ONLINE => 'veebiplatvorm',
			),
		);
	}

	/**
	 * The services CPT name comes from the plugin settings, not hardcoded.
	 */
	public static function services_cpt() {

		if ( class_exists( '\JET_APB\Plugin' ) ) {
			$cpt = \JET_APB\Plugin::instance()->settings->get( 'services_cpt' );

			if ( ! empty( $cpt ) ) {
				return $cpt;
			}
		}

		return 'services';
	}

	public static function init() {
		add_filter( 'jet-engine/forms/field-options', array( __CLASS__, 'only_three_types' ), 10, 2 );
		add_filter( 'jet-engine/forms/handler/form-data', array( __CLASS__, 'apply_chosen_format' ), 10, 3 );
		add_filter( 'jet-engine/forms/booking/form-cache', array( __CLASS__, 'disable_cache' ), 10, 2 );
	}

	/**
	 * The rendered form cache is stored in a meta and bypasses every render
	 * filter — the service list in it is the one from the very first render.
	 * For the two booking forms the cache is disabled: the forms are small and
	 * the cost of a mistake is high — the client would see a stale service list.
	 */
	public static function disable_cache( $cache, $form_id ) {

		if ( in_array( absint( $form_id ), self::form_ids(), true ) ) {
			return '';
		}

		return $cache;
	}

	/**
	 * Leaves only the in-person versions in the service list — three types.
	 */
	public static function only_three_types( $options, $args ) {

		$name = isset( $args['name'] ) ? $args['name'] : '';

		if ( 'service_id' !== $name || empty( $options ) || ! is_array( $options ) ) {
			return $options;
		}

		$keep = array_map( 'strval', array_keys( self::pairs() ) );
		$kept = array();

		foreach ( $options as $option ) {

			$value = is_array( $option ) && isset( $option['value'] ) ? (string) $option['value'] : '';

			if ( in_array( $value, $keep, true ) ) {
				$kept[] = $option;
			}
		}

		// If none of the expected services was found, the service set has
		// changed. Better to show everything than an empty step.
		return $kept ? $kept : $options;
	}

	/**
	 * Resolves the chosen format: substitutes the paired service for online and
	 * turns the internal code into the consultation place for the email.
	 *
	 * The script puts a code (office/online) into the hidden field — convenient
	 * for decisions. What goes out, though, must be a human readable place, so
	 * here the code is replaced with an address or a platform name.
	 */
	public static function apply_chosen_format( $data, $form, $fields ) {

		if ( ! is_array( $data ) || empty( $data['service_id'] ) ) {
			return $data;
		}

		$format = isset( $data[ self::FORMAT_FIELD ] ) ? trim( (string) $data[ self::FORMAT_FIELD ] ) : '';

		if ( ! in_array( $format, array( Positum_Format_Schedule::OFFICE, Positum_Format_Schedule::ONLINE ), true ) ) {
			// No format arrived — the slot was available in a single format and
			// that is already baked into the service itself. Leave it alone.
			return $data;
		}

		$pairs   = self::pairs();
		$service = absint( $data['service_id'] );

		if ( Positum_Format_Schedule::ONLINE === $format && isset( $pairs[ $service ] ) ) {
			$data['service_id'] = (string) $pairs[ $service ];
			$data                = self::swap_service_in_slots( $data, $service, $pairs[ $service ] );
		}

		$data[ self::FORMAT_FIELD ] = self::place_text( $format, self::form_id_of( $data, $form ) );

		return $data;
	}

	/**
	 * Replaces the service inside the picked slots.
	 *
	 * A booking is created from the payload of the date field rather than from
	 * service_id: it holds an array of picked slots, each with its own service
	 * and provider. Fixing service_id alone still books the in-person service.
	 */
	private static function swap_service_in_slots( $data, $from, $to ) {

		if ( empty( $data['appointment_date'] ) || ! is_string( $data['appointment_date'] ) ) {
			return $data;
		}

		$raw     = $data['appointment_date'];
		$slots   = json_decode( $raw, true );
		$slashed = false;

		// The value may arrive escaped — handle both shapes.
		if ( ! is_array( $slots ) ) {
			$slots   = json_decode( stripslashes( $raw ), true );
			$slashed = true;
		}

		if ( ! is_array( $slots ) ) {
			return $data;
		}

		foreach ( $slots as $index => $slot ) {
			if ( isset( $slot['service'] ) && absint( $slot['service'] ) === $from ) {
				$slots[ $index ]['service'] = $to;
			}
		}

		$encoded = wp_json_encode( $slots );

		$data['appointment_date'] = $slashed ? addslashes( $encoded ) : $encoded;

		return $data;
	}

	private static function place_text( $format, $form_id ) {

		$places = self::places();
		$set    = isset( $places[ $form_id ] ) ? $places[ $form_id ] : reset( $places );

		return isset( $set[ $format ] ) ? $set[ $format ] : '';
	}

	/**
	 * The email language is decided by the form, not by the current page
	 * language: the submit goes through a shared handler where Polylang is out.
	 */
	private static function form_id_of( $data, $form ) {

		if ( ! empty( $data['_jet_engine_booking_form_id'] ) ) {
			return absint( $data['_jet_engine_booking_form_id'] );
		}

		if ( is_object( $form ) && ! empty( $form->ID ) ) {
			return absint( $form->ID );
		}

		return absint( $form );
	}

	/**
	 * @return string office|online|'' — service format by its place in a pair.
	 */
	public static function format_of_service( $service ) {

		$service = absint( $service );
		$pairs   = self::pairs();

		if ( isset( $pairs[ $service ] ) ) {
			return Positum_Format_Schedule::OFFICE;
		}

		if ( in_array( $service, $pairs, true ) ) {
			return Positum_Format_Schedule::ONLINE;
		}

		return '';
	}
}
