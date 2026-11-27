<?php
/**
 * Endpoints for translating in the editor's own Chrome.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Rest;

use Lango7\Cache\Purger;
use Lango7\Html\UrlMatcher;
use Lango7\Languages;
use Lango7\Plugin;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;
use Lango7\Store\RedoList;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;
use Lango7\Translation\Glossary;
use Lango7\Translation\Placeholders;
use Lango7\Translation\Segment;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Free has no server-side engine. Instead the Translate with Chrome screen asks
 * for strings that have no translation yet, runs them through Chrome's
 * on-device Translator API, and posts the results back here.
 *
 * Saving accepts text from the browser, so it is limited to users who may edit
 * translations, and every item gets the same checks as engine output: it must
 * match a stored string, keep its placeholders exactly, and contain no markup.
 */
final class ChromeController {

	private const MAX_ITEMS = 100;

	public function __construct(
		private Settings $settings,
		private Languages $languages,
		private LanguageRouter $router,
		private StringRepository $strings,
		private TranslationRepository $translations,
		private Glossary $glossary,
		private ?RedoList $redo = null
	) {}

	/**
	 * Glossary terms become <gN/> placeholders, exactly as for server engines, so
	 * Chrome cannot translate a brand name. Deterministic: save() re-wraps the
	 * source to know what each <gN/> stands for.
	 *
	 * @return array{0: string, 1: array<string, string>} Text for Chrome, gN => fixed text.
	 */
	private function wrap( \Lango7\Language $lang, array $row ): array {
		[ $segment, $replacements ] = Glossary::wrap( new Segment( $row['hash'], $row['source_text'], $row['kind'] ), $this->glossary->forLanguage( $lang->code ) );
		return array( $segment->text, $replacements );
	}

