<?php
/**
 * Buffers translated-language pages and runs the pipeline at flush.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Buffer;

use Lango7\Html\UrlMatcher;
use Lango7\Log;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;
use Lango7\Translation\PageContext;
use Lango7\Translation\Pipeline;

/**
 * Only requests under a language prefix are buffered; source-language pages pay
 * nothing. Any exception inside the pipeline sends the original page untouched.
 */
final class OutputBuffer {

	private const CRAWLERS = '/googlebot|google-inspectiontool|bingbot|yandex|baiduspider|duckduckbot|applebot|slurp|petalbot|lango7-warmup/i';

	/** Tracking parameters that do not change the page. */
	private const IGNORED_PARAMS = '/^(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|mc_cid|mc_eid|_ga|_gl|ref|lango7_.*|_wpnonce)$/i';

	private string $buffer = '';

	private ?PageContext $page = null;

	public function __construct(
		private LanguageRouter $router,
		private Pipeline $pipeline,
		private Settings $settings
	) {}

	public function maybeStart(): void {
		if ( null === $this->router->current() || $this->skip() ) {
			return;
		}
		$lang       = $this->router->current();
		$crawler    = (bool) preg_match( self::CRAWLERS, $this->userAgent() );
		$this->page = new PageContext(
			$this->router->sourcePath(),
			$this->router->urlFor( $lang ),
			$this->engineAllowed(),
			(float) $this->settings->get( $crawler ? 'crawler_budget' : 'time_budget' ),
			is_404()
		);
		ob_start( array( $this, 'flush' ) );
	}

	/**
	 * Output callback. Accumulates until the final flush so a theme calling
	 * flush() mid-page never sends half a page untranslated.
	 */
	public function flush( string $chunk, int $phase ): string {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$this->buffer = '';
			$chunk        = '';
		}
		$this->buffer .= $chunk;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$html         = $this->buffer;
		$this->buffer = '';

		$lang = $this->router->current();
		if ( null === $lang || null === $this->page || ! $this->isHtml( $html ) ) {
			return $html;
		}
		try {
			$out = $this->pipeline->process( $html, $lang, $this->page );
		} catch ( \Throwable $e ) {
			Log::error(
				'Translation skipped: ' . $e->getMessage(),
				array(
					'file' => basename( $e->getFile() ),
					'line' => $e->getLine(),
					'url'  => $this->page->url,
				)
			);
			return $html;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Language: ' . $lang->code );
		}
		if ( $this->pipeline->pending() ) {
			$this->noCache();
		}
		return $out;
	}

	/**
	 * Keep page caches from storing a half-translated page; the next request
	 * after the queue finishes is complete and cacheable.
	 */
	public static function noCache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the shared page-cache convention.
		}
		do_action( 'litespeed_control_set_nocache', 'lango7: translation pending' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-Lango7: pending' );
		}
	}

	private function skip(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only checks.
		$builder = isset( $_GET['bricks'] ) || isset( $_GET['elementor-preview'] ) || isset( $_GET['et_fb'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['ct_builder'] );
		// phpcs:enable
		$rel = UrlMatcher::relative( $this->router->sourcePath(), $this->router->homePath() ) ?? '/';

		$skip = is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| is_feed()
			|| is_robots()
			|| is_trackback()
			|| is_embed()
			|| is_customize_preview()
			|| $builder
			|| UrlMatcher::matches( $rel, \Lango7\Exclusions::patterns() );

		return (bool) apply_filters( 'lango7_skip_request', $skip );
	}

	/**
	 * Only real pages, reached without visitor-supplied query text, may spend
	 * engine credit (spec 11.2). Everything else is served from cache.
	 */
	private function engineAllowed(): bool {
		$allowed = ! is_404() && ! is_search() && ! is_preview()
			&& 'head' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'get' )
			&& ! ( is_singular() && post_password_required() )
			&& ( ! is_user_logged_in() || current_user_can( 'edit_posts' ) );

		if ( $allowed ) {
			global $wp;
			$public = isset( $wp->public_query_vars ) ? array_flip( $wp->public_query_vars ) : array();
			unset( $public['s'] );
			foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! isset( $public[ $key ] ) && ! preg_match( self::IGNORED_PARAMS, (string) $key ) ) {
					$allowed = false;
					break;
				}
			}
		}
		return (bool) apply_filters( 'lango7_engine_allowed', $allowed );
	}

	private function isHtml( string $html ): bool {
		$code = http_response_code();
		if ( false !== $code && 200 !== $code && 404 !== $code ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}
		return false !== stripos( $html, '<html' ) || false !== stripos( $html, '<body' );
	}

	private function userAgent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}
}
