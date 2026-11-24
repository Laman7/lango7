<?php
/**
 * Where a segment sits on the page.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

use Lango7\Translation\Segment;

/**
 * A text occurrence replaces bytes [start, end) of the page. An attribute
 * occurrence ($attr set) rewrites one attribute of $node instead.
 */
final class Occurrence {

	/**
	 * @param array<string, Node>   $placeholders Placeholder id => the element it stands for.
	 * @param array<string, string> $values       This occurrence's numbers (another page may differ).
	 */
	public function __construct(
		public readonly Segment $segment,
		public readonly Node $node,
		public readonly int $start = 0,
		public readonly int $end = 0,
		public readonly string $lead = '',
		public readonly string $trail = '',
		public readonly array $placeholders = array(),
		public readonly array $values = array(),
		public readonly ?string $attr = null
	) {}
}
