<?php
/**
 * Freezing updates of the plugins the booking flow depends on.
 *
 * Why. On 7 October 2025 a JetAppointments update wiped the edits that had
 * been made in its sources by hand, and the protection against double bookings
 * silently stopped working, without a single error in the log. The edits now
 * live in Positum_Core_Fixes and survive updates, but the version is still
 * pinned: the booking flow depends on the plugin internals, and updating must
 * be deliberate, with the booking scenarios replayed on dev.
 *
 * How to update properly:
 *   1. ./scripts/sync-prod-to-dev.sh
 *   2. on dev raise the version in the list below, update, test booking
 *   3. ./scripts/plugins-snapshot.sh — record the versions
 *   4. deploy to prod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Plugin_Freeze {

	/**
	 * Plugin => the version it is pinned to.
	 * The version here is an expectation rather than a limit: if the site ends up
	 * with a different one, the admin will say so.
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
	 * Removes the frozen plugins from the list of available updates.
	 * The "Update" button simply does not appear — neither on the plugins page
	 * nor in Dashboard → Updates.
	 */
	public static function hide_updates( $transient ) {

		if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->response ) ) {
			return $transient;
		}

		foreach ( array_keys( self::frozen() ) as $file ) {
			if ( isset( $transient->response[ $file ] ) ) {
				// Moved to no_update rather than removed entirely: this way WordPress
				// still considers the plugin known and does not show it as being
				// of unknown origin.
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
	 * Two admin notices: a calm one saying the versions are pinned, and an
	 * alarming one if a version has drifted from what is expected.
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
	 * @return array Plugins whose installed version differs from the expected.
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
