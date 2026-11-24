<?php
/**
 * Sends missing segments to the engine and stores the results.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

use Lango7\Store\Activity;
use Lango7\Engine\Context;
use Lango7\Engine\EngineException;
use Lango7\Engine\EngineRegistry;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Log;
use Lango7\Security\SpendCap;
use Lango7\Store\TranslationRepository;

/**
 * Every result is checked before it is stored: placeholders must come back
 * exactly once and properly nested, and the text may contain no markup of its
 * own. A segment that fails gets one more try on its own; if that fails too it is
 * stored as "failed" and renders in the source language, so a broken engine
 * reply can never break a link or inject a tag.
 */
final class Translator {

	public function __construct(
		private EngineRegistry $engines,
		private TranslationRepository $translations,
		private SpendCap $cap,
		private Glossary $glossary,
		private Languages $languages,
		private int $batch_strings = 100
	) {}

	/**
	 * @param array<string, Segment> $segments Keyed by hash.
	 * @param array<string, int>     $ids      hash => string id; segments without an id are skipped.
	 * @param float                  $deadline microtime(true) after which no new engine call starts.
	 * @param bool                   $persist  False when an admin wants to replace an existing row only after validation.
	 * @return array{translated: array<string, string>, failed: string[], stopped: ?string, engine: ?string}
	 *         stopped: null when everything was attempted, else 'time', 'cap', 'engine' or 'unconfigured'.
	 */
	public function translate( array $segments, array $ids, Language $lang, float $deadline, string $page_url = '', bool $persist = true ): array {
		$result = array(
			'translated' => array(),
			'failed'     => array(),
			'stopped'    => null,
			'engine'     => null,
		);
		$engine = $this->engines->forLanguage( $lang );
		if ( null === $engine || ! $engine->isConfigured() ) {
			$result['stopped'] = 'unconfigured';
			return $result;
		}
		$result['engine'] = $engine->id();

		$segments = array_intersect_key( $segments, $ids );
		$terms    = $this->glossary->forLanguage( $lang->code );
		$ctx      = new Context( $terms, '', $page_url );
		/**
		 * Lets an engine add tone or a locale preset to what it is told about the text.
		 *
		 * @param Context              $ctx
		 * @param Language             $lang
		 * @param \Lango7\Engine\Engine $engine
		 */
		$ctx = apply_filters( 'lango7_translation_context', $ctx, $lang, $engine );
		if ( ! $ctx instanceof Context ) {
			$ctx = new Context( $terms, '', $page_url );
		}
		/**
		 * The language code sent to an engine. Defaults to Google's code (zh-CN).
		 *
		 * @param string   $code
		 * @param string   $engine_id
		 * @param Language $lang
		 */
		$source   = (string) apply_filters( 'lango7_engine_code', $this->sourceEngineCode(), $engine->id(), $this->languages->source() );
		$target   = (string) apply_filters( 'lango7_engine_code', $lang->engine_code, $engine->id(), $lang );

		$retry   = array();
		$sent    = 0;
		$batches = $this->batches( $segments, $engine->maxBatchChars() );
		$pass    = 0;
		while ( $batches ) {
			$batch = array_shift( $batches );
			if ( microtime( true ) >= $deadline ) {
				$result['stopped'] = 'time';
				break;
			}
			$chars = array_sum( array_map( static fn( Segment $s ): int => $s->chars(), $batch ) );
			if ( ! $this->cap->allows( $chars ) ) {
				$result['stopped'] = 'cap';
				break;
			}

			$wrapped = array();
			$repl    = array();
			foreach ( $batch as $hash => $s ) {
				[ $wrapped[ $hash ], $repl[ $hash ] ] = Glossary::wrap( $s, $terms );
			}

			try {
				$out = $engine->translate( array_values( $wrapped ), $source, $target, $ctx );
			} catch ( EngineException $e ) {
				Log::error( $e->getMessage(), array( 'engine' => $engine->id(), 'lang' => $lang->code, 'code' => $e->getCode() ) );
				$result['stopped'] = 'engine';
				break;
			} finally {
				$sent += $chars;
				$this->cap->record( $engine->id(), $chars );
				/**
				 * Characters sent to an engine (bulk translation totals these).
				 *
				 * @param string   $engine_id
				 * @param int      $chars
				 * @param Language $lang
				 */
				do_action( 'lango7_chars_sent', $engine->id(), $chars, $lang );
			}

			$good = array();
			$i    = 0;
			foreach ( $batch as $hash => $s ) {
				$text = self::check( $s, (string) ( $out[ $i++ ] ?? '' ), $repl[ $hash ] );
				if ( null !== $text ) {
					$good[ $ids[ $hash ] ]           = $text;
					$result['translated'][ $hash ] = $text;
				} elseif ( 0 === $pass ) {
					$retry[ $hash ] = $s;
				} else {
					$result['failed'][] = $hash;
				}
			}
			if ( $persist ) {
				$this->translations->saveMachine( $lang->code, $good, $engine->id() );
			}

			// Second pass: each failed segment on its own, so one bad reply cannot
			// take a neighbour with it.
			if ( ! $batches && $retry && 0 === $pass ) {
				$pass    = 1;
				$batches = array_map( static fn( Segment $s ): array => array( $s->hash => $s ), $retry );
				$retry   = array();
			}
		}

		if ( $result['failed'] ) {
			if ( $persist ) {
				$this->translations->markFailed( $lang->code, array_map( static fn( string $h ): int => $ids[ $h ], $result['failed'] ), $engine->id() );
			}
			Log::error(
				sprintf( $persist ? 'Engine output had broken placeholders; %d segment(s) left in the source language.' : 'Engine output had broken placeholders; %d previous translation(s) kept.', count( $result['failed'] ) ),
				array(
					'engine' => $engine->id(),
					'lang'   => $lang->code,
					'url'    => $page_url,
				)
			);
		}

		if ( $result['translated'] ) {
			Activity::record(
				Activity::TRANSLATED,
				array(
					'lang'   => $lang->code,
					'url'    => $page_url,
					'engine' => $engine->id(),
					'items'  => count( $result['translated'] ),
					'chars'  => $sent,
				)
			);
		}
		if ( $result['failed'] ) {
			Activity::record(
				Activity::FAILED,
				array(
					'lang'   => $lang->code,
					'url'    => $page_url,
					'engine' => $engine->id(),
					'items'  => count( $result['failed'] ),
				)
			);
		}
		if ( $persist && ( $result['translated'] || $result['failed'] ) ) {
			do_action( 'lango7_translations_saved', $lang, array_keys( $result['translated'] ), $page_url );
		}
		return $result;
	}

