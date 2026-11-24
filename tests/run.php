<?php
/**
 * Unit tests for the WordPress-free core: parser, segmenter, placeholders,
 * applier, link rewriting and glossary.
 *
 * Run: php tests/run.php
 *
 * @package Lango7
 */

declare( strict_types=1 );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'Lango7\\' ) ) {
			require dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class_name, 7 ) ) . '.php';
		}
	}
);

$GLOBALS['lango7_failures'] = 0;
$GLOBALS['lango7_count']    = 0;

function check( string $label, $expected, $actual ): void {
	++$GLOBALS['lango7_count'];
	if ( $expected === $actual ) {
		return;
	}
	++$GLOBALS['lango7_failures'];
	echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

require __DIR__ . '/helpers.php';

foreach ( glob( __DIR__ . '/unit/*.php' ) as $file ) {
	require $file;
}

printf( "%d checks, %d failed\n", $GLOBALS['lango7_count'], $GLOBALS['lango7_failures'] );
exit( $GLOBALS['lango7_failures'] > 0 ? 1 : 0 );
