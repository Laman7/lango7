<?php
/** Selected Chrome rows may replace edits; ordinary pending scans must not. */

declare( strict_types=1 );

use Lango7\Store\StringRepository;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$previous_db = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public string $sql = '';
	public array $args = array();

	public function prepare( string $sql, ...$args ): string {
		$this->sql  = $sql;
		$this->args = $args;
		return $sql;
	}

	public function get_results(): array {
		return array();
	}
};

$repository = new StringRepository();
$hash = str_repeat( 'a', 40 );
$repository->pending( 'fr', 'en', null, 1, $hash, true );
check( 'selected Chrome row includes saved edits', true, str_contains( $GLOBALS['wpdb']->sql, 's.hash = %s' ) && ! str_contains( $GLOBALS['wpdb']->sql, 't.locked = 0' ) );
check( 'selected Chrome row binds its hash', $hash, $GLOBALS['wpdb']->args[4] );

$repository->pending( 'fr', 'en', null, 1, $hash );
check( 'normal selected scan protects edits', true, str_contains( $GLOBALS['wpdb']->sql, 't.locked = 0' ) );

$repository->pending( 'fr', 'en' );
check( 'whole-site scan sees only missing translations', true, str_contains( $GLOBALS['wpdb']->sql, 't.id IS NULL' ) && ! str_contains( $GLOBALS['wpdb']->sql, 's.hash = %s' ) );

$GLOBALS['wpdb'] = $previous_db;
