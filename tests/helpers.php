<?php
/**
 * Test helpers.
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Html\Parser;
use Lango7\Html\Segmenter;
use Lango7\Html\Selector;

/**
 * Segment texts of an HTML string, in document order.
 *
 * @return array{texts: string[], kinds: string[], attributes: array<int, string>, result: array}
 */
function segs( string $html, string $skip = '' ): array {
	$root   = Parser::parse( $html );
	$result = ( new Segmenter( 'en', '' === $skip ? null : new Selector( $skip ) ) )->segment( $root, $html );
	return array(
		'texts'      => array_map( static fn( $o ) => $o->segment->text, $result['texts'] ),
		'kinds'      => array_map( static fn( $o ) => $o->segment->kind, $result['texts'] ),
		'attributes' => array_map( static fn( $o ) => $o->attr . '=' . $o->segment->text . ' (' . $o->segment->kind . ')', $result['attributes'] ),
		'result'     => $result,
		'root'       => $root,
	);
}
