<?php
/**
 * Writes translations back into the original HTML.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

use Lango7\Translation\Placeholders;

/**
 * Collects edits and splices them into the original string in one pass.
 *
 * Call order matters: set every attribute (translated alt text, rewritten hrefs,
 * <html lang>) before replace(), because a placeholder like <1> renders as its
 * element's start tag *including* those attribute changes.
 */
final class Applier {

	/** @var array<int, array{node: Node, attrs: array<string, string>}> Keyed by element start offset. */
	private array $mods = array();

	/** @var array<int, string> Built start tags, keyed like $mods. */
	private array $tags = array();

	private bool $sorted = true;

	/** @var array<int, array{0: int, 1: int, 2: string, 3: int}> start, end, replacement, sort rank. */
	private array $edits = array();

	public function __construct( private string $html ) {}

	/**
	 * Set an attribute to a plain-text value (escaped here).
	 */
	public function replaceAttribute( Node $node, string $name, string $plain ): void {
		$this->setAttribute( $node, $name, $plain );
	}

	public function setAttribute( Node $node, string $name, string $plain ): void {
		$this->mods[ $node->start ]['node']           = $node;
		$this->mods[ $node->start ]['attrs'][ $name ] = $plain;
		unset( $this->tags[ $node->start ] );
		$this->sorted = false;
	}

	/**
	 * Replace a text occurrence with translated placeholder text.
	 */
	public function replace( Occurrence $o, string $translated ): void {
		$this->edits[] = array( $o->start, $o->end, $o->lead . $this->render( $o, $translated ) . $o->trail, 1 );
	}

	/**
	 * Wrap a text occurrence in markup (edit-mode markers), keeping its content.
	 */
	public function wrap( Occurrence $o, string $before, string $after, ?string $translated = null ): void {
		$inner         = null === $translated
			? $this->renderSlice( $o->start + strlen( $o->lead ), $o->end - strlen( $o->trail ) )
			: $this->render( $o, $translated );
		$this->edits[] = array( $o->start, $o->end, $o->lead . $before . $inner . $after . $o->trail, 1 );
	}

	/**
	 * What each placeholder of an occurrence renders as, for editors that
	 * rebuild a segment in the browser: open/close tags for pairs (<1>…</1>),
	 * the whole element for opaque ones (<2/>), the literal for numbers.
	 *
	 * @return array{tags: array<string, array{0: string, 1: string}>, whole: array<string, string>, n: array<string, string>}
	 */
	public function placeholderMarkup( Occurrence $o ): array {
		$out = array(
			'tags'  => array(),
			'whole' => array(),
			'n'     => $o->values,
		);
		foreach ( $o->placeholders as $id => $node ) {
			if ( str_contains( $o->segment->text, '<' . $id . '/>' ) ) {
				$out['whole'][ $id ] = $this->renderSlice( $node->start, $node->end );
			} else {
				$out['tags'][ $id ] = array( $this->startTag( $node ), substr( $this->html, $node->closeStart, $node->end - $node->closeStart ) );
			}
		}
		return $out;
	}

	/**
	 * Replace a byte range of the original with markup, e.g. the contents of a
	 * <script> element (start = its tagEnd, end = its closeStart).
	 */
	public function replaceRange( int $start, int $end, string $markup ): void {
		$this->edits[] = array( $start, $end, $markup, 1 );
	}

	public function insert( int $at, string $markup ): void {
		$this->edits[] = array( $at, $at, $markup, 0 );
	}

	public function startTag( Node $n ): string {
		if ( ! isset( $this->mods[ $n->start ] ) ) {
			return substr( $this->html, $n->start, $n->tagEnd - $n->start );
		}
		return $this->tags[ $n->start ] ??= $this->buildTag( $n, $this->mods[ $n->start ]['attrs'] );
	}

