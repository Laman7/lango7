<?php
/**
 * "Languages" column in Posts, Pages and other public post type lists:
 * how much of each published page is translated, with a link to edit it.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Html\UrlMatcher;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;
use Lango7\Store\Coverage;

final class PostsColumn {

	public const COLUMN = 'lango7';

	/** @var array<int, string|null> post ID => source path (null: no translated page). */
	private array $paths = array();

	private bool $batched = false;

	/** @var array<string, array{total: int, langs: array<string, array{done: int, edited: int, review: int}>}> */
	private array $counts = array();

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( 'load-edit.php', array( $this, 'load' ) );
	}

	public function load(): void {
		$type = (string) ( get_current_screen()->post_type ?? '' );
		if ( '' === $type || 'attachment' === $type || ! is_post_type_viewable( $type ) || ! $this->languages->enabled() ) {
			return;
		}
		add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'cell' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		if ( current_user_can( \Lango7\Plugin::CAPABILITY ) ) {
			add_filter( 'post_row_actions', array( $this, 'rowAction' ), 10, 2 );
			add_filter( 'page_row_actions', array( $this, 'rowAction' ), 10, 2 );
			add_filter( 'bulk_actions-edit-' . $type, array( $this, 'bulkActions' ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, array( $this, 'handleBulk' ), 10, 3 );
			add_action( 'admin_notices', array( $this, 'bulkNotice' ) );
		}
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function rowAction( array $actions, \WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) || 'trash' === $post->post_status ) {
			return $actions;
		}
		$off = \Lango7\Exclusions::isPost( $post->ID );
		$back = add_query_arg( array() );
		$actions['lango7_no_translate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( \Lango7\Exclusions::url( $post->ID, ! $off, is_string( $back ) ? $back : '' ) ),
			esc_html( $off ? __( 'Translate again', 'lango7' ) : __( 'Don\'t translate', 'lango7' ) )
		);
		return $actions;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function bulkActions( array $actions ): array {
		$actions['lango7_no_translate'] = __( 'Don\'t translate', 'lango7' );
		$actions['lango7_translate']    = __( 'Translate again', 'lango7' );
		return $actions;
	}

	/**
	 * @param int[] $ids
	 */
	public function handleBulk( string $redirect, string $action, array $ids ): string {
		if ( 'lango7_no_translate' !== $action && 'lango7_translate' !== $action ) {
			return $redirect;
		}
		$done = 0;
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', (int) $id ) ) {
				\Lango7\Exclusions::set( (int) $id, 'lango7_no_translate' === $action );
				++$done;
			}
		}
		return add_query_arg( array( 'lango7_excluded' => $done, 'lango7_off' => 'lango7_no_translate' === $action ? 1 : 0 ), $redirect );
	}

	public function bulkNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only shows a message.
		if ( ! isset( $_GET['lango7_excluded'] ) ) {
			return;
		}
		$n   = absint( wp_unslash( $_GET['lango7_excluded'] ) );
		$off = ! empty( $_GET['lango7_off'] );
		// phpcs:enable
		$msg = $off
			/* translators: %s: number of posts. */
			? _n( '%s item will not be translated. Visitors see it in the original language.', '%s items will not be translated. Visitors see them in the original language.', $n, 'lango7' )
			/* translators: %s: number of posts. */
			: _n( '%s item is translated again.', '%s items are translated again.', $n, 'lango7' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $msg, number_format_i18n( $n ) ) ) . '</p></div>';
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out[ self::COLUMN ] = __( 'Languages', 'lango7' );
			}
		}
		return $out + array( self::COLUMN => __( 'Languages', 'lango7' ) );
	}

	public function cell( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		if ( ! $this->batched ) {
			// Flat lists hold full posts: one batch for the screen. Hierarchical ones
			// (Pages) only hold IDs of every page, so their rows are read one by one.
			global $wp_query;
			$this->batched = true;
			$this->prime( array_filter( (array) ( $wp_query->posts ?? array() ), static fn( $p ): bool => $p instanceof \WP_Post ) );
		}
		if ( ! array_key_exists( $post_id, $this->paths ) ) {
			$post = get_post( $post_id );
			$this->prime( $post ? array( $post ) : array() );
		}
		$path = $this->paths[ $post_id ] ?? null;
		if ( null === $path ) {
			echo '<span class="lango7-col-note">&mdash;</span>';
			return;
		}
		if ( self::excluded( $path, $this->router, $this->settings ) ) {
			echo '<span class="lango7-col-note">' . esc_html__( 'Not translated (excluded in Settings)', 'lango7' ) . '</span>';
			return;
		}
		$data = $this->counts[ sha1( $path ) ] ?? null;
		if ( null === $data ) {
			echo '<span class="lango7-col-note" title="' . esc_attr__( 'Lango7 reads a page\'s text the first time it is opened in a translated language, or when it is translated.', 'lango7' ) . '">' . esc_html__( 'Not scanned yet', 'lango7' ) . '</span>';
		} else {
			foreach ( $this->languages->enabled() as $lang ) {
				echo $this->badge( $lang, $path, $data['total'], $data['langs'][ $lang->code ] ?? array( 'done' => 0, 'edited' => 0, 'review' => 0, 'unapproved' => 0 ), $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
			}
		}
		if ( null !== $data && Coverage::complete( $data, array_map( static fn( Language $l ): string => $l->code, $this->languages->enabled() ) ) ) {
			return;
		}
		$status = TranslateNow::status( $post_id );
		$url    = '' === $status ? TranslateNow::pageUrl( $post_id, $path ) : null;
		if ( '' !== $status ) {
			echo '<div class="lango7-col-row lango7-col-note">' . esc_html( $status ) . '</div>';
		} elseif ( null !== $url ) {
			echo '<div class="lango7-col-row"><a class="lango7-col-action" href="' . esc_url( $url ) . '">' . esc_html__( 'Translate now', 'lango7' ) . '</a></div>';
		}
	}

	/**
	 * @param array{done: int, edited: int, review: int, unapproved?: int} $c
	 */
	private function badge( Language $lang, string $path, int $total, array $c, int $post_id ): string {
		$percent = $total > 0 ? (int) floor( 100 * min( $c['done'], $total ) / $total ) : 0;
		$state   = $c['review'] > 0 || ( $percent > 0 && $percent < 100 ) ? 'is-partial' : ( 100 === $percent ? 'is-done' : 'is-none' );

		/* translators: 1: language name, 2: sentences translated, 3: sentences on the page. */
		$title = sprintf( __( '%1$s: %2$d of %3$d sentences translated', 'lango7' ), $lang->name, min( $c['done'], $total ), $total );
		if ( $c['edited'] ) {
			/* translators: %d: number of sentences. */
			$title .= ', ' . sprintf( _n( '%d edited by hand', '%d edited by hand', $c['edited'], 'lango7' ), $c['edited'] );
		}
		if ( $c['review'] ) {
			/* translators: %d: number of sentences. */
			$title .= ', ' . sprintf( _n( '%d to review', '%d to review', $c['review'], 'lango7' ), $c['review'] );
		}

		$waiting = (int) ( $c['unapproved'] ?? 0 );
		if ( $waiting ) {
			/* translators: %d: number of sentences. */
			$title .= ', ' . sprintf( _n( '%d awaiting approval', '%d awaiting approval', $waiting, 'lango7' ), $waiting );
		}

		/**
		 * Short notes shown after a language's progress in the Languages column, e.g. "Noindex".
		 *
		 * @param string[] $notes
		 * @param int      $post_id
		 * @param Language $lang
		 */
		$notes = array_filter( array_map( 'strval', (array) apply_filters( 'lango7_post_language_notes', array(), $post_id, $lang ) ) );

		$label = '<span class="lango7-col-code">' . esc_html( strtoupper( $lang->prefix ) ) . '</span> ' . esc_html( $percent . '%' );
		if ( $c['review'] ) {
			$label .= ' <span class="lango7-col-flag">' . esc_html__( 'review', 'lango7' ) . '</span>';
		}
		if ( $waiting ) {
			$label .= ' <span class="lango7-col-flag">' . esc_html__( 'approve', 'lango7' ) . '</span>';
		}
		/* translators: %s: language name. */
		$title .= '. ' . sprintf( __( 'Opens the %s page.', 'lango7' ), $lang->name );
		$out    = '<a class="lango7-col-badge ' . $state . '" href="' . esc_url( $this->router->urlFor( $lang, $path ) ) . '" target="_blank" title="' . esc_attr( $title ) . '">' . $label . '</a>';
		foreach ( $notes as $note ) {
			$out .= ' <span class="lango7-col-note">' . esc_html( $note ) . '</span>';
		}
		return '<div class="lango7-col-row">' . $out . '</div>';
	}

	/**
	 * Paths and counts for these posts.
	 *
	 * @param \WP_Post[] $posts
	 */
	private function prime( array $posts ): void {
		$new = array();
		foreach ( $posts as $post ) {
			$path                     = self::sourcePath( $post, $this->router );
			$this->paths[ $post->ID ] = $path;
			if ( null !== $path ) {
				$new[] = $path;
			}
		}
		if ( ! $new ) {
			return;
		}

		$this->counts = Coverage::forPaths( $new ) + $this->counts;
	}

	/**
	 * A published post's source path, e.g. "/contact/", or null when it has no
	 * translated pages (not published, password protected, plain permalinks).
	 */
	public static function sourcePath( \WP_Post $post, LanguageRouter $router ): ?string {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}
		if ( $post->ID === (int) get_option( 'page_on_front' ) && 'page' === get_option( 'show_on_front' ) ) {
			return $router->homePath() . '/';
		}
		$url  = (string) get_permalink( $post );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $path || null !== wp_parse_url( $url, PHP_URL_QUERY ) ? null : $path; // Plain permalinks (?p=) have no language folder.
	}

	/**
	 * Whether a source path is excluded from translation in Settings.
	 */
	public static function excluded( string $path, LanguageRouter $router, Settings $settings ): bool {
		$rel = (string) UrlMatcher::relative( $path, $router->homePath() );
		return UrlMatcher::matches( $rel, \Lango7\Exclusions::patterns() );
	}

	public function styles( string $hook = '' ): void {
		if ( 'edit.php' !== $hook ) {
			return; // Only the post and page lists have the column.
		}
		wp_enqueue_style( 'lango7-posts-column', LANGO7_URL . 'assets/admin/posts-column.css', array(), \Lango7\Plugin::assetVersion( 'assets/admin/posts-column.css' ) );
	}
}
