<?php
/**
 * Orders list integration: a status column and bulk include/exclude actions, so the
 * request loop can be curated without opening each order.
 *
 * Registered against both the legacy posts table and the HPOS orders table.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Orders_List {

	const COLUMN = 'rrfw_status';

	public static function init() {
		// Legacy post-based orders screen.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 20, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ), 20 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_actions' ), 20, 3 );

		// HPOS orders screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 20, 2 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ), 20 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_actions' ), 20, 3 );

		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	public static function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			// Sit just after the order status so the two read together.
			if ( 'order_status' === $key ) {
				$new[ self::COLUMN ] = __( 'Review request', 'review-requests-for-woocommerce' );
			}
		}

		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'Review request', 'review-requests-for-woocommerce' );
		}

		return $new;
	}

	public static function render_column( $column, $post_or_order ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$email = $order->get_billing_email();

		if ( $email && RRFW_Suppression::is_suppressed( $email ) ) {
			self::badge( __( 'Unsubscribed', 'review-requests-for-woocommerce' ), '#b32d2e' );
			return;
		}

		if ( $order->get_meta( RRFW_Scheduler::META_CONFIRMED ) ) {
			self::badge( __( 'Reviewed', 'review-requests-for-woocommerce' ), '#1d7a3f' );
			return;
		}

		if ( $order->get_meta( RRFW_Scheduler::META_CLICKED ) ) {
			self::badge( __( 'Clicked', 'review-requests-for-woocommerce' ), '#1d7a3f' );
			return;
		}

		if ( 'yes' !== $order->get_meta( RRFW_Scheduler::META_ENROLLED ) ) {
			self::badge( __( 'Excluded', 'review-requests-for-woocommerce' ), '#888' );
			return;
		}

		$count = (int) $order->get_meta( RRFW_Scheduler::META_COUNT );

		if ( $count > 0 ) {
			self::badge(
				sprintf(
					/* translators: %d: number of requests sent */
					_n( 'Sent %d', 'Sent %d', $count, 'review-requests-for-woocommerce' ),
					$count
				),
				'#2271b1'
			);
			return;
		}

		$next = $order->get_meta( RRFW_Scheduler::META_NEXT_SEND );

		if ( $next ) {
			self::badge( __( 'Scheduled', 'review-requests-for-woocommerce' ), '#996800' );
			echo '<br /><span style="font-size:11px;color:#888;">' . esc_html( gmdate( 'j M', strtotime( $next ) ) ) . '</span>';
			return;
		}

		self::badge( __( 'Included', 'review-requests-for-woocommerce' ), '#2271b1' );
	}

	private static function badge( $text, $colour ) {
		printf(
			'<span style="display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;background:%1$s;color:#fff;">%2$s</span>',
			esc_attr( $colour ),
			esc_html( $text )
		);
	}

	public static function add_bulk_actions( $actions ) {
		$actions['rrfw_include'] = __( 'Include in review requests', 'review-requests-for-woocommerce' );
		$actions['rrfw_exclude'] = __( 'Exclude from review requests', 'review-requests-for-woocommerce' );
		return $actions;
	}

	/**
	 * Including an order that is already completed schedules it straight away; excluding
	 * cancels anything pending. Orders outside the consent window are counted as skipped
	 * rather than silently enrolled.
	 */
	public static function handle_bulk_actions( $redirect_to, $action, $ids ) {
		if ( ! in_array( $action, array( 'rrfw_include', 'rrfw_exclude' ), true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect_to;
		}

		$changed = 0;
		$skipped = 0;

		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}

			if ( 'rrfw_exclude' === $action ) {
				$order->update_meta_data( RRFW_Scheduler::META_ENROLLED, 'no' );
				$order->update_meta_data( RRFW_Scheduler::META_NEXT_SEND, '' );
				$order->update_meta_data( RRFW_Scheduler::META_STATUS, 'excluded' );
				$order->save();
				RRFW_Scheduler::cancel_for_order( $order->get_id() );
				$changed++;
				continue;
			}

			$order->update_meta_data( RRFW_Scheduler::META_ENROLLED, 'yes' );
			$order->save();

			$reason = RRFW_Scheduler::ineligible_reason( $order );

			// "Plugin is disabled" is a deliberate hold, not a property of the order:
			// enrol it now so it is ready when sending is switched on.
			$is_hold = ( null !== $reason ) && RRFW_Scheduler::is_temporary_hold( $reason );

			if ( null !== $reason && ! $is_hold ) {
				$order->update_meta_data( RRFW_Scheduler::META_STATUS, 'not eligible' );
				$order->save();
				$skipped++;
				continue;
			}

			if ( ! $is_hold && $order->has_status( 'completed' ) ) {
				RRFW_Scheduler::schedule_next( $order, (int) $order->get_meta( RRFW_Scheduler::META_COUNT ) );
			}

			$changed++;
		}

		return add_query_arg(
			array(
				'rrfw_bulk'    => $action,
				'rrfw_changed' => $changed,
				'rrfw_skipped' => $skipped,
			),
			$redirect_to
		);
	}

	public static function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice.
		if ( empty( $_GET['rrfw_bulk'] ) ) {
			return;
		}

		$changed = isset( $_GET['rrfw_changed'] ) ? absint( $_GET['rrfw_changed'] ) : 0;
		$skipped = isset( $_GET['rrfw_skipped'] ) ? absint( $_GET['rrfw_skipped'] ) : 0;
		$action  = sanitize_key( wp_unslash( $_GET['rrfw_bulk'] ) );
		// phpcs:enable

		$message = ( 'rrfw_exclude' === $action )
			/* translators: %d: number of orders */
			? sprintf( _n( '%d order excluded from review requests.', '%d orders excluded from review requests.', $changed, 'review-requests-for-woocommerce' ), $changed )
			/* translators: %d: number of orders */
			: sprintf( _n( '%d order included in review requests.', '%d orders included in review requests.', $changed, 'review-requests-for-woocommerce' ), $changed );

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders skipped */
				_n( '%d was not eligible (refunded, unsubscribed, or outside the consent window).', '%d were not eligible (refunded, unsubscribed, or outside the consent window).', $skipped, 'review-requests-for-woocommerce' ),
				$skipped
			);
		}

		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
