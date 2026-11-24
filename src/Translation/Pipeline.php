<?php
/**
 * Translates one rendered page.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Translation;

use Lango7\Cache\Purger;
use Lango7\Html\Applier;
use Lango7\Html\Node;
use Lango7\Html\Parser;
use Lango7\Html\Segmenter;
use Lango7\Html\Selector;
use Lango7\Html\UrlMatcher;
use Lango7\Language;
use Lango7\Languages;
use Lango7\Queue\Jobs;
use Lango7\Router\LanguageRouter;
use Lango7\Settings;
use Lango7\Store\StringRepository;
use Lango7\Store\TranslationRepository;

/**
 * parse → segment → look up (one query, or one object-cache hit) → translate
 * misses within the time budget → queue the rest → apply → rewrite links and
 * head tags.
 */
final class Pipeline {

	/** True when some text is still in the source language after this request. */
	private bool $pending = false;

	public function __construct(
		private Settings $settings,
		private Languages $languages,
		private LanguageRouter $router,
		private StringRepository $strings,
		private TranslationRepository $translations,
		private Translator $translator,
		private Jobs $jobs
	) {}

	public function pending(): bool {
		return $this->pending;
	}

	public function process( string $html, Language $lang, PageContext $page ): string {
		$start         = microtime( true );
		$this->pending = false;

		[ $root, $found, $segments ] = $this->scan( $html );

		$map = $this->resolve( $segments, $lang, $page, $start );

		$applier = new Applier( $html );
		foreach ( $found['attributes'] as $o ) {
			$t = $map[ $o->segment->hash ] ?? null;
			if ( null !== $t ) {
				$applier->setAttribute( $o->node, (string) $o->attr, Placeholders::toPlain( $t, $o->values ) );
			}
		}
		$this->rewriteDocument( $root, $applier, $lang, $page );

		/**
		 * Last chance to change attributes before text is applied (edit-mode markers use this).
		 *
		 * @param Applier $applier
		 * @param array   $found   texts and attributes occurrences.
		 * @param array   $map     hash => translation (null = source).
		 */
		do_action( 'lango7_before_apply', $applier, $found, $map, $lang, $root, $html, $page );

		$marking = has_filter( 'lango7_text_marker' );
		foreach ( $found['texts'] as $o ) {
			$t = $map[ $o->segment->hash ] ?? null;
			/**
			 * Wrap a text occurrence in markup (edit mode). Return [before, after], or
			 * null to leave it as is; a callback may set attributes on $o->node instead.
			 *
			 * @param array{0: string, 1: string}|null $wrap
			 */
			$wrap = $marking ? apply_filters( 'lango7_text_marker', null, $o, $applier, $html ) : null;
			if ( is_array( $wrap ) ) {
				$applier->wrap( $o, $wrap[0], $wrap[1], $t );
			} elseif ( null !== $t ) {
				$applier->replace( $o, $t );
			}
		}

		/**
		 * The translated page, before it is sent.
		 *
		 * @param string   $output
		 * @param Language $lang
		 * @param array    $found  texts and attributes occurrences.
		 * @param array    $map    hash => translation (null = source).
		 */
		return (string) apply_filters( 'lango7_output', $applier->output(), $lang, $found, $map );
	}

	/**
	 * Every segment a page would send for translation, keyed by hash (bulk
	 * estimates use this so they count exactly what a visit would).
	 *
	 * @return array<string, Segment>
	 */
	public function segmentsOf( string $html ): array {
		return $this->scan( $html )[2];
	}

