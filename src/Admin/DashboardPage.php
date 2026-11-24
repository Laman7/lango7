<?php
/**
 * Translate (top-level menu): the setup until it is finished or skipped (or asked for again), then the dashboard.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Editor\EditMode;
use Lango7\Engine\EngineRegistry;
use Lango7\Languages;
use Lango7\Rest\AdminController;
use Lango7\Switcher\NavMenu;
use Lango7\Switcher\Switcher;
use Lango7\Translation\Glossary;

/**
 * The wizard asks for two languages and saves them; translating is then one
 * click away (Translate with Chrome in Free, automatic with a Pro engine). The
 * dashboard shows progress per language and what is left to do.
 */
final class DashboardPage {

	public const SLUG = 'lango7';

	public function __construct(
		private Languages $languages,
		private EngineRegistry $engines,
		private AdminController $rest,
		private Glossary $glossary,
		private Setup $setup,
		private News $news
	) {}

	public function register(): void {
		$hook = add_menu_page(
			__( 'Translate', 'lango7' ),
			__( 'Translate', 'lango7' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-translation',
			81
		);
		add_submenu_page( self::SLUG, __( 'Translate', 'lango7' ), __( 'Dashboard', 'lango7' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		add_action( 'load-' . $hook, fn() => add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function assets(): void {
		PageShell::styles();
		wp_enqueue_script( 'lango7-admin-api', LANGO7_URL . 'assets/admin/api.js', array(), \Lango7\Plugin::assetVersion( 'assets/admin/api.js' ), true );
		wp_enqueue_script( 'lango7-dashboard', LANGO7_URL . 'assets/admin/dashboard.js', array( 'lango7-admin-api' ), \Lango7\Plugin::assetVersion( 'assets/admin/dashboard.js' ), true );
		if ( $this->setup->wanted() ) {
			wp_enqueue_script( 'lango7-setup', LANGO7_URL . 'assets/admin/setup.js', array( 'lango7-admin-api' ), (string) filemtime( LANGO7_DIR . 'assets/admin/setup.js' ), true );
			wp_add_inline_script(
				'lango7-setup',
				'window.lango7Setup = ' . wp_json_encode(
					array(
						'rest'  => esc_url_raw( rest_url( AdminController::NS ) ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
					) + $this->setup->config()
				) . ';',
				'before'
			);
		}
		wp_add_inline_script(
			'lango7-dashboard',
			'window.lango7Dashboard = ' . wp_json_encode(
				array(
					'rest'      => esc_url_raw( rest_url( AdminController::NS ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'home'      => home_url( '/' ),
					'catalog'   => array_map( static fn( array $c ): string => $c['prefix'], Languages::available() ),
					'server'    => $this->engines->hasServerEngine(),
					'chromeUrl' => admin_url( 'admin.php?page=' . ChromePage::SLUG ),
					'i18n'      => array(
						'error'   => __( 'Something went wrong.', 'lango7' ),
						'copied'  => __( 'Copied', 'lango7' ),
						'added'   => __( 'Added. Open your site to see it.', 'lango7' ),
						'saving'  => __( 'Saving…', 'lango7' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public function render(): void {
		$setup = $this->setup->wanted();
		echo '<div class="wrap lango7-wrap lango7-dash">';
		PageShell::header( $setup ? __( 'Setup', 'lango7' ) : __( 'Overview', 'lango7' ) );
		if ( $setup ) {
			$this->setup->render();
		} else {
			$this->dashboard();
		}
		echo '</div>';
	}

	private function dashboard(): void {
		$status   = $this->rest->statusData();
		$server   = $this->engines->hasServerEngine();
		$waiting  = array_sum( array_column( $status['languages'], 'waiting' ) );
		$edited   = array_sum( array_column( $status['languages'], 'edited' ) );
		$switcher = Switcher::seen() || $this->menusWithSwitcher();
		// Only terms the site owner added; the site name is always protected.
		$terms    = (bool) apply_filters( 'lango7_glossary_terms', array(), '' );
		$langs    = count( $status['languages'] );
		$done     = array_sum( array_column( $status['languages'], 'done' ) );
		$engine   = (bool) array_filter( $this->engines->all(), static fn( $e ): bool => $e->isConfigured() );
		$pro      = $this->engines->hasServerEngine();
		$settings = admin_url( 'admin.php?page=' . SettingsPage::SLUG );
		/** The site brief AI engines follow (Lango7 Pro); null when there is no brief feature. */
		$brief    = apply_filters( 'lango7_site_brief', null );
		$chrome   = admin_url( 'admin.php?page=' . ChromePage::SLUG );
		$strings  = admin_url( 'admin.php?page=' . StringsPage::SLUG );
		$gloss    = admin_url( 'admin.php?page=' . GlossaryPage::SLUG );
		$news     = $this->news->items();
		?>
		<?php PageShell::proStart(); ?>
		<?php if ( $news['promo'] ) : ?>
			<a class="lango7-promo" href="<?php echo esc_url( $news['promo']['url'] ); ?>" target="_blank" rel="noopener"><span class="lango7-badge"><?php esc_html_e( 'Offer', 'lango7' ); ?></span> <strong><?php echo esc_html( $news['promo']['title'] ); ?></strong> <?php echo esc_html( $news['promo']['text'] ); ?></a>
		<?php endif; ?>
		<header class="lango7-page-header"><div><h2><?php esc_html_e( 'Lango7 – translate your site and reach a wider audience', 'lango7' ); ?></h2><p><?php esc_html_e( 'Managing multiple website translation with ease.', 'lango7' ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lango7-settings&tab=languages' ) ); ?>"><?php esc_html_e( 'Add language', 'lango7' ); ?></a></header>
		<?php PageShell::nav( 'lango7' ); ?>
		<?php
		/** Short status lines under the page links (e.g. Pro's Translate on publish). */
		do_action( 'lango7_dashboard_extra' );
		?>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
		if ( ! empty( $_GET['lango7_welcome'] ) ) {
			$this->setup->welcome();
		}
		?>

		<div class="lango7-overview-grid"><div class="lango7-langs">
			<?php
			foreach ( $status['languages'] as $l ) :
				$pct = $l['strings'] ? (int) floor( 100 * $l['done'] / $l['strings'] ) : 0;
				?>
				<div class="lango7-card lango7-lang">
                    <span class="lango7-completion"><?php echo esc_html( (string) $pct ); ?>%</span>
					<h2><?php echo esc_html( $l['name'] ); ?> <a class="lango7-lang-url" href="<?php echo esc_url( $l['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $l['url'], PHP_URL_PATH ) ?: '/' ); ?></a></h2>
					<progress aria-label="<?php echo esc_attr( $l['name'] ); ?>" max="100" value="<?php echo esc_attr( (string) $pct ); ?>"></progress>
					<?php
					$left = max( 0, (int) $l['strings'] - (int) $l['done'] );
					/* translators: 1: translated sentences, 2: all sentences. */
					$exact = sprintf( __( '%1$s of %2$s sentences translated', 'lango7' ), number_format_i18n( min( (int) $l['done'], (int) $l['strings'] ) ), number_format_i18n( (int) $l['strings'] ) );
					?>
					<p class="lango7-lang-numbers" title="<?php echo esc_attr( $exact ); ?>">
						<?php
						if ( 0 === $left ) {
							esc_html_e( '✓ Fully translated', 'lango7' );
						} else {
							/* translators: %s: percentage translated, rounded down. */
							echo esc_html( sprintf( __( '%s%% translated', 'lango7' ), number_format_i18n( $pct ) ) ) . ' · ';
							/* translators: %s: number of sentences without a translation. */
							echo '<a href="' . esc_url( add_query_arg( array( 'lang' => $l['code'], 'status' => 'untranslated' ), admin_url( 'admin.php?page=' . StringsPage::SLUG ) ) ) . '">' . esc_html( sprintf( __( '%s to go', 'lango7' ), number_format_i18n( $left ) ) ) . '</a>';
						}
						if ( $l['edited'] ) {
							/* translators: %s: number of strings. */
							echo ' · ' . esc_html( sprintf( _n( '%s edited by hand', '%s edited by hand', $l['edited'], 'lango7' ), number_format_i18n( $l['edited'] ) ) );
						}
						if ( $l['failed'] ) {
							/* translators: %s: number of strings. */
							echo ' · ' . esc_html( sprintf( _n( '%s failed', '%s failed', $l['failed'], 'lango7' ), number_format_i18n( $l['failed'] ) ) );
						}
						if ( ! empty( $l['review'] ) ) {
							/* translators: %s: number of strings. */
							echo ' · ' . esc_html( sprintf( _n( '%s needs review', '%s need review', $l['review'], 'lango7' ), number_format_i18n( $l['review'] ) ) );
						}
						?>
					</p>
					<p class="lango7-remaining"><?php /* translators: 1: number of strings, 2: number of characters. */ echo esc_html( sprintf( __( '%1$s strings remaining (%2$s characters)', 'lango7' ), number_format_i18n( max( 0, $l['strings'] - $l['done'] ) ), number_format_i18n( $l['remaining_chars'] ) ) ); ?></p>
                    <p class="lango7-lang-actions">
						<?php if ( $l['waiting'] && ! $server ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $chrome ); ?>">
								<?php
								/* translators: %s: number of strings. */
								echo esc_html( sprintf( _n( 'Translate %s string', 'Translate %s strings', $l['waiting'], 'lango7' ), number_format_i18n( $l['waiting'] ) ) );
								?>
							</a>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( EditMode::link( $l['url'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit on your site', 'lango7' ); ?></a>
						<a class="button <?php echo $l['waiting'] && ! $server ? '' : 'button-primary'; ?>" href="<?php echo esc_url( add_query_arg( 'lang', $l['code'], $strings ) ); ?>"><?php esc_html_e( 'Review translations', 'lango7' ); ?></a>
                        <a class="lango7-preview" href="<?php echo esc_url( $l['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview site ↗', 'lango7' ); ?></a>
					</p>
				</div>
			<?php endforeach; ?>
		</div>                <div class="lango7-card lango7-guidance">
                <span class="lango7-eyebrow"><?php esc_html_e( 'Recommended next', 'lango7' ); ?></span>
                <h2><?php echo esc_html( $waiting ? __( 'Finish your translations', 'lango7' ) : __( 'Check your translated pages', 'lango7' ) ); ?></h2>
                <p><?php echo esc_html( $waiting ? __( 'Translate the remaining content, then check the wording on your site.', 'lango7' ) : __( 'Read your translated pages in context and adjust any wording that needs attention.', 'lango7' ) ); ?></p>
                <a class="button" href="<?php echo esc_url( $waiting && ! $server ? $chrome : $strings ); ?>"><?php echo esc_html( $waiting && ! $server ? __( 'Open Free Translate', 'lango7' ) : __( 'Open translations', 'lango7' ) ); ?></a>
                </div>
</div>

		<div class="lango7-columns">
			<div class="lango7-main">
				<?php
				$steps = array(
					$langs > 0,
					$engine,
					null === $brief ? null : '' !== trim( (string) $brief ),
					$langs > 0 && $done > 0 && ! $waiting,
					(bool) $switcher,
					$terms,
					$edited > 0,
				);
				$count = array_filter( $steps, static fn( $v ): bool => null !== $v );
				?>
				<details class="lango7-card lango7-setup"<?php echo in_array( false, $count, true ) ? ' open' : ''; ?>><summary><?php /* translators: 1: number of setup steps done, 2: number of steps. */ echo esc_html( sprintf( __( 'Setup progress · %1$d of %2$d complete', 'lango7' ), count( array_filter( $count ) ), count( $count ) ) ); ?></summary>
					<ul class="lango7-todo">
						<li class="<?php echo $steps[1] ? 'is-done' : ''; ?>">
							<?php if ( $pro ) : ?>
								<a href="<?php echo esc_url( $settings . '&tab=engine' ); ?>"><?php esc_html_e( 'Add a translation engine (API key)', 'lango7' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'Add a translation engine', 'lango7' ); ?> — <?php esc_html_e( 'DeepL, Google and AI engines come with Lango7 Pro. Free Translate works without one.', 'lango7' ); ?>
							<?php endif; ?>
						</li>
						<li class="<?php echo $steps[0] ? 'is-done' : ''; ?>">
							<a href="<?php echo esc_url( $settings . '&tab=languages' ); ?>"><?php esc_html_e( 'Add a language', 'lango7' ); ?></a>
						</li>
						<?php if ( null !== $steps[2] ) : ?>
							<li class="<?php echo $steps[2] ? 'is-done' : ''; ?>">
								<a href="<?php echo esc_url( $settings . '&tab=brief' ); ?>"><?php esc_html_e( 'Write a site brief, so AI engines know your audience and tone', 'lango7' ); ?></a>
							</li>
						<?php endif; ?>
						<li class="<?php echo $steps[3] ? 'is-done' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . StringsPage::SLUG ) ); ?>"><?php esc_html_e( 'Translate your pages', 'lango7' ); ?></a>
							<?php if ( $langs && $waiting && ! $engine ) : ?>
								— <a href="<?php echo esc_url( $chrome ); ?>"><?php esc_html_e( 'Free Translate', 'lango7' ); ?></a>
							<?php elseif ( $langs && $waiting ) : ?>
								— <?php esc_html_e( 'pages translate as visitors open them', 'lango7' ); ?>
							<?php endif; ?>
						</li>
						<li class="<?php echo $steps[4] ? 'is-done' : ''; ?>">
							<a href="<?php echo esc_url( SwitcherTab::url() ); ?>"><?php esc_html_e( 'Add a language switcher, so visitors can find the translation', 'lango7' ); ?></a>
						</li>
						<li class="<?php echo $steps[5] ? 'is-done' : ''; ?>">
							<a href="<?php echo esc_url( $gloss ); ?>"><?php esc_html_e( 'Check your protected keywords in the glossary', 'lango7' ); ?></a>
						</li>
						<li class="<?php echo $steps[6] ? 'is-done' : ''; ?>">
							<?php esc_html_e( 'Read a page or two, and fix any wording with Edit on your site', 'lango7' ); ?>
						</li>
					</ul>
				</details>

				<?php $this->switcherCard(); ?>
			</div>
            <aside class="lango7-side lango7-flow" aria-label="<?php esc_attr_e( 'Translation guidance', 'lango7' ); ?>">
                <div class="lango7-card lango7-help"><h2><?php esc_html_e( 'Your workflow', 'lango7' ); ?></h2><ol><li><strong><?php esc_html_e( 'Choose Language', 'lango7' ); ?></strong><p><?php esc_html_e( 'Pick any language to add to the website', 'lango7' ); ?></p></li><li><strong><?php esc_html_e( 'Translate', 'lango7' ); ?></strong><p><?php esc_html_e( 'Perform language translation with ease for Free or with AI', 'lango7' ); ?></p></li><li><strong><?php esc_html_e( 'Review', 'lango7' ); ?></strong><p><?php esc_html_e( 'Edit languages, terminology and improve the comprehension', 'lango7' ); ?></p></li></ol></div>
				<?php if ( $news['posts'] ) : ?>
					<div class="lango7-card lango7-blog"><h2><?php esc_html_e( 'From the Lango7 blog', 'lango7' ); ?></h2><ul>
						<?php foreach ( $news['posts'] as $post ) : ?>
							<li><a href="<?php echo esc_url( $post['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $post['title'] ); ?></a><span><?php echo esc_html( $post['date'] ); ?></span></li>
						<?php endforeach; ?>
					</ul><a href="https://lango7.com/blog/" target="_blank" rel="noopener"><?php esc_html_e( 'More on the blog', 'lango7' ); ?></a></div>
				<?php elseif ( ! $this->news->enabled() ) : ?>
					<form class="lango7-card lango7-news-optin" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<h2><?php esc_html_e( 'Lango7 news', 'lango7' ); ?></h2>
						<p><?php esc_html_e( 'Show offers and the latest Lango7 blog posts here? Lango7 would read the public lango7.com news feed twice a day. Nothing about your site is sent.', 'lango7' ); ?></p>
						<input type="hidden" name="action" value="<?php echo esc_attr( News::ACTION ); ?>">
						<input type="hidden" name="news" value="1">
						<?php wp_nonce_field( News::ACTION ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Show news', 'lango7' ); ?></button>
					</form>
				<?php endif; ?>
            </aside>
		</div>
		<?php PageShell::proEnd(); ?>
		<?php
	}

	private function switcherCard(): void {
		$menus = wp_get_nav_menus();
		?>
		<details class="lango7-card" id="lango7-switcher-card"><summary><?php esc_html_e( 'Language switcher', 'lango7' ); ?></summary>
			<p><?php esc_html_e( 'Links to the same page in each language. Add it wherever suits your site:', 'lango7' ); ?></p>
			<dl class="lango7-ways lango7-placement">
				<dt><?php esc_html_e( 'In a menu', 'lango7' ); ?></dt>
				<dd>
					<?php if ( $menus && count( array_filter( $menus, fn( $menu ) => ! $this->menuHasSwitcher( (int) $menu->term_id ) ) ) ) : ?>
						<label class="screen-reader-text" for="lango7-menu"><?php esc_html_e( 'Menu', 'lango7' ); ?></label>
						<select id="lango7-menu">
							<?php foreach ( $menus as $menu ) : ?>
								<option value="<?php echo esc_attr( (string) $menu->term_id ); ?>" <?php disabled( $this->menuHasSwitcher( (int) $menu->term_id ) ); ?>><?php echo esc_html( $menu->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="lango7-menu-add"><?php esc_html_e( 'Add to menu', 'lango7' ); ?></button>
						<span class="lango7-result" id="lango7-menu-result" aria-live="polite"></span>
						<p class="description"><?php esc_html_e( 'Shows the current language, with the others in its sub-menu. You can move it in Appearance → Menus.', 'lango7' ); ?></p>
					<?php else : ?>
						<?php esc_html_e( 'No available menus. Create a menu or manage an existing switcher in Appearance → Menus.', 'lango7' ); ?>
					<?php endif; ?>
				</dd>
				<?php if ( class_exists( '\Bricks\Elements' ) ) : ?>
					<dt><?php esc_html_e( 'In Bricks', 'lango7' ); ?></dt>
					<dd><?php esc_html_e( 'Add the Language Switcher element, for example to your header template.', 'lango7' ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'In the block editor', 'lango7' ); ?></dt>
				<dd><?php esc_html_e( 'Add the Language Switcher block to a page, a template or the site header.', 'lango7' ); ?></dd>
				<dt><?php esc_html_e( 'Anywhere else', 'lango7' ); ?></dt>
				<dd>
					<span class="lango7-switcher-pick">
						<label for="lango7-switcher-style"><?php esc_html_e( 'Style', 'lango7' ); ?></label>
						<select id="lango7-switcher-style"><option value="dropdown"><?php esc_html_e( 'Dropdown', 'lango7' ); ?></option><option value="globe"><?php esc_html_e( 'Globe icon', 'lango7' ); ?></option><option value="list"><?php esc_html_e( 'List of links', 'lango7' ); ?></option></select>
					</span>
					<span class="lango7-switcher-pick">
					<label for="lango7-switcher-names"><?php esc_html_e( 'Language label', 'lango7' ); ?></label>
					<select id="lango7-switcher-names"><option value="native"><?php esc_html_e( 'Native name', 'lango7' ); ?></option><option value="name"><?php esc_html_e( 'Full language name', 'lango7' ); ?></option><option value="code"><?php esc_html_e( '2-letter code', 'lango7' ); ?></option><option value="iso3"><?php esc_html_e( '3-letter ISO code', 'lango7' ); ?></option></select>
					</span>
					<code id="lango7-shortcode">[lango7_switcher style="dropdown"]</code>
					<button type="button" class="button button-small" data-lango7-copy="lango7-shortcode"><?php esc_html_e( 'Copy', 'lango7' ); ?></button>
					<p class="description"><?php esc_html_e( 'style: dropdown, globe (a globe icon that opens the list) or list. names: native (Français, the default), name (French), code (FR) or iso3 (FRA).', 'lango7' ); ?></p>
				</dd>
			</dl>
		</details>
		<?php
	}

	private function menusWithSwitcher(): bool {
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( $this->menuHasSwitcher( (int) $menu->term_id ) ) {
				return true;
			}
		}
		return false;
	}

	private function menuHasSwitcher( int $menu_id ): bool {
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( NavMenu::URL === ( $item->url ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
