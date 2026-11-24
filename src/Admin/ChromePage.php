<?php
/**
 * Translate → Translate with Chrome.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Cache\Purger;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Rest\AdminController;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;

/**
 * Free's translation engine: Chrome's built-in, on-device Translator API,
 * running in the admin's own browser. The screen visits each translated page
 * (which records its text), translates whatever has no translation yet, and
 * saves it through lango7/v1/chrome/*. Visitors then get finished,
 * server-rendered pages in any browser.
 *
 * Any language Chrome supports can be picked here. Starting one that is not
 * turned on yet adds it, or, when the language limit is reached (Free: one),
 * replaces the current one, exactly as saving it in Settings would.
 */
final class ChromePage {

	public const SLUG = 'lango7-chrome';

	public function __construct( private Languages $languages, private StringRepository $strings, private TranslationRepository $translations ) {}

	public function register(): void {
		$hook = add_submenu_page(
			DashboardPage::SLUG,
			__( 'Free Translate', 'lango7' ),
			__( 'Free Translate', 'lango7' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
		add_action( 'load-' . $hook, fn() => add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ) );
	}

	/**
	 * Enabled languages first, then every other one Chrome can translate into.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function choices(): array {
		$source  = $this->languages->sourceCode();
		$total   = $this->strings->count();
		$done    = $this->translations->countsByLanguage();
		$enabled = array();
		foreach ( $this->languages->enabled() as $l ) {
			$enabled[ $l->code ] = true;
		}
		$out = array();
		foreach ( Languages::available() as $code => $c ) {
			if ( $code === $source || '' === $c['chrome'] ) {
				continue;
			}
			$out[] = array(
				'code'    => $code,
				'name'    => $c['name'],
				'native'  => $c['native'],
				'prefix'  => $c['prefix'],
				'chrome'  => $c['chrome'],
				'enabled' => isset( $enabled[ $code ] ),
				'waiting' => max( 0, $total - (int) ( $done[ $code ] ?? 0 ) ),
			);
		}
		usort( $out, static fn( array $a, array $b ): int => (int) $b['enabled'] <=> (int) $a['enabled'] );
		return $out;
	}

	public function assets(): void {
		PageShell::styles();
		wp_enqueue_script( 'lango7-chrome-core', LANGO7_URL . 'assets/admin/chrome-core.js', array(), \Lango7\Plugin::assetVersion( 'assets/admin/chrome-core.js' ), true );
		wp_enqueue_script( 'lango7-chrome', LANGO7_URL . 'assets/admin/chrome-tool.js', array( 'lango7-chrome-core' ), (string) filemtime( LANGO7_DIR . 'assets/admin/chrome-tool.js' ), true );
		wp_add_inline_script(
			'lango7-chrome',
			'window.lango7Chrome = ' . wp_json_encode(
				array(
					'rest'      => esc_url_raw( rest_url( AdminController::NS ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'source'    => Languages::chromeCode( $this->languages->sourceCode() ),
                    'sourceCode' => $this->languages->sourceCode(),
					'languages' => $this->choices(),
					'enabled'   => array_map( static fn( Language $l ): array => $l->toArray(), $this->languages->enabled() ),
					'home'      => home_url( '/' ),
					'page'      => $this->onePage(),
					'hash'      => $this->oneString(),
					'i18n'      => array(
						'noApi'       => __( 'This browser has no built-in translator. Open this page in Google Chrome 138 or later on a desktop or laptop computer.', 'lango7' ),
                        'chromeOnly' => __( 'Free Translate works in desktop Google Chrome. Open this page in Chrome to continue.', 'lango7' ),
						'insecure'    => __( 'Chrome only allows its built-in translator on secure pages. Open this admin screen over https:// (for a local site, turn on and trust its SSL certificate).', 'lango7' ),
						'noResponse'  => __( 'Chrome\'s built-in translator did not respond. Use Google Chrome itself (not another Chromium-based app), version 138 or later, and check that on-device translation is not turned off by policy.', 'lango7' ),
						'unsupported' => __( 'Chrome cannot translate this language pair.', 'lango7' ),
                        'invalidSource' => __( 'The site language has no Chrome translation code. Choose a supported site language in Settings, then reload this page.', 'lango7' ),
						'ready'       => __( 'Ready. Chrome has the language model on this computer.', 'lango7' ),
						'download'    => __( 'Ready. Chrome will download the language model (one time) when you start.', 'lango7' ),
						'preparing'   => __( 'Preparing Chrome translator…', 'lango7' ),
						'translatingSelected' => __( 'Translating the selected string…', 'lango7' ),
						/* translators: %s: percent downloaded. */
						'downloading' => __( 'Downloading the language model… %s%%', 'lango7' ),
						'listing'     => __( 'Finding pages…', 'lango7' ),
						/* translators: 1: page number, 2: number of pages, 3: page path. */
						'page'        => __( 'Page %1$d of %2$d: %3$s', 'lango7' ),
						'leftovers'   => __( 'Translating text found on other pages…', 'lango7' ),
						/* translators: 1: strings translated, 2: strings rejected. */
						'counts'      => __( '%1$d strings translated · %2$d rejected', 'lango7' ),
						'done'        => __( 'Done. Translated pages are live.', 'lango7' ),
						'stopped'     => __( 'Stopped. Start again to continue where you left off.', 'lango7' ),
						'error'       => __( 'Something went wrong.', 'lango7' ),
						'leave'       => __( 'Translation is still running. Leave anyway?', 'lango7' ),
						/* translators: %s: number of strings. */
						'waiting'     => __( 'Only text already found on visited pages (%s waiting)', 'lango7' ),
						'start'       => __( 'Start translating', 'lango7' ),
						/* translators: %s: language name. */
						'add'         => __( '%s is not on your site yet. Starting adds it, at its own URL folder.', 'lango7' ),
						/* translators: %s: language name. */
						'addNew'      => __( 'Add %s as New Language', 'lango7' ),
						'switching'   => __( 'Switching the site language…', 'lango7' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The page asked for by "Translate now" (?path=), relative to the site root, or null.
	 */
	private function oneString(): ?string {
		$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only preselects a translation scope.
		return preg_match( '/^[0-9a-f]{40}$/', $hash ) ? $hash : null;
	}

	private function onePage(): ?string {
		$path = isset( $_GET['path'] ) ? rawurldecode( esc_url_raw( wp_unslash( (string) $_GET['path'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only preselects an option.
		$rel  = '' !== $path && '/' === $path[0] ? \Lango7\Html\UrlMatcher::relative( $path, (string) wp_parse_url( home_url(), PHP_URL_PATH ) ) : null;
		return null !== $rel && '' !== $rel ? $rel : null;
	}

	private function pendingOnly(): bool {
		return isset( $_GET['scope'] ) && 'pending' === sanitize_key( wp_unslash( (string) $_GET['scope'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only preselects a scope.
	}

	public function render(): void {
		$choices = $this->choices();
		$first   = $choices[0] ?? null;
		?>
		<div class="wrap lango7-wrap">
			<?php PageShell::header( __( 'Free Translate', 'lango7' ) ); ?>
			<?php PageShell::proStart(); ?>
			<p class="lango7-lead"><?php esc_html_e( 'Google Chrome has a translator built in that runs on your computer, free and without an API key. Lango7 uses it to translate your pages here, then saves the translations to your site, so every visitor, in any browser, gets fast, indexable translated pages.', 'lango7' ); ?></p>
			<?php PageShell::nav( 'lango7-chrome' ); ?>

			<div class="lango7-chrome-grid lango7-dash">
			<div class="lango7-card lango7-chrome">
				<p id="lango7-chrome-check" class="lango7-check" aria-live="polite"></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lango7-chrome-lang"><?php esc_html_e( 'Language', 'lango7' ); ?></label></th>
						<td>
							<select id="lango7-chrome-lang">
								<?php if ( ! ( $first['enabled'] ?? false ) ) : ?>
									<option value=""><?php esc_html_e( '— Choose —', 'lango7' ); ?></option>
								<?php endif; ?>
								<?php $this->options( $choices, true, __( 'On your site', 'lango7' ) ); ?>
								<?php $this->options( $choices, false, __( 'Other languages Chrome can translate', 'lango7' ) ); ?>
							</select>
							<p class="description" id="lango7-chrome-switch" hidden></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What to translate', 'lango7' ); ?></th>
						<td>
						<?php if ( null !== $this->oneString() ) : ?>
							<label><input type="radio" name="lango7-scope" value="string" checked> <?php esc_html_e( 'Only the selected translation', 'lango7' ); ?></label><br>
						<?php endif; ?>
							<?php $page = $this->onePage(); ?>
							<?php if ( null !== $page ) : ?>
								<label><input type="radio" name="lango7-scope" value="page" <?php checked( null === $this->oneString() ); ?>> <?php /* translators: %s: page path. */ echo esc_html( sprintf( __( 'Only this page: %s', 'lango7' ), $page ) ); ?></label><br>
							<?php endif; ?>
						<label><input type="radio" name="lango7-scope" value="site" <?php checked( null === $this->oneString() && null === $page && ! $this->pendingOnly() ); ?>> <?php esc_html_e( 'Every published page and post', 'lango7' ); ?></label><br>
						<label><input type="radio" name="lango7-scope" value="pending" <?php checked( null === $this->oneString() && null === $page && $this->pendingOnly() ); ?>> <span id="lango7-chrome-waiting"></span></label>
							<p class="description"><?php esc_html_e( 'Only new or changed text is translated; anything translated before is skipped.', 'lango7' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-primary" id="lango7-chrome-start" disabled><?php esc_html_e( 'Start translating', 'lango7' ); ?></button>
					<button type="button" class="button" id="lango7-chrome-stop" hidden><?php esc_html_e( 'Stop', 'lango7' ); ?></button>
				</p>
				<div id="lango7-chrome-progress" hidden>
					<progress max="1" value="0"></progress>
					<p class="lango7-chrome-step" aria-live="polite"></p>
					<p class="lango7-chrome-counts"></p>
				</div>
				<p id="lango7-chrome-done" class="lango7-chrome-done" role="status" hidden><?php esc_html_e( 'Done, review your language.', 'lango7' ); ?></p>
                <div id="lango7-chrome-next" class="lango7-chrome-next" hidden>
					<strong><?php esc_html_e( 'What next?', 'lango7' ); ?></strong>
					<ul>
						<li><a id="lango7-chrome-view" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'View the translated site', 'lango7' ); ?></a></li>
						<li><a href="<?php echo esc_url( DashboardPage::url() . '#lango7-switcher-card' ); ?>"><?php esc_html_e( 'Add a language switcher so visitors can find it', 'lango7' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . GlossaryPage::SLUG ) ); ?>"><?php esc_html_e( 'Check your protected keywords in the glossary', 'lango7' ); ?></a></li>
						<?php if ( null !== Purger::reminder() ) : ?>
							<li><?php echo esc_html( Purger::reminder() ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
				<p class="description"><?php esc_html_e( 'Keep this tab open while it runs. Translation happens on this computer: page text is not sent to Google, Lango7 or anyone else. Chrome\'s translations are good but not perfect; you can correct any sentence afterwards.', 'lango7' ); ?></p>
			</div>
            <aside class="lango7-card lango7-guidance"><h2><?php esc_html_e( 'Before you start translating', 'lango7' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Open this page in desktop Google Chrome with a secure HTTPS connection or localhost.', 'lango7' ); ?></p>
            <p class="description"><?php esc_html_e( 'Choose a language Chrome supports. Chrome may download a language model the first time.', 'lango7' ); ?></p>
            <p class="description"><?php esc_html_e( 'Keep this tab open while translation runs. Your browser must stay online to save translations.', 'lango7' ); ?></p>
</aside>
			</div>
            <details class="lango7-card" id="lango7-chrome-debug"><summary><?php esc_html_e( 'Translation diagnostics', 'lango7' ); ?></summary><p class="description"><?php esc_html_e( 'Share this information when reporting a problem. It contains browser and language codes only, not page text or API keys.', 'lango7' ); ?></p><pre id="lango7-chrome-debug-output" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre></details>
			<?php PageShell::proEnd(); ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $choices
	 */
	private function options( array $choices, bool $enabled, string $label ): void {
		$group = array_filter( $choices, static fn( array $c ): bool => $c['enabled'] === $enabled );
		if ( ! $group ) {
			return;
		}
		printf( '<optgroup label="%s">', esc_attr( $label ) );
		foreach ( $group as $c ) {
			printf( '<option value="%s">%s</option>', esc_attr( $c['code'] ), esc_html( $c['name'] . ( $c['native'] !== $c['name'] ? ' — ' . $c['native'] : '' ) ) );
		}
		echo '</optgroup>';
	}
}
