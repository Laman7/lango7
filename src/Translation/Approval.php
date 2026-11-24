<?php
/**
 * Approving translations that wait for approval (Pro's "Require approval"):
 * one sentence, a selection, or every sentence on a page. Free stores no
 * unapproved text of its own, so this only acts on what Pro holds back.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

use Lango7\Store\Activity;
use Lango7\Cache\Purger;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Plugin;
use Lango7\Rest\AdminController;
use Lango7\Router\LanguageRouter;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Approval {

	public const ACTION = 'lango7_approve_page';

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private StringRepository $strings,
		private TranslationRepository $translations
	) {}

	/**
	 * Editors and administrators who can edit the language approve; translators
	 * (who edit only) do not.
	 */
	public static function can( string $code ): bool {
		$can = current_user_can( 'edit_others_posts' ) && current_user_can( Plugin::LANGUAGE_CAPABILITY, $code );

		/**
		 * Whether the current user may approve translations in a language.
		 *
		 * @param bool   $can
		 * @param string $code
		 */
		return (bool) apply_filters( 'lango7_can_approve', $can, $code );
	}

	public function routes(): void {
		register_rest_route(
			AdminController::NS,
			'/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest' ),
				'permission_callback' => static fn( WP_REST_Request $r ): bool => self::can( (string) $r['lang'] ),
				'args'                => array(
					'lang'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'path'   => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
					'hashes' => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{40}$',
						),
						'maxItems' => 500,
					),
				),
			)
		);
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest( WP_REST_Request $request ) {
		$lang = $this->languages->byCode( (string) $request['lang'] );
		if ( null === $lang ) {
			return new WP_Error( 'lango7_lang', __( 'That language is not on your site.', 'lango7' ), array( 'status' => 400 ) );
		}
		if ( null !== $request['hashes'] ) {
			$ids = array_map( static fn( array $r ): int => (int) $r['id'], $this->strings->byHashes( (array) $request['hashes'] ) );
			$n   = $this->approveIds( $lang, $ids );
		} elseif ( null !== $request['path'] ) {
			$n = $this->approvePage( $lang, (string) $request['path'] );
		} else {
			return new WP_Error( 'lango7_none', __( 'Nothing to approve.', 'lango7' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response(
			array(
				'approved' => $n,
				/* translators: %d: number of sentences. */
				'message'  => sprintf( _n( '%d sentence approved.', '%d sentences approved.', $n, 'lango7' ), $n ),
			)
		);
	}

	/**
	 * Approve every sentence on a page (source path, e.g. "/contact/").
	 */
	public function approvePage( Language $lang, string $path ): int {
		$path = '/' . ltrim( $path, '/' );
		return $this->approveIds( $lang, $this->translations->unapprovedOn( $lang->code, sha1( $path ) ), $path );
	}

	/**
	 * @param int[] $ids
	 */
	public function approveIds( Language $lang, array $ids, string $path = '' ): int {
		$n = $ids ? $this->translations->approve( $lang->code, $ids ) : 0;
		if ( $n ) {
			Purger::bump();
			$paths = '' !== $path ? array( $path ) : $this->strings->urlsFor( $ids );
			Purger::purgeUrls( array_map( fn( string $p ): string => $this->router->urlFor( $lang, $p ), $paths ) );

			/**
			 * Translations were approved.
			 *
			 * @param Language $lang
			 * @param int      $count
			 * @param string   $path Source path when a whole page was approved, else ''.
			 */
			do_action( 'lango7_approved', $lang, $n, $path );
			Activity::record(
				Activity::APPROVED,
				array(
					'lang'  => $lang->code,
					'url'   => $path,
					'items' => $n,
				)
			);
		}
		return $n;
	}

	/**
	 * Link that approves a page (admin bar on a translated page).
	 */
	public static function pageLink( Language $lang, string $path ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'lang'   => $lang->code,
					'path'   => rawurlencode( $path ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . $lang->code
		);
	}

	public function handle(): void {
		$code = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lang'] ) ) : '';
		check_admin_referer( self::ACTION . $code );
		$lang = $this->languages->byCode( $code );
		if ( null === $lang || ! self::can( $lang->code ) ) {
			wp_die( esc_html__( 'You cannot approve translations in this language.', 'lango7' ), '', array( 'response' => 403 ) );
		}
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ) : '/';
		$this->approvePage( $lang, $path );
		wp_safe_redirect( $this->router->urlFor( $lang, '/' . ltrim( $path, '/' ) ) );
		exit;
	}
}
