/**
 * Consultation format on the calendar time slots.
 *
 * A slot available in a single format gets a badge. A slot available in both
 * gets a switch with a preselected default format — so the form field is never
 * submitted empty, and the client sees what exactly will be booked and can
 * change it.
 *
 * Captions are rendered here rather than on the server: slots arrive in a
 * separate request where the page language is no longer known.
 *
 * Slots are re-rendered on every date change, so the markup is attached by an
 * observer and clicks are caught by delegation.
 */
( function () {
	'use strict';

	var settings = window.positumBookingFormat || {};
	var labels   = settings.labels || { office: 'Kohapeal', online: 'Online' };
	var field    = settings.field || 'cons_place';
	var fallback = settings.defaultFormat || 'online';
	var caption  = settings.summaryCaption || 'Formaat:';
	var title    = settings.summaryTitle || 'Teie broneering';
	var names    = settings.names || {};
	var pairs    = settings.pairs || {};

	var syncing = false;
	var pending = null;

	function badge( format ) {
		var span = document.createElement( 'span' );
		span.className = 'positum-slot-format positum-slot-format--' + format;
		span.textContent = labels[ format ] || format;
		return span;
	}

	function switcher() {

		var wrap = document.createElement( 'span' );
		wrap.className = 'positum-format-switch';

		[ 'office', 'online' ].forEach( function ( format ) {

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'positum-format-switch__option';
			button.setAttribute( 'data-format', format );

			var text = document.createElement( 'span' );
			text.textContent = labels[ format ] || format;
			button.appendChild( text );

			wrap.appendChild( button );
		} );

		return wrap;
	}

	/** Highlights a single format in the switch. */
	function highlight( slot, format ) {

		var options = slot.querySelectorAll( '.positum-format-switch__option' );

		Array.prototype.forEach.call( options, function ( button ) {
			button.classList.toggle( 'is-chosen', button.getAttribute( 'data-format' ) === format );
		} );
	}

	function enhance() {

		var slots = document.querySelectorAll( '.jet-apb-slot[data-formats]:not([data-positum-ready])' );

		Array.prototype.forEach.call( slots, function ( slot ) {

			slot.setAttribute( 'data-positum-ready', '1' );

			var formats = ( slot.getAttribute( 'data-formats' ) || '' ).split( ',' ).filter( Boolean );

			if ( ! formats.length ) {
				return;
			}

			if ( 1 === formats.length ) {
				slot.appendChild( badge( formats[ 0 ] ) );
				return;
			}

			slot.appendChild( switcher() );
			highlight( slot, defaultFor( formats ) );
		} );
	}

	function defaultFor( formats ) {
		return formats.indexOf( fallback ) > -1 ? fallback : formats[ 0 ];
	}

	function formatField() {
		return document.querySelector( '[name="' + field + '"]' );
	}

	/**
	 * Sets the format on the picked slot and returns the others to the default:
	 * otherwise slots clicked earlier keep a stale highlight and it is unclear
	 * what exactly has been booked.
	 */
	function choose( slot, format ) {

		var input = formatField();

		if ( input ) {
			input.value = format;
		}

		highlight( slot, format );

		var others = document.querySelectorAll( '.jet-apb-slot--both-formats' );

		Array.prototype.forEach.call( others, function ( other ) {

			if ( other === slot ) {
				return;
			}

			var formats = ( other.getAttribute( 'data-formats' ) || '' ).split( ',' ).filter( Boolean );
			highlight( other, defaultFor( formats ) );
		} );

		sync();
	}

	/**
	 * Adds the format to the booking details block — without it there is no way
	 * to tell whether an in-person or an online consultation was chosen.
	 */
	function sync() {

		var input  = formatField();
		var format = input ? input.value : '';
		var label  = labels[ format ] || '';
		var items  = document.querySelectorAll( '.jet-apb-appointments-item' );

		Array.prototype.forEach.call( items, function ( item ) {

			// The booking lines live in an inner container: the element itself is
			// a flex row, and inserting next to that container would push us into
			// a second column.
			var host = item.querySelector( '.jet-apb-appointments-item-content' ) || item;

			var heading = host.querySelector( '.jet-apb-item-service-provider' );
			var titled  = bookingTitle();

			if ( heading && titled && heading.textContent.trim() !== titled ) {
				heading.textContent = titled;
			}

			var node = host.querySelector( '.positum-item-format' );

			if ( ! label ) {
				if ( node ) {
					node.parentNode.removeChild( node );
				}
				return;
			}

			if ( ! node ) {
				node = document.createElement( 'div' );
				node.className = 'positum-item-format';
				host.appendChild( node );
			}

			// The plugin appends the date and time after our insertion,
			// so the format line is moved to the end every time.
			if ( host.lastElementChild !== node ) {
				host.appendChild( node );
			}

			var text = caption + ' ' + label;

			// Write only on a mismatch: otherwise the DOM observer would loop
			// on our own edits.
			if ( node.textContent !== text ) {
				node.textContent = text;
			}

			if ( node.getAttribute( 'data-format' ) !== format ) {
				node.setAttribute( 'data-format', format );
			}
		} );
	}

	/** Value picked in a wizard step. */
	function checked( name ) {
		var input = document.querySelector( '[name="' + name + '"]:checked' );
		return input ? input.value : '';
	}

	/**
	 * Post title by its id. Our own translation first, then whatever the plugin
	 * knows: its config carries the titles in the language of the posts.
	 */
	function nameOf( kind, id ) {

		if ( ! id ) {
			return '';
		}

		var mine = ( names[ kind ] || {} )[ id ];

		if ( mine ) {
			return mine;
		}

		var config = window.JetAPBData || {};
		var map    = 'services' === kind ? config.services : config.providers;

		return map && map[ id ] ? map[ id ] : '';
	}

	/** "Service - Specialist" in the language of the page. */
	function bookingTitle() {

		var service  = nameOf( 'services', checked( 'service_id' ) );
		var provider = nameOf( 'providers', checked( 'provider_id' ) );

		return service && provider ? service + ' - ' + provider : '';
	}

	/** The picked date, while the time is not chosen yet. */
	function chosenDate() {

		// Slots already carry the date in the right shape — take it from there
		// so we do not diverge from the calendar format itself.
		var slot = document.querySelector( '.jet-apb-slot[data-friendly-date]' );

		if ( slot ) {
			return slot.getAttribute( 'data-friendly-date' );
		}

		var cell = document.querySelector( '.jet-apb-calendar-date--selected[data-calendar-date]' );

		if ( ! cell ) {
			return '';
		}

		// Calendar timestamps are wall clock, so they are read in UTC.
		var date = new Date( parseInt( cell.getAttribute( 'data-calendar-date' ), 10 ) * 1000 );
		var pad  = function ( n ) { return n < 10 ? '0' + n : String( n ); };

		return pad( date.getUTCDate() ) + '.' + pad( date.getUTCMonth() + 1 ) + '.' + date.getUTCFullYear();
	}

	/**
	 * Shows the chosen consultation and date in the lower block before a time
	 * is picked: the plugin renders something there only after a slot is chosen,
	 * and until then the client cannot see who and what they are booking.
	 *
	 * As soon as the plugin renders the real booking line the placeholder is
	 * removed — otherwise the two would be shown twice.
	 */
	function showChoice() {

		var input = document.querySelector( '[name="appointment_date"]' );
		var page  = input ? input.closest( '.jet-form-page' ) : null;

		if ( ! page ) {
			return;
		}

		var anchor = page.querySelector( '.jet-apb-calendar-appointments-list-wrapper' )
			|| page.querySelector( '.jet-apb-calendar-appointments-list' );

		if ( ! anchor || ! anchor.parentNode ) {
			return;
		}

		var text = bookingTitle();
		var node = page.querySelector( '.positum-choice' );
		var real = page.querySelector( '.jet-apb-appointments-item' );

		if ( ! text || real ) {
			if ( node ) {
				node.parentNode.removeChild( node );
			}
			return;
		}

		if ( ! node ) {
			node = document.createElement( 'div' );
			node.className = 'positum-choice';

			var title = document.createElement( 'div' );
			title.className = 'positum-choice__title';

			var date = document.createElement( 'div' );
			date.className = 'positum-choice__date';

			node.appendChild( title );
			node.appendChild( date );
			anchor.parentNode.insertBefore( node, anchor.nextSibling );
		}

		var title = node.querySelector( '.positum-choice__title' );
		var date  = node.querySelector( '.positum-choice__date' );
		var when  = chosenDate();

		if ( title.textContent !== text ) {
			title.textContent = text;
		}

		if ( date.textContent !== when ) {
			date.textContent = when;
		}

		date.hidden = ! when;
	}

	/**
	 * Repeats the booking summary on the last step.
	 *
	 * The contact details step is a separate page, and what the client picked
	 * earlier is not visible there — confirming a booking blindly is awkward.
	 * The text is taken from the calendar details block so that it is not
	 * rebuilt and cannot diverge from it.
	 */
	function mirrorSummary() {

		var name = document.querySelector( '[name="user_name"]' );
		var page = name ? name.closest( '.jet-form-page' ) : null;

		if ( ! page ) {
			return;
		}

		var source = document.querySelector( '.jet-apb-appointments-item-content' );
		var text   = source ? source.innerText.trim() : '';
		var box    = page.querySelector( '.positum-booking-summary' );

		if ( ! text ) {
			if ( box ) {
				box.parentNode.removeChild( box );
			}
			return;
		}

		if ( ! box ) {
			box = document.createElement( 'div' );
			box.className = 'positum-booking-summary';

			var head = document.createElement( 'div' );
			head.className = 'positum-booking-summary__title';
			head.textContent = title;

			var body = document.createElement( 'div' );
			body.className = 'positum-booking-summary__body';

			box.appendChild( head );
			box.appendChild( body );
			page.insertBefore( box, page.firstChild );
		}

		var body = box.querySelector( '.positum-booking-summary__body' );

		if ( body.textContent !== text ) {
			body.textContent = text;
		}
	}

	document.addEventListener( 'click', function ( event ) {

		if ( ! event.target || ! event.target.closest ) {
			return;
		}

		var slot = event.target.closest( '.jet-apb-slot' );

		if ( ! slot ) {
			return;
		}

		var formats = ( slot.getAttribute( 'data-formats' ) || '' ).split( ',' ).filter( Boolean );

		if ( ! formats.length ) {
			return;
		}

		var button = event.target.closest( '[data-format]' );
		var chosen = button ? button.getAttribute( 'data-format' ) : ( pending || defaultFor( formats ) );

		choose( slot, chosen );

		if ( ! button ) {
			return;
		}

		// The plugin does not treat a click on a button inside a slot as picking
		// the slot itself, so we pick it ourselves — but only if it is not picked
		// yet. The check runs after the original click has finished propagating.
		setTimeout( function () {

			if ( slot.classList.contains( 'jet-apb-slot--selected' ) ) {
				return;
			}

			// pending preserves the chosen format: our own handler runs again, this
			// time without a button, and would otherwise apply the default.
			pending = chosen;
			slot.click();
			pending = null;
		}, 0 );
	}, true );

	/**
	 * Substitutes the online service into the payload of the picked slots.
	 *
	 * A booking is created from the array of slots in the hidden date field
	 * rather than from the service field: every slot carries its own service.
	 *
	 * We hook the click on the submit button rather than the submit event:
	 * JetEngine does not submit the form natively — it collects the field values
	 * itself and sends them as a request, so no submit event happens at all.
	 * Capturing guarantees we run before the values are collected.
	 */
	function swapServiceForOnline( form ) {

		if ( ! form ) {
			return;
		}

		var chosen = form.querySelector( '[name="' + field + '"]' );

		if ( ! chosen || 'online' !== chosen.value ) {
			return;
		}

		var input = form.querySelector( '[name="appointment_date"]' );

		if ( ! input || ! input.value ) {
			return;
		}

		try {

			var slots   = JSON.parse( input.value );
			var changed = false;

			slots.forEach( function ( slot ) {

				var twin = pairs[ String( slot.service ) ];

				if ( twin ) {
					slot.service = twin;
					changed = true;
				}
			} );

			if ( changed ) {
				input.value = JSON.stringify( slots );
			}

		} catch ( error ) {
			// Unexpected payload shape — leave it as is, the service will be
			// corrected on the server by Positum_Form_Structure.
		}
	}

	document.addEventListener( 'click', function ( event ) {

		if ( ! event.target || ! event.target.closest ) {
			return;
		}

		var button = event.target.closest( '.jet-form__submit' );

		if ( button ) {
			swapServiceForOnline( button.closest( 'form.jet-form' ) );
		}
	}, true );

	function refresh() {

		if ( syncing ) {
			return;
		}

		syncing = true;

		try {
			enhance();
			showChoice();
			sync();
			mirrorSummary();
		} finally {
			syncing = false;
		}
	}

	function start() {
		refresh();
		new MutationObserver( refresh ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
