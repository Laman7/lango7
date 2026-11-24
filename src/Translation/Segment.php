<?php
/**
 * A unit of text sent to an engine.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

final class Segment {

	/**
	 * @param string                $hash   sha1(source_lang|text).
	 * @param string                $text   Placeholder text (see Placeholders).
	 * @param string                $kind   text | attr | meta | title.
	 * @param array<string, string> $values Placeholder id => literal value for <nX/> and <gX/>,
	 *                                      so engines that need context can see the real number.
	 */
	public function __construct(
		public readonly string $hash,
		public readonly string $text,
		public readonly string $kind = 'text',
		public readonly array $values = array()
	) {}

	public function chars(): int {
		return mb_strlen( Placeholders::decode( (string) preg_replace( Placeholders::TOKEN, '', $this->text ) ) );
	}

	/**
	 * @param array<string, string> $values
	 */
	public function with( string $text, array $values ): self {
		return new self( $this->hash, $text, $this->kind, $values );
	}
}
