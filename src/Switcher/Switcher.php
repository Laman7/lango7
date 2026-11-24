<?php
/**
 * Language switcher markup.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Switcher;

use Lango7\Language;
use Lango7\Languages;
use Lango7\Router\LanguageRouter;

/**
 * Server-rendered <a> links to the equivalent page in each language, so
 * crawlers can follow them. The dropdown is a <details> element: no JavaScript.
 * The whole switcher is marked translate="no" so native names stay native.
 */
final class Switcher {

	/** Set the first time a switcher is shown on the front end (dashboard checklist). */
	public const SEEN_OPTION = 'lango7_switcher_seen';

	public function __construct( private Languages $languages, private LanguageRouter $router ) {}

	public static function seen(): bool {
		return (bool) get_option( self::SEEN_OPTION );
	}

	public static function markSeen(): void {
		$preview = is_admin() || wp_is_serving_rest_request() || ( function_exists( 'bricks_is_builder_call' ) && ( bricks_is_builder_call() || bricks_is_builder() ) );
		if ( ! $preview && ! self::seen() ) {
			update_option( self::SEEN_OPTION, time() );
		}
	}

	/** Language codes in the order the switcher shows them (Settings → Switcher). */
	public const ORDER_OPTION = 'lango7_switcher_order';

	/**
	 * The original language (null) and the target languages, in the saved
	 * order. Languages not in the saved order follow at the end.
	 *
	 * @param Language[] $langs
	 * @return array<int, Language|null>
	 */
	public static function ordered( array $langs, string $source_code ): array {
		$items = array( $source_code => null );
		foreach ( $langs as $l ) {
			$items[ $l->code ] = $l;
		}
		$order = array_values( array_filter( (array) get_option( self::ORDER_OPTION, array() ), 'is_string' ) );
		$out   = array();
		foreach ( $order as $code ) {
			if ( array_key_exists( $code, $items ) ) {
				$out[] = $items[ $code ];
				unset( $items[ $code ] );
			}
		}
		return array_merge( $out, array_values( $items ) );
	}

