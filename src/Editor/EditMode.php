<?php
/**
 * Front-end editor: edit mode on translated pages.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Editor;

use Lango7\Buffer\OutputBuffer;
use Lango7\Engine\EngineRegistry;
use Lango7\Html\Applier;
use Lango7\Html\Node;
use Lango7\Html\Occurrence;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Plugin;
use Lango7\Rest\AdminController;
use Lango7\Router\LanguageRouter;
use Lango7\Store\TranslationRepository;
use Lango7\Translation\Approval;

/**
 * "Edit translations" in the admin bar reloads a translated page with
 * ?lango7_edit=1 and a nonce. In that mode the page is never cached, every
 * text segment is marked (data-lango7 on its element when it is the element's
 * whole text, else a <lango7-seg> wrapper styled display: contents), and a JSON
 * map of the page's segments is printed for assets/editor/editor.js. Visitors
 * never get any of it.
 */
final class EditMode {

	public const PARAM = 'lango7_edit';

	private const NONCE = 'lango7_edit';

	/**
	 * A translated page's URL that opens it in edit mode (for the current user).
	 */
	public static function link( string $url ): string {
		return add_query_arg(
			array(
				self::PARAM => '1',
				'_wpnonce'  => wp_create_nonce( self::NONCE ),
			),
			$url
		);
	}

	/** @var array<int, array<string, mixed>> Occurrence data, indexed by data-lango7-i. */
	private array $occurrences = array();

