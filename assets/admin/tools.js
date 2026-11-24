/* Tools → Cost estimator: scans the site in steps, then prices the text on every service. */
( function () {
	'use strict';
	var c = window.lango7Tools;
	if ( ! c || ! document.getElementById( 'lango7-cost-scan' ) ) {
		return;
	}
	var api = window.lango7Admin.client( c.rest, c.nonce, c.i18n.error );
	var fmt = window.lango7Admin.format;
	var el = window.lango7Admin.el;
	var t = c.i18n;
	var button = document.getElementById( 'lango7-cost-scan' );
	var status = document.getElementById( 'lango7-cost-status' );
	var bar = document.getElementById( 'lango7-cost-progress' );
	var out = document.getElementById( 'lango7-cost-result' );
	var num = new Intl.NumberFormat();
	var money = new Intl.NumberFormat( undefined, { style: 'currency', currency: 'USD' } );

	function price( chars, perMillion ) {
		return perMillion ? money.format( Math.round( chars * perMillion / 10000 ) / 100 ) : t.free;
	}

	function show( r ) {
		out.textContent = '';
		var busy = r.pages && r.read < r.pages;
		button.textContent = r.done ? t.rescan : t.scan;
		bar.hidden = ! busy;
		if ( busy ) {
			bar.max = r.pages;
			bar.value = r.read;
			status.textContent = fmt( t.reading, r.read, r.pages );
		} else {
			status.textContent = r.done ? fmt( t.scanned, new Date( r.done * 1000 ).toLocaleString() ) : '';
		}
		if ( ! r.done || ! r.totals ) {
			return;
		}
		var tot = r.totals;
		var n = r.languages.length;
		out.appendChild( el( 'div', { class: 'lango7-cost-stats' }, [
			stat( t.pages, r.pages - r.failed.length ),
			stat( t.strings, tot.strings ),
			stat( t.words, tot.words ),
			stat( t.chars, tot.chars )
		] ) );
		var todo = 0;
		Object.keys( tot.todo || {} ).forEach( function ( k ) {
			todo += tot.todo[ k ];
		} );
		var head = [ el( 'th', { scope: 'col', text: t.service } ), el( 'th', { scope: 'col', class: 'num', text: t.price } ), el( 'th', { scope: 'col', class: 'num', text: t.one } ) ];
		if ( n > 1 ) {
			head.push( el( 'th', { scope: 'col', class: 'num', text: fmt( t.all, n ) } ) );
		}
		if ( n ) {
			head.push( el( 'th', { scope: 'col', class: 'num', title: t.todoHelp, text: t.todo } ) );
		}
		var rows = [];
		var group = '';
		r.services.forEach( function ( s ) {
			if ( s.group !== group ) {
				group = s.group;
				rows.push( el( 'tr', { class: 'lango7-cost-group' }, [ el( 'th', { colspan: head.length, scope: 'colgroup', text: group } ) ] ) );
			}
			var label = [ el( 'strong', { text: s.label } ) ];
			if ( s.note ) {
				label.push( el( 'span', { class: 'description', text: s.note } ) );
			}
			var cells = [ el( 'th', { scope: 'row' }, label ), el( 'td', { class: 'num', text: s.per_million ? money.format( s.per_million ) : t.free } ), el( 'td', { class: 'num', text: price( tot.chars, s.per_million ) } ) ];
			if ( n > 1 ) {
				cells.push( el( 'td', { class: 'num', text: price( tot.chars * n, s.per_million ) } ) );
			}
			if ( n ) {
				cells.push( el( 'td', { class: 'num', text: price( todo, s.per_million ) } ) );
			}
			rows.push( el( 'tr', {}, cells ) );
		} );
		out.appendChild( el( 'table', { class: 'widefat lango7-cost-table' }, [ el( 'thead', {}, [ el( 'tr', {}, head ) ] ), el( 'tbody', {}, rows ) ] ) );
		out.appendChild( el( 'p', { class: 'description', text: t.estimate } ) );
		if ( ! c.pro && r.services.some( function ( s ) { return s.pro; } ) ) {
			out.appendChild( el( 'p', { class: 'description', text: t.proServices } ) );
		}
		if ( r.failed.length ) {
			out.appendChild( el( 'details', { class: 'lango7-cost-failed' }, [
				el( 'summary', { text: fmt( t.failed, r.failed.length ) } ),
				el( 'ul', {}, r.failed.map( function ( f ) {
					return el( 'li', {}, [ el( 'code', { text: f.path } ), ' ' + f.error ] );
				} ) )
			] ) );
		}
	}

	function stat( label, value ) {
		return el( 'div', { class: 'lango7-cost-stat' }, [ el( 'span', { text: label } ), el( 'strong', { text: num.format( value ) } ) ] );
	}

	function run( restart ) {
		button.disabled = true;
		if ( restart ) {
			status.textContent = t.listing;
		}
		api( 'POST', 'tools/estimate', { restart: restart } ).then( function ( r ) {
			show( r );
			if ( r.read < r.pages ) {
				return run( false );
			}
			button.disabled = false;
		} ).catch( function ( e ) {
			status.textContent = e.message || t.error;
			button.disabled = false;
		} );
	}

	button.addEventListener( 'click', function () {
		run( true );
	} );
	api( 'GET', 'tools/estimate' ).then( function ( r ) {
		show( r );
		// A scan left half way (page closed) carries on.
		if ( r.pages && r.read < r.pages ) {
			run( false );
		}
	} );
}() );
