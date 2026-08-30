<?php
/**
 * Two edits that used to be made directly in the JetAppointments sources and
 * were lost on every plugin update. The instructions for restoring them lived
 * in the header of cron_5346776343.php — and nobody followed them after the
 * update on 7 October 2025.
 *
 * Here the same is done with hooks: a plugin update does not touch this file.
 *
 * EDIT 1. Technical bookings must not appear in the lists.
 *   Was:  $filter['technical'] = 0;  in includes/db/appointments.php
 *         (after prepare_params, otherwise array_filter() dropped the zero)
 *   Now:  the same filter is injected into the REST request for the list.
 *
 * EDIT 2. A slot that does not fit until the end of the working interval
 *   must not be offered. Was: break instead of $end = $to; in time-slots.php.
 *   Now:  such slots are dropped in the jet-apb/calendar/slots filter.
 *
 * What technical bookings are. Besides the two real specialists there is a
 * service one, "Iga spetsialist" (ID 1275). So that booking them blocks the
 * time for both — and the other way round — cron_5346776343.php creates hidden
 * duplicate bookings marked technical = 1. They must not be visible in the UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Core_Fixes {

	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'hide_technical_appointments' ), 10, 3 );
		add_filter( 'jet-apb/calendar/slots', array( __CLASS__, 'drop_truncated_slots' ), 10, 3 );
	}

	/**
	 * EDIT 1.
	 *
	 * Adds a technical = 0 condition to the request filter. The endpoint uses the
	 * same parameters both for the query and for the total count, so pagination
	 * stays correct.
	 *
	 * The value is passed as an array with an operator: inside the plugin the
	 * filter goes through array_filter(), and a plain 0 would be dropped as empty.
	 */
	public static function hide_technical_appointments( $result, $server, $request ) {

		if ( ! is_object( $request ) || false === strpos( (string) $request->get_route(), 'appointments-list' ) ) {
			return $result;
		}

		$filter = $request->get_param( 'filter' );

		if ( is_string( $filter ) && '' !== $filter ) {
			$filter = json_decode( $filter, true );
		}

		if ( ! is_array( $filter ) ) {
			$filter = array();
		}

		$filter['technical'] = array(
			'operator' => '=',
			'value'    => 0,
		);

		// A string on purpose: the endpoint itself runs json_decode() on this
		// parameter, and an array here kills the request with a TypeError.
		$request->set_param( 'filter', wp_json_encode( $filter ) );

		return $result;
	}

	/**
	 * EDIT 2.
	 *
	 * The plugin slices the day into slots and, reaching the end of the working
	 * interval, trims the last slot to that end instead of dropping it.
	 * As a result the client is offered a shorter appointment than advertised —
	 * 20 minutes instead of 50, for example.
	 *
	 * A trimmed slot is recognised by its duration: it is shorter than the
	 * service duration. No need to look the schedule up separately.
	 */
	public static function drop_truncated_slots( $slots, $service, $provider ) {

		if ( empty( $slots ) || ! is_array( $slots ) ) {
			return $slots;
		}

		$duration = self::slot_duration( $service, $provider );

		if ( ! $duration ) {
			return $slots;
		}

		foreach ( $slots as $key => $slot ) {

			if ( ! isset( $slot['from'], $slot['to'] ) ) {
				continue;
			}

			if ( ( absint( $slot['to'] ) - absint( $slot['from'] ) ) < $duration ) {
				unset( $slots[ $key ] );
			}
		}

		return $slots;
	}

	/**
	 * Duration of one appointment for a service/provider pair, in seconds.
	 * Taken from the schedule: the provider's own, else the service's, else global.
	 */
	private static function slot_duration( $service, $provider ) {

		if ( ! class_exists( '\JET_APB\Plugin' ) ) {
			return 0;
		}

		$calendar = \JET_APB\Plugin::instance()->calendar;

		if ( ! $calendar || ! method_exists( $calendar, 'get_schedule_settings' ) ) {
			return 0;
		}

		return absint( $calendar->get_schedule_settings( $provider, $service, 0, 'default_slot' ) );
	}
}
