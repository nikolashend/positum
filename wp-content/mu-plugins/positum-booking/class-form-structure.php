<?php
/**
 * Три типа консультации вместо шести услуг.
 *
 * На сайте шесть услуг: каждый тип заведён дважды — очной версией и онлайновой.
 * По ТЗ клиент выбирает тип (три варианта), а формат — уже в календаре.
 *
 * Услуги при этом НЕ сливаются. В списке показываются только очные версии —
 * они играют роль «типа». Когда клиент выбирает формат у слота, при отправке
 * подставляется парная услуга. Так не нужна миграция: прошлые брони, цены
 * и отчётность остаются как есть.
 *
 * Длительности внутри пары совпадают (50/30/80 минут, буфер 10 минут),
 * поэтому подмена не меняет ни границы слота, ни занятость.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Form_Structure {

	/** Имя скрытого поля формы, в котором приезжает выбранный формат. */
	const FORMAT_FIELD = 'cons_place';

	/** Формы бронирования: русская и эстонская. */
	public static function form_ids() {
		return array( 1055, 2026 );
	}

	/**
	 * Пары услуг: очная версия => онлайновая.
	 * Очная считается основной — именно она показывается как тип консультации.
	 */
	public static function pairs() {
		return array(
			1060 => 1063, // Индивидуальная, 50 мин, 65 €
			1049 => 1062, // С ребёнком, 30 мин, 55 €
			1048 => 1061, // Парная / семейная, 80 мин, 95 €
		);
	}

	public static function init() {
		add_filter( 'jet-engine/forms/field-options', array( __CLASS__, 'only_three_types' ), 10, 2 );
		add_filter( 'jet-engine/forms/handler/form-data', array( __CLASS__, 'apply_chosen_format' ), 10, 3 );
		add_filter( 'jet-engine/forms/booking/form-cache', array( __CLASS__, 'disable_cache' ), 10, 2 );
	}

	/**
	 * Кэш отрендеренной формы хранится в мете и обходит все фильтры рендера —
	 * список услуг из него берётся тот, что был на момент первой отрисовки.
	 * Для двух форм бронирования кэш отключаем: формы небольшие, а цена ошибки
	 * велика — клиент увидел бы неактуальный набор услуг.
	 */
	public static function disable_cache( $cache, $form_id ) {

		if ( in_array( absint( $form_id ), self::form_ids(), true ) ) {
			return '';
		}

		return $cache;
	}

	/**
	 * Оставляет в списке услуг только очные версии — три типа консультации.
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

		// Если ни одна из ожидаемых услуг не нашлась, значит состав услуг
		// изменился. Тогда лучше показать всё, чем пустой шаг.
		return $kept ? $kept : $options;
	}

	/**
	 * Подставляет парную услугу, если клиент выбрал онлайн.
	 */
	public static function apply_chosen_format( $data, $form, $fields ) {

		if ( ! is_array( $data ) || empty( $data['service_id'] ) ) {
			return $data;
		}

		$format = isset( $data[ self::FORMAT_FIELD ] ) ? trim( (string) $data[ self::FORMAT_FIELD ] ) : '';

		if ( Positum_Format_Schedule::ONLINE !== $format ) {
			return $data;
		}

		$pairs   = self::pairs();
		$service = absint( $data['service_id'] );

		if ( isset( $pairs[ $service ] ) ) {
			$data['service_id'] = (string) $pairs[ $service ];
		}

		return $data;
	}

	/**
	 * @return string office|online|'' — формат услуги по её принадлежности к паре.
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
