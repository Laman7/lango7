<?php
/**
 * Translate → Settings screen.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Engine\EngineRegistry;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Rest\AdminController;
use Lango7\Settings;

/**
 * Rendered server-side with current values; saving, engine tests and warm-up
 * go through the lango7/v1 REST API from assets/admin/settings.js.
 *
 * Free translates with Chrome (see ChromePage). Server engines come from Pro
 * through the lango7_engines filter and add their own fields with the
 * lango7_settings_engine action; the engine-only settings (cap, time budget)
 * are shown only when one is registered.
 */
final class SettingsPage {

	public const SLUG = 'lango7-settings';

	/** User meta: the Pro card was dismissed. */
	public const DISMISS_META = 'lango7_pro_card_dismissed';

	public function __construct(
		private Settings $settings,
		private Languages $languages,
		private EngineRegistry $engines,
		private AdminController $rest
	) {}

	public function register(): void {
		$hook = add_submenu_page(
			DashboardPage::SLUG,
			__( 'Settings', 'lango7' ),
			__( 'Settings', 'lango7' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
		add_action( 'load-' . $hook, fn() => add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ) );
	}

	public function assets(): void {
		PageShell::styles();
		wp_enqueue_script( 'lango7-admin', LANGO7_URL . 'assets/admin/settings.js', array(), \Lango7\Plugin::assetVersion( 'assets/admin/settings.js' ), true );
		wp_add_inline_script(
			'lango7-admin',
			'window.lango7Admin = ' . wp_json_encode(
				array(
					'rest'    => esc_url_raw( rest_url( AdminController::NS ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'langs'   => $this->pickerLanguages(),
					'names'   => $this->serviceNames(),
					'popular' => array( 'es', 'fr', 'de', 'zh-Hans', 'ja', 'pt-BR', 'ar', 'it' ),
					'page'    => admin_url( 'admin.php?page=' . self::SLUG . '&tab=languages' ),
					'i18n'    => array(
						'saved'     => __( 'Settings saved.', 'lango7' ),
						'addTitle'  => __( 'Add a language', 'lango7' ),
						'swapTitle' => __( 'Change language', 'lango7' ),
						/* translators: %s: language name. */
						'addThis'   => __( 'Add %s', 'lango7' ),
						/* translators: %s: language name. */
						'swapThis'  => __( 'Switch to %s', 'lango7' ),
						/* translators: 1: current language, 2: new language. */
						'swapNote'  => __( '%1$s will be replaced by %2$s. Its translations are kept in case you switch back, and its old addresses redirect to your original pages.', 'lango7' ),
						'popular'   => __( 'Popular:', 'lango7' ),
						'added'     => __( 'Added', 'lango7' ),
						'siteLang'  => __( 'Site language', 'lango7' ),
						/* translators: %s: search text. */
						'noMatch'   => __( 'No language matches “%s”.', 'lango7' ),
						'badPrefix' => __( 'Use lowercase letters, numbers and dashes for the address.', 'lango7' ),
						'adding'    => __( 'Adding…', 'lango7' ),
						'noService' => __( 'Needs another service', 'lango7' ),
						/* translators: 1: service names, 2: language name. */
						'cannot'    => __( '%1$s cannot translate %2$s, so it is greyed out.', 'lango7' ),
						/* translators: 1: service name, 2: language name. */
						'stuck'     => __( '%1$s cannot translate %2$s. Choose another service below.', 'lango7' ),
						/* translators: %s: language name. */
						'none'      => __( 'None of the services you set up can translate %s. Add a key for another service (Google, OpenAI, Claude or Gemini translate almost every language) under Translation engine first.', 'lango7' ),
						'notFor'    => __( 'not available', 'lango7' ),
						'testing'   => __( 'Testing…', 'lango7' ),
						'notSaved'  => __( 'The key was not saved.', 'lango7' ),
						'removeKey' => __( 'Remove this saved key? Languages using this service stop translating new text until you add a key again.', 'lango7' ),
						'keyRemoved' => __( 'Key removed.', 'lango7' ),
						'warming'   => __( 'Translating the homepage… this can take up to 30 seconds.', 'lango7' ),
						'warmed'    => __( 'Homepage translated.', 'lango7' ),
						'partial'   => __( 'Homepage partly translated; the rest is finishing in the background.', 'lango7' ),
						'confirm'   => __( 'Delete all machine translations so they are translated again? Your edits are kept.', 'lango7' ),
						'reset'     => __( 'Machine translations cleared.', 'lango7' ),
						'removeAsk' => __( 'Remove this language from the site now?', 'lango7' ),
						'deleteAsk' => __( 'Remove this language and permanently delete all its translations, including your edits? This cannot be undone.', 'lango7' ),
						'error'     => __( 'Something went wrong.', 'lango7' ),
						'noLang'    => __( 'Pick a target language first.', 'lango7' ),
						'dupLang'   => __( 'Each language can only be added once.', 'lango7' ),
						/* translators: 1: translated strings, 2: all strings, 3: queued, 4: failed. */
						'progress'  => __( '%1$d of %2$d strings translated · %3$d queued · %4$d failed', 'lango7' ),
						/* translators: 1: translated strings, 2: all strings, 3: strings waiting. */
						'waiting'   => __( '%1$d of %2$d strings translated · %3$d waiting for Chrome', 'lango7' ),
						/* translators: 1: characters used today, 2: daily cap. */
						'today'     => __( 'Characters today: %1$s / %2$s', 'lango7' ),
						/* translators: 1: characters this month, 2: estimated cost. */
						'month'     => __( 'This month: %1$s characters, about USD %2$s', 'lango7' ),
						'noCap'     => __( 'no cap', 'lango7' ),
						'view'      => __( 'View site', 'lango7' ),
						'capHit'    => __( 'Daily cap reached. New text will be translated tomorrow.', 'lango7' ),
						'errors'    => __( 'Recent errors', 'lango7' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public function render(): void {
		if ( apply_filters( 'lango7_settings_tab_rendered', false ) ) {
			return;
		}
		$s         = $this->settings->all();
		$available = Languages::available();
		$targets   = $this->languages->enabled();
		$status    = $this->rest->statusData();
		$by_code   = array_column( $status['languages'], null, 'code' );
		$server    = $this->engines->hasServerEngine();
		$view = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'languages'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the tab shown.
		?>
		<div class="wrap lango7-wrap <?php echo $server ? 'lango7-settings-pro ' : ''; ?>lango7-settings-view-<?php echo esc_attr( $view ); ?>">
			<?php PageShell::header( __( 'Settings', 'lango7' ), '<a class="button" href="' . esc_url( Setup::url() ) . '">' . esc_html__( 'Run setup again', 'lango7' ) . '</a>' ); ?>
			<?php PageShell::proStart(); ?>
			<?php PageShell::nav( 'lango7-settings' ); ?>
			<?php do_action( 'lango7_settings_navigation' ); ?>
			<div id="lango7-notice" class="notice inline" hidden><p></p></div>

			<div class="lango7-columns">
			<form id="lango7-settings" class="lango7-main" autocomplete="off">

				<div class="lango7-card lango7-engine-panel">
					<h2><?php esc_html_e( 'Translation engine', 'lango7' ); ?></h2>
					<?php if ( ! $server ) : ?>
						<p><strong><?php esc_html_e( 'Chrome built-in translator (free)', 'lango7' ); ?></strong></p>
						<p><?php esc_html_e( 'Google Chrome on your computer translates your pages, with no API key and no cost. Lango7 saves the translations to your site, so every visitor, in any browser, gets fast translated pages that search engines can index.', 'lango7' ); ?></p>
						<p><?php esc_html_e( 'After adding or changing content, open Free Translate to translate the new text. Until then, new text shows in the site language.', 'lango7' ); ?></p>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ChromePage::SLUG ) ); ?>"><?php esc_html_e( 'Open Free Translate', 'lango7' ); ?></a></p>
					<?php endif; ?>
					<?php
					/**
					 * Print engine settings (API keys, test buttons) inside the engine card.
					 * Fields with a name attribute are sent with the settings form.
					 */
					do_action( 'lango7_settings_engine' );
					?>
				</div>

				<?php $this->proCard( $server ); ?>

				<div class="lango7-card lango7-languages-panel">
					<?php $this->addedNotice( $status ); ?>
					<div class="lango7-langs-head">
						<div>
							<h2><?php esc_html_e( 'Languages', 'lango7' ); ?></h2>
							<p><?php esc_html_e( 'Visitors can read your site in these languages. Each one gets its own URLs, hreflang tags and sitemap entries.', 'lango7' ); ?></p>
						</div>
						<button type="button" class="button button-primary lango7-lang-add" id="lango7-lang-add">
							<span aria-hidden="true">+</span> <?php esc_html_e( 'Add a language', 'lango7' ); ?>
						</button>
					</div>

					<div class="lango7-source-row">
						<label for="lango7-source"><?php esc_html_e( 'Your site is written in', 'lango7' ); ?></label>
						<?php $this->select( 'source_lang', 'lango7-source', (string) $s['source_lang'], $available ); ?>
					</div>

					<ul id="lango7-langs" class="lango7-lang-list">
						<?php foreach ( $targets as $lang ) : ?>
							<?php $this->languageRow( $lang, $by_code[ $lang->code ] ?? array() ); ?>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! $targets ) : ?>
						<div class="lango7-lang-empty">
							<p><strong><?php esc_html_e( 'No languages yet', 'lango7' ); ?></strong></p>
							<p><?php esc_html_e( 'Add a language and your site gets a translated copy at its own address, such as /es/ for Spanish.', 'lango7' ); ?></p>
						</div>
					<?php endif; ?>


					<details class="lango7-css-help">
						<summary><?php esc_html_e( 'Style each language with CSS', 'lango7' ); ?></summary>
						<?php $css_code = $targets ? $targets[0]->code : 'es'; ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: CSS class name, e.g. lango7-lang-es. */
									__( 'Every page has a language class on <body>, such as %s, so you can change fonts, spacing or text size for one language in your theme or builder\'s custom CSS. Translated pages also have lango7-translated.', 'lango7' ),
									'lango7-lang-' . $css_code
								)
							);
							?>
						</p>
						<p><code>.lango7-lang-<?php echo esc_html( $css_code ); ?> h1 { font-size: 2.25rem; }</code></p>
						<p><a href="<?php echo esc_url( (string) apply_filters( 'lango7_help_url', 'https://lango7.com/blog/style-each-language/', 'language-css' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'How to style each language', 'lango7' ); ?></a></p>
					</details>
				</div>

				<div class="lango7-card lango7-limits-panel">
					<h2><?php echo esc_html( $server ? __( 'Limits and exclusions', 'lango7' ) : __( 'Exclusions', 'lango7' ) ); ?></h2>
					<table class="form-table" role="presentation">
						<?php if ( $server ) : ?>
						<tr>
							<th scope="row"><label for="lango7-cap"><?php esc_html_e( 'Daily character cap', 'lango7' ); ?></label></th>
							<td>
								<input type="number" id="lango7-cap" name="daily_cap" min="0" step="1000" class="regular-text" value="<?php echo esc_attr( (string) $s['daily_cap'] ); ?>">
								<p class="description"><?php esc_html_e( 'Stops engine calls for the day once reached; untranslated text shows in the site language until tomorrow. 0 = no cap.', 'lango7' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="lango7-exclude-urls"><?php esc_html_e( 'Do not translate URLs', 'lango7' ); ?></label></th>
							<td>
								<textarea id="lango7-exclude-urls" name="exclude_urls" rows="4" class="large-text code"><?php echo esc_textarea( (string) $s['exclude_urls'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One path per line. End with * to include everything below, e.g. /my-account/*', 'lango7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lango7-exclude-selectors"><?php esc_html_e( 'Do not translate elements', 'lango7' ); ?></label></th>
							<td>
								<textarea id="lango7-exclude-selectors" name="exclude_selectors" rows="3" class="large-text code" placeholder=".site-logo, #price-table"><?php echo esc_textarea( (string) $s['exclude_selectors'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'CSS selectors: tag, #id, .class, [attribute]. Elements with translate="no" or class "notranslate" are always skipped.', 'lango7' ); ?></p>
							</td>
						</tr>
					</table>
					<details class="lango7-advanced">
						<summary><?php echo esc_html( $server ? __( 'Performance, news and uninstall', 'lango7' ) : __( 'News and uninstall', 'lango7' ) ); ?></summary>
						<table class="form-table" role="presentation">
							<?php if ( $server ) : ?>
							<tr>
								<th scope="row"><label for="lango7-budget"><?php esc_html_e( 'Time budget per page (seconds)', 'lango7' ); ?></label></th>
								<td><input type="number" id="lango7-budget" name="time_budget" min="1" max="30" step="0.5" class="small-text" value="<?php echo esc_attr( (string) $s['time_budget'] ); ?>">
									<span class="description"><?php esc_html_e( 'How long a first visit may wait for the engine. The rest is translated in the background.', 'lango7' ); ?></span></td>
							</tr>
							<tr>
								<th scope="row"><label for="lango7-crawler"><?php esc_html_e( 'Search crawler budget (seconds)', 'lango7' ); ?></label></th>
								<td><input type="number" id="lango7-crawler" name="crawler_budget" min="1" max="60" step="0.5" class="small-text" value="<?php echo esc_attr( (string) $s['crawler_budget'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="lango7-max"><?php esc_html_e( 'Max new strings per request', 'lango7' ); ?></label></th>
								<td><input type="number" id="lango7-max" name="max_segments" min="10" max="2000" class="small-text" value="<?php echo esc_attr( (string) $s['max_segments'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="lango7-batch"><?php esc_html_e( 'Characters per engine call', 'lango7' ); ?></label></th>
								<td><input type="number" id="lango7-batch" name="batch_chars" min="500" max="30000" step="500" class="small-text" value="<?php echo esc_attr( (string) $s['batch_chars'] ); ?>"></td>
							</tr>
							<?php endif; ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Lango7 news', 'lango7' ); ?></th>
								<td><input type="hidden" name="news" value="0"><label><input type="checkbox" name="news" value="1" <?php checked( (bool) $s['news'] ); ?>> <?php esc_html_e( 'Show offers and the latest blog posts from lango7.com on the dashboard', 'lango7' ); ?></label>
									<p class="description"><?php esc_html_e( 'Off until you turn it on. Reads the public lango7.com news feed twice a day. Nothing about your site is sent.', 'lango7' ); ?></p></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'On uninstall', 'lango7' ); ?></th>
								<td>
									<label><input type="radio" name="uninstall" value="keep" <?php checked( 'keep', $s['uninstall'] ); ?>> <?php esc_html_e( 'Keep translations', 'lango7' ); ?></label><br>
									<label><input type="radio" name="uninstall" value="drop" <?php checked( 'drop', $s['uninstall'] ); ?>> <?php esc_html_e( 'Delete all translations and settings', 'lango7' ); ?></label>
								</td>
							</tr>
						</table>
					</details>
				</div>

				<div class="lango7-card lango7-disclosure">
					<h2><?php esc_html_e( 'Privacy', 'lango7' ); ?></h2>
					<p><?php esc_html_e( 'Lango7 sends nothing about your site to external services. The dashboard can show news read from lango7.com, but only if you turn it on below. Free Translate runs Chrome\'s built-in translator on your own computer: Chrome downloads its language model from Google once, and page text stays on your computer and your site. Translations are stored in this site\'s database.', 'lango7' ); ?></p>
					<?php
					/**
					 * Describe what a server engine sends where (required by the plugin guidelines).
					 */
					do_action( 'lango7_settings_disclosure' );
					?>
				</div>

				<p class="submit lango7-save-bar"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'lango7' ); ?></button> <span class="lango7-save-status" role="status"></span></p>
			</form>
			<?php $this->addDialog( count( Languages::available() ), count( Languages::catalog() ) ); ?>

			</div>
			<?php PageShell::proEnd(); ?>
		</div>
		<?php
	}

	/**
	 * Pro features, on this screen only, and dismissible per user.
	 */
	private function proCard( bool $server ): void {
		if ( $server || ! apply_filters( 'lango7_show_pro_card', true ) || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		?>
		<div class="lango7-card lango7-pro" id="lango7-pro">
			<button type="button" class="notice-dismiss lango7-dismiss" id="lango7-pro-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss', 'lango7' ); ?></span></button>
			<h2><?php esc_html_e( 'Want translation to happen by itself?', 'lango7' ); ?></h2>
			<p><?php esc_html_e( 'Lango7 Pro adds server translation engines, so new pages are translated on their first visit without opening Chrome:', 'lango7' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Google Cloud Translation, DeepL, and AI translation with OpenAI, Anthropic Claude or Google Gemini (your own API key)', 'lango7' ); ?></li>
				<li><?php esc_html_e( 'Languages Chrome cannot translate, such as Malay, Filipino, Persian and Urdu', 'lango7' ); ?></li>
				<li><?php esc_html_e( 'A different engine for each language', 'lango7' ); ?></li>
			</ul>
			<p><a class="button button-primary" href="<?php echo esc_url( (string) apply_filters( 'lango7_pro_url', 'https://lango7.com/pro/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'lango7' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * @return array<string, string> Service id => name.
	 */
	private function serviceNames(): array {
		$out = array( 'chrome' => $this->engines->serviceLabel( 'chrome' ) );
		foreach ( $this->engines->all() as $engine ) {
			$out[ $engine->id() ] = $engine->label();
		}
		return $out;
	}

	/**
	 * The languages the Add dialog offers: only those this site can translate
	 * (Chrome's, unless an extension widens Languages::available()).
	 *
	 * @return array<string, array{name: string, native: string, prefix: string, chrome: bool}>
	 */
	private function pickerLanguages(): array {
		$source = $this->languages->sourceCode();
		$out    = array();
		foreach ( Languages::available() as $code => $c ) {
			$out[ $code ] = array(
				'name'      => $c['name'],
				'native'    => $c['native'],
				'prefix'    => $c['prefix'],
				'chrome'    => '' !== $c['chrome'],
				'services'  => $this->engines->servicesFor( $code, $source ),
				'default'   => $this->engines->serviceFor( Language::fromArray( array( 'code' => $code ) ) ),
			);
		}
		uasort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * One added language: a summary line (name, address, progress, engine) and
	 * an Edit panel with its fields. Fields carry data-field instead of a name;
	 * the script sends each row as one entry of "languages".
	 *
	 * @param array<string, mixed> $status This language's entry from AdminController::statusData().
	 */
	private function languageRow( Language $lang, array $status ): void {
		$url     = (string) ( $status['url'] ?? home_url( '/' . $lang->prefix . '/' ) );
		$panel   = 'lango7-lang-' . sanitize_html_class( $lang->code );
		$catalog = Languages::catalog()[ $lang->code ] ?? array();
		$done    = (int) ( $status['done'] ?? 0 );
		?>
		<li class="lango7-lang-row" data-saved="<?php echo esc_attr( $lang->code ); ?>">
			<input type="hidden" data-field="code" value="<?php echo esc_attr( $lang->code ); ?>">
			<div class="lango7-lang-summary">
				<span class="lango7-lang-code" aria-hidden="true"><?php echo esc_html( strtoupper( $lang->prefix ) ); ?></span>
				<div class="lango7-lang-name">
					<strong><?php echo esc_html( $lang->name ); ?></strong>
					<?php if ( ! empty( $catalog['native'] ) && $catalog['native'] !== $lang->name ) : ?>
						<span lang="<?php echo esc_attr( $lang->code ); ?>"><?php echo esc_html( $catalog['native'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! Languages::isLive( $lang ) ) : ?>
						<span class="lango7-lang-tag"><?php esc_html_e( 'Draft', 'lango7' ); ?></span>
					<?php endif; ?>
					<?php if ( $lang->noindex ) : ?>
						<span class="lango7-lang-tag"><?php esc_html_e( 'Hidden from search', 'lango7' ); ?></span>
					<?php endif; ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $url ) ); ?></a>
				</div>
				<?php if ( $this->engines->canTranslate( $lang, $this->languages->sourceCode() ) ) : ?>
					<span class="lango7-lang-engine"><?php echo esc_html( $this->engineLabel( $lang ) ); ?></span>
				<?php else : ?>
					<span class="lango7-lang-engine is-error" title="<?php echo esc_attr( sprintf( /* translators: 1: service name, 2: language name. */ __( '%1$s cannot translate %2$s. Edit the language and choose another service.', 'lango7' ), $this->engineLabel( $lang ), $lang->name ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: service name. */ __( '%s cannot translate this', 'lango7' ), $this->engineLabel( $lang ) ) ); ?></span>
				<?php endif; ?>
				<button type="button" class="button lango7-lang-edit" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel ); ?>">
					<?php esc_html_e( 'Edit', 'lango7' ); ?><span class="screen-reader-text"> <?php echo esc_html( $lang->name ); ?></span>
				</button>
			</div>
			<div class="lango7-lang-details" id="<?php echo esc_attr( $panel ); ?>" hidden>
				<?php $this->languageFields( $lang ); ?>
				<p class="lango7-lang-save-hint"><?php esc_html_e( 'Save settings below to keep your changes.', 'lango7' ); ?></p>
				<details class="lango7-remove">
					<summary><?php echo esc_html( sprintf( /* translators: %s: language name. */ __( 'Remove %s', 'lango7' ), $lang->name ) ); ?></summary>
					<p class="description"><?php echo esc_html( sprintf( /* translators: 1: URL folder, 2: example original URL. */ __( 'Its pages, switcher entry, hreflang tags and sitemap entries go at once. Old links such as /%1$s/about/ redirect permanently to the original page (%2$s), so search engines drop them cleanly.', 'lango7' ), $lang->prefix, '/about/' ) ); ?></p>
					<p><label><input type="checkbox" class="lango7-remove-delete"> <?php echo esc_html( sprintf( /* translators: %s: number of translations. */ __( 'Also delete its %s translations, including your edits (otherwise they are kept in case you add it back)', 'lango7' ), number_format_i18n( $done ) ) ); ?></label></p>
					<p><button type="button" class="button lango7-button-delete lango7-remove-button" data-code="<?php echo esc_attr( $lang->code ); ?>"><?php echo esc_html( sprintf( /* translators: %s: language name. */ __( 'Remove %s', 'lango7' ), $lang->name ) ); ?></button></p>
				</details>
			</div>
		</li>
		<?php
	}

	/**
	 * The fields of one language (web address, engine and extras from Pro),
	 * for an added language's Edit panel or, with null, the Add dialog.
	 */
	private function languageFields( ?Language $lang ): void {
		$key     = $lang ? sanitize_html_class( $lang->code ) : 'new';
		$engines = array_filter( $this->engines->all(), static fn( $e ): bool => $e->isConfigured() || ( $lang && $lang->engine === $e->id() ) );
		$host    = preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );
		?>
		<div class="lango7-lang-fields">
			<div class="lango7-field">
				<label for="lango7-prefix-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Web address', 'lango7' ); ?></label>
				<div class="lango7-url-input">
					<span><?php echo esc_html( $host ); ?>/</span><input type="text" id="lango7-prefix-<?php echo esc_attr( $key ); ?>" data-field="prefix" value="<?php echo esc_attr( $lang ? $lang->prefix : '' ); ?>" pattern="[a-z0-9\-]{1,20}" maxlength="20" spellcheck="false" autocapitalize="off"><span>/</span>
				</div>
				<p class="description"><?php echo $lang ? esc_html__( 'Lowercase letters, numbers and dashes. If you change it, the old addresses redirect to the new ones.', 'lango7' ) : esc_html__( 'Lowercase letters, numbers and dashes. Every page of this language lives under this folder.', 'lango7' ); ?></p>
			</div>
			<?php if ( $engines ) : ?>
				<div class="lango7-field">
					<label for="lango7-engine-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Translated by', 'lango7' ); ?></label>
					<select id="lango7-engine-<?php echo esc_attr( $key ); ?>" data-field="engine">
						<option value=""><?php echo esc_html( sprintf( /* translators: %s: service name. */ __( 'Default service (%s)', 'lango7' ), $this->engineLabel( Language::fromArray( array( 'code' => $lang ? $lang->code : 'es' ) ) ) ) ); ?></option>
						<option value="chrome" <?php selected( 'chrome', $lang ? $lang->engine : '' ); ?>><?php esc_html_e( 'Free Translate (Chrome on your computer)', 'lango7' ); ?></option>
						<?php foreach ( $engines as $engine ) : ?>
							<option value="<?php echo esc_attr( $engine->id() ); ?>" <?php selected( $engine->id(), $lang ? $lang->engine : '' ); ?>><?php echo esc_html( $engine->label() ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description lango7-engine-note" hidden></p>
				</div>
			<?php endif; ?>
			<div class="lango7-field lango7-lang-extra">
				<?php
				/**
				 * Print extra per-language fields. Give them data-field="name" to have
				 * them sent with the language.
				 *
				 * @param Language|null $lang Null in the Add dialog.
				 */
				do_action( 'lango7_settings_language_row', $lang );
				?>
			</div>
		</div>
		<?php
	}

	private function engineLabel( Language $lang ): string {
		return $this->engines->serviceLabel( $this->engines->serviceFor( $lang ) );
	}

	/**
	 * The Add a language dialog: search the catalogue, then set the address.
	 */
	/**
	 * @param int $count Languages the dialog offers.
	 * @param int $all   Languages Lango7 Pro offers.
	 */
	private function addDialog( int $count, int $all ): void {
		?>
		<dialog id="lango7-lang-dialog" class="lango7-lang-dialog" aria-labelledby="lango7-lang-dialog-title">
			<div class="lango7-lang-dialog-head">
				<h2 id="lango7-lang-dialog-title"><?php esc_html_e( 'Add a language', 'lango7' ); ?></h2>
				<button type="button" class="lango7-dialog-close" data-lango7-close aria-label="<?php esc_attr_e( 'Close', 'lango7' ); ?>">&times;</button>
			</div>
			<ol class="lango7-lang-steps">
				<li data-for="pick"><?php esc_html_e( 'Choose a language', 'lango7' ); ?></li>
				<li data-for="setup"><?php esc_html_e( 'Check the address', 'lango7' ); ?></li>
			</ol>
			<section data-step="pick">
				<label for="lango7-lang-search" class="screen-reader-text"><?php esc_html_e( 'Search languages', 'lango7' ); ?></label>
				<input type="search" id="lango7-lang-search" class="lango7-lang-search" role="combobox" aria-expanded="true" aria-controls="lango7-lang-options" aria-autocomplete="list" autocomplete="off" placeholder="<?php echo esc_attr( sprintf( /* translators: %d: number of languages. */ __( 'Search %d languages, e.g. French or Français', 'lango7' ), $count ) ); ?>">
				<div class="lango7-lang-popular" id="lango7-lang-popular"></div>
				<ul id="lango7-lang-options" class="lango7-lang-options" role="listbox" aria-label="<?php esc_attr_e( 'Languages', 'lango7' ); ?>"></ul>
				<p class="lango7-lang-none" id="lango7-lang-none" hidden></p>
				<?php if ( $all > $count ) : ?>
					<p class="lango7-lang-more">
						<?php
						/* translators: %d: number of languages. */
						echo esc_html( sprintf( __( 'Can\'t find your language? Lango7 Pro offers %d languages.', 'lango7' ), $all ) );
						?>
						<a href="<?php echo esc_url( (string) apply_filters( 'lango7_pro_url', 'https://lango7.com/pro/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Go Pro', 'lango7' ); ?></a>
					</p>
				<?php endif; ?>
			</section>
			<section data-step="setup" hidden>
				<div class="lango7-lang-picked">
					<span class="lango7-lang-code" id="lango7-lang-picked-code" aria-hidden="true"></span>
					<div><strong id="lango7-lang-picked-name"></strong> <span id="lango7-lang-picked-native"></span></div>
					<button type="button" class="button-link" data-lango7-back><?php esc_html_e( 'Choose another', 'lango7' ); ?></button>
				</div>
				<div class="lango7-lang-new" id="lango7-lang-new"><?php $this->languageFields( null ); ?></div>
				<p class="lango7-lang-replace" id="lango7-lang-replace" hidden></p>
			</section>
			<p class="lango7-result" id="lango7-lang-result" role="status"></p>
			<div class="lango7-lang-dialog-foot">
				<button type="button" class="button" data-lango7-close><?php esc_html_e( 'Cancel', 'lango7' ); ?></button>
				<button type="button" class="button button-primary" id="lango7-lang-confirm" hidden></button>
			</div>
		</dialog>
		<?php
	}

	/**
	 * After a language is added: where it lives and what to do next.
	 *
	 * @param array<string, mixed> $status From AdminController::statusData().
	 */
	private function addedNotice( array $status ): void {
		$code = isset( $_GET['lango7_added'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lango7_added'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$lang = '' !== $code ? $this->languages->byCode( $code ) : null;
		if ( ! $lang ) {
			return;
		}
		$url = (string) ( array_column( $status['languages'], 'url', 'code' )[ $lang->code ] ?? home_url( '/' . $lang->prefix . '/' ) );
		/**
		 * The next step shown after a language is added: array( label, url ).
		 *
		 * @param array{0: string, 1: string} $action
		 * @param Language                    $lang
		 */
		$action = (array) apply_filters(
			'lango7_language_added_action',
			array(
				/* translators: %s: language name. */
				sprintf( __( 'Translate into %s with Free Translate', 'lango7' ), $lang->name ),
				admin_url( 'admin.php?page=' . ChromePage::SLUG ),
			),
			$lang
		);
		?>
		<div class="lango7-lang-added" role="status">
			<p>
				<strong><?php echo esc_html( sprintf( /* translators: %s: language name. */ __( '%s is added.', 'lango7' ), $lang->name ) ); ?></strong>
				<?php esc_html_e( 'Its pages are at', 'lango7' ); ?> <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $url ) ); ?></a>.
				<?php esc_html_e( 'Until they are translated, visitors there see your original text.', 'lango7' ); ?>
			</p>
			<?php if ( ! empty( $action[0] ) && ! empty( $action[1] ) ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( (string) $action[1] ); ?>"><?php echo esc_html( (string) $action[0] ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function select( string $name, string $id, string $value, array $catalog, bool $blank = false, string $field = '' ): void {
		if ( '' !== $value && ! isset( $catalog[ $value ] ) && isset( Languages::catalog()[ $value ] ) ) {
			$catalog = array( $value => Languages::catalog()[ $value ] ) + $catalog; // Saved earlier, e.g. with Pro.
		}
		echo '<select';
		foreach ( array_filter( array( 'name' => $name, 'id' => $id, 'data-field' => $field ) ) as $attr => $v ) {
			printf( ' %s="%s"', esc_attr( $attr ), esc_attr( $v ) );
		}
		echo '>';
		if ( $blank ) {
			echo '<option value="">' . esc_html__( '— Choose —', 'lango7' ) . '</option>';
		}
		foreach ( $catalog as $code => $c ) {
			printf(
				'<option value="%s" data-prefix="%s"%s>%s</option>',
				esc_attr( $code ),
				esc_attr( $c['prefix'] ),
				selected( $code, $value, false ),
				esc_html( $c['name'] . ( $c['native'] !== $c['name'] ? ' — ' . $c['native'] : '' ) )
			);
		}
		echo '</select>';
	}

}
