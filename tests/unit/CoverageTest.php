<?php
/**
 * When a page counts as fully translated (Languages column, "Untranslated").
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Store\Coverage;

$counts = array(
	'total' => 10,
	'langs' => array(
		'es' => array( 'done' => 10, 'edited' => 0, 'review' => 1 ),
		'fr' => array( 'done' => 7, 'edited' => 2, 'review' => 0 ),
	),
);
check( 'complete when every sentence has a translation', true, Coverage::complete( $counts, array( 'es' ) ) );
check( 'incomplete when one language is behind', false, Coverage::complete( $counts, array( 'es', 'fr' ) ) );
check( 'a language with no rows is incomplete', false, Coverage::complete( $counts, array( 'de' ) ) );
check( 'no languages means nothing is missing', true, Coverage::complete( $counts, array() ) );
