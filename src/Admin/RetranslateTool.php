<?php
/**
 * Tools → Retranslate: delete machine translations, for one language or all,
 * so they are translated again. Edits are kept. Moved here from Settings so a
 * destructive action does not sit beside everyday settings.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Cache\Purger;
use Lango7\Plugin;

final class RetranslateTool {

	public const TAB = 'retranslate';

	private const ACTION = 'lango7_retranslate';

	public function __construct( private Plugin $lango7 ) {}

	public function register(): void {
		add_filter( 'lango7_tools_tabs', array( $this, 'tab' ), 99 );
		add_action( 'lango7_tools_tab_' . self::TAB, array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'run' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function tab( array $tabs ): array {
		$tabs[ self::TAB ] = array(
			'label' => __( 'Retranslate', 'lango7' ),
			'title' => __( 'Retranslate', 'lango7' ),
			'about' => __( 'Delete machine translations so pages are translated again, for example after you change the translation service or the glossary. Translations you edited by hand are kept.', 'lango7' ),
			'pro'   => false,
		);
		return $tabs;
	}

	/**
	 * @param array<string, mixed> $tab
	 */
	public function render( array $tab ): void {
		ToolsPage::head( $tab );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
		$done = isset( $_GET['deleted'] ) ? absint( wp_unslash( $_GET['deleted'] ) ) : null;
		?>
		<?php if ( null !== $done ) : ?>
			<div class="notice notice-success inline"><p>
				<?php
				/* translators: %s: number of translations deleted. */
				echo esc_html( sprintf( _n( '%s machine translation deleted. Pages are translated again as they are visited or translated.', '%s machine translations deleted. Pages are translated again as they are visited or translated.', $done, 'lango7' ), number_format_i18n( $done ) ) );
				?>
			</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lango7-retranslate-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<p>
				<label for="lango7-retranslate-lang"><strong><?php esc_html_e( 'Language', 'lango7' ); ?></strong></label><br>
				<select id="lango7-retranslate-lang" name="lang">
					<option value=""><?php esc_html_e( 'All languages', 'lango7' ); ?></option>
					<?php foreach ( $this->lango7->languages()->enabled() as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang->code ); ?>"><?php echo esc_html( $lang->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I understand the machine translations are deleted and cannot be brought back. Translating them again uses your translation service, which may cost money.', 'lango7' ); ?></label></p>
			<p><button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete machine translations', 'lango7' ); ?></button></p>
		</form>
		<?php
	}

	public function run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lango7' ), 403 );
		}
		check_admin_referer( self::ACTION );
		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( ToolsPage::url( self::TAB ) );
			exit;
		}
		$code  = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$codes = array_map( static fn( $l ): string => $l->code, $this->lango7->languages()->enabled() );
		if ( '' !== $code && ! in_array( $code, $codes, true ) ) {
			wp_safe_redirect( ToolsPage::url( self::TAB ) );
			exit;
		}
		$deleted = $this->lango7->translations()->resetMachine( '' === $code ? null : $code );
		Purger::purgeAll();
		PendingBar::flush();
		UntranslatedReport::flush();
		wp_safe_redirect( add_query_arg( 'deleted', $deleted, ToolsPage::url( self::TAB ) ) );
		exit;
	}
}
