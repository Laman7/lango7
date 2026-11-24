<?php
/**
 * Right-to-left languages without mirroring the site's design.
 *
 * Setting <html dir="rtl"> flips every flex row, grid, float, margin and
 * menu built for a left-to-right site, which rarely looks right. By default
 * Lango7 keeps the page's layout and sets only the text blocks (paragraphs,
 * headings, list items, table cells, form fields) to right-to-left, so
 * Arabic, Hebrew, Persian and Urdu read correctly inside the same design.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

use Lango7\Language;

final class TextDirection {

	/**
	 * Whether the whole page is mirrored (<html dir="rtl">) for this language.
	 */
	public static function mirror( Language $lang ): bool {
		if ( 'rtl' !== $lang->dir ) {
			return false;
		}
		/**
		 * Mirror the whole layout for a right-to-left language. Off by default:
		 * only the text runs right to left. Return true when the theme has
		 * proper RTL styles (rtl.css) and the page should be flipped.
		 *
		 * @param bool     $mirror
		 * @param Language $lang
		 */
		return (bool) apply_filters( 'lango7_rtl_layout', false, $lang );
	}

	/**
	 * Whether text blocks need the right-to-left style on this page.
	 */
	public static function textOnly( ?Language $lang ): bool {
		return null !== $lang && 'rtl' === $lang->dir && ! self::mirror( $lang );
	}

	/**
	 * Zero-specificity rules (:where) so any direction the theme sets still wins.
	 */
	public static function css(): string {
		return ':where(p,h1,h2,h3,h4,h5,h6,li,dt,dd,blockquote,figcaption,caption,td,th,label,legend,summary,address,input,textarea,select){direction:rtl}'
			. ':where(input[type=email],input[type=url],input[type=tel],input[type=number],code,pre,kbd,samp){direction:ltr}';
	}

	public static function render( ?Language $lang ): void {
		if ( self::textOnly( $lang ) ) {
			echo '<style id="lango7-rtl-text">' . self::css() . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CSS.
		}
	}
}
