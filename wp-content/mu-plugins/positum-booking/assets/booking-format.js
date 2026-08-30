/**
 * Формат консультации у слотов календаря.
 *
 * Слот с единственным форматом уже помечен на сервере. Слоту, доступному
 * в обоих форматах, добавляем переключатель: клиент выбирает очно или онлайн
 * прямо у времени. Выбор кладётся в скрытое поле формы, а сервер по нему
 * подставляет нужную услугу.
 *
 * Слоты приходят аякс-запросом и перерисовываются при смене даты,
 * поэтому переключатели навешиваются наблюдателем, а клики ловятся делегированием.
 */
( function () {
	'use strict';

	var settings = window.positumBookingFormat || {};
	var labels   = settings.labels || { office: 'Kohapeal', online: 'Online' };
	var field    = settings.field || 'cons_place';

	function enhance() {

		var slots = document.querySelectorAll( '.jet-apb-slot--both-formats:not([data-positum-ready])' );

		Array.prototype.forEach.call( slots, function ( slot ) {

			slot.setAttribute( 'data-positum-ready', '1' );

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

			slot.appendChild( wrap );
		} );
	}

	function formatField( from ) {
		var form = from.closest( 'form' );
		return ( form || document ).querySelector( '[name="' + field + '"]' );
	}

	function choose( slot, format ) {

		var input = formatField( slot );

		if ( input ) {
			input.value = format;
		}

		var options = slot.querySelectorAll( '.positum-format-switch__option' );

		Array.prototype.forEach.call( options, function ( button ) {
			button.classList.toggle( 'is-chosen', button.getAttribute( 'data-format' ) === format );
		} );

		var wrap = slot.querySelector( '.positum-format-switch' );

		if ( wrap ) {
			wrap.classList.add( 'is-resolved' );
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
		var chosen = button ? button.getAttribute( 'data-format' ) : null;

		// Слот с единственным форматом решается сам. У слота с двумя формат
		// по умолчанию — первый (очно): так поле никогда не уезжает пустым,
		// а клиент видит, что выбрано, и может переключить.
		if ( ! chosen ) {
			chosen = formats[ 0 ];
		}

		if ( chosen ) {
			choose( slot, chosen );
		}
	}, true );

	function start() {
		enhance();
		new MutationObserver( enhance ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
