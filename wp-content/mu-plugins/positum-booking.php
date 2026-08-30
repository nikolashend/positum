<?php
/**
 * Plugin Name: Positum — бронирование
 * Description: Доработки бронирования positum.ee поверх JetAppointments: заморозка обновлений, замена правок, которые раньше вносились в исходники плагина, формат консультации на уровне слотов.
 * Version:     1.0.0
 *
 * Это mu-плагин: он подключается всегда и его нельзя отключить из админки.
 * Загрузчик; сам код — в папке positum-booking/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSITUM_BOOKING_DIR', __DIR__ . '/positum-booking' );

require_once POSITUM_BOOKING_DIR . '/class-plugin-freeze.php';
require_once POSITUM_BOOKING_DIR . '/class-core-fixes.php';
require_once POSITUM_BOOKING_DIR . '/class-format-schedule.php';
require_once POSITUM_BOOKING_DIR . '/class-format-admin.php';
require_once POSITUM_BOOKING_DIR . '/class-format-slots.php';
require_once POSITUM_BOOKING_DIR . '/class-form-structure.php';
require_once POSITUM_BOOKING_DIR . '/class-format-frontend.php';

Positum_Plugin_Freeze::init();
Positum_Core_Fixes::init();
Positum_Format_Admin::init();
Positum_Format_Slots::init();
Positum_Form_Structure::init();
Positum_Format_Frontend::init();
