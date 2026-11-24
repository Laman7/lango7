/* Run: node tests/js/chrome-core.test.js */
'use strict';

const assert = require( 'assert' );
const core = require( '../../assets/admin/chrome-core.js' );

const tests = [];
const test = ( name, fn ) => tests.push( [ name, fn ] );

// A fake Translator: uppercases letters, so placeholders, markers and digits are visible.
const upper = async ( t ) => t.toUpperCase();
// One that drops the ⟦…⟧ markers, as a model sometimes does.
const dropMarks = async ( t ) => t.replace( /⟦[^⟧]*⟧/g, '' ).toUpperCase();
// One that moves a marker to the end (still valid if nesting holds).
const reorder = async ( t ) => 'X ' + t;

test( 'plain text is escaped after translation', async () => {
	const r = await core.translateSegment( 'Fish &amp; chips', async ( t ) => t.replace( 'chips', 'kentang <goreng>' ) );
	assert.deepStrictEqual( r, { text: 'Fish &amp; kentang &lt;goreng&gt;', mode: 'plain' } );
} );

test( 'markers round-trip', async () => {
	const src = 'Call <1>our <2>whole</2> team</1> at <n1/><3/>';
	assert.strictEqual( core.toMarked( src ), 'Call ⟦1⟧our ⟦2⟧whole⟦/2⟧ team⟦/1⟧ at ⟦n1⟧⟦3⟧' );
	const r = await core.translateSegment( src, upper );
	assert.deepStrictEqual( r, { text: 'CALL <1>OUR <2>WHOLE</2> TEAM</1> AT <n1/><3/>', mode: 'marked' } );
} );

test( 'falls back to pieces when markers are lost', async () => {
	const r = await core.translateSegment( 'Call <1>our team</1> today', dropMarks );
	assert.deepStrictEqual( r, { text: 'CALL <1>OUR TEAM</1> TODAY', mode: 'pieces' } );
	assert.ok( core.validate( 'Call <1>our team</1> today', r.text ) );
} );

test( 'markers with spaces inside still parse', async () => {
	const r = await core.translateSegment( 'See <1>this</1>', async () => 'Lihat ⟦ 1 ⟧ini⟦ /1 ⟧' );
	assert.deepStrictEqual( r, { text: 'Lihat <1>ini</1>', mode: 'marked' } );
} );

test( 'pieces keep spacing and leading placeholders', async () => {
	const r = await core.piecewise( '<1/> Read more <2/>', upper );
	assert.strictEqual( r, '<1/> READ MORE <2/>' );
} );

test( 'escaped text inside markers', async () => {
	const r = await core.translateSegment( 'A &lt; B <1>&amp; C</1>', reorder );
	assert.deepStrictEqual( r, { text: 'X A &lt; B <1>&amp; C</1>', mode: 'marked' } );
} );

test( 'validate matches the PHP rules', () => {
	const src = 'Call <1>our <2>whole</2> team</1> at <n1/><3/>';
	assert.strictEqual( core.validate( src, '<1>a <2>b</2></1> <n1/><3/>' ), true );
	assert.strictEqual( core.validate( src, '<1>a <2>b</1></2> <n1/><3/>' ), false );
	assert.strictEqual( core.validate( src, '<1>a <2>b</2></1> <n1/>' ), false );
	assert.strictEqual( core.validate( src, '<1>a <2>b</2></1> <n1/><3/><4/>' ), false );
	assert.strictEqual( core.validate( 'Hello', ' ' ), false );
} );

( async () => {
	let failed = 0;
	for ( const [ name, fn ] of tests ) {
		try {
			await fn();
		} catch ( e ) {
			failed++;
			console.log( 'FAIL: ' + name + '\n  ' + e.message );
		}
	}
	console.log( `${ tests.length } tests, ${ failed } failed` );
	process.exit( failed ? 1 : 0 );
} )();
