<?php
/**
 * Custom tables.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7\Store;

/**
 * Every source string is stored once; translations hold one row per string,
 * language and scope. Machine output and human edits share a table and differ
 * only by status, and locked = 1 rows are never overwritten by a machine.
 */
final class Schema {

	public const VERSION_OPTION = 'lango7_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'lango7_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$strings  = self::table( 'strings' );
		$trans    = self::table( 'translations' );
		$urls     = self::table( 'string_urls' );
		$activity = self::table( 'activity' );

		// dbDelta wants each column on its own line and two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$strings} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hash char(40) NOT NULL,
  source_lang varchar(20) NOT NULL,
  source_text longtext NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'text',
  chars int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash)
) {$charset};
CREATE TABLE {$trans} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  string_id bigint(20) unsigned NOT NULL,
  lang varchar(20) NOT NULL,
  scope char(40) NOT NULL DEFAULT '',
  translated longtext NOT NULL,
  status varchar(20) NOT NULL,
  locked tinyint(1) NOT NULL DEFAULT 0,
  engine varchar(40) DEFAULT NULL,
  edited_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY one_per_scope (string_id,lang,scope),
  KEY lang_status (lang,status)
) {$charset};
CREATE TABLE {$urls} (
  string_id bigint(20) unsigned NOT NULL,
  url_hash char(40) NOT NULL,
  url_path varchar(2048) NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (url_hash,string_id),
  KEY string_id (string_id)
) {$charset};
CREATE TABLE {$activity} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL,
  lang varchar(20) NOT NULL DEFAULT '',
  url varchar(1024) NOT NULL DEFAULT '',
  engine varchar(40) NOT NULL DEFAULT '',
  items int(10) unsigned NOT NULL DEFAULT 0,
  chars int(10) unsigned NOT NULL DEFAULT 0,
  message varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY updated_at (updated_at),
  KEY type_lang (type,lang)
) {$charset};"
		);

		update_option( self::VERSION_OPTION, LANGO7_DB_VERSION );
	}

	public static function maybeUpgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== LANGO7_DB_VERSION ) {
			self::install();
		}
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( array( 'strings', 'translations', 'string_urls', 'glossary', 'slugs', 'activity' ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( self::VERSION_OPTION );
	}
}
