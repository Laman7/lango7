<?php
/**
 * Source strings and where they appear.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Store;

use Lango7\Translation\Segment;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom tables. Every SQL fragment built at runtime ($in, $join, $lsql, $rows, $sql) contains only %s/%d/%i placeholders and fixed column names; all values go through $wpdb->prepare().

final class StringRepository {

	/**
	 * Insert any segments not stored yet and return hash => id for all of them.
	 *
	 * @param Segment[] $segments
	 * @return array<string, int>
	 */
	public function ensure( array $segments, string $source_lang ): array {
		global $wpdb;
		if ( ! $segments ) {
			return array();
		}
		$table = Schema::table( 'strings' );
		$now   = current_time( 'mysql', true );
		foreach ( array_chunk( $segments, 200 ) as $chunk ) {
			$rows = array();
			$args = array( $table );
			foreach ( $chunk as $s ) {
				$rows[] = '(%s, %s, %s, %s, %d, %s, %s)';
				array_push( $args, $s->hash, $source_lang, $s->text, $s->kind, $s->chars(), $now, $now );
			}
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (hash, source_lang, source_text, kind, chars, created_at, last_seen_at) VALUES ' . implode( ',', $rows ),
					...$args
				)
			);
		}
		$ids = array();
		foreach ( $this->byHashes( array_map( static fn( Segment $s ): string => $s->hash, $segments ) ) as $row ) {
			$ids[ $row['hash'] ] = (int) $row['id'];
		}
		return $ids;
	}

	/**
	 * @param string[] $hashes
	 * @return array<int, array{id: string, hash: string, source_text: string, kind: string}>
	 */
	public function byHashes( array $hashes ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, hash, source_text, kind FROM %i WHERE hash IN ({$in})", Schema::table( 'strings' ), ...$chunk ),
				ARRAY_A
			);
			array_push( $out, ...(array) $rows );
		}
		return $out;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array{id: string, hash: string, source_text: string, kind: string}>
	 */
	public function byIds( array $ids ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, hash, source_text, kind FROM %i WHERE id IN ({$in})", Schema::table( 'strings' ), ...$chunk ),
				ARRAY_A
			);
			array_push( $out, ...(array) $rows );
		}
		return $out;
	}

	/**
	 * Record that these strings appear on a URL. Called at most once a day per
	 * URL and string (the lookup reports which rows are stale), so a warm page
	 * view stays at one query.
	 *
	 * @param int[] $ids
	 */
	public function touchUrls( array $ids, string $url_hash, string $path ): void {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$now  = current_time( 'mysql', true );
		$path = substr( $path, 0, 2048 );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$rows = array();
			$args = array( Schema::table( 'string_urls' ) );
			foreach ( $chunk as $id ) {
				$rows[] = '(%d, %s, %s, %s)';
				array_push( $args, $id, $url_hash, $path, $now );
			}
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (string_id, url_hash, url_path, last_seen_at) VALUES ' . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)',
					...$args
				)
			);
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET last_seen_at = %s WHERE id IN ({$in})", Schema::table( 'strings' ), $now, ...$chunk ) );
		}
	}

	/**
	 * Of these strings, the ones also seen on a page other than $url_hash
	 * (any page when $url_hash is ''): shared text such as menus and footers.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public function sharedElsewhere( array $ids, string $url_hash ): array {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), 500 ) as $chunk ) {
			$in  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$out = array_merge( $out, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT string_id FROM %i WHERE url_hash <> %s AND string_id IN ({$in})", Schema::table( 'string_urls' ), $url_hash, ...$chunk ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
		}
		return $out;
	}

	/**
	 * Source paths that show any of these strings, for cache purging.
	 *
	 * @param int[] $ids
	 * @return string[]
	 */
	public function urlsFor( array $ids, int $limit = 500 ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT DISTINCT url_path FROM %i WHERE string_id IN ({$in}) LIMIT %d", Schema::table( 'string_urls' ), ...array_merge( $ids, array( $limit ) ) )
			)
		);
	}

	/**
	 * One page each string was last seen on.
	 *
	 * @param int[] $ids
	 * @return array<int, string> id => source path.
	 */
	public function lastPaths( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT string_id, url_path FROM %i WHERE string_id IN ({$in}) ORDER BY last_seen_at", Schema::table( 'string_urls' ), ...$ids ),
			ARRAY_A
		);
		return array_column( $rows, 'url_path', 'string_id' ); // Later rows win: the most recent page.
	}

	/**
	 * Strings with no translation in a language, optionally only those seen on
	 * one URL. A selected string may also have an unlocked machine translation
	 * to replace. Explicit admin row retranslation may include locked edits.
	 *
	 * @return array<int, array{id: string, hash: string, source_text: string, kind: string}>
	 */
	public function pending( string $lang, string $source_lang, ?string $url_hash = null, int $limit = 50, ?string $string_hash = null, bool $include_locked = false, array $redo_ids = array() ): array {
		global $wpdb;
		$join = '';
		$args = array( Schema::table( 'strings' ) );
		if ( null !== $url_hash ) {
			$join = 'JOIN %i u ON u.string_id = s.id AND u.url_hash = %s';
			array_push( $args, Schema::table( 'string_urls' ), $url_hash );
		}
		array_push( $args, Schema::table( 'translations' ), $lang, $source_lang );
		$hash_sql = 't.id IS NULL';
		$redo_ids = array_slice( array_map( 'intval', $redo_ids ), 0, 5000 );
		if ( null === $string_hash && $redo_ids ) {
			// Strings to do again: their current translation (unlocked) stays live until replaced.
			$hash_sql = '(t.id IS NULL OR (t.locked = 0 AND s.id IN (' . implode( ',', $redo_ids ) . ')))';
		}
		if ( null !== $string_hash ) {
			$hash_sql = $include_locked ? 's.hash = %s' : 's.hash = %s AND (t.id IS NULL OR t.locked = 0)';
			$args[]   = $string_hash;
		}
		$args[] = $limit;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.hash, s.source_text, s.kind FROM %i s {$join}
				LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s AND t.scope = ''
				WHERE s.source_lang = %s AND {$hash_sql}
				ORDER BY s.id LIMIT %d",
				...$args
			),
			ARRAY_A
		);
	}

	/** Source characters still without a completed default-scope translation. */
	public function remainingCharacters( string $lang ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(s.chars), 0) FROM %i s
				WHERE NOT EXISTS (SELECT 1 FROM %i t WHERE t.string_id = s.id AND t.lang = %s
				AND t.scope = '' AND t.status IN ('machine', 'edited', 'ai_edited', 'needs_review', 'unapproved'))",
				Schema::table( 'strings' ), Schema::table( 'translations' ), $lang
			)
		);
	}

	/**
	 * @param int[] $redo_ids Strings to do again (RedoList), counted when their row is unlocked.
	 */
	public function pendingCount( string $lang, string $source_lang, array $redo_ids = array() ): int {
		global $wpdb;
		$redo = 0;
		$redo_ids = array_slice( array_map( 'intval', $redo_ids ), 0, 5000 );
		if ( $redo_ids ) {
			$redo = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i t WHERE t.lang = %s AND t.scope = \'\' AND t.locked = 0 AND t.string_id IN (' . implode( ',', $redo_ids ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
					Schema::table( 'translations' ),
					$lang
				)
			);
		}
		return $redo + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i s LEFT JOIN %i t ON t.string_id = s.id AND t.lang = %s AND t.scope = ''
				WHERE t.id IS NULL AND s.source_lang = %s",
				Schema::table( 'strings' ),
				Schema::table( 'translations' ),
				$lang,
				$source_lang
			)
		);
	}

	/**
	 * Strings not seen on any page for $days days, with their translations
	 * (edits too) and URL records. Text that comes back is recorded again.
	 *
	 * @return int Strings deleted.
	 */
	public function deleteOrphans( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE last_seen_at < %s', Schema::table( 'strings' ), $cutoff ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			foreach ( array( 'translations', 'string_urls', 'strings' ) as $table ) {
				$column = 'strings' === $table ? 'id' : 'string_id';
				$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$column} IN ({$in})", Schema::table( $table ), ...$chunk ) );
			}
		}
		if ( $ids ) {
			/**
			 * Strings (with their translations) were deleted as no longer seen.
			 *
			 * @param int[] $ids
			 */
			do_action( 'lango7_strings_deleted', $ids );
		}
		return count( $ids );
	}

	public function orphanCount( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_seen_at < %s', Schema::table( 'strings' ), $cutoff ) );
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'strings' ) ) );
	}
}
