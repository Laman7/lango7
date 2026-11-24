<?php
/**
 * Language-aware content for themes and builders: the [lango7_lang] shortcode,
 * body classes, and the Bricks {lango7_language} dynamic tag.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Site;

use Lango7\Languages;
use Lango7\Router\LanguageRouter;

final class LanguageContent {

	public const TAG = 'lango7_language';

	public function __construct( private Languages $languages, private LanguageRouter $router ) {}

	public function register(): void {
		add_shortcode( 'lango7_lang', array( $this, 'shortcode' ) );
		add_filter( 'body_class', array( $this, 'bodyClass' ) );
		add_filter( 'bricks/dynamic_tags_list', array( $this, 'bricksTags' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'bricksTag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( $this, 'bricksContent' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( $this, 'bricksContent' ), 20, 2 );
	}

	/**
	 * The language of this request: a target language code, or the source code.
	 */
	public function current(): string {
		return $this->router->current()?->code ?? $this->languages->sourceCode();
	}

	/**
	 * Whether this request's language is in a comma-separated list of codes or
	 * URL prefixes; "source" stands for the site's own language.
	 */
	public function matches( string $list ): bool {
		$lang = $this->router->current();
		foreach ( array_filter( array_map( 'trim', explode( ',', strtolower( $list ) ) ) ) as $item ) {
			if ( 'source' === $item ? null === $lang : ( strtolower( $this->current() ) === $item || ( $lang && strtolower( $lang->prefix ) === $item ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * [lango7_lang code="ms,zh-Hans"]…[/lango7_lang] shows its content only in those
	 * languages; not="ms" hides it there. Hidden content never reaches the page,
	 * so it is not translated either.
	 *
	 * @param array<string, string>|string $atts
	 */
	public function shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts(
			array(
				'code' => '',
				'not'  => '',
			),
			(array) $atts,
			'lango7_lang'
		);
		$show = ( '' === $atts['code'] || $this->matches( $atts['code'] ) ) && ( '' === $atts['not'] || ! $this->matches( $atts['not'] ) );
		return $show ? do_shortcode( (string) $content ) : '';
	}

	/**
	 * lango7-lang-{code} on every page, plus lango7-translated on translated ones,
	 * so theme and builder CSS can target a language.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public function bodyClass( array $classes ): array {
		$classes[] = 'lango7-lang-' . sanitize_html_class( $this->current() );
		if ( null !== $this->router->current() ) {
			$classes[] = 'lango7-translated';
		}
		return $classes;
	}

	/**
	 * @param array<int, array<string, string>> $tags
	 * @return array<int, array<string, string>>
	 */
	public function bricksTags( array $tags ): array {
		$tags[] = array(
			'name'  => '{' . self::TAG . '}',
			'label' => __( 'Current language code', 'lango7' ),
			'group' => 'Lango7',
		);
		return $tags;
	}

	/**
	 * @param mixed $tag
	 * @param mixed $post
	 * @return mixed
	 */
	public function bricksTag( $tag, $post = null, string $context = 'text' ) {
		return is_string( $tag ) && self::TAG === trim( $tag, '{}' ) ? $this->current() : $tag;
	}

	/**
	 * @param mixed $content
	 * @param mixed $post
	 * @return mixed
	 */
	public function bricksContent( $content, $post = null, string $context = 'text' ) {
		return is_string( $content ) && str_contains( $content, '{' . self::TAG . '}' ) ? str_replace( '{' . self::TAG . '}', esc_html( $this->current() ), $content ) : $content;
	}
}
