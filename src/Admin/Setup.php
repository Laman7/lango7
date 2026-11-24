<?php
/**
 * First-run setup on the Translate dashboard (and again from Settings): languages,
 * how to translate, the API key, when to translate. Pro adds the services.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Admin;

use Lango7\Engine\EngineRegistry;
use Lango7\Languages;
use Lango7\Rest\AdminController;
use WP_REST_Request;
use WP_REST_Response;

final class Setup {

	public const OPTION = 'lango7_setup';

	private static bool $showing = false;

	public function __construct( private Languages $languages, private EngineRegistry $engines ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'removable_query_args', static fn( array $args ): array => array_merge( $args, array( 'lango7_welcome' ) ) );
	}

	/** Show setup while no target language is configured, unless deferred. */
	public function needed(): bool {
		return ! $this->languages->enabled() && 'skipped' !== get_option( self::OPTION, false );
	}

	/** Whether the setup is on screen now (other notices and dialogs stay out of its way). */
	public static function showing(): bool {
		return self::$showing;
	}

	/** Setup again, from Settings. */
	public static function url(): string {
		return add_query_arg( 'setup', '1', DashboardPage::url() );
	}

	public function wanted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only chooses what to show.
		return $this->needed() || ! empty( $_GET['setup'] );
	}

	public function routes(): void {
		register_rest_route(
			AdminController::NS,
			'/setup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'state'  => array(
						'type'    => 'string',
						'enum'    => array( 'done', 'skipped' ),
						'default' => 'done',
					),
					'how'    => array(
						'type'    => 'string',
						'enum'    => array( 'free', 'service' ),
						'default' => 'free',
					),
					'engine' => array(
						'type'    => 'string',
						'default' => '',
					),
					'mode'   => array(
						'type'    => 'string',
						'enum'    => array( 'manual', 'auto' ),
						'default' => 'manual',
					),
				),
			)
		);
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$state = (string) $request['state'];
		update_option( self::OPTION, $state, false );
		if ( 'done' === $state ) {
			$how    = (string) $request['how'];
			$engine = sanitize_key( (string) $request['engine'] );
			/**
			 * Setup finished: how to translate ('free' or 'service'), the service, and when ('manual' or 'auto').
			 *
			 * @param string $how
			 * @param string $engine Engine id, '' for Free Translate.
			 * @param string $mode
			 */
			do_action( 'lango7_setup_saved', $how, 'service' === $how ? $engine : '', (string) $request['mode'] );
		}
		return new WP_REST_Response(
			array(
				'state'    => $state,
				'redirect' => 'done' === $state ? add_query_arg( 'lango7_welcome', '1', DashboardPage::url() ) : DashboardPage::url(),
			)
		);
	}

	/** A pointer to the setup on the WordPress dashboard and the Plugins screen. */
	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! current_user_can( 'manage_options' ) || ! $this->needed() ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Lango7 is installed.', 'lango7' ) . '</strong> ' . esc_html__( 'Choose your languages and how to translate them. It takes about two minutes.', 'lango7' ) . ' <a class="button button-primary" href="' . esc_url( DashboardPage::url() ) . '">' . esc_html__( 'Start setup', 'lango7' ) . '</a></p></div>';
	}

	/**
	 * Services Pro offers in setup: id => label, placeholder, help, saved key mask.
	 *
	 * @return array<string, array{label: string, placeholder: string, help: string, mask: string}>
	 */
	private function services(): array {
		/**
		 * Translation services offered in setup.
		 *
		 * @param array<string, array{label: string, placeholder: string, help: string, mask: string}> $services
		 */
		return (array) apply_filters( 'lango7_setup_services', array() );
	}

	/** @return array<string, mixed> Script settings. */
	public function config(): array {
		$catalog = Languages::catalog();
		$chosen  = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$chosen[] = array(
				'code'   => $lang->code,
				'prefix' => $lang->prefix,
			);
		}
		$langs = array();
		foreach ( Languages::available() as $code => $c ) {
			$langs[ $code ] = array(
				'name'   => $c['name'] . ( $c['native'] !== $c['name'] ? ' — ' . $c['native'] : '' ),
				'prefix' => $c['prefix'],
				'chrome' => '' !== $c['chrome'],
			);
		}
		$source = $this->languages->sourceCode();

		/**
		 * The mode setup starts on for a service ('manual' or 'auto'), e.g. the one chosen before.
		 *
		 * @param string $mode
		 */
		$mode = (string) apply_filters( 'lango7_setup_mode', 'manual' );
		return array(
			'home'      => home_url( '/' ),
			'langs'     => $langs,
			'chosen'    => $chosen,
			'services'  => $this->services(),
			'current'   => $this->engines->hasServerEngine() ? 'service' : 'free',
			'engine'    => (string) apply_filters( 'lango7_setup_engine', '' ),
			'mode'      => in_array( $mode, array( 'manual', 'auto' ), true ) ? $mode : 'manual',
			'catalog'   => count( $catalog ),
			'i18n'      => array(
				'saving'   => __( 'Saving…', 'lango7' ),
				'checking' => __( 'Checking the key…', 'lango7' ),
				/* translators: 1: "Hello", 2: its translation. */
				'works'    => __( 'The key works: “%1$s” → “%2$s”.', 'lango7' ),
				'needKey'  => __( 'Paste your API key, then check it.', 'lango7' ),
				'needLang' => __( 'Choose a language to translate into and click “Add language”.', 'lango7' ),
                'chooseLanguage' => __( 'Choose a language', 'lango7' ),
				'remove'   => __( 'Remove', 'lango7' ),
				/* translators: %s: language names. */
				'noChrome' => __( 'Free Translate can\'t translate %s. Choose a translation service, or remove it.', 'lango7' ),
				/* translators: 1: service name, 2: language names. */
				'cantInto' => __( '%1$s can\'t translate %2$s. Choose another service, or go back and remove it.', 'lango7' ),
				/* translators: 1: service name, 2: site language name. */
				'cantFrom' => __( '%1$s can\'t translate from %2$s, your site language. Choose another service.', 'lango7' ),
				'error'    => __( 'Something went wrong.', 'lango7' ),
				/* translators: %s: masked key. */
				'saved'    => __( 'A key is saved (%s). Leave this empty to keep it.', 'lango7' ),
			),
		);
	}

	public function render(): void {
		self::$showing = true;
		$source   = $this->languages->sourceCode();
		$services = $this->services();
		$pro      = (bool) $services;
		$missing  = count( Languages::catalog() ) - count( Languages::available() );
		$again    = ! $this->needed();
		?>
		<h2 class="lango7-setup-title"><?php echo esc_html( $again ? __( 'Set up Lango7', 'lango7' ) : __( 'Welcome to Lango7', 'lango7' ) ); ?></h2>
		<p class="lango7-lead"><?php echo esc_html( $again ? __( 'Change your languages and how they are translated. Your translations and other settings stay as they are.', 'lango7' ) : __( 'A few questions, then your site has a translated version with its own addresses that search engines can index.', 'lango7' ) ); ?></p>

		<div class="lango7-card lango7-setup-wizard" id="lango7-setup" data-lango7-ui data-finish="<?php esc_attr_e( 'Finish setup', 'lango7' ); ?>">
			<ol class="lango7-setup-steps" aria-label="<?php esc_attr_e( 'Setup steps', 'lango7' ); ?>">
				<li data-for="langs"><?php esc_html_e( 'Languages', 'lango7' ); ?></li>
				<li data-for="how"><?php esc_html_e( 'How to translate', 'lango7' ); ?></li>
				<?php if ( $pro ) : ?>
					<li data-for="key" data-service><?php esc_html_e( 'API key', 'lango7' ); ?></li>
					<li data-for="mode" data-service><?php esc_html_e( 'When to translate', 'lango7' ); ?></li>
				<?php endif; ?>
			</ol>

			<section data-step="langs">
				<h2><?php esc_html_e( 'Which languages?', 'lango7' ); ?></h2>
				<p>
					<label for="lango7-setup-source"><?php esc_html_e( 'Your site is written in', 'lango7' ); ?></label><br>
					<select id="lango7-setup-source">
						<?php foreach ( $this->sourceChoices( $source ) as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $source ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="lango7-setup-label"><?php esc_html_e( 'Translate it into these languages', 'lango7' ); ?></p>
				<ul class="lango7-setup-langs" id="lango7-setup-chosen"></ul>
				<p>
					<label class="screen-reader-text" for="lango7-setup-add"><?php esc_html_e( 'Language', 'lango7' ); ?></label>
					<select id="lango7-setup-add"></select>
					<button type="button" class="button" id="lango7-setup-add-button"><?php esc_html_e( 'Add language', 'lango7' ); ?></button>
				</p>
				<?php if ( $missing > 0 && ! $pro ) : ?>
					<p class="description">
						<?php
						/* translators: %d: number of languages. */
						echo esc_html( sprintf( __( 'Free Translate works with the languages Chrome\'s built-in translator supports. Lango7 Pro adds translation services for %d more languages, such as Malay.', 'lango7' ), $missing ) );
						?>
					</p>
				<?php endif; ?>
			</section>

			<section data-step="how" hidden>
				<h2><?php esc_html_e( 'How should your pages be translated?', 'lango7' ); ?></h2>
				<label class="lango7-setup-option<?php echo $pro ? '' : ' is-only'; ?>">
					<input type="radio" name="lango7-setup-how" value="free"<?php echo $pro ? '' : ' checked hidden'; ?>>
					<span class="lango7-setup-name"><?php esc_html_e( 'Free Translate', 'lango7' ); ?> <span class="lango7-setup-tag"><?php esc_html_e( 'Free', 'lango7' ); ?></span></span>
					<span class="lango7-setup-help"><?php esc_html_e( 'Google Chrome on your computer translates your pages, with no API key and no cost. You start it from Translate → Free Translate whenever you add content; it runs while that tab is open. Visitors in any browser get the saved translations.', 'lango7' ); ?></span>
				</label>
				<?php if ( $pro ) : ?>
					<label class="lango7-setup-option">
						<input type="radio" name="lango7-setup-how" value="service">
						<span class="lango7-setup-name"><?php esc_html_e( 'A translation service', 'lango7' ); ?></span>
						<span class="lango7-setup-help"><?php esc_html_e( 'DeepL, Google Translate, OpenAI, Claude or Gemini, with your own API key. Pages translate on the server without your browser, and you pay the service for what is translated.', 'lango7' ); ?></span>
					</label>
				<?php else : ?>
					<p class="lango7-setup-more">
						<?php esc_html_e( 'Lango7 Pro adds translation services (DeepL, Google Translate, OpenAI, Claude or Gemini) that translate on the server with your own API key.', 'lango7' ); ?>
						<a href="<?php echo esc_url( (string) apply_filters( 'lango7_pro_url', 'https://lango7.com/pro/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'About Lango7 Pro', 'lango7' ); ?></a>
					</p>
				<?php endif; ?>
				<p class="lango7-setup-warn" id="lango7-setup-chrome-warn" hidden></p>
			</section>

			<?php if ( $pro ) : ?>
				<section data-step="key" hidden>
					<h2><?php esc_html_e( 'Which service, and your API key', 'lango7' ); ?></h2>
					<fieldset class="lango7-setup-services">
						<legend class="screen-reader-text"><?php esc_html_e( 'Service', 'lango7' ); ?></legend>
						<?php foreach ( $services as $id => $s ) : ?>
							<label class="lango7-setup-service"><input type="radio" name="lango7-setup-engine" value="<?php echo esc_attr( $id ); ?>"> <?php echo esc_html( $s['label'] ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p>
						<label for="lango7-setup-key"><?php esc_html_e( 'API key', 'lango7' ); ?></label><br>
						<input type="password" id="lango7-setup-key" class="regular-text" spellcheck="false" autocomplete="off">
						<button type="button" class="button" id="lango7-setup-check"><?php esc_html_e( 'Check key', 'lango7' ); ?></button>
					</p>
					<p class="description" id="lango7-setup-key-help"></p>
					<p class="description" id="lango7-setup-key-saved" hidden></p>
					<p class="lango7-result" id="lango7-setup-key-result" aria-live="polite"></p>
					<p class="lango7-setup-warn" id="lango7-setup-service-warn" hidden></p>
					<p class="description"><?php esc_html_e( 'Checking sends the word “Hello” to the service. An OpenAI-compatible server, a model or a different service per language can be set in Settings later.', 'lango7' ); ?></p>
				</section>

				<section data-step="mode" hidden>
					<h2><?php esc_html_e( 'When should pages be translated?', 'lango7' ); ?></h2>
					<label class="lango7-setup-option">
						<input type="radio" name="lango7-setup-mode" value="manual">
						<span class="lango7-setup-name"><?php esc_html_e( 'Only when I choose', 'lango7' ); ?> <span class="lango7-setup-tag"><?php esc_html_e( 'Recommended', 'lango7' ); ?></span></span>
						<span class="lango7-setup-help"><?php esc_html_e( 'Nothing is sent to the service until you say so: Translate now in the post list or the admin bar, or Bulk translate, which shows the cost first. Pages not translated yet show in your own language.', 'lango7' ); ?></span>
					</label>
					<label class="lango7-setup-option">
						<input type="radio" name="lango7-setup-mode" value="auto">
						<span class="lango7-setup-name"><?php esc_html_e( 'Automatically', 'lango7' ); ?></span>
						<span class="lango7-setup-help"><?php esc_html_e( 'Every page is translated the first time someone opens it in another language, and new or updated posts when you publish them. The daily character limit in Settings still applies.', 'lango7' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'You can change this any time in Settings → Automation, down to each post type.', 'lango7' ); ?></p>
				</section>
			<?php endif; ?>

			<p class="lango7-result" id="lango7-setup-result" aria-live="polite"></p>
			<div class="lango7-setup-actions">
				<button type="button" class="button-link" id="lango7-setup-later"><?php esc_html_e( 'Finish later', 'lango7' ); ?></button>
				<span>
					<button type="button" class="button" id="lango7-setup-back" hidden><?php esc_html_e( 'Back', 'lango7' ); ?></button>
					<button type="button" class="button button-primary" id="lango7-setup-next"><?php esc_html_e( 'Continue', 'lango7' ); ?></button>
				</span>
			</div>
		</div>
		<?php
	}

	/** First screen after setup: one clear thing to do. */
	public function welcome(): void {
		/**
		 * The first action after setup: url, label and a note.
		 *
		 * @param array{url: string, label: string, note: string} $action
		 */
		$action = (array) apply_filters(
			'lango7_setup_first_action',
			array(
				'url'   => add_query_arg( 'path', rawurlencode( '/' ), admin_url( 'admin.php?page=' . ChromePage::SLUG ) ),
				'label' => __( 'Translate your homepage now', 'lango7' ),
				'note'  => __( 'Free Translate opens with just your homepage selected. Keep the tab open while it works.', 'lango7' ),
			)
		);
		?>
		<div class="lango7-card lango7-welcome" id="lango7-welcome">
			<span class="lango7-eyebrow"><?php esc_html_e( 'You are set up', 'lango7' ); ?></span>
			<h2><?php esc_html_e( 'Start with your homepage', 'lango7' ); ?></h2>
			<p><?php echo esc_html( (string) ( $action['note'] ?? '' ) ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( (string) $action['url'] ); ?>"><?php echo esc_html( (string) $action['label'] ); ?></a></p>
			<p class="description"><?php esc_html_e( 'Next, add a language switcher so visitors can find the translation (see Language switcher below), and set up the rest any time in Settings.', 'lango7' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The site language may be one Chrome cannot translate into; it still has to be listed.
	 *
	 * @return array<string, string>
	 */
	private function sourceChoices( string $source ): array {
		$out = array();
		foreach ( Languages::available() as $code => $c ) {
			$out[ $code ] = $c['name'];
		}
		if ( ! isset( $out[ $source ] ) && isset( Languages::catalog()[ $source ] ) ) {
			$out = array( $source => Languages::catalog()[ $source ]['name'] ) + $out;
		}
		return $out;
	}
}
