<?php
/**
 * wp lango7 …
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Cli;

use Lango7\Cache\Purger;
use Lango7\Plugin;
use WP_CLI;

/**
 * Manage Lango7 translations.
 */
final class Command {

	public function __construct( private Plugin $plugin ) {}

	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'lango7', new self( $plugin ) );
	}

	/**
	 * Translate a page now by requesting its translated URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Source URL or path, e.g. / or /contact/.
	 *
	 * [--lang=<code>]
	 * : Language code. Defaults to the first enabled language.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lango7 translate / --lang=ms
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function translate( array $args, array $assoc ): void {
		$lang = $this->lang( $assoc );
		$path = (string) wp_parse_url( $args[0], PHP_URL_PATH );
		$url  = $this->plugin->router()->urlFor( $lang, $this->plugin->router()->homePath() . ( '' === $path ? '/' : $path ) );
		$res  = wp_remote_get(
			$url,
			array(
				'timeout'    => 60,
				'user-agent' => 'Lango7-Warmup/' . LANGO7_VERSION,
				'sslverify'  => false,
			)
		);
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		$pending = 'pending' === wp_remote_retrieve_header( $res, 'x-lango7' );
		WP_CLI::log( sprintf( '%s → HTTP %d', $url, wp_remote_retrieve_response_code( $res ) ) );
		$pending ? WP_CLI::warning( 'Partly translated; the rest is queued. Run: wp lango7 process-queue' ) : WP_CLI::success( 'Fully translated.' );
	}

	/**
	 * Show strings, translation progress and usage.
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$s = $this->plugin->rest()->statusData();
		\WP_CLI\Utils\format_items( 'table', $s['languages'], array( 'code', 'strings', 'done', 'edited', 'failed', 'queued', 'url' ) );
		WP_CLI::log( sprintf( 'Characters today: %d / %s · this month: %d (≈ USD %.2f)', $s['chars_today'], $s['cap'] ? (string) $s['cap'] : 'no cap', $s['chars_month'], $s['cost_month'] ) );
		foreach ( $s['errors'] as $e ) {
			WP_CLI::log( $e['time'] . '  ' . $e['message'] );
		}
	}

	/**
	 * Purge page caches and the lookup cache.
	 *
	 * @subcommand purge
	 */
	public function purge(): void {
		Purger::purgeAll();
		WP_CLI::success( 'Caches purged.' );
	}

	/**
	 * Translate "Hello" with the configured engine.
	 *
	 * [--lang=<code>]
	 * : Target language code.
	 *
	 * @subcommand test-engine
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function test_engine( array $args, array $assoc ): void {
		$lang   = $this->lang( $assoc );
		$engine = $this->plugin->engines()->forLanguage( $lang );
		if ( null === $engine ) {
			WP_CLI::error( 'No server engine is registered. Lango7 Free translates in Chrome: Translate → Translate with Chrome.' );
		}
		$source = \Lango7\Languages::catalog()[ $this->plugin->languages()->sourceCode() ]['google'] ?? 'en';
		$result = $engine->test( $source, $lang->engine_code );
		$result->ok ? WP_CLI::success( 'Hello → ' . $result->sample ) : WP_CLI::error( $result->message );
	}

	/**
	 * Run the background translation queue now.
	 *
	 * @subcommand process-queue
	 */
	public function process_queue(): void {
		$this->plugin->jobs()->run();
		WP_CLI::success( 'Queue processed. Still queued: ' . array_sum( $this->plugin->jobs()->pending() ) );
	}

	/**
	 * List strings.
	 *
	 * ## OPTIONS
	 *
	 * <list>
	 * : The word "list".
	 *
	 * [--lang=<code>]
	 * : Language code.
	 *
	 * [--status=<status>]
	 * : machine, edited, failed or untranslated.
	 *
	 * [--limit=<n>]
	 * : Rows to show. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lango7 strings list --lang=ms --status=edited
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function strings( array $args, array $assoc ): void {
		if ( 'list' !== ( $args[0] ?? '' ) ) {
			WP_CLI::error( 'Usage: wp lango7 strings list [--lang=<code>] [--status=<status>]' );
		}
		$lang = $this->lang( $assoc );
		$rows = $this->plugin->translations()->listStrings( $lang->code, $assoc['status'] ?? null, (int) ( $assoc['limit'] ?? 50 ) );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'hash', 'kind', 'status', 'source_text', 'translated' ) );
	}

	/**
	 * @param array<string, string> $assoc
	 */
	private function lang( array $assoc ): \Lango7\Language {
		$langs = $this->plugin->languages();
		$lang  = isset( $assoc['lang'] ) ? $langs->byCode( $assoc['lang'] ) : ( $langs->enabled()[0] ?? null );
		if ( null === $lang ) {
			WP_CLI::error( 'No such enabled language. Enabled: ' . implode( ', ', array_map( static fn( $l ) => $l->code, $langs->enabled() ) ) );
		}
		return $lang;
	}
}
