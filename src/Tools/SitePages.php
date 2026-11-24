<?php
/**
 * The site's pages as visitors get them: the list of public addresses and a
 * loopback fetch of one page in the original language. Used by Tools.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Tools;

use Lango7\Html\UrlMatcher;
use Lango7\Plugin;

final class SitePages {

	public const MAX = 5000;

	public function __construct( private Plugin $lango7 ) {}

	/**
	 * Home plus every published post of the types in the core sitemap.
	 *
	 * @return string[] Source paths.
	 */
	public function paths(): array {
		$paths = array( $this->lango7->router()->homePath() . '/' );
		$types = array_keys( ( new \WP_Sitemaps_Posts() )->get_object_subtypes() );
		if ( ! $types ) {
			return $paths;
		}
		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'numberposts'      => self::MAX,
				'fields'           => 'ids',
				'orderby'          => array(
					'post_type'  => 'ASC',
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		foreach ( $ids as $id ) {
			$path = $this->localPath( (string) get_permalink( (int) $id ) );
			if ( null !== $path ) {
				$paths[] = $path;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * The page in the original language.
	 *
	 * @return string|\WP_Error
	 */
	public function fetch( string $path ) {
		$router = $this->lango7->router();
		$url    = $router->urlFor( null, $path );
		// Follow redirects on this site only, such as WordPress adding or
		// removing a trailing slash; a redirect elsewhere still fails.
		for ( $hop = 0; ; ++$hop ) {
			$res = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
					'redirection' => 0,
					'user-agent'  => 'Lango7 site scan; ' . home_url( '/' ),
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopbacks.
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$next = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $hop >= 3 || $code < 300 || $code > 399 || '' === $next ) {
				break;
			}
			$next = \WP_Http::make_absolute_url( $next, $url );
			if ( 0 !== strcasecmp( (string) wp_parse_url( $next, PHP_URL_HOST ), (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
				break;
			}
			$url = $next;
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'lango7_http', sprintf( __( 'The page answered with HTTP %d.', 'lango7' ), $code ) );
		}
		$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
		if ( '' !== $type && false === stripos( $type, 'text/html' ) ) {
			return new \WP_Error( 'lango7_type', __( 'Not an HTML page.', 'lango7' ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Source path of a URL on this site, or null (other host, query string,
	 * excluded, or already under a language prefix).
	 */
	public function localPath( string $url ): ?string {
		$router = $this->lango7->router();
		$parts  = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) || isset( $parts['query'] ) ) {
			return null;
		}
		$host = ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 !== strcasecmp( $host, $router->host() ) ) {
			return null;
		}
		$rel = UrlMatcher::relative( (string) $parts['path'], $router->homePath() );
		if ( null === $rel ) {
			return null;
		}
		$first    = explode( '/', trim( $rel, '/' ) )[0];
		$excluded = \Lango7\Exclusions::patterns();
		if ( in_array( $first, $this->lango7->languages()->prefixes(), true ) || UrlMatcher::matches( $rel, $excluded ) ) {
			return null;
		}
		return (string) $parts['path'];
	}
}
