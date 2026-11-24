<?php
/**
 * Source-preserving HTML tokenizer and tree builder.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

/**
 * Why not DOMDocument or Dom\HTMLDocument: both re-serialise the whole page, and
 * any difference between their output and the original (attribute quoting, void
 * tags, entity choices, libxml's HTML4 rules) lands on every translated page.
 * This parser only records where things are; the Applier then splices the
 * translated ranges into the untouched original, so markup it does not understand
 * still comes out byte-identical.
 *
 * It implements the parts of the HTML5 tree-construction rules that change where
 * text belongs: void elements, raw-text elements, implied end tags (p, li, dt/dd,
 * table cells, options) and self-closing tags inside SVG/MathML. It does not do
 * table foster-parenting or the adoption agency; neither moves text between
 * block-level parents in practice.
 */
final class Parser {

	private const VOID = array(
		'area'   => true,
		'base'   => true,
		'br'     => true,
		'col'    => true,
		'embed'  => true,
		'hr'     => true,
		'img'    => true,
		'input'  => true,
		'keygen' => true,
		'link'   => true,
		'meta'   => true,
		'param'  => true,
		'source' => true,
		'track'  => true,
		'wbr'    => true,
	);

	/** Elements whose content is text up to the matching end tag. */
	private const RAW = array(
		'script'    => true,
		'style'     => true,
		'textarea'  => true,
		'title'     => true,
		'xmp'       => true,
		'iframe'    => true,
		'noembed'   => true,
		'noframes'  => true,
		'noscript'  => true,
		'plaintext' => true,
	);

	/** Start tags that close an open <p>. */
	private const CLOSES_P = array(
		'address'    => true,
		'article'    => true,
		'aside'      => true,
		'blockquote' => true,
		'center'     => true,
		'details'    => true,
		'dialog'     => true,
		'dir'        => true,
		'div'        => true,
		'dl'         => true,
		'dd'         => true,
		'dt'         => true,
		'fieldset'   => true,
		'figcaption' => true,
		'figure'     => true,
		'footer'     => true,
		'form'       => true,
		'h1'         => true,
		'h2'         => true,
		'h3'         => true,
		'h4'         => true,
		'h5'         => true,
		'h6'         => true,
		'header'     => true,
		'hgroup'     => true,
		'hr'         => true,
		'li'         => true,
		'main'       => true,
		'menu'       => true,
		'nav'        => true,
		'ol'         => true,
		'p'          => true,
		'pre'        => true,
		'section'    => true,
		'table'      => true,
		'ul'         => true,
	);

	private const HEADINGS = array(
		'h1' => true,
		'h2' => true,
		'h3' => true,
		'h4' => true,
		'h5' => true,
		'h6' => true,
	);

	private const START_TAG = '/\G<([a-zA-Z][^\s\/>]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/';
	private const ATTRIBUTE = '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/';

	private string $html;
	private int $len;

	/** @var Node[] */
	private array $stack;

	/** Open svg/math elements; inside them "/>" really is self-closing. */
	private int $foreign = 0;

	public static function parse( string $html ): Node {
		return ( new self() )->run( $html );
	}

	private function run( string $html ): Node {
		$this->html  = $html;
		$this->len   = strlen( $html );
		$root        = new Node( Node::ELEMENT, 0, $this->len );
		$root->name  = '#root';
		$this->stack = array( $root );

		$pos = 0;
		while ( $pos < $this->len ) {
			$lt = strpos( $html, '<', $pos );
			if ( false === $lt ) {
				$this->text( $pos, $this->len );
				break;
			}
			if ( $lt > $pos ) {
				$this->text( $pos, $lt );
			}
			$pos = $this->tag( $lt );
		}

		while ( count( $this->stack ) > 1 ) {
			$this->pop( $this->len, $this->len );
		}
		$root->tagEnd     = 0;
		$root->closeStart = $this->len;
		return $root;
	}

