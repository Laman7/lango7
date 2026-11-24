<?php
/**
 * Placeholder round-trip (spec 4.3).
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Translation\Glossary;
use Lango7\Translation\Placeholders;
use Lango7\Translation\Segment;

$src = 'Call <1>our <2>whole</2> team</1> at <n1/><3/>';

check( 'valid: same placeholders', true, Placeholders::validate( $src, 'Hubungi <1>seluruh <2>pasukan</2> kami</1> di <n1/><3/>' ) );
check( 'valid: reordered siblings', true, Placeholders::validate( 'A <1>b</1> c <2>d</2>', '<2>D</2> x <1>B</1>' ) );
check( 'invalid: missing void', false, Placeholders::validate( $src, 'Hubungi <1>seluruh <2>pasukan</2> kami</1> di <n1/>' ) );
check( 'invalid: missing pair', false, Placeholders::validate( $src, 'Hubungi seluruh <2>pasukan</2> kami di <n1/><3/>' ) );
check( 'invalid: duplicated', false, Placeholders::validate( $src, '<1>a</1><1>b</1> <2>x</2> <n1/><3/>' ) );
check( 'invalid: misnested', false, Placeholders::validate( $src, '<1>a <2>b</1> c</2> <n1/><3/>' ) );
check( 'invalid: extra id', false, Placeholders::validate( $src, '<1>a <2>b</2></1> <n1/><3/><4/>' ) );
check( 'invalid: close before open', false, Placeholders::validate( 'a <1>b</1>', 'a </1>b<1>' ) );
check( 'invalid: pair used as void', false, Placeholders::validate( 'a <1>b</1>', 'a <1/> b' ) );
check( 'invalid: empty', false, Placeholders::validate( 'Hello', '   ' ) );

check( 'clean keeps placeholders and re-escapes text', 'Tom &amp; Jerry &lt; <1>x</1>', Placeholders::clean( 'Tom &amp; Jerry < <1>x</1>' ) );
check( 'clean decodes engine entities', "It's <1>ok</1>", Placeholders::clean( 'It&#39;s <1>ok</1>' ) );
check( 'clean rejects invented tags', null, Placeholders::clean( 'Hello <b>world</b>' ) );
check( 'clean for an edit keeps formatting', 'Hello <b>world</b><br/>', Placeholders::clean( 'Hello <b>world</b><br/>', true ) );
check( 'clean for an edit still rejects other tags', null, Placeholders::clean( 'Hello <strong>world</strong>', true ) );
check( 'clean for an edit rejects attributes', null, Placeholders::clean( 'Hello <b onclick=x>world</b>', true ) );

$edit_src = 'Ops,<1/> <2>no chaos</2> <3>here</3> <n1/>';
check( 'edit: drop a line break and a pair', true, Placeholders::validateEdit( $edit_src, 'Ops, sin caos <3>aqui</3> <n1/>' ) );
check( 'edit: add bold, italic, line break', true, Placeholders::validateEdit( $edit_src, '<b>Ops</b>,<br/> <i><2>sin</2></i> <3>aqui</3> <n1/>' ) );
check( 'edit: bold twice is fine', true, Placeholders::validateEdit( 'a b', '<b>a</b> <b>b</b>' ) );
check( 'edit: invented placeholder', false, Placeholders::validateEdit( $edit_src, 'x <4>y</4>' ) );
check( 'edit: duplicated source pair', false, Placeholders::validateEdit( $edit_src, '<2>a</2> <2>b</2>' ) );
check( 'edit: half a pair', false, Placeholders::validateEdit( $edit_src, 'a <2>b' ) );
check( 'edit: crossed pairs', false, Placeholders::validateEdit( $edit_src, '<b>a <2>b</b></2>' ) );
check( 'edit: br as pair', false, Placeholders::validateEdit( 'a', 'a <br>b</br>' ) );
check( 'edit: bold as void', false, Placeholders::validateEdit( 'a', 'a <b/>' ) );
check( 'edit: empty', false, Placeholders::validateEdit( 'Hello', '<b> </b>' ) );
check( 'toPlain drops formatting', 'a b c', Placeholders::toPlain( 'a<br/>b <b>c</b>', array() ) );
check( 'clean rejects script', null, Placeholders::clean( '<script>alert(1)</script><1>x</1>' ) );
check( 'clean keeps an escaped tag as text', '&lt;b&gt; is bold', Placeholders::clean( '&lt;b&gt; is bold' ) );

check( 'toPlain restores numbers', 'Jimat 20% & lagi', Placeholders::toPlain( 'Jimat <n1/>% &amp; lagi<1/>', array( 'n1' => '20' ) ) );

// Glossary.
$terms = array(
	array(
		'term'           => 'Lango7',
		'translation'    => null,
		'case_sensitive' => true,
	),
	array(
		'term'           => 'care plan',
		'translation'    => 'pelan penjagaan',
		'case_sensitive' => false,
	),
);
[ $wrapped, $repl ] = Glossary::wrap( new Segment( 'h', 'Get a Care Plan from <1>Lango7</1> or Lango7x', 'text' ), $terms );
check( 'glossary wraps terms outside tags', 'Get a <g1/> from <1><g2/></1> or Lango7x', $wrapped->text );
check( 'glossary values for engine', array( 'g1' => 'Care Plan', 'g2' => 'Lango7' ), $wrapped->values );
check( 'glossary unwrap', 'Dapatkan pelan penjagaan dari <1>Lango7</1>', Glossary::unwrap( 'Dapatkan <g1/> dari <1><g2/></1>', $repl ) );
check( 'glossary unwrap rejects a dropped term', null, Glossary::unwrap( 'Dapatkan dari <1><g2/></1>', $repl ) );
