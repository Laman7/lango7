<?php
/**
 * One configured language.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

final class Language {

	/**
	 * @param string $code        BCP-47, used for hreflang and <html lang> (zh-Hans).
	 * @param string $prefix      URL folder (cn).
	 * @param string $engine_code What the engine expects (zh-CN for Google).
	 * @param string $engine      Engine id; Pro sets this per language ('' = default).
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $prefix,
		public readonly string $name,
		public readonly string $native_name,
		public readonly string $dir = 'ltr',
		public readonly string $engine_code = '',
		public readonly string $engine = '',
		public readonly bool $noindex = false
	) {}

	/**
	 * @param array<string, mixed> $a
	 */
	public static function fromArray( array $a ): self {
		$code    = (string) ( $a['code'] ?? '' );
		$catalog = Languages::catalog()[ $code ] ?? array();
		return new self(
			$code,
			(string) ( $a['prefix'] ?? $catalog['prefix'] ?? strtolower( $code ) ),
			(string) ( $a['name'] ?? $catalog['name'] ?? $code ),
			(string) ( $a['native_name'] ?? $catalog['native'] ?? $code ),
			'rtl' === ( $a['dir'] ?? $catalog['dir'] ?? 'ltr' ) ? 'rtl' : 'ltr',
			(string) ( $a['engine_code'] ?? $catalog['google'] ?? $code ),
			(string) ( $a['engine'] ?? '' ),
			! empty( $a['noindex'] )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'code'        => $this->code,
			'prefix'      => $this->prefix,
			'name'        => $this->name,
			'native_name' => $this->native_name,
			'dir'         => $this->dir,
			'engine_code' => $this->engine_code,
			'engine'      => $this->engine,
			'noindex'     => $this->noindex,
		);
	}
}
