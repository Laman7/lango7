<?php
/**
 * Page-cache purging and the lookup cache version.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Cache;

/**
 * A translated page is its own URL to every page cache (WP Rocket, LiteSpeed,
 * Cloudflare APO), so when a translation changes only that URL needs purging.
 * Unknown caches can listen to lango7_purge_url.
 */
final class Purger {

	public const VERSION_OPTION = 'lango7_cache_version';

	/**
	 * @param string[] $urls Absolute URLs.
	 */
	public static function purgeUrls( array $urls ): void {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( ! $urls ) {
			return;
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
			if ( function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $url );
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache( $url );
			}
			if ( function_exists( 'wpsc_delete_url_cache' ) ) {
				wpsc_delete_url_cache( $url );
			}
			do_action( 'lango7_purge_url', $url );
		}
	}

	public static function purgeAll(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			sg_cachepress_purge_everything();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'lango7_purge_all' );
		self::bump();
	}

	/**
	 * Whether a page cache Lango7 clears by itself is active, or another plugin
	 * listens to lango7_purge_url / lango7_purge_all.
	 */
	public static function automatic(): bool {
		return function_exists( 'rocket_clean_domain' ) || defined( 'LSCWP_V' ) || function_exists( 'w3tc_flush_all' )
			|| function_exists( 'sg_cachepress_purge_everything' ) || function_exists( 'wp_cache_clear_cache' )
			|| has_action( 'lango7_purge_url' ) || has_action( 'lango7_purge_all' );
	}

	/**
	 * Shown when a translation run finishes and Lango7 can't clear the page cache itself.
	 */
	public static function reminder(): ?string {
		return self::automatic() ? null : __( 'If your site uses a page cache, your host\'s cache or a CDN such as Cloudflare, clear it now so visitors see the new translations.', 'lango7' );
	}

	/**
	 * Part of every lookup-cache key; bumping it invalidates all cached pages
	 * in the object cache at once.
	 */
	public static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	public static function bump(): void {
		update_option( self::VERSION_OPTION, self::version() + 1, true );
	}
}
