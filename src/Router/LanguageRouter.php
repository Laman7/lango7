<?php
/**
 * Language prefix routing.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Router;

use Lango7\Html\UrlMatcher;
use Lango7\Language;
use Lango7\Languages;

/**
 * "/ms/contact/" is served by stripping "/ms" from REQUEST_URI before WordPress
 * parses the request, so WordPress resolves the original page (and returns a real
 * 404 for "/ms/does-not-exist"). No rewrite rules are added and none need
 * flushing.
 */
final class LanguageRouter {

	private ?Language $current = null;

	/** Path of the current request without the language prefix or query string. */
	private string $source_path = '/';

	private string $origin = '';

	private string $home_path = '';

	private string $host = '';

	/** The path as requested, before an extension mapped it back (translated slugs). */
	private string $requested_path = '/';

	public function __construct( private Languages $languages ) {}

	/**
	 * A path the server received (REQUEST_URI, PATH_INFO, …), unslashed and
	 * sanitized as a URL. Null when the server did not set it.
	 */
	private static function server( string $key ): ?string {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return null;
		}
		return esc_url_raw( wp_unslash( $_SERVER[ $key ] ) );
	}

	public function detect(): void {
		$home            = wp_parse_url( (string) home_url( '/' ) );
		$this->host      = ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$this->origin    = ( $home['scheme'] ?? 'http' ) . '://' . $this->host;
		$this->home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );

		$uri               = self::server( 'REQUEST_URI' ) ?? '/';
		$parts             = explode( '?', $uri, 2 );
		$path              = $parts[0];
		$query             = $parts[1] ?? null;
		$this->source_path = $path;

		$rel = UrlMatcher::relative( $path, $this->home_path );
		if ( null === $rel || ! preg_match( '#^/([^/]+)(/.*)?$#', $rel, $m ) ) {
			return;
		}
		$lang = $this->languages->byPrefix( $m[1] );
		if ( null === $lang ) {
			if ( $this->languages->isRemovedPrefix( $m[1] ) ) {
				$this->redirectRemoved( $this->home_path . ( $m[2] ?? '/' ) . ( null !== $query ? '?' . $query : '' ) );
			}
			return;
		}

		$this->current        = $lang;
		$stripped             = $this->home_path . ( $m[2] ?? '/' );
		$this->source_path    = $stripped;
		$this->requested_path = $stripped;

		$_SERVER['REQUEST_URI'] = $stripped . ( null !== $query ? '?' . $query : '' );
		$prefix_path            = $this->home_path . '/' . $lang->prefix;
		// Rewrites the server's own path variables so WordPress routes the stripped path; nothing is output or stored.
		foreach ( array( 'PATH_INFO', 'REDIRECT_URL' ) as $key ) {
			$value = self::server( $key );
			if ( null !== $value && str_starts_with( $value, $prefix_path ) ) {
				$_SERVER[ $key ] = $this->home_path . ( substr( $value, strlen( $prefix_path ) ) ?: '/' );
			}
		}

		add_filter( 'redirect_canonical', array( $this, 'keepLanguage' ) );
		add_filter( 'wp_redirect', array( $this, 'keepLanguage' ) );
		add_filter( 'do_parse_request', array( $this, 'mapRequest' ), 1 );
	}

	/**
	 * Just before WordPress parses the request (extensions are loaded by now),
	 * let them map a translated path back to the original one:
	 * "/hubungi/" on /ms/ → "/contact/".
	 *
	 * @param bool $parse
	 * @return bool
	 */
	public function mapRequest( $parse ) {
		$lang = $this->current;
		$rel  = UrlMatcher::relative( $this->source_path, $this->home_path );
		if ( null === $lang || null === $rel ) {
			return $parse;
		}
		/**
		 * The original path for a path requested in a language.
		 *
		 * @param string   $rel  Path relative to the site root, without the language prefix.
		 * @param Language $lang
		 */
		$mapped = (string) apply_filters( 'lango7_source_path', $rel, $lang );
		if ( '' === $mapped || $mapped === $rel || '/' !== $mapped[0] ) {
			return $parse;
		}
		$uri                    = self::server( 'REQUEST_URI' ) ?? '';
		$query                  = explode( '?', $uri, 2 )[1] ?? null;
		$previous = $this->source_path;
		$this->source_path      = $this->home_path . $mapped;
		// Servers using PATH_INFO must resolve the mapped slug as well.
		foreach ( array( 'PATH_INFO', 'REDIRECT_URL' ) as $key ) {
			if ( self::server( $key ) === $previous ) {
				$_SERVER[ $key ] = $this->source_path;
			}
		}
		$_SERVER['REQUEST_URI'] = $this->source_path . ( null !== $query ? '?' . $query : '' );
		return $parse;
	}

	/**
	 * "/cn/about/" of a language that was turned off: send visitors and search
	 * engines to "/about/" for good. Only when WordPress has nothing at that URL
	 * itself, so a real page that happens to live at /cn/ is left alone.
	 */
	private function redirectRemoved( string $target ): void {
		add_action(
			'template_redirect',
			static function () use ( $target ): void {
				if ( is_404() && wp_safe_redirect( $target, 301, 'Lango7' ) ) {
					exit;
				}
			},
			0
		);
	}

	public function current(): ?Language {
		return $this->current;
	}

	public function sourcePath(): string {
		return $this->source_path;
	}

	/**
	 * The path as requested, without the language prefix: differs from
	 * sourcePath() when it was a translated slug.
	 */
	public function requestedPath(): string {
		return $this->requested_path;
	}

	public function host(): string {
		return $this->host;
	}

	public function homePath(): string {
		return $this->home_path;
	}

	/**
	 * Absolute URL of a source path in a language (null = the source language).
	 */
	public function urlFor( ?Language $lang, ?string $path = null ): string {
		$rel = UrlMatcher::relative( $path ?? $this->source_path, $this->home_path ) ?? '/';
		return $this->origin . $this->home_path . ( $lang ? '/' . $lang->prefix . $this->localize( $rel, $lang ) : $rel );
	}

	/**
	 * A source path as it appears in a language (translated slugs).
	 */
	public function localize( string $rel, Language $lang ): string {
		/**
		 * The path of a page in a language, relative to the site root and without
		 * the prefix: "/contact/" → "/hubungi/". Must return a path that
		 * lango7_source_path maps back.
		 *
		 * @param string   $rel
		 * @param Language $lang
		 */
		$out = (string) apply_filters( 'lango7_localized_path', $rel, $lang );
		return '' !== $out && '/' === $out[0] ? $out : $rel;
	}

	/**
	 * Link rewriter for this site, with translated paths.
	 *
	 * @param string[] $excluded
	 */
	public function linkRewriter( array $excluded = array() ): \Lango7\Html\LinkRewriter {
		return new \Lango7\Html\LinkRewriter(
			$this->host,
			$this->home_path,
			$this->languages->prefixes(),
			$excluded,
			function ( string $rel, string $prefix ): string {
				$lang = $this->languages->byPrefix( $prefix );
				return null === $lang ? $rel : $this->localize( $rel, $lang );
			}
		);
	}

	/**
	 * Canonical and other same-site redirects stay in the visitor's language.
	 *
	 * @param string|false $location
	 * @return string|false
	 */
	public function keepLanguage( $location ) {
		if ( ! is_string( $location ) || null === $this->current ) {
			return $location;
		}
		return $this->linkRewriter()->rewrite( $location, $this->current->prefix ) ?? $location;
	}
}
