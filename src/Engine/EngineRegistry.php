<?php
/**
 * Engine registry.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Engine;

use Lango7\Language;
use Lango7\Languages;

final class EngineRegistry {

	/** @var array<string, Engine>|null */
	private ?array $engines = null;

	/**
	 * @return array<string, Engine>
	 */
	public function all(): array {
		if ( null === $this->engines ) {
			/**
			 * Register server-side engines (Lango7 Pro: Google, DeepL, AI):
			 * add_filter( 'lango7_engines', fn( $e ) => $e + [ 'deepl' => new DeepL() ] ).
			 *
			 * Free has none: it translates in the admin's own Chrome (Admin\ChromePage),
			 * which saves through REST instead of being called from the pipeline.
			 *
			 * @param array<string, Engine> $engines
			 */
			$engines       = apply_filters( 'lango7_engines', array() );
			$this->engines = array_filter( $engines, static fn( $e ): bool => $e instanceof Engine );
		}
		return $this->engines;
	}

	public function get( string $id ): ?Engine {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * The engine for a language: the language's own when Pro sets one, else the
	 * first configured one, else the first registered. Null in Free, where pages
	 * are translated from Chrome.
	 */
	public function forLanguage( Language $lang ): ?Engine {
		$id  = (string) apply_filters( 'lango7_engine_for_language', $lang->engine, $lang );
		if ( 'chrome' === $id ) { return null; }
		$all = $this->all();
		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		foreach ( $all as $engine ) {
			if ( $engine->isConfigured() ) {
				return $engine;
			}
		}
		return $all ? reset( $all ) : null;
	}

	/**
	 * Whether a service can translate the site language into a language.
	 *
	 * @param string $id     'chrome' for Free Translate, or an engine id.
	 * @param string $code   Target language code.
	 * @param string $source Site language code.
	 */
	public function supports( string $id, string $code, string $source ): bool {
		if ( 'chrome' === $id ) {
			return '' !== Languages::chromeCode( $code ) && '' !== Languages::chromeCode( $source );
		}
		$engine = $this->get( $id );
		if ( null === $engine || ! $engine->isConfigured() ) {
			return false;
		}
		return ! $engine instanceof LanguageSupport || ( $engine->supports( $code ) && $engine->supports( $source, true ) );
	}

	/**
	 * The id of the service a language is translated with: an engine id, or
	 * 'chrome' for Free Translate (also when the engine has no key yet).
	 */
	public function serviceFor( Language $lang ): string {
		$engine = $this->hasServerEngine() ? $this->forLanguage( $lang ) : null;
		return $engine && $engine->isConfigured() ? $engine->id() : 'chrome';
	}

	/**
	 * Whether the service set for a language can translate it.
	 */
	public function canTranslate( Language $lang, string $source ): bool {
		return $this->supports( $this->serviceFor( $lang ), $lang->code, $source );
	}

	/**
	 * Every service that can translate the site language into a language:
	 * 'chrome' and configured engine ids.
	 *
	 * @return string[]
	 */
	public function servicesFor( string $code, string $source ): array {
		$ids = array_merge( array( 'chrome' ), array_keys( $this->all() ) );
		return array_values( array_filter( $ids, fn( string $id ): bool => $this->supports( $id, $code, $source ) ) );
	}

	/**
	 * Display name of a service id.
	 */
	public function serviceLabel( string $id ): string {
		return 'chrome' === $id ? __( 'Free Translate', 'lango7' ) : ( $this->get( $id )?->label() ?? $id );
	}

	public function hasServerEngine(): bool {
		return (bool) $this->all();
	}
}
