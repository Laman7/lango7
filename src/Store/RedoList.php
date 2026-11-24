<?php
/**
 * Translations to do again without taking the current one down.
 *
 * When a glossary keyword changes how a string should read, the old
 * translation stays on the site until its new version is ready: server
 * engines redo the listed strings in the background, Free Translate treats
 * them as pending. A failed redo keeps the old translation.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Store;

final class RedoList {

	public const OPTION = 'lango7_redo';

	/**
	 * @return array<string, int[]> lang => string ids.
	 */
	private function load(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * @param array<string, int[]> $all
	 */
	private function store( array $all ): void {
		$all = array_filter( $all );
		if ( $all ) {
			update_option( self::OPTION, $all, false );
		} else {
			delete_option( self::OPTION );
		}
	}

	/**
	 * @param int[] $string_ids
	 */
	public function add( string $lang, array $string_ids ): void {
		$all          = $this->load();
		$all[ $lang ] = array_values( array_unique( array_merge( array_map( 'intval', $all[ $lang ] ?? array() ), array_map( 'intval', $string_ids ) ) ) );
		$this->store( $all );
	}

	/**
	 * @return int[]
	 */
	public function ids( string $lang ): array {
		return array_map( 'intval', $this->load()[ $lang ] ?? array() );
	}

	public function count( string $lang ): int {
		return count( $this->load()[ $lang ] ?? array() );
	}

	/**
	 * @return string[] Languages with strings waiting.
	 */
	public function languages(): array {
		return array_map( 'strval', array_keys( $this->load() ) );
	}

	/**
	 * @param int[] $string_ids
	 */
	public function remove( string $lang, array $string_ids ): void {
		$all = $this->load();
		if ( ! isset( $all[ $lang ] ) || ! $string_ids ) {
			return;
		}
		$all[ $lang ] = array_values( array_diff( array_map( 'intval', $all[ $lang ] ), array_map( 'intval', $string_ids ) ) );
		$this->store( $all );
	}

	public function forgetLanguage( string $lang ): void {
		$all = $this->load();
		unset( $all[ $lang ] );
		$this->store( $all );
	}
}
