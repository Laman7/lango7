/* Translate → Strings: list, search, edit in place and retranslate (lango7/v1/strings, /translations). */
( function () {
	'use strict';

	var cfg = window.lango7Strings;
	var lib = window.lango7Admin;
	var core = window.lango7EditorCore;
	var body = document.getElementById( 'lango7-str-rows' );
	if ( ! cfg || ! lib || ! core || ! body ) {
		return;
	}
	var t = cfg.i18n;
	var api = lib.client( cfg.rest, cfg.nonce, t.error );
	var el = lib.el;
	var $ = function ( id ) {
		return document.getElementById( 'lango7-str-' + id );
	};
	var langInput = $( 'lang' );
	var search = $( 'q' );
	var result = $( 'result' );
	var all = $( 'all' );
	var redo = $( 'redo' );
	var approveBtn = $( 'approve' );

	var state = { status: '', page: 1, pages: 1, q: '', engine: '' };
	var rows = [];
	var selected = {};
	var request = 0;

	function say( content, cls ) {
		result.textContent = '';
		result.className = 'lango7-result' + ( cls ? ' ' + cls : '' );
		( Array.isArray( content ) ? content : [ content ] ).forEach( function ( c ) {
			result.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
	}

	function plural( forms, n ) {
		return lib.format.apply( null, [ n === 1 ? forms.singular : forms.plural, n ].concat( Array.prototype.slice.call( arguments, 2 ) ) );
	}

	function resetNotice( n ) {
		if ( cfg.server ) {
			return say( plural( t.resetServer, n ), 'is-ok' );
		}
		say( [ plural( t.resetChrome, n ) + ' ', el( 'a', { href: cfg.chromeUrl, text: t.openChrome } ) ], 'is-ok' );
	}

	// Placeholders are shown as locked chips: [1 … 1] around linked or
	// formatted words, ◆ for an element, # for a number or glossary term.
	function chipText( part ) {
		if ( /^[ng]/.test( part.id ) ) {
			return '#';
		}
		return 'open' === part.type ? '[' + part.id : 'close' === part.type ? part.id + ']' : '◆' + part.id;
	}

	function chip( part ) {
		var number = /^[ng]/.test( part.id );
		var text = chipText( part );
		return el( 'span', {
			class: 'lango7-chip' + ( number || 'void' === part.type ? ' is-void' : '' ),
			contenteditable: 'false',
			'data-token': part.token,
			title: number ? t.chipNumber : ( 'void' === part.type ? t.chipVoid : t.chipPair ),
			text: text
		} );
	}

	function fill( box, text ) {
		box.textContent = '';
		core.parse( text ).forEach( function ( p ) {
			box.appendChild( 'text' === p.type ? document.createTextNode( p.value ) : chip( p ) );
		} );
	}

	function read( box ) {
		var parts = [];
		( function walk( node ) {
			node.childNodes.forEach( function ( n ) {
				if ( n.nodeType === 3 ) {
					parts.push( { type: 'text', value: n.nodeValue } );
				} else if ( n.nodeType === 1 && n.getAttribute( 'data-token' ) ) {
					parts.push( { type: 'chip', token: n.getAttribute( 'data-token' ) } );
				} else if ( n.nodeType === 1 ) {
					if ( n.nodeName === 'BR' || n.nodeName === 'DIV' ) {
						parts.push( { type: 'text', value: ' ' } );
					}
					walk( n );
				}
			} );
		}( box ) );
		return core.serialize( parts );
	}

	function statusLabel( r ) {
		var label = t[ r.status ] || r.status;
		return 'machine' === r.status && 'chrome' === r.engine ? label + ' · ' + t.chrome : label;
	}

	function kindLabel( kind ) {
		return { attr: t.kindAttr, meta: t.kindMeta, title: t.kindTitle }[ kind ] || '';
	}

	function edit( r, cell ) {
		var box = el( 'div', { class: 'lango7-str-edit', contenteditable: 'true', spellcheck: 'true', role: 'textbox', 'aria-multiline': 'false' } );
		var msg = el( 'p', { class: 'lango7-str-msg' } );
		var save = el( 'button', { type: 'button', class: 'button button-primary button-small', text: t.save } );
		var cancel = el( 'button', { type: 'button', class: 'button button-small', text: t.cancel } );
		fill( box, null === r.text ? r.source : r.text );
		cell.textContent = '';
		cell.appendChild( box );
		cell.appendChild( msg );
		cell.appendChild( el( 'p', { class: 'lango7-str-buttons' }, [ save, cancel ] ) );
		box.focus();

		function submit() {
			var text = read( box );
			var empty = text === '';
		if ( ! empty ) {
				if ( ! core.plain( text, {} ) && ! /<\d+\/>/.test( text ) ) {
					msg.textContent = t.emptyText;
					return;
				}
				var diff = core.check( r.source, text );
				if ( diff.missing.length || diff.extra.length ) {
					msg.textContent = lib.format( t.missing, diff.missing.concat( diff.extra ).map( function ( token ) {
						return chipText( core.parse( token )[ 0 ] );
					} ).join( ' ' ) );
					return;
				}
			}
			save.disabled = true;
			api( 'POST', '/translations', { hash: r.hash, lang: langInput.value, text: text, allow_empty: empty } ).then( function ( d ) {
				r.text = d.text;
				r.status = 'edited';
				r.engine = null;
				if ( ! empty ) { say( t.savedEdit, 'is-ok' ); }
				draw();
				load( true );
			} ).catch( function ( e ) {
				save.disabled = false;
				msg.textContent = e.message;
			} );
		}
		save.addEventListener( 'click', submit );
		cancel.addEventListener( 'click', draw );
		box.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				draw();
			} else if ( e.key === 'Enter' ) {
				e.preventDefault(); // One line: breaks are part of the page markup.
				if ( e.metaKey || e.ctrlKey ) {
					submit();
				}
			}
		} );
		box.addEventListener( 'paste', function ( e ) {
			e.preventDefault();
			var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' ).replace( /\s+/g, ' ' );
			document.execCommand( 'insertText', false, text );
		} );
	}

	function emptyTranslation( r, button ) {
		button.disabled = true;
		api( 'POST', '/translations', { hash: r.hash, lang: langInput.value, text: '', allow_empty: true } ).then( function () {
			r.text = '';
			r.status = 'edited';
			r.engine = null;
			draw();
			load( true );
		} ).catch( function ( e ) {
			button.disabled = false;
			say( e.message, 'is-error' );
		} );
	}

	function row( r ) {
		var cell = el( 'td', { class: 'lango7-str-text' } );
		var shown = el( 'div', { class: null === r.text ? 'lango7-muted' : '' } );
		if ( null === r.text ) {
			shown.textContent = t.untranslated;
		} else if ( '' === r.text && 'edited' === r.status ) {
			shown.className = 'lango7-muted';
			shown.textContent = t.emptySaved;
		} else {
			fill( shown, r.text );
		}
		var actions = [ el( 'button', { type: 'button', class: 'button-link', text: t.edit, onclick: function () {
			edit( r, cell );
		} } ) ];
		if ( r.edit ) {
			actions.push( el( 'a', { href: r.edit, target: '_blank', rel: 'noopener', text: t.onPage } ) );
		}
		if ( !( 'edited' === r.status && '' === r.text ) ) {
			actions.push( el( 'button', { type: 'button', class: 'button-link', text: t.empty, onclick: function ( e ) {
				emptyTranslation( r, e.currentTarget );
			} } ) );
		}
		// Add-ons add their own row actions (and may draw into cell), e.g. Pro's History.
		document.dispatchEvent( new CustomEvent( 'lango7:string-row', { detail: {
			row: r,
			lang: langInput.value,
			actions: actions,
			cell: cell,
			reload: load,
			say: say
		} } ) );
		cell.appendChild( shown );
		cell.appendChild( el( 'div', { class: 'row-actions visible' }, actions ) );

		var src = el( 'div' );
		fill( src, r.source );
		var kind = kindLabel( r.kind );
		var box = el( 'input', { type: 'checkbox', checked: selected[ r.hash ] ? true : null, onchange: function ( e ) {
			if ( e.target.checked ) {
				selected[ r.hash ] = true;
			} else {
				delete selected[ r.hash ];
			}
			sync();
		} } );
		return el( 'tr', { class: 'is-' + r.status }, [
			el( 'th', { scope: 'row', class: 'check-column' }, [ box ] ),
			el( 'td', { class: 'lango7-str-src' }, [ src, kind ? el( 'span', { class: 'lango7-str-kind', text: kind } ) : null ] ),
			cell,
			el( 'td', { class: 'lango7-str-engine' }, [ engineButton( r ) ] ),
			el( 'td', { class: 'lango7-str-action' }, [ translateAgainButton( r ) ] ),
			el( 'td', { class: 'lango7-str-status' }, [ el( 'span', { class: 'lango7-badge is-' + r.status, text: statusLabel( r ) } ) ] )
		] );
	}

	function translateAgainButton( r ) {
		var server = ( cfg.engines || {} )[ langInput.value ];
		var button = el( 'button', { type: 'button', class: 'button button-small', text: t.translateAgain } );
		button.addEventListener( 'click', async function () {
			var lang = langInput.value;
			button.disabled = true;
			button.textContent = t.preparingChrome;
			if ( server ) {
				button.textContent = t.translating;
				api( 'POST', '/strings/translate', { lang: lang, hashes: [ r.hash ] } ).then( function ( d ) {
					if ( ! d.translated ) { say( t.engineFailed, 'is-error' ); }
					else { say( t.chromeDone, 'is-ok' ); }
					load();
				} ).catch( function ( e ) {
					say( e.message, 'is-error' );
					button.disabled = false;
					button.textContent = t.translateAgain;
				} );
					return;
			}
			var translator;
			try {
				var brands = navigator.userAgentData && navigator.userAgentData.brands;
				var chrome = brands ? brands.some( function ( b ) { return b.brand === 'Google Chrome'; } ) :
					/Chrome\/\d+/.test( navigator.userAgent ) && ! /Edg\/|OPR\/|SamsungBrowser\/|CriOS\/|HeadlessChrome\//.test( navigator.userAgent );
				if ( ! chrome ) { throw new Error( t.chromeOnly ); }
				if ( ! window.isSecureContext ) { throw new Error( t.chromeSecure ); }
				if ( ! ( 'Translator' in self ) ) { throw new Error( t.chromeMissing ); }
				var source = cfg.chromeSource;
				var target = ( cfg.chromeTargets || {} )[ lang ];
				if ( ! source || ! target ) { throw new Error( t.chromeMissing ); }
				// Creation stays in the click event so Chrome can download its model.
				translator = await self.Translator.create( {
					sourceLanguage: source,
					targetLanguage: target,
					monitor: function ( monitor ) {
						monitor.addEventListener( 'downloadprogress', function ( event ) {
							button.textContent = lib.format( t.downloadingChrome, Math.round( event.loaded * 100 ) );
						} );
					}
				} );
				button.textContent = t.translating;
				var pending = await api( 'GET', '/chrome/pending?lang=' + encodeURIComponent( lang ) + '&hash=' + encodeURIComponent( r.hash ) + '&replace_edit=1&limit=1' );
				if ( ! pending.items || ! pending.items.length ) { throw new Error( t.chromeFailed ); }
				var translated = await window.lango7ChromeCore.translateSegment( pending.items[ 0 ].text, function ( text ) {
					return translator.translate( text );
				} );
				var saved = await api( 'POST', '/chrome/translations', { lang: lang, items: [ { hash: r.hash, text: translated.text } ], replace_edit: true } );
				if ( saved.saved !== 1 ) { throw new Error( t.chromeFailed ); }
				say( t.chromeDone, 'is-ok' );
				load();
			} catch ( e ) {
				say( e.message || t.chromeFailed, 'is-error' );
			} finally {
				if ( translator && translator.destroy ) { translator.destroy(); }
				button.disabled = false;
				button.textContent = t.translateAgain;
			}
		} );
		return button;
	}

	function engineButton( r ) {
		var label = ( cfg.engines || {} )[ langInput.value ];
		if ( ! label ) {
			return el( 'span', { class: 'description', text: t.noEngine } );
		}
		return el( 'span', { text: label } );
	}

	function sync() {
		var n = Object.keys( selected ).length;
		redo.disabled = ! n;
		approveBtn.hidden = 'unapproved' !== state.status;
		approveBtn.disabled = ! n;
		all.checked = rows.length > 0 && rows.every( function ( r ) {
			return selected[ r.hash ];
		} );
	}

	// Approval is set per language: say which ones need it when this one does not.
	function approvalNote() {
		var codes = cfg.approval || [];
		if ( codes.indexOf( langInput.value ) >= 0 ) {
			return t.noApprovals;
		}
		var names = ( cfg.languages || [] ).filter( function ( l ) {
			return codes.indexOf( l.code ) >= 0;
		} ).map( function ( l ) {
			return l.name;
		} );
		return t.notApproval.replace( '%s', names.join( ', ' ) );
	}

	function draw() {
		body.textContent = '';
		if ( ! rows.length ) {
			body.appendChild( el( 'tr', {}, [ el( 'td', { colspan: 6, text: 'unapproved' === state.status ? approvalNote() : t.none } ) ] ) );
		}
		rows.forEach( function ( r ) {
			body.appendChild( row( r ) );
		} );
		sync();
	}

	function counts( c ) {
		document.querySelectorAll( '#lango7-str-tabs [data-status]' ).forEach( function ( a ) {
			var n = c[ a.getAttribute( 'data-status' ) ] || 0;
			a.querySelector( '.count' ).textContent = '(' + n + ')';
			if ( 'unapproved' === a.getAttribute( 'data-status' ) ) {
				// Shown for languages that require approval (Lango7 Pro), and while anything still waits.
				a.parentNode.hidden = ! n && ! ( cfg.approval || [] ).length;
			}
		} );
	}

	function orphans( n ) {
		var box = $( 'orphans' );
		box.hidden = ! n;
		box.textContent = '';
		if ( ! n ) {
			return;
		}
		box.appendChild( document.createTextNode( plural( t.orphans, n, cfg.orphanDays ) + ' ' ) );
		box.appendChild( el( 'button', { type: 'button', class: 'button-link lango7-link-delete', text: t.deleteOrphans, onclick: function ( e ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( t.confirmOrphans ) ) {
				return;
			}
			e.currentTarget.disabled = true;
			api( 'DELETE', '/strings/orphans' ).then( function ( d ) {
				say( plural( t.orphansGone, d.deleted ), 'is-ok' );
				load();
			} ).catch( function ( err ) {
				say( err.message, 'is-error' );
			} );
		} } ) );
	}

	// The services that translated this language; keeps the current choice.
	var engineSelect = document.getElementById( 'lango7-str-engine' );
	function engineOptions( list ) {
		if ( ! engineSelect ) {
			return;
		}
		Array.prototype.slice.call( engineSelect.options, 2 ).forEach( function ( o ) {
			engineSelect.removeChild( o );
		} );
		list.forEach( function ( e ) {
			engineSelect.appendChild( el( 'option', { value: e.id, text: e.label } ) );
		} );
		engineSelect.value = state.engine;
		if ( engineSelect.value !== state.engine ) {
			// That service never translated this language: show everything.
			engineSelect.value = '';
			state.engine = '';
			load();
		}
	}
	if ( engineSelect ) {
		engineSelect.addEventListener( 'change', function () {
			state.engine = engineSelect.value;
			state.page = 1;
			selected = {};
			load();
		} );
	}

	// quiet: refresh counts only, keeping the rows (and any open editor) as they are.
	function load( quiet ) {
		var mine = ++request;
		var query = '?lang=' + encodeURIComponent( langInput.value ) + '&status=' + encodeURIComponent( state.status ) +
			'&page=' + state.page + '&q=' + encodeURIComponent( state.q ) + '&engine=' + encodeURIComponent( state.engine );
		if ( ! quiet ) {
			$( 'total' ).textContent = t.loading;
		}
		return api( 'GET', '/strings' + query ).then( function ( d ) {
			if ( mine !== request ) {
				return;
			}
			counts( d.counts );
			engineOptions( d.engines || [] );
			orphans( d.orphans );
			state.pages = d.pages;
			$( 'total' ).textContent = plural( t.items, d.total );
			$( 'page' ).textContent = lib.format( t.pageOf, d.page, d.pages );
			$( 'prev' ).disabled = d.page <= 1;
			$( 'next' ).disabled = d.page >= d.pages;
			if ( ! quiet ) {
				rows = d.rows;
				draw();
			}
		} ).catch( function ( e ) {
			say( e.message, 'is-error' );
		} );
	}

	document.querySelectorAll( '#lango7-str-tabs [data-status]' ).forEach( function ( a ) {
		a.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			document.querySelectorAll( '#lango7-str-tabs a' ).forEach( function ( x ) {
				x.classList.remove( 'current' );
				x.removeAttribute( 'aria-current' );
			} );
			a.classList.add( 'current' );
			a.setAttribute( 'aria-current', 'page' );
			state.status = a.getAttribute( 'data-status' );
			state.page = 1;
			selected = {};
			load();
		} );
	} );
	var timer;
	search.addEventListener( 'input', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			state.q = search.value.trim();
			state.page = 1;
			load();
		}, 300 );
	} );
	document.querySelectorAll( '[data-lango7-language]' ).forEach( function (button) {
		button.addEventListener( 'click', function () {
			langInput.value = button.getAttribute( 'data-lango7-language' );
			document.querySelectorAll( '[data-lango7-language]' ).forEach( function (item) { item.setAttribute( 'aria-pressed', item === button ? 'true' : 'false' ); } );
			langInput.dispatchEvent( new Event( 'change' ) );
		} );
	} );
	langInput.addEventListener( 'change', function () {
		state.page = 1;
		selected = {};
		load();
	} );
	$( 'prev' ).addEventListener( 'click', function () {
		state.page = Math.max( 1, state.page - 1 );
		load();
	} );
	$( 'next' ).addEventListener( 'click', function () {
		state.page = Math.min( state.pages, state.page + 1 );
		load();
	} );
	all.addEventListener( 'change', function () {
		rows.forEach( function ( r ) {
			if ( all.checked ) {
				selected[ r.hash ] = true;
			} else {
				delete selected[ r.hash ];
			}
		} );
		draw();
	} );
	redo.addEventListener( 'click', function () {
		redo.disabled = true;
		api( 'POST', '/strings/retranslate', { lang: langInput.value, hashes: Object.keys( selected ) } ).then( function ( d ) {
			selected = {};
			if ( d.reset ) {
				resetNotice( d.reset );
			} else {
				say( t.resetNone );
			}
			load();
		} ).catch( function ( e ) {
			say( e.message, 'is-error' );
			sync();
		} );
	} );

	// Links such as the approval email open a tab directly (?status=unapproved).
	var startTab = document.querySelector( '#lango7-str-tabs [data-status="' + ( new URLSearchParams( window.location.search ).get( 'status' ) || '' ).replace( /[^a-z]/g, '' ) + '"]' );
	if ( startTab && startTab.getAttribute( 'data-status' ) ) {
		startTab.parentNode.hidden = false;
		document.querySelectorAll( '#lango7-str-tabs a' ).forEach( function ( x ) {
			x.classList.remove( 'current' );
			x.removeAttribute( 'aria-current' );
		} );
		startTab.classList.add( 'current' );
		startTab.setAttribute( 'aria-current', 'page' );
		state.status = startTab.getAttribute( 'data-status' );
		var needs = cfg.approval || [];
		if ( 'unapproved' === state.status && needs.length && needs.indexOf( langInput.value ) < 0 ) {
			langInput.value = needs[ 0 ];
			document.querySelectorAll( '[data-lango7-language]' ).forEach( function ( item ) {
				item.setAttribute( 'aria-pressed', item.getAttribute( 'data-lango7-language' ) === needs[ 0 ] ? 'true' : 'false' );
			} );
		}
	}

	approveBtn.addEventListener( 'click', function () {
		approveBtn.disabled = true;
		api( 'POST', '/approve', { lang: langInput.value, hashes: Object.keys( selected ) } ).then( function ( d ) {
			selected = {};
			say( d.message );
			load();
		} ).catch( function ( e ) {
			say( e.message, 'is-error' );
			sync();
		} );
	} );

	load();
}() );
