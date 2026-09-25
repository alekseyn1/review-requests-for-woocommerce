<?php
/**
 * Activation, schema, and the signing secret.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Install {

	const DB_VERSION_OPTION = 'rrfw_db_version';
	const DB_VERSION        = 1;
	const SECRET_OPTION     = 'rrfw_secret';

	public static function activate() {
		self::create_tables();
		self::ensure_secret();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function deactivate() {
		// Cancel anything still queued so a deactivated plugin never mails a customer.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( RRFW_Scheduler::HOOK, array(), RRFW_Scheduler::GROUP );
		}
	}

	public static function maybe_upgrade() {
		RRFW_Settings::upgrade_variants();

		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			self::ensure_secret();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Suppression list. Deliberately keyed on the email address rather than the order,
	 * because an opt-out must outlive any single order.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::suppression_table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL DEFAULT '',
			reason VARCHAR(40) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY email (email),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
	}

	public static function suppression_table() {
		global $wpdb;
		return $wpdb->prefix . 'rrfw_suppressions';
	}

	/**
	 * Per-site HMAC secret for unsubscribe and click links. Nonces are useless here:
	 * they expire in 24h and are tied to a logged-in session.
	 */
	public static function ensure_secret() {
		$secret = get_option( self::SECRET_OPTION );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, true );
		}
		return $secret;
	}
}
