<?php
/**
 * Pages that are never translated: the paths in Settings → "Do not translate
 * URLs", plus single posts and pages switched off with "Don't translate this
 * page". A switched-off post is stored on the post (meta) and its current path
 * is kept in one option, refreshed when the post is saved, so every check
 * reads one cached option instead of querying posts, and a changed slug is
 * followed.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

use Lango7\Cache\Purger;
use Lango7\Html\UrlMatcher;

final class Exclusions {

	public const META = '_lango7_no_translate';

	/** post ID => path relative to the home folder. */
	public const OPTION = 'lango7_no_translate_posts';

	private const ACTION = 'lango7_no_translate';

	/** @var string[]|null */
	private static ?array $patterns = null;

	public static function register(): void {
		add_action( 'save_post', array( self::class, 'refresh' ), 20 );
		add_action( 'trashed_post', array( self::class, 'refresh' ) );
		add_action( 'deleted_post', array( self::class, 'forget' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION . '_path', array( self::class, 'handlePath' ) );
		add_action( 'lango7_settings_saved', static function (): void {
			self::$patterns = null;
		} );
	}

	/**
	 * Every excluded path pattern: Settings lines, then switched-off posts.
	 *
	 * @return string[]
	 */
	public static function patterns(): array {
		if ( null === self::$patterns ) {
			self::$patterns = array_values(
				array_unique(
					array_merge(
						UrlMatcher::lines( (string) Plugin::instance()->settings()->get( 'exclude_urls' ) ),
						array_map( 'strval', array_values( (array) get_option( self::OPTION, array() ) ) )
					)
				)
			);
		}
		return self::$patterns;
	}

	/** Whether a path relative to the home folder is in the Settings list (not a post switch). */
	public static function inSettings( string $rel ): bool {
		return UrlMatcher::matches( $rel, UrlMatcher::lines( (string) Plugin::instance()->settings()->get( 'exclude_urls' ) ) );
	}

	public static function isPost( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::META, true );
	}

	/**
	 * Switch translation off (true) or back on (false) for one post.
	 */
	public static function set( int $post_id, bool $off ): void {
		$before = self::relPath( $post_id );
		if ( $off ) {
			update_post_meta( $post_id, self::META, 1 );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		self::refresh( $post_id );
		self::purge( $before );
	}

	/**
	 * Keep the stored path in step with the post: its slug, status and switch.
	 */
	public static function refresh( int $post_id ): void {
		$all  = (array) get_option( self::OPTION, array() );
		$path = self::isPost( $post_id ) && 'publish' === get_post_status( $post_id ) ? self::relPath( $post_id ) : null;
		$old  = $all[ $post_id ] ?? null;
		if ( null === $path ) {
			unset( $all[ $post_id ] );
		} else {
			$all[ $post_id ] = $path;
		}
		if ( $old !== $path ) {
			update_option( self::OPTION, $all, true );
			self::$patterns = null;
			self::changed();
		}
	}

	public static function forget( int $post_id ): void {
		$all = (array) get_option( self::OPTION, array() );
		if ( isset( $all[ $post_id ] ) ) {
			unset( $all[ $post_id ] );
			update_option( self::OPTION, $all, true );
			self::$patterns = null;
		}
	}

	/** The post's path relative to the home folder, or null (plain ?p= links, drafts). */
	public static function relPath( int $post_id ): ?string {
		$url  = (string) get_permalink( $post_id );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path || null !== wp_parse_url( $url, PHP_URL_QUERY ) ) {
			return null;
		}
		return UrlMatcher::relative( $path, Plugin::instance()->router()->homePath() );
	}

	/**
	 * Link that switches a post off or on, then returns to $back.
	 */
	public static function url( int $post_id, bool $off, string $back = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post_id,
					'off'    => $off ? 1 : 0,
					'back'   => rawurlencode( $back ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post_id
		);
	}

	public static function handle(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $post_id );
		if ( ! $post_id || ! current_user_can( Plugin::CAPABILITY ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lango7' ), 403 );
		}
		self::set( $post_id, ! empty( $_GET['off'] ) );
		$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( (string) $_GET['back'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw.
		wp_safe_redirect( '' !== $back ? $back : admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) ) );
		exit;
	}

	/**
	 * What was excluded changed: cached reports and counts start over.
	 * (Not lango7_settings_saved: its listeners expect the posted settings.)
	 */
	private static function changed(): void {
		self::$patterns = null;
		Admin\PendingBar::flush();
		Admin\UntranslatedReport::flush();
		do_action( 'lango7_exclusions_changed' );
	}

	/** Whether the Settings list has this exact path (no wildcard), so it can be removed again. */
	public static function inSettingsExactly( string $rel ): bool {
		foreach ( UrlMatcher::lines( (string) Plugin::instance()->settings()->get( 'exclude_urls' ) ) as $line ) {
			if ( ! str_ends_with( $line, '*' ) && UrlMatcher::matches( $rel, array( $line ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Link that adds a path (an archive, the shop page…) to the Settings list or removes it.
	 */
	public static function pathUrl( string $rel, bool $off, string $back = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION . '_path',
					'path'   => rawurlencode( $rel ),
					'off'    => $off ? 1 : 0,
					'back'   => rawurlencode( $back ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_path_' . md5( $rel )
		);
	}

	public static function handlePath(): void {
		$rel = isset( $_GET['path'] ) ? '/' . ltrim( sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ), '/' ) : '';
		check_admin_referer( self::ACTION . '_path_' . md5( $rel ) );
		if ( '' === $rel || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lango7' ), 403 );
		}
		$settings = Plugin::instance()->settings();
		$lines    = UrlMatcher::lines( (string) $settings->get( 'exclude_urls' ) );
		if ( ! empty( $_GET['off'] ) ) {
			if ( ! UrlMatcher::matches( $rel, $lines ) ) {
				$lines[] = $rel;
			}
		} else {
			$lines = array_values( array_filter( $lines, static fn( string $l ): bool => str_ends_with( $l, '*' ) || ! UrlMatcher::matches( $rel, array( $l ) ) ) );
		}
		$settings->update( array( 'exclude_urls' => implode( "\n", $lines ) ) );
		self::changed();
		self::purge( $rel );
		$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( (string) $_GET['back'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw.
		wp_safe_redirect( '' !== $back ? $back : home_url( '/' ) );
		exit;
	}

	/**
	 * Translated pages of that path are cached as translated: clear them.
	 */
	private static function purge( ?string $rel ): void {
		if ( null === $rel || ! class_exists( Purger::class ) ) {
			return;
		}
		$plugin = Plugin::instance();
		$path   = rtrim( $plugin->router()->homePath(), '/' ) . $rel;
		Purger::bump();
		Purger::purgeUrls( array_map( static fn( Language $l ): string => $plugin->router()->urlFor( $l, $path ), $plugin->languages()->enabled() ) );
	}
}
