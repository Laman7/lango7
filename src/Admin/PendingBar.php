<?php
/**
 * Language menu in the admin bar on the front end only, next to "Howdy": the language being viewed,
 * links to this page in every language (drafts included, for previewing), and
 * "Translate this page" when some of it is missing (a dot on the icon says so).
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Exclusions;
use Lango7\Html\UrlMatcher;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Plugin;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;
use Lango7\Store\Coverage;
use Lango7\Store\StringRepository;

final class PendingBar {

	private const CACHE = 'lango7_waiting';

	public function __construct(
		private Languages $languages,
		private LanguageRouter $router,
		private Settings $settings,
		private StringRepository $strings
	) {}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'node' ), 90 );
		add_action( 'admin_bar_init', array( $this, 'styles' ) );
		add_action( 'lango7_translations_saved', array( self::class, 'flush' ) );
		add_action( 'lango7_settings_saved', array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	public function node( \WP_Admin_Bar $bar ): void {
		$targets = $this->languages->enabled();
		if ( is_admin() || ! current_user_can( Plugin::CAPABILITY ) || ! $targets ) {
			return;
		}
		$source = $this->languages->source();
		$shown  = $this->router->current() ?? $source;
		$page   = $this->page();
		$dot    = null !== $page ? '<span class="lango7-ab-dot" aria-hidden="true"></span>' : '';

		$bar->add_node(
			array(
				'id'     => 'lango7-pending',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label" lang="' . esc_attr( $shown->code ) . '">' . esc_html( $shown->native_name ? $shown->native_name : $shown->name ) . '</span>' . $dot,
				'href'   => false,
				'meta'   => array( 'title' => null !== $page ? __( 'Some text on this page isn\'t translated yet.', 'lango7' ) : __( 'View this page in another language', 'lango7' ) ),
			)
		);
		$bar->add_node(
			array(
				'id'     => 'lango7-view-head',
				'parent' => 'lango7-pending',
				'title'  => '<span class="lango7-ab-head">' . esc_html__( 'View in', 'lango7' ) . '</span>',
				'href'   => false,
			)
		);
		foreach ( array_merge( array( null ), $targets ) as $lang ) {
			$obj  = $lang ?? $source;
			$name = $obj->native_name ? $obj->native_name : $obj->name;
			if ( $obj->native_name && $obj->native_name !== $obj->name ) {
				$name .= ' (' . $obj->name . ')';
			}
			if ( null === $lang ) {
				$name .= ' · ' . __( 'original', 'lango7' );
			} elseif ( ! Languages::isLive( $lang ) ) {
				$name .= ' · ' . __( 'Draft', 'lango7' );
			}
			$here = $obj->code === $shown->code;
			$bar->add_node(
				array(
					'id'     => 'lango7-view-' . sanitize_html_class( $obj->code ),
					'parent' => 'lango7-pending',
					'title'  => esc_html( $name ) . ( $here ? ' <span aria-label="' . esc_attr__( 'current', 'lango7' ) . '">✓</span>' : '' ),
					'href'   => $this->router->urlFor( $lang ),
				)
			);
		}

		if ( null !== $page ) {
			$status = TranslateNow::status( $page[0] );
			$url    = '' === $status ? TranslateNow::pageUrl( $page[0], $page[1] ) : null;
			if ( '' !== $status || null !== $url ) {
				$bar->add_node(
					array(
						'id'     => 'lango7-pending-page',
						'parent' => 'lango7-pending',
						'title'  => esc_html( '' !== $status ? $status : __( 'Translate this page', 'lango7' ) ),
						'href'   => $url ?? false,
						'meta'   => array( 'class' => 'lango7-ab-sep' ),
					)
				);
			}
		}
		$this->blockNode( $bar, null !== $page );
	}

	/**
	 * "Don't translate this page" (or back on) under Translate this page.
	 */
	private function blockNode( \WP_Admin_Bar $bar, bool $after_translate ): void {
		if ( is_admin() || is_404() || is_search() || is_preview() ) {
			return;
		}
		$source = $this->router->sourcePath();
		$rel    = UrlMatcher::relative( $source, $this->router->homePath() );
		if ( null === $rel ) {
			return;
		}
		$post = is_singular() ? (int) get_queried_object_id() : 0;
		$back = $this->router->urlFor( $this->router->current(), $source );
		$meta = array( 'class' => $after_translate ? '' : 'lango7-ab-sep' );
		if ( $post && Exclusions::isPost( $post ) ) {
			$title = __( 'Translate this page again', 'lango7' );
			$href  = current_user_can( 'edit_post', $post ) ? Exclusions::url( $post, false, $back ) : false;
			$meta['title'] = __( 'This page is not translated. Visitors see it in the original language.', 'lango7' );
		} elseif ( Exclusions::inSettingsExactly( $rel ) && current_user_can( 'manage_options' ) ) {
			$title = __( 'Translate this page again', 'lango7' );
			$href  = Exclusions::pathUrl( $rel, false, $back );
			$meta['title'] = __( 'This address is listed under Do not translate URLs in Settings. This removes it from the list.', 'lango7' );
		} elseif ( Exclusions::inSettings( $rel ) ) {
			$title = __( 'Not translated (Settings → Exclusions)', 'lango7' );
			$href  = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=limits' ) : false;
			$meta['title'] = __( 'This address is listed under Do not translate URLs.', 'lango7' );
		} elseif ( $post && current_user_can( 'edit_post', $post ) ) {
			$title = __( 'Don\'t translate this page', 'lango7' );
			$href  = Exclusions::url( $post, true, $back );
			$meta['title'] = __( 'Visitors see this page in the original language in every language. Its translations are kept and come back if you turn translation on again.', 'lango7' );
		} elseif ( ! $post && current_user_can( 'manage_options' ) ) {
			// An archive, the shop page or another address without a post: add it to the Settings list.
			$title = __( 'Don\'t translate this page', 'lango7' );
			$href  = Exclusions::pathUrl( $rel, true, $back );
			$meta['title'] = __( 'Adds this address to Do not translate URLs in Settings. Visitors see it in the original language.', 'lango7' );
		} else {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'lango7-no-translate',
				'parent' => 'lango7-pending',
				'title'  => esc_html( $title ),
				'href'   => $href,
				'meta'   => $meta,
			)
		);
	}

	/**
	 * The page being viewed, when some of it is not translated: [post ID or 0, source path].
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private function page(): ?array {
		if ( is_404() || is_search() || is_preview() ) {
			return null;
		}
		$path = $this->router->sourcePath();
		$rel  = UrlMatcher::relative( $path, $this->router->homePath() );
		if ( null === $rel || UrlMatcher::matches( $rel, \Lango7\Exclusions::patterns() ) ) {
			return null;
		}
		$codes  = array_map( static fn( Language $l ): string => $l->code, $this->languages->enabled() );
		$counts = Coverage::forPaths( array( $path ) )[ sha1( $path ) ] ?? null;
		if ( null !== $counts && Coverage::complete( $counts, $codes ) ) {
			return null;
		}
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		return array( $id, $path );
	}

	public function styles(): void {
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-lango7-pending .ab-icon:before { top: 2px; }
#wpadminbar .lango7-ab-dot { display: inline-block; width: 7px; height: 7px; margin-left: 6px; border-radius: 50%; background: #f0b849; vertical-align: middle; }
#wpadminbar .lango7-ab-head { color: #a7aaad; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
#wpadminbar .lango7-ab-sep { border-top: 1px solid rgba(255,255,255,.15); margin-top: 4px; padding-top: 4px; }
@media screen and (max-width: 782px) { #wpadminbar #wp-admin-bar-lango7-pending { display: block; } #wpadminbar #wp-admin-bar-lango7-pending .ab-label { display: none; } #wpadminbar #wp-admin-bar-lango7-pending .ab-icon { margin-right: 0; } }'
		);
	}
}
