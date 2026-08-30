<?php
/**
 * Две правки, которые раньше вносились прямо в исходники JetAppointments
 * и терялись при каждом обновлении плагина. Инструкция по их восстановлению
 * лежала в шапке cron_5346776343.php — и её никто не выполнил после
 * обновления 7 октября 2025.
 *
 * Здесь то же самое сделано через хуки: обновление плагина этот файл не трогает.
 *
 * ПРАВКА 1. Технические брони не должны попадать в списки.
 *   Было:  $filter['technical'] = 0;  в includes/db/appointments.php
 *          (после prepare_params, иначе array_filter() выбрасывал ноль)
 *   Стало: тот же фильтр подставляется в REST-запрос списка броней.
 *
 * ПРАВКА 2. Слот, не помещающийся до конца приёма, не должен предлагаться.
 *   Было:  break вместо $end = $to;  в includes/time-slots.php
 *   Стало: такие слоты отбрасываются в фильтре jet-apb/calendar/slots.
 *
 * Что за технические брони. Кроме двух живых специалистов есть служебный
 * «Iga spetsialist» (ID 1275). Чтобы запись к нему блокировала время у обоих —
 * и наоборот, — cron_5346776343.php создаёт скрытые дубли броней с пометкой
 * technical = 1. В интерфейсе они видны быть не должны.
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
	 * ПРАВКА 1.
	 *
	 * Дописывает в фильтр запроса условие technical = 0. Эндпоинт использует
	 * одни и те же параметры и для выборки, и для подсчёта общего числа,
	 * поэтому постраничная навигация остаётся верной.
	 *
	 * Значение передаём в виде массива с operator: внутри плагина фильтр
	 * прогоняется через array_filter(), и обычный 0 был бы выброшен как пустой.
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

		$request->set_param( 'filter', $filter );

		return $result;
	}

	/**
	 * ПРАВКА 2.
	 *
	 * Плагин нарезает день на слоты и, дойдя до конца рабочего интервала,
	 * обрезает последний слот по этому концу вместо того чтобы его выбросить.
	 * В результате клиенту предлагается приём короче заявленного — например
	 * 20 минут вместо 50.
	 *
	 * Обрезанный слот узнаётся по длительности: она меньше длительности услуги.
	 * Отдельно лезть за графиком не нужно.
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
	 * Длительность одного приёма для пары услуга/специалист, в секундах.
	 * Берётся из графика: у специалиста свой, иначе услуги, иначе общий.
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
