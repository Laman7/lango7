<?php
/**
 * Daily character cap and usage counters.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Security;

use Lango7\Settings;

/**
 * The cap is the backstop that stops anyone burning the customer's API credit,
 * whether by crawling thousands of URL variants or by a bug. When it is reached,
 * untranslated text renders in the source language and waits for tomorrow.
 *
 * Usage lives in lango7_usage as [ 'Y-m-d' => [ engine => chars ] ] for 90 days.
 */
final class SpendCap {

	public const OPTION = 'lango7_usage';

	/** @var array<string, array<string, int>>|null */
	private ?array $usage = null;

	public function __construct( private Settings $settings ) {}

	public function cap(): int {
		return max( 0, (int) $this->settings->get( 'daily_cap' ) );
	}

	public function usedToday(): int {
		return (int) array_sum( $this->load()[ $this->today() ] ?? array() );
	}

	/**
	 * Characters still allowed today; PHP_INT_MAX when uncapped.
	 */
	public function remaining(): int {
		$cap = $this->cap();
		return 0 === $cap ? PHP_INT_MAX : max( 0, $cap - $this->usedToday() );
	}

	public function allows( int $chars ): bool {
		return $chars <= $this->remaining();
	}

	public function record( string $engine, int $chars ): void {
		$usage = $this->load();
		$today = $this->today();

		$usage[ $today ][ $engine ] = ( $usage[ $today ][ $engine ] ?? 0 ) + $chars;

		$cutoff = gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS );
		foreach ( array_keys( $usage ) as $day ) {
			if ( $day < $cutoff ) {
				unset( $usage[ $day ] );
			}
		}
		$this->usage = $usage;
		update_option( self::OPTION, $usage, false );

		do_action( 'lango7_usage_recorded', $engine, $chars, $this->usedToday(), $this->cap() );
	}

	/**
	 * Characters per engine for a period: 'today' or 'month'.
	 *
	 * @return array<string, int>
	 */
	public function totals( string $period ): array {
		$prefix = 'month' === $period ? substr( $this->today(), 0, 7 ) : $this->today();
		$out    = array();
		foreach ( $this->load() as $day => $engines ) {
			if ( str_starts_with( $day, $prefix ) ) {
				foreach ( $engines as $engine => $chars ) {
					$out[ $engine ] = ( $out[ $engine ] ?? 0 ) + (int) $chars;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<string, array<string, int>>
	 */
	private function load(): array {
		if ( null === $this->usage ) {
			$stored      = get_option( self::OPTION, array() );
			$this->usage = is_array( $stored ) ? $stored : array();
		}
		return $this->usage;
	}

	private function today(): string {
		return wp_date( 'Y-m-d' );
	}
}
