<?php
/**
 * Background translation of whatever the time budget left over.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Queue;

use Lango7\Cache\Purger;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Router\LanguageRouter;
use Lango7\Store\StringRepository;
use Lango7\Translation\Segment;
use Lango7\Translation\Translator;

/**
 * The queue is an option of [ lang => [ hash => number values ] ]. Strings are
 * already stored by the time they are queued, so a job only needs the hashes;
 * the numbers ride along because the stored text has <nX/> where the page had
 * digits, and Google translates more naturally with the real number in place.
 *
 * Runs on WP-Cron. Each run spends at most 20 seconds, purges the page cache for
 * every URL that showed a newly translated string, and reschedules itself while
 * work remains (tomorrow, when the daily cap stopped it).
 */
final class Jobs {

	public const HOOK   = 'lango7_process_queue';
	public const OPTION = 'lango7_queue';
	private const MAX   = 5000;

	public function __construct(
		private Languages $languages,
		private StringRepository $strings,
		private Translator $translator,
		private LanguageRouter $router,
		private ?\Lango7\Store\RedoList $redo = null,
		private ?\Lango7\Store\TranslationRepository $translations = null
	) {}

	/**
	 * Translate strings again in the background, keeping each current
	 * translation until its new one is ready (glossary Retranslate).
	 *
	 * @param int[] $string_ids
	 */
	public function redo( string $lang, array $string_ids ): void {
		if ( null === $this->redo || ! $string_ids ) {
			return;
		}
		$this->redo->add( $lang, $string_ids );
		$this->schedule( 10 );
	}

	/**
	 * One slice of the redo list for a server-engine language. Only good
	 * results replace the current translation.
	 *
	 * @return int Seconds until the next run is needed, 0 when nothing is left.
	 */
	private function runRedo( Language $lang, float $deadline ): int {
		$ids = array_slice( $this->redo->ids( $lang->code ), 0, 300 );
		if ( ! $ids ) {
			return 0;
		}
		$segments = array();
		$found    = array();
		foreach ( $this->strings->byIds( $ids ) as $row ) {
			$segments[ $row['hash'] ] = new Segment( $row['hash'], $row['source_text'], $row['kind'] );
			$found[ $row['hash'] ]    = (int) $row['id'];
		}
		$result = $this->translator->translate( $segments, $found, $lang, $deadline, '', false );
		if ( 'unconfigured' === $result['stopped'] ) {
			return 0; // Free Translate (Chrome) picks these up instead.
		}
		$good = array();
		foreach ( $result['translated'] as $hash => $text ) {
			$good[ $found[ $hash ] ] = $text;
		}
		if ( $good && null !== $this->translations ) {
			$this->translations->saveMachine( $lang->code, $good, (string) $result['engine'] );
			do_action( 'lango7_translations_saved', $lang, array_keys( $result['translated'] ), '' );
			$urls = array();
			foreach ( $this->strings->urlsFor( array_keys( $good ) ) as $path ) {
				$urls[] = $this->router->urlFor( $lang, $path );
			}
			Purger::bump();
			Purger::purgeUrls( $urls );
		}
		$handled = array_merge( array_keys( $good ), array_map( static fn( string $h ): int => $found[ $h ], array_intersect( $result['failed'], array_keys( $found ) ) ) );
		// Strings that no longer exist are dropped too.
		$handled = array_merge( $handled, array_values( array_diff( $ids, array_values( $found ) ) ) );
		$this->redo->remove( $lang->code, $handled );

		if ( 'cap' === $result['stopped'] ) {
			return ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->getTimestamp() - time() + 300;
		}
		if ( 'engine' === $result['stopped'] ) {
			return 300;
		}
		return $this->redo->count( $lang->code ) ? 30 : 0;
	}

	/**
	 * @param Segment[] $segments
	 */
	public function enqueue( string $lang, array $segments ): void {
		$queue = $this->load();
		$items = $queue[ $lang ] ?? array();
		foreach ( $segments as $s ) {
			if ( count( $items ) >= self::MAX ) {
				break;
			}
			$items[ $s->hash ] = array_filter( $s->values, static fn( $k ): bool => 'n' === $k[0], ARRAY_FILTER_USE_KEY );
		}
		$queue[ $lang ] = $items;
		update_option( self::OPTION, $queue, false );
		$this->schedule( 10 );
	}