	/**
	 * @param array<string, mixed> $args style: list|dropdown|globe|ul|ol; names: native|name|code|iso3.
	 *                                   globe: a globe button that opens the list, for headers.
	 *                                   ul/ol: a bare list with no Lango7 styles, to style yourself.
	 */
	public function render( array $args = array() ): string {
		$args  = wp_parse_args(
			$args,
			array(
				'style' => 'list',
				'names' => 'native',
			)
		);
		$langs = $this->languages->visible();
		if ( ! $langs ) {
			return '';
		}
		self::markSeen();
		if ( 'ul' === $args['style'] || 'ol' === $args['style'] ) {
			return $this->bare( (string) $args['style'], $langs, (string) $args['names'] );
		}
		wp_enqueue_style( 'lango7-switcher', LANGO7_URL . 'assets/switcher/switcher.css', array(), \Lango7\Plugin::assetVersion( 'assets/switcher/switcher.css' ) );

		$current = $this->router->current();
		$items   = self::ordered( $langs, $this->languages->sourceCode() );
		$links   = '';
		$label   = '';
		foreach ( $items as $lang ) {
			$lang_obj  = $lang ?? $this->languages->source();
			$is_active = ( null === $lang && null === $current ) || ( null !== $lang && null !== $current && $lang->code === $current->code );
			$text      = $this->label( $lang_obj, (string) $args['names'] );
			if ( $is_active ) {
				$label = $text;
			}
			$links .= sprintf(
				'<li><a href="%1$s" hreflang="%2$s" lang="%2$s" data-lango7-lang="%2$s"%3$s>%4$s</a></li>',
				esc_url( $this->router->urlFor( $lang ) ),
				esc_attr( $lang_obj->code ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $text )
			);
		}

		$aria = esc_attr__( 'Language', 'lango7' );
		if ( 'globe' === $args['style'] || 'dropdown' === $args['style'] ) {
			wp_enqueue_script( 'lango7-switcher', LANGO7_URL . 'assets/switcher/switcher.js', array(), \Lango7\Plugin::assetVersion( 'assets/switcher/switcher.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		}
		if ( 'globe' === $args['style'] ) {
			/* translators: %s: the language the page is shown in, e.g. English. */
			$title = sprintf( __( 'Language: %s', 'lango7' ), $label );
			return '<details class="lango7-switcher lango7-switcher--globe" translate="no" data-lango7-skip>'
				. '<summary aria-label="' . esc_attr( $title ) . '" title="' . esc_attr( $title ) . '">' . self::globe() . '</summary><ul>' . $links . '</ul></details>';
		}
		if ( 'dropdown' === $args['style'] ) {
			return '<details class="lango7-switcher lango7-switcher--dropdown" translate="no" data-lango7-skip>'
				. '<summary aria-label="' . $aria . '">' . esc_html( $label ) . '</summary><ul>' . $links . '</ul></details>';
		}
		return '<nav class="lango7-switcher lango7-switcher--list" aria-label="' . $aria . '" translate="no" data-lango7-skip><ul>' . $links . '</ul></nav>';
	}

	/**
	 * A plain <ul> or <ol>, no Lango7 CSS:
	 * <li class="current"><a …><span class="iso_name">ES</span><span class="full_name">Español</span></a></li>
	 * names="iso3" gives a three-letter code (SPA); names="name" the English name (Spanish).
	 *
	 * @param Language[] $langs
	 */
	private function bare( string $tag, array $langs, string $names ): string {
		$current = $this->router->current();
		$out     = '';
		foreach ( self::ordered( $langs, $this->languages->sourceCode() ) as $lang ) {
			$obj    = $lang ?? $this->languages->source();
			$active = null === $lang ? null === $current : ( null !== $current && $lang->code === $current->code );
			$out   .= sprintf(
				'<li class="lango7-lang-%2$s%3$s"><a href="%1$s" hreflang="%2$s" lang="%2$s" data-lango7-lang="%2$s"%4$s><span class="iso_name">%5$s</span><span class="full_name">%6$s</span></a></li>',
				esc_url( $this->router->urlFor( $lang ) ),
				esc_attr( $obj->code ),
				$active ? ' current' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $this->label( $obj, 'iso3' === $names ? 'iso3' : 'code' ) ),
				esc_html( $this->label( $obj, 'name' === $names ? 'name' : 'native' ) )
			);
		}
		return '<' . $tag . ' class="lango7-switcher-list" translate="no" data-lango7-skip>' . $out . '</' . $tag . '>';
	}

	/**
	 * Globe icon; takes the text colour.
	 */
	private static function globe(): string {
		return '<svg class="lango7-switcher-globe" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false">'
			. '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.4 2.6 3.7 5.6 3.7 9s-1.3 6.4-3.7 9c-2.4-2.6-3.7-5.6-3.7-9S9.6 5.6 12 3z"/></svg>';
	}

	private function label( Language $lang, string $names ): string {
		return match ( $names ) {
			'name'  => $lang->name,
			'code'  => strtoupper( $lang->prefix ?: $lang->code ),
			'iso3'  => strtoupper( self::iso3( $lang->code ) ),
			default => $lang->native_name,
		};
	}

	private static function iso3( string $code ): string {
		$map = array( 'en'=>'eng','ms'=>'msa','zh-Hans'=>'zho','zh-Hant'=>'zho','id'=>'ind','ta'=>'tam','th'=>'tha','vi'=>'vie','fil'=>'fil','km'=>'khm','lo'=>'lao','my'=>'mya','hi'=>'hin','bn'=>'ben','mr'=>'mar','te'=>'tel','kn'=>'kan','ja'=>'jpn','ko'=>'kor','ar'=>'ara','he'=>'heb','fa'=>'fas','ur'=>'urd','tr'=>'tur','fr'=>'fra','de'=>'deu','es'=>'spa','pt'=>'por','pt-BR'=>'por','it'=>'ita','nl'=>'nld','pl'=>'pol','ru'=>'rus','uk'=>'ukr','cs'=>'ces','sk'=>'slk','sl'=>'slv' );
		return $map[ $code ] ?? $code;
	}
}
