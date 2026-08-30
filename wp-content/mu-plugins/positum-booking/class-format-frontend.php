<?php
/**
 * Подключение стилей и скрипта формата на витрине.
 *
 * Файлы крошечные, а форма бронирования вызывается из модального окна
 * на нескольких страницах — определять «есть ли она тут» дороже,
 * чем отдать полтора килобайта.
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
			)
		);
	}

	/**
	 * Версия по времени изменения файла: после выкатки браузеры
	 * подхватывают новый файл без ручной правки номера.
	 */
	private static function version( $path ) {
		return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
	}
}
