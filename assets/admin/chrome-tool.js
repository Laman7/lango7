/* Translate with Chrome: drives Chrome's Translator API and saves through lango7/v1/chrome. */
( function () {
	'use strict';

	var cfg = window.lango7Chrome;
	var core = window.lango7ChromeCore;
	var start = document.getElementById( 'lango7-chrome-start' );
	if ( ! cfg || ! core || ! start ) {
		return;
	}
	var t = cfg.i18n;
	var stop = document.getElementById( 'lango7-chrome-stop' );
	var check = document.getElementById( 'lango7-chrome-check' );
	var select = document.getElementById( 'lango7-chrome-lang' );
	var requestedLanguage = new URLSearchParams( window.location.search ).get( 'lang' );
	if ( requestedLanguage && cfg.languages.some( function ( choice ) { return choice.code === requestedLanguage; } ) ) {
		select.value = requestedLanguage;
	}
	var box = document.getElementById( 'lango7-chrome-progress' );
	var bar = box.querySelector( 'progress' );
	var step = box.querySelector( '.lango7-chrome-step' );
	var counts = box.querySelector( '.lango7-chrome-counts' );
	var note = document.getElementById( 'lango7-chrome-switch' );
	var waiting = document.getElementById( 'lango7-chrome-waiting' );
	var next = document.getElementById( 'lango7-chrome-next' );
    var doneNotice = document.getElementById( 'lango7-chrome-done' );
    var completed = false;
    var debug = document.getElementById( 'lango7-chrome-debug-output' );
    var debugState = { availability: 'not checked', error: '' };
    function diagnostics() {
        if ( ! debug ) { return; }
        var selected = lang();
        debug.textContent = [
            'Page: Free Translate',
            'Browser: ' + navigator.userAgent,
            'Secure context: ' + String( window.isSecureContext ),
            'Translator API: ' + String( 'Translator' in self ),
            'Configured site language: ' + ( cfg.sourceCode || '(empty)' ),
            'Chrome source code: ' + ( cfg.source || '(empty)' ),
            'Selected language: ' + ( selected ? selected.code : '(none)' ),
            'Chrome target code: ' + ( selected ? selected.chrome || '(empty)' : '(none)' ),
            'Availability: ' + debugState.availability,
            'Last error: ' + ( debugState.error || '(none)' )
        ].join( '\n' );
    }


	var running = false;
	var stopped = false;
	var saved = 0;
	var rejected = 0;

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d)\$[sd]/g, function ( m, n ) {
			return String( args[ n - 1 ] );
		} ).replace( '%s', String( args[ 0 ] ) ).replace( '%%', '%' );
	}

	function api( method, path, body ) {
		return fetch( cfg.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || t.error );
				}
				return data;
			} );
		} );
	}

	function lang() {
		var code = select.value;
		return cfg.languages.filter( function ( l ) {
			return l.code === code;
		} )[ 0 ] || null;
	}

	/**
	 * The languages list to save when starting l: the site's languages plus l.
	 */
	function nextLanguages( l ) {
		var list = cfg.enabled.map( function ( e ) {
			return { code: e.code, prefix: e.prefix };
		} );
		list.push( { code: l.code, prefix: l.prefix } );
		return list;
	}

	function describe( l ) {
		start.textContent = t.start;
		note.hidden = true;
		waiting.textContent = format( t.waiting, l ? l.waiting : 0 );
		if ( ! l || l.enabled ) {
			return;
		}
		note.textContent = format( t.add, l.name );
		note.hidden = false;
		start.textContent = format( t.addNew, l.name );
	}

	// Turn the language on (as Settings would) before its pages can be translated.
	async function enable( l ) {
		say( step, t.switching );
		var data = await api( 'POST', '/settings', { languages: nextLanguages( l ) } );
		cfg.enabled = data.languages;
		var on = data.languages.map( function ( e ) {
			return e.code;
		} );
		cfg.languages.forEach( function ( c ) {
			c.enabled = on.indexOf( c.code ) !== -1;
		} );
		describe( l );
	}

	function say( el, text, cls ) {
		el.textContent = text;
		el.className = el.className.replace( / is-\w+/g, '' ) + ( cls ? ' ' + cls : '' );
	}

	function updateCounts() {
		counts.textContent = format( t.counts, saved, rejected );
	}

	// Some Chromium builds expose the API without the on-device service behind it; its promises never settle.
	function withTimeout( promise, ms ) {
		return Promise.race( [
			promise,
			new Promise( function ( resolve, reject ) {
				setTimeout( function () {
					reject( new Error( t.noResponse ) );
				}, ms );
			} )
		] );
	}

	async function checkSupport() {
		start.disabled = true;
        if ( completed ) { return; }
		var l = lang();
		describe( l );
        diagnostics();
		if ( ! l ) {
			check.textContent = '';
			return;
		}
        var brands = navigator.userAgentData && navigator.userAgentData.brands;
        var googleChrome = brands ? brands.some( function ( b ) { return b.brand === 'Google Chrome'; } ) : /Chrome\/\d+/.test( navigator.userAgent ) && ! /Edg\/|OPR\/|SamsungBrowser\/|CriOS\/|HeadlessChrome\//.test( navigator.userAgent );
        if ( ! googleChrome ) {
            say( check, t.chromeOnly, 'is-error' );
            debugState.error = 'Google Chrome required';
            diagnostics();
            return;
        }
		if ( ! window.isSecureContext ) {
			say( check, t.insecure, 'is-error' ); // Chrome only offers the API on https (and localhost).
			return;
		}
		if ( ! ( 'Translator' in self ) ) {
			say( check, t.noApi, 'is-error' );
			return;
		}
        if ( ! cfg.source || ! /^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/.test( cfg.source ) ) {
            debugState.error = 'Invalid Chrome source language code';
            diagnostics();
            say( check, t.invalidSource, 'is-error' );
            return;
        }
        if ( ! l.chrome || ! /^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/.test( l.chrome ) ) {
            debugState.error = 'Invalid Chrome target language code';
            diagnostics();
            say( check, t.unsupported, 'is-error' );
            return;
        }
        debugState.error = '';
        debugState.availability = 'checking';
        diagnostics();
        var state;
        try {
            state = await withTimeout( self.Translator.availability( { sourceLanguage: cfg.source, targetLanguage: l.chrome } ), 15000 );
        } catch ( e ) {
            debugState.error = e.message || String( e );
            debugState.availability = 'error';
            diagnostics();
            throw e;
        }
        debugState.availability = state;
        diagnostics();
        if ( select.value !== l.code ) { return; }
		if ( state === 'unavailable' || ! cfg.source ) {
			say( check, t.unsupported, 'is-error' );
			return;
		}
		say( check, state === 'available' ? t.ready : t.download, 'is-ok' );
		start.disabled = false;
	}

	/**
	 * Translate everything still waiting, for one page (path) or all ('').
	 */
	async function drain( l, translate, path, hash ) {
		var tried = {};
		while ( ! stopped ) {
			var query = '?lang=' + encodeURIComponent( l.code ) + '&limit=40' + ( path ? '&path=' + encodeURIComponent( path ) : '' ) + ( hash ? '&hash=' + encodeURIComponent( hash ) : '' );
			var r = await api( 'GET', '/chrome/pending' + query );
			var items = r.items.filter( function ( i ) {
				return ! tried[ i.hash ];
			} );
			if ( ! items.length ) {
				return;
			}
			var out = [];
			for ( var i = 0; i < items.length && ! stopped; i++ ) {
				tried[ items[ i ].hash ] = true;
				try {
					var res = await core.translateSegment( items[ i ].text, translate );
					out.push( { hash: items[ i ].hash, text: res.text } );
				} catch ( e ) {
					rejected++; // Left untranslated; it stays in the list for the next run.
				}
			}
			if ( out.length ) {
				var s = await api( 'POST', '/chrome/translations', { lang: l.code, items: out } );
				saved += s.saved;
				rejected += s.failed;
			}
			updateCounts();
		}
	}

	async function run() {
		var l = lang();
		var translator;
		if ( running || ! l ) {
			return;
		}
		// Show feedback before Chrome creates or downloads its language model.
		running = true;
		stopped = false;
		saved = 0;
		rejected = 0;
		start.disabled = true;
		start.hidden = true;
		stop.hidden = false;
		box.hidden = false;
		bar.removeAttribute( 'value' );
		if ( next ) { next.hidden = true; }
		if ( doneNotice ) { doneNotice.hidden = true; }
		say( step, t.preparing );
		updateCounts();
		// create() must run inside the click (user activation) when the model needs downloading.
		try {
			translator = await self.Translator.create( {
				sourceLanguage: cfg.source,
				targetLanguage: l.chrome,
				monitor: function ( m ) {
					m.addEventListener( 'downloadprogress', function ( e ) {
						say( step, format( t.downloading, Math.round( e.loaded * 100 ) ) );
					} );
				}
			} );
		} catch ( e ) {
            debugState.error = e.message || String( e );
            diagnostics();
			say( step, e.message || t.error, 'is-error' );
			running = false;
			start.disabled = false;
			start.hidden = false;
			stop.hidden = true;
			return;
		}

		say( check, t.ready, 'is-ok' ); // The model is on this computer now.
		bar.value = 0;
		var translate = function ( text ) {
			return translator.translate( text );
		};

		try {
			if ( ! l.enabled ) {
				await enable( l );
			}
			var scope = document.querySelector( '[name=lango7-scope]:checked' ).value;
			if ( scope === 'string' && cfg.hash ) {
				say( step, t.translatingSelected );
				await drain( l, translate, '', cfg.hash );
				bar.value = bar.max;
			}
			if ( scope === 'page' && cfg.page ) {
				// Viewing the translated page as an editor records its text on the server.
				say( step, format( t.page, 1, 1, cfg.page ) );
				await fetch( cfg.home + l.prefix + cfg.page, { credentials: 'same-origin', cache: 'no-store' } );
				await drain( l, translate, ( new URL( cfg.home ) ).pathname.replace( /\/$/, '' ) + cfg.page );
				bar.value = bar.max;
			}
			if ( scope === 'site' ) {
				say( step, t.listing );
				var urls = await api( 'GET', '/chrome/urls?lang=' + encodeURIComponent( l.code ) );
				bar.max = urls.length || 1;
				for ( var i = 0; i < urls.length && ! stopped; i++ ) {
					say( step, format( t.page, i + 1, urls.length, urls[ i ].path ) );
					// Viewing the translated page as an editor records its text on the server.
					await fetch( urls[ i ].url, { credentials: 'same-origin', cache: 'no-store' } );
					await drain( l, translate, urls[ i ].path );
					bar.value = i + 1;
				}
			}
			if ( ! stopped && scope !== 'page' && scope !== 'string' ) {
				say( step, t.leftovers );
				await drain( l, translate, '' );
			}
			say( step, stopped ? t.stopped : t.done, stopped ? '' : 'is-ok' );
            if ( ! stopped ) {
                completed = true;
                start.disabled = true;
                start.classList.remove( 'button-primary' );
                if ( doneNotice ) { doneNotice.hidden = false; }
            }
			if ( ! stopped && next ) {
				document.getElementById( 'lango7-chrome-view' ).href = cfg.home + l.prefix + ( scope === 'page' && cfg.page ? cfg.page : '/' ) + '?lango7_tour=1';
				next.hidden = false;
			}
		} catch ( e ) {
			say( step, e.message || t.error, 'is-error' );
		} finally {
			if ( translator.destroy ) {
				translator.destroy();
			}
			running = false;
			if ( ! completed ) { start.disabled = false; }
			start.hidden = false;
			stop.hidden = true;
		}
	}

	start.addEventListener( 'click', run );
	stop.addEventListener( 'click', function () {
		stopped = true;
		stop.disabled = true;
		setTimeout( function () {
			stop.disabled = false;
		}, 1500 );
	} );
	select.addEventListener( 'change', function () {
        completed = false;
        start.classList.add( 'button-primary' );
        if ( doneNotice ) { doneNotice.hidden = true; }
        checkSupport().catch( function ( e ) { say( check, e.message || t.error, 'is-error' ); } );
    } );
    document.querySelectorAll( '[name=lango7-scope]' ).forEach( function ( radio ) {
        radio.addEventListener( 'change', function () {
            completed = false;
            start.classList.add( 'button-primary' );
            if ( doneNotice ) { doneNotice.hidden = true; }
            checkSupport().catch( function ( e ) { say( check, e.message || t.error, 'is-error' ); } );
        } );
    } );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = t.leave;
		}
	} );

	checkSupport().catch( function ( e ) {
	    debugState.error = e.message || String( e );
    diagnostics();
	say( check, e.message || t.error, 'is-error' );
	} );
}() );
