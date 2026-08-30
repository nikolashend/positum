<?php
/**
 * Убирает из форм бронирования первый шаг «онлайн / очно».
 *
 * Структура формы хранится в базе, а база из dev в prod не льётся — поэтому
 * изменение оформлено скриптом: он лежит в git и одинаково применяется
 * в любой среде.
 *
 * Запуск:
 *   wp eval-file scripts/form-remove-format-step.php            — применить
 *   wp eval-file scripts/form-remove-format-step.php revert     — вернуть как было
 *   wp eval-file scripts/form-remove-format-step.php dry-run    — показать, что будет
 *
 * Скрипт идемпотентен: повторный запуск ничего не ломает.
 * Перед первой правкой исходная структура сохраняется в отдельную мету,
 * поэтому откат возможен всегда.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mode = isset( $args[0] ) ? $args[0] : 'apply';

if ( ! in_array( $mode, array( 'apply', 'revert', 'dry-run' ), true ) ) {
	WP_CLI::error( 'Неизвестный режим: ' . $mode . '. Ожидается apply, revert или dry-run.' );
}

if ( ! class_exists( 'Positum_Form_Structure' ) ) {
	WP_CLI::error( 'mu-плагин positum-booking не загружен.' );
}

const POSITUM_FORM_BACKUP_META = '_form_data_positum_backup';

/**
 * @return array Список полей формы с разбивкой по страницам, для отчёта.
 */
function positum_form_outline( $items ) {

	usort( $items, function ( $a, $b ) {
		return ( isset( $a['y'] ) ? $a['y'] : 0 ) <=> ( isset( $b['y'] ) ? $b['y'] : 0 );
	} );

	$pages = array( array() );

	foreach ( $items as $item ) {

		$settings = isset( $item['settings'] ) ? $item['settings'] : array();
		$type     = isset( $settings['type'] ) ? $settings['type'] : '?';

		if ( 'page_break' === $type ) {
			$pages[] = array();
			continue;
		}

		if ( in_array( $type, array( 'hidden', 'submit' ), true ) ) {
			continue;
		}

		$pages[ count( $pages ) - 1 ][] = ( isset( $settings['name'] ) ? $settings['name'] : $type );
	}

	$out = array();

	foreach ( $pages as $index => $fields ) {
		if ( $fields ) {
			$out[] = sprintf( '  шаг %d: %s', $index + 1, implode( ', ', $fields ) );
		}
	}

	return $out;
}

foreach ( Positum_Form_Structure::form_ids() as $form_id ) {

	$title = get_the_title( $form_id );

	if ( ! $title ) {
		WP_CLI::warning( "Форма $form_id не найдена, пропускаю." );
		continue;
	}

	WP_CLI::log( "=== Форма $form_id: $title ===" );

	$backup = get_post_meta( $form_id, POSITUM_FORM_BACKUP_META, true );

	if ( 'revert' === $mode ) {

		if ( ! $backup ) {
			WP_CLI::warning( '  резервной копии нет — форма и не менялась' );
			continue;
		}

		update_post_meta( $form_id, '_form_data', wp_slash( $backup ) );
		delete_post_meta( $form_id, POSITUM_FORM_BACKUP_META );
		delete_post_meta( $form_id, '_rendered_form' );

		WP_CLI::success( '  структура возвращена к исходной' );
		WP_CLI::log( implode( "\n", positum_form_outline( json_decode( stripslashes( $backup ), true ) ) ) );
		continue;
	}

	$raw   = get_post_meta( $form_id, '_form_data', true );
	$items = json_decode( stripslashes( $raw ), true );

	if ( ! is_array( $items ) ) {
		WP_CLI::warning( '  не удалось разобрать структуру формы, пропускаю' );
		continue;
	}

	WP_CLI::log( "  было:" );
	WP_CLI::log( implode( "\n", positum_form_outline( $items ) ) );

	// Ищем поле выбора формата и самый первый разделитель страниц.
	$format_key = null;
	$break_key  = null;
	$break_y    = null;

	foreach ( $items as $key => $item ) {

		$settings = isset( $item['settings'] ) ? $item['settings'] : array();
		$name     = isset( $settings['name'] ) ? $settings['name'] : '';
		$type     = isset( $settings['type'] ) ? $settings['type'] : '';

		if ( 'cons-type' === $name ) {
			$format_key = $key;
		}

		if ( 'page_break' === $type ) {
			$y = isset( $item['y'] ) ? $item['y'] : 0;

			if ( null === $break_y || $y < $break_y ) {
				$break_y   = $y;
				$break_key = $key;
			}
		}
	}

	if ( null === $format_key ) {
		WP_CLI::log( '  шага «онлайн / очно» уже нет — пропускаю' );
		continue;
	}

	if ( null === $break_key ) {
		WP_CLI::warning( '  не найден разделитель страниц, пропускаю' );
		continue;
	}

	unset( $items[ $format_key ], $items[ $break_key ] );
	$items = array_values( $items );

	WP_CLI::log( "  станет:" );
	WP_CLI::log( implode( "\n", positum_form_outline( $items ) ) );

	if ( 'dry-run' === $mode ) {
		WP_CLI::log( '  (пробный прогон, ничего не сохранено)' );
		continue;
	}

	if ( ! $backup ) {
		update_post_meta( $form_id, POSITUM_FORM_BACKUP_META, wp_slash( $raw ) );
	}

	// Два уровня экранирования — не опечатка. Плагин читает мету через
	// stripslashes(), то есть хранит её уже экранированной. update_post_meta()
	// снимает один слой сам. Если применить wp_slash() один раз,
	// последовательности вида В потеряют обратный слэш и русские подписи
	// превратятся в текст «u0412».
	update_post_meta( $form_id, '_form_data', wp_slash( wp_slash( wp_json_encode( $items ) ) ) );
	delete_post_meta( $form_id, '_rendered_form' );

	WP_CLI::success( '  шаг «онлайн / очно» убран' );
}
