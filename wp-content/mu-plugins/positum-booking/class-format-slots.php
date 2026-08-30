<?php
/**
 * Bringing the consultation format into the calendar: availability and markup.
 *
 * Three things:
 *   1. A slot left with no format at all is not offered.
 *      The plugin marks such a day unavailable once no slots remain.
 *   2. Every slot in the response gets the format — a data-formats attribute
 *      and a modifier class. Captions are drawn by the front-end script:
 *      slots arrive over REST, where Polylang no longer knows the page
 *      language, and the server would put Estonian captions on a Russian page.
 *   3. The date list response gets a map of formats by weekday, so the
 *      calendar can mark dates without opening each one.
 *
 * Why the markup is patched in the REST response rather than at generation.
 * The plugin builds the slot HTML itself, and the time string format is taken
 * once for the whole list — a single slot cannot be affected through filters.
 * The slot markup is simple and predictable: <div class="jet-apb-slot ..." data-slot="…">.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Format_Slots {

	public static function init() {
		// Priority 20 — after Positum_Core_Fixes, which drops trimmed slots.
		add_filter( 'jet-apb/calendar/slots', array( __CLASS__, 'drop_slots_without_format' ), 20, 3 );
		// rest_request_after_callbacks rather than rest_post_dispatch:
		// the latter only fires on real HTTP requests, and internal
		// rest_do_request() calls would be left without the format.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'decorate_response' ), 10, 3 );
	}

	/**
	 * Drops slots that can be held neither in person nor online.
	 */
	public static function drop_slots_without_format( $slots, $service, $provider ) {

		if ( empty( $slots ) || ! is_array( $slots ) || ! $provider ) {
			return $slots;
		}

		if ( Positum_Format_Schedule::is_empty( Positum_Format_Schedule::get( $provider ) ) ) {
			return $slots;
		}

		foreach ( $slots as $key => $slot ) {

			if ( ! isset( $slot['from'], $slot['to'] ) ) {
				continue;
			}

			if ( ! Positum_Format_Schedule::formats_for_slot( $provider, $slot['from'], $slot['to'] ) ) {
				unset( $slots[ $key ] );
			}
		}

		return $slots;
	}

	public static function decorate_response( $result, $handler, $request ) {

		if ( ! is_object( $request ) || ! ( $result instanceof WP_REST_Response ) ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( false !== strpos( $route, 'appointment-date-slots' ) ) {
			return self::decorate_slots( $result, $request );
		}

		if ( false !== strpos( $route, 'appointment-refresh-date' ) ) {
			return self::decorate_dates( $result, $request );
		}

		return $result;
	}

	/**
	 * Adds the format to the slots. The front end gets HTML, the admin an array;
	 * both response shapes are supported.
	 */
	private static function decorate_slots( $result, $request ) {

		$provider = absint( $request->get_param( 'provider' ) );

		if ( ! $provider ) {
			return $result;
		}

		$data = $result->get_data();

		if ( empty( $data['data']['slots'] ) ) {
			return $result;
		}

		$slots = $data['data']['slots'];

		if ( is_array( $slots ) ) {

			foreach ( $slots as $key => $slot ) {
				if ( isset( $slot['from'], $slot['to'] ) ) {
					$slots[ $key ]['formats'] = Positum_Format_Schedule::formats_for_slot(
						$provider, $slot['from'], $slot['to']
					);
				}
			}

			$data['data']['slots'] = $slots;
			$result->set_data( $data );

			return $result;
		}

		if ( is_string( $slots ) ) {
			$data['data']['slots'] = self::decorate_slots_html( $slots, $provider );
			$result->set_data( $data );
		}

		return $result;
	}

	/**
	 * Adds data-formats and a modifier class to the slot markup.
	 * The caption text is deliberately not set here — see the note on language.
	 */
	private static function decorate_slots_html( $html, $provider ) {

		return preg_replace_callback(
			'~<div\s+class="jet-apb-slot(?P<mods>[^"]*)"(?P<attrs>[^>]*)>(?P<inner>.*?)</div>~s',
			function ( $match ) use ( $provider ) {

				if ( ! preg_match( '~data-slot="(\d+)"~', $match['attrs'], $from )
					|| ! preg_match( '~data-slot-end="(\d+)"~', $match['attrs'], $to ) ) {
					return $match[0];
				}

				$formats = Positum_Format_Schedule::formats_for_slot(
					$provider, absint( $from[1] ), absint( $to[1] )
				);

				if ( ! $formats ) {
					// Should never get here — such slots were dropped earlier.
					// But if it did, they must not be shown to the client.
					return '';
				}

				$mods = $match['mods'];

				foreach ( $formats as $format ) {
					$mods .= ' jet-apb-slot--' . $format;
				}

				$mods .= 1 === count( $formats )
					? ' jet-apb-slot--single-format'
					: ' jet-apb-slot--both-formats';

				return sprintf(
					'<div class="jet-apb-slot%1$s"%2$s data-formats="%3$s">%4$s</div>',
					$mods,
					$match['attrs'],
					esc_attr( implode( ',', $formats ) ),
					$match['inner']
				);
			},
			$html
		);
	}

	/**
	 * Adds a map of formats by weekday to the date list response.
	 * The calendar uses it to mark dates without requesting slots for each.
	 */
	private static function decorate_dates( $result, $request ) {

		$provider = absint( $request->get_param( 'provider' ) );

		if ( ! $provider ) {
			return $result;
		}

		$map  = Positum_Format_Schedule::get( $provider );
		$data = $result->get_data();

		if ( Positum_Format_Schedule::is_empty( $map ) ) {
			$data['data']['positumFormats'] = new stdClass();
			$result->set_data( $data );

			return $result;
		}

		$by_weekday = array();

		foreach ( $map as $day => $intervals ) {

			$formats = array();

			foreach ( $intervals as $interval ) {

				if ( Positum_Format_Schedule::BOTH === $interval['format'] ) {
					$formats = array( Positum_Format_Schedule::OFFICE, Positum_Format_Schedule::ONLINE );
					break;
				}

				if ( ! in_array( $interval['format'], $formats, true ) ) {
					$formats[] = $interval['format'];
				}
			}

			$by_weekday[ $day ] = $formats;
		}

		$data['data']['positumFormats'] = $by_weekday;
		$result->set_data( $data );

		return $result;
	}
}
