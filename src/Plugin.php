<?php
/**
 * Service container and hooks.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

use Lango7\Admin\ChromePage;
use Lango7\Admin\DashboardPage;
use Lango7\Admin\GlossaryPage;
use Lango7\Admin\StringsPage;
use Lango7\Admin\SettingsPage;
use Lango7\Buffer\OutputBuffer;
use Lango7\Editor\EditMode;
use Lango7\Engine\EngineRegistry;
use Lango7\Queue\Jobs;
use Lango7\Rest\AdminController;
use Lango7\Rest\ChromeController;
use Lango7\Rest\StringsController;
use Lango7\Rest\TranslationsController;
use Lango7\Router\LanguageRouter;
use Lango7\Security\KeyVault;
use Lango7\Security\SpendCap;
use Lango7\Seo\Hreflang;
use Lango7\Seo\Sitemap;
use Lango7\Store\Schema;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;
use Lango7\Switcher\NavMenu;
use Lango7\Switcher\Switcher;
use Lango7\Translation\Glossary;
use Lango7\Translation\Pipeline;
use Lango7\Translation\Translator;

/**
 * Services are built on first use, so a source-language page view constructs
 * only the router and the hreflang printer.
 */
final class Plugin {

	/**
	 * Version for a bundled file's URL: the plugin version plus the file's
	 * modified time, so browsers fetch a changed file even within one release.
	 */
	public static function assetVersion( string $relative ): string {
		$time = @filemtime( LANGO7_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just keeps the plain version.
		return LANGO7_VERSION . ( $time ? '.' . $time : '' );
	}


	public const CAPABILITY = 'lango7_edit_translations';

	/** Meta capability: current_user_can( LANGUAGE_CAPABILITY, 'ms' ). */
	public const LANGUAGE_CAPABILITY = 'lango7_edit_language';

	private static ?self $instance = null;

	/** @var array<string, object> */
	private array $services = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		Schema::maybeUpgrade();
		$this->router()->detect();

		add_action( 'template_redirect', fn() => $this->buffer()->maybeStart(), PHP_INT_MAX - 10 );
		add_action( 'wp_head', fn() => $this->hreflang()->render(), 1 );
		add_action( 'wp_head', fn() => Translation\TextDirection::render( $this->router()->current() ), 2 );
		add_action( 'wp_sitemaps_init', fn( \WP_Sitemaps $s ) => Sitemap::register( $s, $this->languages(), $this->router(), $this->settings() ) );
		add_action( Jobs::HOOK, fn() => $this->jobs()->run() );
		add_action(
			'rest_api_init',
			function (): void {
				$this->rest()->register();
				$this->chromeRest()->register();
				( new StringsController( $this->languages(), $this->router(), $this->strings(), $this->translations(), fn() => $this->translator() ) )->register();
				( new TranslationsController( $this->languages(), $this->router(), $this->strings(), $this->translations(), $this->translator(), $this->engines() ) )->register();
				( new Tools\CostEstimator( $this ) )->routes();
				$this->editorPanel()->routes();
				$this->approval()->routes();
			}
		);
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'map_meta_cap', array( $this, 'mapCaps' ), 10, 4 );
		if ( null !== $this->router()->current() ) {
			$this->editMode()->register();
		}

