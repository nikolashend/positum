<?php
/**
 * Заморозка обновлений плагинов, на которых держится бронирование.
 *
 * Зачем. 7 октября 2025 обновление JetAppointments стёрло правки, внесённые
 * в его исходники вручную, и защита от двойных броней перестала работать —
 * молча, без единой ошибки в логе. Сами правки теперь живут в Positum_Core_Fixes
 * и обновлением не стираются, но версию всё равно держим фиксированной:
 * бронирование завязано на внутреннее устройство плагина, и обновляться
 * нужно осознанно, прогнав сценарии записи на dev.
 *
 * Как обновиться правильно:
 *   1. ./scripts/sync-prod-to-dev.sh
 *   2. на dev поднять версию в списке FROZEN, обновить плагин, проверить запись
 *   3. ./scripts/plugins-snapshot.sh — зафиксировать версии
 *   4. выкатить на prod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Plugin_Freeze {

	/**
	 * Плагин => версия, на которой он зафиксирован.
	 * Версия здесь — не ограничение, а ожидание: если на сайте окажется другая,
	 * админка об этом скажет.
	 */
	private static function frozen() {
		return array(
			'jet-appointments-booking/jet-appointments-booking.php' => '2.2.4',
			'jet-engine/jet-engine.php'                            => '3.7.6',
		);
	}

	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'hide_updates' ), 999 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'block_auto_update' ), 999, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Убирает замороженные плагины из списка доступных обновлений.
	 * Кнопка «Обновить» просто не появляется — ни на странице плагинов,
	 * ни в «Консоль → Обновления».
	 */
	public static function hide_updates( $transient ) {

		if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->response ) ) {
			return $transient;
		}

		foreach ( array_keys( self::frozen() ) as $file ) {
			if ( isset( $transient->response[ $file ] ) ) {
				// Переносим в no_update, а не удаляем совсем: так WordPress
				// продолжает считать плагин известным и не показывает его
				// как «неизвестного происхождения».
				$transient->no_update[ $file ] = $transient->response[ $file ];
				unset( $transient->response[ $file ] );
			}
		}

		return $transient;
	}

	public static function block_auto_update( $update, $item ) {

		$file = is_object( $item ) && ! empty( $item->plugin ) ? $item->plugin : '';

		if ( $file && array_key_exists( $file, self::frozen() ) ) {
			return false;
		}

		return $update;
	}

	/**
	 * Две заметки в админке: спокойная — что версии зафиксированы,
	 * и тревожная — если версия всё-таки разъехалась с ожидаемой.
	 */
	public static function notice() {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'update-core' ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$mismatched = self::mismatched_versions();

		if ( $mismatched ) {
			echo '<div class="notice notice-error"><p><strong>Версия плагина бронирования изменилась.</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $mismatched as $name => $versions ) {
				printf(
					'<li>%s — ожидалась %s, установлена %s</li>',
					esc_html( $name ),
					esc_html( $versions['expected'] ),
					esc_html( $versions['actual'] )
				);
			}
			echo '</ul><p>Проверьте запись на приём и обновите ожидаемую версию в <code>mu-plugins/positum-booking/class-plugin-freeze.php</code>.</p></div>';
			return;
		}

		echo '<div class="notice notice-info"><p>'
			. 'Обновления <strong>JetAppointments</strong> и <strong>JetEngine</strong> намеренно скрыты: бронирование завязано на их внутреннее устройство. '
			. 'Обновлять только через dev-копию — порядок описан в <code>mu-plugins/positum-booking/class-plugin-freeze.php</code>.'
			. '</p></div>';
	}

	/**
	 * @return array Плагины, чья установленная версия разошлась с ожидаемой.
	 */
	private static function mismatched_versions() {

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$result    = array();

		foreach ( self::frozen() as $file => $expected ) {

			if ( empty( $installed[ $file ] ) ) {
				continue;
			}

			$actual = $installed[ $file ]['Version'];

			if ( $actual !== $expected ) {
				$result[ $installed[ $file ]['Name'] ] = array(
					'expected' => $expected,
					'actual'   => $actual,
				);
			}
		}

		return $result;
	}
}
