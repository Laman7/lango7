<?php
/**
 * The stable API for add-ons and site code.
 *
 * Add-ons (Lango7 Pro's integration packs and others) should use these
 * methods and the documented hooks only. Everything else in Lango7 may change
 * between versions; these keep working within the same Api::VERSION.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

use Lango7\Html\UrlMatcher;
use Lango7\Translation\PageContext;

final class Api {

	/** Bumped only when a method here changes in a way that breaks callers. */
	public const VERSION = 1;

	/** @var array{0: Language, 1: string}|false|null */
	private static array|false|null $request = null;

	/**
	 * The language of this page view, from its URL. Null on the site's own language.
	 */
	public static function currentLanguage(): ?Language {
		return Plugin::instance()->router()->current();
	}

	/**
	 * The visitor's language and the page's source path. For a page, from its
	 * URL; for a request a page sends in the background (REST, admin-ajax.php),
	 * from that page's address (the Referer header) when it is on this site.
	 * Null on the site's own language.
	 *
	 * @return array{0: Language, 1: string}|null
	 */
	public static function requestLanguage(): ?array {
		if ( null === self::$request ) {
			$router = Plugin::instance()->router();
			$lang   = $router->current();
			if ( null !== $lang ) {
				self::$request = array( $lang, $router->sourcePath() );
			} else {
				$referer       = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : '';
				self::$request = self::languageOfUrl( $referer ) ?? false;
			}
		}
		return false === self::$request ? null : self::$request;
	}

	/**
	 * Language and source path of a URL on this site ("/es/producto/" → Spanish,
	 * "/product/"). Null for the site's own language or another site.
	 *
	 * @return array{0: Language, 1: string}|null
	 */
	public static function languageOfUrl( string $url ): ?array {
		$plugin = Plugin::instance();
		$router = $plugin->router();
		$parts  = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return null;
		}
		$host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( strtolower( $host ) !== strtolower( $router->host() ) ) {
			return null;
		}
		$rel = UrlMatcher::relative( (string) ( $parts['path'] ?? '/' ), $router->homePath() );
		if ( null === $rel || ! preg_match( '#^/([^/]+)(/.*)?$#', $rel, $m ) ) {
			return null;
		}
		$lang = $plugin->languages()->byPrefix( $m[1] );
		if ( null === $lang ) {
			return null;
		}
		$path = $m[2] ?? '/';
		/** This filter is documented in src/Router/LanguageRouter.php */
		$mapped = (string) apply_filters( 'lango7_source_path', $path, $lang );
		if ( '' !== $mapped && '/' === $mapped[0] ) {
			$path = $mapped;
		}
		return array( $lang, $router->homePath() . $path );
	}

	/**
	 * A target language by code ("es", "zh-Hans") or URL prefix.
	 */
	public static function language( string $code ): ?Language {
		$languages = Plugin::instance()->languages();
		return $languages->byCode( $code ) ?? $languages->byPrefix( $code );
	}

	/**
	 * The site's target languages the current visitor can see (a draft
	 * language only for the people who edit it).
	 *
	 * @return Language[]
	 */
	public static function languages(): array {
		return Plugin::instance()->languages()->visible();
	}

	/**
	 * The site's own language code.
	 */
	public static function sourceLanguage(): string {
		return Plugin::instance()->languages()->sourceCode();
	}

	/**
	 * A URL of this site in a language (null = the site's own language), with
	 * translated slugs. Other sites' URLs come back unchanged.
	 */
	public static function url( string $url, ?Language $lang ): string {
		return lango7_url( null === $lang ? self::sourceLanguage() : $lang->code, $url );
	}

	/**
	 * Translate a piece of HTML (a whole page or a fragment) from saved
	 * translations, as on a page view: text, attributes and links. Never calls
	 * a translation engine; text with no saved translation stays as it is and
	 * is recorded for the next translation run.
	 *
	 * @param Language|null $lang Default: the request's language.
	 * @param string|null   $path Source path of the page it belongs to. Default: the request's.
	 */
	public static function translateHtml( string $html, ?Language $lang = null, ?string $path = null ): string {
		$request = self::requestLanguage();
		$lang  ??= $request[0] ?? null;
		if ( null === $lang || '' === trim( $html ) ) {
			return $html;
		}
		$plugin = Plugin::instance();
		$path ??= $request[1] ?? $plugin->router()->sourcePath();
		return $plugin->pipeline()->process( $html, $lang, new PageContext( $path, $plugin->router()->urlFor( $lang, $path ), false, 0.0 ) );
	}
}
