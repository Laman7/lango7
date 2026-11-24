<?php
/**
 * Glossary terms.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

/**
 * Terms are protected by turning them into <gN/> placeholders before the text
 * goes to the engine, then swapping in the fixed translation (or the term itself,
 * for "do not translate") afterwards, so the engine can never alter a brand name.
 *
 * Lango7 protects the site's own name automatically. Extensions add more terms
 * through the lango7_glossary_terms filter.
 */
final class Glossary {

	/**
	 * The site name, as protected automatically ('' when the site has none).
	 */
	public static function siteName(): string {
		$name = trim( wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ) );
		/**
		 * Whether the site name is kept as it is in every language.
		 *
		 * @param bool   $protect
		 * @param string $name
		 */
		return mb_strlen( $name ) >= 2 && apply_filters( 'lango7_protect_site_name', true, $name ) ? $name : '';
	}

	/**
	 * Terms for one language: the extensions' terms first (language-specific
	 * ones win), then the site name unless a term already covers it.
	 *
	 * @return array<int, array{term: string, translation: ?string, case_sensitive: bool}>
	 */
	public function forLanguage( string $lang ): array {
		/**
		 * Glossary terms for a language.
		 *
		 * @param array<int, array{term: string, translation: ?string, case_sensitive: bool}> $terms
		 * @param string                                                                       $lang
		 */
		$terms = array_values( (array) apply_filters( 'lango7_glossary_terms', array(), $lang ) );
		$name  = self::siteName();
		if ( '' !== $name && ! in_array( mb_strtolower( $name ), array_map( static fn( array $t ): string => mb_strtolower( (string) $t['term'] ), $terms ), true ) ) {
			$terms[] = array(
				'term'           => $name,
				'translation'    => null,
				'case_sensitive' => true,
			);
		}
		return $terms;
	}

	/**
	 * @param array<int, array{term: string, translation: ?string, case_sensitive: bool}> $terms
	 * @return array{0: Segment, 1: array<string, string>} The segment to send and gN => replacement text.
	 */
	public static function wrap( Segment $s, array $terms ): array {
		if ( ! $terms ) {
			return array( $s, array() );
		}
		usort( $terms, static fn( array $a, array $b ): int => mb_strlen( $b['term'] ) <=> mb_strlen( $a['term'] ) );
		$alternatives = array();
		foreach ( $terms as $t ) {
			$quoted         = preg_quote( Placeholders::escape( $t['term'] ), '/' );
			$alternatives[] = $t['case_sensitive'] ? $quoted : '(?i:' . $quoted . ')';
		}
		$regex = '/(?<![\p{L}\p{N}])(?:' . implode( '|', $alternatives ) . ')(?![\p{L}\p{N}])/u';

		$values       = $s->values;
		$replacements = array();
		$parts        = preg_split( Placeholders::TOKEN, $s->text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$count        = count( $parts );
		$out          = '';
		for ( $i = 0; $i < $count; $i += 4 ) {
			$out .= (string) preg_replace_callback(
				$regex,
				static function ( array $m ) use ( $terms, &$values, &$replacements ): string {
					$plain = Placeholders::decode( $m[0] );
					$id    = 'g' . ( count( $replacements ) + 1 );
					$fixed = null;
					foreach ( $terms as $t ) {
						if ( $t['case_sensitive'] ? $t['term'] === $plain : 0 === strcasecmp( $t['term'], $plain ) ) {
							$fixed = $t['translation'];
							break;
						}
					}
					$values[ $id ]       = $plain;
					$replacements[ $id ] = Placeholders::escape( $fixed ?? $plain );
					return '<' . $id . '/>';
				},
				$parts[ $i ]
			);
			if ( $i + 3 < $count ) {
				$out .= '<' . $parts[ $i + 1 ] . $parts[ $i + 2 ] . $parts[ $i + 3 ] . '>';
			}
		}
		return array( $s->with( $out, $values ), $replacements );
	}

	/**
	 * Swap <gN/> back for the fixed text. Returns null when the engine dropped or
	 * duplicated a term placeholder.
	 *
	 * @param array<string, string> $replacements
	 */
	public static function unwrap( string $text, array $replacements ): ?string {
		foreach ( $replacements as $id => $replacement ) {
			if ( 1 !== substr_count( $text, '<' . $id . '/>' ) ) {
				return null;
			}
			$text = str_replace( '<' . $id . '/>', $replacement, $text );
		}
		return $text;
	}
}
