<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keep nested Telepilot queue inserts from committing their caller's content lock. */
class Telepilot_DB_Transaction {
	private static $depth = 0;
	public static function is_active() { return self::$depth > 0; }
	public static function begin() {
		global $wpdb;
		if ( self::$depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue and content writes share a real transaction with nested savepoints.
			$wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', 'telepilot_' . self::$depth ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue and content writes share a real transaction with nested savepoints.
			$wpdb->query( 'START TRANSACTION' );
		}
		self::$depth++;
	}
	public static function commit() {
		global $wpdb;
		if ( self::$depth < 1 ) { return; }
		self::$depth--;
		if ( self::$depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the nested savepoint or commit the owning database transaction.
			$wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', 'telepilot_' . self::$depth ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the nested savepoint or commit the owning database transaction.
			$wpdb->query( 'COMMIT' );
		}
	}
	public static function rollback() {
		global $wpdb;
		if ( self::$depth < 1 ) { return; }
		self::$depth--;
		if ( self::$depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back only the nested savepoint or the owning database transaction.
			$wpdb->query( $wpdb->prepare( 'ROLLBACK TO SAVEPOINT %i', 'telepilot_' . self::$depth ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back only the nested savepoint or the owning database transaction.
			$wpdb->query( 'ROLLBACK' );
		}
	}
}