	/**
	 * Handle the markup starting at '<' and return the offset after it.
	 */
	private function tag( int $lt ): int {
		$html = $this->html;
		$next = $html[ $lt + 1 ] ?? '';

		if ( '!' === $next || '?' === $next ) {
			if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
				$close = strpos( $html, '-->', $lt + 4 );
				$end   = false === $close ? $this->len : $close + 3;
			} else {
				$close = strpos( $html, '>', $lt );
				$end   = false === $close ? $this->len : $close + 1;
			}
			$this->append( new Node( Node::COMMENT, $lt, $end ) );
			return $end;
		}

		if ( '/' === $next ) {
			if ( preg_match( '/\G<\/([a-zA-Z][^\s\/>]*)[^>]*>/', $html, $m, 0, $lt ) ) {
				$end = $lt + strlen( $m[0] );
				$this->endTag( strtolower( $m[1] ), $lt, $end );
				return $end;
			}
			$close = strpos( $html, '>', $lt );
			$end   = false === $close ? $this->len : $close + 1;
			$this->append( new Node( Node::COMMENT, $lt, $end ) );
			return $end;
		}

		if ( ctype_alpha( $next ) && preg_match( self::START_TAG, $html, $m, PREG_OFFSET_CAPTURE, $lt ) ) {
			return $this->startTag( $lt, $m );
		}

