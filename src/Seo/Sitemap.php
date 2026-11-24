<?php
/**
 * Core sitemap entries for translated URLs.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Seo;

use Lango7\Html\UrlMatcher;
use Lango7\Languages;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;

/**
 * Adds one provider to wp-sitemap.xml whose sub-sitemaps mirror the core post
 * and taxonomy sitemaps per language:
 *
 *   wp-sitemap-translations-p_ms_page-1.xml  → every page, under /ms/
 *
 * The core providers do the querying (so their filters still apply); this only
 * maps each <loc> into the language and drops excluded URLs.
 */
final class Sitemap extends \WP_Sitemaps_Provider {

	private \WP_Sitemaps_Posts $posts;

	private \WP_Sitemaps_Taxonomies $taxonomies;

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private Settings $settings
	) {
		$this->name        = 'translations';
		$this->object_type = 'translations';
		$this->posts       = new \WP_Sitemaps_Posts();
		$this->taxonomies  = new \WP_Sitemaps_Taxonomies();
	}

	public static function register( \WP_Sitemaps $sitemaps, Languages $languages, LanguageRouter $router, Settings $settings ): void {
		/**
		 * False leaves translated URLs out of wp-sitemap.xml (Pro lists them in its own sitemap).
		 *
		 * @param bool $add
		 */
		if ( $languages->enabled() && apply_filters( 'lango7_core_sitemap', true ) ) {
			$sitemaps->registry->add_provider( 'translations', new self( $languages, $router, $settings ) );
		}
	}

	/**
	 * @return array<string, object>
	 */
	public function get_object_subtypes() {
		$out = array();
		foreach ( $this->languages->enabled() as $lang ) {
			if ( ! Languages::isPublic( $lang ) ) {
				continue;
			}
			foreach ( array_keys( $this->posts->get_object_subtypes() ) as $type ) {
				$out[ 'p_' . $lang->prefix . '_' . $type ] = (object) array( 'name' => $type );
			}
			foreach ( array_keys( $this->taxonomies->get_object_subtypes() ) as $tax ) {
				$out[ 't_' . $lang->prefix . '_' . $tax ] = (object) array( 'name' => $tax );
			}
		}
		return $out;
	}

	/**
	 * @param int    $page_num
	 * @param string $object_subtype
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$parsed = $this->parse( (string) $object_subtype );
		if ( null === $parsed ) {
			return array();
		}
		[ $inner, $prefix, $sub ] = $parsed;

		$links = $this->router->linkRewriter( \Lango7\Exclusions::patterns() );
		$out   = array();
		foreach ( $inner->get_url_list( $page_num, $sub ) as $entry ) {
			$loc = $links->rewrite( (string) $entry['loc'], $prefix );
			if ( null !== $loc ) {
				$entry['loc'] = $loc;
				$out[]        = $entry;
			}
		}
		return $out;
	}

	/**
	 * @param string $object_subtype
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		$parsed = $this->parse( (string) $object_subtype );
		return null === $parsed ? 0 : $parsed[0]->get_max_num_pages( $parsed[2] );
	}

	/**
	 * "p_ms_page" → [ posts provider, "ms", "page" ]. Prefixes cannot contain "_".
	 *
	 * @return array{0: \WP_Sitemaps_Provider, 1: string, 2: string}|null
	 */
	private function parse( string $subtype ): ?array {
		if ( ! preg_match( '/^([pt])_([a-z0-9-]+)_(.+)$/', $subtype, $m ) || null === $this->languages->byPrefix( $m[2] ) ) {
			return null;
		}
		return array( 'p' === $m[1] ? $this->posts : $this->taxonomies, $m[2], $m[3] );
	}
}
