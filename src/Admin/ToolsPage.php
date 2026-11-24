<?php
/**
 * Translate → Tools: one screen with a tab per tool, styled like Settings.
 *
 *   Shortcodes      a different shortcode per language (Pro)
 *   Cost estimator  count the site's text and price it on every service (Free and Pro)
 *   Images          every image replaced for a language, changeable here (Pro)
 *   Translated URLs the address of every page per language (Pro)
 *   Categories and tags  the address of every category and tag per language (Pro)
 *   Import / export translations as CSV or XLIFF (Pro)
 *
 * Pro prints its tabs with the lango7_tools_tab_{tab} action. Without it, a
 * Pro tab shows its heading, what it does, and a link to get Pro.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Plugin;

final class ToolsPage {

	public const SLUG = 'lango7-tools';

	public function __construct( private Plugin $lango7 ) {}

	public function register( string $parent ): void {
		$hook = add_submenu_page( $parent, __( 'Tools', 'lango7' ), __( 'Tools', 'lango7' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		add_action( 'load-' . $hook, fn() => add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ) );
	}

	public static function url( string $tab = '' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . ( '' !== $tab ? '&tab=' . $tab : '' ) );
	}

	/**
	 * @return array<string, array{label: string, title: string, about: string, pro: bool}>
	 */
	public static function tabs(): array {
		$tabs = array(
			'cost' => array(
				'label' => __( 'Cost Estimator', 'lango7' ),
				'title' => __( 'Cost estimator', 'lango7' ),
				'about' => __( 'Read every page of your site, count its words and characters, and see what translating it costs on each service.', 'lango7' ),
				'pro'   => false,
			),
		);
		/**
		 * Tools tabs: key => label, title, description, and whether an extension adds it.
		 * Each extension tab is drawn on the lango7_tools_tab_{key} action.
		 *
		 * @param array<string, array{label: string, title: string, about: string, pro: bool}> $tabs
		 */
		return (array) apply_filters( 'lango7_tools_tabs', $tabs );
	}

	public static function current(): string {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the tab shown.
		$tabs = self::tabs();
		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	public function assets(): void {
		PageShell::styles();
		/** Lets Pro add the scripts of its tabs. */
		do_action( 'lango7_tools_assets', self::current() );
		if ( 'cost' !== self::current() ) {
			return;
		}
		wp_enqueue_script( 'lango7-admin-api', LANGO7_URL . 'assets/admin/api.js', array(), Plugin::assetVersion( 'assets/admin/api.js' ), true );
		wp_enqueue_script( 'lango7-tools', LANGO7_URL . 'assets/admin/tools.js', array( 'lango7-admin-api' ), Plugin::assetVersion( 'assets/admin/tools.js' ), true );
		wp_localize_script(
			'lango7-tools',
			'lango7Tools',
			array(
				'rest'  => rest_url( 'lango7/v1/' ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'pro'   => PageShell::isPro(),
				'i18n'  => array(
					'scan'      => __( 'Scan my site', 'lango7' ),
					'rescan'    => __( 'Scan again', 'lango7' ),
					'listing'   => __( 'Listing your pages…', 'lango7' ),
					/* translators: 1: pages read, 2: pages in total. */
					'reading'   => __( 'Reading pages: %1$d of %2$d…', 'lango7' ),
					'error'     => __( 'The scan stopped. Try again.', 'lango7' ),
					/* translators: %s: date and time. */
					'scanned'   => __( 'Scanned %s', 'lango7' ),
					'pages'     => __( 'Pages', 'lango7' ),
					'strings'   => __( 'Sentences', 'lango7' ),
					'words'     => __( 'Words', 'lango7' ),
					'chars'     => __( 'Characters', 'lango7' ),
					'service'   => __( 'Service', 'lango7' ),
					'price'     => __( 'Price per million characters', 'lango7' ),
					'one'       => __( 'Whole site, one language', 'lango7' ),
					/* translators: %d: number of languages. */
					'all'       => __( 'Whole site, your %d languages', 'lango7' ),
					'todo'      => __( 'Not translated yet', 'lango7' ),
					'todoHelp'  => __( 'Text in your languages that has no translation yet: what a first full run would cost today.', 'lango7' ),
					'free'      => __( 'Free', 'lango7' ),
					'proServices' => __( 'Free Translate is the service in Lango7. The other services are available with Lango7 Pro.', 'lango7' ),
					/* translators: %d: number of pages. */
					'failed'    => __( '%d pages could not be read and are not counted:', 'lango7' ),
					'estimate'  => __( 'Estimates only: services bill what they receive, and AI prices depend on how long the replies are.', 'lango7' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs    = self::tabs();
		$current = self::current();
		$tab     = $tabs[ $current ];
		?>
		<div class="wrap lango7-wrap lango7-tools lango7-tools-<?php echo esc_attr( $current ); ?>">
			<?php PageShell::header( __( 'Tools', 'lango7' ) ); ?>
			<?php PageShell::proStart(); ?>
			<?php PageShell::nav( self::SLUG ); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Tools', 'lango7' ); ?>">
				<?php foreach ( $tabs as $key => $t ) : ?>
					<a class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $t['label'] ); ?>
						<?php if ( $t['pro'] && ! PageShell::isPro() ) : ?>
							<span class="lango7-pro-badge"><?php esc_html_e( 'Pro', 'lango7' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( has_action( 'lango7_tools_tab_' . $current ) ) {
				do_action( 'lango7_tools_tab_' . $current, $tab );
			} else {
				$this->cost( $tab );
			}
			?>
			<?php if ( ! PageShell::isPro() ) : ?>
				<p class="lango7-tools-more"><?php esc_html_e( 'Lango7 Pro adds more tools: shortcodes and images per language, translated page addresses, and import / export.', 'lango7' ); ?></p>
			<?php endif; ?>
			<?php PageShell::proEnd(); ?>
		</div>
		<?php
	}

	/** Shared heading for a tab. */
	public static function head( array $tab ): void {
		echo '<div class="lango7-tool-head"><h2>' . esc_html( $tab['title'] ) . '</h2><p>' . esc_html( $tab['about'] ) . '</p></div>';
	}

	private function cost( array $tab ): void {
		?>
		<section class="lango7-card lango7-cost">
			<?php self::head( $tab ); ?>
			<p class="lango7-cost-actions">
				<button type="button" class="button button-primary" id="lango7-cost-scan"><?php esc_html_e( 'Scan my site', 'lango7' ); ?></button>
				<span class="lango7-cost-status" id="lango7-cost-status" role="status"></span>
			</p>
			<progress id="lango7-cost-progress" max="1" value="0" hidden></progress>
			<div id="lango7-cost-result"></div>
			<p class="description"><?php esc_html_e( 'The scan reads your public pages from your own site, the way a visitor gets them. Nothing is sent to a translation service.', 'lango7' ); ?></p>
		</section>
		<?php
	}
}
