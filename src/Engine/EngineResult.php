<?php
/**
 * Outcome of an engine key test.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Engine;

final class EngineResult {

	public function __construct(
		public readonly bool $ok,
		public readonly string $message,
		public readonly string $sample = ''
	) {}
}
