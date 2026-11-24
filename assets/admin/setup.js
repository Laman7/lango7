/* Lango7 setup: languages, how to translate, the API key and when to translate. */
( function () {
	'use strict';

	var cfg = window.lango7Setup;
	var lib = window.lango7Admin;
	var box = document.getElementById( 'lango7-setup' );
	if ( ! cfg || ! lib || ! box ) {
		return;
	}
	var t = cfg.i18n;
	var api = lib.client( cfg.rest, cfg.nonce, t.error );
	var $ = function ( id ) {
		return document.getElementById( id );
	};

	var chosen = cfg.chosen.slice();
	var source = $( 'lango7-setup-source' );
	var add = $( 'lango7-setup-add' );
	var list = $( 'lango7-setup-chosen' );
	var result = $( 'lango7-setup-result' );
	var next = $( 'lango7-setup-next' );
	var back = $( 'lango7-setup-back' );
	var keyField = $( 'lango7-setup-key' );
	var keyResult = $( 'lango7-setup-key-result' );
	var checked = {}; // engine id => key that passed the check
	var labels = { next: next.textContent, finish: box.getAttribute( 'data-finish' ) || 'Finish' };
	var current = 'langs';

	function say( el, text, cls ) {
		el.textContent = text || '';
		el.className = 'lango7-result' + ( cls ? ' ' + cls : '' );
	}
	function radio( name ) {
		var r = box.querySelector( 'input[name="' + name + '"]:checked' );
		return r ? r.value : '';
	}
	function setRadio( name, value ) {
		var r = box.querySelector( 'input[name="' + name + '"][value="' + value + '"]' );
		if ( r && ! r.disabled ) {
			r.checked = true;
		}
	}
	function how() {
		return radio( 'lango7-setup-how' ) || 'free';
	}
	function steps() {
		return how() === 'service' ? [ 'langs', 'how', 'key', 'mode' ] : [ 'langs', 'how' ];
	}

	// Languages.
	function drawLanguages() {
		list.innerHTML = '';
		chosen.forEach( function ( l, i ) {
			var li = document.createElement( 'li' );
			var name = document.createElement( 'span' );
			name.textContent = ( cfg.langs[ l.code ] || { name: l.code } ).name;
			var url = document.createElement( 'code' );
			url.textContent = cfg.home + l.prefix + '/';
			li.appendChild( name );
			li.appendChild( url );
			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link lango7-link-delete';
			remove.textContent = t.remove;
			remove.addEventListener( 'click', function () {
				chosen.splice( i, 1 );
				drawLanguages();
			} );
			li.appendChild( remove );
			list.appendChild( li );
		} );
		add.innerHTML = '';
        var prompt = document.createElement( 'option' );
        prompt.value = '';
        prompt.textContent = t.chooseLanguage || 'Choose a language';
        add.appendChild( prompt );
		Object.keys( cfg.langs ).forEach( function ( code ) {
			if ( code === source.value || chosen.some( function ( l ) { return l.code === code; } ) ) {
				return;
			}
			var o = document.createElement( 'option' );
			o.value = code;
			o.textContent = cfg.langs[ code ].name;
			add.appendChild( o );
		} );
	}
	$( 'lango7-setup-add-button' ).addEventListener( 'click', function () {
		if ( ! add.value ) {
			return;
		}
		var row = { code: add.value, prefix: cfg.langs[ add.value ].prefix };
		chosen.push( row );
		say( result, '' );
		drawLanguages();
	} );
	source.addEventListener( 'change', function () {
		chosen = chosen.filter( function ( l ) {
			return l.code !== source.value;
		} );
		drawLanguages();
	} );

	// How to translate: warn when Free Translate can't do a chosen language.
	function chromeWarning() {
		var warn = $( 'lango7-setup-chrome-warn' );
		var bad = chosen.filter( function ( l ) {
			return cfg.langs[ l.code ] && ! cfg.langs[ l.code ].chrome;
		} ).map( function ( l ) {
			return cfg.langs[ l.code ].name.split( ' — ' )[ 0 ];
		} );
		warn.hidden = ! ( how() === 'free' && bad.length );
		warn.textContent = bad.length ? t.noChrome.replace( '%s', bad.join( ', ' ) ) : '';
		return warn.hidden;
	}
	box.querySelectorAll( 'input[name="lango7-setup-how"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			chromeWarning();
			draw();
		} );
	} );

	// Service and key.
	function service() {
		return cfg.services[ radio( 'lango7-setup-engine' ) ] || null;
	}
	function drawService() {
		var s = service();
		var saved = $( 'lango7-setup-key-saved' );
		if ( ! s ) {
			return;
		}
		keyField.placeholder = s.mask || s.placeholder;
		$( 'lango7-setup-key-help' ).textContent = s.help;
		saved.hidden = ! s.mask;
		saved.textContent = s.mask ? t.saved.replace( '%s', s.mask ) : '';
		say( keyResult, checked[ radio( 'lango7-setup-engine' ) ] === keyField.value && keyField.value ? keyResult.textContent : '' );
	}
	// Stop when the chosen service can't translate a chosen language.
	function serviceWarning() {
		var warn = $( 'lango7-setup-service-warn' );
		var s = service();
		if ( ! warn ) {
			return true;
		}
		var text = '';
		if ( s && s.into ) {
			var bad = chosen.filter( function ( l ) {
				return s.into.indexOf( l.code ) === -1;
			} ).map( function ( l ) {
				return ( cfg.langs[ l.code ] || { name: l.code } ).name.split( ' — ' )[ 0 ];
			} );
			if ( bad.length ) {
				text = t.cantInto.replace( '%1$s', s.label ).replace( '%2$s', bad.join( ', ' ) );
			}
		}
		if ( ! text && s && s.from && s.from.indexOf( source.value ) === -1 ) {
			text = t.cantFrom.replace( '%1$s', s.label ).replace( '%2$s', ( cfg.langs[ source.value ] || { name: source.value } ).name.split( ' — ' )[ 0 ] );
		}
		warn.textContent = text;
		warn.hidden = ! text;
		return ! text;
	}
	box.querySelectorAll( 'input[name="lango7-setup-engine"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			keyField.value = '';
			drawService();
			serviceWarning();
		} );
	} );
	keyField && keyField.addEventListener( 'input', function () {
		say( keyResult, '' );
	} );
	function keyReady() {
		var id = radio( 'lango7-setup-engine' );
		var s = service();
		if ( ! s ) {
			return false;
		}
		return keyField.value ? checked[ id ] === keyField.value : !! s.mask;
	}
	var check = $( 'lango7-setup-check' );
	check && check.addEventListener( 'click', function () {
		var id = radio( 'lango7-setup-engine' );
		if ( ! id || ( ! keyField.value && ! service().mask ) ) {
			say( keyResult, t.needKey, 'is-error' );
			return;
		}
		var key = keyField.value;
		check.disabled = true;
		say( keyResult, t.checking );
		api( 'POST', '/engines/' + encodeURIComponent( id ) + '/test', { key: key, target: chosen.length ? chosen[ 0 ].code : '', fields: {} } )
			.then( function ( r ) {
				if ( r.ok ) {
					checked[ id ] = key;
					say( keyResult, t.works.replace( '%1$s', 'Hello' ).replace( '%2$s', r.sample ), 'is-ok' );
				} else {
					say( keyResult, r.message, 'is-error' );
				}
			} )
			.catch( function ( err ) {
				say( keyResult, err.message, 'is-error' );
			} )
			.then( function () {
				check.disabled = false;
			} );
	} );

	// Steps.
	function draw() {
		var all = steps();
		var at = all.indexOf( current );
		box.querySelectorAll( 'section[data-step]' ).forEach( function ( s ) {
			s.hidden = s.getAttribute( 'data-step' ) !== current;
		} );
		box.querySelectorAll( '.lango7-setup-steps li' ).forEach( function ( li ) {
			var i = all.indexOf( li.getAttribute( 'data-for' ) );
			li.hidden = -1 === i;
			li.className = i === at ? 'is-current' : ( i > -1 && i < at ? 'is-done' : '' );
			if ( i === at ) {
				li.setAttribute( 'aria-current', 'step' );
			} else {
				li.removeAttribute( 'aria-current' );
			}
		} );
		back.hidden = at <= 0;
		next.textContent = at === all.length - 1 ? labels.finish : labels.next;
	}
	function valid() {
		if ( current === 'langs' && ! chosen.length ) {
			say( result, t.needLang, 'is-error' );
			return false;
		}
		if ( current === 'how' && ! chromeWarning() ) {
			return false;
		}
		if ( current === 'key' && ! serviceWarning() ) {
			return false;
		}
		if ( current === 'key' && ! keyReady() ) {
			say( result, t.needKey, 'is-error' );
			return false;
		}
		return true;
	}
	function go( step ) {
		current = step;
		say( result, '' );
		draw();
		if ( step === 'key' ) {
			serviceWarning();
		}
		var section = box.querySelector( 'section[data-step="' + step + '"] h2' );
		if ( section ) {
			section.setAttribute( 'tabindex', '-1' );
			section.focus();
		}
	}

	function settings() {
		var data = {
			source_lang: source.value,
			languages: chosen.map( function ( l ) {
				return { code: l.code, prefix: l.prefix };
			} )
		};
		var id = radio( 'lango7-setup-engine' );
		if ( how() === 'service' && id && keyField.value && checked[ id ] === keyField.value ) {
			data[ id + '_key' ] = keyField.value;
		}
		return api( 'POST', '/settings', data );
	}
	function finish( state ) {
		next.disabled = true;
		say( result, t.saving );
		var first = state === 'done' || steps().indexOf( current ) > 0 ? settings() : Promise.resolve();
		first.then( function () {
			return api( 'POST', '/setup', { state: state, how: how(), engine: radio( 'lango7-setup-engine' ), mode: radio( 'lango7-setup-mode' ) || 'manual' } );
		} ).then( function ( r ) {
			window.location.href = r.redirect;
		} ).catch( function ( err ) {
			next.disabled = false;
			say( result, err.message, 'is-error' );
		} );
	}

	next.addEventListener( 'click', function () {
		if ( ! valid() ) {
			return;
		}
		var all = steps();
		var at = all.indexOf( current );
		if ( at === all.length - 1 ) {
			finish( 'done' );
		} else {
			go( all[ at + 1 ] );
		}
	} );
	back.addEventListener( 'click', function () {
		var all = steps();
		go( all[ Math.max( 0, all.indexOf( current ) - 1 ) ] );
	} );
	$( 'lango7-setup-later' ).addEventListener( 'click', function () {
		finish( 'skipped' );
	} );

	// Start from what is set up now.
	setRadio( 'lango7-setup-how', cfg.current );
	if ( ! radio( 'lango7-setup-how' ) ) {
		setRadio( 'lango7-setup-how', 'free' );
	}
	setRadio( 'lango7-setup-mode', cfg.mode );
	setRadio( 'lango7-setup-engine', cfg.engine || Object.keys( cfg.services )[ 0 ] || '' );
	if ( keyField ) {
		drawService();
	}
	drawLanguages();
	draw();
}() );
