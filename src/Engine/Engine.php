<?php
/**
 * Translation engine contract.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Engine;

use Lango7\Translation\Segment;

/**
 * Free has no server engine (it translates in the admin's Chrome, see Admin\ChromePage);
 * Pro registers engines through the lango7_engines filter.
 *
 * translate() receives placeholder text and must return placeholder text in the
 * same order and count. Throw EngineException on failure; the caller validates
 * placeholders, retries once, and falls back to the source language.
 */
interface Engine {

	public function id(): string;

	public function label(): string;

	public function isConfigured(): bool;

	/**
	 * Translate "Hello" to check the key.
	 */
	public function test( string $source = 'en', string $target = 'ms' ): EngineResult;

	/**
	 * @param Segment[] $segments
	 * @return string[] Same order, same count.
	 * @throws EngineException On HTTP or API errors.
	 */
	public function translate( array $segments, string $source, string $target, Context $ctx ): array;

	/**
	 * Estimated cost in USD, null if unknown.
	 */
	public function estimate( int $chars ): ?float;

	public function maxBatchChars(): int;
}
