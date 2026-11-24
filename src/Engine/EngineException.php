<?php
/**
 * Engine failure. The code is the HTTP status when there was one.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Engine;

final class EngineException extends \RuntimeException {

	/** 429 and 5xx: try again later rather than giving up on the text. */
	public function isTemporary(): bool {
		return 429 === $this->getCode() || $this->getCode() >= 500 || 0 === $this->getCode();
	}
}
