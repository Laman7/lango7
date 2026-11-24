<?php
/**
 * Keeps internal links inside the current language.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

/**
 * "/about/" on a /ms/ page becomes "/ms/about/". Links to files, wp-admin, the
 * REST API, other hosts, anchors and excluded URLs are left alone, and so is a
 * link that already carries a language prefix.
 */
final class LinkRewriter {

	private const PAGE_EXTENSIONS = array(
		'htm'  => true,
		'html' => true,
		'php'  => true,
	);

	/**
	 * @param string   $host      Site host, with ":port" when non-standard.
	 * @param string   $home_path Path of home_url() without trailing slash ('' at the root).
	 * @param string[] $prefixes  Every enabled language prefix.
	 * @param string[] $excluded  Excluded URL patterns (see UrlMatcher).
	 * @param ?\Closure $localize (string $rel, string $prefix): string, the path in that language.
	 */
	public function __construct(
		private string $host,
		private string $home_path,
		private array $prefixes,
		private array $excluded = array(),
		private ?\Closure $localize = null
	) {}

	public function rewrite( string $url, string $prefix ): ?string {
		$url = trim( $url );
		if ( '' === $url || '#' === $url[0] || '?' === $url[0] ) {
			return null;
		}

		if ( str_starts_with( $url, '//' ) || preg_match( '#^https?://#i', $url ) ) {
			$authority = strpos( $url, '//' ) + 2;
			$slash     = strcspn( $url, '/?#', $authority ) + $authority;
			if ( 0 !== strcasecmp( substr( $url, $authority, $slash - $authority ), $this->host ) ) {
				return null;
			}
			$head = substr( $url, 0, $slash );
			$tail = substr( $url, $slash );
		} elseif ( '/' === $url[0] ) {
			$head = '';
			$tail = $url;
		} else {
			return null; // Other schemes (mailto:, tel:, javascript:) and relative paths, which already resolve under the prefix.
		}

		$cut    = strcspn( $tail, '?#' );
		$path   = substr( $tail, 0, $cut );
		$suffix = substr( $tail, $cut );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( str_contains( $suffix, 'rest_route=' ) ) {
			return null;
		}

		$rel = UrlMatcher::relative( $path, $this->home_path );
		if ( null === $rel || UrlMatcher::matches( $rel, $this->excluded ) ) {
			return null;
		}

		$segments = explode( '/', trim( $rel, '/' ) );
		$first    = $segments[0];
		if ( in_array( $first, $this->prefixes, true ) || str_starts_with( $first, 'wp-' ) || 'xmlrpc.php' === $first || 'robots.txt' === $first ) {
			return null;
		}
		$last = end( $segments );
		$dot  = strrpos( $last, '.' );
		if ( false !== $dot && ! isset( self::PAGE_EXTENSIONS[ strtolower( substr( $last, $dot + 1 ) ) ] ) ) {
			return null; // A file: image, PDF, zip, sitemap.
		}

		if ( null !== $this->localize ) {
			$rel = ( $this->localize )( $rel, $prefix );
		}
		return $head . $this->home_path . '/' . $prefix . $rel . $suffix;
	}
}
