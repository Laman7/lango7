<?php
/**
 * Facts about the request being translated.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

final class PageContext {

	/**
	 * @param string $path   Source path, no prefix or query ("/contact/").
	 * @param string $url    Translated URL, for logs and engine context.
	 * @param bool   $engine Whether new text may be translated (now or queued). False for
	 *                       search results, 404s, unknown query strings and the like, which
	 *                       can carry visitor-supplied text.
	 * @param float  $budget Seconds of engine time this request may spend.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $url,
		public readonly bool $engine,
		public readonly float $budget,
		public readonly bool $is_404 = false
	) {}
}
