<?php
/**
 * Strings to redo after a glossary change; current translations stay live.
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Store\RedoList;

if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['lango7_test_options'] = array();
	function get_option( $name, $default = false ) {
		return $GLOBALS['lango7_test_options'][ $name ] ?? $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['lango7_test_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['lango7_test_options'][ $name ] );
		return true;
	}
}

$redo = new RedoList();
$redo->add( 'es', array( 3, 1, 3 ) );
$redo->add( 'es', array( 2 ) );
$redo->add( 'ms', array( 9 ) );
check( 'Redo: ids are unique per language', array( 3, 1, 2 ), $redo->ids( 'es' ) );
check( 'Redo: languages with work', array( 'es', 'ms' ), $redo->languages() );
$redo->remove( 'es', array( 1, 3 ) );
check( 'Redo: done strings leave the list', array( 2 ), $redo->ids( 'es' ) );
$redo->remove( 'es', array( 2 ) );
$redo->forgetLanguage( 'ms' );
check( 'Redo: an empty list removes the option', false, get_option( RedoList::OPTION ) );