	public function forgetLanguage( string $lang ): void {
		$queue = $this->load();
		unset( $queue[ $lang ] );
		update_option( self::OPTION, $queue, false );
	}

	public function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	/**
	 * @return array<string, int> lang => queued strings.
	 */
	public function pending(): array {
		return array_map( 'count', $this->load() );
	}

	public function run(): void {
		$deadline = microtime( true ) + 20;
		$done     = array();
		$ids      = array();
		$again    = 0;

		foreach ( $this->load() as $code => $items ) {
			$lang = $this->languages->byCode( (string) $code );
			if ( null === $lang || ! $items ) {
				$done[ $code ] = array_keys( $items );
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				$again = 30;
				break;
			}

			$hashes   = array_slice( array_keys( $items ), 0, 300 );
			$segments = array();
			$found    = array();
			foreach ( $this->strings->byHashes( $hashes ) as $row ) {
				$segments[ $row['hash'] ] = new Segment( $row['hash'], $row['source_text'], $row['kind'], (array) $items[ $row['hash'] ] );
				$found[ $row['hash'] ]    = (int) $row['id'];
			}

			$result        = $this->translator->translate( $segments, $found, $lang, $deadline );
			$done[ $code ] = array_merge(
				array_keys( $result['translated'] ),
				$result['failed'],
				array_values( array_diff( $hashes, array_keys( $found ) ) )
			);
			foreach ( array_keys( $result['translated'] ) as $hash ) {
				$ids[ $code ][] = $found[ $hash ];
			}

			if ( 'cap' === $result['stopped'] ) {
				$again = max( $again, ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->getTimestamp() - time() + 300 );
			} elseif ( 'engine' === $result['stopped'] ) {
				$again = max( $again, 300 );
			} elseif ( 'unconfigured' === $result['stopped'] ) {
				$done[ $code ] = array_keys( $items );
			} elseif ( count( $items ) > count( $done[ $code ] ) ) {
				$again = max( $again, 30 );
			}
		}

		$this->forget( $done );

		if ( null !== $this->redo ) {
			foreach ( $this->redo->languages() as $code ) {
				$lang = $this->languages->byCode( $code );
				if ( null === $lang ) {
					$this->redo->forgetLanguage( $code );
					continue;
				}
				if ( microtime( true ) >= $deadline ) {
					$again = max( $again, 30 );
					break;
				}
				$wait = $this->runRedo( $lang, $deadline );
				if ( $wait > 0 ) {
					$again = max( $again, $wait );
				}
			}
		}

		$urls = array();
		foreach ( $ids as $code => $string_ids ) {
			$lang = $this->languages->byCode( (string) $code );
			foreach ( $this->strings->urlsFor( $string_ids ) as $path ) {
				$urls[] = $this->router->urlFor( $lang, $path );
			}
		}
		if ( $urls ) {
			Purger::bump();
			Purger::purgeUrls( $urls );
		}

		if ( $again > 0 && ( array_filter( $this->load() ) || ( null !== $this->redo && $this->redo->languages() ) ) ) {
			wp_schedule_single_event( time() + $again, self::HOOK );
		}
	}

	/**
	 * Remove processed hashes, re-reading the option so anything queued while
	 * this job ran is kept.
	 *
	 * @param array<string, string[]> $done
	 */
	private function forget( array $done ): void {
		wp_cache_delete( self::OPTION, 'options' );
		$queue = $this->load();
		foreach ( $done as $code => $hashes ) {
			foreach ( $hashes as $hash ) {
				unset( $queue[ $code ][ $hash ] );
			}
			if ( empty( $queue[ $code ] ) ) {
				unset( $queue[ $code ] );
			}
		}
		update_option( self::OPTION, $queue, false );
	}

	/**
	 * @return array<string, array<string, array<string, string>>>
	 */
	private function load(): array {
		$queue = get_option( self::OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}
}
