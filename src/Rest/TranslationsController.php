<?php
/**
 * lango7/v1/translations: saving edits from the front-end editor.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Rest;

use Lango7\Cache\Purger;
use Lango7\Engine\EngineRegistry;
use Lango7\Languages;
use Lango7\Plugin;
use Lango7\Router\LanguageRouter;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;
use Lango7\Translation\Placeholders;
use Lango7\Translation\Segment;
use Lango7\Translation\Translator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * An edit gets the same checks as engine output (it must keep every
 * placeholder, so a link cannot be broken, and may contain no markup) and is
 * stored locked: machine runs never overwrite it. Free edits are global.
 */
final class TranslationsController {

	/** Saves per user per minute. */
	private const RATE = 60;

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private StringRepository $strings,
		private TranslationRepository $translations,
		private Translator $translator,
		private ?EngineRegistry $engines = null
	) {}

	public function register(): void {
		$can = static fn(): bool => current_user_can( Plugin::CAPABILITY );
		register_rest_route(
			AdminController::NS,
			'/translations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => $can,
				'args'                => array(
					'hash' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[0-9a-f]{40}$',
					),
					'lang' => array(
						'type'     => 'string',
						'required' => true,
					),
					'text' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 20000,
					),
					'allow_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/translations/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revert' ),
				'permission_callback' => $can,
				'args'                => array(
					'hash' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[0-9a-f]{40}$',
					),
					'lang' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		$string = array(
			'hash' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[0-9a-f]{40}$',
			),
			'lang' => array(
				'type'     => 'string',
				'required' => true,
			),
			'path' => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 2048,
			),
		);
		register_rest_route(
			AdminController::NS,
			'/translations/now',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translateNow' ),
				'permission_callback' => $can,
				'args'                => $string + array(
					'service' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 64,
					),
					'text'    => array(
						'type'      => 'string',
						'maxLength' => 20000,
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/translations/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => $can,
				'args'                => $string + array(
					'previous' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$target = $this->target( $request );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		[ $lang, $row ] = $target;

		$key   = 'lango7_edits_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE ) {
			return new WP_Error( 'lango7_rate', __( 'Too many saves in a minute. Wait a moment and try again.', 'lango7' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		$text = Placeholders::clean( (string) $request['text'], true );
		$text = null === $text ? null : Placeholders::collapse( $text );
		$empty_edit = '' === $text && true === $request->get_param( 'allow_empty' );
		if ( null === $text || ( ! $empty_edit && ! Placeholders::validateEdit( $row['source_text'], $text ) ) ) {
			return new WP_Error( 'lango7_invalid', __( 'Each marked item (link, number, formatting) can appear only once, pairs must stay in order, and the translation can contain no HTML.', 'lango7' ), array( 'status' => 400 ) );
		}

		$this->translations->saveEdit( $lang->code, (int) $row['id'], $text, get_current_user_id() );
		\Lango7\Store\Activity::record(
			\Lango7\Store\Activity::EDITED,
			array(
				'lang'  => $lang->code,
				'url'   => (string) $request['path'],
				'items' => 1,
			)
		);
		$this->saved( $lang, $row );
		return new WP_REST_Response(
			array(
				'hash'   => $row['hash'],
				'text'   => $text,
				'status' => 'edited',
			)
		);
	}

	/**
	 * Remove the edit. With a server engine (Pro) the string is translated again
	 * right away; otherwise it waits for Translate with Chrome, and the editor
	 * translates it in the browser when it can.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function revert( WP_REST_Request $request ) {
		$target = $this->target( $request );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		[ $lang, $row ] = $target;

		$this->translations->forget( $lang->code, (int) $row['id'] );
		$result = $this->translator->translate(
			array( $row['hash'] => new Segment( $row['hash'], $row['source_text'], $row['kind'] ) ),
			array( $row['hash'] => (int) $row['id'] ),
			$lang,
			microtime( true ) + 10
		);
		$text = $result['translated'][ $row['hash'] ] ?? null;
		$this->saved( $lang, $row );
		return new WP_REST_Response(
			array(
				'hash'   => $row['hash'],
				'text'   => $text,
				'status' => null === $text ? 'pending' : TranslationRepository::machineStatus( $lang->code ),
			)
		);
	}

	/**
	 * Translate one string again now with a chosen service and replace what the
	 * site shows (an edit too, and this page's own edit). The answer carries the
	 * replaced version, so the editor can put it back (undo).
	 *
	 * Free Translate ('chrome') runs in the editor's browser and posts its text;
	 * a server engine (Pro) is called here. Nothing changes when it fails.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function translateNow( WP_REST_Request $request ) {
		$target = $this->target( $request );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		[ $lang, $row ] = $target;
		if ( $this->limited() ) {
			return new WP_Error( 'lango7_rate', __( 'Too many saves in a minute. Wait a moment and try again.', 'lango7' ), array( 'status' => 429 ) );
		}
		$service = (string) $request['service'];
		$source  = $this->languages->sourceCode();
		if ( null !== $this->engines && ! $this->engines->supports( $service, $lang->code, $source ) ) {
			return new WP_Error( 'lango7_engine', __( 'That service cannot translate this language.', 'lango7' ), array( 'status' => 400 ) );
		}

		if ( 'chrome' === $service ) {
			$text = Placeholders::clean( (string) $request['text'] );
			$text = null === $text ? null : Placeholders::collapse( $text );
			if ( null === $text || ! Placeholders::validate( $row['source_text'], $text ) ) {
				return new WP_Error( 'lango7_invalid', __( 'Free Translate returned a translation that breaks a link or formatting mark. Nothing was changed.', 'lango7' ), array( 'status' => 400 ) );
			}
		} else {
			$pick = static fn(): string => $service;
			add_filter( 'lango7_engine_for_language', $pick, 999 );
			try {
				$result = $this->translator->translate(
					array( $row['hash'] => new Segment( $row['hash'], $row['source_text'], $row['kind'] ) ),
					array( $row['hash'] => (int) $row['id'] ),
					$lang,
					microtime( true ) + 15,
					'',
					false
				);
			} finally {
				remove_filter( 'lango7_engine_for_language', $pick, 999 );
			}
			$text = $result['translated'][ $row['hash'] ] ?? null;
			if ( null === $text ) {
				$why = match ( $result['stopped'] ) {
					'cap'          => __( 'The daily limit for this service is reached. Nothing was changed.', 'lango7' ),
					'unconfigured' => __( 'That service is not set up. Nothing was changed.', 'lango7' ),
					default        => __( 'The service could not translate this text. Nothing was changed; see Settings → Debug.', 'lango7' ),
				};
				return new WP_Error( 'lango7_engine', $why, array( 'status' => 502 ) );
			}
		}

		$id       = (int) $row['id'];
		$scope    = '' !== (string) $request['path'] ? sha1( '/' . ltrim( (string) $request['path'], '/' ) ) : '';
		$site     = $this->translations->row( $lang->code, $id );
		$page     = '' !== $scope ? $this->translations->row( $lang->code, $id, $scope ) : null;
		$previous = array(
			'text'   => $site && 'failed' !== $site['status'] ? $site['translated'] : null,
			'status' => $site['status'] ?? 'pending',
			'engine' => $site['engine'] ?? null,
			'page'   => $page ? $page['translated'] : null,
		);

		if ( $page ) {
			$this->translations->forget( $lang->code, $id, $scope );
		}
		$this->translations->forget( $lang->code, $id );
		$this->translations->saveMachine( $lang->code, array( $id => $text ), $service );
		$this->saved( $lang, $row );
		return new WP_REST_Response(
			array(
				'hash'     => $row['hash'],
				'text'     => $text,
				'status'   => TranslationRepository::machineStatus( $lang->code ),
				'service'  => $service,
				'label'    => null !== $this->engines ? $this->engines->serviceLabel( $service ) : $service,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Put back the version a Translate now replaced (the "previous" it answered).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$target = $this->target( $request );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		[ $lang, $row ] = $target;
		if ( $this->limited() ) {
			return new WP_Error( 'lango7_rate', __( 'Too many saves in a minute. Wait a moment and try again.', 'lango7' ), array( 'status' => 429 ) );
		}
		$prev   = (array) $request['previous'];
		$status = (string) ( $prev['status'] ?? 'pending' );
		$text   = $this->checked( $row, $prev['text'] ?? null );
		$page   = $this->checked( $row, $prev['page'] ?? null );
		if ( false === $text || false === $page ) {
			return new WP_Error( 'lango7_invalid', __( 'The earlier translation could not be restored.', 'lango7' ), array( 'status' => 400 ) );
		}

		$id = (int) $row['id'];
		$this->translations->forget( $lang->code, $id );
		if ( null !== $text ) {
			if ( in_array( $status, array( 'edited', 'ai_edited' ), true ) ) {
				$this->translations->saveEdit( $lang->code, $id, $text, get_current_user_id() );
				$status = 'edited';
			} else {
				$this->translations->saveMachine( $lang->code, array( $id => $text ), (string) ( $prev['engine'] ?? '' ) ?: 'restored' );
				if ( 'needs_review' === $status ) {
					$this->translations->markStatus( $lang->code, array( $id ), 'needs_review' );
				} else {
					$status = TranslationRepository::machineStatus( $lang->code );
				}
			}
		} else {
			$status = 'pending';
		}
		$scoped = false;
		if ( null !== $page && '' !== (string) $request['path'] ) {
			$this->translations->saveEdit( $lang->code, $id, $page, get_current_user_id(), sha1( '/' . ltrim( (string) $request['path'], '/' ) ) );
			$scoped = true;
		}
		$this->saved( $lang, $row );
		return new WP_REST_Response(
			array(
				'hash'   => $row['hash'],
				'text'   => $scoped ? $page : $text,
				'status' => $scoped ? 'edited' : $status,
				'scoped' => $scoped,
			)
		);
	}

	/**
	 * A restored text after the same checks as an edit: null stays null, false
	 * when it is not valid for the string.
	 *
	 * @param array{source_text: string} $row
	 * @return string|null|false
	 */
	private function checked( array $row, $text ) {
		if ( null === $text ) {
			return null;
		}
		$clean = Placeholders::clean( (string) $text, true );
		$clean = null === $clean ? null : Placeholders::collapse( $clean );
		if ( null === $clean || ( '' !== $clean && ! Placeholders::validateEdit( $row['source_text'], $clean ) ) ) {
			return false;
		}
		return $clean;
	}

	/** Counts a save against the per-user limit; true when over it. */
	private function limited(): bool {
		$key   = 'lango7_edits_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * @return array{0: \Lango7\Language, 1: array{id: string, hash: string, source_text: string, kind: string}}|WP_Error
	 */
	private function target( WP_REST_Request $request ) {
		$lang = $this->languages->byCode( (string) $request['lang'] );
		if ( null === $lang ) {
			return new WP_Error( 'lango7_language', __( 'That language is not turned on.', 'lango7' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( Plugin::LANGUAGE_CAPABILITY, $lang->code ) ) {
			return new WP_Error( 'lango7_forbidden', __( 'You cannot edit this language.', 'lango7' ), array( 'status' => 403 ) );
		}
		$row = $this->strings->byHashes( array( (string) $request['hash'] ) )[0] ?? null;
		if ( null === $row ) {
			return new WP_Error( 'lango7_string', __( 'This text is no longer on the site. Reload the page.', 'lango7' ), array( 'status' => 404 ) );
		}
		return array( $lang, $row );
	}

	/**
	 * Every page showing the string is purged, since edits are global.
	 *
	 * @param array{id: string, hash: string} $row
	 */
	private function saved( \Lango7\Language $lang, array $row ): void {
		Purger::bump();
		Purger::purgeUrls( array_map( fn( string $path ): string => $this->router->urlFor( $lang, $path ), $this->strings->urlsFor( array( (int) $row['id'] ) ) ) );
		do_action( 'lango7_translations_saved', $lang, array( $row['hash'] ), '' );
	}
}
