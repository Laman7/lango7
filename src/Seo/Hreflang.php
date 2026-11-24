<?php
/**
 * hreflang alternates, printed on source and translated pages alike.
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
 * Uses the BCP-47 code, not the URL prefix: the /cn/ page declares zh-Hans.
 * x-default points at the source page.
 */
final class Hreflang {

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private Settings $settings
	) {}

	/**
	 * @return array<string, string> hreflang => URL, empty when the page has no alternates.
	 */
	public function alternates(): array {
		if ( is_404() || is_search() || is_preview() || is_feed() ) {
			return array();
		}
		$langs = $this->languages->enabled();
		$rel   = UrlMatcher::relative( $this->router->sourcePath(), $this->router->homePath() );
		if ( ! $langs || null === $rel || UrlMatcher::matches( $rel, \Lango7\Exclusions::patterns() ) ) {
			return array();
		}
		$out = array( $this->languages->sourceCode() => $this->router->urlFor( null ) );
		foreach ( $langs as $lang ) {
			if ( Languages::isPublic( $lang ) ) {
				$out[ $lang->code ] = $this->router->urlFor( $lang );
			}
		}
		$out['x-default'] = $this->router->urlFor( null );
		return (array) apply_filters( 'lango7_hreflang', $out );
	}

	public function render(): void {
		foreach ( $this->alternates() as $code => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( $code ), esc_url( $url ) );
		}
	}
}
