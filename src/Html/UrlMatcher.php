<?php
/**
 * Path helpers shared by the router, link rewriter and sitemap.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Html;

final class UrlMatcher {

	/**
	 * Path relative to the home path ("/blog/about/" → "/about/"), null when the
	 * path is outside the WordPress install.
	 */
	public static function relative( string $path, string $home_path ): ?string {
		if ( '' === $home_path ) {
			return '' === $path ? '/' : $path;
		}
		if ( $path === $home_path ) {
			return '/';
		}
		if ( str_starts_with( $path, $home_path . '/' ) ) {
			return substr( $path, strlen( $home_path ) );
		}
		return null;
	}

	/**
	 * Patterns are paths relative to the site root. A trailing "*" matches
	 * anything below it ("/my-account/*" covers "/my-account/" and its children);
	 * without one the match is exact, ignoring a trailing slash.
	 *
	 * @param string[] $patterns
	 */
	public static function matches( string $rel_path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( '/' !== $pattern[0] ) {
				$pattern = '/' . $pattern;
			}
			if ( str_ends_with( $pattern, '*' ) ) {
				$base = rtrim( $pattern, '*' );
				if ( str_starts_with( $rel_path, $base ) || rtrim( $rel_path, '/' ) === rtrim( $base, '/' ) ) {
					return true;
				}
			} elseif ( rtrim( $rel_path, '/' ) === rtrim( $pattern, '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string[]
	 */
	public static function lines( string $text ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ) ) );
	}
}
