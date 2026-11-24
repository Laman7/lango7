<?php
/**
 * Link rewriting with translated paths.
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Html\LinkRewriter;

$slugs = array(
	'contact' => 'hubungi',
	'about'   => 'tentang',
);
$links = new LinkRewriter(
	'example.com',
	'',
	array( 'ms' ),
	array(),
	static fn( string $rel, string $prefix ): string => implode( '/', array_map( static fn( string $p ): string => $slugs[ $p ] ?? $p, explode( '/', $rel ) ) )
);

check( 'localized path', '/ms/hubungi/', $links->rewrite( '/contact/', 'ms' ) );
check( 'localized nested path keeps query and fragment', 'https://example.com/ms/tentang/team/?a=1#x', $links->rewrite( 'https://example.com/about/team/?a=1#x', 'ms' ) );
check( 'files are not localized', null, $links->rewrite( '/contact/form.pdf', 'ms' ) );
check( 'already prefixed is left alone', null, $links->rewrite( '/ms/contact/', 'ms' ) );
check( 'no localizer: unchanged path', '/ms/contact/', ( new LinkRewriter( 'example.com', '', array( 'ms' ) ) )->rewrite( '/contact/', 'ms' ) );
