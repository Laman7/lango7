<?php
/**
 * The placeholder text format shared by the segmenter, engines and editor.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

/**
 * Segment text is plain text with only "&", "<" and ">" escaped, plus
 * placeholder tags standing in for markup and values the engine must not change:
 *
 *   <1>…</1>  an inline element (a, strong, span…); its attributes stay on the page
 *   <2/>      a void or opaque element (br, img, svg, code…), kept verbatim
 *   <n1/>     a number inside a sentence
 *   <g1/>     a glossary term (only while the text is at the engine)
 *
 * A human edit may also contain formatting of its own, which renders as plain
 * tags with no attributes: <b>…</b> (strong), <i>…</i> (em) and <br/>. Engines
 * never see or return these; see EDIT_TOKEN and validateEdit().
 *
 * Because attributes and numbers never reach the stored text, "Call <1>us</1>"
 * is one string whatever the link points at, and "Save <n1/>%" is one string for
 * every discount.
 */
final class Placeholders {

	public const TOKEN = '/<(\/?)([ng]?\d+)(\/?)>/';

	/** TOKEN plus the formatting an edit may add: <b>, <i>, <br/>. */
	public const EDIT_TOKEN = '/<(\/?)([ng]?\d+|b|i|br)(\/?)>/';

	/** HTML for the formatting tokens. */
	public const FORMAT = array(
		'b'  => array( '<strong>', '</strong>' ),
		'i'  => array( '<em>', '</em>' ),
		'br' => array( '<br>', '' ),
	);

