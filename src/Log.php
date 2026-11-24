<?php
/**
 * Error log: a ring buffer of the last 200 entries.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

final class Log {

	public const OPTION = 'lango7_log';

	/** Set to 1 to stop recording errors (Settings → Debug). */
	public const OFF_OPTION = 'lango7_log_off';
	private const SIZE  = 200;

	/**
	 * @param array<string, scalar|null> $context Never put keys or page text here.
	 */
	public static function error( string $message, array $context = array() ): void {
		if ( self::off() ) {
			return;
		}
		$log   = (array) get_option( self::OPTION, array() );
		$log[] = array(
			'time'    => time(),
			'message' => $message,
			'context' => $context,
		);
		update_option( self::OPTION, array_slice( $log, -self::SIZE ), false );
		Store\Activity::record(
			Store\Activity::ERROR,
			array(
				'lang'    => is_scalar( $context['lang'] ?? null ) ? (string) $context['lang'] : '',
				'url'     => is_scalar( $context['url'] ?? null ) ? (string) $context['url'] : '',
				'engine'  => is_scalar( $context['engine'] ?? null ) ? (string) $context['engine'] : '',
				'message' => $message,
			)
		);
	}

	/**
	 * Newest first.
	 *
	 * @return array<int, array{time: int, message: string, context: array<string, mixed>}>
	 */
	public static function recent( int $limit = 10 ): array {
		return array_slice( array_reverse( (array) get_option( self::OPTION, array() ) ), 0, $limit );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	public static function off(): bool {
		return (bool) get_option( self::OFF_OPTION, false );
	}

	public static function setOff( bool $off ): void {
		update_option( self::OFF_OPTION, $off ? 1 : 0, true );
	}

	public static function count(): int {
		return count( (array) get_option( self::OPTION, array() ) );
	}
}
