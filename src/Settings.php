<?php
/**
 * Plugin settings (option lango7_settings).
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

final class Settings {

	public const OPTION = 'lango7_settings';

	/** @var array<string, mixed>|null */
	private ?array $data = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return (array) apply_filters( 'lango7_settings_defaults', array(
			'source_lang'       => Languages::fromLocale( (string) get_locale() ),
			// One pattern per line, relative to the site root; "*" matches below.
			// Account and checkout pages carry personal data, which must not go to the engine.
			'exclude_urls'      => "/my-account/*\n/checkout/*",
			// Never-translate selectors: tag, #id, .class, [attr], comma or line separated.
			'exclude_selectors' => '',
			'time_budget'       => 4,
			'crawler_budget'    => 10,
			'max_segments'      => 300,
			'batch_chars'       => 5000,
			'daily_cap'         => 200000,
			'uninstall'         => 'keep',
			'news'              => 0, // Off until an administrator turns it on (no request to lango7.com before that).
		) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$this->data = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->data;
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function update( array $values ): void {
		$data = array_intersect_key( array_merge( $this->all(), $values ), self::defaults() );
		update_option( self::OPTION, $data );
		$this->data = $data;
	}
}
