<?php
/**
 * Language Switcher block: server-rendered, so links are in the page HTML for crawlers.
 *
 * @package Lango7
 *
 * @var array<string, mixed> $attributes
 */

defined( 'ABSPATH' ) || exit;

$lango7_markup = \Lango7\Plugin::instance()->switcher()->render(
	array(
		'style' => $attributes['style'] ?? 'list',
		'names' => $attributes['names'] ?? 'native',
	)
);
if ( '' !== $lango7_markup ) {
	printf( '<div %s>%s</div>', get_block_wrapper_attributes(), $lango7_markup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Switcher::render() and core.
}
