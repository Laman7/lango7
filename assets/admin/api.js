/* Shared by the Lango7 admin screens: REST calls and sprintf-style formatting. */
( function () {
	'use strict';

	/**
	 * A fetch wrapper bound to lango7/v1. Rejects with the server's message.
	 */
	function client( rest, nonce, fallback ) {
		// Local can serve wp-admin on a port while WordPress's home URL omits it.
		// Keep REST requests on the admin page's origin so its auth cookie is sent.
		var url = new URL( rest, window.location.href );
		if ( url.hostname === window.location.hostname && url.origin !== window.location.origin ) {
			rest = window.location.origin + url.pathname + url.search;
		}
		return function ( method, path, body ) {
			return fetch( rest + path, {
				method: method,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: body ? JSON.stringify( body ) : undefined
			} ).then( function ( res ) {
				return res.json().catch( function () {
					return null;
				} ).then( function ( data ) {
					if ( ! res.ok ) {
						throw new Error( ( data && data.message ) || fallback );
					}
					return data;
				} );
			} );
		};
	}

	// %s, %d and numbered %1$s / %2$d, as in the PHP strings they come from.
	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return template.replace( /%(?:(\d)\$)?([sd%])/g, function ( m, n, type ) {
			if ( type === '%' ) {
				return '%';
			}
			return String( args[ n ? n - 1 : next++ ] );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else if ( k === 'class' ) {
				node.className = attrs[ k ];
			} else if ( k.indexOf( 'on' ) === 0 ) {
				node.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				node.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c !== null && c !== undefined ) {
				node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	window.lango7Admin = { client: client, format: format, el: el };
}() );
