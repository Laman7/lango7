<?php
/**
 * "Lango7" panel in the block editor's post sidebar: each language's progress,
 * a link that opens the translated page in a new tab, edit mode, and
 * Translate now with the site's chosen service.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Editor\EditMode;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Plugin;
use Lango7\Rest\AdminController;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;
use Lango7\Cache\Purger;
use Lango7\Store\Coverage;
use Lango7\Tools\SitePages;
use Lango7\Translation\Approval;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class EditorPanel {

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'assets' ) );
	}

	public function routes(): void {
		register_rest_route(
			AdminController::NS,
			'/editor/post/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'data' ),
				'permission_callback' => static fn( WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r['id'] ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/editor/post/(?P<id>\d+)/no-translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'noTranslate' ),
				'permission_callback' => static fn( WP_REST_Request $r ): bool => current_user_can( Plugin::CAPABILITY ) && current_user_can( 'edit_post', (int) $r['id'] ),
				'args'                => array(
					'id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'off' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/editor/post/(?P<id>\d+)/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => static fn( WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r['id'] ) && current_user_can( Plugin::LANGUAGE_CAPABILITY, (string) $r['lang'] ),
				'args'                => array(
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'lang' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			AdminController::NS,
			'/editor/post/(?P<id>\d+)/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => static fn( WP_REST_Request $r ): bool => current_user_can( 'edit_post', (int) $r['id'] ),
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'lang'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'engine' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	public function assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = (string) ( $screen->post_type ?? '' );
		if ( '' === $type || 'attachment' === $type || ! is_post_type_viewable( $type ) || ! $this->languages->enabled() ) {
			return;
		}
		wp_enqueue_script(
			'lango7-editor-panel',
			LANGO7_URL . 'assets/admin/editor-panel.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-editor', 'wp-edit-post' ),
			Plugin::assetVersion( 'assets/admin/editor-panel.js' ),
			true
		);
		wp_enqueue_style( 'lango7-editor-panel', LANGO7_URL . 'assets/admin/editor-panel.css', array(), Plugin::assetVersion( 'assets/admin/editor-panel.css' ) );
		wp_add_inline_script(
			'lango7-editor-panel',
			'window.lango7Panel = ' . wp_json_encode(
				array(
					'path' => '/' . AdminController::NS . '/editor/post/',
					'i18n' => array(
						'title'       => __( 'Lango7 Translation', 'lango7' ),
						'pick'        => __( 'Click a language to view, edit or translate this page.', 'lango7' ),
						'draft'       => __( 'Not published yet. You can translate it now; the translation shows when you publish.', 'lango7' ),
						'viewPage'    => __( 'View the page', 'lango7' ),
						'editOnPage'  => __( 'Edit on page', 'lango7' ),
						'afterPublish' => __( 'after publishing', 'lango7' ),
						'noRights'    => __( 'you cannot edit this language', 'lango7' ),
						'translatePage' => __( 'Translate page with', 'lango7' ),
						'service'     => __( 'Translation service', 'lango7' ),
						'translateBtn' => __( 'Translate page', 'lango7' ),
						/* translators: 1: sentences translated so far, 2: sentences to translate. */
						'translating' => __( 'Translating… %1$d of %2$d', 'lango7' ),
						/* translators: %d: number of sentences. */
						'translated'  => __( 'Done: %d sentences translated.', 'lango7' ),
						/* translators: %d: number of sentences. */
						'someFailed'  => __( '%d sentences could not be translated. See Translate → Activity.', 'lango7' ),
						'nothing'     => __( 'Already translated. Nothing was sent.', 'lango7' ),
						'deleteLink'  => __( 'Delete Translation', 'lango7' ),
						/* translators: %s: language name. */
						'deleteAsk'   => __( 'Delete the %s translation of this page, including hand edits? This cannot be undone. Text shared with other pages, such as the menu and footer, keeps its translation.', 'lango7' ),
						/* translators: %d: number of translations. */
						'deleted'     => __( 'Deleted %d translations.', 'lango7' ),
						/* translators: %d: number of sentences. */
						'keptShared'  => __( '%d shared with other pages were kept.', 'lango7' ),
						/* translators: 1: number of sentences, 2: page addresses. */
						'deletedNone' => __( 'Nothing deleted: %1$d sentences on this page also appear on other pages (%2$s), so their translations were kept. Delete the translation there, or change those pages first.', 'lango7' ),
						'deleting'    => __( 'Deleting…', 'lango7' ),
						'updated'     => __( 'Updated.', 'lango7' ),
						'loading'     => __( 'Loading…', 'lango7' ),
						'error'       => __( 'Could not load the translation status.', 'lango7' ),
						'unpublished' => __( 'Publish this page to translate it. Translated pages follow the published page.', 'lango7' ),
						'noPath'      => __( 'This page has no translated versions (password protected, or plain permalinks).', 'lango7' ),
						'excluded'    => __( 'Not translated: this page is excluded in Lango7 Settings.', 'lango7' ),
						'blocked'     => __( 'Not translated: visitors see this page in the original language in every language. Its translations are kept and come back when you turn this off.', 'lango7' ),
						'noTranslate' => __( 'Don\'t translate this page', 'lango7' ),
						'noTranslateHelp' => __( 'Show the original language in every language. Translations are kept.', 'lango7' ),
						'notScanned'  => __( 'Not scanned yet. Translate it now, or open a language to start.', 'lango7' ),
						/* translators: %s: language name, e.g. Malay. */
						'view'        => __( 'View %s', 'lango7' ),
						'edit'        => __( 'Edit translations', 'lango7' ),
						'translate'   => __( 'Translate now', 'lango7' ),
						'working'     => __( 'Starting…', 'lango7' ),
						'started'     => __( 'Translation started. This panel updates when it is done.', 'lango7' ),
						'opened'      => __( 'Free Translate opened in a new tab. Keep it open until it finishes.', 'lango7' ),
						'complete'    => __( 'Every sentence is translated.', 'lango7' ),
						'saveFirst'   => __( 'Save your changes first: translation uses the saved page.', 'lango7' ),
						'refresh'     => __( 'Refresh', 'lango7' ),
						/* translators: 1: sentences translated, 2: sentences on the page. */
						'progress'    => __( '%1$d of %2$d sentences', 'lango7' ),
						/* translators: %d: number of sentences. */
						'review'      => __( '%d to review', 'lango7' ),
						/* translators: %d: number of sentences. */
						'unapproved'  => __( '%d awaiting approval', 'lango7' ),
						'newTab'      => __( '(opens in a new tab)', 'lango7' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function data( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new WP_Error( 'lango7_not_found', __( 'Page not found.', 'lango7' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $this->build( $post ) );
	}

	/**
	 * Everything the panel shows for one post.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WP_Post $post ): array {
		$out = array(
			'state'     => 'ok',
			'total'     => 0,
			'languages' => array(),
			'translate' => null,
			'status'    => '',
			'complete'  => false,
		);
		$out['engines']  = $this->engines();
		$out['blocked']  = \Lango7\Exclusions::isPost( $post->ID );
		$out['canBlock'] = current_user_can( Plugin::CAPABILITY );
		if ( $out['blocked'] ) {
			$out['state'] = 'blocked';
			return $out;
		}
		if ( 'publish' !== $post->post_status ) {
			return $this->draft( $post, $out );
		}
		$path = PostsColumn::sourcePath( $post, $this->router );
		if ( null === $path ) {
			$out['state'] = 'nopath';
			return $out;
		}
		if ( PostsColumn::excluded( $path, $this->router, $this->settings ) ) {
			$out['state'] = 'excluded';
			return $out;
		}

		$langs  = $this->languages->enabled();
		$counts = Coverage::forPaths( array( $path ) )[ sha1( $path ) ] ?? null;
		if ( null === $counts ) {
			$out['state'] = 'notscanned';
		}
		$total        = (int) ( $counts['total'] ?? 0 );
		$out['total'] = $total;
		foreach ( $langs as $lang ) {
			$c                  = (array) ( $counts['langs'][ $lang->code ] ?? array() );
			$done               = min( (int) ( $c['done'] ?? 0 ), $total );
			$url                = $this->router->urlFor( $lang, $path );
			$row                = array(
				'code'       => $lang->code,
				'prefix'     => $lang->prefix,
				'name'       => $lang->name,
				'live'       => Languages::isLive( $lang ),
				'done'       => $done,
				'percent'    => $total > 0 ? (int) floor( 100 * $done / $total ) : 0,
				'edited'     => (int) ( $c['edited'] ?? 0 ),
				'review'     => (int) ( $c['review'] ?? 0 ),
				'unapproved' => (int) ( $c['unapproved'] ?? 0 ),
				'url'        => $url,
				'editUrl'    => current_user_can( Plugin::LANGUAGE_CAPABILITY, $lang->code ) ? EditMode::link( $url ) : null,
				/** This filter is documented in src/Admin/PostsColumn.php */
				'notes'      => array_values( array_filter( array_map( 'strval', (array) apply_filters( 'lango7_post_language_notes', array(), $post->ID, $lang ) ) ) ),
				'actions'    => array(),
				'engine'     => $this->defaultEngine( $lang ),
			);
			if ( $row['unapproved'] > 0 && Approval::can( $lang->code ) ) {
				$row['actions'][] = array(
					/* translators: %d: number of sentences. */
					'label'  => sprintf( _n( 'Approve %d sentence', 'Approve %d sentences', $row['unapproved'], 'lango7' ), $row['unapproved'] ),
					'path'   => '/' . AdminController::NS . '/approve',
					'method' => 'POST',
					'data'   => array(
						'lang' => $lang->code,
						'path' => $path,
					),
				);
			}
			$out['languages'][] = $row;
		}

		$out['complete'] = null !== $counts && Coverage::complete( $counts, array_map( static fn( Language $l ): string => $l->code, $langs ) );
		$out['status']   = TranslateNow::status( $post->ID );
		$url             = TranslateNow::pageUrl( $post->ID, $path );
		if ( null !== $url ) {
			$out['translate'] = array(
				'url'        => wp_specialchars_decode( $url ), // wp_nonce_url() escapes & for HTML.
				// Free Translate runs in a browser tab; server services start in the background.
				'background' => str_contains( $url, 'admin-post.php' ),
			);
		}

		/**
		 * Data for the block editor's Lango7 panel. Each language row can carry
		 * "notes" (short labels) and "actions": [{label, path, method, data}] buttons
		 * that call that REST path and then reload the panel.
		 *
		 * @param array<string, mixed> $out
		 * @param \WP_Post             $post
		 * @param string               $path Source path.
		 */
		return (array) apply_filters( 'lango7_editor_panel_data', $out, $post, $path );
	}

	/**
	 * Services the panel offers for "Translate page", id => label. Free Translate
	 * always; Lango7 Pro adds the server services that are set up.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function engines(): array {
		/**
		 * Services offered by the block editor's "Translate page".
		 *
		 * @param array<string, string> $engines id => label.
		 */
		$list = (array) apply_filters( 'lango7_editor_engines', array( 'chrome' => __( 'Free Translate (Chrome)', 'lango7' ) ) );
		$out  = array();
		foreach ( $list as $id => $label ) {
			$out[] = array(
				'id'    => (string) $id,
				'label' => (string) $label,
			);
		}
		return $out;
	}

	private function defaultEngine( Language $lang ): string {
		$ids = array_column( $this->engines(), 'id' );
		$id  = (string) apply_filters( 'lango7_engine_for_language', $lang->engine, $lang );
		return in_array( $id, $ids, true ) ? $id : (string) ( $ids[0] ?? 'chrome' );
	}

	/**
	 * A page that is not published has no public address yet: read its title
	 * and content directly. Translations are shared across the site, so what is
	 * translated now shows the moment the page is published.
	 */
	private static function draftHtml( \WP_Post $post ): string {
		$prev            = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the_content filters read it; restored below.
		setup_postdata( $post );
		$html = '<!DOCTYPE html><html><head><title></title></head><body><h1>' . esc_html( get_the_title( $post ) ) . '</h1>'
			. apply_filters( 'the_content', $post->post_content ) . '</body></html>'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$GLOBALS['post'] = $prev; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
		if ( $prev instanceof \WP_Post ) {
			setup_postdata( $prev );
		}
		return $html;
	}

	/**
	 * Status of an unpublished page, counted from its own content.
	 *
	 * @param array<string, mixed> $out
	 * @return array<string, mixed>
	 */
	private function draft( \WP_Post $post, array $out ): array {
		$out['state'] = 'draft';
		$plugin       = Plugin::instance();
		$hashes       = array_keys( $plugin->pipeline()->segmentsOf( self::draftHtml( $post ) ) );
		$total        = count( $hashes );
		$out['total'] = $total;
		foreach ( $this->languages->enabled() as $lang ) {
			$found              = $total ? array_filter( $plugin->translations()->lookup( $lang->code, '', $hashes, '' )['found'], static fn( $v ): bool => null !== $v ) : array();
			$done               = count( $found );
			$out['languages'][] = array(
				'code'       => $lang->code,
				'prefix'     => $lang->prefix,
				'name'       => $lang->name,
				'live'       => Languages::isLive( $lang ),
				'done'       => $done,
				'percent'    => $total > 0 ? (int) floor( 100 * $done / $total ) : 0,
				'edited'     => 0,
				'review'     => 0,
				'unapproved' => 0,
				'url'        => null,
				'editUrl'    => null,
				'notes'      => array_values( array_filter( array_map( 'strval', (array) apply_filters( 'lango7_post_language_notes', array(), $post->ID, $lang ) ) ) ),
				'actions'    => array(),
				'engine'     => $this->defaultEngine( $lang ),
			);
		}
		$out['complete'] = $total > 0 && ! array_filter( $out['languages'], static fn( array $l ): bool => $l['done'] < $total );
		return $out;
	}

	/**
	 * POST /editor/post/{id}/translate: translate one page into one language
	 * with the chosen service. Runs for up to 20 seconds; the panel calls again
	 * while "done" is false. Free Translate returns a link to open instead.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function translate( WP_REST_Request $request ) {
		$post   = get_post( (int) $request['id'] );
		$lang   = $this->languages->byCode( (string) $request['lang'] );
		$engine = (string) $request['engine'];
		$ids    = array_column( $this->engines(), 'id' );
		if ( ! $post || null === $lang ) {
			return new WP_Error( 'lango7_not_found', __( 'Page or language not found.', 'lango7' ), array( 'status' => 404 ) );
		}
		$engine = in_array( $engine, $ids, true ) ? $engine : $this->defaultEngine( $lang );
		$live   = 'publish' === $post->post_status;
		$path   = $live ? PostsColumn::sourcePath( $post, $this->router ) : null;
		if ( $live && ( null === $path || PostsColumn::excluded( $path, $this->router, $this->settings ) ) ) {
			return new WP_Error( 'lango7_excluded', __( 'This page is not translated (excluded, password protected or plain permalinks).', 'lango7' ), array( 'status' => 400 ) );
		}
		$plugin = Plugin::instance();

		if ( 'chrome' === $engine ) {
			$url = admin_url( 'admin.php?page=' . ChromePage::SLUG );
			if ( $live ) {
				$url = add_query_arg( 'path', rawurlencode( (string) $path ), $url );
			} else {
				// Record the draft's sentences so Free Translate finds them under "waiting".
				$segments = $plugin->pipeline()->segmentsOf( self::draftHtml( $post ) );
				$plugin->strings()->ensure( array_values( $segments ), $this->languages->sourceCode() );
				$url = add_query_arg( 'scope', 'pending', $url );
			}
			return new WP_REST_Response(
				array(
					'open' => $url,
					'done' => true,
				)
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'lango7_forbidden', __( 'Only administrators can translate with a paid service.', 'lango7' ), array( 'status' => 403 ) );
		}

		if ( $live ) {
			$html = ( new SitePages( $plugin ) )->fetch( (string) $path );
			if ( ! is_string( $html ) ) {
				return new WP_Error( 'lango7_fetch', $html->get_error_message(), array( 'status' => 502 ) );
			}
		} else {
			$html = self::draftHtml( $post );
		}
		$segments = $plugin->pipeline()->segmentsOf( $html );
		$string   = $plugin->strings()->ensure( array_values( $segments ), $this->languages->sourceCode() );
		$scope    = $live ? sha1( (string) $path ) : '';
		if ( $live ) {
			$plugin->strings()->touchUrls( array_values( $string ), $scope, (string) $path );
		}
		$look = $plugin->translations()->lookup( $lang->code, $scope, array_keys( $segments ), $scope );
		$miss = array_diff_key( $segments, array_filter( $look['found'], static fn( $v ): bool => null !== $v ) );
		if ( ! $miss ) {
			return new WP_REST_Response(
				array(
					'done'       => true,
					'translated' => 0,
					'remaining'  => 0,
				)
			);
		}

		$pick = static fn(): string => $engine;
		add_filter( 'lango7_engine_for_language', $pick, PHP_INT_MAX );
		try {
			$result = $plugin->translator()->translate( $miss, $string, $lang, microtime( true ) + 20, $live ? $this->router->urlFor( $lang, $path ) : '' );
		} finally {
			remove_filter( 'lango7_engine_for_language', $pick, PHP_INT_MAX );
		}
		$stopped = (string) ( $result['stopped'] ?? '' );
		if ( 'cap' === $stopped ) {
			return new WP_Error( 'lango7_cap', __( 'The daily limit is reached. Translation carries on tomorrow, or raise the limit in Settings.', 'lango7' ), array( 'status' => 429 ) );
		}
		if ( in_array( $stopped, array( 'engine', 'unconfigured' ), true ) ) {
			return new WP_Error( 'lango7_engine', __( 'The translation service returned an error. See Translate → Activity for details.', 'lango7' ), array( 'status' => 502 ) );
		}
		if ( $live && $result['translated'] ) {
			Purger::purgeUrls( array( $this->router->urlFor( $lang, $path ) ) );
		}
		$remaining = max( 0, count( $miss ) - count( $result['translated'] ) );
		return new WP_REST_Response(
			array(
				'done'       => 'time' !== $stopped,
				'translated' => count( $result['translated'] ),
				'failed'     => count( $result['failed'] ),
				'remaining'  => $remaining,
			)
		);
	}

	/**
	 * POST /editor/post/{id}/delete: remove one language's translations of this
	 * page, edits included. Text that also appears on other pages (menus,
	 * footers, shared blocks) keeps its translation, so other pages stay as they are.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Switch translation off or on for this post.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function noTranslate( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new WP_Error( 'lango7_post', __( 'Unknown post.', 'lango7' ), array( 'status' => 404 ) );
		}
		\Lango7\Exclusions::set( $post->ID, (bool) $request['off'] );
		return rest_ensure_response( $this->build( $post ) );
	}

	public function delete( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		$lang = $this->languages->byCode( (string) $request['lang'] );
		if ( ! $post || null === $lang ) {
			return new WP_Error( 'lango7_not_found', __( 'Page or language not found.', 'lango7' ), array( 'status' => 404 ) );
		}
		$plugin = Plugin::instance();
		$live   = 'publish' === $post->post_status;
		$path   = $live ? PostsColumn::sourcePath( $post, $this->router ) : null;
		if ( null !== $path ) {
			$html = ( new SitePages( $plugin ) )->fetch( $path );
			if ( ! is_string( $html ) ) {
				return new WP_Error( 'lango7_fetch', $html->get_error_message(), array( 'status' => 502 ) );
			}
		} else {
			$html = self::draftHtml( $post );
		}
		$segments = $plugin->pipeline()->segmentsOf( $html );
		$ids      = array_values( $plugin->strings()->ensure( array_values( $segments ), $this->languages->sourceCode() ) );
		$scope    = null !== $path ? sha1( $path ) : '';
		$shared   = $plugin->strings()->sharedElsewhere( $ids, $scope );
		$own      = array_values( array_diff( $ids, $shared ) );
		$deleted  = $plugin->translations()->deleteForPage( $lang->code, $own, $ids, $scope );
		if ( null !== $path ) {
			Purger::purgeUrls( array( $this->router->urlFor( $lang, $path ) ) );
		}
		\Lango7\Store\Activity::record(
			\Lango7\Store\Activity::EDITED,
			array(
				'lang'    => $lang->code,
				'url'     => (string) $path,
				/* translators: 1: number of translations, 2: page title. */
				'message' => sprintf( __( 'Deleted %1$d translations of “%2$s”.', 'lango7' ), $deleted, get_the_title( $post ) ),
			)
		);
		return new WP_REST_Response(
			array(
				'deleted' => $deleted,
				'shared'  => count( $shared ),
				'others'  => array_values( array_diff( $shared ? $plugin->strings()->urlsFor( $shared, 4 ) : array(), array( (string) $path ) ) ),
			)
		);
	}
}
