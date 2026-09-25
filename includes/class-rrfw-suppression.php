<?php
/**
 * Opt-out list. Keyed on the email address so an unsubscribe applies to the person,
 * not just the order they happened to click from.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Suppression {

	public static function normalise( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	public static function is_suppressed( $email ) {
		global $wpdb;

		$email = self::normalise( $email );
		if ( '' === $email ) {
			return true;
		}

		$table = RRFW_Install::suppression_table();
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return ! empty( $found );
	}

	public static function add( $email, $reason = 'unsubscribe', $order_id = 0 ) {
		global $wpdb;

		$email = self::normalise( $email );
		if ( '' === $email ) {
			return false;
		}

		$table = RRFW_Install::suppression_table();

		// INSERT IGNORE keeps a second unsubscribe click idempotent.
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (email, reason, order_id, created_at) VALUES (%s, %s, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$email,
			sanitize_key( $reason ),
			(int) $order_id,
			current_time( 'mysql', true )
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function remove( $email ) {
		global $wpdb;
		$table = RRFW_Install::suppression_table();
		return $wpdb->delete( $table, array( 'email' => self::normalise( $email ) ), array( '%s' ) );
	}

	public static function count() {
		global $wpdb;
		$table = RRFW_Install::suppression_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}
}
