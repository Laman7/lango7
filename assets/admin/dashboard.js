/* Translate dashboard: "Add to menu" and copying the shortcode. */
( function () {
	'use strict';

	var cfg = window.lango7Dashboard;
	var lib = window.lango7Admin;
	if ( ! cfg || ! lib ) {
		return;
	}
	var t = cfg.i18n;
	var api = lib.client( cfg.rest, cfg.nonce, t.error );

	function say( el, text, cls ) {
		el.textContent = text;
		el.className = 'lango7-result' + ( cls ? ' ' + cls : '' );
	}

	// Builds the shortcode from the Style and Language label choices (native names are the default).
	var names = document.getElementById( 'lango7-switcher-names' );
	var style = document.getElementById( 'lango7-switcher-style' );
	function shortcode() {
		var out = '[lango7_switcher style="' + ( style ? style.value : 'dropdown' ) + '"';
		if ( names && 'native' !== names.value ) {
			out += ' names="' + names.value + '"';
		}
		document.getElementById( 'lango7-shortcode' ).textContent = out + ']';
	}
	[ names, style ].forEach( function ( select ) {
		if ( select ) {
			select.addEventListener( 'change', shortcode );
		}
	} );

	var add = document.getElementById( 'lango7-menu-add' );
	if ( add ) {
		var menu = document.getElementById( 'lango7-menu' );
		var menuResult = document.getElementById( 'lango7-menu-result' );
		var sync = function () {
			var option = menu.options[ menu.selectedIndex ];
			add.disabled = ! option || option.disabled;
		};
		if ( menu.options[ menu.selectedIndex ] && menu.options[ menu.selectedIndex ].disabled ) {
			var free = Array.prototype.filter.call( menu.options, function ( o ) {
				return ! o.disabled;
			} )[ 0 ];
			if ( free ) {
				menu.value = free.value;
			}
		}
		sync();
		menu.addEventListener( 'change', sync );
		add.addEventListener( 'click', function () {
			add.disabled = true;
			api( 'POST', '/switcher/menu', { menu: parseInt( menu.value, 10 ) } ).then( function () {
				menu.options[ menu.selectedIndex ].disabled = true;
				say( menuResult, t.added, 'is-ok' );
			} ).catch( function ( err ) {
				add.disabled = false;
				say( menuResult, err.message, 'is-error' );
			} );
		} );
	}

	document.querySelectorAll( '[data-lango7-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var text = document.getElementById( button.getAttribute( 'data-lango7-copy' ) ).textContent;
			var label = button.textContent;
			function copied() {
				button.textContent = t.copied;
				setTimeout( function () { button.textContent = label; }, 1500 );
			}
			function fallback() {
				var field = document.createElement( 'textarea' );
				field.value = text;
				field.setAttribute( 'readonly', '' );
				field.style.position = 'fixed';
				field.style.opacity = '0';
				document.body.appendChild( field );
				field.select();
				var ok = document.execCommand( 'copy' );
				field.remove();
				if ( ok ) { copied(); } else { button.textContent = 'Select and copy the shortcode'; }
			}
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( copied, fallback );
			} else { fallback(); }
		} );
	} );
}() );
