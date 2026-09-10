<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keep nested Telepilot queue inserts from committing their caller's content lock. */
class Telepilot_DB_Transaction {
	private static $depth = 0;
	public static function begin() {
		global $wpdb;
		$wpdb->query( self::$depth ? 'SAVEPOINT telepilot_' . self::$depth : 'START TRANSACTION' );
		self::$depth++;
	}
	public static function commit() {
		global $wpdb;
		if ( self::$depth < 1 ) { return; }
		self::$depth--;
		$wpdb->query( self::$depth ? 'RELEASE SAVEPOINT telepilot_' . self::$depth : 'COMMIT' );
	}
	public static function rollback() {
		global $wpdb;
		if ( self::$depth < 1 ) { return; }
		self::$depth--;
		$wpdb->query( self::$depth ? 'ROLLBACK TO SAVEPOINT telepilot_' . self::$depth : 'ROLLBACK' );
	}
}