	/**
	 * @return array{0: Node, 1: array{texts: \Lango7\Html\Occurrence[], attributes: \Lango7\Html\Occurrence[]}, 2: array<string, Segment>}
	 */
	private function scan( string $html ): array {
		$root      = Parser::parse( $html );
		$skip      = new Selector( (string) apply_filters( 'lango7_skip_selectors', (string) $this->settings->get( 'exclude_selectors' ) ) );
		$attrs     = (array) apply_filters( 'lango7_translatable_attributes', array( 'alt', 'title', 'placeholder', 'aria-label' ) );
		$segmenter = new Segmenter( $this->languages->sourceCode(), $skip->isEmpty() ? null : $skip, $attrs );
		$found     = $segmenter->segment( $root, $html );

		$segments = array();
		foreach ( array_merge( $found['texts'], $found['attributes'] ) as $o ) {
			$segments[ $o->segment->hash ] ??= $o->segment;
		}
		/**
		 * Text outside the markup an extension translates itself (JSON-LD). It is
		 * looked up, translated and cached with the page's other segments; apply
		 * it in lango7_before_apply.
		 *
		 * @param Segment[] $extra
		 * @param Node      $root
		 * @param string    $html
		 */
		foreach ( (array) apply_filters( 'lango7_extra_segments', array(), $root, $html ) as $s ) {
			if ( $s instanceof Segment ) {
				$segments[ $s->hash ] ??= $s;
			}
		}
		return array( $root, $found, $segments );
	}

	/**
	 * @param array<string, Segment> $segments
	 * @return array<string, ?string> hash => translation; null renders the source.
	 */
	private function resolve( array $segments, Language $lang, PageContext $page, float $start ): array {
		if ( ! $segments ) {
			return array();
		}
		$hashes    = array_keys( $segments );
		$url_hash  = sha1( $page->path );
		$cache_key = 'page2_' . md5( $lang->code . '|' . $page->path . '|' . sha1( implode( ',', $hashes ) ) . '|' . Purger::version() );

		// A warm page is one object-cache hit. Only complete maps are cached, so a
		// miss is always re-checked against the database before costing money.
		$cached = wp_cache_get( $cache_key, 'lango7' );
		if ( is_array( $cached ) && isset( $cached['map'], $cached['held'] ) ) {
			return $this->shown( (array) $cached['map'], (array) $cached['held'], $lang );
		}

		$look   = $this->translations->lookup( $lang->code, $url_hash, $hashes, $url_hash );
		$map    = $look['found'];
		$ids    = $look['ids'];
		$touch  = $look['stale'];
		$misses = array_diff_key( $segments, $map );
		$held   = array_keys( $look['status'], TranslationRepository::UNAPPROVED, true );

		if ( $misses && $page->engine ) {
			$new   = $this->strings->ensure( array_values( array_diff_key( $misses, $ids ) ), $this->languages->sourceCode() );
			$ids  += $new;
			$touch = array_merge( $touch, array_values( $new ) );

			/**
			 * Text on this page with no translation yet, before it is translated
			 * or queued (source-changed review compares it with edits that left).
			 *
			 * @param Language               $lang
			 * @param array<string, Segment> $misses
			 * @param array<string, int>     $ids      hash => string id, every segment on the page.
			 * @param PageContext            $page
			 */
			do_action( 'lango7_new_text', $lang, $misses, $ids, $page );

			/**
			 * False leaves new text untranslated on this visit: it is recorded (so
			 * the Languages column and "Untranslated" count it) but neither
			 * translated nor queued. It waits for "Translate now" or a bulk run.
			 *
			 * @param bool        $on_visit
			 * @param Language    $lang
			 * @param PageContext $page
			 */
			$on_visit = (bool) apply_filters( 'lango7_translate_on_visit', true, $lang, $page );

			/**
			 * False sends new text straight to the background queue instead of
			 * translating it while the visitor waits (slow AI engines).
			 *
			 * @param bool     $inline
			 * @param Language $lang
			 */
			if ( $on_visit && apply_filters( 'lango7_translate_inline', true, $lang ) ) {
				$limit  = max( 1, (int) $this->settings->get( 'max_segments' ) );
				$result = $this->translator->translate( array_slice( $misses, 0, $limit, true ), $ids, $lang, $start + $page->budget, $page->url );
				$map   += $result['translated'];
				if ( TranslationRepository::UNAPPROVED === TranslationRepository::machineStatus( $lang->code ) ) {
					$held = array_merge( $held, array_keys( $result['translated'] ) );
				}
				foreach ( $result['failed'] as $hash ) {
					$map[ $hash ] = null;
				}
				$misses = array_diff_key( $misses, $map );
				if ( $misses && 'unconfigured' !== $result['stopped'] ) {
					$this->jobs->enqueue( $lang->code, $misses );
				}
			} elseif ( $on_visit ) {
				$this->jobs->enqueue( $lang->code, $misses );
			}
		}

		if ( $touch && $page->engine ) {
			$this->strings->touchUrls( $touch, $url_hash, $page->path );
		}

		if ( $misses ) {
			$this->pending = true;
		} else {
			wp_cache_set(
				$cache_key,
				array(
					'map'  => $map,
					'held' => $held,
				),
				'lango7',
				DAY_IN_SECONDS
			);
		}
		return $this->shown( $map, $held, $lang );
	}