	/**
	 * Who may use these routes: people who can translate, and only for a
	 * language they are allowed to edit (the "lang" parameter, or the first
	 * language when it is left out).
	 */
	public function permission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return false;
		}
		$lang = $this->lang( $request );
		// An unknown language is answered by the route itself (400).
		return $lang instanceof WP_Error || current_user_can( Plugin::LANGUAGE_CAPABILITY, $lang->code );
	}

	public function register(): void {
		$can = array( $this, 'permission' );
		$ns  = AdminController::NS;

		register_rest_route(
			$ns,
			'/chrome/urls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'urls' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$ns,
			'/chrome/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'pending' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$ns,
			'/chrome/translations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => $can,
			)
		);
	}

	/**
	 * Every published post of the post types in the core sitemap (up to 1,000;
	 * builder templates and other non-content types are left out), homepage first, as source path + translated URL. Visiting each translated
	 * URL is what records its strings.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function urls( WP_REST_Request $request ) {
		$lang = $this->lang( $request );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}
		$types = array_keys( ( new \WP_Sitemaps_Posts() )->get_object_subtypes() );
		if ( ! $types ) {
			return new WP_REST_Response( array( array( 'path' => $this->router->homePath() . '/', 'url' => $this->router->urlFor( $lang, $this->router->homePath() . '/' ) ) ) );
		}
		$ids   = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'numberposts'      => 1000,
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

		$excluded = \Lango7\Exclusions::patterns();
		$paths    = array( $this->router->homePath() . '/' => true );
		foreach ( $ids as $id ) {
			$url  = (string) get_permalink( $id );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST ) . ( wp_parse_url( $url, PHP_URL_PORT ) ? ':' . wp_parse_url( $url, PHP_URL_PORT ) : '' );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' === $path || 0 !== strcasecmp( $host, $this->router->host() ) || null !== wp_parse_url( $url, PHP_URL_QUERY ) ) {
				continue; // Plain permalinks (?p=) and other hosts are skipped.
			}
			$rel = UrlMatcher::relative( $path, $this->router->homePath() );
			if ( null !== $rel && ! UrlMatcher::matches( $rel, $excluded ) ) {
				$paths[ $path ] = true;
			}
		}

		$out = array();
		foreach ( array_keys( $paths ) as $path ) {
			$out[] = array(
				'path' => $path,
				'url'  => $this->router->urlFor( $lang, $path ),
			);
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Strings waiting for a translation, for one URL (?path=) or the whole site.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function pending( WP_REST_Request $request ) {
		$lang = $this->lang( $request );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}
		$path  = (string) $request->get_param( 'path' );
		$hash  = (string) $request->get_param( 'hash' );
		if ( '' !== $hash && ! preg_match( '/^[0-9a-f]{40}$/', $hash ) ) {
			return new WP_Error( 'lango7_hash', __( 'Invalid string.', 'lango7' ), array( 'status' => 400 ) );
		}
		$limit = min( self::MAX_ITEMS, max( 1, (int) ( $request->get_param( 'limit' ) ?: 40 ) ) );
		$replace_edit = '' !== $hash && current_user_can( 'manage_options' ) && '1' === (string) $request->get_param( 'replace_edit' );
		$rows  = $this->strings->pending( $lang->code, $this->languages->sourceCode(), '' === $path ? null : sha1( $path ), $limit, '' === $hash ? null : $hash, $replace_edit, $this->redo?->ids( $lang->code ) ?? array() );

		return new WP_REST_Response(
			array(
				'items' => array_map(
					fn( array $r ): array => array(
						'hash' => $r['hash'],
						'text' => $this->wrap( $lang, $r )[0],
						'kind' => $r['kind'],
					),
					$rows
				),
				'total' => $this->strings->pendingCount( $lang->code, $this->languages->sourceCode(), $this->redo?->ids( $lang->code ) ?? array() ),
			)
		);
	}

	/**
	 * Save Chrome's output: { lang, items: [ { hash, text } ] }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$lang = $this->lang( $request );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}
		$items = array_slice( (array) ( $request->get_json_params()['items'] ?? array() ), 0, self::MAX_ITEMS );
		$texts = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && is_string( $item['hash'] ?? null ) && is_string( $item['text'] ?? null ) && strlen( $item['text'] ) <= 20000 ) {
				$texts[ $item['hash'] ] = $item['text'];
			}
		}

		$good   = array();
		$hashes = array();
		$failed = array();
		$replace_edit = current_user_can( 'manage_options' ) && true === ( $request->get_json_params()['replace_edit'] ?? false );
		$redo         = array_flip( $this->redo?->ids( $lang->code ) ?? array() );
		$redone       = array(); // Redo strings handled this round: saved, or kept as they were.
		foreach ( $this->strings->byHashes( array_keys( $texts ) ) as $row ) {
			$text = Glossary::unwrap( $texts[ $row['hash'] ], $this->wrap( $lang, $row )[1] );
			if ( null === $text ) {
				if ( isset( $redo[ (int) $row['id'] ] ) ) {
					$redone[] = (int) $row['id']; // Keep the current translation rather than retry for ever.
				}
				continue; // A term went missing, or the glossary changed mid-run: stays pending for the next run.
			}
			$text = Placeholders::clean( $text );
			$text = null === $text ? null : Placeholders::collapse( $text );
			if ( null !== $text && Placeholders::validate( $row['source_text'], $text ) ) {
				$text = Placeholders::respace( $row['source_text'], $text );
				$good[ (int) $row['id'] ] = $text;
				$hashes[]                 = $row['hash'];
			} elseif ( isset( $redo[ (int) $row['id'] ] ) ) {
				$redone[] = (int) $row['id']; // A failed redo keeps the current translation.
			} else {
				$failed[] = (int) $row['id'];
			}
		}
		if ( $replace_edit ) {
			foreach ( array_keys( $good ) as $id ) {
				$this->translations->forget( $lang->code, (int) $id );
			}
		}
		$this->translations->saveMachine( $lang->code, $good, 'chrome' );
		$this->redo?->remove( $lang->code, array_merge( $redone, array_keys( $good ) ) );
		if ( ! $replace_edit ) {
			$this->translations->markFailed( $lang->code, $failed, 'chrome' );
		}

		$saved = array_keys( $good );
		if ( $saved ) {
			Purger::bump();
			Purger::purgeUrls( array_map( fn( string $path ): string => $this->router->urlFor( $lang, $path ), $this->strings->urlsFor( $saved ) ) );
			do_action( 'lango7_translations_saved', $lang, $hashes, '' );
			\Lango7\Store\Activity::record(
				\Lango7\Store\Activity::TRANSLATED,
				array(
					'lang'   => $lang->code,
					'engine' => 'chrome',
					'items'  => count( $saved ),
					'chars'  => array_sum( array_map( 'mb_strlen', array_intersect_key( $good, array_flip( $saved ) ) ) ),
				)
			);
		}
		return new WP_REST_Response(
			array(
				'saved'  => count( $good ),
				'failed' => count( $failed ),
			)
		);
	}

	/**
	 * @return \Lango7\Language|WP_Error
	 */
	private function lang( WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'lang' );
		$lang = '' === $code ? ( $this->languages->enabled()[0] ?? null ) : $this->languages->byCode( $code );
		return $lang ?? new WP_Error( 'lango7_language', __( 'Add a target language in Translate → Settings first.', 'lango7' ), array( 'status' => 400 ) );
	}
}
