<?php
/**
 * Translate → Strings.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Engine\EngineRegistry;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Rest\AdminController;
use Lango7\Rest\StringsController;
use Lango7\Store\TranslationRepository;

/**
 * Every piece of text Lango7 has found, with its translation: search, filter,
 * correct a translation in place, or send machine translations back to be
 * translated again. Drawn by assets/admin/strings.js.
 */
final class StringsPage {

	public const SLUG = 'lango7-strings';

	public function __construct( private Languages $languages, private EngineRegistry $engines ) {}

	public function register( string $parent ): void {
		$hook = add_submenu_page(
			$parent,
			__( 'Translations', 'lango7' ),
			__( 'Translations', 'lango7' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
		add_action( 'load-' . $hook, fn() => add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ) );
	}

	public function assets(): void {
		PageShell::styles();
		wp_enqueue_script( 'lango7-admin-api', LANGO7_URL . 'assets/admin/api.js', array(), \Lango7\Plugin::assetVersion( 'assets/admin/api.js' ), true );
		wp_enqueue_script( 'lango7-editor-core', LANGO7_URL . 'assets/editor/editor-core.js', array(), \Lango7\Plugin::assetVersion( 'assets/editor/editor-core.js' ), true );
		wp_enqueue_script( 'lango7-chrome-core', LANGO7_URL . 'assets/admin/chrome-core.js', array(), \Lango7\Plugin::assetVersion( 'assets/admin/chrome-core.js' ), true );
		wp_enqueue_script( 'lango7-strings', LANGO7_URL . 'assets/admin/strings.js', array( 'lango7-admin-api', 'lango7-editor-core', 'lango7-chrome-core' ), \Lango7\Plugin::assetVersion( 'assets/admin/strings.js' ), true );
		wp_add_inline_script(
			'lango7-strings',
			'window.lango7Strings = ' . wp_json_encode(
				array(
					'rest'      => esc_url_raw( rest_url( AdminController::NS ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'languages' => array_map( static fn( Language $l ): array => $l->toArray(), $this->languages->enabled() ),
					'server'    => $this->engines->hasServerEngine(),
					'engines'   => $this->engineLabels(),
					'chromeSource' => Languages::chromeCode( $this->languages->sourceCode() ),
					'chromeTargets' => array_combine(
						array_map( static fn( Language $l ): string => $l->code, $this->languages->enabled() ),
						array_map( static fn( Language $l ): string => Languages::chromeCode( $l->code ), $this->languages->enabled() )
					) ?: array(),
					'chromeUrl' => admin_url( 'admin.php?page=' . ChromePage::SLUG ),
					'orphanDays' => StringsController::ORPHAN_DAYS,
					// Languages whose new machine translations wait for approval (Lango7 Pro).
					'approval'  => array_values(
						array_map(
							static fn( Language $l ): string => $l->code,
							array_filter( $this->languages->enabled(), static fn( Language $l ): bool => TranslationRepository::UNAPPROVED === TranslationRepository::machineStatus( $l->code ) )
						)
					),
					'i18n'      => array(
						'machine'       => __( 'Machine', 'lango7' ),
						'unapproved'    => __( 'Awaiting approval', 'lango7' ),
						/* translators: %s: language names, e.g. Chinese (Simplified). */
						'notApproval'   => __( 'This language publishes machine translations straight away. Approval is on for: %s. Pick that language above.', 'lango7' ),
						'noApprovals'   => __( 'Nothing is waiting for approval. New machine translations in this language appear here until an editor approves them.', 'lango7' ),
						/* translators: %s: translation service, e.g. DeepL. */
						'withEngine'    => __( 'Translate with %s', 'lango7' ),
						'noEngine'      => __( 'Free Translate only', 'lango7' ),
						'translateAgain' => __( 'Translate Now', 'lango7' ),
						'translating'   => __( 'Translating…', 'lango7' ),
						'preparingChrome' => __( 'Preparing Chrome translator…', 'lango7' ),
						/* translators: %s: download progress, 0 to 100. */
						'downloadingChrome' => __( 'Downloading language model… %s%%', 'lango7' ),
						'chromeOnly'   => __( 'Translate Now here requires desktop Google Chrome.', 'lango7' ),
						'chromeSecure' => __( 'Chrome translation needs HTTPS or localhost.', 'lango7' ),
						'chromeMissing' => __( 'Chrome\'s built-in translator is unavailable in this browser.', 'lango7' ),
						'chromeFailed' => __( 'Chrome could not translate this string. The previous translation was kept.', 'lango7' ),
						'chromeDone'   => __( 'Translation updated.', 'lango7' ),
						'engineFailed'  => __( 'The service could not translate this. Try again later.', 'lango7' ),
						'edited'        => __( 'Edited', 'lango7' ),
						'untranslated'  => __( 'Not translated', 'lango7' ),
						'failed'        => __( 'Failed', 'lango7' ),
						'chrome'        => __( 'Chrome', 'lango7' ),
						'edit'          => __( 'Edit', 'lango7' ),
						'empty'         => __( 'Empty', 'lango7' ),
						'save'          => __( 'Save', 'lango7' ),
						'cancel'        => __( 'Cancel', 'lango7' ),
						'onPage'        => __( 'Edit on page', 'lango7' ),
						'none'          => __( 'Nothing matches.', 'lango7' ),
						'loading'       => __( 'Loading…', 'lango7' ),
						/* translators: %d: number of strings. */
						'items'         => _n_noop( '%d string', '%d strings', 'lango7' ),
						/* translators: 1: page number, 2: number of pages. */
						'pageOf'        => __( 'Page %1$d of %2$d', 'lango7' ),
						'kindAttr'      => __( 'Attribute', 'lango7' ),
						'kindMeta'      => __( 'SEO', 'lango7' ),
						'kindTitle'     => __( 'Title', 'lango7' ),
						/* translators: %s: the marked pieces, e.g. "[1 1]". */
						'missing'       => __( 'Keep every marked piece (links, formatting, numbers) exactly once. Check: %s', 'lango7' ),
						'emptyText'     => __( 'The translation cannot be empty.', 'lango7' ),
						'emptySaved'    => __( 'Saved empty translation', 'lango7' ),
						'savedEdit'     => __( 'Saved. Your wording stays until you edit or translate this row again.', 'lango7' ),
						/* translators: %d: number of strings. */
						'resetServer'   => _n_noop( '%d translation cleared. It translates again as visitors and the warm-up reach it.', '%d translations cleared. They translate again as visitors and the warm-up reach them.', 'lango7' ),
						/* translators: %d: number of strings. */
						'resetChrome'   => _n_noop( '%d translation cleared. Run Free Translate to translate it again.', '%d translations cleared. Run Free Translate to translate them again.', 'lango7' ),
						'openChrome'    => __( 'Open Free Translate', 'lango7' ),
						'resetNone'     => __( 'Nothing was cleared. Edited strings keep their wording in bulk; use Translate Now on a row to replace one.', 'lango7' ),
						/* translators: 1: number of strings, 2: number of days. */
						'orphans'       => _n_noop( '%1$d string has not been seen on any page for %2$d days (the text was changed or removed).', '%1$d strings have not been seen on any page for %2$d days (the text was changed or removed).', 'lango7' ),
						'deleteOrphans' => __( 'Delete them', 'lango7' ),
						/* translators: %d: number of strings. */
						'orphansGone'   => _n_noop( '%d unused string deleted.', '%d unused strings deleted.', 'lango7' ),
						'confirmOrphans' => __( 'Delete these strings and their translations, edits included? Text that shows up on a page again is picked up again.', 'lango7' ),
						'error'         => __( 'Something went wrong.', 'lango7' ),
						'chipPair'      => __( 'Link or formatting: keep it around the same words', 'lango7' ),
						'chipVoid'      => __( 'Image, icon or other element: keep it', 'lango7' ),
						'chipNumber'    => __( 'Number or protected term: filled in automatically', 'lango7' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public function render(): void {
		$enabled = $this->languages->enabled();
		?>
		<div class="wrap lango7-wrap lango7-strings">
			<?php PageShell::header( __( 'Translations', 'lango7' ) ); ?>
			<p class="lango7-lead"><?php esc_html_e( 'Every piece of text Lango7 has found on your pages, with its translation. Fix a translation here, or on the page itself with Edit translations in the toolbar.', 'lango7' ); ?></p>
			<?php PageShell::nav( 'lango7-strings' ); ?>

			<?php if ( ! $enabled ) : ?>
				<div class="lango7-card">
					<p>
						<?php esc_html_e( 'Add a language first.', 'lango7' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . ChromePage::SLUG ) ); ?>"><?php esc_html_e( 'Free Translate', 'lango7' ); ?></a>
					</p>
				</div>
			<?php else : ?>
				<div class="lango7-str-toolbar">
					<?php if ( count( $enabled ) > 1 ) : ?>
						<label class="screen-reader-text" for="lango7-str-lang"><?php esc_html_e( 'Language', 'lango7' ); ?></label>
						<select id="lango7-str-lang" class="screen-reader-text" aria-label="Language">
							<?php
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only preselects a filter.
							$lango7_pick = sanitize_text_field( wp_unslash( $_GET['lang'] ?? '' ) );
							foreach ( $enabled as $l ) :
								?>
								<option value="<?php echo esc_attr( $l->code ); ?>" <?php selected( $l->code, $lango7_pick ); ?>><?php echo esc_html( $l->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<div class="lango7-language-buttons" role="group" aria-label="<?php esc_attr_e( 'Translation language', 'lango7' ); ?>">
							<?php foreach ( $enabled as $index => $l ) : ?>
								<button type="button" class="button" data-lango7-language="<?php echo esc_attr( $l->code ); ?>" aria-pressed="<?php echo esc_attr( ( $lango7_pick ? $lango7_pick === $l->code : 0 === $index ) ? 'true' : 'false' ); ?>"><?php echo esc_html( $l->name ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<input type="hidden" id="lango7-str-lang" value="<?php echo esc_attr( $enabled[0]->code ); ?>">
					<?php endif; ?>
				</div>
				<div class="lango7-str-toolbar lango7-str-filters">
					<ul class="subsubsub" id="lango7-str-tabs">
						<?php
						$tabs = array(
							''             => __( 'All', 'lango7' ),
							'machine'      => __( 'Machine', 'lango7' ),
							'unapproved'   => __( 'Approvals', 'lango7' ),
							'edited'       => __( 'Edited', 'lango7' ),
							'untranslated' => __( 'Not Translated', 'lango7' ),
							'failed'       => __( 'Failed', 'lango7' ),
						);
						foreach ( $tabs as $key => $label ) :
							?>
							<li<?php echo 'unapproved' === $key ? ' hidden' : ''; ?>><a href="#" data-status="<?php echo esc_attr( $key ); ?>"<?php echo '' === $key ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count"></span></a></li>
						<?php endforeach; ?>
					</ul>
					<label class="screen-reader-text" for="lango7-str-engine"><?php esc_html_e( 'Translated by', 'lango7' ); ?></label>
					<select id="lango7-str-engine">
						<option value=""><?php esc_html_e( 'All services', 'lango7' ); ?></option>
						<option value="manual"><?php esc_html_e( 'Edited by hand', 'lango7' ); ?></option>
					</select>
					<p class="search-box">
						<label class="screen-reader-text" for="lango7-str-q"><?php esc_html_e( 'Search strings', 'lango7' ); ?></label>
						<input type="search" id="lango7-str-q" placeholder="<?php esc_attr_e( 'Search original or translation', 'lango7' ); ?>">
					</p>
				</div>

				<div class="tablenav top">
					<div class="alignleft actions">
						<button type="button" class="button" id="lango7-str-redo" disabled><?php esc_html_e( 'Translate Selected', 'lango7' ); ?></button>
						<button type="button" class="button" id="lango7-str-approve" hidden disabled><?php esc_html_e( 'Approve selected', 'lango7' ); ?></button>
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num" id="lango7-str-total"></span>
						<span class="pagination-links">
							<button type="button" class="button" id="lango7-str-prev" aria-label="<?php esc_attr_e( 'Previous page', 'lango7' ); ?>">‹</button>
							<span class="paging-input" id="lango7-str-page"></span>
							<button type="button" class="button" id="lango7-str-next" aria-label="<?php esc_attr_e( 'Next page', 'lango7' ); ?>">›</button>
						</span>
					</div>
				</div>
				<div id="lango7-str-result" class="lango7-result" aria-live="polite"></div>

				<table class="widefat striped lango7-str-table">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="lango7-str-all" aria-label="<?php esc_attr_e( 'Select all', 'lango7' ); ?>"></td>
							<th scope="col" class="lango7-str-src"><?php esc_html_e( 'Original', 'lango7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Translation', 'lango7' ); ?></th>
						<th scope="col" class="lango7-str-engine"><?php esc_html_e( 'Engine', 'lango7' ); ?></th>
						<th scope="col" class="lango7-str-action"><?php esc_html_e( 'Translate Now', 'lango7' ); ?></th>
							<th scope="col" class="lango7-str-status"><?php esc_html_e( 'Status', 'lango7' ); ?></th>
						</tr>
					</thead>
					<tbody id="lango7-str-rows"></tbody>
				</table>

				<p id="lango7-str-orphans" class="lango7-str-orphans" hidden></p>
				<?php
				/**
				 * Below the Translations table (Pro: translation history settings).
				 */
				do_action( 'lango7_strings_page_after' );
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Language code => label of the server engine that translates it ('' for Free Translate).
	 *
	 * @return array<string, string>
	 */
	private function engineLabels(): array {
		$out = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$id                 = $this->engines->serviceFor( $lang );
			$out[ $lang->code ] = 'chrome' === $id ? '' : $this->engines->serviceLabel( $id );
		}
		return $out;
	}
}