	/**
	 * The map this viewer gets: text waiting for approval renders as the
	 * source unless the viewer may see it (Pro decides).
	 *
	 * @param array<string, ?string> $map
	 * @param string[]               $held hashes waiting for approval.
	 * @return array<string, ?string>
	 */
	private function shown( array $map, array $held, Language $lang ): array {
		/**
		 * Whether this viewer gets the source text instead of translations that
		 * wait for approval.
		 *
		 * @param bool     $hide
		 * @param Language $lang
		 */
		if ( $held && apply_filters( 'lango7_hide_unapproved', false, $lang ) ) {
			foreach ( $held as $hash ) {
				if ( array_key_exists( $hash, $map ) ) {
					$map[ $hash ] = null;
				}
			}
		}
		return $map;
	}

	/**
	 * Internal links, canonical, og:url, pagination links and <html lang dir>.
	 */
	private function rewriteDocument( Node $root, Applier $applier, Language $lang, PageContext $page ): void {
		$links     = $this->router->linkRewriter( \Lango7\Exclusions::patterns() );
		$head      = null;
		$canonical = false;

		$rewrite = static function ( Node $n, string $attr ) use ( $links, $applier, $lang ): void {
			$value = $n->attr( $attr );
			if ( null !== $value ) {
				$new = $links->rewrite( $value, $lang->prefix );
				if ( null !== $new ) {
					$applier->setAttribute( $n, $attr, $new );
				}
			}
		};

		$stack = array( $root );
		while ( $stack ) {
			$node = array_pop( $stack );
			foreach ( $node->children as $n ) {
				if ( Node::ELEMENT !== $n->type ) {
					continue;
				}
				switch ( $n->name ) {
					case 'html':
						$applier->setAttribute( $n, 'lang', (string) apply_filters( 'lango7_html_lang', $lang->code, $lang ) );
						// Right-to-left text keeps the site's layout unless the page is mirrored.
						if ( 'rtl' !== $lang->dir || TextDirection::mirror( $lang ) ) {
							$applier->setAttribute( $n, 'dir', $lang->dir );
						}
						break;
					case 'head':
						$head = $n;
						break;
					case 'a':
					case 'area':
						// The switcher's links point at other languages on purpose.
						if ( ! isset( $n->attrs['hreflang'] ) && ! isset( $n->attrs['data-lango7-lang'] ) ) {
							$rewrite( $n, 'href' );
						}
						break;
					case 'form':
						$rewrite( $n, 'action' );
						break;
					case 'link':
						$rel = strtolower( (string) $n->attr( 'rel' ) );
						if ( 'canonical' === $rel ) {
							$canonical = true;
						}
						if ( in_array( $rel, array( 'canonical', 'next', 'prev' ), true ) ) {
							$rewrite( $n, 'href' );
						}
						break;
					case 'meta':
						if ( 'og:url' === strtolower( (string) $n->attr( 'property' ) ) ) {
							$rewrite( $n, 'content' );
						}
						break;
				}
				if ( $n->children ) {
					$stack[] = $n;
				}
			}
		}

		// Translated pages are self-canonical even when the source page had no tag.
		if ( ! $canonical && ! $page->is_404 && null !== $head && $head->closeStart < $head->end ) {
			$applier->insert( $head->closeStart, '<link rel="canonical" href="' . esc_url( $this->router->urlFor( $lang, $page->path ) ) . '">' . "\n" );
		}
	}
}
