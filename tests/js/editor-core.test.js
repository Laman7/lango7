/* Run: node tests/js/editor-core.test.js */
'use strict';

var core = require( '../../assets/editor/editor-core.js' );
var assert = require( 'assert' );
var passed = 0;
var failed = 0;

function test( name, fn ) {
	try {
		fn();
		passed++;
	} catch ( e ) {
		failed++;
		console.log( 'FAIL: ' + name + '\n  ' + e.message );
	}
}

var occ = {
	tags: { 1: [ '<a href="/fr/contact/">', '</a>' ] },
	whole: { 2: '<img src="x.png" alt="Logo">' },
	n: { n1: '20' }
};
var src = 'Call <1>our team</1> &amp; save <n1/>% <2/>';

test( 'parse and serialize round-trip', function () {
	var parts = core.parse( src );
	assert.deepStrictEqual( parts.map( function ( p ) {
		return p.type;
	} ), [ 'text', 'open', 'text', 'close', 'text', 'void', 'text', 'void' ] );
	assert.strictEqual( parts[ 4 ].value, ' & save ' );
	assert.strictEqual( core.serialize( parts ), src );
} );

test( 'serialize escapes typed text and collapses spaces', function () {
	assert.strictEqual( core.serialize( [ { type: 'text', value: '  a <b>  & c ' } ] ), 'a &lt;b&gt; &amp; c' );
} );

test( 'render puts the page markup back', function () {
	assert.strictEqual(
		core.render( 'Hubungi <1>pasukan kami</1> &amp; jimat <n1/>% <2/>', occ ),
		'Hubungi <a href="/fr/contact/">pasukan kami</a> &amp; jimat 20% <img src="x.png" alt="Logo">'
	);
} );

test( 'render never lets typed text become markup', function () {
	assert.strictEqual( core.render( '&lt;script&gt;x&lt;/script&gt;', occ ), '&lt;script&gt;x&lt;/script&gt;' );
} );

test( 'check finds missing protected and extra placeholders', function () {
	assert.deepStrictEqual( core.check( src, 'Hubungi <1>kami</1> <n1/>', occ ), { missing: [ '<2/>' ], extra: [], order: false } );
	assert.deepStrictEqual( core.check( src, src + '<2/>', occ ).extra, [ '<2/>' ] );
	assert.deepStrictEqual( core.check( src, 'jimat <n1/> <2/> <1>x</1>', occ ), { missing: [], extra: [], order: false } );
	assert.deepStrictEqual( core.check( src, 'Hubungi kami <2/>', occ ).missing, [ '<1>', '<n1/>' ] );
} );

test( 'styling may be dropped or added, links may not', function () {
	var sOcc = { tags: { 1: [ '<span class="hl">', '</span>' ], 3: [ '<a href="/x">', '</a>' ] }, whole: { 2: '<br>' }, n: {} };
	var s = 'Ops,<2/> <1>no chaos</1> <3>here</3>';
	assert.deepStrictEqual( core.check( s, 'Ops, no chaos <3>aqui</3>', sOcc ), { missing: [], extra: [], order: false } );
	assert.deepStrictEqual( core.check( s, 'Ops <1>sin</1>', sOcc ).missing, [ '<3>' ] );
	assert.deepStrictEqual( core.check( s, '<b>Ops</b><br/><i>x</i> <3>y</3>', sOcc ), { missing: [], extra: [], order: false } );
	assert.strictEqual( core.check( s, '<b>Ops <3>y</b></3>', sOcc ).order, true );
	assert.deepStrictEqual( core.check( s, '<1>a</1><1>b</1> <3>y</3>', sOcc ).extra, [ '<1>', '</1>' ] );
	assert.strictEqual( core.label( '1', sOcc ), 'styled text' );
	assert.strictEqual( core.label( 'b', sOcc ), 'bold' );
	assert.strictEqual( core.isProtected( '3', sOcc ), true );
	assert.strictEqual( core.isProtected( '1', sOcc ), false );
} );

test( 'tidy drops a pair half and empty formatting', function () {
	assert.strictEqual( core.tidy( 'a <1>b c <3>d</3>' ), 'a b c <3>d</3>' );
	assert.strictEqual( core.tidy( 'a </1>b' ), 'a b' );
	assert.strictEqual( core.tidy( 'a <b></b> <i>x</i>' ), 'a <i>x</i>' );
} );

test( 'render and plain handle added formatting', function () {
	assert.strictEqual( core.render( '<b>a</b><br/><i>b</i>', occ ), '<strong>a</strong><br><em>b</em>' );
	assert.strictEqual( core.plain( 'a<br/>b <b>c</b>', occ ), 'a b c' );
} );

test( 'plain text and labels', function () {
	assert.strictEqual( core.plain( src, occ ), 'Call our team & save 20%' );
	assert.strictEqual( core.label( '1', occ ), 'link' );
	assert.strictEqual( core.label( '2', occ ), 'image' );
	assert.strictEqual( core.label( 'n1', occ ), '20' );
} );

console.log( passed + ' tests, ' + failed + ' failed' );
process.exit( failed ? 1 : 0 );
