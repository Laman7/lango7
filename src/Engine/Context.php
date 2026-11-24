<?php
/**
 * What an engine may use besides the text itself. $brief is background about
 * the site and the language's style (Pro's Site brief), never text to translate.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Engine;

final class Context {

	/**
	 * @param array<int, array{term: string, translation: ?string, case_sensitive: bool}> $glossary
	 */
	public function __construct(
		public readonly array $glossary = array(),
		public readonly string $pageTitle = '',
		public readonly string $url = '',
		public readonly string $tone = '',
		public readonly string $preset = '',
		public readonly string $brief = ''
	) {}
}
