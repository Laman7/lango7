<?php
/**
 * Where "Translate now" leads: Free Translate for one page or for all waiting
 * text. Pro points these at its server engines instead.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

final class TranslateNow {

	/**
	 * Link that translates one page now, or null when the current user can't.
	 *
	 * @param int    $post_id 0 when the page is not a post (an archive, the blog page).
	 * @param string $path    Source path, e.g. "/contact/".
	 */
	public static function pageUrl( int $post_id, string $path ): ?string {
		$url = current_user_can( 'manage_options' )
			? add_query_arg( 'path', rawurlencode( $path ), admin_url( 'admin.php?page=' . ChromePage::SLUG ) )
			: null;

		/**
		 * Link behind "Translate now" for one page (null hides it).
		 *
		 * @param string|null $url
		 * @param int         $post_id
		 * @param string      $path
		 */
		$url = apply_filters( 'lango7_translate_now_url', $url, $post_id, $path );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Link that translates everything waiting, or null when the current user can't.
	 */
	public static function allUrl(): ?string {
		$url = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . ChromePage::SLUG . '&scope=pending' ) : null;

		/**
		 * Link behind "Translate everything untranslated…" (null hides it).
		 *
		 * @param string|null $url
		 */
		$url = apply_filters( 'lango7_translate_all_url', $url );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Short status that replaces "Translate now" while a page is being translated,
	 * e.g. "Translating…" (Pro's background runs).
	 */
	public static function status( int $post_id ): string {
		/**
		 * @param string $status
		 * @param int    $post_id
		 */
		return (string) apply_filters( 'lango7_translate_now_status', '', $post_id );
	}
}
