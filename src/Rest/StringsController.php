<?php
/**
 * lango7/v1/strings: the Strings screen's list, retranslate and clean-up.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Rest;

use Lango7\Cache\Purger;
use Lango7\Editor\EditMode;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Router\LanguageRouter;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Editing a row goes through lango7/v1/translations, like the front-end
 * editor, so both share one set of checks.
 */
final class StringsController {

	public const PER_PAGE = 50;

	/** Strings not seen on any page for this long can be deleted. */
	public const ORPHAN_DAYS = 90;

	private const STATUSES = array( '', 'machine', 'unapproved', 'edited', 'untranslated', 'failed' );

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private StringRepository $strings,
		private TranslationRepository $translations,
		private ?\Closure $translator = null
	) {}

	public function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			AdminController::NS,
			'/strings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => $admin,
				'args'                => array(
					'lang'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'q'      => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 200,
					),
					'status' => array(
						'type'    => 'string',
						'enum'    => self::STATUSES,
						'default' => '',
					),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'engine' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/strings/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translateNow' ),
				'permission_callback' => $admin,
				'args'                => array(
					'lang'   => array( 'type' => 'string', 'required' => true ),
					'hashes' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/strings/retranslate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retranslate' ),
				'permission_callback' => $admin,
				'args'                => array(
					'lang'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'hashes' => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{40}$',
						),
						'required' => true,
						'maxItems' => 500,
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/strings/orphans',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'deleteOrphans' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * The services that translated this language, for the engine filter.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function enginesFor( string $lang ): array {
		$registry = \Lango7\Plugin::instance()->engines();
		$out      = array();
		foreach ( $this->translations->enginesUsed( $lang ) as $id ) {
			$out[] = array(
				'id'    => $id,
				'label' => $registry->serviceLabel( $id ),
			);
		}
		usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] ) );
		return $out;
	}

	public function list( WP_REST_Request $request ) {
		$lang = $this->language( $request );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}
		$page   = (int) $request['page'];
		$result = $this->translations->search( $lang->code, trim( (string) $request['q'] ), (string) $request['status'], ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE, (string) $request['engine'] );
		$paths  = $this->strings->lastPaths( array_map( 'intval', array_column( $result['rows'], 'id' ) ) );

		$rows = array();
		foreach ( $result['rows'] as $r ) {
			$path   = $paths[ (int) $r['id'] ] ?? null;
			$rows[] = array(
				'hash'    => $r['hash'],
				'kind'    => $r['kind'],
				'source'  => $r['source_text'],
				'text'    => $r['translated'],
				'status'  => $this->status( $r['status'] ),
				'engine'  => $r['engine'],
				'updated' => $r['updated_at'],
				'seen'    => $r['last_seen_at'],
				'edit'    => null === $path ? null : EditMode::link( $this->router->urlFor( $lang, $path ) ),
			);
		}

		return new WP_REST_Response(
			array(
				'rows'    => $rows,
				'total'   => $result['total'],
				'page'    => $page,
				'pages'   => max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) ),
				'counts'  => $this->counts( $lang ),
				'orphans' => $this->strings->orphanCount( self::ORPHAN_DAYS ),
				'engines' => $this->enginesFor( $lang->code ),
			)
		);
	}

	/**
	 * Drop machine translations so they translate again. Edits are kept.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function retranslate( WP_REST_Request $request ) {
		$lang = $this->language( $request );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}
		$ids   = array_map( static fn( array $r ): int => (int) $r['id'], $this->strings->byHashes( (array) $request['hashes'] ) );
		$reset = $this->translations->resetStrings( $ids, $lang->code );
		if ( $reset ) {
			Purger::purgeUrls( array_map( fn( string $p ): string => $this->router->urlFor( $lang, $p ), $this->strings->urlsFor( $ids ) ) );
		}
		return new WP_REST_Response(
			array(
				'reset'  => $reset,
				'counts' => $this->counts( $lang ),
			)
		);
	}

	/**
	 * Translate the chosen strings with the language's engine (else the default).
	 * Keep the previous result, including an edit, until a valid replacement exists.
	 */
	public function translateNow( WP_REST_Request $request ) {
		$lang = $this->language( $request );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}
		$segments = array();
		$ids      = array();
		foreach ( $this->strings->byHashes( array_slice( (array) $request['hashes'], 0, 100 ) ) as $row ) {
			$segments[ $row['hash'] ] = new \Lango7\Translation\Segment( $row['hash'], $row['source_text'], $row['kind'] );
			$ids[ $row['hash'] ]      = (int) $row['id'];
		}
		if ( ! $ids || null === $this->translator ) {
			return new WP_Error( 'lango7_none', __( 'Nothing to translate.', 'lango7' ), array( 'status' => 400 ) );
		}
		$result = ( $this->translator )()->translate( $segments, $ids, $lang, microtime( true ) + 20, '', false );
		if ( 'unconfigured' === $result['stopped'] ) {
			return new WP_Error( 'lango7_engine', __( 'No translation service is set up for this language.', 'lango7' ), array( 'status' => 400 ) );
		}
		if ( $result['translated'] ) {
			$good = array();
			foreach ( $result['translated'] as $hash => $text ) {
				$id = $ids[ $hash ];
				$this->translations->forget( $lang->code, $id );
				$good[ $id ] = $text;
			}
			$this->translations->saveMachine( $lang->code, $good, (string) $result['engine'] );
			$done = array_keys( $good );
			Purger::bump();
			Purger::purgeUrls( array_map( fn( string $p ): string => $this->router->urlFor( $lang, $p ), $this->strings->urlsFor( $done ) ) );
			do_action( 'lango7_translations_saved', $lang, array_keys( $result['translated'] ), '' );
		}
		return new WP_REST_Response(
			array(
				'translated' => count( $result['translated'] ),
				'failed'     => count( $result['failed'] ),
				'stopped'    => $result['stopped'],
				'counts'     => $this->counts( $lang ),
			)
		);
	}

	public function deleteOrphans(): WP_REST_Response {
		return new WP_REST_Response( array( 'deleted' => $this->strings->deleteOrphans( self::ORPHAN_DAYS ) ) );
	}

	/**
	 * @return array<string, int> Count per filter tab.
	 */
	private function counts( Language $lang ): array {
		$by    = $this->translations->statusCounts( $lang->code );
		$total = $this->strings->count();
		return array(
			''             => $total,
			'machine'      => ( $by['machine'] ?? 0 ) + ( $by['needs_review'] ?? 0 ) + ( $by[ TranslationRepository::UNAPPROVED ] ?? 0 ),
			'unapproved'   => $by[ TranslationRepository::UNAPPROVED ] ?? 0,
			'edited'       => ( $by['edited'] ?? 0 ) + ( $by['ai_edited'] ?? 0 ),
			'failed'       => $by[ TranslationRepository::FAILED ] ?? 0,
			'untranslated' => max( 0, $total - array_sum( $by ) ),
		);
	}

	private function status( ?string $status ): string {
		return match ( $status ) {
			null                          => 'untranslated',
			'edited', 'ai_edited'         => 'edited',
			TranslationRepository::FAILED => 'failed',
			TranslationRepository::UNAPPROVED => 'unapproved',
			default                       => 'machine',
		};
	}

	/**
	 * @return Language|WP_Error
	 */
	private function language( WP_REST_Request $request ) {
		$lang = $this->languages->byCode( (string) $request['lang'] );
		if ( null === $lang || $lang->code === $this->languages->sourceCode() ) {
			return new WP_Error( 'lango7_lang', __( 'That language is not on your site.', 'lango7' ), array( 'status' => 400 ) );
		}
		return $lang;
	}
}