	/**
	 * The engine's text for a segment, unwrapped and checked, or null when it
	 * must not be stored.
	 *
	 * @param array<string, string> $replacements From Glossary::wrap().
	 */
	public static function check( Segment $s, string $raw, array $replacements ): ?string {
		$text = Glossary::unwrap( $raw, $replacements );
		if ( null === $text ) {
			return null;
		}
		$text = Placeholders::clean( $text );
		if ( null === $text ) {
			return null;
		}
		$text = Placeholders::collapse( $text );
		return Placeholders::validate( $s->text, $text ) ? $text : null;
	}

	/**
	 * @param array<string, Segment> $segments
	 * @return array<int, array<string, Segment>>
	 */
	private function batches( array $segments, int $max_chars ): array {
		$batches = array();
		$current = array();
		$size    = 0;
		foreach ( $segments as $hash => $s ) {
			$chars = $s->chars();
			if ( $current && ( $size + $chars > $max_chars || count( $current ) >= $this->batch_strings ) ) {
				$batches[] = $current;
				$current   = array();
				$size      = 0;
			}
			$current[ $hash ] = $s;
			$size            += $chars;
		}
		if ( $current ) {
			$batches[] = $current;
		}
		return $batches;
	}

	private function sourceEngineCode(): string {
		$code = $this->languages->sourceCode();
		return Languages::catalog()[ $code ]['google'] ?? $code;
	}
}
