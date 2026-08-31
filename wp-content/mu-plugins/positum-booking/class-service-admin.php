<?php
/**
 * Explains on the service edit screen how a service takes part in booking.
 *
 * The in-person and online versions of one consultation type are paired in
 * code, and the pairing is easy to get wrong: the client sees only the
 * in-person one, and the online twin is substituted on submit. Whoever adds a
 * service months from now should not have to reconstruct that from the source.
 *
 * The box is read only on purpose. Editing the pairs here would mean storing
 * them in two places at once, so it points at the one file that owns them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Service_Admin {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
	}

	public static function register() {

		add_meta_box(
			'positum-service-pairing',
			'Участие в бронировании',
			array( __CLASS__, 'render' ),
			Positum_Form_Structure::services_cpt(),
			'side',
			'high'
		);
	}

	public static function render( $post ) {

		$pairs = Positum_Form_Structure::pairs();
		$id    = absint( $post->ID );

		self::styles();

		if ( isset( $pairs[ $id ] ) ) {
			self::offered( $id, $pairs[ $id ] );
		} elseif ( in_array( $id, $pairs, true ) ) {
			self::online_twin( $id, array_search( $id, $pairs, true ) );
		} else {
			self::unused();
		}

		self::how_to_add( $pairs );
	}

	/** The service the client actually picks as a consultation type. */
	private static function offered( $id, $online ) {
		?>
		<p class="psp-state psp-state--on">
			Показывается на сайте как <strong>тип консультации</strong>.
		</p>
		<p>
			Когда клиент выбирает у времени формат «онлайн», вместо этой услуги
			записывается парная: <?php echo self::link( $online ); ?>.
		</p>
		<?php
		self::durations( $id, $online );
	}

	/** The hidden half of a pair. */
	private static function online_twin( $id, $office ) {
		?>
		<p class="psp-state psp-state--twin">
			Это <strong>онлайн-версия</strong> услуги <?php echo self::link( $office ); ?>.
		</p>
		<p>
			В списке на сайте она не показывается — подставляется сама, когда клиент
			выбирает формат «онлайн». Цена и длительность берутся отсюда.
		</p>
		<?php
		self::durations( $office, $id );
	}

	private static function unused() {
		?>
		<p class="psp-state psp-state--off">
			В бронировании <strong>не участвует</strong>.
		</p>
		<p>
			Услуга не привязана ни к одному типу консультации, поэтому на сайте
			её не предложат. Прошлые брони с ней остаются в порядке.
		</p>
		<?php
	}

	/**
	 * The two halves of a pair must run for the same time: the client picks a
	 * slot computed for one service and gets booked into the other, so a
	 * mismatch would move the appointment away from what was on the screen.
	 */
	private static function durations( $office, $online ) {

		$left  = self::duration( $office );
		$right = self::duration( $online );

		if ( ! $left || ! $right ) {
			return;
		}

		$same = $left === $right;
		?>
		<p class="psp-durations <?php echo $same ? 'psp-ok' : 'psp-warn'; ?>">
			<?php if ( $same ) : ?>
				Длительность у пары совпадает: <strong><?php echo esc_html( $left / 60 ); ?> мин</strong>.
			<?php else : ?>
				<strong>Длительности разошлись:</strong>
				<?php echo esc_html( $left / 60 ); ?> мин против <?php echo esc_html( $right / 60 ); ?> мин.
				Клиент выбирает время по одной услуге, а записывается на другую — приём
				сдвинется относительно того, что он видел. Приведите их к одному значению.
			<?php endif; ?>
		</p>
		<?php
	}

	private static function duration( $service ) {

		if ( ! class_exists( '\JET_APB\Plugin' ) ) {
			return 0;
		}

		$calendar = \JET_APB\Plugin::instance()->calendar;

		if ( ! $calendar || ! method_exists( $calendar, 'get_schedule_settings' ) ) {
			return 0;
		}

		return absint( $calendar->get_schedule_settings( null, $service, 0, 'default_slot' ) );
	}

	private static function how_to_add( $pairs ) {
		?>
		<hr class="psp-rule">
		<p class="psp-head">Как добавить новый тип</p>
		<ol class="psp-steps">
			<li>Создайте <strong>две</strong> услуги — очную и онлайновую.</li>
			<li>Задайте им <strong>одинаковую длительность и буфер</strong>.</li>
			<li>Свяжите их в паре — это делает разработчик, одной строкой.</li>
		</ol>
		<p class="psp-note">
			Пары заданы в файле<br>
			<code>mu-plugins/positum-booking/<wbr>class-form-structure.php</code><br>
			функция <code>pairs()</code>. Сейчас там:
		</p>
		<ul class="psp-pairs">
			<?php foreach ( $pairs as $office => $online ) : ?>
				<li><code><?php echo esc_html( $office ); ?> =&gt; <?php echo esc_html( $online ); ?></code>
					— <?php echo esc_html( get_the_title( $office ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="psp-note">
			Клиент видит только очную половину пары — она и есть «тип консультации».
			Формат он выбирает уже у конкретного времени.
		</p>
		<?php
	}

	private static function link( $service ) {

		$title = get_the_title( $service );
		$url   = get_edit_post_link( $service );

		if ( ! $url ) {
			return esc_html( $title );
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $title ) );
	}

	private static function styles() {
		?>
		<style>
			#positum-service-pairing p { margin: 0 0 10px; }
			.psp-state { padding: 8px 10px; border-radius: 3px; }
			.psp-state--on   { background: #edf7ee; border-left: 3px solid #46812b; }
			.psp-state--twin { background: #eef3f9; border-left: 3px solid #2a6fb0; }
			.psp-state--off  { background: #f2f2f3; border-left: 3px solid #a7aaad; }
			.psp-durations { padding: 8px 10px; border-radius: 3px; }
			.psp-ok   { background: #f6f7f7; color: #50575e; }
			.psp-warn { background: #fcf0f1; border-left: 3px solid #b32d2e; }
			.psp-rule { margin: 14px 0; border: 0; border-top: 1px solid #dcdcde; }
			.psp-head { font-weight: 600; }
			.psp-steps { margin: 0 0 10px 18px; list-style: decimal; }
			.psp-steps li { margin-bottom: 4px; }
			.psp-note { color: #50575e; font-size: 12px; }
			.psp-note code { font-size: 11px; }
			.psp-pairs { margin: 0 0 10px; font-size: 12px; }
			.psp-pairs li { margin-bottom: 3px; }
		</style>
		<?php
	}
}
