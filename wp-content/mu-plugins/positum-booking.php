<?php
/**
 * Plugin Name: Positum — Booking
 * Description: Booking customizations for positum.ee on top of JetAppointments: update freeze, replacements for edits that used to live in the plugin sources, consultation format per time slot.
 * Version:     1.0.0
 *
 * This is a mu-plugin: it always loads and cannot be disabled from the admin.
 * Loader only; the code itself lives in the positum-booking/ folder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POSITUM_BOOKING_DIR', __DIR__ . '/positum-booking' );

require_once POSITUM_BOOKING_DIR . '/class-plugin-freeze.php';
require_once POSITUM_BOOKING_DIR . '/class-core-fixes.php';
require_once POSITUM_BOOKING_DIR . '/class-format-schedule.php';
require_once POSITUM_BOOKING_DIR . '/class-format-admin.php';
require_once POSITUM_BOOKING_DIR . '/class-service-admin.php';
require_once POSITUM_BOOKING_DIR . '/class-format-slots.php';
require_once POSITUM_BOOKING_DIR . '/class-form-structure.php';
require_once POSITUM_BOOKING_DIR . '/class-format-frontend.php';
require_once POSITUM_BOOKING_DIR . '/class-form-submit.php';

Positum_Plugin_Freeze::init();
Positum_Core_Fixes::init();
Positum_Format_Admin::init();
Positum_Service_Admin::init();
Positum_Format_Slots::init();
Positum_Form_Structure::init();
Positum_Format_Frontend::init();
Positum_Form_Submit::init();
