<?php
/**
 * Translate → Glossary.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Translation\Glossary;

/**
 * Lango7 keeps the site's name as it is in every language, automatically.
 * An extension can draw its own glossary screen here instead
 * (lango7_glossary_page_rendered, lango7_glossary_assets).
 */
final class GlossaryPage {

	public const SLUG = 'lango7-glossary';

	public function __construct( private Glossary $glossary ) {}

	public function register( string $parent ): void {
		$hook = add_submenu_page(
			$parent,
			__( 'Glossary', 'lango7' ),
			__( 'Glossary', 'lango7' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
		add_action( 'load-' . $hook, fn() => add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ) );
	}

	public function assets(): void {
		PageShell::styles();
		/**
		 * Enqueue the scripts of an extension's glossary screen.
		 */
		do_action( 'lango7_glossary_assets' );
	}

	public function render(): void {
		/**
		 * Draw another glossary screen instead; return true when it was drawn.
		 *
		 * @param bool $rendered
		 */
		if ( apply_filters( 'lango7_glossary_page_rendered', false ) ) {
			return;
		}
		$site = Glossary::siteName();
		?>
		<div class="wrap lango7-wrap lango7-glossary">
			<?php PageShell::header( __( 'Glossary', 'lango7' ) ); ?>
			<?php PageShell::proStart(); ?>
			<p class="lango7-lead"><?php esc_html_e( 'Words that must never be translated, like your business, brand and product names.', 'lango7' ); ?></p>
			<?php PageShell::nav( 'lango7-glossary' ); ?>

			<div class="lango7-card lango7-gl-site">
				<h2><?php esc_html_e( 'Protected keywords', 'lango7' ); ?></h2>
				<?php if ( '' !== $site ) : ?>
					<p>
						<?php
						/* translators: %s: the site name. */
						echo esc_html( sprintf( __( 'Your site name, “%s”, is protected automatically: it stays as it is in every language.', 'lango7' ), $site ) );
						?>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Your site has no name yet. Add one in Settings → General and Lango7 keeps it as it is in every language.', 'lango7' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Your site name is taken from Settings → General. New translations follow it straight away.', 'lango7' ); ?></p>
				<p class="lango7-gl-upgrade">
					<?php esc_html_e( 'For more protected keywords, or to always translate a word one way, upgrade to Lango7 Pro.', 'lango7' ); ?>
					<a class="button" href="<?php echo esc_url( (string) apply_filters( 'lango7_pro_url', 'https://lango7.com/pro/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'lango7' ); ?></a>
				</p>
			</div>
			<?php PageShell::proEnd(); ?>
		</div>
		<?php
	}
}
