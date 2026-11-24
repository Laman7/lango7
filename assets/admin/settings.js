/* Lango7 settings screen. Vanilla JS; every action goes through lango7/v1. */
( function () {
	'use strict';

	var cfg = window.lango7Admin;
	var form = document.getElementById( 'lango7-settings' );
	if ( ! cfg || ! form ) {
		return;
	}
	var t = cfg.i18n;

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

	function notice( message, type ) {
		var box = document.getElementById( 'lango7-notice' );
		box.className = 'notice inline notice-' + ( type || 'success' );
		box.querySelector( 'p' ).textContent = message;
		box.hidden = false;
		box.scrollIntoView( { block: 'nearest' } );
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d)\$[sd]/g, function ( m, n ) {
			return String( args[ n - 1 ] );
		} );
	}

	function el( tag, text, attrs ) {
		var node = document.createElement( tag );
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		return node;
	}

	function renderStatus( s ) {
		var body = document.getElementById( 'lango7-status-body' );
		if ( ! body ) {
			return;
		}
		body.textContent = '';
		s.languages.forEach( function ( l ) {
			var p = el( 'p' );
			p.appendChild( el( 'strong', l.name ) );
			p.appendChild( document.createTextNode( ' — ' ) );
			p.appendChild( el( 'a', t.view, { href: l.url, target: '_blank' } ) );
			p.appendChild( el( 'br' ) );
			p.appendChild( document.createTextNode( s.server_engine ? format( t.progress, l.done, l.strings, l.queued, l.failed ) : format( t.waiting, l.done, l.strings, l.waiting ) ) );
			body.appendChild( p );
		} );
		if ( ! s.server_engine ) {
			return;
		}
		var usage = el( 'p' );
		usage.appendChild( document.createTextNode( format( t.today, s.chars_today.toLocaleString(), s.cap ? s.cap.toLocaleString() : t.noCap ) ) );
		usage.appendChild( el( 'br' ) );
		usage.appendChild( document.createTextNode( format( t.month, s.chars_month.toLocaleString(), s.cost_month.toFixed( 2 ) ) ) );
		body.appendChild( usage );
		if ( s.cap_reached ) {
			body.appendChild( el( 'p', t.capHit, { 'class': 'lango7-warn' } ) );
		}
		if ( s.errors.length ) {
			var details = el( 'details' );
			details.appendChild( el( 'summary', t.errors ) );
			var ul = el( 'ul', null, { 'class': 'lango7-errors' } );
			s.errors.forEach( function ( e ) {
				var li = el( 'li' );
				li.appendChild( el( 'time', e.time ) );
				li.appendChild( document.createTextNode( ' ' + e.message ) );
				ul.appendChild( li );
			} );
			details.appendChild( ul );
			body.appendChild( details );
		}
	}

	function busy( button, on ) {
		button.disabled = on;
		button.classList.toggle( 'is-busy', on );
	}

	// Added languages: one row each (see SettingsPage::languageRow). Fields
	// carry data-field and are sent per row, not by name.
	var langs = document.getElementById( 'lango7-langs' );
	var addLang = document.getElementById( 'lango7-lang-add' );

	function rows() {
		return langs ? Array.prototype.slice.call( langs.querySelectorAll( '.lango7-lang-row' ) ) : [];
	}

	function field( row, name ) {
		return row.querySelector( '[data-field="' + name + '"]' );
	}

	function firstCode() {
		var row = rows()[ 0 ];
		return row ? field( row, 'code' ).value : '';
	}

	function entry( box, code ) {
		var out = { code: code };
		box.querySelectorAll( '[data-field]' ).forEach( function ( input ) {
			out[ input.getAttribute( 'data-field' ) ] = input.type === 'checkbox' ? input.checked : String( input.value ).trim();
		} );
		out.code = code;
		return out;
	}

	// Grey out the services that cannot translate a language, and say why.
	// keep: leave the saved choice selectable (an Edit panel), else switch to
	// the first service that works (the Add dialog). False when none works.
	function limitEngines( select, code, keep ) {
		var l = cfg.langs && cfg.langs[ code ];
		if ( ! select || ! l ) {
			return true;
		}
		var name = function ( id ) {
			return ( cfg.names && cfg.names[ id ] ) || id;
		};
		var works = function ( o ) {
			return l.services.indexOf( o.value || l.default ) > -1;
		};
		var off = [];
		Array.prototype.forEach.call( select.options, function ( o ) {
			if ( ! o.hasAttribute( 'data-label' ) ) {
				o.setAttribute( 'data-label', o.textContent );
			}
			var ok = works( o );
			o.disabled = ! ok && ! ( keep && o.selected );
			o.textContent = o.getAttribute( 'data-label' ) + ( ok ? '' : ' — ' + t.notFor );
			if ( ! ok && o.value && off.indexOf( name( o.value ) ) === -1 ) {
				off.push( name( o.value ) );
			}
		} );
		var current = select.options[ select.selectedIndex ];
		if ( ! keep && current && ! works( current ) ) {
			var first = Array.prototype.filter.call( select.options, function ( o ) {
				return ! o.disabled;
			} )[ 0 ];
			if ( first ) {
				select.value = first.value;
				current = first;
			}
		}
		var note = select.parentNode.querySelector( '.lango7-engine-note' );
		var text = '';
		var bad = false;
		if ( ! l.services.length ) {
			text = t.none.replace( '%s', l.name );
			bad = true;
		} else if ( current && ! works( current ) ) {
			text = format( t.stuck, name( current.value || l.default ), l.name );
			bad = true;
		} else if ( off.length ) {
			text = format( t.cannot, off.join( ', ' ), l.name );
		}
		if ( note ) {
			note.textContent = text;
			note.hidden = ! text;
			note.classList.toggle( 'is-error', bad );
		}
		return l.services.length > 0;
	}

	rows().forEach( function ( row ) {
		limitEngines( field( row, 'engine' ), field( row, 'code' ).value, true );
	} );

	if ( langs ) {
		langs.addEventListener( 'change', function ( e ) {
			if ( e.target.getAttribute( 'data-field' ) === 'engine' ) {
				limitEngines( e.target, field( e.target.closest( '.lango7-lang-row' ), 'code' ).value, true );
			}
		} );
		langs.addEventListener( 'click', function ( e ) {
			var edit = e.target.closest( '.lango7-lang-edit' );
			if ( edit ) {
				var panel = document.getElementById( edit.getAttribute( 'aria-controls' ) );
				var open = edit.getAttribute( 'aria-expanded' ) !== 'true';
				edit.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				edit.closest( '.lango7-lang-row' ).classList.toggle( 'is-open', open );
				panel.hidden = ! open;
				if ( open ) {
					var first = panel.querySelector( 'input, select' );
					first && first.focus();
				}
				return;
			}
			var remove = e.target.closest( '.lango7-remove-button' );
			if ( ! remove ) {
				return;
			}
			var del = remove.closest( '.lango7-remove' ).querySelector( '.lango7-remove-delete' ).checked;
			if ( ! window.confirm( del ? t.deleteAsk : t.removeAsk ) ) {
				return;
			}
			busy( remove, true );
			api( 'POST', '/languages/remove', { code: remove.getAttribute( 'data-code' ), delete: del } )
				.then( function () {
					window.location.href = cfg.page;
				} )
				.catch( function ( err ) {
					notice( err.message, 'error' );
					busy( remove, false );
				} );
		} );
	}

	// Add a language: search, pick, check the address, add (saved at once).
	var dialog = document.getElementById( 'lango7-lang-dialog' );
	if ( addLang && dialog && cfg.langs ) {
		var search = document.getElementById( 'lango7-lang-search' );
		var list = document.getElementById( 'lango7-lang-options' );
		var none = document.getElementById( 'lango7-lang-none' );
		var popular = document.getElementById( 'lango7-lang-popular' );
		var confirmButton = document.getElementById( 'lango7-lang-confirm' );
		var result = document.getElementById( 'lango7-lang-result' );
		var fresh = document.getElementById( 'lango7-lang-new' );
		var prefixField = field( fresh, 'prefix' );
		var engineField = field( fresh, 'engine' );
		var replace = addLang.getAttribute( 'data-replace' ) === '1';
		var picked = '';
		var active = -1;

		var fold = function ( text ) {
			return String( text ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
		};
		var added = function () {
			return rows().map( function ( r ) {
				return field( r, 'code' ).value;
			} );
		};
		var source = function () {
			var s = document.getElementById( 'lango7-source' );
			return s ? s.value : '';
		};
		// Why a language cannot be picked, or '' when it can.
		var blocked = function ( code ) {
			var l = cfg.langs[ code ];
			if ( code === source() ) {
				return t.siteLang;
			}
			if ( ! replace && added().indexOf( code ) > -1 ) {
				return t.added;
			}
			if ( replace && added()[ 0 ] === code ) {
				return t.added;
			}
			return l.services.length ? '' : t.noService;
		};
		var say = function ( text, cls ) {
			result.textContent = text || '';
			result.className = 'lango7-result' + ( cls ? ' ' + cls : '' );
		};

		var options = function () {
			return Array.prototype.slice.call( list.querySelectorAll( '[role="option"]:not([aria-disabled="true"])' ) );
		};
		var highlight = function ( i ) {
			var all = options();
			all.forEach( function ( o ) {
				o.classList.remove( 'is-active' );
				o.setAttribute( 'aria-selected', 'false' );
			} );
			active = all.length && i > -1 ? Math.min( i, all.length - 1 ) : -1;
			if ( active > -1 ) {
				all[ active ].classList.add( 'is-active' );
				all[ active ].setAttribute( 'aria-selected', 'true' );
				search.setAttribute( 'aria-activedescendant', all[ active ].id );
				all[ active ].scrollIntoView( { block: 'nearest' } );
			} else {
				search.removeAttribute( 'aria-activedescendant' );
			}
		};

		var drawList = function () {
			var q = fold( search.value.trim() );
			list.textContent = '';
			var shown = 0;
			Object.keys( cfg.langs ).forEach( function ( code ) {
				var l = cfg.langs[ code ];
				if ( q && fold( l.name + ' ' + l.native + ' ' + code + ' ' + l.prefix ).indexOf( q ) === -1 ) {
					return;
				}
				var why = blocked( code );
				var li = el( 'li', null, { role: 'option', id: 'lango7-opt-' + code, 'data-code': code, 'aria-selected': 'false' } );
				if ( why ) {
					li.setAttribute( 'aria-disabled', 'true' );
				}
				li.appendChild( el( 'span', l.name, { 'class': 'lango7-opt-name' } ) );
				if ( l.native !== l.name ) {
					li.appendChild( el( 'span', l.native, { 'class': 'lango7-opt-native', lang: code } ) );
				}
				if ( why ) {
					li.appendChild( el( 'span', why, { 'class': 'lango7-opt-tag' } ) );
				}
				list.appendChild( li );
				shown++;
			} );
			none.hidden = shown > 0;
			none.textContent = shown ? '' : t.noMatch.replace( '%s', search.value.trim() );
			popular.hidden = q !== '';
			say( '' );
			highlight( q ? 0 : -1 );
		};

		var drawPopular = function () {
			popular.textContent = '';
			popular.appendChild( el( 'span', t.popular ) );
			cfg.popular.forEach( function ( code ) {
				if ( ! cfg.langs[ code ] || blocked( code ) ) {
					return;
				}
				popular.appendChild( el( 'button', cfg.langs[ code ].name, { type: 'button', 'class': 'button', 'data-code': code } ) );
			} );
			if ( popular.children.length < 2 ) {
				popular.textContent = '';
			}
		};

		var step = function ( name ) {
			dialog.querySelectorAll( 'section[data-step]' ).forEach( function ( s ) {
				s.hidden = s.getAttribute( 'data-step' ) !== name;
			} );
			dialog.querySelectorAll( '.lango7-lang-steps li' ).forEach( function ( li ) {
				var on = li.getAttribute( 'data-for' ) === name;
				li.className = on ? 'is-current' : ( name === 'setup' ? 'is-done' : '' );
				on ? li.setAttribute( 'aria-current', 'step' ) : li.removeAttribute( 'aria-current' );
			} );
			confirmButton.hidden = name !== 'setup';
			say( '' );
		};

		var pick = function ( code ) {
			var l = cfg.langs[ code ];
			if ( ! l || blocked( code ) ) {
				return;
			}
			picked = code;
			document.getElementById( 'lango7-lang-picked-name' ).textContent = l.name;
			var native = document.getElementById( 'lango7-lang-picked-native' );
			native.textContent = l.native !== l.name ? l.native : '';
			native.setAttribute( 'lang', code );
			document.getElementById( 'lango7-lang-picked-code' ).textContent = l.prefix.toUpperCase();
			prefixField.value = l.prefix;
			if ( engineField ) {
				engineField.value = '';
				limitEngines( engineField, code, false );
			}
			var note = document.getElementById( 'lango7-lang-replace' );
			var current = rows()[ 0 ];
			note.hidden = ! ( replace && current );
			if ( replace && current ) {
				var was = cfg.langs[ field( current, 'code' ).value ];
				note.textContent = format( t.swapNote, was ? was.name : field( current, 'code' ).value, l.name );
			}
			confirmButton.textContent = ( replace ? t.swapThis : t.addThis ).replace( '%s', l.name );
			confirmButton.disabled = false;
			step( 'setup' );
			prefixField.focus();
		};

		var open = function () {
			dialog.querySelector( 'h2' ).textContent = replace ? t.swapTitle : t.addTitle;
			search.value = '';
			picked = '';
			drawPopular();
			drawList();
			step( 'pick' );
			dialog.showModal();
			search.focus();
		};

		addLang.addEventListener( 'click', open );
		engineField && engineField.addEventListener( 'change', function () {
			limitEngines( engineField, picked, false );
		} );
		search.addEventListener( 'input', drawList );
		search.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				highlight( Math.max( 0, active + ( e.key === 'ArrowDown' ? 1 : -1 ) ) );
			} else if ( e.key === 'Enter' ) {
				e.preventDefault();
				var all = options();
				if ( all[ active ] ) {
					pick( all[ active ].getAttribute( 'data-code' ) );
				}
			}
		} );
		list.addEventListener( 'click', function ( e ) {
			var o = e.target.closest( '[role="option"]' );
			if ( ! o ) {
				return;
			}
			var code = o.getAttribute( 'data-code' );
			if ( o.getAttribute( 'aria-disabled' ) !== 'true' ) {
				pick( code );
			} else if ( blocked( code ) === t.noService ) {
				say( t.none.replace( '%s', cfg.langs[ code ].name ), 'is-error' ); // Why it can't be picked.
			}
		} );
		popular.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button[data-code]' );
			b && pick( b.getAttribute( 'data-code' ) );
		} );
		dialog.querySelector( '[data-lango7-back]' ).addEventListener( 'click', function () {
			step( 'pick' );
			search.focus();
		} );
		dialog.querySelectorAll( '[data-lango7-close]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				dialog.close();
				addLang.focus();
			} );
		} );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				dialog.close();
			}
		} );
		prefixField.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				confirmButton.click();
			}
		} );

		confirmButton.addEventListener( 'click', function () {
			prefixField.value = prefixField.value.trim().toLowerCase();
			if ( ! /^[a-z0-9-]{1,20}$/.test( prefixField.value ) ) {
				say( t.badPrefix, 'is-error' );
				prefixField.focus();
				return;
			}
			var data;
			try {
				data = payload();
			} catch ( err ) {
				say( err.message, 'is-error' );
				return;
			}
			var row = entry( fresh, picked );
			data.languages = replace ? [ row ] : data.languages.concat( [ row ] );
			busy( confirmButton, true );
			say( t.adding );
			api( 'POST', '/settings', data )
				.then( function () {
					window.location.href = cfg.page + '&lango7_added=' + encodeURIComponent( picked );
				} )
				.catch( function ( err ) {
					say( err.message, 'is-error' );
					busy( confirmButton, false );
				} );
		} );
	}

	// Every named field is sent, plus one entry in "languages" per row.
	function payload() {
		var data = {};
		new FormData( form ).forEach( function ( value, name ) {
			data[ name ] = value;
		} );
		data.languages = [];
		rows().forEach( function ( row ) {
			data.languages.push( entry( row, field( row, 'code' ).value ) );
		} );
		var seen = {};
		data.languages.forEach( function ( l ) {
			if ( seen[ l.code ] ) {
				throw new Error( t.dupLang );
			}
			seen[ l.code ] = true;
		} );
		return data;
	}

	var saveStatus = form.querySelector( '.lango7-save-status' );

	function stamp( text, cls ) {
		if ( saveStatus ) {
			saveStatus.className = 'lango7-save-status' + ( cls ? ' ' + cls : '' );
			saveStatus.textContent = text;
		}
	}

	// After a save: each service box shows its saved key (masked) and whether it is ready.
	function refreshKeys( keys ) {
		Object.keys( keys || {} ).forEach( function ( id ) {
			var box = form.querySelector( '[data-lango7-engine="' + id + '"]' );
			if ( ! box ) {
				return;
			}
			var k = keys[ id ];
			var badge = box.querySelector( '[data-lango7-badge]' );
			if ( badge ) {
				badge.classList.toggle( 'is-ok', !! k.ready );
				badge.textContent = k.ready ? badge.getAttribute( 'data-on' ) : badge.getAttribute( 'data-off' );
			}
			var input = box.querySelector( 'input[type=password]' );
			if ( input && k.mask ) {
				input.placeholder = k.mask;
			}
		} );
	}

	// Saves the whole form. Resolves with the saved settings, rejects with an Error.
	function save() {
		var data = payload();
		return api( 'POST', '/settings', data ).then( function ( data ) {
			// Secrets are write-only: clear them once saved.
			form.querySelectorAll( 'input[type=password]' ).forEach( function ( input ) {
				input.value = '';
			} );
			form.querySelectorAll( 'input[type=checkbox][hidden]' ).forEach( function ( input ) {
				input.checked = false;
			} );
			var warm = document.getElementById( 'lango7-warm' );
			if ( warm ) {
				warm.disabled = ! data.languages.length;
			}
			refreshKeys( data.keys );
			document.dispatchEvent( new CustomEvent( 'lango7:saved', { detail: data } ) );
			stamp( t.saved + ' ' + new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ), 'is-ok' );
			api( 'GET', '/status' ).then( renderStatus ).catch( function () {} );
			return data;
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var button = form.querySelector( '[type=submit]' );
		busy( button, true );
		stamp( '' );
		var run;
		try {
			run = save();
		} catch ( err ) {
			run = Promise.reject( err );
		}
		run.then( function () {
			notice( t.saved );
		} ).catch( function ( err ) {
			stamp( err.message, 'is-error' );
			notice( err.message, 'error' );
		} ).then( function () {
			busy( button, false );
		} );
	} );

	// Unsaved fields of a service box (model, base URL), sent with a key test.
	function boxFields( box ) {
		var fields = {};
		if ( box ) {
			box.querySelectorAll( '[name]' ).forEach( function ( input ) {
				if ( input.type !== 'password' && ( input.type !== 'checkbox' || input.checked ) ) {
					fields[ input.name ] = input.value;
				}
			} );
		}
		return fields;
	}

	// "Test and save": test the typed (or saved) key; only a key that works is saved.
	form.querySelectorAll( '[data-lango7-save-key]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var box = button.closest( '[data-lango7-engine]' );
			var out = box.querySelector( '.lango7-result' );
			var input = form.elements[ button.getAttribute( 'data-key-field' ) ];
			var key = input ? input.value.trim() : '';
			out.className = 'lango7-result';
			out.textContent = button.getAttribute( 'data-saving' ) || t.testing;
			busy( button, true );
			api( 'POST', '/engines/' + encodeURIComponent( button.getAttribute( 'data-lango7-save-key' ) ) + '/test', { key: key, target: firstCode(), fields: boxFields( box ) } )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( r.message );
					}
					var sample = '“Hello” → “' + r.sample + '”';
					return save().then( function () {
						out.classList.add( 'is-ok' );
						out.textContent = '✓ ' + t.saved + ' ' + sample;
					} );
				} )
				.catch( function ( err ) {
					out.classList.add( 'is-error' );
					out.textContent = err.message + ( key ? ' ' + t.notSaved : '' );
				} )
				.then( function () {
					busy( button, false );
				} );
		} );
	} );

	// "Remove key": tick the hidden box and save.
	form.querySelectorAll( '[data-lango7-remove-key]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var box = form.elements[ button.getAttribute( 'data-lango7-remove-key' ) ];
			var out = button.closest( '[data-lango7-engine]' ).querySelector( '.lango7-result' );
			if ( ! box || ! window.confirm( t.removeKey ) ) {
				return;
			}
			box.checked = true;
			save().then( function () {
				out.className = 'lango7-result is-ok';
				out.textContent = t.keyRemoved;
				button.hidden = true;
				var input = button.closest( '[data-lango7-engine]' ).querySelector( 'input[type=password]' );
				if ( input ) {
					input.placeholder = '';
				}
			} ).catch( function ( err ) {
				box.checked = false;
				out.className = 'lango7-result is-error';
				out.textContent = err.message;
			} );
		} );
	} );

	// Engine test buttons: <button data-lango7-test="engine-id" data-key-field="name">.
	form.querySelectorAll( '[data-lango7-test]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var out = button.parentNode.querySelector( '.lango7-result' );
			var field = button.getAttribute( 'data-key-field' );
			var key = field && form.elements[ field ] ? form.elements[ field ].value : '';
			out.className = 'lango7-result';
			out.textContent = t.testing;
			busy( button, true );
			// Unsaved fields of the engine's box (model, base URL) are tested too.
			var fields = {};
			var box = button.closest( '[data-lango7-engine]' );
			if ( box ) {
				box.querySelectorAll( '[name]' ).forEach( function ( input ) {
					if ( input.type !== 'password' && ( input.type !== 'checkbox' || input.checked ) ) {
						fields[ input.name ] = input.value;
					}
				} );
			}
			api( 'POST', '/engines/' + encodeURIComponent( button.getAttribute( 'data-lango7-test' ) ) + '/test', { key: key, target: firstCode(), fields: fields } )
				.then( function ( r ) {
					out.classList.add( r.ok ? 'is-ok' : 'is-error' );
					out.textContent = r.ok ? '“Hello” → “' + r.sample + '”' : r.message;
				} )
				.catch( function ( err ) {
					out.classList.add( 'is-error' );
					out.textContent = err.message;
				} )
				.then( function () {
					busy( button, false );
				} );
		} );
	} );

	var dismiss = document.getElementById( 'lango7-pro-dismiss' );
	if ( dismiss ) {
		dismiss.addEventListener( 'click', function () {
			document.getElementById( 'lango7-pro' ).remove();
			api( 'POST', '/pro-card/dismiss', {} ).catch( function () {} );
		} );
	}

}() );
