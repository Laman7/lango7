<?php
/**
 * Settings → Switcher: every way to add the language switcher, each with a
 * live preview of what it looks like on this site.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Plugin;
use Lango7\Switcher\Switcher;

final class SwitcherTab {

	public const TAB = 'switcher';

	public static function register(): void {
		add_filter( 'lango7_settings_tab_rendered', array( self::class, 'render' ), 20 );
		add_action( 'admin_post_lango7_switcher_order', array( self::class, 'saveOrder' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . self::TAB );
	}

	private static function tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the tab shown.
	}

	/**
	 * Shortcode => [title, what it shows, render args].
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
	 */
	private static function examples(): array {
		return array(
			'[lango7_switcher]'                             => array( __( 'List of links', 'lango7' ), __( 'Every language side by side, in its own name. Good for footers and sidebars.', 'lango7' ), array() ),
			'[lango7_switcher style="dropdown"]'            => array( __( 'Dropdown', 'lango7' ), __( 'Shows the current language; click it to pick another. Good where space is tight.', 'lango7' ), array( 'style' => 'dropdown' ) ),
			'[lango7_switcher style="globe"]'               => array( __( 'Globe icon', 'lango7' ), __( 'A small globe that opens the list. Made for headers and menus.', 'lango7' ), array( 'style' => 'globe' ) ),
			'[lango7_switcher names="name"]'                => array( __( 'English names', 'lango7' ), __( 'Spanish instead of Español. Add names="…" to any style.', 'lango7' ), array( 'names' => 'name' ) ),
			'[lango7_switcher names="code"]'                => array( __( 'Short codes', 'lango7' ), __( 'ES, MS, TH. Compact, for tight headers.', 'lango7' ), array( 'names' => 'code' ) ),
			'[lango7_switcher style="ul"]'                  => array( __( 'Bare list, to style yourself', 'lango7' ), __( 'A plain list with no Lango7 styling. Each link holds <span class="iso_name"> (ES) and <span class="full_name"> (Español); hide or style either with your theme\'s CSS. Use style="ol" for a numbered list, names="iso3" for SPA, names="name" for Spanish.', 'lango7' ), array( 'style' => 'ul' ) ),
		);
	}

	/**
	 * Drag the languages into the order the switcher shows them, as in Menus.
	 */
	private static function orderCard(): void {
		$languages = Plugin::instance()->languages();
		$items     = Switcher::ordered( $languages->enabled(), $languages->sourceCode() );
		if ( count( $items ) < 2 ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_add_inline_script(
			'jquery-ui-sortable',
			'jQuery(function($){var l=$("#lango7-lang-order");l.sortable({handle:".lango7-order-handle",axis:"y",placeholder:"lango7-order-placeholder",update:function(){$("#lango7-order-save").prop("disabled",false);}});l.on("click","button[data-move]",function(){var li=$(this).closest("li");if("up"===$(this).data("move")){li.prev().before(li);}else{li.next().after(li);}$("#lango7-order-save").prop("disabled",false);$(this).trigger("focus");});});'
		);
		$saved = isset( $_GET['order-saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
		?>
		<div class="lango7-card">
			<h2><?php esc_html_e( 'Language order', 'lango7' ); ?></h2>
			<p class="lango7-lead"><?php esc_html_e( 'Drag the languages into the order the switcher shows them, in every style, menu and block.', 'lango7' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Order saved.', 'lango7' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lango7_switcher_order">
				<?php wp_nonce_field( 'lango7_switcher_order' ); ?>
				<ul id="lango7-lang-order" class="lango7-lang-order">
					<?php foreach ( $items as $lang ) : ?>
						<?php $obj = $lang ?? $languages->source(); ?>
						<li>
							<span class="lango7-order-handle dashicons dashicons-menu" aria-hidden="true"></span>
							<input type="hidden" name="order[]" value="<?php echo esc_attr( $obj->code ); ?>">
							<span class="lango7-order-name"><?php echo esc_html( $obj->native_name ? $obj->native_name : $obj->name ); ?></span>
							<span class="description"><?php echo esc_html( null === $lang ? __( 'Original', 'lango7' ) : $obj->name ); ?></span>
							<span class="lango7-order-move">
								<?php /* translators: %s: language name. */ ?>
								<button type="button" class="button-link" data-move="up" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s up', 'lango7' ), $obj->name ) ); ?>">↑</button>
								<?php /* translators: %s: language name. */ ?>
								<button type="button" class="button-link" data-move="down" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s down', 'lango7' ), $obj->name ) ); ?>">↓</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><button type="submit" class="button button-primary" id="lango7-order-save" disabled><?php esc_html_e( 'Save order', 'lango7' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public static function saveOrder(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'lango7' ), 403 );
		}
		check_admin_referer( 'lango7_switcher_order' );
		$languages = Plugin::instance()->languages();
		$allowed   = array_merge( array( $languages->sourceCode() ), array_map( static fn( $l ): string => $l->code, $languages->enabled() ) );
		$order     = array();
		foreach ( (array) wp_unslash( $_POST['order'] ?? array() ) as $code ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against the language list.
			$code = (string) $code;
			if ( in_array( $code, $allowed, true ) && ! in_array( $code, $order, true ) ) {
				$order[] = $code;
			}
		}
		update_option( Switcher::ORDER_OPTION, $order );
		\Lango7\Cache\Purger::purgeAll();
		wp_safe_redirect( add_query_arg( 'order-saved', '1', self::url() ) );
		exit;
	}

	public static function render( bool $handled ): bool {
		if ( $handled || self::TAB !== self::tab() ) {
			return $handled;
		}
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return true;
		}
		PageShell::styles();
		$switcher = Plugin::instance()->switcher();
		echo '<div class="wrap lango7-wrap lango7-control-page lango7-switcher-page">';
		PageShell::header( __( 'Settings', 'lango7' ) );
		PageShell::nav( SettingsPage::SLUG );
		do_action( 'lango7_settings_navigation' );
		?>
		<?php self::orderCard(); ?>
		<div class="lango7-card">
			<h2><?php esc_html_e( 'Language switcher', 'lango7' ); ?></h2>
			<p class="lango7-lead"><?php esc_html_e( 'Visitors use the switcher to change language. Each link goes to the same page in that language, and search engines can follow it. Copy a shortcode into any page, widget or template.', 'lango7' ); ?></p>
			<table class="widefat striped lango7-switcher-examples">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'lango7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it shows', 'lango7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Preview', 'lango7' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( self::examples() as $code => $ex ) : ?>
					<tr>
						<td><code><?php echo esc_html( $code ); ?></code></td>
						<td><strong><?php echo esc_html( $ex[0] ); ?></strong><br><?php echo esc_html( $ex[1] ); ?></td>
						<td><div class="lango7-switcher-preview" inert><?php echo $switcher->render( $ex[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render(). ?></div></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Previews use your theme\'s fonts and colours on the site, so they will look a little different there.', 'lango7' ); ?></p>
		</div>
		<div class="lango7-card">
			<h2><?php esc_html_e( 'Other ways to add it', 'lango7' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Block editor: add the "Language Switcher" block.', 'lango7' ); ?></li>
				<li>
					<?php esc_html_e( 'Menu: Appearance → Menus, then add it from the "Language switcher" box.', 'lango7' ); ?>
					<?php if ( current_theme_supports( 'menus' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>"><?php esc_html_e( 'Open Menus', 'lango7' ); ?></a>
					<?php endif; ?>
				</li>
				<li><?php esc_html_e( 'Bricks Builder: add the "Language Switcher" element.', 'lango7' ); ?></li>
				<li><?php esc_html_e( 'PHP, in a theme template:', 'lango7' ); ?> <code>&lt;?php lango7_switcher( array( 'style' =&gt; 'dropdown' ) ); ?&gt;</code></li>
			</ul>
		</div>
		<?php
		echo '</div>';
		return true;
	}
}
