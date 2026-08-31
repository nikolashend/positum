<?php
/**
 * Consultation format editor in the specialist's card.
 *
 * A metabox of our own rather than an extension of the JetAppointments
 * schedule editor: that one is built on the plugin's Vue components, and a
 * field can only be added there by editing the sources — exactly what we avoid.
 *
 * The metabox works in the block editor too: WordPress renders classic
 * metaboxes below the canvas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Positum_Format_Admin {

	const NONCE = 'positum_format_schedule_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function register() {

		add_meta_box(
			'positum-format-schedule',
			'Формат консультаций по дням',
			array( __CLASS__, 'render' ),
			Positum_Format_Schedule::providers_cpt(),
			'normal',
			'high'
		);
	}

	public static function render( $post ) {

		$map      = Positum_Format_Schedule::get( $post->ID );
		$formats  = Positum_Format_Schedule::formats();
		$is_empty = Positum_Format_Schedule::is_empty( $map );

		wp_nonce_field( self::NONCE, self::NONCE );

		self::styles();
		?>
		<p class="pfs-intro">
			Разметьте, в какие промежутки дня специалист принимает очно.
			Онлайн доступен всегда, поэтому выбор такой: «только онлайн» или «очно и онлайн».
			Время приёма должно целиком помещаться в интервал — сеанс, который начался бы
			в очные часы, а закончился в онлайновые, клиенту не предложится.
		</p>
		<?php if ( $is_empty ) : ?>
			<p class="pfs-notice">
				Сейчас график форматов не задан: считается, что оба формата доступны всегда.
				Так же вёл себя сайт до появления этой настройки.
			</p>
		<?php endif; ?>

		<div class="pfs-days">
			<?php foreach ( Positum_Format_Schedule::weekdays() as $day => $label ) : ?>
				<div class="pfs-day" data-day="<?php echo esc_attr( $day ); ?>">
					<div class="pfs-day__head">
						<span class="pfs-day__name"><?php echo esc_html( $label ); ?></span>
						<button type="button" class="button button-small pfs-add">+ интервал</button>
					</div>
					<div class="pfs-rows">
						<?php
						foreach ( $map[ $day ] as $index => $interval ) {
							self::row( $day, $index, $interval, $formats );
						}
						?>
					</div>
					<p class="pfs-empty"<?php echo empty( $map[ $day ] ) ? '' : ' hidden'; ?>>Не принимает</p>
				</div>
			<?php endforeach; ?>
		</div>

		<script type="text/template" id="pfs-row-template">
			<?php self::row( '__DAY__', '__INDEX__', array( 'from' => '09:00', 'to' => '17:00', 'format' => Positum_Format_Schedule::BOTH ), $formats ); ?>
		</script>
		<?php
		self::script();
	}

	private static function row( $day, $index, $interval, $formats ) {

		$name = sprintf( 'positum_format[%s][%s]', $day, $index );
		?>
		<div class="pfs-row">
			<input type="time" name="<?php echo esc_attr( $name ); ?>[from]"
			       value="<?php echo esc_attr( $interval['from'] ); ?>" required>
			<span class="pfs-dash">–</span>
			<input type="time" name="<?php echo esc_attr( $name ); ?>[to]"
			       value="<?php echo esc_attr( $interval['to'] ); ?>" required>
			<select name="<?php echo esc_attr( $name ); ?>[format]">
				<?php foreach ( $formats as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"
						<?php selected( $interval['format'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button-link pfs-remove" aria-label="Удалить интервал">Удалить</button>
		</div>
		<?php
	}

	private static function styles() {
		?>
		<style>
			.pfs-intro, .pfs-notice { max-width: 70ch; }
			.pfs-notice { padding: 8px 12px; background: #fcf9e8; border-left: 3px solid #dba617; }
			.pfs-days { display: grid; gap: 8px; margin-top: 14px; }
			.pfs-day { border: 1px solid #dcdcde; border-radius: 3px; padding: 10px 12px; background: #fff; }
			.pfs-day__head { display: flex; align-items: center; gap: 12px; }
			.pfs-day__name { font-weight: 600; min-width: 130px; }
			.pfs-rows { display: flex; flex-direction: column; gap: 6px; }
			.pfs-rows:not(:empty) { margin-top: 10px; }
			.pfs-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
			.pfs-row input[type="time"] { width: 8em; }
			.pfs-dash { color: #646970; }
			.pfs-remove { color: #b32d2e; text-decoration: none; }
			.pfs-empty { margin: 8px 0 0; color: #646970; font-style: italic; }
		</style>
		<?php
	}

	private static function script() {
		?>
		<script>
		( function () {
			var box = document.getElementById( 'positum-format-schedule' );
			if ( ! box ) { return; }

			var template = document.getElementById( 'pfs-row-template' ).innerHTML;

			function refreshEmpty( day ) {
				var rows = day.querySelector( '.pfs-rows' );
				day.querySelector( '.pfs-empty' ).hidden = rows.children.length > 0;
			}

			box.addEventListener( 'click', function ( event ) {

				var add = event.target.closest( '.pfs-add' );
				if ( add ) {
					var day  = add.closest( '.pfs-day' );
					var rows = day.querySelector( '.pfs-rows' );
					// The index comes from a counter, not from the list length: after
					// a removal lengths repeat and fields would overwrite each other.
					var next = parseInt( day.dataset.next || rows.children.length, 10 );
					day.dataset.next = next + 1;

					var html = template
						.split( '__DAY__' ).join( day.dataset.day )
						.split( '__INDEX__' ).join( String( next ) );

					rows.insertAdjacentHTML( 'beforeend', html.trim() );
					refreshEmpty( day );
					return;
				}

				var remove = event.target.closest( '.pfs-remove' );
				if ( remove ) {
					var row    = remove.closest( '.pfs-row' );
					var parent = row.closest( '.pfs-day' );
					row.remove();
					refreshEmpty( parent );
				}
			} );
		}() );
		</script>
		<?php
	}

	public static function save( $post_id, $post ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post->post_type !== Positum_Format_Schedule::providers_cpt() ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE ] ) ? $_POST[ self::NONCE ] : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		// The nonce is here but no intervals — they were all removed by hand.
		// Quick edit and REST saves never reach this point: they carry no nonce
		// of ours, and the function returned above.
		if ( ! isset( $_POST['positum_format'] ) ) {
			Positum_Format_Schedule::save( $post_id, array() );
			return;
		}

		Positum_Format_Schedule::save( $post_id, wp_unslash( $_POST['positum_format'] ) );
	}
}
