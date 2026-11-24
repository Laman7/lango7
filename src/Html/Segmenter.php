<?php
/**
 * Splits a parsed page into translatable segments.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

use Lango7\Translation\Placeholders;
use Lango7\Translation\Segment;

/**
 * A segment is a run of inline content inside a block: the text of a <p>, an
 * <li>, a <td>, or the loose text in a <div> next to other blocks. Translating
 * whole sentences gives far better output than translating text nodes one by one,
 * so inline elements inside the run become numbered placeholders instead of
 * splitting it.
 *
 * An inline element that contains a block (Bricks and Elementor wrap whole cards
 * in <a>) is treated as a block itself, so its contents segment normally.
 */
final class Segmenter {

	/** Inline elements that become paired placeholders. */
	private const INLINE = array(
		'a'      => true,
		'abbr'   => true,
		'b'      => true,
		'bdi'    => true,
		'bdo'    => true,
		'big'    => true,
		'cite'   => true,
		'data'   => true,
		'del'    => true,
		'dfn'    => true,
		'em'     => true,
		'font'   => true,
		'i'      => true,
		'ins'    => true,
		'mark'   => true,
		'nobr'   => true,
		'q'      => true,
		's'      => true,
		'small'  => true,
		'span'   => true,
		'strike' => true,
		'strong' => true,
		'sub'    => true,
		'sup'    => true,
		'time'   => true,
		'tt'     => true,
		'u'      => true,
		'var'    => true,
	);

	/** Inline elements kept verbatim as void placeholders; their attributes still translate. */
	private const VOID_INLINE = array(
		'audio'    => true,
		'br'       => true,
		'canvas'   => true,
		'embed'    => true,
		'iframe'   => true,
		'img'      => true,
		'input'    => true,
		'meter'    => true,
		'object'   => true,
		'picture'  => true,
		'progress' => true,
		'video'    => true,
		'wbr'      => true,
	);

	/** Inline elements kept verbatim and never translated. */
	private const OPAQUE_INLINE = array(
		'code'     => true,
		'kbd'      => true,
		'math'     => true,
		'noscript' => true,
		'samp'     => true,
		'script'   => true,
		'style'    => true,
		'svg'      => true,
		'textarea' => true,
	);

	/** Never translated, wherever they are. */
	private const NEVER = array(
		'code'     => true,
		'kbd'      => true,
		'math'     => true,
		'noscript' => true,
		'pre'      => true,
		'samp'     => true,
		'script'   => true,
		'style'    => true,
		'svg'      => true,
		'template' => true,
		'textarea' => true,
	);

	private const HEAD_META = array(
		'description'         => true,
		'og:title'            => true,
		'og:description'      => true,
		'twitter:title'       => true,
		'twitter:description' => true,
	);

	private const INPUT_VALUE_TYPES = array(
		'submit' => true,
		'button' => true,
		'reset'  => true,
	);

	private string $html = '';

	/** @var Occurrence[] */
	private array $texts = array();

	/** @var Occurrence[] */
	private array $attributes = array();

	/**
	 * @param string[] $attribute_names Body attributes to translate.
	 */
	public function __construct(
		private string $sourceLang,
		private ?Selector $skip = null,
		private array $attribute_names = array( 'alt', 'title', 'placeholder', 'aria-label' )
	) {}

	/**
	 * @return array{texts: Occurrence[], attributes: Occurrence[]}
	 */
	public function segment( Node $root, string $html ): array {
		$this->html       = $html;
		$this->texts      = array();
		$this->attributes = array();
		$this->walk( $root, false );
		return array(
			'texts'      => $this->texts,
			'attributes' => $this->attributes,
		);
	}

	public function isSkipped( Node $n ): bool {
		if ( Node::ELEMENT !== $n->type ) {
			return false;
		}
		return isset( self::NEVER[ $n->name ] )
			|| ( isset( $n->attrs['translate'] ) && 'no' === strtolower( trim( (string) $n->attr( 'translate' ) ) ) )
			|| isset( $n->attrs['data-lango7-skip'] )
			|| ( isset( $n->attrs['id'] ) && 'wpadminbar' === $n->attr( 'id' ) )
			|| ( isset( $n->attrs['class'] ) && $n->hasClass( 'notranslate' ) )
			|| ( null !== $this->skip && $this->skip->matches( $n ) );
	}

	private function isInline( Node $n ): bool {
		if ( Node::ELEMENT !== $n->type ) {
			return true;
		}
		if ( isset( self::VOID_INLINE[ $n->name ] ) || isset( self::OPAQUE_INLINE[ $n->name ] ) ) {
			return true;
		}
		if ( ! isset( self::INLINE[ $n->name ] ) ) {
			return false;
		}
		if ( $this->isSkipped( $n ) ) {
			return true;
		}
		if ( null === $n->phrasing ) {
			$n->phrasing = true;
			foreach ( $n->children as $child ) {
				if ( ! $this->isInline( $child ) ) {
					$n->phrasing = false;
					break;
				}
			}
		}
		return $n->phrasing;
	}

	private function walk( Node $node, bool $in_head ): void {
		$run = array();
		foreach ( $node->children as $child ) {
			if ( $this->isInline( $child ) ) {
				$run[] = $child;
				continue;
			}
			$this->flush( $run, $node, $in_head );
			$run = array();
			if ( ! $this->isSkipped( $child ) ) {
				$this->collectAttributes( $child, $in_head );
				$this->walk( $child, $in_head || 'head' === $child->name );
			}
		}
		$this->flush( $run, $node, $in_head );
	}

