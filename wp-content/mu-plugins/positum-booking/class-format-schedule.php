<?php
/**
 * Формат консультации (очно / онлайн) на уровне интервалов рабочего дня.
 *
 * Зачем отдельное хранилище. В графике JetAppointments интервал — это пара
 * «начало — конец», третьего поля под формат там нет, а расширить его редактор
 * нельзя, не правя плагин. Поэтому формат живёт своей мета-записью у карточки
 * специалиста и накладывается на слоты поверх плагина.
 *
 * Структура меты positum_format_schedule:
 *   [ 'monday' => [ ['from'=>'09:00','to'=>'13:00','format'=>'office'], ... ], ... ]
 *
 * Пустая карта означает «оба формата доступны всегда» — ровно то поведение,
 * которое было до внедрения. Так специалист без настроек ничего не теряет.
 *
 * О времени. Слоты JetAppointments приходят timestamp-ами, у которых настенное
 * время читается через gmdate(): для графика 15:55 gmdate() даёт 15:55,
 * а wp_date() — 18:55. Поэтому здесь везде gmdate(), это не ошибка.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Format_Schedule {

	const META_KEY = 'positum_format_schedule';

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

	public static function formats() {
		return array(
			self::OFFICE => 'Очно',
			self::ONLINE => 'Онлайн',
			self::BOTH   => 'Очно и онлайн',
		);
	}

	/**
	 * Подпись формата на языке текущей страницы.
	 * Сайт двуязычный (Polylang), эстонский — основной.
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
	 * Название CPT специалистов берём из настроек плагина, а не хардкодим.
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
	 * @return array Карта форматов специалиста, приведённая к порядку.
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
	 * Форматы, доступные специалисту на отрезке [$from, $to).
	 *
	 * Слот получает формат только если целиком помещается в интервал этого
	 * формата. Приём, который начался бы в очные часы, а закончился в онлайновые,
	 * не предлагается ни в одном из форматов — провести его нельзя.
	 *
	 * @return array Список из self::OFFICE и/или self::ONLINE. Пустой — слот недоступен.
	 */
	public static function formats_for_slot( $provider, $from, $to ) {

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

		// Слот через полночь не поддерживаем: приёмы столько не длятся,
		// а поддержка такого случая усложнила бы всё остальное.
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
	 * Форматы, встречающиеся у специалиста в этот день хоть где-нибудь.
	 * Нужно для пометок в календаре, чтобы не открывать каждую дату подряд.
	 *
	 * @param int $date Любой timestamp внутри нужного дня.
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
	 * Интервалы одного формата, слитые в непрерывные куски.
	 * Формат «оба» участвует и в очной, и в онлайновой выборке.
	 *
	 * Слияние нужно, чтобы приём на стыке двух соседних интервалов одного
	 * формата (09:00–13:00 и 13:00–17:00) не оказался «не помещающимся».
	 *
	 * @return array Список ['from'=>минуты,'to'=>минуты], упорядоченный.
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
	 * Приводит что угодно к валидной карте: мусор выбрасывается,
	 * интервалы внутри дня сортируются по времени начала.
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
