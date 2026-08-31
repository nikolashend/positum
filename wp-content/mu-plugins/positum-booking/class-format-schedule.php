<?php
/**
 * Consultation format (in person / online) at the level of working intervals.
 *
 * Why a separate store. In the JetAppointments schedule an interval is a
 * "start — end" pair, there is no third field for the format, and its editor
 * cannot be extended without editing the plugin. So the format lives in its own
 * meta on the specialist's card and is applied on top of the plugin.
 *
 * Structure of the positum_format_schedule meta:
 *   [ 'monday' => [ ['from'=>'09:00','to'=>'13:00','format'=>'office'], ... ], ... ]
 *
 * An empty map means "both formats are always available" — exactly the
 * behaviour from before. A specialist without settings loses nothing.
 *
 * About time. JetAppointments slots arrive as timestamps whose wall clock is
 * read with gmdate(): for a 15:55 schedule gmdate() gives 15:55 while wp_date()
 * gives 18:55. That is why gmdate() is used here, it is not a mistake.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Format_Schedule {

	const META_KEY = 'positum_format_schedule';

	/** Format overrides for the date ranges of the JetAppointments schedule. */
	const DATES_META = 'positum_format_dates';

	const OFFICE = 'office';
	const ONLINE = 'online';
	const BOTH   = 'both';

	public static function weekdays() {
		return array(
			'monday'    => 'Понедельник',
			'tuesday'   => 'Вторник',
			'wednesday' => 'Среда',
			'thursday'  => 'Четверг',
			'friday'    => 'Пятница',
			'saturday'  => 'Суббота',
			'sunday'    => 'Воскресенье',
		);
	}

	/**
	 * Formats an interval can be set to.
	 *
	 * There is no in-person only option on purpose: online is always possible,
	 * so an interval is either online only or both. A stored "office" value is
	 * therefore no longer valid and normalize() turns it into "both".
	 */
	public static function formats() {
		return array(
			self::ONLINE => 'Только онлайн',
			self::BOTH   => 'Очно и онлайн',
		);
	}

	/**
	 * Format caption in the language of the current page.
	 * The site is bilingual (Polylang), Estonian is the primary language.
	 */
	public static function label( $format ) {

		$lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';

		$labels = array(
			'ru' => array( self::OFFICE => 'Очно', self::ONLINE => 'Онлайн' ),
			'et' => array( self::OFFICE => 'Kohapeal', self::ONLINE => 'Online' ),
		);

		$set = isset( $labels[ $lang ] ) ? $labels[ $lang ] : $labels['et'];

		return isset( $set[ $format ] ) ? $set[ $format ] : '';
	}

	/**
	 * Caption for the format in the booking details block.
	 */
	public static function summary_caption() {

		$lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';

		return 'ru' === $lang ? 'Формат:' : 'Formaat:';
	}

	/**
	 * Heading of the booking summary on the last step.
	 */
	public static function summary_title() {

		$lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';

		return 'ru' === $lang ? 'Ваша бронь' : 'Teie broneering';
	}

	/**
	 * The providers CPT name comes from the plugin settings, not hardcoded.
	 */
	public static function providers_cpt() {

		if ( class_exists( '\JET_APB\Plugin' ) ) {
			$cpt = \JET_APB\Plugin::instance()->settings->get( 'providers_cpt' );

			if ( ! empty( $cpt ) ) {
				return $cpt;
			}
		}

		return 'psychologists';
	}

	/**
	 * Date ranges from the "Working days" section of the JetAppointments
	 * schedule — the ones where the hours differ from the usual week.
	 *
	 * They are read from the plugin meta rather than duplicated: the dates are
	 * entered once, in the plugin's own editor, and only the format is ours.
	 *
	 * @return array List of ['key','name','start','end','from','to'].
	 */
	public static function date_ranges( $provider ) {

		$meta  = get_post_meta( absint( $provider ), 'jet_apb_post_meta', true );
		$rows  = isset( $meta['custom_schedule']['working_days'] ) ? $meta['custom_schedule']['working_days'] : array();
		$ranges = array();

		if ( ! is_array( $rows ) ) {
			return $ranges;
		}

		foreach ( $rows as $row ) {

			if ( empty( $row['startTimeStamp'] ) || empty( $row['endTimeStamp'] ) ) {
				continue;
			}

			// Timestamps are in milliseconds and, like the slots, carry wall clock time.
			$from = intval( $row['startTimeStamp'] ) / 1000;
			$to   = intval( $row['endTimeStamp'] ) / 1000;

			$ranges[] = array(
				'key'   => gmdate( 'Y-m-d', $from ) . '_' . gmdate( 'Y-m-d', $to ),
				'name'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'start' => isset( $row['start'] ) ? (string) $row['start'] : gmdate( 'd-m-Y', $from ),
				'end'   => isset( $row['end'] ) ? (string) $row['end'] : gmdate( 'd-m-Y', $to ),
				'from'  => gmdate( 'Y-m-d', $from ),
				'to'    => gmdate( 'Y-m-d', $to ),
			);
		}

		return $ranges;
	}

	/**
	 * @return array Range key => format, for ranges with an explicit format.
	 */
	public static function get_date_formats( $provider ) {

		$stored = get_post_meta( absint( $provider ), self::DATES_META, true );
		$clean  = array();

		if ( ! is_array( $stored ) ) {
			return $clean;
		}

		foreach ( $stored as $key => $format ) {
			if ( array_key_exists( $format, self::formats() ) ) {
				$clean[ (string) $key ] = $format;
			}
		}

		return $clean;
	}

	public static function save_date_formats( $provider, $raw ) {

		$provider = absint( $provider );
		$clean    = array();

		foreach ( (array) $raw as $key => $format ) {
			if ( array_key_exists( $format, self::formats() ) ) {
				$clean[ sanitize_text_field( (string) $key ) ] = $format;
			}
		}

		if ( ! $clean ) {
			delete_post_meta( $provider, self::DATES_META );
			return array();
		}

		update_post_meta( $provider, self::DATES_META, $clean );

		return $clean;
	}

	/**
	 * Format set for a date by an explicit range override, if there is one.
	 *
	 * @return string office|online|both|'' — empty means no override.
	 */
	private static function format_for_date( $provider, $timestamp ) {

		$overrides = self::get_date_formats( $provider );

		if ( ! $overrides ) {
			return '';
		}

		$day = gmdate( 'Y-m-d', $timestamp );

		foreach ( self::date_ranges( $provider ) as $range ) {
			if ( isset( $overrides[ $range['key'] ] ) && $day >= $range['from'] && $day <= $range['to'] ) {
				return $overrides[ $range['key'] ];
			}
		}

		return '';
	}

	/**
	 * @return array The specialist's format map, normalised.
	 */
	public static function get( $provider ) {
		return self::normalize( get_post_meta( absint( $provider ), self::META_KEY, true ) );
	}

	public static function save( $provider, $raw ) {

		$provider = absint( $provider );
		$clean    = self::normalize( $raw );

		if ( self::is_empty( $clean ) ) {
			delete_post_meta( $provider, self::META_KEY );
			return array();
		}

		update_post_meta( $provider, self::META_KEY, $clean );

		return $clean;
	}

	public static function is_empty( $map ) {

		foreach ( (array) $map as $intervals ) {
			if ( ! empty( $intervals ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Formats available to the specialist over the [$from, $to) range.
	 *
	 * A slot gets a format only if it fits entirely inside an interval of that
	 * format. An appointment that would start in the in-person hours and end in
	 * the online ones is offered in neither — it cannot be held.
	 *
	 * @return array List of self::OFFICE and/or self::ONLINE. Empty — unavailable.
	 */
	public static function formats_for_slot( $provider, $from, $to ) {

		// A date range wins over the usual week: it is the more specific answer,
		// and it is exactly what a holiday or a temporary schedule is for.
		$override = self::format_for_date( $provider, $from );

		if ( $override ) {
			return self::BOTH === $override
				? array( self::OFFICE, self::ONLINE )
				: array( $override );
		}

		$map = self::get( $provider );

		if ( self::is_empty( $map ) ) {
			return array( self::OFFICE, self::ONLINE );
		}

		$day = self::weekday_key( $from );

		if ( empty( $map[ $day ] ) ) {
			return array();
		}

		$from_min = self::minutes_of_day( $from );
		$to_min   = self::minutes_of_day( $to );

		// Slots crossing midnight are not supported: appointments never run that
		// long, and supporting it would complicate everything else.
		if ( $to_min <= $from_min ) {
			return array();
		}

		$result = array();

		foreach ( array( self::OFFICE, self::ONLINE ) as $format ) {
			foreach ( self::merged_intervals( $map[ $day ], $format ) as $interval ) {
				if ( $interval['from'] <= $from_min && $to_min <= $interval['to'] ) {
					$result[] = $format;
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Formats found anywhere in that day for the specialist.
	 * Used for calendar marks, so that not every date has to be opened.
	 *
	 * @param int $date Any timestamp inside the day in question.
	 */
	public static function formats_for_date( $provider, $date ) {

		$map = self::get( $provider );

		if ( self::is_empty( $map ) ) {
			return array( self::OFFICE, self::ONLINE );
		}

		$day    = self::weekday_key( $date );
		$result = array();

		foreach ( ( isset( $map[ $day ] ) ? $map[ $day ] : array() ) as $interval ) {

			if ( self::BOTH === $interval['format'] ) {
				return array( self::OFFICE, self::ONLINE );
			}

			if ( ! in_array( $interval['format'], $result, true ) ) {
				$result[] = $interval['format'];
			}
		}

		return $result;
	}

	/**
	 * Intervals of one format merged into continuous ranges.
	 * The "both" format takes part in the in-person and the online selection.
	 *
	 * Merging is needed so that an appointment on the seam of two adjacent
	 * intervals of the same format (09:00–13:00 and 13:00–17:00) still fits.
	 *
	 * @return array List of ['from'=>minutes,'to'=>minutes], ordered.
	 */
	private static function merged_intervals( $intervals, $format ) {

		$ranges = array();

		foreach ( $intervals as $interval ) {

			if ( $interval['format'] !== $format && self::BOTH !== $interval['format'] ) {
				continue;
			}

			$ranges[] = array(
				'from' => self::minutes_of_string( $interval['from'] ),
				'to'   => self::minutes_of_string( $interval['to'] ),
			);
		}

		if ( ! $ranges ) {
			return array();
		}

		usort( $ranges, function ( $a, $b ) {
			return $a['from'] <=> $b['from'];
		} );

		$merged  = array();
		$current = array_shift( $ranges );

		foreach ( $ranges as $range ) {
			if ( $range['from'] <= $current['to'] ) {
				$current['to'] = max( $current['to'], $range['to'] );
			} else {
				$merged[] = $current;
				$current  = $range;
			}
		}

		$merged[] = $current;

		return $merged;
	}

	/**
	 * Normalises anything into a valid map: junk is dropped and the intervals
	 * inside a day are sorted by start time.
	 */
	public static function normalize( $raw ) {

		$result = array();

		foreach ( array_keys( self::weekdays() ) as $day ) {

			$result[ $day ] = array();

			if ( empty( $raw[ $day ] ) || ! is_array( $raw[ $day ] ) ) {
				continue;
			}

			foreach ( $raw[ $day ] as $interval ) {

				if ( ! is_array( $interval ) ) {
					continue;
				}

				$from   = self::sanitize_time( isset( $interval['from'] ) ? $interval['from'] : '' );
				$to     = self::sanitize_time( isset( $interval['to'] ) ? $interval['to'] : '' );
				$format = isset( $interval['format'] ) ? $interval['format'] : '';

				if ( ! $from || ! $to ) {
					continue;
				}

				if ( self::minutes_of_string( $to ) <= self::minutes_of_string( $from ) ) {
					continue;
				}

				if ( ! array_key_exists( $format, self::formats() ) ) {
					$format = self::BOTH;
				}

				$result[ $day ][] = array(
					'from'   => $from,
					'to'     => $to,
					'format' => $format,
				);
			}

			usort( $result[ $day ], function ( $a, $b ) {
				return self::minutes_of_string( $a['from'] ) <=> self::minutes_of_string( $b['from'] );
			} );
		}

		return $result;
	}

	private static function sanitize_time( $value ) {

		$value = trim( (string) $value );

		if ( ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value ) ) {
			return '';
		}

		return $value;
	}

	private static function minutes_of_string( $time ) {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) );
		return $hours * 60 + $minutes;
	}

	private static function minutes_of_day( $timestamp ) {
		return intval( gmdate( 'H', $timestamp ) ) * 60 + intval( gmdate( 'i', $timestamp ) );
	}

	private static function weekday_key( $timestamp ) {
		return strtolower( gmdate( 'l', $timestamp ) );
	}
}
