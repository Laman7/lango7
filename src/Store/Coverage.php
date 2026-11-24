<?php
/**
 * How much of a page is translated: sentences seen on the page against
 * sentences with a translation, per language.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Store;

final class Coverage {

	/**
	 * Counts for these source paths, in two queries. Paths never scanned are left out.
	 *
	 * @param string[] $paths
	 * @return array<string, array{total: int, langs: array<string, array{done: int, edited: int, review: int}>}> sha1(path) => counts.
	 */
	public static function forPaths( array $paths ): array {
		global $wpdb;
		$hashes = array_values( array_unique( array_map( 'sha1', $paths ) ) );
		if ( ! $hashes ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$urls = Schema::table( 'string_urls' );
		$out  = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only; the count includes the spread hashes.
		$totals = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, COUNT(*) AS n FROM %i WHERE url_hash IN ({$in}) GROUP BY url_hash", $urls, ...$hashes ), ARRAY_A );
		foreach ( (array) $totals as $row ) {
			$out[ $row['url_hash'] ] = array(
				'total' => (int) $row['n'],
				'langs' => array(),
			);
		}
		if ( ! $out ) {
			return array();
		}
		// A sentence counts once per language, whether it has a site-wide or a this-page translation.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.url_hash, t.lang,
					COUNT(DISTINCT u.string_id) AS done,
					COUNT(DISTINCT CASE WHEN t.status IN ('edited','ai_edited') THEN u.string_id END) AS edited,
					COUNT(DISTINCT CASE WHEN t.status = 'needs_review' THEN u.string_id END) AS review,
					COUNT(DISTINCT CASE WHEN t.status = 'unapproved' THEN u.string_id END) AS unapproved
				FROM %i u JOIN %i t ON t.string_id = u.string_id AND ( t.scope = '' OR t.scope = u.url_hash ) AND t.status <> 'failed'
				WHERE u.url_hash IN ({$in})
				GROUP BY u.url_hash, t.lang",
				$urls,
				Schema::table( 'translations' ),
				...$hashes
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['url_hash'] ] ) ) {
				$out[ $row['url_hash'] ]['langs'][ $row['lang'] ] = array(
					'done'   => (int) $row['done'],
					'edited' => (int) $row['edited'],
					'review' => (int) $row['review'],
					'unapproved' => (int) $row['unapproved'],
				);
			}
		}
		return $out;
	}

	/**
	 * Whether every enabled language has every sentence of a page.
	 *
	 * @param array{total: int, langs: array<string, array{done: int, edited: int, review: int}>} $counts
	 * @param string[]                                                                                $codes
	 */
	public static function complete( array $counts, array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( ( $counts['langs'][ $code ]['done'] ?? 0 ) < $counts['total'] ) {
				return false;
			}
		}
		return true;
	}
}
