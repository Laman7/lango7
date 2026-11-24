<?php
/**
 * Optional for engines: which languages they can translate.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Engine;

/**
 * An engine that cannot translate every language implements this, so Lango7
 * can grey it out for those languages and refuse to save them. Engines
 * without it are taken to translate every language.
 */
interface LanguageSupport {

	/**
	 * Whether the engine can translate into (or, with $source, from) a language.
	 *
	 * @param string $code   Lango7 language code, e.g. es-MX or zh-Hans.
	 * @param bool   $source True to ask about the site language instead.
	 */
	public function supports( string $code, bool $source = false ): bool;
}