	public static function escape( string $plain ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $plain );
	}

	public static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Put back a space an engine dropped beside a placeholder: where the source
	 * has a space between a placeholder and a word ("<n1/> words"), a translation
	 * that joins them ("<n1/>perkataan") gets the space again. Left alone when the
	 * translation has no spaces at all (Chinese, Japanese, Thai…).
	 */
	public static function respace( string $source, string $translated ): string {
		if ( ! str_contains( (string) preg_replace( self::EDIT_TOKEN, '', $translated ), ' ' ) ) {
			return $translated;
		}
		$before = array();
		$after  = array();
		if ( preg_match_all( self::TOKEN, $source, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$token = $hit[0];
				$at    = (int) $hit[1];
				$end   = $at + strlen( $token );
				if ( $at > 0 && ' ' === $source[ $at - 1 ] ) {
					$before[ $token ] = true;
				}
				if ( $end < strlen( $source ) && ' ' === $source[ $end ] ) {
					$after[ $token ] = true;
				}
			}
		}
		if ( ! $before && ! $after ) {
			return $translated;
		}
		$parts = preg_split( '/(<\/?[ng]?\d+\/?>)/', $translated, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$out .= $part;
				continue;
			}
			// Text between tokens: the token before it is $parts[$i-1], after it $parts[$i+1].
			if ( $i > 0 && isset( $after[ $parts[ $i - 1 ] ] ) && preg_match( '/^[\p{L}\p{N}]/u', $part ) ) {
				$part = ' ' . $part;
			}
			if ( isset( $parts[ $i + 1 ] ) && isset( $before[ $parts[ $i + 1 ] ] ) && preg_match( '/[\p{L}\p{N}]$/u', $part ) ) {
				$part .= ' ';
			}
			$out .= $part;
		}
		return $out;
	}

	/**
	 * Collapse ASCII whitespace and trim. Non-breaking spaces are content.
	 */
	public static function collapse( string $text ): string {
		return trim( (string) preg_replace( '/[ \t\n\r\f]+/', ' ', $text ), " \t\n\r\f" );
	}

	/**
	 * Replace numbers inside plain text with <nX/> placeholders.
	 *
	 * @param array<string, string> $values Receives id => original number.
	 */
	public static function numbers( string $plain, array &$values ): string {
		$out    = '';
		$offset = 0;
		if ( ! preg_match_all( '/(?<![\p{L}\p{N}])\p{Nd}+(?:[.,:]\p{Nd}+)*(?![\p{L}\p{N}])/u', $plain, $m, PREG_OFFSET_CAPTURE ) ) {
			return self::escape( $plain );
		}
		foreach ( $m[0] as $match ) {
			$id            = 'n' . ( count( $values ) + 1 );
			$values[ $id ] = $match[0];
			$out          .= self::escape( substr( $plain, $offset, $match[1] - $offset ) ) . '<' . $id . '/>';
			$offset        = $match[1] + strlen( $match[0] );
		}
		return $out . self::escape( substr( $plain, $offset ) );
	}

	/**
	 * Whether a segment is worth sending to an engine: it has letters left after
	 * removing placeholders, and it is not just an email address or URL.
	 */
	public static function isTranslatable( string $text ): bool {
		$plain = trim( self::decode( (string) preg_replace( self::TOKEN, ' ', $text ) ) );
		if ( '' === $plain || ! preg_match( '/\p{L}/u', $plain ) ) {
			return false;
		}
		if ( preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $plain ) ) {
			return false;
		}
		if ( preg_match( '#^(?:https?://|www\.)\S+$#iu', $plain ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Normalise text that came back from an engine or an editor: placeholders are
	 * kept, everything else is decoded and re-escaped. Returns null when the text
	 * contains markup that is not a placeholder, which means the engine invented
	 * or broke a tag. With $edit, the formatting tokens of an edit are kept too.
	 */
	public static function clean( string $text, bool $edit = false ): ?string {
		$parts = preg_split( $edit ? self::EDIT_TOKEN : self::TOKEN, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$count = count( $parts );
		for ( $i = 0; $i < $count; $i += 4 ) {
			if ( preg_match( '/<[a-zA-Z\/!?]/', $parts[ $i ] ) ) {
				return null;
			}
			$out .= self::escape( self::decode( $parts[ $i ] ) );
			if ( $i + 3 < $count ) {
				$out .= '<' . $parts[ $i + 1 ] . $parts[ $i + 2 ] . $parts[ $i + 3 ] . '>';
			}
		}
		return $out;
	}

	/**
	 * Every placeholder in the source appears exactly once in the translation,
	 * properly nested, with nothing extra, and the translation is not empty.
	 */
	public static function validate( string $source, string $translated ): bool {
		if ( '' === trim( (string) preg_replace( self::TOKEN, '', $translated ) ) && '' !== trim( (string) preg_replace( self::TOKEN, '', $source ) ) ) {
			return false;
		}

		$paired = array();
		$void   = array();
		preg_match_all( self::TOKEN, $source, $m, PREG_SET_ORDER );
		foreach ( $m as $t ) {
			if ( '/' === $t[3] ) {
				$void[ $t[2] ] = true;
			} elseif ( '' === $t[1] ) {
				$paired[ $t[2] ] = true;
			}
		}

		$seen  = array();
		$stack = array();
		preg_match_all( self::TOKEN, $translated, $m, PREG_SET_ORDER );
		foreach ( $m as $t ) {
			$id = $t[2];
			if ( '/' === $t[3] ) {
				if ( '' !== $t[1] || ! isset( $void[ $id ] ) || isset( $seen[ 'v' . $id ] ) ) {
					return false;
				}
				$seen[ 'v' . $id ] = true;
			} elseif ( '' === $t[1] ) {
				if ( ! isset( $paired[ $id ] ) || isset( $seen[ 'p' . $id ] ) ) {
					return false;
				}
				$seen[ 'p' . $id ] = true;
				$stack[]           = $id;
			} elseif ( array_pop( $stack ) !== $id ) {
				return false;
			}
		}

		return ! $stack && count( $seen ) === count( $paired ) + count( $void );
	}

	/**
	 * Rules for a human edit, looser than validate() so a sentence can be
	 * restyled: a placeholder of the source may be left out (a pair as a whole),
	 * but none may appear twice or be invented; <b>…</b>, <i>…</i> and <br/> may
	 * be added any number of times. Everything must nest properly and some text
	 * must remain. The editor itself keeps links, images, buttons and numbers
	 * (see editor-core.js), since what a placeholder stands for is only known on
	 * the page.
	 */
	public static function validateEdit( string $source, string $translated ): bool {
		if ( '' === trim( (string) preg_replace( self::EDIT_TOKEN, '', $translated ) ) && '' !== trim( (string) preg_replace( self::TOKEN, '', $source ) ) ) {
			return false;
		}
		$paired = array();
		$void   = array();
		preg_match_all( self::TOKEN, $source, $m, PREG_SET_ORDER );
		foreach ( $m as $t ) {
			if ( '/' === $t[3] ) {
				$void[ $t[2] ] = true;
			} elseif ( '' === $t[1] ) {
				$paired[ $t[2] ] = true;
			}
		}

		$seen  = array();
		$stack = array();
		preg_match_all( self::EDIT_TOKEN, $translated, $m, PREG_SET_ORDER );
		foreach ( $m as $t ) {
			$id     = $t[2];
			$format = isset( self::FORMAT[ $id ] );
			if ( 'br' === $id ) {
				if ( '' !== $t[1] || '/' !== $t[3] ) {
					return false;
				}
				continue;
			}
			if ( '/' === $t[3] ) {
				if ( $format || '' !== $t[1] || ! isset( $void[ $id ] ) || isset( $seen[ 'v' . $id ] ) ) {
					return false;
				}
				$seen[ 'v' . $id ] = true;
			} elseif ( '' === $t[1] ) {
				if ( ! $format && ( ! isset( $paired[ $id ] ) || isset( $seen[ 'p' . $id ] ) ) ) {
					return false;
				}
				if ( ! $format ) {
					$seen[ 'p' . $id ] = true;
				}
				$stack[] = $id;
			} elseif ( array_pop( $stack ) !== $id ) {
				return false;
			}
		}
		return ! $stack;
	}

	/**
	 * Plain text for attributes: numbers restored, other placeholders dropped.
	 *
	 * @param array<string, string> $values
	 */
	public static function toPlain( string $text, array $values ): string {
		$text = (string) preg_replace_callback(
			self::EDIT_TOKEN,
			static fn( array $m ): string => isset( $values[ $m[2] ] ) ? self::escape( $values[ $m[2] ] ) : ( 'br' === $m[2] ? ' ' : '' ),
			$text
		);
		return self::decode( $text );
	}

	public static function hash( string $source_lang, string $text ): string {
		return sha1( $source_lang . '|' . $text );
	}
}
