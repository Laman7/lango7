<?php
/**
 * News from lango7.com for the dashboard: one promotion and the latest blog posts.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Settings;

/**
 * Off by default. Only after an administrator turns it on (on the dashboard
 * or in Settings) does it read two public RSS feeds, at most twice a day, and
 * keep the result in a transient. Nothing about the site is sent.
 */
final class News {

	public const PROMO_FEED = 'https://lango7.com/category/promo/feed/';
	public const BLOG_FEED  = 'https://lango7.com/feed/';
	private const CACHE     = 'lango7_news';
	public const ACTION     = 'lango7_news';

	public function __construct( private Settings $settings ) {}

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	/** Turns news on or off from the dashboard card. */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lango7' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$on = isset( $_POST['news'] ) && '1' === sanitize_key( wp_unslash( (string) $_POST['news'] ) );
		\Lango7\Plugin::instance()->settings()->update( array( 'news' => $on ? 1 : 0 ) );
		delete_transient( self::CACHE );
		wp_safe_redirect( DashboardPage::url() );
		exit;
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'news' );
	}

	/**
	 * @return array{promo: ?array{title: string, url: string, text: string}, posts: list<array{title: string, url: string, date: string}>}
	 */
	public function items(): array {
		$empty = array(
			'promo' => null,
			'posts' => array(),
		);
		if ( ! $this->enabled() ) {
			return $empty;
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$promo = $this->read( self::PROMO_FEED, 1 );
		$posts = array();
		foreach ( $this->read( self::BLOG_FEED, 6 ) as $item ) {
			if ( ! $item['promo'] && count( $posts ) < 2 ) {
				$posts[] = array(
					'title' => $item['title'],
					'url'   => $item['url'],
					'date'  => $item['date'],
				);
			}
		}
		$data = array(
			'promo' => $promo ? array(
				'title' => $promo[0]['title'],
				'url'   => $promo[0]['url'],
				'text'  => $promo[0]['text'],
			) : null,
			'posts' => $posts,
		);
		// Try again in an hour when lango7.com could not be reached.
		set_transient( self::CACHE, $data, ( $promo || $posts ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * @return list<array{title: string, url: string, text: string, date: string, promo: bool}>
	 */
	private function read( string $url, int $max ): array {
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}
		$short = static function ( $feed ): void {
			$feed->set_timeout( 3 );
		};
		add_action( 'wp_feed_options', $short );
		$feed = fetch_feed( $url );
		remove_action( 'wp_feed_options', $short );
		if ( is_wp_error( $feed ) ) {
			return array();
		}
		$out = array();
		foreach ( $feed->get_items( 0, $max ) as $item ) {
			$link = (string) $item->get_permalink();
			if ( ! str_starts_with( $link, 'https://lango7.com/' ) ) {
				continue;
			}
			$cats = array_map( static fn( $c ): string => strtolower( (string) $c->get_term() ), (array) $item->get_categories() );
			$out[] = array(
				'title' => wp_strip_all_tags( html_entity_decode( (string) $item->get_title(), ENT_QUOTES, 'UTF-8' ) ),
				'url'   => $link,
				'text'  => wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 24 ),
				'date'  => (string) $item->get_date( get_option( 'date_format' ) ),
				'promo' => in_array( 'promo', $cats, true ),
			);
		}
		return $out;
	}
}
