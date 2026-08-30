<?php
/**
 * Проброс формата консультации в календарь: доступность слотов и разметка.
 *
 * Три вещи:
 *   1. Слот, для которого не остаётся ни одного формата, вообще не предлагается.
 *      Плагин сам пометит такой день как недоступный, если слотов не осталось.
 *   2. К каждому слоту в ответе добавляется формат — атрибутом data-formats
 *      и, если формат единственный, видимой пометкой рядом со временем.
 *   3. К ответу со списком дат добавляется карта форматов по дням недели,
 *      чтобы календарь мог помечать даты, не открывая каждую.
 *
 * Почему разметка правится в ответе REST, а не при генерации. Плагин собирает
 * HTML слотов сам, и формат строки времени берётся один раз на весь список —
 * повлиять на отдельный слот через фильтры нельзя. Разметка слота при этом
 * простая и предсказуемая: <div class="jet-apb-slot ..." data-slot="…">.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Format_Slots {

	public static function init() {
		// Приоритет 20 — после Positum_Core_Fixes, который отбрасывает обрезанные слоты.
		add_filter( 'jet-apb/calendar/slots', array( __CLASS__, 'drop_slots_without_format' ), 20, 3 );
		// Именно rest_request_after_callbacks, а не rest_post_dispatch:
		// второй срабатывает только на настоящих HTTP-запросах, и внутренние
		// вызовы rest_do_request() остались бы без формата.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'decorate_response' ), 10, 3 );
	}

	/**
	 * Убирает слоты, которые нельзя провести ни очно, ни онлайн.
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
	 * Добавляет формат к слотам. Витрина получает HTML, админка — массив;
	 * поддерживаем оба вида ответа.
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
	 * Дописывает в разметку слота data-formats, класс-модификатор и,
	 * если формат единственный, пометку рядом со временем.
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
					// Досюда доходить не должно — такие слоты отброшены раньше.
					// Но если дошло, показывать их клиенту нельзя.
					return '';
				}

				$mods = $match['mods'];
				$badge = '';

				foreach ( $formats as $format ) {
					$mods .= ' jet-apb-slot--' . $format;
				}

				if ( 1 === count( $formats ) ) {
					$mods .= ' jet-apb-slot--single-format';
					$badge = sprintf(
						' <span class="positum-slot-format positum-slot-format--%1$s">%2$s</span>',
						esc_attr( $formats[0] ),
						esc_html( Positum_Format_Schedule::label( $formats[0] ) )
					);
				} else {
					$mods .= ' jet-apb-slot--both-formats';
				}

				return sprintf(
					'<div class="jet-apb-slot%1$s"%2$s data-formats="%3$s">%4$s%5$s</div>',
					$mods,
					$match['attrs'],
					esc_attr( implode( ',', $formats ) ),
					$match['inner'],
					$badge
				);
			},
			$html
		);
	}

	/**
	 * Добавляет к ответу со списком дат карту форматов по дням недели.
	 * Календарь берёт её, чтобы помечать даты, не запрашивая слоты каждой.
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
