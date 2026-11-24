<?php
/**
 * Tools → Cost estimator: reads every public page of the site (in steps, so no
 * request runs long), counts its text, and prices it on every translation
 * service. Nothing is sent to a service.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Tools;

use Lango7\Plugin;
use Lango7\Store\Schema;
use Lango7\Translation\Placeholders;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Lango7's own tables; the totals are worked out once per scan.

final class CostEstimator {

	public const OPTION = 'lango7_cost_scan';

	/** Seconds of page reading per request. */
	private const STEP = 6.0;

	public function __construct( private Plugin $lango7 ) {}

	public function routes(): void {
		$can = static fn(): bool => current_user_can( Plugin::CAPABILITY );
		register_rest_route(
			'lango7/v1',
			'/tools/estimate',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn() => rest_ensure_response( $this->report() ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => 'POST',
					'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( $this->step( (bool) $r['restart'] ) ),
					'permission_callback' => $can,
					'args'                => array(
						'restart' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Prices per million characters. AI engines bill tokens: about 3 characters
	 * a token, 1.5× for the prompt and JSON around the text going in and 1.2×
	 * coming out (the same sums as Lango7 Pro's engines).
	 *
	 * @return array<int, array{id: string, group: string, label: string, per_million: float, note: string, pro: bool}>
	 */
	public static function services(): array {
		$ai  = static fn( float $in, float $out ): float => round( ( 1000000 / 3 ) * ( 1.5 * $in + 1.2 * $out ) / 1000000, 2 );
		$out = array(
			array( 'chrome', __( 'Free', 'lango7' ), __( 'Free Translate (Chrome on your computer)', 'lango7' ), 0.0, __( 'No cost. Runs in desktop Chrome; about 47 languages.', 'lango7' ), false ),
			array( 'google', __( 'Machine translation', 'lango7' ), 'Google Cloud Translation Basic', 20.0, __( 'The first 500,000 characters each month are free.', 'lango7' ), true ),
			array( 'google3', __( 'Machine translation', 'lango7' ), 'Google Cloud Translation Advanced (Translation LLM)', 20.0, __( 'Input and output characters are billed separately; check Google\'s price list.', 'lango7' ), true ),
			array( 'deepl', __( 'Machine translation', 'lango7' ), 'DeepL API Pro', 25.0, __( 'Plus DeepL\'s monthly fee. DeepL API Free: 500,000 characters a month at no cost.', 'lango7' ), true ),
			array( 'openai:gpt-5', __( 'AI', 'lango7' ), 'OpenAI GPT-5', $ai( 1.25, 10.0 ), '', true ),
			array( 'openai:gpt-5-mini', __( 'AI', 'lango7' ), 'OpenAI GPT-5 mini', $ai( 0.25, 2.0 ), '', true ),
			array( 'openai:gpt-5-nano', __( 'AI', 'lango7' ), 'OpenAI GPT-5 nano', $ai( 0.05, 0.40 ), '', true ),
			array( 'anthropic:claude-opus-5-5', __( 'AI', 'lango7' ), 'Claude Opus 5.5', $ai( 4.0, 20.0 ), '', true ),
			array( 'anthropic:claude-sonnet-5-5', __( 'AI', 'lango7' ), 'Claude Sonnet 5.5', $ai( 2.0, 10.0 ), '', true ),
			array( 'anthropic:claude-haiku-5-5', __( 'AI', 'lango7' ), 'Claude Haiku 5.5', $ai( 0.10, 0.50 ), '', true ),
			array( 'gemini:gemini-2.5-pro', __( 'AI', 'lango7' ), 'Gemini 2.5 Pro', $ai( 1.25, 10.0 ), '', true ),
			array( 'gemini:gemini-2.5-flash', __( 'AI', 'lango7' ), 'Gemini 2.5 Flash', $ai( 0.30, 2.50 ), '', true ),
			array( 'gemini:gemini-2.5-flash-lite', __( 'AI', 'lango7' ), 'Gemini 2.5 Flash-Lite', $ai( 0.10, 0.40 ), '', true ),
		);
		$out = array_map(
			static fn( array $s ): array => array_combine( array( 'id', 'group', 'label', 'per_million', 'note', 'pro' ), $s ),
			$out
		);
		/**
		 * The services and prices the cost estimator shows.
		 *
		 * @param array<int, array{id: string, group: string, label: string, per_million: float, note: string, pro: bool}> $out
		 */
		return (array) apply_filters( 'lango7_cost_services', $out );
	}

	public static function cost( int $chars, float $per_million ): float {
		return round( $chars * $per_million / 1000000, 2 );
	}

	/** Words in placeholder text: placeholders dropped, CJK characters counted one each. */
	public static function words( string $text ): int {
		$plain = Placeholders::decode( (string) preg_replace( Placeholders::TOKEN, ' ', $text ) );
		$cjk   = (int) preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', $plain );
		$plain = (string) preg_replace( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', ' ', $plain );
		return $cjk + count( preg_split( '/[^\p{L}\p{N}\'’-]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	}

	/** @return array<string, mixed> */
	private function state(): array {
		$s = get_option( self::OPTION );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Read pages for a few seconds. A restart (or no scan yet) lists the pages first.
	 *
	 * @return array<string, mixed>
	 */
	public function step( bool $restart ): array {
		$state = $this->state();
		if ( $restart || ! $state ) {
			$state = array(
				'urls'    => ( new SitePages( $this->lango7 ) )->paths(),
				'pos'     => 0,
				'failed'  => array(),
				'started' => time(),
				'done'    => 0,
			);
		}
		$deadline = microtime( true ) + self::STEP;
		$pages    = new SitePages( $this->lango7 );
		$src      = $this->lango7->languages()->sourceCode();
		while ( $state['pos'] < count( $state['urls'] ) && microtime( true ) < $deadline ) {
			$path = (string) $state['urls'][ $state['pos'] ];
			$html = $pages->fetch( $path );
			if ( is_string( $html ) ) {
				$segments = $this->lango7->pipeline()->segmentsOf( $html );
				$ids      = $this->lango7->strings()->ensure( array_values( $segments ), $src );
				$this->lango7->strings()->touchUrls( array_values( $ids ), sha1( $path ), $path );
			} else {
				$state['failed'][] = array(
					'path'  => $path,
					'error' => $html->get_error_message(),
				);
				$state['failed']   = array_slice( $state['failed'], -20 );
			}
			++$state['pos'];
		}
		if ( $state['pos'] >= count( $state['urls'] ) && ! $state['done'] ) {
			$state['done']   = time();
			$state['totals'] = $this->totals( $state['urls'] );
		}
		update_option( self::OPTION, $state, false );
		return $this->report();
	}

	/**
	 * What the screen shows: progress, and once done the counts and prices.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$state = $this->state();
		$langs = $this->lango7->languages()->enabled();
		$out   = array(
			'pages'     => count( $state['urls'] ?? array() ),
			'read'      => (int) ( $state['pos'] ?? 0 ),
			'failed'    => $state['failed'] ?? array(),
			'done'      => (int) ( $state['done'] ?? 0 ),
			'totals'    => $state['totals'] ?? null,
			'languages' => array_map(
				static fn( $l ): array => array(
					'code' => $l->code,
					'name' => $l->name,
				),
				$langs
			),
			'services'  => self::services(),
		);
		return $out;
	}

	/**
	 * Distinct text on the pages: strings, words, characters, and per language
	 * the characters that have no translation yet.
	 *
	 * @param string[] $paths
	 * @return array{strings: int, words: int, chars: int, todo: array<string, int>}
	 */
	private function totals( array $paths ): array {
		global $wpdb;
		$rows = array();
		foreach ( array_chunk( array_map( 'sha1', $paths ), 300 ) as $chunk ) {
			$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s or %d per spread $chunk item.
				'SELECT DISTINCT s.id, s.chars, s.source_text FROM %i u JOIN %i s ON s.id = u.string_id WHERE u.url_hash IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')',
				Schema::table( 'string_urls' ),
				Schema::table( 'strings' ),
				...$chunk
			);
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared just above.
				$rows[ (int) $r['id'] ] = $r;
			}
		}
		$words = 0;
		foreach ( $rows as $r ) {
			$words += self::words( (string) $r['source_text'] );
		}
		$chars = array_sum( array_map( static fn( array $r ): int => (int) $r['chars'], $rows ) );
		$todo  = array();
		foreach ( $this->lango7->languages()->enabled() as $lang ) {
			$done = array();
			foreach ( array_chunk( array_keys( $rows ), 500 ) as $chunk ) {
				$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s or %d per spread $chunk item.
					"SELECT DISTINCT string_id FROM %i WHERE lang = %s AND scope = '' AND string_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					Schema::table( 'translations' ),
					$lang->code,
					...$chunk
				);
				foreach ( (array) $wpdb->get_col( $sql ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared just above.
					$done[ (int) $id ] = true;
				}
			}
			$todo[ $lang->code ] = array_sum( array_map( static fn( array $r ): int => isset( $done[ (int) $r['id'] ] ) ? 0 : (int) $r['chars'], $rows ) );
		}
		return array(
			'strings' => count( $rows ),
			'words'   => $words,
			'chars'   => $chars,
			'todo'    => $todo,
		);
	}
}
