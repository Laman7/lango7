/**
 * Placeholder handling for Chrome's Translator API. No DOM; also loaded by
 * tests/js/chrome-core.test.js under Node.
 *
 * Segment text uses the plugin's placeholder format: "&", "<" and ">" escaped,
 * plus <1>…</1> (inline element), <2/> (void element) and <n1/> (number).
 * Chrome translates plain text, so placeholders are swapped for ⟦1⟧ ⟦/1⟧ ⟦2⟧
 * markers first. If the markers do not survive intact, the text between
 * placeholders is translated piece by piece instead, which always keeps every
 * placeholder (at some cost to fluency). The server checks the result again.
 */
( function ( root ) {
	'use strict';

	var TOKEN = /<(\/?)([ng]?\d+)(\/?)>/g;
	// Models sometimes change case or add spaces inside a marker; accept both.
	var MARK = /⟦\s*(\/?)\s*([ng]?\d+)\s*(\/?)\s*⟧/gi;

	function unescape( text ) {
		return text.replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&amp;/g, '&' );
	}

	function escape( text ) {
		return text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function hasPlaceholders( text ) {
		return new RegExp( TOKEN.source ).test( text );
	}

	/**
	 * Same rules as Placeholders::validate() in PHP.
	 */
	function validate( source, translated ) {
		var strip = function ( t ) {
			return t.replace( TOKEN, '' ).trim();
		};
		if ( strip( translated ) === '' && strip( source ) !== '' ) {
			return false;
		}
		var paired = {};
		var voids = {};
		var m;
		var re = new RegExp( TOKEN.source, 'g' );
		while ( ( m = re.exec( source ) ) ) {
			if ( m[ 3 ] === '/' ) {
				voids[ m[ 2 ] ] = true;
			} else if ( m[ 1 ] === '' ) {
				paired[ m[ 2 ] ] = true;
			}
		}
		var seen = {};
		var count = 0;
		var stack = [];
		re = new RegExp( TOKEN.source, 'g' );
		while ( ( m = re.exec( translated ) ) ) {
			var id = m[ 2 ];
			if ( m[ 3 ] === '/' ) {
				if ( m[ 1 ] !== '' || ! voids[ id ] || seen[ 'v' + id ] ) {
					return false;
				}
				seen[ 'v' + id ] = true;
				count++;
			} else if ( m[ 1 ] === '' ) {
				if ( ! paired[ id ] || seen[ 'p' + id ] ) {
					return false;
				}
				seen[ 'p' + id ] = true;
				count++;
				stack.push( id );
			} else if ( stack.pop() !== id ) {
				return false;
			}
		}
		return stack.length === 0 && count === Object.keys( paired ).length + Object.keys( voids ).length;
	}

	function toMarked( text ) {
		var out = '';
		var last = 0;
		var re = new RegExp( TOKEN.source, 'g' );
		var m;
		while ( ( m = re.exec( text ) ) ) {
			out += unescape( text.slice( last, m.index ) ) + '⟦' + m[ 1 ] + m[ 2 ] + '⟧';
			last = m.index + m[ 0 ].length;
		}
		return out + unescape( text.slice( last ) );
	}

	function fromMarked( marked, source ) {
		var voids = {};
		var re = new RegExp( TOKEN.source, 'g' );
		var m;
		while ( ( m = re.exec( source ) ) ) {
			if ( m[ 3 ] === '/' ) {
				voids[ m[ 2 ] ] = true;
			}
		}
		var out = '';
		var last = 0;
		var mark = new RegExp( MARK.source, 'gi' );
		while ( ( m = mark.exec( marked ) ) ) {
			var id = m[ 2 ].toLowerCase();
			out += escape( marked.slice( last, m.index ) );
			out += voids[ id ] && m[ 1 ] === '' ? '<' + id + '/>' : '<' + m[ 1 ] + id + '>';
			last = m.index + m[ 0 ].length;
		}
		return ( out + escape( marked.slice( last ) ) ).replace( /[ \t\n\r\f]+/g, ' ' ).trim();
	}

	/**
	 * Translate the text between placeholders separately, keeping each piece's
	 * surrounding spaces.
	 */
	async function piecewise( text, translate ) {
		var parts = text.split( new RegExp( '(' + TOKEN.source.replace( /\((?!\?)/g, '(?:' ) + ')' ) );
		var out = '';
		for ( var i = 0; i < parts.length; i++ ) {
			var part = parts[ i ];
			if ( i % 2 === 1 || part.trim() === '' ) {
				out += part;
				continue;
			}
			var lead = part.match( /^\s*/ )[ 0 ];
			var trail = part.match( /\s*$/ )[ 0 ];
			out += lead + escape( ( await translate( unescape( part.trim() ) ) ).trim() ) + trail;
		}
		return out.replace( /[ \t\n\r\f]+/g, ' ' ).trim();
	}

	/**
	 * @param {string}   text      Placeholder text from the server.
	 * @param {Function} translate async (plainText) => translatedText, e.g. translator.translate.
	 * @return {Promise<{text: string, mode: string}>}
	 */
	async function translateSegment( text, translate ) {
		if ( ! hasPlaceholders( text ) ) {
			return { text: escape( ( await translate( unescape( text ) ) ).trim() ), mode: 'plain' };
		}
		var marked = fromMarked( await translate( toMarked( text ) ), text );
		if ( validate( text, marked ) ) {
			return { text: marked, mode: 'marked' };
		}
		return { text: await piecewise( text, translate ), mode: 'pieces' };
	}

	var api = {
		translateSegment: translateSegment,
		validate: validate,
		toMarked: toMarked,
		fromMarked: fromMarked,
		piecewise: piecewise
	};
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.lango7ChromeCore = api;
	}
}( this ) );
