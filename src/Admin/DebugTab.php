<?php
/**
 * Settings → Debug: the error log, one line per entry, with Clear and a switch
 * to stop recording errors.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Log;

final class DebugTab {

	public const TAB = 'debug';

	private const ACTION = 'lango7_debug';

	public static function register(): void {
		add_filter( 'lango7_settings_tab_rendered', array( self::class, 'render' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
		// Pro prints the full tab bar; on its own, Lango7 has three tabs.
		add_action(
			'lango7_settings_navigation',
			static function (): void {
				if ( ! PageShell::isPro() ) {
					self::navigation();
				}
			}
		);
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . self::TAB );
	}

	private static function tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the tab shown.
	}

	private static function navigation(): void {
		$current = in_array( self::tab(), array( self::TAB, SwitcherTab::TAB ), true ) ? self::tab() : 'settings';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Translation settings', 'lango7' ) . '">';
		foreach ( array(
			'settings'       => array( __( 'Settings', 'lango7' ), admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			SwitcherTab::TAB => array( __( 'Switcher', 'lango7' ), SwitcherTab::url() ),
			self::TAB        => array( __( 'Debug', 'lango7' ), self::url() ),
		) as $key => $item ) {
			echo '<a class="nav-tab' . ( $key === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a>';
		}
		echo '</nav>';
	}

	public static function render( bool $handled ): bool {
		if ( $handled || self::TAB !== self::tab() ) {
			return $handled;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return true;
		}
		PageShell::styles();
		$entries = Log::recent( 200 );
		$off     = Log::off();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
		$done = isset( $_GET['lango7_debug'] ) ? sanitize_key( wp_unslash( $_GET['lango7_debug'] ) ) : '';
		echo '<div class="wrap lango7-wrap lango7-control-page lango7-debug-page">';
		PageShell::header( __( 'Settings', 'lango7' ) );
		PageShell::nav( SettingsPage::SLUG );
		do_action( 'lango7_settings_navigation' );
		if ( 'cleared' === $done ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Debug log cleared.', 'lango7' ) . '</p></div>';
		} elseif ( 'saved' === $done ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'lango7' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<div class="lango7-debug-head">
				<div>
					<h2><?php esc_html_e( 'Error log', 'lango7' ); ?></h2>
					<p class="description">
						<?php
						echo esc_html(
							$entries
								/* translators: %s: number of entries. */
								? sprintf( _n( '%s entry, newest first. The log keeps the last 200.', '%s entries, newest first. The log keeps the last 200.', count( $entries ), 'lango7' ), number_format_i18n( count( $entries ) ) )
								: __( 'Nothing logged. Translation errors, failed engine replies and blocked requests show here.', 'lango7' )
						);
						?>
					</p>
				</div>
				<button type="submit" name="do" value="clear" class="button"<?php disabled( ! $entries ); ?>><?php esc_html_e( 'Clear log', 'lango7' ); ?></button>
			</div>
			<?php if ( $entries ) : ?>
				<table class="widefat striped lango7-debug-log">
					<thead><tr><th scope="col"><?php esc_html_e( 'Time', 'lango7' ); ?></th><th scope="col"><?php esc_html_e( 'Error', 'lango7' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'lango7' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td class="lango7-debug-time"><?php echo esc_html( (string) wp_date( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
							<td class="lango7-debug-context"><?php echo esc_html( self::context( (array) ( $entry['context'] ?? array() ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Logging', 'lango7' ); ?></h2>
			<p><label><input type="checkbox" name="off" value="1"<?php checked( $off ); ?>> <?php esc_html_e( "Don't log errors", 'lango7' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'Errors are still handled the same way; they are just not written down. Leave logging on while setting up or when something looks wrong.', 'lango7' ); ?></p>
			<p><button type="submit" name="do" value="save" class="button button-primary"><?php esc_html_e( 'Save changes', 'lango7' ); ?></button></p>
		</form>
		</div>
		<?php
		return true;
	}

	/**
	 * "route: /bricks/v1/ · lang: es".
	 *
	 * @param array<string, mixed> $context
	 */
	public static function context( array $context ): string {
		$out = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$out[] = $key . ': ' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
			}
		}
		return implode( ' · ', $out );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lango7' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'clear' === $do ) {
			Log::clear();
		} else {
			Log::setOff( ! empty( $_POST['off'] ) );
		}
		wp_safe_redirect( add_query_arg( 'lango7_debug', 'clear' === $do ? 'cleared' : 'saved', self::url() ) );
		exit;
	}
}
