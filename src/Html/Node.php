<?php
/**
 * A node in the lightweight document tree.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

/**
 * Nodes carry byte offsets into the original HTML instead of their own copy of
 * the markup. Translation is applied by splicing those ranges, so every byte the
 * plugin does not translate is sent out exactly as WordPress rendered it.
 */
final class Node {

	public const ELEMENT = 1;
	public const TEXT    = 3;
	public const COMMENT = 8;

	/** Lower-case tag name, '' for text and comments. */
	public string $name = '';

	/** Offset just after the start tag's '>' (elements only). */
	public int $tagEnd = 0;

	/** Offset of the end tag's '<', or $end when the element was closed implicitly. */
	public int $closeStart = 0;

	/**
	 * Attributes as written. Offset -1 means the attribute has no value.
	 *
	 * @var array<string, array{value: string, offset: int, length: int, quote: string, name_offset: int}>
	 */
	public array $attrs = array();

	/** @var Node[] */
	public array $children = array();

	public ?Node $parent = null;

	/** Written as <x/>; only meaningful inside SVG/MathML. */
	public bool $selfClosing = false;

	/** Cached result of Segmenter::isInline() for inline elements. */
	public ?bool $phrasing = null;

	public function __construct(
		public int $type,
		public int $start,
		public int $end = 0
	) {}

	/**
	 * Decoded attribute value, or null when absent.
	 */
	public function attr( string $name ): ?string {
		if ( ! isset( $this->attrs[ $name ] ) ) {
			return null;
		}
		return html_entity_decode( $this->attrs[ $name ]['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public function hasAttr( string $name ): bool {
		return isset( $this->attrs[ $name ] );
	}

	public function hasClass( string $class_name ): bool {
		$classes = $this->attr( 'class' );
		if ( null === $classes || '' === $classes ) {
			return false;
		}
		return in_array( $class_name, preg_split( '/[ \t\n\r\f]+/', $classes, -1, PREG_SPLIT_NO_EMPTY ), true );
	}

	public function isElement( string ...$names ): bool {
		return self::ELEMENT === $this->type && ( ! $names || in_array( $this->name, $names, true ) );
	}
}