	/**
	 * @param Node[] $run
	 */
	private function flush( array $run, Node $container, bool $in_head ): void {
		$run = $this->trim( $run );
		if ( ! $run ) {
			return;
		}

		// "<li><a href>About</a></li>": the segment is "About", not "<1>About</1>",
		// so it is shared with the same word everywhere else on the site.
		while ( 1 === count( $run ) && isset( self::INLINE[ $run[0]->name ] ) && ! $this->isSkipped( $run[0] ) ) {
			$container = $run[0];
			$this->collectAttributes( $container, $in_head );
			$run = $this->trim( $container->children );
			if ( ! $run ) {
				return;
			}
		}

		$placeholders = array();
		$values       = array();
		$text         = Placeholders::collapse( $this->build( $run, $placeholders, $values, $in_head ) );
		if ( ! Placeholders::isTranslatable( $text ) ) {
			return;
		}

		$first = $run[0];
		$last  = $run[ count( $run ) - 1 ];
		$lead  = '';
		$trail = '';
		if ( Node::TEXT === $first->type ) {
			$raw  = substr( $this->html, $first->start, $first->end - $first->start );
			$lead = substr( $raw, 0, strlen( $raw ) - strlen( ltrim( $raw, " \t\n\r\f" ) ) );
		}
		if ( Node::TEXT === $last->type ) {
			$raw   = substr( $this->html, $last->start, $last->end - $last->start );
			$trail = substr( $raw, strlen( rtrim( $raw, " \t\n\r\f" ) ) );
		}

		$kind          = ( $in_head && 'title' === $container->name ) ? 'title' : 'text';
		$this->texts[] = new Occurrence(
			new Segment( Placeholders::hash( $this->sourceLang, $text ), $text, $kind, $values ),
			$container,
			$first->start,
			$last->end,
			$lead,
			$trail,
			$placeholders,
			$values
		);
	}

	/**
	 * @param Node[]                $nodes
	 * @param array<string, Node>   $placeholders
	 * @param array<string, string> $values
	 */
	private function build( array $nodes, array &$placeholders, array &$values, bool $in_head ): string {
		$out = '';
		foreach ( $nodes as $n ) {
			if ( Node::TEXT === $n->type ) {
				$out .= Placeholders::numbers( Placeholders::decode( substr( $this->html, $n->start, $n->end - $n->start ) ), $values );
				continue;
			}
			$id                  = (string) ( count( $placeholders ) + 1 );
			$placeholders[ $id ] = $n;
			if ( Node::ELEMENT === $n->type && isset( self::INLINE[ $n->name ] ) && ! $this->isSkipped( $n ) ) {
				$this->collectAttributes( $n, $in_head );
				$out .= '<' . $id . '>' . $this->build( $n->children, $placeholders, $values, $in_head ) . '</' . $id . '>';
				continue;
			}
			if ( Node::ELEMENT === $n->type && isset( self::VOID_INLINE[ $n->name ] ) && ! $this->isSkipped( $n ) ) {
				$this->collectAttributesDeep( $n, $in_head );
			}
			$out .= '<' . $id . '/>';
		}
		return $out;
	}

	/**
	 * Drop whitespace-only text and comments from both ends of a run.
	 *
	 * @param Node[] $run
	 * @return Node[]
	 */
	private function trim( array $run ): array {
		$run = array_values( $run );
		while ( $run && $this->isBlank( $run[0] ) ) {
			array_shift( $run );
		}
		while ( $run && $this->isBlank( $run[ count( $run ) - 1 ] ) ) {
			array_pop( $run );
		}
		return $run;
	}

	private function isBlank( Node $n ): bool {
		if ( Node::COMMENT === $n->type ) {
			return true;
		}
		return Node::TEXT === $n->type && '' === trim( substr( $this->html, $n->start, $n->end - $n->start ), " \t\n\r\f" );
	}

	private function collectAttributesDeep( Node $n, bool $in_head ): void {
		$this->collectAttributes( $n, $in_head );
		foreach ( $n->children as $child ) {
			if ( Node::ELEMENT === $child->type && ! $this->isSkipped( $child ) ) {
				$this->collectAttributesDeep( $child, $in_head );
			}
		}
	}

	private function collectAttributes( Node $el, bool $in_head ): void {
		if ( ! $el->attrs ) {
			return;
		}
		if ( $in_head ) {
			if ( 'meta' === $el->name ) {
				$key = strtolower( (string) ( $el->attr( 'name' ) ?? $el->attr( 'property' ) ) );
				if ( isset( self::HEAD_META[ $key ] ) ) {
					$this->addAttribute( $el, 'content', 'meta' );
				}
			}
			return;
		}
		foreach ( $this->attribute_names as $name ) {
			if ( isset( $el->attrs[ $name ] ) ) {
				$this->addAttribute( $el, $name, 'attr' );
			}
		}
		if ( 'input' === $el->name && isset( $el->attrs['value'] ) && isset( self::INPUT_VALUE_TYPES[ strtolower( (string) $el->attr( 'type' ) ) ] ) ) {
			$this->addAttribute( $el, 'value', 'attr' );
		}
	}

	private function addAttribute( Node $el, string $attr, string $kind ): void {
		$values = array();
		$text   = Placeholders::collapse( Placeholders::numbers( (string) $el->attr( $attr ), $values ) );
		if ( ! Placeholders::isTranslatable( $text ) ) {
			return;
		}
		$this->attributes[] = new Occurrence(
			new Segment( Placeholders::hash( $this->sourceLang, $text ), $text, $kind, $values ),
			$el,
			values: $values,
			attr: $attr
		);
	}
}
