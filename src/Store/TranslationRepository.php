<?php
/**
 * Translations: lookup and writes.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Store;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom tables. Every SQL fragment built at runtime ($in, $join, $lsql, $rows, $sql) contains only %s/%d/%i placeholders and fixed column names; all values go through $wpdb->prepare().

final class TranslationRepository {

	/** Rendered as the source text: the engine could not produce a valid translation. */
	public const FAILED = 'failed';

	/** Machine output that waits for approval; visitors see the source text. */
	public const UNAPPROVED = 'unapproved';

	/** Higher wins. Page-scoped edits beat global edits beat machine output. */
	private const RANK = array(
		'edited'       => 3,
		'ai_edited'    => 3,
		'machine'      => 2,
		'needs_review' => 2,
		self::UNAPPROVED => 2,
		self::FAILED   => 1,
	);

	/**
	 * Everything the page needs in one query.
	 *
	 * @param string[] $hashes
	 * @return array{found: array<string, ?string>, status: array<string, string>, ids: array<string, int>, stale: int[], scoped: array<string, bool>}
	 *         found: hash => translation (null = failed, render source);
	 *         scoped: hash => true when the winning row is this page's own edit;
	 *         ids: hash => string id for every stored string, translated or not;
	 *         stale: string ids whose URL record is missing or older than a day.
	 */
	public function lookup( string $lang, string $scope, array $hashes, string $url_hash ): array {
		global $wpdb;
		$out = array(
			'found'  => array(),
			'status' => array(),
			'ids'    => array(),
			'stale'  => array(),
			'scoped' => array(),
		);
		if ( ! $hashes ) {
			return $out;
		}

		$in   = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.hash, t.scope, t.translated, t.status, u.last_seen_at AS url_seen
				FROM %i s
				LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s AND t.scope IN ('', %s)
				LEFT JOIN %i u ON u.string_id = s.id AND u.url_hash = %s
				WHERE s.hash IN ({$in})",
				Schema::table( 'strings' ),
				Schema::table( 'translations' ),
				$lang,
				$scope,
				Schema::table( 'string_urls' ),
				$url_hash,
				...array_values( $hashes )
			),
			ARRAY_A
		);

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$best   = array();
		foreach ( (array) $rows as $row ) {
			$hash               = $row['hash'];
			$out['ids'][ $hash ] = (int) $row['id'];
			if ( null === $row['url_seen'] || $row['url_seen'] < $cutoff ) {
				$out['stale'][ (int) $row['id'] ] = (int) $row['id'];
			}
			if ( null === $row['status'] ) {
				continue;
			}
			$rank = ( self::RANK[ $row['status'] ] ?? 0 ) + ( '' !== $row['scope'] ? 10 : 0 );
			if ( $rank > ( $best[ $hash ] ?? -1 ) ) {
				$best[ $hash ]          = $rank;
				$out['status'][ $hash ] = $row['status'];
				$out['found'][ $hash ]  = self::FAILED === $row['status'] ? null : $row['translated'];
				if ( '' !== $row['scope'] ) {
					$out['scoped'][ $hash ] = true;
				} else {
					unset( $out['scoped'][ $hash ] );
				}
			}
		}
		$out['stale'] = array_values( $out['stale'] );
		return $out;
	}

	/**
	 * Store machine output. A locked row (a human edit) is never overwritten.
	 *
	 * @param array<int, string> $texts string id => translation.
	 */
	public function saveMachine( string $lang, array $texts, string $engine ): void {
		$this->upsert( $lang, $texts, self::machineStatus( $lang ), $engine );
	}

	/**
	 * The status new machine output gets in a language: "machine", or
	 * "unapproved" where Pro holds it for approval.
	 */
	public static function machineStatus( string $lang ): string {
		/**
		 * Status for new machine translations in a language.
		 *
		 * @param string $status "machine" or "unapproved".
		 * @param string $lang
		 */
		$status = (string) apply_filters( 'lango7_machine_status', 'machine', $lang );
		return self::UNAPPROVED === $status ? $status : 'machine';
	}

	/**
	 * @param int[] $ids
	 */
	public function markFailed( string $lang, array $ids, string $engine ): void {
		$this->upsert( $lang, array_fill_keys( $ids, '' ), self::FAILED, $engine );
	}

	/**
	 * Change the status of unlocked global rows (e.g. to needs_review).
	 * Text waiting for approval keeps waiting: only approving it ($from
	 * "unapproved") changes it.
	 *
	 * @param int[] $string_ids
	 * @return int Rows changed.
	 */
	public function markStatus( string $lang, array $string_ids, string $status, string $from = '' ): int {
		global $wpdb;
		$n = 0;
		foreach ( array_chunk( array_map( 'intval', $string_ids ), 500 ) as $chunk ) {
			$in    = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$where = '' === $from ? 'status <> %s' : 'status = %s';
			$n    += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s WHERE lang = %s AND scope = '' AND locked = 0 AND {$where} AND string_id IN ({$in})",
					Schema::table( 'translations' ),
					$status,
					$lang,
					'' === $from ? self::UNAPPROVED : $from,
					...$chunk
				)
			);
		}
		return $n;
	}

	/**
	 * String ids on a page whose translation waits for approval.
	 *
	 * @return int[]
	 */
	public function unapprovedOn( string $lang, string $url_hash ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT t.string_id FROM %i t JOIN %i u ON u.string_id = t.string_id AND u.url_hash = %s
					WHERE t.lang = %s AND t.scope = '' AND t.status = %s",
					Schema::table( 'translations' ),
					Schema::table( 'string_urls' ),
					$url_hash,
					$lang,
					self::UNAPPROVED
				)
			)
		);
	}

	/**
	 * Make translations that wait for approval visible to everyone.
	 *
	 * @param int[] $string_ids
	 * @return int Rows approved.
	 */
	public function approve( string $lang, array $string_ids ): int {
		return $this->markStatus( $lang, $string_ids, 'machine', self::UNAPPROVED );
	}

	/**
	 * Delete machine and failed rows so the strings translate again. Edits stay.
	 *
	 * @return int Rows deleted.
	 */
	public function resetMachine( ?string $lang = null ): int {
		global $wpdb;
		if ( null === $lang ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE locked = 0', Schema::table( 'translations' ) ) );
		}
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE locked = 0 AND lang = %s', Schema::table( 'translations' ), $lang ) );
	}

	/**
	 * Delete machine and failed rows of some strings so they translate again. Edits stay.
	 *
	 * @param int[] $string_ids
	 * @return int Rows deleted.
	 */
	public function resetStrings( array $string_ids, ?string $lang = null ): int {
		global $wpdb;
		$deleted = 0;
		foreach ( array_chunk( array_map( 'intval', $string_ids ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args = array_merge( array( Schema::table( 'translations' ) ), $chunk );
			$lsql = '';
			if ( null !== $lang ) {
				$lsql   = ' AND lang = %s';
				$args[] = $lang;
			}
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE locked = 0 AND string_id IN ({$in}){$lsql}", ...$args ) );
		}
		return $deleted;
	}

	/**
	 * Machine (unlocked) translations whose source text contains a phrase:
	 * candidates for a new glossary term. Matching is the database's (usually
	 * case-insensitive); callers narrow it down.
	 *
	 * @return array<int, array{id: string, hash: string, source_text: string, kind: string, lang: string, translated: string}>
	 */
	public function machineContaining( string $escaped_phrase, ?string $lang = null, int $limit = 5000 ): array {
		global $wpdb;
		$args = array( Schema::table( 'strings' ), Schema::table( 'translations' ), '%' . $wpdb->esc_like( $escaped_phrase ) . '%' );
		$lsql = '';
		if ( null !== $lang ) {
			$lsql   = ' AND t.lang = %s';
			$args[] = $lang;
		}
		$args[] = $limit;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.hash, s.source_text, s.kind, t.lang, t.translated FROM %i s JOIN %i t ON t.string_id = s.id AND t.locked = 0
				WHERE s.source_text LIKE %s{$lsql} LIMIT %d",
				...$args
			),
			ARRAY_A
		);
	}

	/**
	 * One page of the Strings screen.
	 *
	 * @param string $status '' (all), 'machine', 'unapproved', 'edited', 'untranslated' or 'failed'.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function search( string $lang, string $query, string $status, int $offset, int $limit, string $engine = '' ): array {
		global $wpdb;
		$from  = "FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s AND t.scope = ''";
		$args  = array( Schema::table( 'strings' ), Schema::table( 'translations' ), $lang );
		$where = array();
		if ( '' !== $query ) {
			$like    = '%' . $wpdb->esc_like( $query ) . '%';
			$where[] = '(s.source_text LIKE %s OR t.translated LIKE %s)';
			array_push( $args, $like, $like );
		}
		if ( 'untranslated' === $status ) {
			$where[] = 't.id IS NULL';
		} elseif ( 'edited' === $status ) {
			$where[] = "t.status IN ('edited', 'ai_edited')";
		} elseif ( 'machine' === $status ) {
			$where[] = "t.status IN ('machine', 'needs_review', 'unapproved')";
		} elseif ( self::UNAPPROVED === $status ) {
			$where[] = "t.status = 'unapproved'";
		} elseif ( self::FAILED === $status ) {
			$where[] = "t.status = 'failed'";
		}
		if ( 'manual' === $engine ) {
			$where[] = "t.status = 'edited'";
		} elseif ( '' !== $engine ) {
			$where[]  = "t.engine = %s AND t.status <> 'edited'";
			$args[] = $engine;
		}
		$sql   = $from . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql}", ...$args ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.hash, s.kind, s.source_text, s.last_seen_at, t.translated, t.status, t.engine, t.updated_at
				{$sql} ORDER BY s.last_seen_at DESC, s.id DESC LIMIT %d OFFSET %d",
				...array_merge( $args, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Engine ids that made translations in this language, for the Translations filter.
	 *
	 * @return string[]
	 */
	public function enginesUsed( string $lang ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT engine FROM %i WHERE lang = %s AND scope = '' AND engine IS NOT NULL AND engine <> '' AND status <> 'edited'", Schema::table( 'translations' ), $lang ) ) );
	}

	/**
	 * A human edit: locked, so machine runs never overwrite it.
	 */
	public function saveEdit( string $lang, int $string_id, string $text, int $user_id, string $scope = '' ): void {
		global $wpdb;
		$this->replacing( $lang, $string_id, $scope, $text );
		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (string_id, lang, scope, translated, status, locked, engine, edited_by, created_at, updated_at)
				VALUES (%d, %s, %s, %s, 'edited', 1, NULL, %d, %s, %s)
				ON DUPLICATE KEY UPDATE translated = VALUES(translated), status = 'edited', locked = 1, engine = NULL,
					edited_by = VALUES(edited_by), updated_at = VALUES(updated_at)",
				Schema::table( 'translations' ),
				$string_id,
				$lang,
				$scope,
				$text,
				$user_id,
				$now,
				$now
			)
		);
	}

	/**
	 * Save an AI Fix correction: not locked, so Retranslate can redo it, and the
	 * text it replaces goes to listeners (Pro's history) so it can be reverted.
	 * Hand edits (locked rows) are never changed.
	 */
	public function saveAiFix( string $lang, int $string_id, string $text, string $engine ): bool {
		global $wpdb;
		$this->replacing( $lang, $string_id, '', $text );
		$now = current_time( 'mysql', true );
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET translated = %s, status = 'ai_edited', engine = %s, updated_at = %s
				WHERE string_id = %d AND lang = %s AND scope = '' AND locked = 0",
				Schema::table( 'translations' ),
				$text,
				$engine,
				$now,
				$string_id,
				$lang
			)
		);
	}

	/**
	 * Hands the row about to be replaced by an edit (or removed by a revert) to
	 * listeners, e.g. a translation history. Read only when someone listens.
	 */
	private function replacing( string $lang, int $string_id, string $scope, ?string $new_text ): void {
		global $wpdb;
		if ( ! has_action( 'lango7_translation_replacing' ) ) {
			return;
		}
		$old = $wpdb->get_row(
			$wpdb->prepare( 'SELECT translated, status, engine, edited_by, updated_at FROM %i WHERE string_id = %d AND lang = %s AND scope = %s', Schema::table( 'translations' ), $string_id, $lang, $scope ),
			ARRAY_A
		);
		if ( ! $old || ( null !== $new_text && $old['translated'] === $new_text ) ) {
			return;
		}

		/**
		 * A translation is about to be replaced by an edit, or removed by a revert.
		 *
		 * @param string                $lang
		 * @param int                   $string_id
		 * @param string                $scope     '' or sha1 of a page path ("This page only").
		 * @param array<string, string> $old       translated, status, engine, edited_by, updated_at.
		 * @param string|null           $new_text  Null for a revert.
		 */
		do_action( 'lango7_translation_replacing', $lang, $string_id, $scope, $old, $new_text );
	}

	/**
	 * The stored row of one string in one language and scope, or null.
	 *
	 * @return array{translated: string, status: string, engine: ?string}|null
	 */
	public function row( string $lang, int $string_id, string $scope = '' ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT translated, status, engine FROM %i WHERE string_id = %d AND lang = %s AND scope = %s', Schema::table( 'translations' ), $string_id, $lang, $scope ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Drop a string's translation in one language and scope (revert an edit).
	 */
	public function forget( string $lang, int $string_id, string $scope = '' ): void {
		global $wpdb;
		$this->replacing( $lang, $string_id, $scope, null );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE string_id = %d AND lang = %s AND scope = %s', Schema::table( 'translations' ), $string_id, $lang, $scope ) );
	}

	/**
	 * @return array<string, int> lang => strings with a translation row (any status).
	 */
	public function countsByLanguage(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT lang, COUNT(*) AS n FROM %i WHERE scope = '' GROUP BY lang", Schema::table( 'translations' ) ), ARRAY_A );
		return array_column( (array) $rows, 'n', 'lang' );
	}

	/**
	 * Delete one language's translations of some strings, edits included:
	 * site-wide rows, and rows scoped to $scope (a page's own edits).
	 *
	 * @param int[] $string_ids Strings whose site-wide rows go.
	 * @param int[] $scoped_ids Strings whose rows for $scope go.
	 * @return int Rows deleted.
	 */
	public function deleteForPage( string $lang, array $string_ids, array $scoped_ids, string $scope ): int {
		global $wpdb;
		$deleted = 0;
		$jobs    = array( array( $string_ids, '' ) );
		if ( '' !== $scope ) {
			$jobs[] = array( $scoped_ids, $scope );
		}
		foreach ( $jobs as [ $ids, $sc ] ) {
			foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
				$in       = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE lang = %s AND scope = %s AND string_id IN ({$in})", Schema::table( 'translations' ), $lang, $sc, ...$chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
			}
		}
		return $deleted;
	}

	/**
	 * Delete every translation of a language, edits included.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteLanguage( string $lang ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE lang = %s', Schema::table( 'translations' ), $lang ) );
	}

	/**
	 * @return array<string, int> status => count, for one language.
	 */
	public function statusCounts( string $lang ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE lang = %s AND scope = '' GROUP BY status", Schema::table( 'translations' ), $lang ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Strings with their translation in one language, newest first.
	 *
	 * @return array<int, array{hash: string, kind: string, source_text: string, translated: ?string, status: ?string}>
	 */
	public function listStrings( string $lang, ?string $status = null, int $limit = 50 ): array {
		global $wpdb;
		$sql  = "SELECT s.hash, s.kind, s.source_text, t.translated, t.status
			FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s AND t.scope = ''";
		$args = array( Schema::table( 'strings' ), Schema::table( 'translations' ), $lang );
		if ( 'untranslated' === $status ) {
			$sql .= ' WHERE t.id IS NULL';
		} elseif ( null !== $status ) {
			$sql   .= ' WHERE t.status = %s';
			$args[] = $status;
		}
		$sql   .= ' ORDER BY s.last_seen_at DESC LIMIT %d';
		$args[] = $limit;
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
	}

	/**
	 * @param array<int, string> $texts
	 */
	private function upsert( string $lang, array $texts, string $status, string $engine ): void {
		global $wpdb;
		if ( ! $texts ) {
			return;
		}
		$now = current_time( 'mysql', true );
		foreach ( array_chunk( $texts, 200, true ) as $chunk ) {
			$rows = array();
			$args = array( Schema::table( 'translations' ) );
			foreach ( $chunk as $id => $text ) {
				$rows[] = "(%d, %s, '', %s, %s, 0, %s, %s, %s)";
				array_push( $args, (int) $id, $lang, $text, $status, $engine, $now, $now );
			}
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (string_id, lang, scope, translated, status, locked, engine, created_at, updated_at) VALUES ' . implode( ',', $rows ) . '
					ON DUPLICATE KEY UPDATE
						translated = IF(locked = 1, translated, VALUES(translated)),
						status = IF(locked = 1, status, VALUES(status)),
						engine = IF(locked = 1, engine, VALUES(engine)),
						updated_at = IF(locked = 1, updated_at, VALUES(updated_at))',
					...$args
				)
			);
		}
	}
}
