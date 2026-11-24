/**
 * Closes an open dropdown or globe switcher on a click outside it or on Escape.
 * Without JavaScript the <details> element still opens and closes by itself.
 */
( function () {
	function openOnes() {
		return document.querySelectorAll( 'details.lango7-switcher[open]' );
	}
	document.addEventListener( 'click', function ( e ) {
		openOnes().forEach( function ( d ) {
			if ( ! d.contains( e.target ) ) {
				d.removeAttribute( 'open' );
			}
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		openOnes().forEach( function ( d ) {
			d.removeAttribute( 'open' );
			d.querySelector( 'summary' ).focus();
		} );
	} );
}() );
