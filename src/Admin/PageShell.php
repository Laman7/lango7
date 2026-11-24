<?php
/** Shared navigation for Lango7 administration screens. */
declare( strict_types=1 );
namespace Lango7\Admin;

final class PageShell {
	/** The one stylesheet every Lango7 admin screen uses (Free and Pro). */
	public static function styles(): void {
		wp_enqueue_style( 'lango7-admin', LANGO7_URL . 'assets/admin/admin.css', array(), (string) filemtime( LANGO7_DIR . 'assets/admin/admin.css' ) );
	}
	public static function isPro(): bool {
		return class_exists( \Lango7\Pro\Pro::class );
	}

	/** Opens the page body next to the "Get Pro Version" card. Prints nothing when Pro is active. */
	public static function proStart(): void {
		if ( ! self::isPro() ) {
			echo '<div class="lango7-dash-layout"><div class="lango7-dash-body">';
		}
	}

	/** Closes the page body and prints the "Get Pro Version" card. Prints nothing when Pro is active. */
	public static function proEnd(): void {
		if ( self::isPro() ) {
			return;
		}
		$features = array(
			__( 'Automatic translation with Google, DeepL, OpenAI, Claude or Gemini', 'lango7' ),
			__( 'Bulk translate the whole site, with the cost shown first', 'lango7' ),
			__( 'Translated page addresses', 'lango7' ),
			__( 'Language sitemaps and SEO controls', 'lango7' ),
			__( 'A glossary for any keyword', 'lango7' ),
			__( 'Translator role, per-language access and translation history', 'lango7' ),
		);
		?>
		</div><aside class="lango7-pro-card" aria-label="<?php esc_attr_e( 'Lango7 Pro', 'lango7' ); ?>">
			<h2><?php esc_html_e( 'Get Pro Version', 'lango7' ); ?></h2>
			<p><?php esc_html_e( 'Everything in Free, plus:', 'lango7' ); ?></p>
			<ul><?php foreach ( $features as $f ) : ?><li><?php echo esc_html( $f ); ?></li><?php endforeach; ?></ul>
			<a class="button" href="<?php echo esc_url( (string) apply_filters( 'lango7_pro_url', 'https://lango7.com/pro/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'lango7' ); ?></a>
		</aside></div>
		<?php
		// Line the card up with the page's first card, below the title and links.
		wp_print_inline_script_tag( '(function(){var c=document.querySelector(".lango7-pro-card"),l=c&&c.parentNode,f=l&&l.querySelector(".lango7-page-nav ~ .lango7-card, .lango7-page-nav ~ * .lango7-card");function a(){c.style.marginTop=f&&innerWidth>=1600?Math.max(0,f.getBoundingClientRect().top-l.getBoundingClientRect().top)+"px":"";}if(c){a();addEventListener("resize",a);addEventListener("load",a);}})();' );
	}

	/**
	 * The Lango7 bar at the top of every Lango7 screen: logo and page name.
	 * Admin notices are placed below it (wp-header-end).
	 *
	 * @param string $title   Page name, e.g. "Free Translate".
	 * @param string $actions Optional HTML for buttons on the right, already escaped.
	 */
	public static function header( string $title, string $actions = '' ): void {
		?>
		<header class="lango7-brandbar">
			<img class="lango7-brandbar-logo" src="<?php echo esc_url( LANGO7_URL . 'assets/admin/lango7-logo-white.svg' ); ?>" width="101" height="24" alt="<?php esc_attr_e( 'Lango7', 'lango7' ); ?>">
			<span class="lango7-brandbar-sep" aria-hidden="true"></span>
			<h1 class="lango7-brandbar-title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== $actions ) : ?>
				<span class="lango7-brandbar-actions"><?php echo wp_kses_post( $actions ); ?></span>
			<?php endif; ?>
		</header>
		<hr class="wp-header-end">
		<?php
	}

	public static function nav( string $current ): void {
		$items = array(
			'lango7' => __( 'Overview', 'lango7' ),
			'lango7-chrome' => __( 'Free Translate', 'lango7' ),
			'lango7-strings' => __( 'Translations', 'lango7' ),
			'lango7-activity' => __( 'Activity', 'lango7' ),
			'lango7-bulk' => __( 'Bulk Translate', 'lango7' ),
			'lango7-glossary' => __( 'Glossary', 'lango7' ),
			'lango7-tools' => __( 'Tools', 'lango7' ),
			'lango7-settings' => __( 'Settings', 'lango7' ),
		);
		if ( ! self::isPro() ) {
			unset( $items['lango7-bulk'] );
		}
		/**
		 * The plugin's page links: slug => label. Pro adds pages such as Bulk translate.
		 *
		 * @param array<string, string> $items
		 */
		$items = (array) apply_filters( 'lango7_admin_nav', $items );
		echo '<nav class="lango7-page-nav" aria-label="' . esc_attr__( 'Translate pages', 'lango7' ) . '">';
		foreach ( $items as $slug => $label ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '"' . ( $slug === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}
}
