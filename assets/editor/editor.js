/*
 * Lango7 front-end editor (edit mode only). Hover outlines a segment, click
 * opens a popover to edit it; links, formatting and numbers are locked chips.
 * The UI lives in a shadow root so the theme's CSS and the editor's cannot
 * touch each other, and the hover outline is an overlay, so the page's layout
 * is never changed.
 */
( function ( start ) {
	// The page data is printed after the footer scripts, at the end of <body>.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( function () {
	'use strict';

	var core = window.lango7EditorCore;
	var dataEl = document.getElementById( 'lango7-editor-data' );
	if ( ! core || ! dataEl ) {
		return;
	}
	var data = JSON.parse( dataEl.textContent );
	var t = data.i18n;
	var segments = data.segments;
	var STATUS = { machine: 'machine', edited: 'edited', ai_edited: 'edited', needs_review: 'review', unapproved: 'unapproved', failed: 'failed', pending: 'pending' };

	// UI host --------------------------------------------------------------

	var host = document.createElement( 'lango7-editor' );
	host.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;z-index:2147483000;';
	var root = host.attachShadow( { mode: 'open' } );
	root.innerHTML = '<link rel="stylesheet" href="' + data.css + '">' +
		'<div class="outline" hidden></div>' +
		'<div class="bar" role="toolbar">' +
			'<strong></strong><span class="count"></span><span class="alt"></span>' +
			'<button type="button" class="strings-btn"></button>' +
			'<a class="exit"></a>' +
		'</div>' +
		'<aside class="panel" hidden><input type="search"><ul></ul></aside>' +
		'<div class="pop" role="dialog" hidden>' +
			'<div class="head"><span class="badge"></span><span class="kind"></span></div>' +
			'<div class="label src-label"></div><div class="src"></div>' +
			'<div class="review" hidden><div class="label"></div><div class="old"></div><button type="button" class="reuse"></button></div>' +
			'<div class="edit-head"><div class="label tr-label"></div><div class="fmt" role="toolbar"><button type="button" class="f-b"><b>B</b></button><button type="button" class="f-i"><i>I</i></button><button type="button" class="f-br">↵</button><button type="button" class="f-help" aria-expanded="false">?</button></div></div>' +
			'<div class="marks-help" hidden></div>' +
			'<div class="edit" contenteditable="true" spellcheck="true"></div>' +
			'<div class="again" hidden><label><span></span> <select class="svc"></select></label><button type="button" class="now"></button></div>' +
			'<div class="rewrite" hidden><select class="tone"></select><input type="text" class="custom" maxlength="200" hidden><button type="button" class="ai"></button></div>' +
			'<label class="scope" hidden><span></span> <select><option value="all"></option><option value="page"></option></select></label>' +
			'<p class="msg" aria-live="polite"></p>' +
			'<div class="actions"><button type="button" class="save primary"></button><button type="button" class="keep"></button><button type="button" class="undo" hidden></button><button type="button" class="approve" hidden></button><button type="button" class="revert"></button><button type="button" class="cancel"></button></div>' +
			'<p class="hint"></p>' +
		'</div>' +
		'<div class="toast" hidden></div>';
	document.body.appendChild( host );

	function $( sel ) {
		return root.querySelector( sel );
	}
	var outline = $( '.outline' );
	var pop = $( '.pop' );
	var editBox = $( '.edit' );
	var msg = $( '.msg' );
	var panel = $( '.panel' );

	$( '.bar strong' ).textContent = t.editing.replace( '%s', data.langName );
	$( '.bar .count' ).textContent = t.count.replace( '%d', Object.keys( segments ).length );
	$( '.strings-btn' ).textContent = t.pageStrings;
	$( '.exit' ).textContent = t.exit;
	$( '.exit' ).href = data.exit;
	$( '.src-label' ).textContent = t.source;
	$( '.tr-label' ).textContent = t.translation;
	$( '.f-b' ).title = t.bold;
	$( '.f-i' ).title = t.italic;
	$( '.f-br' ).title = t.lineBreak;
	$( '.f-help' ).title = t.marksHelpTitle;
	$( '.marks-help' ).textContent = t.marksHelp;
	$( '.save' ).textContent = t.save;
	$( '.revert' ).textContent = t.revert;
	$( '.keep' ).textContent = t.keep;
	$( '.keep' ).title = t.keepTitle;
	$( '.approve' ).textContent = t.approve;
	$( '.cancel' ).textContent = t.cancel;
	$( '.undo' ).textContent = t.undo;
	$( '.undo' ).title = t.undoTitle;
	$( '.hint' ).textContent = t.hint;
	$( '.bar .alt' ).textContent = t.altShort;
	$( '.bar .alt' ).title = t.altHint;
	$( '.panel input' ).placeholder = t.search;

	// Extension slots (see EditMode::printData): extra string groups, an
	// "Applies to" choice, and earlier edits to reuse.
	var groups = data.groups || [];
	var grouped = {};
	groups.forEach( function ( g ) {
		g.hashes.forEach( function ( h ) {
			grouped[ h ] = g.label;
		} );
	} );
	var scope = data.scope || null;
	var review = data.review || {};
	if ( scope ) {
		$( '.scope span' ).textContent = scope.i18n.label;
		$( '.scope option[value=all]' ).textContent = scope.i18n.all;
		$( '.scope option[value=page]' ).textContent = scope.i18n.page;
		$( '.scope' ).hidden = false;
	}
	if ( data.reviewI18n ) {
		$( '.review .label' ).textContent = data.reviewI18n.title;
		$( '.review .reuse' ).textContent = data.reviewI18n.reuse;
	}

	// Translate now: the language's service first, then every other one that can do it.
	var services = ( data.services && data.services.list ) || [];
	var svc = $( '.again .svc' );
	if ( services.length ) {
		services.forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o.id;
			opt.textContent = o.id === data.services.default ? t.yours.replace( '%s', o.label ) : o.label;
			svc.appendChild( opt );
		} );
		svc.value = data.services.default;
		$( '.again span' ).textContent = t.service;
		$( '.again .now' ).textContent = t.translateNow;
		$( '.again' ).hidden = false;
	}
	function serviceLabel( id ) {
		for ( var i = 0; i < services.length; i++ ) {
			if ( services[ i ].id === id ) {
				return services[ i ].label;
			}
		}
		return id;
	}
	function costHint() {
		$( '.again .now' ).title = 'chrome' === svc.value ? '' : t.cost.replace( '%s', serviceLabel( svc.value ) );
	}
	svc.addEventListener( 'change', costHint );
	costHint();
	// What Translate now replaced, per string, so Revert can put it back.
	var undos = {};

	// Extensions mark their own dialogs with data-lango7-ui so their buttons still work.
	function isUi( e ) {
		return e.composedPath().indexOf( host ) !== -1 || !! ( e.target.closest && e.target.closest( '[data-lango7-ui]' ) );
	}

	// Segments on the page ---------------------------------------------------

	function elements( hash ) {
		return Array.prototype.slice.call( document.querySelectorAll( '[data-lango7="' + hash + '"]' ) ).filter( function ( el ) {
			return el.closest( 'body' ) && 'TITLE' !== el.tagName;
		} );
	}

	function occOf( el ) {
		return data.occ[ el.getAttribute( 'data-lango7-i' ) ] || {};
	}

	function anyOcc( hash ) {
		var el = elements( hash )[ 0 ];
		if ( el ) {
			return occOf( el );
		}
		for ( var i = 0; i < data.occ.length; i++ ) {
			if ( data.occ[ i ].h === hash ) {
				return data.occ[ i ];
			}
		}
		return {};
	}

	function rect( el ) {
		if ( 'LANGO7-SEG' === el.tagName ) {
			var range = document.createRange();
			range.selectNodeContents( el );
			return range.getBoundingClientRect();
		}
		return el.getBoundingClientRect();
	}

	function statusOf( hash ) {
		return STATUS[ segments[ hash ].st ] || 'machine';
	}

	function showOutline( el ) {
		var r = rect( el );
		outline.hidden = false;
		outline.className = 'outline is-' + statusOf( el.getAttribute( 'data-lango7' ) );
		outline.style.cssText = 'top:' + ( r.top - 3 ) + 'px;left:' + ( r.left - 3 ) + 'px;width:' + ( r.width + 6 ) + 'px;height:' + ( r.height + 6 ) + 'px';
	}

	document.addEventListener( 'mouseover', function ( e ) {
		if ( isUi( e ) || ! pop.hidden ) {
			return;
		}
		var el = e.target.closest && e.target.closest( '[data-lango7]' );
		if ( el ) {
			showOutline( el );
		} else {
			outline.hidden = true;
		}
	}, true );

	// A link that goes nowhere (slider arrows, tab handles) is a page control.
	function goesNowhere( a ) {
		var href = ( a.getAttribute( 'href' ) || '' ).trim();
		return '' === href || '#' === href.charAt( 0 ) || 0 === href.toLowerCase().indexOf( 'javascript:' );
	}

	// Clicking text edits it and nothing navigates or submits. The page's own
	// controls (slider arrows, dots, tabs, toggles) still work when they hold no
	// text to edit, and Alt+click always reaches the page.
	document.addEventListener( 'click', function ( e ) {
		if ( isUi( e ) || e.altKey || ! e.target.closest ) {
			return;
		}
		var el = e.target.closest( '[data-lango7]' );
		var link = e.target.closest( 'a[href]' );
		if ( ! el ) {
			if ( link && ! goesNowhere( link ) ) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		open( el.getAttribute( 'data-lango7' ), el );
	}, true );
	document.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();
	}, true );

	// Popover ------------------------------------------------------------------

	var current = null; // { hash, el, occ, original }

	function chip( part, occ, editable ) {
		var c = document.createElement( 'span' );
		c.className = 'chip chip-' + part.type;
		c.contentEditable = 'false';
		c.setAttribute( 'data-token', part.token );
		var name = core.label( part.id, occ );
		c.textContent = 'close' === part.type ? '/' + name : name;
		c.setAttribute( 'data-id', part.id );
		var locked = core.isProtected( part.id, occ );
		if ( locked ) {
			c.className += ' is-locked';
		}
		if ( editable ) {
			c.title = 'n' === part.id.charAt( 0 ) ? t.chipNumber : ( locked ? t.chipLocked.replace( '%s', name ) : ( 'void' === part.type ? t.chipBreak : t.chipStyle.replace( '%s', name ) ) );
		}
		return c;
	}

	function fill( box, text, occ, editable ) {
		box.textContent = '';
		core.parse( text ).forEach( function ( p ) {
			box.appendChild( 'text' === p.type ? document.createTextNode( p.value ) : chip( p, occ, editable ) );
		} );
	}

	// Read the editable box back into placeholder text.
	function read( box ) {
		var parts = [];
		( function walk( node ) {
			node.childNodes.forEach( function ( n ) {
				if ( 3 === n.nodeType ) {
					parts.push( { type: 'text', value: n.nodeValue } );
				} else if ( 1 === n.nodeType && n.hasAttribute( 'data-token' ) ) {
					parts.push( { type: 'chip', token: n.getAttribute( 'data-token' ) } );
				} else if ( 1 === n.nodeType && 'BR' === n.tagName ) {
					parts.push( { type: 'text', value: ' ' } );
				} else if ( 1 === n.nodeType ) {
					walk( n );
				}
			} );
		}( box ) );
		return core.serialize( parts );
	}

	function say( text, kind ) {
		msg.textContent = text || '';
		msg.className = 'msg' + ( kind ? ' is-' + kind : '' );
	}

	function position( el ) {
		var r = el ? rect( el ) : null;
		var w = Math.min( 460, window.innerWidth - 24 );
		pop.style.width = w + 'px';
		var left = r ? Math.min( Math.max( 12, r.left ), window.innerWidth - w - 12 ) : ( window.innerWidth - w ) / 2;
		var top = r ? r.bottom + 10 : 80;
		pop.style.left = left + 'px';
		pop.style.top = top + 'px';
		var h = pop.offsetHeight;
		if ( r && top + h > window.innerHeight - 12 && r.top - h - 10 > 12 ) {
			pop.style.top = ( r.top - h - 10 ) + 'px';
		}
	}

	function open( hash, el ) {
		var s = segments[ hash ];
		if ( ! s ) {
			return;
		}
		var occ = el ? occOf( el ) : anyOcc( hash );
		var text = null === s.t ? s.s : s.t;
		current = { hash: hash, el: el, occ: occ, original: text };
		badge( hash );
		$( '.kind' ).textContent = grouped[ hash ] || ( 'title' === s.k ? t.title : '' );
		fill( $( '.src' ), s.s, occ, false );
		fill( editBox, text, occ, true );
		if ( services.length ) {
			svc.value = data.services.default;
			costHint();
		}
		if ( scope ) {
			$( '.scope select' ).value = s.sc ? 'page' : 'all';
		}
		var old = review[ hash ];
		$( '.review' ).hidden = ! old;
		if ( old ) {
			fill( $( '.review .old' ), old.text, occ, false );
		}
		say( '' );
		pop.hidden = false;
		outline.hidden = true;
		if ( el ) {
			el.scrollIntoView( { block: 'nearest' } );
			showOutline( el );
		}
		position( el );
		editBox.focus();
		var sel = root.getSelection ? root.getSelection() : window.getSelection();
		if ( sel ) {
			sel.selectAllChildren( editBox );
			sel.collapseToEnd();
		}
	}

	// Status badge and the buttons that depend on it.
	function badge( hash ) {
		var st = statusOf( hash );
		$( '.badge' ).textContent = t[ st ] || st;
		$( '.badge' ).className = 'badge is-' + st;
		$( '.revert' ).hidden = 'edited' !== st || !! undos[ hash ];
		$( '.undo' ).hidden = ! undos[ hash ];
		$( '.approve' ).hidden = 'unapproved' !== st || ! data.canApprove;
	}

	// Approve one machine translation that waits for approval: visitors see it from now on.
	function approve() {
		var c = current;
		busy( true );
		api( '/approve', { lang: data.lang, hashes: [ c.hash ] } )
			.then( function ( r ) {
				apply( c.hash, segments[ c.hash ].t, 'machine' );
				close();
				toast( r.message );
			} )
			.catch( function ( err ) {
				say( err.message, 'error' );
			} )
			.then( function () {
				busy( false );
			} );
	}

	function close() {
		pop.hidden = true;
		outline.hidden = true;
		current = null;
	}

	function api( path, body, nonce ) {
		return fetch( /^https?:/.test( path ) ? path : data.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce || data.nonce },
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error( ( json && json.message ) || t.error );
				}
				return json;
			} );
		} );
	}

	// Show a new translation everywhere it appears on this page.
	function apply( hash, text, status ) {
		var s = segments[ hash ];
		s.t = text;
		s.st = status;
		var shown = null === text ? s.s : text;
		elements( hash ).forEach( function ( el ) {
			el.innerHTML = core.render( shown, occOf( el ) );
		} );
		if ( 'title' === s.k ) {
			document.title = core.plain( shown, anyOcc( hash ) );
		}
		renderPanel();
	}

	function toast( text ) {
		var box = $( '.toast' );
		box.textContent = text;
		box.hidden = false;
		clearTimeout( toast.timer );
		toast.timer = setTimeout( function () {
			box.hidden = true;
		}, 4000 );
	}

	function busy( on ) {
		root.querySelectorAll( '.actions button, .again button, .again select' ).forEach( function ( b ) {
			b.disabled = on;
		} );
	}

	function save() {
		if ( ! current ) {
			return Promise.resolve();
		}
		var c = current;
		var text = core.tidy( read( editBox ) );
		var where = scope ? $( '.scope select' ).value : 'all';
		var wasPage = !! segments[ c.hash ].sc;
		if ( text === c.original && ( ! scope || ( 'page' === where ) === wasPage ) ) {
			close();
			return Promise.resolve();
		}
		if ( ! core.plain( text, c.occ ) && ! /<\d+\/>/.test( text ) ) {
			say( t.empty, 'error' );
			return Promise.reject( new Error( t.empty ) );
		}
		var diff = core.check( segments[ c.hash ].s, text, c.occ );
		if ( diff.missing.length ) {
			say( t.missing.replace( '%s', diff.missing.map( function ( tok ) {
				return core.label( tok.replace( /[<>/]/g, '' ), c.occ );
			} ).join( ', ' ) ), 'error' );
			return Promise.reject( new Error( 'missing' ) );
		}
		if ( diff.extra.length || diff.order ) {
			say( diff.extra.length ? t.extra : t.order, 'error' );
			return Promise.reject( new Error( 'extra' ) );
		}
		busy( true );
		var request = scope ?
			api( scope.url, { hash: c.hash, lang: data.lang, text: text, path: data.path, scope: where }, scope.nonce ) :
			api( '/translations', { hash: c.hash, lang: data.lang, text: text } );
		return request
			.then( function ( r ) {
				if ( scope ) {
					segments[ c.hash ].sc = 'page' === where ? 1 : 0;
				}
				delete review[ c.hash ];
				delete undos[ c.hash ];
				apply( c.hash, r.text, r.status );
				close();
				toast( t.saved );
			} )
			.catch( function ( err ) {
				say( err.message, 'error' );
				throw err;
			} )
			.finally( function () {
				busy( false );
			} );
	}

	// Without a server engine, translate the original again here with Chrome.
	function chromeTranslate( source ) {
		var chrome = window.lango7ChromeCore;
		if ( ! chrome || ! window.isSecureContext || ! ( 'Translator' in self ) || ! data.chrome || ! data.source ) {
			return Promise.resolve( null );
		}
		return self.Translator.create( { sourceLanguage: data.source, targetLanguage: data.chrome } ).then( function ( tr ) {
			return chrome.translateSegment( source, function ( s ) {
				return tr.translate( s );
			} ).then( function ( res ) {
				return res.text;
			} );
		} ).catch( function () {
			return null;
		} );
	}

	function revert() {
		var c = current;
		busy( true );
		var created = chromeTranslate( segments[ c.hash ].s ); // Started inside the click.
		var pageEdit = scope && segments[ c.hash ].sc;
		var request = pageEdit ?
			api( scope.revert, { hash: c.hash, lang: data.lang, path: data.path }, scope.nonce ) :
			api( '/translations/revert', { hash: c.hash, lang: data.lang } );
		request
			.then( function ( r ) {
				if ( pageEdit ) {
					segments[ c.hash ].sc = 0;
					return r; // The site-wide translation shows again.
				}
				if ( null !== r.text ) {
					return r;
				}
				return created.then( function ( text ) {
					if ( null === text ) {
						return r;
					}
					return api( '/chrome/translations', { lang: data.lang, items: [ { hash: c.hash, text: text } ] } ).then( function ( s ) {
						return s.saved ? { text: text, status: 'machine' } : r;
					} );
				} );
			} )
			.then( function ( r ) {
				apply( c.hash, r.text, r.status );
				close();
				toast( null === r.text ? t.revertedSrc : t.reverted );
			} )
			.catch( function ( err ) {
				say( err.message, 'error' );
			} )
			.finally( function () {
				busy( false );
			} );
	}

	// Translate the original again with the chosen service; saved at once, and
	// the replaced version is kept for Revert.
	function translateNow() {
		if ( ! current ) {
			return;
		}
		var c = current;
		var service = svc.value;
		var body = { hash: c.hash, lang: data.lang, service: service, path: scope ? data.path : '' };
		busy( true );
		say( t.working );
		var text = 'chrome' === service ? chromeTranslate( segments[ c.hash ].s ) : Promise.resolve( null ); // Started inside the click.
		text
			.then( function ( got ) {
				if ( 'chrome' === service ) {
					if ( null === got ) {
						throw new Error( t.noChrome );
					}
					body.text = got;
				}
				return api( '/translations/now', body );
			} )
			.then( function ( r ) {
				undos[ c.hash ] = r.previous;
				segments[ c.hash ].sc = 0;
				delete review[ c.hash ];
				apply( c.hash, r.text, r.status );
				if ( current === c ) {
					c.original = r.text;
					fill( editBox, r.text, c.occ, true );
					if ( scope ) {
						$( '.scope select' ).value = 'all';
					}
					badge( c.hash );
					say( t.translated.replace( '%s', r.label || serviceLabel( service ) ), 'info' );
				}
			} )
			.catch( function ( err ) {
				say( err.message, 'error' );
			} )
			.finally( function () {
				busy( false );
			} );
	}

	function undo() {
		var c = current;
		if ( ! c || ! undos[ c.hash ] ) {
			return;
		}
		var previous = undos[ c.hash ];
		busy( true );
		api( '/translations/undo', { hash: c.hash, lang: data.lang, previous: previous, path: data.path } )
			.then( function ( r ) {
				delete undos[ c.hash ];
				segments[ c.hash ].sc = r.scoped ? 1 : 0;
				apply( c.hash, r.text, r.status );
				if ( current === c ) {
					var shown = null === r.text ? segments[ c.hash ].s : r.text;
					c.original = shown;
					fill( editBox, shown, c.occ, true );
					if ( scope ) {
						$( '.scope select' ).value = r.scoped ? 'page' : 'all';
					}
					badge( c.hash );
					say( null === r.text ? t.undoneSrc : t.undone, 'info' );
				}
			} )
			.catch( function ( err ) {
				say( err.message, 'error' );
			} )
			.finally( function () {
				busy( false );
			} );
	}

	// Formatting ---------------------------------------------------------------

	function selection() {
		return root.getSelection ? root.getSelection() : window.getSelection();
	}

	function rangeInBox() {
		var sel = selection();
		if ( ! sel || ! sel.rangeCount ) {
			return null;
		}
		var range = sel.getRangeAt( 0 );
		return editBox.contains( range.commonAncestorContainer ) ? range : null;
	}

	function caretAfter( node ) {
		var sel = selection();
		var range = document.createRange();
		range.setStartAfter( node );
		range.collapse( true );
		sel.removeAllRanges();
		sel.addRange( range );
	}

	// Wrap the selected words in <b>…</b> or <i>…</i>.
	function wrap( id ) {
		if ( ! current ) {
			return;
		}
		var range = rangeInBox();
		if ( ! range || range.collapsed ) {
			say( t.selectFirst, 'info' );
			editBox.focus();
			return;
		}
		var open = chip( { type: 'open', id: id, token: '<' + id + '>' }, current.occ, true );
		var close = chip( { type: 'close', id: id, token: '</' + id + '>' }, current.occ, true );
		var end = range.cloneRange();
		end.collapse( false );
		end.insertNode( close );
		range.insertNode( open );
		caretAfter( close );
		say( '' );
	}

	function lineBreak() {
		if ( ! current ) {
			return;
		}
		var range = rangeInBox();
		var br = chip( { type: 'void', id: 'br', token: '<br/>' }, current.occ, true );
		if ( range ) {
			range.deleteContents();
			range.insertNode( br );
		} else {
			editBox.appendChild( br );
		}
		caretAfter( br );
	}

	// Keep the selection when a toolbar button is pressed.
	root.querySelectorAll( '.fmt button' ).forEach( function ( b ) {
		b.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
		} );
	} );
	$( '.f-b' ).addEventListener( 'click', function () {
		wrap( 'b' );
	} );
	$( '.f-i' ).addEventListener( 'click', function () {
		wrap( 'i' );
	} );
	$( '.f-br' ).addEventListener( 'click', lineBreak );
	$( '.f-help' ).addEventListener( 'click', function () {
		var box = $( '.marks-help' );
		box.hidden = ! box.hidden;
		$( '.f-help' ).setAttribute( 'aria-expanded', String( ! box.hidden ) );
	} );

	// Hovering a mark highlights its partner, so a pair reads as one.
	editBox.addEventListener( 'mouseover', function ( e ) {
		var c = e.target.closest && e.target.closest( '.chip' );
		editBox.querySelectorAll( '.chip.is-pair' ).forEach( function ( x ) {
			x.classList.remove( 'is-pair' );
		} );
		if ( c ) {
			editBox.querySelectorAll( '.chip[data-id="' + c.getAttribute( 'data-id' ) + '"]' ).forEach( function ( x ) {
				x.classList.add( 'is-pair' );
			} );
		}
	} );

	function next() {
		var all = Array.prototype.slice.call( document.querySelectorAll( 'body [data-lango7]' ) );
		var i = current && current.el ? all.indexOf( current.el ) : -1;
		var el = all[ i + 1 ] || all[ 0 ];
		if ( el ) {
			open( el.getAttribute( 'data-lango7' ), el );
		}
	}

	$( '.save' ).addEventListener( 'click', function () {
		save().catch( function () {} );
	} );
	$( '.revert' ).addEventListener( 'click', revert );
	// Keep original: save the source text as this language's wording.
	$( '.keep' ).addEventListener( 'click', function () {
		if ( ! current ) {
			return;
		}
		fill( editBox, segments[ current.hash ].s, current.occ, true );
		save().then( null, function () {} );
	} );
	$( '.approve' ).addEventListener( 'click', approve );
	$( '.undo' ).addEventListener( 'click', undo );
	$( '.again .now' ).addEventListener( 'click', translateNow );
	$( '.review .reuse' ).addEventListener( 'click', function () {
		if ( current && review[ current.hash ] ) {
			fill( editBox, review[ current.hash ].text, current.occ, true );
			editBox.focus();
		}
	} );
	$( '.cancel' ).addEventListener( 'click', close );

	editBox.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			e.preventDefault();
			close();
		} else if ( 'Enter' === e.key && ( e.metaKey || e.ctrlKey ) ) {
			e.preventDefault();
			save().catch( function () {} );
		} else if ( 'Enter' === e.key && e.shiftKey ) {
			e.preventDefault();
			lineBreak();
		} else if ( 'Enter' === e.key ) {
			e.preventDefault(); // Segments are one paragraph; line breaks are chips.
		} else if ( ( e.metaKey || e.ctrlKey ) && ! e.altKey && ( 'b' === e.key || 'i' === e.key ) ) {
			e.preventDefault();
			wrap( e.key );
		} else if ( 'Tab' === e.key && ! e.shiftKey ) {
			e.preventDefault();
			save().then( next ).catch( function () {} );
		}
	} );
	// Paste as plain text, so no markup can enter the translation.
	editBox.addEventListener( 'paste', function ( e ) {
		e.preventDefault();
		document.execCommand( 'insertText', false, ( e.clipboardData || window.clipboardData ).getData( 'text/plain' ) );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! pop.hidden ) {
			close();
		}
	} );
	window.addEventListener( 'scroll', function () {
		if ( current && current.el ) {
			position( current.el );
			showOutline( current.el );
		} else {
			outline.hidden = true;
		}
	}, { passive: true } );

	// AI rewrite (only when an extension provides data.rewrite) -----------------
	// The suggestion replaces the edit box text for review; nothing is saved
	// until Save is pressed, and Cancel keeps the current translation.

	var rw = data.rewrite;
	if ( rw && rw.url && rw.tones && rw.tones.length ) {
		var tone = $( '.rewrite .tone' );
		var custom = $( '.rewrite .custom' );
		var aiButton = $( '.rewrite .ai' );
		rw.tones.forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o.id;
			opt.textContent = o.label;
			tone.appendChild( opt );
		} );
		custom.placeholder = rw.i18n.custom;
		aiButton.textContent = rw.i18n.button;
		$( '.rewrite' ).hidden = false;
		tone.addEventListener( 'change', function () {
			custom.hidden = 'custom' !== tone.value;
			if ( ! custom.hidden ) {
				custom.focus();
			}
		} );
		aiButton.addEventListener( 'click', function () {
			if ( ! current ) {
				return;
			}
			var c = current;
			busy( true );
			aiButton.disabled = true;
			say( rw.i18n.working );
			api( rw.url, { hash: c.hash, lang: data.lang, text: read( editBox ), tone: tone.value, custom: custom.value }, rw.nonce )
				.then( function ( r ) {
					if ( current !== c ) {
						return;
					}
					fill( editBox, r.text, c.occ, true );
					say( rw.i18n.preview, 'info' );
					editBox.focus();
				} )
				.catch( function ( err ) {
					say( err.message, 'error' );
				} )
				.finally( function () {
					busy( false );
					aiButton.disabled = false;
				} );
		} );
	}

	// Page strings panel ---------------------------------------------------------

	function panelItem( list, hash, q ) {
		var s = segments[ hash ];
		var occ = anyOcc( hash );
		var src = core.plain( s.s, occ );
		var tr = null === s.t ? '' : core.plain( s.t, occ );
		if ( q && ( src + ' ' + tr ).toLowerCase().indexOf( q ) === -1 ) {
			return false;
		}
		var li = document.createElement( 'li' );
		li.className = 'is-' + statusOf( hash );
		var b = document.createElement( 'button' );
		b.type = 'button';
		var small = document.createElement( 'small' );
		small.textContent = ( 'title' === s.k ? t.title + ' · ' : '' ) + src;
		var span = document.createElement( 'span' );
		span.textContent = tr || '—';
		b.appendChild( span );
		b.appendChild( small );
		b.addEventListener( 'click', function () {
			var els = elements( hash );
			var el = els.filter( onScreen )[ 0 ] || null;
			if ( el ) {
				el.scrollIntoView( { block: 'center' } );
			}
			// Text not on screen opens on its own; scrolling to it could jump a slider.
			open( hash, el );
		} );
		li.appendChild( b );
		list.appendChild( li );
		return true;
	}

	// On screen: rendered, not hidden by CSS, and not clipped away by a parent
	// with overflow (an off-screen slide inside a slider's track).
	function onScreen( el ) {
		if ( el.checkVisibility && ! el.checkVisibility( { opacityProperty: true, visibilityProperty: true } ) ) {
			return false;
		}
		var r = rect( el );
		if ( r.width < 1 || r.height < 1 ) {
			return false;
		}
		for ( var p = el.parentElement; p && p !== document.body; p = p.parentElement ) {
			var cs = getComputedStyle( p );
			if ( 'visible' !== cs.overflowX || 'visible' !== cs.overflowY ) {
				var c = p.getBoundingClientRect();
				if ( r.right <= c.left + 1 || r.left >= c.right - 1 || r.bottom <= c.top + 1 || r.top >= c.bottom - 1 ) {
					return false;
				}
			}
		}
		return true;
	}

	function isHidden( hash ) {
		var els = elements( hash );
		return els.length > 0 && ! els.some( onScreen );
	}

	function addGroup( list, label, hashes, q ) {
		var head = document.createElement( 'li' );
		head.className = 'group';
		head.textContent = label;
		list.appendChild( head );
		var shown = 0;
		hashes.forEach( function ( hash ) {
			if ( segments[ hash ] && panelItem( list, hash, q ) ) {
				shown++;
			}
		} );
		if ( ! shown ) {
			list.removeChild( head );
		}
	}

	function renderPanel() {
		var list = $( '.panel ul' );
		var q = $( '.panel input' ).value.toLowerCase();
		list.textContent = '';
		var hidden = [];
		Object.keys( segments ).forEach( function ( hash ) {
			if ( grouped[ hash ] ) {
				return;
			}
			if ( 'title' !== segments[ hash ].k && isHidden( hash ) ) {
				hidden.push( hash );
			} else {
				panelItem( list, hash, q );
			}
		} );
		if ( hidden.length ) {
			addGroup( list, t.hidden, hidden, q );
		}
		groups.forEach( function ( g ) {
			addGroup( list, g.label, g.hashes, q );
		} );
	}

	$( '.strings-btn' ).addEventListener( 'click', function () {
		panel.hidden = ! panel.hidden;
		if ( ! panel.hidden ) {
			renderPanel();
			$( '.panel input' ).focus();
		}
	} );
	$( '.panel input' ).addEventListener( 'input', renderPanel );
} ) );
