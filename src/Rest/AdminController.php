<?php
/**
 * lango7/v1 admin endpoints: settings, engine test, warm-up, status.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Rest;

use Lango7\Cache\Purger;
use Lango7\Engine\EngineRegistry;
use Lango7\Html\UrlMatcher;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Log;
use Lango7\Queue\Jobs;
use Lango7\Router\LanguageRouter;
use Lango7\Security\KeyVault;
use Lango7\Security\SpendCap;
use Lango7\Settings;
use Lango7\Admin\SettingsPage;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Every route needs manage_options. Nothing here lets anyone submit arbitrary
 * text to the engine: the key test translates the fixed word "Hello", and
 * warm-up only requests the site's own homepage.
 */
final class AdminController {

	public const NS = 'lango7/v1';

	/** URL folders that would shadow WordPress paths. */
	private const RESERVED = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'comments', 'category', 'tag', 'author', 'search', 'embed', 'trackback', 'sitemap' );

	public function __construct(
		private Settings $settings,
		private Languages $languages,
		private KeyVault $vault,
		private EngineRegistry $engines,
		private SpendCap $cap,
		private StringRepository $strings,
		private TranslationRepository $translations,
		private Jobs $jobs,
		private LanguageRouter $router
	) {}

	public function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'saveSettings' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/engines/(?P<id>[a-z0-9_-]+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'testEngine' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/warm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'warm' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/languages/remove',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'removeLanguage' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/pro-card/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( array( 'dismissed' => (bool) update_user_meta( get_current_user_id(), SettingsPage::DISMISS_META, 1 ) ) ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/retranslate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retranslate' ),
				'permission_callback' => $admin,
			)
		);
	}

	public function getSettings(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function saveSettings( WP_REST_Request $request ) {
		$p       = (array) $request->get_json_params();
		$catalog = Languages::available();
		// Languages saved earlier (e.g. with Pro) stay valid, so other settings can still be saved.
		foreach ( array_merge( array( $this->languages->sourceCode() ), array_map( static fn( Language $l ): string => $l->code, $this->languages->enabled() ) ) as $kept ) {
			$catalog[ $kept ] ??= Languages::catalog()[ $kept ] ?? null;
		}
		$catalog = array_filter( $catalog );

		$source = (string) ( $p['source_lang'] ?? $this->settings->get( 'source_lang' ) );
		if ( ! isset( $catalog[ $source ] ) ) {
			return new WP_Error( 'lango7_source', __( 'Unknown source language.', 'lango7' ), array( 'status' => 400 ) );
		}

		$rows     = array();
		$prefixes = array();
		$codes    = array();
		foreach ( (array) ( $p['languages'] ?? array() ) as $row ) {
			$code   = (string) ( $row['code'] ?? '' );
			$prefix = strtolower( trim( (string) ( $row['prefix'] ?? '' ) ) );
			if ( '' === $code ) {
				continue;
			}
			if ( ! isset( $catalog[ $code ] ) ) {
				return new WP_Error( 'lango7_language', __( 'This language is not available. Without a server engine, Lango7 can only use languages Chrome\'s built-in translator supports.', 'lango7' ), array( 'status' => 400 ) );
			}
			if ( $code === $source ) {
				return new WP_Error( 'lango7_language', __( 'Pick a target language different from the source language.', 'lango7' ), array( 'status' => 400 ) );
			}
			if ( ! preg_match( '/^[a-z0-9-]{1,20}$/', $prefix ) || in_array( $prefix, self::RESERVED, true ) || isset( $prefixes[ $prefix ] ) ) {
				return new WP_Error(
					'lango7_prefix',
					/* translators: %s: URL prefix. */
					sprintf( __( 'The URL prefix "%s" is not allowed. Use lowercase letters, numbers and dashes, different for each language.', 'lango7' ), $prefix ),
					array( 'status' => 400 )
				);
			}
			if ( isset( $codes[ $code ] ) ) {
				return new WP_Error( 'lango7_language', __( 'Each language can only be added once.', 'lango7' ), array( 'status' => 400 ) );
			}
			$codes[ $code ]      = true;
			$prefixes[ $prefix ] = true;
			$existing            = $this->languages->byCode( $code );
			$engine              = (string) ( $row['engine'] ?? ( $existing ? $existing->engine : '' ) );
			$engine              = 'chrome' === $engine || null !== $this->engines->get( $engine ) ? $engine : '';
			$merged              = array_merge(
				$existing ? $existing->toArray() : Language::fromArray( array( 'code' => $code ) )->toArray(),
				array(
					'prefix' => $prefix,
					'engine' => $engine,
				)
			);
			// Refuse a service that cannot translate the language, for languages
			// being added or changed where a service was chosen (Settings sends
			// one; Setup checks on its own, as its key is saved after this).
			$changed = array_key_exists( 'engine', (array) $row ) && ( ! $existing || $existing->engine !== $engine || $source !== $this->languages->sourceCode() );
			$lang    = Language::fromArray( $merged );
			if ( $changed && ! $this->engines->canTranslate( $lang, $source ) ) {
				return new WP_Error(
					'lango7_service',
					sprintf(
						/* translators: 1: service name, 2: language name, 3: site language name. */
						__( '%1$s cannot translate %3$s into %2$s. Choose another service for %2$s.', 'lango7' ),
						$this->engines->serviceLabel( $this->engines->serviceFor( $lang ) ),
						$lang->name,
						Languages::catalog()[ $source ]['name'] ?? $source
					),
					array( 'status' => 400 )
				);
			}
			$rows[] = $merged;
		}

		$this->settings->update(
			array(
				'source_lang'       => $source,
				'exclude_urls'      => sanitize_textarea_field( (string) ( $p['exclude_urls'] ?? $this->settings->get( 'exclude_urls' ) ) ),
				'exclude_selectors' => sanitize_textarea_field( (string) ( $p['exclude_selectors'] ?? $this->settings->get( 'exclude_selectors' ) ) ),
				'daily_cap'         => max( 0, (int) ( $p['daily_cap'] ?? $this->settings->get( 'daily_cap' ) ) ),
				'time_budget'       => min( 30, max( 1, (float) ( $p['time_budget'] ?? $this->settings->get( 'time_budget' ) ) ) ),
				'crawler_budget'    => min( 60, max( 1, (float) ( $p['crawler_budget'] ?? $this->settings->get( 'crawler_budget' ) ) ) ),
				'max_segments'      => min( 2000, max( 10, (int) ( $p['max_segments'] ?? $this->settings->get( 'max_segments' ) ) ) ),
				'batch_chars'       => min( 30000, max( 500, (int) ( $p['batch_chars'] ?? $this->settings->get( 'batch_chars' ) ) ) ),
				'uninstall'         => 'drop' === ( $p['uninstall'] ?? $this->settings->get( 'uninstall' ) ) ? 'drop' : 'keep',
				'news'              => (int) ! empty( $p['news'] ?? $this->settings->get( 'news' ) ),
			)
		);
		$this->languages->save( $rows );

		/**
		 * Save extra fields printed with lango7_settings_engine (e.g. Pro's API keys).
		 *
		 * @param array<string, mixed> $p Submitted settings.
		 */
		do_action( 'lango7_settings_saved', $p );

		Purger::bump();
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function testEngine( WP_REST_Request $request ) {
		$engine = $this->engines->get( (string) $request['id'] );
		if ( null === $engine ) {
			return new WP_Error( 'lango7_engine', __( 'Unknown engine.', 'lango7' ), array( 'status' => 404 ) );
		}
		/**
		 * The engine to test, given the unsaved fields of its settings box, so a
		 * model or address can be tried before saving.
		 *
		 * @param \Lango7\Engine\Engine $engine
		 * @param array<string, string>  $fields
		 */
		$engine = apply_filters( 'lango7_engine_for_test', $engine, array_map( 'strval', (array) ( $request->get_json_params()['fields'] ?? array() ) ) );
		if ( ! $engine instanceof \Lango7\Engine\Engine ) {
			return new WP_Error( 'lango7_engine', __( 'Unknown engine.', 'lango7' ), array( 'status' => 404 ) );
		}
		$key = trim( (string) ( $request->get_json_params()['key'] ?? '' ) );
		if ( '' !== $key ) {
			$this->vault->useTemporarily( $engine->id(), $key );
		}
		$code = (string) ( $request->get_json_params()['target'] ?? '' );
		// A site language carries its engine code (es, zh-CN…); a bare catalogue entry does not.
		$lang = $this->languages->byCode( $code ) ?? ( isset( Languages::catalog()[ $code ] ) ? Language::fromArray( array( 'code' => $code ) ) : ( $this->languages->enabled()[0] ?? Language::fromArray( array( 'code' => 'ms' ) ) ) );
		/** This filter is documented in src/Translation/Translator.php */
		$source = (string) apply_filters( 'lango7_engine_code', Languages::catalog()[ $this->languages->sourceCode() ]['google'] ?? 'en', $engine->id(), $this->languages->source() );
		/** This filter is documented in src/Translation/Translator.php */
		$target = (string) apply_filters( 'lango7_engine_code', $lang->engine_code, $engine->id(), $lang );

		$result = $engine->test( $source, $target );
		return new WP_REST_Response(
			array(
				'ok'      => $result->ok,
				'message' => $result->message,
				'sample'  => $result->sample,
			)
		);
	}

	/**
	 * Request the translated homepage from the server itself, which runs the
	 * normal lazy translation with the crawler time budget.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function warm( WP_REST_Request $request ) {
		$code = (string) ( $request->get_json_params()['lang'] ?? '' );
		$lang = '' !== $code ? $this->languages->byCode( $code ) : ( $this->languages->enabled()[0] ?? null );
		if ( null === $lang ) {
			return new WP_Error( 'lango7_language', __( 'Add a target language first.', 'lango7' ), array( 'status' => 400 ) );
		}
		$url   = $this->router->urlFor( $lang, $this->router->homePath() . '/' );
		$start = microtime( true );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout'    => 45,
				'user-agent' => 'Lango7-Warmup/' . LANGO7_VERSION,
				'sslverify'  => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopbacks.
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'lango7_warm', $res->get_error_message(), array( 'status' => 502 ) );
		}
		return new WP_REST_Response(
			array(
				'url'      => $url,
				'code'     => wp_remote_retrieve_response_code( $res ),
				'complete' => 'pending' !== wp_remote_retrieve_header( $res, 'x-lango7' ),
				'seconds'  => round( microtime( true ) - $start, 1 ),
				'status'   => $this->statusData(),
			)
		);
	}

	/**
	 * Turn a language off: its pages, switcher entry, hreflang and sitemap go
	 * at once, and its old URLs 301 to the original pages. With delete, its
	 * translations (edits included) are deleted too; otherwise they are kept
	 * for when the language is added again.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function removeLanguage( WP_REST_Request $request ) {
		$p    = (array) $request->get_json_params();
		$lang = $this->languages->remove( (string) ( $p['code'] ?? '' ) );
		if ( null === $lang ) {
			return new WP_Error( 'lango7_language', __( 'That language is not turned on.', 'lango7' ), array( 'status' => 404 ) );
		}
		$this->jobs->forgetLanguage( $lang->code );
		$deleted = ! empty( $p['delete'] ) ? $this->translations->deleteLanguage( $lang->code ) : 0;
		/**
		 * A language was turned off.
		 *
		 * @param Language $lang
		 * @param bool     $deleted Whether its translations were deleted too.
		 */
		do_action( 'lango7_language_removed', $lang, ! empty( $p['delete'] ) );
		Purger::purgeAll();
		return new WP_REST_Response(
			array(
				'removed' => $lang->code,
				'deleted' => $deleted,
				'status'  => $this->statusData(),
			)
		);
	}

	public function status(): WP_REST_Response {
		return new WP_REST_Response( $this->statusData() );
	}

	public function retranslate( WP_REST_Request $request ): WP_REST_Response {
		$code    = (string) ( $request->get_json_params()['lang'] ?? '' );
		$deleted = $this->translations->resetMachine( '' === $code ? null : $code );
		Purger::purgeAll();
		return new WP_REST_Response(
			array(
				'deleted' => $deleted,
				'status'  => $this->statusData(),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return (array) apply_filters(
			'lango7_settings_payload',
			array(
				'settings'      => $this->settings->all(),
				'languages'     => array_map( static fn( Language $l ): array => $l->toArray(), $this->languages->enabled() ),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function statusData(): array {
		$total   = $this->strings->count();
		$pending = $this->jobs->pending();
		$source  = $this->languages->sourceCode();
		$langs   = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$counts  = $this->translations->statusCounts( $lang->code );
			$done    = ( $counts['machine'] ?? 0 ) + ( $counts['edited'] ?? 0 ) + ( $counts['ai_edited'] ?? 0 ) + ( $counts['needs_review'] ?? 0 ) + ( $counts['unapproved'] ?? 0 );
			$langs[] = array(
				'code'    => $lang->code,
				'name'    => $lang->name,
				'url'     => $this->router->urlFor( $lang, $this->router->homePath() . '/' ),
				'strings' => $total,
                'remaining_chars' => $this->strings->remainingCharacters( $lang->code ),
				'done'    => $done,
				'edited'  => ( $counts['edited'] ?? 0 ) + ( $counts['ai_edited'] ?? 0 ),
				'failed'  => $counts['failed'] ?? 0,
				'review'  => $counts['needs_review'] ?? 0,
				'unapproved' => $counts['unapproved'] ?? 0,
				'queued'  => $pending[ $lang->code ] ?? 0,
				'waiting' => $this->strings->pendingCount( $lang->code, $source ),
			);
		}
		$today = $this->cap->totals( 'today' );
		$month = $this->cap->totals( 'month' );
		$cost  = 0.0;
		foreach ( $month as $engine_id => $chars ) {
			$cost += (float) ( $this->engines->get( (string) $engine_id )?->estimate( $chars ) ?? 0 );
		}
		return array(
			'server_engine' => $this->engines->hasServerEngine(),
			'languages'     => $langs,
			'chars_today'   => array_sum( $today ),
			'chars_month'   => array_sum( $month ),
			'cost_month'    => round( $cost, 2 ),
			'cap'           => $this->cap->cap(),
			'cap_reached'   => 0 === $this->cap->remaining(),
			'errors'        => array_map(
				static fn( array $e ): array => array(
					'time'    => wp_date( 'Y-m-d H:i', (int) $e['time'] ),
					'message' => (string) $e['message'],
				),
				Log::recent( 10 )
			),
			'excluded'      => \Lango7\Exclusions::patterns(),
		);
	}
}
