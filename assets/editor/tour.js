/* First edit walkthrough, only on a translated page for an editor. */
( function () {
	'use strict';
	var cfg = window.lango7Tour;
	if ( ! cfg ) { return; }
	var bar = document.getElementById( 'wp-admin-bar-lango7-edit' );
	var box = document.createElement( 'aside' );
	box.className = 'lango7-edit-tour';
	box.setAttribute( 'role', 'status' );
	var message = document.createElement( 'p' );
	message.textContent = cfg.step === '1' ? cfg.first : cfg.second;
	var close = document.createElement( 'button' );
	close.type = 'button';
	close.textContent = cfg.close;
	box.appendChild( message );
	box.appendChild( close );
	document.body.appendChild( box );
	if ( cfg.step === '1' && bar ) {
		bar.classList.add( 'lango7-tour-focus' );
		var link = bar.querySelector( 'a' );
		if ( link ) {
			var url = new URL( link.href );
			url.searchParams.set( 'lango7_tour', '2' );
			link.href = url.toString();
		}
	}
	function dismiss() {
		box.remove();
		if ( bar ) { bar.classList.remove( 'lango7-tour-focus' ); }
		var clean = new URL( location.href );
		clean.searchParams.delete( 'lango7_tour' );
		history.replaceState( null, '', clean.toString() );
	}
	close.addEventListener( 'click', dismiss );
	document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { dismiss(); } } );
}() );