	public function output(): string {
		$edits = $this->edits;
		foreach ( $this->mods as $start => $mod ) {
			$edits[] = array( $start, $mod['node']->tagEnd, $this->startTag( $mod['node'] ), 2 );
		}
		if ( ! $edits ) {
			return $this->html;
		}
		// Inserts first, then the widest range: an element's start-tag edit inside
		// a replaced segment is already part of that segment's rendering.
		usort( $edits, static fn( array $a, array $b ): int => $a[0] <=> $b[0] ?: $a[3] <=> $b[3] ?: $b[1] <=> $a[1] );

		$out = '';
		$pos = 0;
		foreach ( $edits as [ $start, $end, $replacement ] ) {
			if ( $start < $pos ) {
				continue;
			}
			$out .= substr( $this->html, $pos, $start - $pos ) . $replacement;
			$pos  = $end;
		}
		return $out . substr( $this->html, $pos );
	}

	private function render( Occurrence $o, string $translated ): string {
		$translated = Placeholders::respace( $o->segment->text, $translated );
		$parts      = preg_split( Placeholders::EDIT_TOKEN, $translated, -1, PREG_SPLIT_DELIM_CAPTURE );
		$count = count( $parts );
		$out   = '';
		for ( $i = 0; $i < $count; $i += 4 ) {
			$out .= $parts[ $i ];
			if ( $i + 3 >= $count ) {
				break;
			}
			$id = $parts[ $i + 2 ];
			if ( isset( Placeholders::FORMAT[ $id ] ) ) {
				$out .= Placeholders::FORMAT[ $id ][ '/' === $parts[ $i + 1 ] ? 1 : 0 ];
				continue;
			}
			if ( 'n' === $id[0] ) {
				$out .= Placeholders::escape( $o->values[ $id ] ?? '' );
				continue;
			}
			$node = $o->placeholders[ $id ] ?? null;
			if ( null === $node ) {
				continue;
			}
			if ( '/' === $parts[ $i + 3 ] ) {
				$out .= $this->renderSlice( $node->start, $node->end );
			} elseif ( '/' === $parts[ $i + 1 ] ) {
				$out .= substr( $this->html, $node->closeStart, $node->end - $node->closeStart );
			} else {
				$out .= $this->startTag( $node );
			}
		}
		return $out;
	}

	/**
	 * Original bytes of [start, end) with any attribute edits applied.
	 */
	private function renderSlice( int $start, int $end ): string {
		if ( ! $this->sorted ) {
			ksort( $this->mods );
			$this->sorted = true;
		}
		$out = '';
		$pos = $start;
		foreach ( $this->mods as $tag_start => $mod ) {
			if ( $tag_start < $pos ) {
				continue;
			}
			if ( $tag_start >= $end ) {
				break;
			}
			$out .= substr( $this->html, $pos, $tag_start - $pos ) . $this->startTag( $mod['node'] );
			$pos  = $mod['node']->tagEnd;
		}
		return $out . substr( $this->html, $pos, $end - $pos );
	}

	/**
	 * @param array<string, string> $attrs
	 */
	private function buildTag( Node $n, array $attrs ): string {
		$splices = array();
		foreach ( $attrs as $name => $plain ) {
			$value = '"' . htmlspecialchars( $plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			$a     = $n->attrs[ $name ] ?? null;
			if ( null === $a ) {
				$at = $n->tagEnd - 1;
				if ( $n->selfClosing && '/' === $this->html[ $at - 1 ] ) {
					--$at;
				}
				$splices[] = array( $at, $at, ' ' . $name . '=' . $value );
			} elseif ( $a['offset'] < 0 ) {
				$splices[] = array( $a['name_offset'], $a['name_offset'] + strlen( $name ), $name . '=' . $value );
			} else {
				$q         = '' === $a['quote'] ? 0 : 1;
				$splices[] = array( $a['offset'] - $q, $a['offset'] + $a['length'] + $q, $value );
			}
		}
		usort( $splices, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		$out = '';
		$pos = $n->start;
		foreach ( $splices as [ $start, $end, $replacement ] ) {
			$out .= substr( $this->html, $pos, $start - $pos ) . $replacement;
			$pos  = $end;
		}
		return $out . substr( $this->html, $pos, $n->tagEnd - $pos );
	}
}
