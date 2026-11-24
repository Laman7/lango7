/*
 * Lango7 editor: placeholder text <-> editable parts, and rendering a segment
 * back to HTML. No DOM access, so it runs under Node for tests.
 *
 * Placeholder text escapes & < > and marks inline elements as <1>…</1>,
 * opaque ones as <2/> and numbers as <n1/>. An edit may add formatting of its
 * own: <b>…</b> (bold), <i>…</i> (italic) and <br/> (line break).
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.lango7EditorCore = factory();
	}
}( this, function () {
	'use strict';

	var TOKEN = /<(\/?)([ng]?\d+|b|i|br)(\/?)>/g;
	var FORMAT = { b: [ '<strong>', '</strong>' ], i: [ '<em>', '</em>' ], br: [ '<br>', '' ] };
	// Elements an edit may drop: only their styling goes, the words stay.
	var FREE = [ 'br', 'wbr', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sup', 'sub', 'abbr', 'del', 'ins', 'q', 'cite', 'font', 'big', 'code', 'kbd', 'time', 'bdi' ];

	function unescape( s ) {
		return s.replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&amp;/g, '&' );
	}

	function escape( s ) {
		return s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	/**
	 * @return {Array<{type: string, value?: string, id?: string, token?: string}>}
	 *   type text (value unescaped), open, close, void (incl. numbers).
	 */
	function parse( text ) {
		var parts = [];
		var last = 0;
		var m;
		TOKEN.lastIndex = 0;
		while ( ( m = TOKEN.exec( text ) ) ) {
			if ( m.index > last ) {
				parts.push( { type: 'text', value: unescape( text.slice( last, m.index ) ) } );
			}
			parts.push( { type: m[ 1 ] ? 'close' : ( m[ 3 ] ? 'void' : 'open' ), id: m[ 2 ], token: m[ 0 ] } );
			last = TOKEN.lastIndex;
		}
		if ( last < text.length ) {
			parts.push( { type: 'text', value: unescape( text.slice( last ) ) } );
		}
		return parts;
	}

	function serialize( parts ) {
		return parts.map( function ( p ) {
			return 'text' === p.type ? escape( p.value ) : p.token;
		} ).join( '' ).replace( /\s+/g, ' ' ).trim();
	}

	function tagName( id, occ ) {
		if ( FORMAT[ id ] ) {
			return 'b' === id ? 'strong' : ( 'i' === id ? 'em' : 'br' );
		}
		var html = ( ( occ || {} ).whole || {} )[ id ] || ( ( ( occ || {} ).tags || {} )[ id ] || [ '' ] )[ 0 ];
		var m = /^<([a-z0-9-]+)/i.exec( html );
		return m ? m[ 1 ].toLowerCase() : '';
	}

	/**
	 * Whether a placeholder must stay in an edit: links, images, buttons, fields,
	 * icons and numbers. Styling (bold, a styled span, a line break) may go.
	 */
	function isProtected( id, occ ) {
		if ( FORMAT[ id ] ) {
			return false;
		}
		if ( 'n' === id.charAt( 0 ) ) {
			return true;
		}
		return FREE.indexOf( tagName( id, occ ) ) === -1;
	}

	/**
	 * Drop the leftover half of a pair whose other half was deleted (the user
	 * removed that styling), and pairs left empty.
	 */
	function tidy( text ) {
		var parts = parse( text );
		var open = {};
		var close = {};
		parts.forEach( function ( p ) {
			if ( 'open' === p.type ) {
				open[ p.id ] = ( open[ p.id ] || 0 ) + 1;
			} else if ( 'close' === p.type ) {
				close[ p.id ] = ( close[ p.id ] || 0 ) + 1;
			}
		} );
		var keep = parts.filter( function ( p ) {
			return 'open' !== p.type && 'close' !== p.type || open[ p.id ] === close[ p.id ];
		} );
		return serialize( keep ).replace( /<(b|i)>\s*<\/\1>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * Problems with an edit, against its source: protected placeholders that are
	 * missing, placeholders not in the source or used twice, and pairs out of order.
	 */
	function check( source, text, occ ) {
		var want = {};
		( source.match( TOKEN ) || [] ).forEach( function ( t ) {
			want[ t ] = true;
		} );
		var count = {};
		var stack = [];
		var extra = [];
		var order = false;
		parse( text ).forEach( function ( p ) {
			if ( 'text' === p.type ) {
				return;
			}
			if ( FORMAT[ p.id ] ) {
				if ( 'br' === p.id ? 'void' !== p.type : 'void' === p.type ) {
					extra.push( p.token );
				}
			} else if ( ! want[ p.token ] ) {
				extra.push( p.token );
				return;
			} else {
				count[ p.token ] = ( count[ p.token ] || 0 ) + 1;
				if ( 2 === count[ p.token ] ) {
					extra.push( p.token );
				}
			}
			if ( 'open' === p.type ) {
				stack.push( p.id );
			} else if ( 'close' === p.type && stack.pop() !== p.id ) {
				order = true;
			}
		} );
		var missing = Object.keys( want ).filter( function ( t ) {
			return ! count[ t ] && '/' !== t.charAt( 1 ) && isProtected( t.replace( /[<>/]/g, '' ), occ );
		} );
		return { missing: missing, extra: extra, order: order || stack.length > 0 };
	}

	/**
	 * HTML for a segment on the page. occ comes from the server's map:
	 * tags[id] = [open, close], whole[id] = element HTML, n[id] = number.
	 */
	function render( text, occ ) {
		return parse( text ).map( function ( p ) {
			if ( 'text' === p.type ) {
				return escape( p.value );
			}
			if ( FORMAT[ p.id ] ) {
				return FORMAT[ p.id ][ 'close' === p.type ? 1 : 0 ];
			}
			if ( 'n' === p.id.charAt( 0 ) ) {
				return escape( String( ( occ.n || {} )[ p.id ] || '' ) );
			}
			if ( 'void' === p.type ) {
				return ( occ.whole || {} )[ p.id ] || '';
			}
			var tag = ( occ.tags || {} )[ p.id ];
			return tag ? tag[ 'open' === p.type ? 0 : 1 ] : '';
		} ).join( '' );
	}

	/**
	 * Plain text (page title, lists): placeholders dropped, numbers filled in.
	 */
	function plain( text, occ ) {
		return parse( text ).map( function ( p ) {
			if ( 'text' === p.type ) {
				return p.value;
			}
			if ( 'br' === p.id ) {
				return ' ';
			}
			return 'n' === p.id.charAt( 0 ) ? String( ( ( occ || {} ).n || {} )[ p.id ] || '' ) : '';
		} ).join( '' ).replace( /\s+/g, ' ' ).trim();
	}

	var LABELS = { a: 'link', strong: 'bold', b: 'bold', em: 'italic', i: 'italic', u: 'underline', br: 'line break', img: 'image', code: 'code', sup: 'sup', sub: 'sub', span: 'styled text', mark: 'highlight', abbr: 'abbr', small: 'small', svg: 'icon', button: 'button', input: 'field' };

	/**
	 * Short chip label for a placeholder: its element name ("link"), or the number.
	 */
	function label( id, occ ) {
		if ( 'n' === id.charAt( 0 ) ) {
			return String( ( ( occ || {} ).n || {} )[ id ] || '#' );
		}
		var name = tagName( id, occ );
		return LABELS[ name ] || name || id;
	}

	return { parse: parse, serialize: serialize, check: check, tidy: tidy, isProtected: isProtected, render: render, plain: plain, label: label, escape: escape, unescape: unescape };
} ) );
