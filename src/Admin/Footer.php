<?php
/**
 * The version and a help link at the bottom of Lango7's own admin screens.
 *
 * Only on Lango7 screens, never elsewhere in wp-admin or on the public site
 * (wordpress.org guideline 10: no credit links on the front end).
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

final class Footer {

	public const DOCS = 'https://lango7.com/docs/';

	public static function register(): void {
		add_filter( 'admin_footer_text', array( self::class, 'text' ), 20 );
	}

	/**
	 * @param string|mixed $text
	 * @return string|mixed
	 */
	public static function text( $text ) {
		if ( ! self::ours() ) {
			return $text;
		}
		return implode( ' · ', array_map( 'esc_html', self::versions() ) )
			. ' · <a href="' . esc_url( self::DOCS ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help and docs', 'lango7' ) . '</a>';
	}

	/**
	 * "Lango7 1.07", plus any add-on's own line ("Pro 1.07").
	 *
	 * @return string[]
	 */
	public static function versions(): array {
		/* translators: %s: plugin version. */
		$items = array( sprintf( __( 'Lango7 %s', 'lango7' ), LANGO7_VERSION ) );
		/**
		 * Lines added to the version in Lango7's admin footer.
		 *
		 * @param string[] $items
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'lango7_admin_footer_versions', $items ) ) ) );
	}

	private static function ours(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && str_contains( (string) $screen->id, 'lango7' );
	}
}
