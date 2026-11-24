<?php
/**
 * Encrypted storage for engine API keys.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Security;

/**
 * Keys are sealed with libsodium secretbox under a key derived from the site's
 * AUTH_KEY and SECURE_AUTH_SALT, so a leaked database dump alone does not reveal
 * them. A wp-config.php constant (LANGO7_GOOGLE_KEY) always wins and is never
 * written to the database.
 *
 * Keys never leave PHP: not in REST responses, the front end or the log. The
 * admin only ever sees mask().
 */
final class KeyVault {

	public const OPTION = 'lango7_keys';

	/** @var array<string, string> */
	private array $plain = array();

	public static function constantName( string $engine ): string {
		return 'LANGO7_' . strtoupper( preg_replace( '/[^a-z0-9]/i', '_', $engine ) ) . '_KEY';
	}

	public function fromConstant( string $engine ): bool {
		$name = self::constantName( $engine );
		return defined( $name ) && '' !== (string) constant( $name );
	}

	public function get( string $engine ): string {
		if ( $this->fromConstant( $engine ) ) {
			return (string) constant( self::constantName( $engine ) );
		}
		if ( isset( $this->plain[ $engine ] ) ) {
			return $this->plain[ $engine ];
		}
		$sealed = (array) get_option( self::OPTION, array() );
		$value  = '';
		if ( ! empty( $sealed[ $engine ] ) ) {
			$raw = base64_decode( (string) $sealed[ $engine ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.
			if ( false !== $raw && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				$opened = sodium_crypto_secretbox_open(
					substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
					substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
					$this->key()
				);
				$value  = false === $opened ? '' : $opened;
			}
		}
		return $this->plain[ $engine ] = $value;
	}

	public function set( string $engine, string $key ): void {
		$sealed = (array) get_option( self::OPTION, array() );
		if ( '' === $key ) {
			unset( $sealed[ $engine ] );
		} else {
			$nonce             = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$sealed[ $engine ] = base64_encode( $nonce . sodium_crypto_secretbox( $key, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		update_option( self::OPTION, $sealed, false );
		$this->plain[ $engine ] = $key;
	}

	/**
	 * Use a key for this request only, without storing it (the Test button
	 * checks a pasted key before it is saved).
	 */
	public function useTemporarily( string $engine, string $key ): void {
		if ( ! $this->fromConstant( $engine ) ) {
			$this->plain[ $engine ] = $key;
		}
	}

	/**
	 * "••••••••abcd", or '' when no key is set.
	 */
	public function mask( string $engine ): string {
		$key = $this->get( $engine );
		return '' === $key ? '' : str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	private function key(): string {
		$secret = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return sodium_crypto_generichash( 'lango7|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