		// A bare '<' is text.
		$this->text( $lt, $lt + 1 );
		return $lt + 1;
	}

	/**
	 * @param array<int, array{0: string, 1: int}> $m START_TAG match with offsets.
	 */
	private function startTag( int $lt, array $m ): int {
		$name    = strtolower( $m[1][0] );
		$tag_end = $lt + strlen( $m[0][0] );

		$this->closeImplied( $name, $lt );

		if ( in_array( $name, array( 'html', 'head', 'body' ), true ) && $this->openIndex( $name ) > 0 ) {
			return $tag_end;
		}

		$node         = new Node( Node::ELEMENT, $lt );
		$node->name   = $name;
		$node->tagEnd = $tag_end;
		$this->attributes( $node, $m[2][0], $m[2][1] );
		$node->selfClosing = str_ends_with( rtrim( $m[2][0] ), '/' );
		$this->append( $node );

		if ( isset( self::VOID[ $name ] ) || ( $this->foreign > 0 && $node->selfClosing ) ) {
			$node->closeStart = $tag_end;
			$node->end        = $tag_end;
			return $tag_end;
		}

		$raw = isset( self::RAW[ $name ] ) && ( 0 === $this->foreign || 'script' === $name || 'style' === $name );
		if ( $raw ) {
			if ( 'plaintext' !== $name && preg_match( '/<\/' . preg_quote( $name, '/' ) . '[\s\/>]/i', $this->html, $close, PREG_OFFSET_CAPTURE, $tag_end ) ) {
				$close_start = $close[0][1];
				$gt          = strpos( $this->html, '>', $close_start );
				$end         = false === $gt ? $this->len : $gt + 1;
			} else {
				$close_start = $this->len;
				$end         = $this->len;
			}
			if ( $close_start > $tag_end ) {
				$text         = new Node( Node::TEXT, $tag_end, $close_start );
				$text->parent = $node;
				$node->children[] = $text;
			}
			$node->closeStart = $close_start;
			$node->end        = $end;
			return $end;
		}

		$this->stack[] = $node;
		if ( 'svg' === $name || 'math' === $name ) {
			++$this->foreign;
		}
		return $tag_end;
	}

	private function attributes( Node $node, string $source, int $base ): void {
		if ( '' === trim( $source ) ) {
			return;
		}
		preg_match_all( self::ATTRIBUTE, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $a ) {
			$name = strtolower( $a[1][0] );
			if ( isset( $node->attrs[ $name ] ) ) {
				continue; // First one wins, as in browsers.
			}
			$entry = array(
				'value'       => '',
				'offset'      => -1,
				'length'      => 0,
				'quote'       => '',
				'name_offset' => $base + $a[1][1],
			);
			foreach ( array( 2 => '"', 3 => "'", 4 => '' ) as $group => $quote ) {
				if ( isset( $a[ $group ] ) && -1 !== $a[ $group ][1] ) {
					$entry['value']  = $a[ $group ][0];
					$entry['offset'] = $base + $a[ $group ][1];
					$entry['length'] = strlen( $a[ $group ][0] );
					$entry['quote']  = $quote;
					break;
				}
			}
			$node->attrs[ $name ] = $entry;
		}
	}

	/**
	 * The subset of HTML5 "implied end tag" rules that decides which block a
	 * run of text belongs to.
	 */
	private function closeImplied( string $name, int $at ): void {
		if ( 'body' === $name ) {
			$this->closeOpen( array( 'head' ), array( 'html' ), $at );
		}
		if ( isset( self::CLOSES_P[ $name ] ) ) {
			$this->closeOpen( array( 'p' ), array( 'button', 'table', 'td', 'th', 'caption', 'template', 'html', 'object', 'marquee', 'applet' ), $at );
		}
		switch ( $name ) {
			case 'li':
				$this->closeOpen( array( 'li' ), array( 'ul', 'ol', 'menu', 'table', 'td', 'th', 'template', 'body', 'html', 'section', 'nav', 'article', 'aside', 'header', 'footer', 'main', 'form' ), $at );
				break;
			case 'dt':
			case 'dd':
				$this->closeOpen( array( 'dt', 'dd' ), array( 'dl', 'table', 'template', 'body', 'html' ), $at );
				break;
			case 'tr':
				$this->closeOpen( array( 'tr' ), array( 'table', 'thead', 'tbody', 'tfoot', 'template', 'html' ), $at );
				break;
			case 'td':
			case 'th':
				$this->closeOpen( array( 'td', 'th' ), array( 'tr', 'table', 'template', 'html' ), $at );
				break;
			case 'thead':
			case 'tbody':
			case 'tfoot':
				$this->closeOpen( array( 'thead', 'tbody', 'tfoot' ), array( 'table', 'template', 'html' ), $at );
				break;
			case 'option':
				$this->closeOpen( array( 'option' ), array( 'select', 'datalist', 'optgroup', 'html' ), $at );
				break;
			case 'optgroup':
				$this->closeOpen( array( 'option', 'optgroup' ), array( 'select', 'html' ), $at );
				break;
		}
		if ( isset( self::HEADINGS[ $name ] ) && isset( self::HEADINGS[ end( $this->stack )->name ] ) ) {
			$this->pop( $at, $at );
		}
	}

	/**
	 * Close the nearest open element named in $targets, unless a $boundaries
	 * element is reached first.
	 *
	 * @param string[] $targets
	 * @param string[] $boundaries
	 */
	private function closeOpen( array $targets, array $boundaries, int $at ): void {
		for ( $i = count( $this->stack ) - 1; $i > 0; $i-- ) {
			$name = $this->stack[ $i ]->name;
			if ( in_array( $name, $targets, true ) ) {
				while ( count( $this->stack ) > $i ) {
					$this->pop( $at, $at );
				}
				return;
			}
			if ( in_array( $name, $boundaries, true ) ) {
				return;
			}
		}
	}

	private function endTag( string $name, int $start, int $end ): void {
		$i = $this->openIndex( $name );
		if ( $i <= 0 ) {
			return; // Stray end tag: ignored, as browsers do.
		}
		while ( count( $this->stack ) > $i + 1 ) {
			$this->pop( $start, $start );
		}
		$this->pop( $start, $end );
	}

	private function openIndex( string $name ): int {
		for ( $i = count( $this->stack ) - 1; $i > 0; $i-- ) {
			if ( $this->stack[ $i ]->name === $name ) {
				return $i;
			}
		}
		return -1;
	}

	private function pop( int $close_start, int $end ): void {
		$node             = array_pop( $this->stack );
		$node->closeStart = $close_start;
		$node->end        = $end;
		if ( 'svg' === $node->name || 'math' === $node->name ) {
			--$this->foreign;
		}
	}

	private function append( Node $node ): void {
		$parent             = end( $this->stack );
		$node->parent       = $parent;
		$parent->children[] = $node;
	}

	private function text( int $start, int $end ): void {
		$parent = end( $this->stack );
		$last   = end( $parent->children );
		if ( $last instanceof Node && Node::TEXT === $last->type && $last->end === $start ) {
			$last->end = $end;
			return;
		}
		$this->append( new Node( Node::TEXT, $start, $end ) );
	}
}