		( new Admin\UntranslatedReport( $this ) )->register();
		( new Admin\RetranslateTool( $this ) )->register();
		Exclusions::register();
		if ( is_admin() ) {
			Admin\Privacy::register();
			Admin\Footer::register();
			Admin\DebugTab::register();
			Admin\SwitcherTab::register();
			Admin\News::register();
			( new Admin\PostsColumn( $this->languages(), $this->router(), $this->settings() ) )->register();
			$this->editorPanel()->register();
			$this->approval()->register();
			Admin\ActivityPage::registerActions();
			add_action(
				'admin_menu',
				function (): void {
					( new DashboardPage( $this->languages(), $this->engines(), $this->rest(), $this->glossary(), $this->setup(), new Admin\News( $this->settings() ) ) )->register();
					( new ChromePage( $this->languages(), $this->strings(), $this->translations() ) )->register();
					( new StringsPage( $this->languages(), $this->engines() ) )->register( DashboardPage::SLUG );
					( new Admin\ActivityPage( $this->languages(), $this->engines(), $this->router() ) )->register( DashboardPage::SLUG );
					( new GlossaryPage( $this->glossary() ) )->register( DashboardPage::SLUG );
					( new Admin\ToolsPage( $this ) )->register( DashboardPage::SLUG );
					$this->settingsPage()->register();
				}
			);
			add_action( 'admin_init', array( $this, 'activationRedirect' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( LANGO7_FILE ), array( $this, 'actionLinks' ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Command::register( $this );
		}

		do_action( 'lango7_loaded', $this );
	}

	public function init(): void {
		add_shortcode( 'lango7_switcher', fn( $atts ) => $this->switcher()->render( (array) $atts ) );
		( new Site\LanguageContent( $this->languages(), $this->router() ) )->register();
		( new Admin\PendingBar( $this->languages(), $this->router(), $this->settings(), $this->strings() ) )->register();
		$this->setup()->register();
		register_block_type( LANGO7_DIR . 'blocks/switcher' );
		( new NavMenu( $this->languages(), $this->router() ) )->register();
		// Bricks loads its own elements on init (10); custom ones register after.
		add_action(
			'init',
			static function (): void {
				if ( class_exists( '\\Bricks\\Elements' ) ) {
					\Bricks\Elements::register_element( LANGO7_DIR . 'integrations/bricks/switcher-element.php', '', Integrations\Bricks\Switcher_Element::class );
				}
			},
			11
		);
	}

	/**
	 * Editing one language needs the translation capability; extensions can
	 * narrow it per user (Pro's per-language translators).
	 *
	 * @param string[] $caps
	 * @param mixed[]  $args
	 * @return string[]
	 */
	public function mapCaps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( self::LANGUAGE_CAPABILITY !== $cap ) {
			return $caps;
		}
		/**
		 * The capabilities a user needs to edit one language.
		 *
		 * @param string[] $caps
		 * @param int      $user_id
		 * @param string   $lang
		 */
		return (array) apply_filters( 'lango7_language_caps', array( self::CAPABILITY ), $user_id, (string) ( $args[0] ?? '' ) );
	}

	public static function activate(): void {
		Schema::install();
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			get_role( $role_name )?->add_cap( self::CAPABILITY );
		}
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		// Reopen setup on activation if no target language is configured.
		delete_option( \Lango7\Admin\Setup::OPTION );
		set_transient( 'lango7_activation_redirect', 1, 60 );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Jobs::HOOK );
	}

	/**
	 * Open the settings screen once after activation (not on bulk activation).
	 */
	public function activationRedirect(): void {
		if ( ! get_transient( 'lango7_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'lango7_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own flag.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( DashboardPage::url() );
		exit;
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function actionLinks( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">' . esc_html__( 'Settings', 'lango7' ) . '</a>' );
		return $links;
	}

	public function settings(): Settings {
		return $this->get( Settings::class, fn() => new Settings() );
	}

	public function languages(): Languages {
		return $this->get( Languages::class, fn() => new Languages( $this->settings() ) );
	}

	public function router(): LanguageRouter {
		return $this->get( LanguageRouter::class, fn() => new LanguageRouter( $this->languages() ) );
	}

	public function vault(): KeyVault {
		return $this->get( KeyVault::class, fn() => new KeyVault() );
	}

	public function engines(): EngineRegistry {
		return $this->get( EngineRegistry::class, fn() => new EngineRegistry() );
	}

	public function cap(): SpendCap {
		return $this->get( SpendCap::class, fn() => new SpendCap( $this->settings() ) );
	}

	public function strings(): StringRepository {
		return $this->get( StringRepository::class, fn() => new StringRepository() );
	}

	public function translations(): TranslationRepository {
		return $this->get( TranslationRepository::class, fn() => new TranslationRepository() );
	}

	public function glossary(): Glossary {
		return $this->get( Glossary::class, fn() => new Glossary() );
	}

	public function translator(): Translator {
		return $this->get(
			Translator::class,
			fn() => new Translator( $this->engines(), $this->translations(), $this->cap(), $this->glossary(), $this->languages() )
		);
	}

	public function redo(): Store\RedoList {
		return $this->get( Store\RedoList::class, fn() => new Store\RedoList() );
	}

	public function jobs(): Jobs {
		return $this->get( Jobs::class, fn() => new Jobs( $this->languages(), $this->strings(), $this->translator(), $this->router(), $this->redo(), $this->translations() ) );
	}

	public function pipeline(): Pipeline {
		return $this->get(
			Pipeline::class,
			fn() => new Pipeline( $this->settings(), $this->languages(), $this->router(), $this->strings(), $this->translations(), $this->translator(), $this->jobs() )
		);
	}

	public function buffer(): OutputBuffer {
		return $this->get( OutputBuffer::class, fn() => new OutputBuffer( $this->router(), $this->pipeline(), $this->settings() ) );
	}

	public function hreflang(): Hreflang {
		return $this->get( Hreflang::class, fn() => new Hreflang( $this->languages(), $this->router(), $this->settings() ) );
	}

	public function switcher(): Switcher {
		return $this->get( Switcher::class, fn() => new Switcher( $this->languages(), $this->router() ) );
	}

	public function approval(): Translation\Approval {
		return $this->get( Translation\Approval::class, fn() => new Translation\Approval( $this->languages(), $this->router(), $this->strings(), $this->translations() ) );
	}

	public function editorPanel(): Admin\EditorPanel {
		return $this->get( Admin\EditorPanel::class, fn() => new Admin\EditorPanel( $this->languages(), $this->router(), $this->settings() ) );
	}

	public function rest(): AdminController {
		return $this->get(
			AdminController::class,
			fn() => new AdminController( $this->settings(), $this->languages(), $this->vault(), $this->engines(), $this->cap(), $this->strings(), $this->translations(), $this->jobs(), $this->router() )
		);
	}

	public function editMode(): EditMode {
		return $this->get( EditMode::class, fn() => new EditMode( $this->languages(), $this->router(), $this->translations(), $this->engines() ) );
	}

	public function chromeRest(): ChromeController {
		return $this->get(
			ChromeController::class,
			fn() => new ChromeController( $this->settings(), $this->languages(), $this->router(), $this->strings(), $this->translations(), $this->glossary(), $this->redo() )
		);
	}


	public function setup(): Admin\Setup {
		return $this->get( Admin\Setup::class, fn() => new Admin\Setup( $this->languages(), $this->engines() ) );
	}

	public function settingsPage(): SettingsPage {
		return $this->get( SettingsPage::class, fn() => new SettingsPage( $this->settings(), $this->languages(), $this->engines(), $this->rest() ) );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @param callable(): T   $factory
	 * @return T
	 */
	private function get( string $id, callable $factory ): object {
		return $this->services[ $id ] ??= $factory();
	}
}
