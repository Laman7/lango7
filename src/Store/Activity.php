<?php
/**
 * Activity log: what Lango7 did, newest first (Translate → Activity).
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Store;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Lango7's own table.

/**
 * One row per event: a page translated by a service, a run of Free
 * Translate, an edit, an approval, an error, a Pro bulk run. Rows for the same
 * kind of work (type, language, service, page, person) within two minutes
 * are merged, so a long Free Translate run is one line, not hundreds.
 *
 * Rows older than 30 days are removed once a day. Never store page text,
 * keys or personal data here.
 */
final class Activity {

	public const TRANSLATED = 'translated';
	public const EDITED     = 'edited';
	public const APPROVED   = 'approved';
	public const FAILED     = 'failed';
	public const ERROR      = 'error';
	public const BULK       = 'bulk';
	public const AUTOMATION = 'automation';

	private const MERGE_SECONDS = 120;
	private const PRUNE_FLAG    = 'lango7_activity_pruned';
	public const PER_PAGE       = 50;

	/**
	 * @param array{lang?: string, url?: string, engine?: string, items?: int, chars?: int, message?: string} $f
	 */
	public static function record( string $type, array $f = array() ): void {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		$table = Schema::table( 'activity' );
		$row   = array(
			'type'    => substr( sanitize_key( $type ), 0, 20 ),
			'lang'    => substr( (string) ( $f['lang'] ?? '' ), 0, 20 ),
			'url'     => substr( self::path( (string) ( $f['url'] ?? '' ) ), 0, 1024 ),
			'engine'  => substr( (string) ( $f['engine'] ?? '' ), 0, 40 ),
			'items'   => max( 0, (int) ( $f['items'] ?? 0 ) ),
			'chars'   => max( 0, (int) ( $f['chars'] ?? 0 ) ),
			'message' => substr( (string) ( $f['message'] ?? '' ), 0, 255 ),
			'user_id' => get_current_user_id(),
		);
		$now = current_time( 'mysql', true );

		if ( '' === $row['message'] ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE type = %s AND lang = %s AND url = %s AND engine = %s AND user_id = %d AND message = %s AND updated_at >= %s ORDER BY id DESC LIMIT 1',
					$table,
					$row['type'],
					$row['lang'],
					$row['url'],
					$row['engine'],
					$row['user_id'],
					'',
					gmdate( 'Y-m-d H:i:s', time() - self::MERGE_SECONDS )
				)
			);
			if ( $id ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET items = items + %d, chars = chars + %d, updated_at = %s WHERE id = %d', $table, $row['items'], $row['chars'], $now, $id ) );
				return;
			}
		}
		$wpdb->insert( $table, $row + array( 'created_at' => $now, 'updated_at' => $now ) );
		self::prune();
	}

	/**
	 * @param array{type?: string, lang?: string} $filter
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $filter = array(), int $page = 1 ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		$table = Schema::table( 'activity' );
		$type  = (string) ( $filter['type'] ?? '' );
		$lang  = (string) ( $filter['lang'] ?? '' );
		// '' matches every row for an empty filter.
		$where = $wpdb->prepare( '( %s = \'\' OR type = %s ) AND ( %s = \'\' OR lang = %s )', $type, $type, $lang, $lang );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ', $table ) . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared above.
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ', $table ) . $where . $wpdb->prepare( ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d', self::PER_PAGE, max( 0, $page - 1 ) * self::PER_PAGE ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every part is prepared.
			ARRAY_A
		);
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * The newest row, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest(): ?array {
		$rows = self::query()['rows'];
		return $rows[0] ?? null;
	}

	public static function clear(): void {
		global $wpdb;
		if ( self::ready() ) {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::table( 'activity' ) ) );
		}
	}

	/**
	 * How many days rows are kept.
	 */
	public static function days(): int {
		/**
		 * Days of activity to keep.
		 *
		 * @param int $days
		 */
		return max( 1, (int) apply_filters( 'lango7_activity_days', 30 ) );
	}

	private static function prune(): void {
		global $wpdb;
		if ( get_transient( self::PRUNE_FLAG ) ) {
			return;
		}
		set_transient( self::PRUNE_FLAG, 1, DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s', Schema::table( 'activity' ), gmdate( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The table exists once the database is on version 2.
	 */
	private static function ready(): bool {
		return version_compare( (string) get_option( Schema::VERSION_OPTION, '0' ), '2', '>=' );
	}

	/**
	 * Store a path, not a full URL: /pricing/ rather than https://site/cn/pricing/.
	 */
	private static function path( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $path ? '/' : $path;
	}
}