	public function __construct( private Languages $languages, private LanguageRouter $router, private TranslationRepository $translations, private ?EngineRegistry $engines = null ) {}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'adminBar' ), 80 );
        add_action( 'wp_enqueue_scripts', array( $this, 'tourAssets' ) );
		add_action( 'template_redirect', array( $this, 'maybeStart' ), PHP_INT_MAX - 11 );
	}

    public function tourAssets(): void {
        // The tour is shown only to an editor on a translated front-end page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tour state.
        $step = isset( $_GET['lango7_tour'] ) ? sanitize_key( wp_unslash( (string) $_GET['lango7_tour'] ) ) : '';
        if ( ! $this->canEdit() || ! in_array( $step, array( '1', '2' ), true ) ) {
            return;
        }
        wp_enqueue_style( 'lango7-tour', LANGO7_URL . 'assets/editor/tour.css', array(), (string) filemtime( LANGO7_DIR . 'assets/editor/tour.css' ) );
        wp_enqueue_script( 'lango7-tour', LANGO7_URL . 'assets/editor/tour.js', array(), (string) filemtime( LANGO7_DIR . 'assets/editor/tour.js' ), true );
        wp_add_inline_script( 'lango7-tour', 'window.lango7Tour = ' . wp_json_encode( array(
            'step' => $step,
            'first' => __( 'Review your translation. Select “Edit translations” in the admin bar to start editing this page.', 'lango7' ),
            'second' => __( 'Edit mode is on. Click any highlighted text to change its translation.', 'lango7' ),
            'close' => __( 'Got it', 'lango7' ),
        ) ) . ';', 'before' );
    }

	public function canEdit(): bool {
		$lang = $this->router->current();
		return null !== $lang && current_user_can( Plugin::LANGUAGE_CAPABILITY, $lang->code );
	}

	public function active(): bool {
		return $this->canEdit()
			&& isset( $_GET[ self::PARAM ], $_GET['_wpnonce'] )
			&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), self::NONCE );
	}

	public function adminBar( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! $this->canEdit() ) {
			return;
		}
		$lang   = $this->router->current();
		$url    = $this->router->urlFor( $lang );
		$active = $this->active();
		if ( Approval::can( $lang->code ) ) {
			$path    = $this->router->sourcePath();
			$waiting = count( $this->translations->unapprovedOn( $lang->code, sha1( $path ) ) );
			if ( $waiting ) {
				$bar->add_node(
					array(
						'id'    => 'lango7-approve',
						/* translators: %d: number of sentences. */
						'title' => esc_html( sprintf( _n( 'Approve page (%d)', 'Approve page (%d)', $waiting, 'lango7' ), $waiting ) ),
						'href'  => Approval::pageLink( $lang, $path ),
						'meta'  => array( 'title' => __( 'Show every machine translation on this page to visitors.', 'lango7' ) ),
					)
				);
			}
		}
		$bar->add_node(
			array(
				'id'    => 'lango7-edit',
				'title' => '<span class="ab-icon dashicons dashicons-translation" style="top:2px"></span>' . esc_html( $active ? __( 'Exit edit mode', 'lango7' ) : __( 'Edit translations', 'lango7' ) ),
				'href'  => $active ? $url : wp_nonce_url( add_query_arg( self::PARAM, '1', $url ), self::NONCE ),
			)
		);
		if ( current_user_can( 'manage_options' ) ) {
			$bar->add_node(
				array(
					'id'     => 'lango7-edit-dashboard',
					'parent' => 'lango7-edit',
					'title'  => esc_html__( 'Go to Lango7 - Translate', 'lango7' ),
					'href'   => admin_url( 'admin.php?page=' . \Lango7\Admin\DashboardPage::SLUG ),
				)
			);
		}
	}

	public function maybeStart(): void {
		if ( ! $this->active() ) {
			return;
		}
		OutputBuffer::noCache();
		add_filter( 'lango7_text_marker', array( $this, 'mark' ), 10, 4 );
		add_filter( 'lango7_output', array( $this, 'printData' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets(): void {
		wp_enqueue_script( 'lango7-editor-core', LANGO7_URL . 'assets/editor/editor-core.js', array(), \Lango7\Plugin::assetVersion( 'assets/editor/editor-core.js' ), true );
		wp_enqueue_script( 'lango7-chrome-core', LANGO7_URL . 'assets/admin/chrome-core.js', array(), \Lango7\Plugin::assetVersion( 'assets/admin/chrome-core.js' ), true );
		wp_enqueue_script( 'lango7-editor', LANGO7_URL . 'assets/editor/editor.js', array( 'lango7-editor-core', 'lango7-chrome-core' ), \Lango7\Plugin::assetVersion( 'assets/editor/editor.js' ), true );
	}

	/**
	 * @param array{0: string, 1: string}|null $wrap
	 * @return array{0: string, 1: string}|null
	 */
	public function mark( $wrap, Occurrence $o, Applier $applier, string $html ) {
		$i                       = count( $this->occurrences );
		$this->occurrences[ $i ] = array( 'h' => $o->segment->hash ) + $applier->placeholderMarkup( $o );

		if ( $this->isWholeText( $o, $html ) ) {
			$applier->setAttribute( $o->node, 'data-lango7', $o->segment->hash );
			$applier->setAttribute( $o->node, 'data-lango7-i', (string) $i );
			return null;
		}
		return array( '<lango7-seg data-lango7="' . esc_attr( $o->segment->hash ) . '" data-lango7-i="' . $i . '" style="display:contents">', '</lango7-seg>' );
	}

	/**
	 * The page's segments for the editor, before </body>.
	 *
	 * @param array{texts: Occurrence[], attributes: Occurrence[]} $found
	 * @param array<string, ?string>                               $map
	 */
	public function printData( string $output, Language $lang, array $found, array $map ): string {
		$segments = array();
		foreach ( $found['texts'] as $o ) {
			$segments[ $o->segment->hash ] ??= array(
				's' => $o->segment->text,
				'k' => $o->segment->kind,
			);
		}
		$path   = $this->router->sourcePath();
		$look   = $this->translations->lookup( $lang->code, sha1( $path ), array_keys( $segments ), sha1( $path ) );
		$status = $look['status'];
		foreach ( $segments as $hash => &$s ) {
			$s['t']  = $map[ $hash ] ?? null;
			$s['st'] = $status[ $hash ] ?? 'pending';
			if ( isset( $look['scoped'][ $hash ] ) ) {
				$s['sc'] = 1;
			}
		}
		unset( $s );

		$data = array(
			'rest'     => esc_url_raw( rest_url( AdminController::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'lang'     => $lang->code,
			'langName' => $lang->name,
			'path'     => $path,
			'chrome'   => Languages::chromeCode( $lang->code ),
			'source'   => Languages::chromeCode( $this->languages->sourceCode() ),
			'exit'     => $this->router->urlFor( $lang ),
			'css'      => LANGO7_URL . 'assets/editor/editor.css?ver=' . \Lango7\Plugin::assetVersion( 'assets/editor/editor.css' ),
			'services' => $this->services( $lang ),
			'canApprove' => Approval::can( $lang->code ),
			'segments' => $segments,
			'occ'      => $this->occurrences,
			'i18n'     => $this->strings(),
		);
		/**
		 * Data for the front-end editor. Extensions can add:
		 *
		 * - "rewrite": an AI rewrite row in the edit box: { url, nonce, tones:
		 *   [{id, label}], i18n: {button, custom, preview, working} }; the url gets
		 *   POST {hash, lang, text, tone, custom} and answers { text }.
		 * - "groups": more strings in the Page strings panel, [{label, hashes}],
		 *   whose segments are added to "segments" (same shape).
		 * - "scope": an "Applies to" choice: { url, revert, nonce, i18n: {label,
		 *   all, page} }; saves then go to url with {hash, lang, text, path,
		 *   scope: all|page}, and reverts of this page's edits to revert.
		 * - "review": hash => {source, text}, an earlier edit to offer for reuse,
		 *   with "reviewI18n": {title, reuse}.
		 *
		 * @param array<string, mixed> $data
		 * @param Language             $lang
		 * @param array                $found texts and attributes occurrences.
		 * @param array                $map   hash => translation (null = source).
		 */
		$data   = (array) apply_filters( 'lango7_editor_data', $data, $lang, $found, $map );
		// Built after the page is rendered (output buffer), so it is added with WordPress's tag helper rather than wp_add_inline_script().
		$json   = wp_get_inline_script_tag(
			(string) wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			array(
				'type' => 'application/json',
				'id'   => 'lango7-editor-data',
			)
		);
		$at     = strripos( $output, '</body>' );
		return false === $at ? $output . $json : substr( $output, 0, $at ) . $json . substr( $output, $at );
	}

	/**
	 * Services Translate now can use for this language, the language's own first.
	 *
	 * @return array{default: string, list: array<int, array{id: string, label: string}>}
	 */
	private function services( Language $lang ): array {
		$source = $this->languages->sourceCode();
		if ( null === $this->engines ) {
			$ids = '' !== Languages::chromeCode( $lang->code ) && '' !== Languages::chromeCode( $source ) ? array( 'chrome' ) : array();
			return array(
				'default' => 'chrome',
				'list'    => array_map( static fn( string $id ): array => array( 'id' => $id, 'label' => __( 'Free Translate', 'lango7' ) ), $ids ),
			);
		}
		$default = $this->engines->serviceFor( $lang );
		$ids     = $this->engines->servicesFor( $lang->code, $source );
		usort( $ids, static fn( string $a, string $b ): int => ( $b === $default ) <=> ( $a === $default ) );
		return array(
			'default' => $default,
			'list'    => array_map( fn( string $id ): array => array( 'id' => $id, 'label' => $this->engines->serviceLabel( $id ) ), $ids ),
		);
	}

	/**
	 * Whether the occurrence is all the text of its element, so the element
	 * itself can carry the marker and no wrapper changes the markup.
	 */
	private function isWholeText( Occurrence $o, string $html ): bool {
		$n = $o->node;
		if ( Node::ELEMENT !== $n->type || $n->closeStart <= $n->tagEnd || $o->start < $n->tagEnd || $o->end > $n->closeStart ) {
			return false;
		}
		return '' === trim( substr( $html, $n->tagEnd, $o->start - $n->tagEnd ) ) && '' === trim( substr( $html, $o->end, $n->closeStart - $o->end ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function strings(): array {
		return array(
			/* translators: %s: language name. */
			'editing'     => __( 'Editing %s', 'lango7' ),
			'pageStrings' => __( 'Page strings', 'lango7' ),
			'exit'        => __( 'Exit', 'lango7' ),
			'search'      => __( 'Search this page', 'lango7' ),
			'hidden'      => __( 'Not on screen (other slides, tabs, menus)', 'lango7' ),
			'altShort'    => __( 'Alt (⌥) + click: use the page', 'lango7' ),
			'altHint'     => __( 'Hold Alt (⌥ Option on a Mac) and click to use the page: open slides, tabs and menus.', 'lango7' ),
			'source'      => __( 'Original', 'lango7' ),
			'translation' => __( 'Translation', 'lango7' ),
			'save'        => __( 'Save', 'lango7' ),
			'revert'      => __( 'Remove my edit', 'lango7' ),
			'translateNow' => __( 'Translate now', 'lango7' ),
			'service'     => __( 'Translate with', 'lango7' ),
			/* translators: %s: translation service name. */
			'yours'       => __( '%s (this language’s service)', 'lango7' ),
			'working'     => __( 'Translating…', 'lango7' ),
			/* translators: %s: translation service name. */
			'translated'  => __( 'Translated with %s and saved. Revert brings back the earlier version.', 'lango7' ),
			'undo'        => __( 'Revert', 'lango7' ),
			'undoTitle'   => __( 'Bring back the translation shown before Translate now', 'lango7' ),
			'undone'      => __( 'The earlier translation is back.', 'lango7' ),
			'undoneSrc'   => __( 'Reverted. This text had no translation before, so it shows in the original language.', 'lango7' ),
			'noChrome'    => __( 'Free Translate needs desktop Chrome 138 or later on a secure (https) address.', 'lango7' ),
			/* translators: %s: translation service name. */
			'cost'        => __( 'This sends the original text to %s again and counts against its usage.', 'lango7' ),
			'cancel'      => __( 'Cancel', 'lango7' ),
			'keep'        => __( 'Keep original', 'lango7' ),
			'keepTitle'   => __( 'Show the original text here instead of a translation. Saved as your edit, so it is not translated again.', 'lango7' ),
			'title'       => __( 'Page title', 'lango7' ),
			'machine'     => __( 'Machine', 'lango7' ),
			'edited'      => __( 'Edited', 'lango7' ),
			'pending'     => __( 'Not translated yet', 'lango7' ),
			'failed'      => __( 'Translation failed', 'lango7' ),
			'review'      => __( 'Needs review', 'lango7' ),
			'unapproved'  => __( 'Awaiting approval: visitors see the original text until it is approved', 'lango7' ),
			'approve'     => __( 'Approve', 'lango7' ),
			'saved'       => __( 'Saved. This edit is kept even when the page is translated again.', 'lango7' ),
			'reverted'    => __( 'Reverted to the machine translation.', 'lango7' ),
			'revertedSrc' => __( 'Edit removed. This text shows in the original language until the next Translate with Chrome run.', 'lango7' ),
			/* translators: %s: the missing items. */
			'missing'     => __( 'Links, images, buttons and numbers can be moved but not removed. Missing: %s', 'lango7' ),
			'extra'       => __( 'Each mark from the original can appear only once.', 'lango7' ),
			'order'       => __( 'A pair of marks is out of order: each closing mark must come after its opening mark, and pairs cannot cross.', 'lango7' ),
			'bold'        => __( 'Bold (Ctrl/⌘ + B): select words first', 'lango7' ),
			'italic'      => __( 'Italic (Ctrl/⌘ + I): select words first', 'lango7' ),
			'lineBreak'   => __( 'Line break (Shift + Enter)', 'lango7' ),
			'selectFirst' => __( 'Select the words to format first.', 'lango7' ),
			'marksHelpTitle' => __( 'What are the marks?', 'lango7' ),
			'marksHelp'   => __( 'Marks stand for parts of the page around your words. Blue pairs (bold, italic, styled text) give the words between them a style: keep the words that should look like that between the two marks, or delete a mark to drop the style. A line break can be deleted. Links, images, buttons and numbers have a solid outline: move them where they belong in your sentence, but keep them. Hover a mark to see its partner.', 'lango7' ),
			/* translators: %s: kind of mark, e.g. link or image. */
			'chipLocked'  => __( '%s: move it where it belongs in your sentence, but keep it.', 'lango7' ),
			'chipNumber'  => __( 'A number from the page; it updates by itself. Move it, but keep it.', 'lango7' ),
			/* translators: %s: kind of style, e.g. bold or italic. */
			'chipStyle'   => __( '%s: the words between this mark and its partner get this style. Delete the mark to drop the style.', 'lango7' ),
			'chipBreak'   => __( 'Line break. Delete it to join the lines.', 'lango7' ),
			'empty'       => __( 'The translation cannot be empty.', 'lango7' ),
			'error'       => __( 'Something went wrong.', 'lango7' ),
			/* translators: %d: number of strings. */
			'count'       => __( '%d strings', 'lango7' ),
			'hint'        => __( 'Ctrl/⌘ + Enter saves · Shift + Enter adds a line break · Esc closes · Tab saves and goes to the next', 'lango7' ),
		);
	}
}
