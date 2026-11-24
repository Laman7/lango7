<?php
/**
 * Minimal CSS selector matching for the "never translate" setting.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

/**
 * Supports compound selectors without combinators: tag, #id, .class (any
 * number), [attr] and [attr=value], comma-separated. That covers what people
 * paste into an exclusion box (".site-logo", "#cookie-bar", "[data-price]").
 */
final class Selector {

	/** @var array<int, array{tag: string, id: string, classes: string[], attrs: array<string, ?string>}> */
	private array $parts = array();

	public function __construct( string $list ) {
		foreach ( preg_split( '/[,\n]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $raw ) {
			$raw = trim( $raw );
			if ( '' === $raw || preg_match( '/[\s>+~]/', $raw ) ) {
				continue; // Combinators are not supported; ignore rather than mis-match.
			}
			$part = array(
				'tag'     => '',
				'id'      => '',
				'classes' => array(),
				'attrs'   => array(),
			);
			preg_match_all( '/\[([^\]=]+)(?:=["\']?([^"\'\]]*)["\']?)?\]/', $raw, $am, PREG_SET_ORDER );
			foreach ( $am as $a ) {
				$part['attrs'][ strtolower( trim( $a[1] ) ) ] = isset( $a[2] ) ? $a[2] : null;
			}
			$raw = (string) preg_replace( '/\[[^\]]*\]/', '', $raw );
			preg_match_all( '/([#.]?)([^#.]+)/', $raw, $tm, PREG_SET_ORDER );
			foreach ( $tm as $t ) {
				if ( '#' === $t[1] ) {
					$part['id'] = $t[2];
				} elseif ( '.' === $t[1] ) {
					$part['classes'][] = $t[2];
				} else {
					$part['tag'] = strtolower( $t[2] );
				}
			}
			$this->parts[] = $part;
		}
	}

	public function isEmpty(): bool {
		return ! $this->parts;
	}

	public function matches( Node $node ): bool {
		foreach ( $this->parts as $p ) {
			if ( '' !== $p['tag'] && '*' !== $p['tag'] && $p['tag'] !== $node->name ) {
				continue;
			}
			if ( '' !== $p['id'] && $node->attr( 'id' ) !== $p['id'] ) {
				continue;
			}
			foreach ( $p['classes'] as $class_name ) {
				if ( ! $node->hasClass( $class_name ) ) {
					continue 2;
				}
			}
			foreach ( $p['attrs'] as $name => $value ) {
				if ( ! $node->hasAttr( $name ) || ( null !== $value && $node->attr( $name ) !== $value ) ) {
					continue 2;
				}
			}
			return true;
		}
		return false;
	}
}
