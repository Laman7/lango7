<?php
/**
 * Plugin Name: Lango7 fake engine (tests only)
 * Description: Registers a local server engine that marks text with "«ms»", so the server pipeline (Pro's path) can be tested without an API key. Counts calls in the lango7_fake_calls option.
 *
 * Mount as an mu-plugin in a test site only.
 *
 * @package Lango7
 */

declare( strict_types=1 );

add_filter(
	'lango7_engines',
	static function ( array $engines ): array {
		if ( ! interface_exists( \Lango7\Engine\Engine::class ) ) {
			return $engines;
		}
		$fake = new class() implements \Lango7\Engine\Engine {
			public function id(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function isConfigured(): bool {
				return true;
			}
			public function test( string $source = 'en', string $target = 'ms' ): \Lango7\Engine\EngineResult {
				return new \Lango7\Engine\EngineResult( true, 'ok', '«' . $target . '» Hello' );
			}
			public function translate( array $segments, string $source, string $target, \Lango7\Engine\Context $ctx ): array {
				update_option( 'lango7_fake_calls', (int) get_option( 'lango7_fake_calls', 0 ) + 1, false );
				$out = array();
				foreach ( $segments as $s ) {
					$out[] = '«' . $target . '» ' . $s->text;
				}
				return $out;
			}
			public function estimate( int $chars ): ?float {
				return 0.0;
			}
			public function maxBatchChars(): int {
				return 5000;
			}
		};
		return array( 'fake' => $fake ) + $engines;
	}
);
