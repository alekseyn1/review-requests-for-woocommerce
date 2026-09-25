<?php
/**
 * One-time migration from the plugin's former identity (prefix `lrr`).
 *
 * Runs once, is idempotent, and reports what it did. The single thing that MUST carry
 * over is the signing secret: unsubscribe and click links in already-delivered emails are
 * HMACs over that key. Generating a fresh one would silently break every outstanding
 * unsubscribe link, which is a compliance failure, not a cosmetic one.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Migrate {

	const FLAG       = 'rrfw_migrated_from_legacy';
	const OLD_HOOK   = 'lrr_send_review_request';
	const OLD_GROUP  = 'lrr';
	const OLD_PLUGIN = 'luxury-review-requests/luxury-review-requests.php';

	/**
	 * Option pairs, old => new. Order is irrelevant; each is copied only if the new name
	 * is unset, so re-running cannot clobber newer values.
	 */
	private static function option_map() {
		return array(
			'lrr_settings'      => 'rrfw_settings',
			'lrr_secret'        => 'rrfw_secret',
			'lrr_db_version'    => 'rrfw_db_version',
			'lrr_daily_counter' => 'rrfw_daily_counter',
		);
	}

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'legacy_plugin_notice' ) );
	}

	/**
	 * Both plugins active at once would double-send: each has its own scheduler listening
	 * on woocommerce_order_status_completed.
	 */
	public static function legacy_plugin_notice() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( self::OLD_PLUGIN ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' .
			esc_html__( 'Review Requests for WooCommerce:', 'review-requests-for-woocommerce' ) .
			'</strong> ' .
			esc_html__( 'the older version of this plugin is still active. Both will send, so customers may receive duplicate requests. Deactivate the old one.', 'review-requests-for-woocommerce' ) .
			'</p></div>';
	}

	public static function needed() {
		if ( get_option( self::FLAG ) ) {
			return false;
		}

		return self::has_legacy_data();
	}

	public static function has_legacy_data() {
		global $wpdb;

		foreach ( array_keys( self::option_map() ) as $old ) {
			if ( false !== get_option( $old, false ) ) {
				return true;
			}
		}

		$legacy_table = $wpdb->prefix . 'lrr_suppressions';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) ) === $legacy_table ) {
			return true;
		}

		$meta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_lrr\_%'" ); // phpcs:ignore

		return $meta > 0;
	}

	public static function maybe_run() {
		if ( ! self::needed() ) {
			return null;
		}

		$report = self::run( false );
		update_option( self::FLAG, gmdate( 'c' ), false );

		return $report;
	}

	/**
	 * @param bool $dry_run When true, counts what would change and writes nothing.
	 * @return array Human-readable report lines keyed by step.
	 */
	public static function run( $dry_run = true ) {
		global $wpdb;

		$report = array( 'dry_run' => $dry_run );

		// 1. Options, secret included.
		$copied = array();
		foreach ( self::option_map() as $old => $new ) {
			$value = get_option( $old, null );

			if ( null === $value ) {
				continue;
			}

			if ( false !== get_option( $new, false ) ) {
				$copied[] = $new . ' (already set, left alone)';
				continue;
			}

			if ( ! $dry_run ) {
				update_option( $new, $value, 'rrfw_secret' === $new );
			}

			$copied[] = $old . ' -> ' . $new;
		}
		$report['options'] = $copied;

		// 2. Suppression table. A rename keeps the rows; if the new table already exists
		// (fresh activation created it) merge instead, since opt-outs must never be lost.
		$old_table = $wpdb->prefix . 'lrr_suppressions';
		$new_table = $wpdb->prefix . 'rrfw_suppressions';
		$old_here  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
		$new_here  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;

		if ( ! $old_here ) {
			$report['suppressions'] = 'no legacy table';
		} elseif ( ! $new_here ) {
			if ( ! $dry_run ) {
				$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" ); // phpcs:ignore
			}
			$report['suppressions'] = 'renamed ' . $old_table . ' -> ' . $new_table;
		} else {
			$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old_table}" ); // phpcs:ignore
			if ( ! $dry_run && $rows ) {
				$wpdb->query( "INSERT IGNORE INTO {$new_table} (email, reason, order_id, created_at) SELECT email, reason, order_id, created_at FROM {$old_table}" ); // phpcs:ignore
			}
			$report['suppressions'] = 'merged ' . $rows . ' row(s) into existing table';
		}

		// 3. Order meta keys, legacy post storage and HPOS alike.
		$report['order_meta'] = array();

		foreach ( self::meta_tables() as $table => $id_col ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE meta_key LIKE '\_lrr\_%'" ); // phpcs:ignore

			if ( ! $count ) {
				continue;
			}

			if ( ! $dry_run ) {
				// Skip any row whose renamed key already exists for that order, so a
				// partial earlier run cannot collide on re-run.
				$wpdb->query( // phpcs:ignore
					"UPDATE {$table} o
					 SET o.meta_key = CONCAT('_rrfw_', SUBSTRING(o.meta_key, 6))
					 WHERE o.meta_key LIKE '\_lrr\_%'
					   AND NOT EXISTS (
					     SELECT 1 FROM (SELECT * FROM {$table}) n
					     WHERE n.{$id_col} = o.{$id_col}
					       AND n.meta_key = CONCAT('_rrfw_', SUBSTRING(o.meta_key, 6))
					   )"
				);
			}

			$report['order_meta'][] = $table . ': ' . $count . ' key(s)';
		}

		// 4. Queued sends. Re-create under the new hook and group at the same moment, then
		// cancel the originals, so nothing is sent twice and nothing is lost.
		$report['scheduled'] = self::migrate_actions( $dry_run );

		return $report;
	}

	private static function meta_tables() {
		global $wpdb;

		$tables = array( $wpdb->postmeta => 'post_id' );

		$hpos = $wpdb->prefix . 'wc_orders_meta';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) {
			$tables[ $hpos ] = 'order_id';
		}

		return $tables;
	}

	private static function migrate_actions( $dry_run ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return 'Action Scheduler unavailable';
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::OLD_HOOK,
				'status'   => 'pending',
				'per_page' => 200,
			),
			'ids'
		);

		if ( empty( $ids ) ) {
			return 'none pending';
		}

		$store = ActionScheduler::store();
		$moved = 0;

		foreach ( $ids as $action_id ) {
			$action = $store->fetch_action( $action_id );

			if ( ! $action ) {
				continue;
			}

			$when = $action->get_schedule()->get_date();

			if ( ! $when ) {
				continue;
			}

			if ( ! $dry_run ) {
				as_schedule_single_action(
					$when->getTimestamp(),
					RRFW_Scheduler::HOOK,
					$action->get_args(),
					RRFW_Scheduler::GROUP
				);
				$store->cancel_action( $action_id );
			}

			$moved++;
		}

		return $moved . ' pending action(s) re-queued';
	}
}
