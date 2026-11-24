<?php
/**
 * Language switcher as a classic menu item.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Switcher;

use Lango7\Language;
use Lango7\Languages;
use Lango7\Rest\AdminController;
use Lango7\Router\LanguageRouter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Appearance → Menus gets a "Language switcher" box that adds a custom link to
 * #lango7-switcher. On the front end that item becomes the current language
 * with the other languages as its sub-menu, so it picks up the theme's own
 * menu and dropdown styles (Bricks' Nav Menu element included). The items are
 * classed notranslate, so markup a theme adds around them (Bricks' sub-menu
 * button label, "Français Sub menu") is not translated either.
 */
final class NavMenu {

	public const URL = '#lango7-switcher';

	public function __construct( private Languages $languages, private LanguageRouter $router ) {}

	public function register(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'expand' ) );
		add_filter( 'nav_menu_link_attributes', array( $this, 'linkAttributes' ), 10, 2 );
		add_action(
			'rest_api_init',
			fn() => register_rest_route(
				AdminController::NS,
				'/switcher/menu',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add' ),
					'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
					'args'                => array(
						'menu' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				)
			)
		);
		if ( is_admin() ) {
			add_action( 'load-nav-menus.php', fn() => add_meta_box( 'lango7-switcher', __( 'Language switcher', 'lango7' ), array( $this, 'metaBox' ), 'nav-menus', 'side', 'low' ) );
		}
	}

	/**
	 * @param array<int, \WP_Post|object> $items
	 * @return array<int, \WP_Post|object>
	 */
	public function expand( array $items ): array {
		$langs = $this->languages->visible();
		$out   = array();
		foreach ( $items as $item ) {
			if ( self::URL !== ( $item->url ?? '' ) ) {
				$out[] = $item;
				continue;
			}
			if ( ! $langs ) {
				continue; // Nothing to switch to yet: hide the item.
			}
			Switcher::markSeen();
			$current = $this->router->current();
			$all     = Switcher::ordered( $langs, $this->languages->sourceCode() );
			$parent  = $this->item( $item, $current, (string) $item->db_id, (string) $item->menu_item_parent, true );

			$parent->classes[] = 'menu-item-has-children';
			$out[]             = $parent;
			foreach ( $all as $lang ) {
				if ( ( $lang?->code ) !== ( $current?->code ) ) {
					$out[] = $this->item( $item, $lang, $item->db_id . '-' . ( $lang ? $lang->code : 'source' ), (string) $item->db_id, false );
				}
			}
		}
		return $out;
	}

	/**
	 * Append the switcher item to a menu (dashboard button).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function add( WP_REST_Request $request ) {
		$menu = wp_get_nav_menu_object( (int) $request['menu'] );
		if ( ! $menu ) {
			return new WP_Error( 'lango7_menu', __( 'That menu does not exist.', 'lango7' ), array( 'status' => 404 ) );
		}
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( self::URL === $item->url ) {
				return new WP_REST_Response( array( 'id' => (int) $item->db_id ) );
			}
		}
		$id = wp_update_nav_menu_item(
			$menu->term_id,
			0,
			array(
				'menu-item-title'   => __( 'Languages', 'lango7' ),
				'menu-item-url'     => self::URL,
				'menu-item-type'    => 'custom',
				'menu-item-classes' => 'lango7-menu-switcher',
				'menu-item-status'  => 'publish',
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( array( 'id' => (int) $id ) );
	}

	/**
	 * @param \WP_Post|object $template
	 * @return \WP_Post|object
	 */
	private function item( object $template, ?Language $lang, string $id, string $parent, bool $current ): object {
		$lang_obj = $lang ?? $this->languages->source();
		$item     = clone $template;

		$item->ID               = $id;
		$item->db_id            = $id;
		$item->menu_item_parent = $parent;
		$item->title            = $lang_obj->native_name;
		$item->url              = $this->router->urlFor( $lang );
		$item->attr_title       = $lang_obj->name;
		$item->current          = $current;
		$item->lango7_lang      = $lang_obj->code;
		$item->classes          = array_values( array_filter( array_merge( (array) $template->classes, array( 'menu-item', 'notranslate', 'lango7-menu-lang', 'lango7-menu-lang-' . $lang_obj->code ), $current ? array( 'current-lang' ) : array() ) ) );
		return $item;
	}

	/**
	 * @param array<string, string> $atts
	 * @param \WP_Post|object       $item
	 * @return array<string, string>
	 */
	public function linkAttributes( array $atts, object $item ): array {
		if ( empty( $item->lango7_lang ) ) {
			return $atts;
		}
		$atts['hreflang']  = $item->lango7_lang;
		$atts['lang']      = $item->lango7_lang;
		$atts['translate'] = 'no'; // Native names stay native on translated pages.
		if ( ! empty( $item->current ) ) {
			$atts['aria-current'] = 'true';
		}
		return $atts;
	}

	public function metaBox(): void {
		?>
		<div id="posttype-lango7-switcher" class="posttypediv">
			<div id="tabs-panel-lango7-switcher" class="tabs-panel tabs-panel-active">
				<ul id="lango7-switcher-checklist" class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1" checked>
							<?php esc_html_e( 'Language switcher', 'lango7' ); ?>
						</label>
						<input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom">
						<input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php esc_attr_e( 'Languages', 'lango7' ); ?>">
						<input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="<?php echo esc_attr( self::URL ); ?>">
						<input type="hidden" class="menu-item-classes" name="menu-item[-1][menu-item-classes]" value="lango7-menu-switcher">
					</li>
				</ul>
			</div>
			<p class="description"><?php esc_html_e( 'Shows the current language, with the others in its sub-menu.', 'lango7' ); ?></p>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'lango7' ); ?>" name="add-post-type-menu-item" id="submit-posttype-lango7-switcher">
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}
}
