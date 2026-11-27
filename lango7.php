<?php
/**
 * Plugin Name:       Lango7
 * Plugin URI:        https://lango7.com
 * Description:       Translate your whole site into SEO-indexable language subfolders (/cn/, /fr/) for free with Chrome's built-in translator. No API key, no duplicate posts; every translation is stored in your own database.
 * Version:           1.24
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Lango7
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lango7
 *
 * Translation is an overlay on the rendered HTML: WordPress renders the original
 * page, the output buffer swaps text from a string cache, and nothing is ever
 * duplicated as a post. That is what makes it builder-agnostic (Bricks, Elementor,
 * Gutenberg, Divi all end up as HTML).
 *
 * @package Lango7
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LANGO7_VERSION', '1.24' );
define( 'LANGO7_DB_VERSION', '2' );
define( 'LANGO7_FILE', __FILE__ );
define( 'LANGO7_DIR', plugin_dir_path( __FILE__ ) );
define( 'LANGO7_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'Lango7\\' ) ) {
			return;
		}
		$file = LANGO7_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, 7 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( \Lango7\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Lango7\Plugin::class, 'deactivate' ) );

// Earliest safe point: options are loaded, so the language prefixes are known,
// and WordPress has not parsed REQUEST_URI yet.
add_action( 'plugins_loaded', array( \Lango7\Plugin::instance(), 'boot' ), PHP_INT_MIN );

/**
 * The plugin container, for Pro and site code.
 */
function lango7(): \Lango7\Plugin {
	return \Lango7\Plugin::instance();
}

/**
 * Print the language switcher.
 *
 * @param array<string, mixed> $args style (list|dropdown), names (native|name|code).
 */
function lango7_switcher( array $args = array() ): void {
	echo \Lango7\Plugin::instance()->switcher()->render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render().
}

/**
 * The current language code, or null on the site's own language.
 */
function lango7_current_language(): ?string {
	return \Lango7\Plugin::instance()->router()->current()?->code;
}

/**
 * A URL in a language: the current page, or $url when given (a source-language
 * URL or path on this site; other sites' URLs come back unchanged). A code or
 * URL prefix that is not a target language, such as the site's own code, gives
 * the source-language URL. Uses translated slugs when Pro has them.
 */
function lango7_url( string $lang, ?string $url = null ): string {
	$plugin = \Lango7\Plugin::instance();
	$router = $plugin->router();
	$target = $plugin->languages()->byCode( $lang ) ?? $plugin->languages()->byPrefix( $lang );
	if ( null === $url ) {
		return $router->urlFor( $target );
	}
	$parts = wp_parse_url( $url );
	if ( false === $parts || ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $router->host() ) ) ) {
		return $url;
	}
	// Accept a URL that is already in a language: drop its prefix first.
	$path = (string) ( $parts['path'] ?? '/' );
	$home = $router->homePath();
	if ( preg_match( '~^' . preg_quote( $home, '~' ) . '/([^/]+)(/.*)?$~', $path, $m ) && $plugin->languages()->byPrefix( $m[1] ) ) {
		$path = $home . ( $m[2] ?? '/' );
	}
	return $router->urlFor( $target, $path )
		. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
		. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
}

/**
 * The site's target languages, each with code, prefix, name, native_name, dir
 * and url (this page in that language).
 *
 * @return array<int, array<string, string>>
 */
function lango7_languages(): array {
	$plugin = \Lango7\Plugin::instance();
	return array_map(
		static fn( \Lango7\Language $l ): array => array(
			'code'        => $l->code,
			'prefix'      => $l->prefix,
			'name'        => $l->name,
			'native_name' => $l->native_name,
			'dir'         => $l->dir,
			'url'         => $plugin->router()->urlFor( $l ),
		),
		$plugin->languages()->enabled()
	);
}
