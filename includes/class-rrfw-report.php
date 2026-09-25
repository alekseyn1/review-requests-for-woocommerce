<?php
/**
 * The request log shown on the settings screen: who has been asked, what happened, and
 * what is still queued.
 *
 * Order lookups go through wc_get_orders() so this keeps working if custom order tables
 * are switched on. Only the grouped counts touch a meta table directly, and those pick
 * the right table for the active storage.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Report {

	const PER_PAGE = 25;

	private static function hpos_active() {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Counts per state, done in SQL so the summary does not need to load every order.
	 */
	public static function counts() {
		global $wpdb;

		if ( self::hpos_active() ) {
			$table  = $wpdb->prefix . 'wc_orders_meta';
			$id_col = 'order_id';
		} else {
			$table  = $wpdb->postmeta;
			$id_col = 'post_id';
		}

		$rows = $wpdb->get_results(
			"SELECT meta_value AS state, COUNT(DISTINCT {$id_col}) AS n
			 FROM {$table} WHERE meta_key = '_rrfw_status' AND meta_value <> ''
			 GROUP BY meta_value" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->state ] = (int) $row->n;
		}

		return $out;
	}

	private static function badge( $text, $colour ) {
		printf(
			'<span style="display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;background:%1$s;color:#fff;white-space:nowrap;">%2$s</span>',
			esc_attr( $colour ),
			esc_html( $text )
		);
	}

	private static function local( $gmt ) {
		if ( empty( $gmt ) ) {
			return '—';
		}
		return get_date_from_gmt( $gmt, 'j M Y, H:i' );
	}

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$state = isset( $_GET['rrfw_state'] ) ? sanitize_key( wp_unslash( $_GET['rrfw_state'] ) ) : '';
		$paged = isset( $_GET['rrfw_page'] ) ? max( 1, absint( wp_unslash( $_GET['rrfw_page'] ) ) ) : 1;
		// phpcs:enable

		$counts = self::counts();
		$total  = array_sum( $counts );

		echo '<h2>' . esc_html__( 'Requests', 'review-requests-for-woocommerce' ) . '</h2>';

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'No review requests yet. Orders appear here once they are enrolled or asked.', 'review-requests-for-woocommerce' ) . '</p>';
			return;
		}

		self::render_filters( $counts, $state );

		$args = array(
			'limit'        => self::PER_PAGE,
			'paged'        => $paged,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'meta_key'     => '_rrfw_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_compare' => 'EXISTS',
			'paginate'     => true,
		);

		if ( '' !== $state ) {
			$args['meta_key']     = '_rrfw_status'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value']   = $state; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_compare'] = '=';
		}

		$results = wc_get_orders( $args );
		$orders  = is_object( $results ) ? $results->orders : $results;
		$pages   = is_object( $results ) ? (int) $results->max_num_pages : 1;

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th style="width:90px;">' . esc_html__( 'Order', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'State', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:50px;">' . esc_html__( 'Sent', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:150px;">' . esc_html__( 'Last sent', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:150px;">' . esc_html__( 'Next', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:170px;">' . esc_html__( 'Outcome', 'review-requests-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			self::render_row( $order );
		}

		echo '</tbody></table>';

		self::render_pagination( $paged, $pages, $state );
	}

	private static function render_filters( $counts, $active ) {
		$labels = self::state_labels();
		$base   = admin_url( 'admin.php?page=rrfw-settings' );

		echo '<ul class="subsubsub" style="margin-bottom:8px;">';

		$all_url = esc_url( $base );
		printf(
			'<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
			$all_url,
			'' === $active ? ' class="current"' : '',
			esc_html__( 'All', 'review-requests-for-woocommerce' ),
			(int) array_sum( $counts )
		);

		foreach ( $counts as $state => $n ) {
			$label = $labels[ $state ][0] ?? ucfirst( str_replace( '-', ' ', $state ) );
			printf(
				' | <li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
				esc_url( add_query_arg( 'rrfw_state', $state, $base ) ),
				$active === $state ? ' class="current"' : '',
				esc_html( $label ),
				(int) $n
			);
		}

		echo '</ul><div style="clear:both;"></div>';
	}

	private static function state_labels() {
		return array(
			'confirmed'          => array( __( 'Reviewed', 'review-requests-for-woocommerce' ), '#1d7a3f' ),
			'clicked'            => array( __( 'Clicked', 'review-requests-for-woocommerce' ), '#1d7a3f' ),
			'sent'               => array( __( 'Sent', 'review-requests-for-woocommerce' ), '#2271b1' ),
			'scheduled'          => array( __( 'Scheduled', 'review-requests-for-woocommerce' ), '#996800' ),
			'on hold'            => array( __( 'On hold', 'review-requests-for-woocommerce' ), '#996800' ),
			'dry-run'            => array( __( 'Dry run', 'review-requests-for-woocommerce' ), '#996800' ),
			'unsubscribed'       => array( __( 'Unsubscribed', 'review-requests-for-woocommerce' ), '#b32d2e' ),
			'stopped'            => array( __( 'Stopped', 'review-requests-for-woocommerce' ), '#888' ),
			'excluded'           => array( __( 'Excluded', 'review-requests-for-woocommerce' ), '#888' ),
			'skipped'            => array( __( 'Skipped', 'review-requests-for-woocommerce' ), '#888' ),
			'finished'           => array( __( 'Finished', 'review-requests-for-woocommerce' ), '#888' ),
			'not eligible'       => array( __( 'Not eligible', 'review-requests-for-woocommerce' ), '#888' ),
			'awaiting selection' => array( __( 'Awaiting selection', 'review-requests-for-woocommerce' ), '#888' ),
		);
	}

	private static function render_row( $order ) {
		$labels = self::state_labels();
		$state  = (string) $order->get_meta( RRFW_Scheduler::META_STATUS );
		$email  = $order->get_billing_email();

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			$name = __( '(no name)', 'review-requests-for-woocommerce' );
		}

		echo '<tr>';

		printf(
			'<td><a href="%s"><strong>#%s</strong></a></td>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() )
		);

		printf(
			'<td>%s<br /><span style="color:#666;font-size:12px;">%s</span></td>',
			esc_html( $name ),
			esc_html( $email )
		);

		echo '<td>';
		$label  = $labels[ $state ][0] ?? ucfirst( str_replace( '-', ' ', $state ) );
		$colour = $labels[ $state ][1] ?? '#888';
		self::badge( $label, $colour );
		echo '</td>';

		printf( '<td>%d</td>', (int) $order->get_meta( RRFW_Scheduler::META_COUNT ) );
		printf( '<td>%s</td>', esc_html( self::local( $order->get_meta( RRFW_Scheduler::META_LAST_SENT ) ) ) );
		printf( '<td>%s</td>', esc_html( self::local( $order->get_meta( RRFW_Scheduler::META_NEXT_SEND ) ) ) );

		echo '<td style="font-size:12px;">';

		$confirmed = $order->get_meta( RRFW_Scheduler::META_CONFIRMED );
		$clicked   = $order->get_meta( RRFW_Scheduler::META_CLICKED );

		if ( $confirmed ) {
			printf(
				'<span style="color:#1d7a3f;">%s %s</span><br />',
				esc_html__( 'Confirmed review', 'review-requests-for-woocommerce' ),
				esc_html( self::local( $confirmed ) )
			);
		}

		if ( $clicked ) {
			printf(
				'<span style="color:#1d7a3f;">%s %s</span><br />',
				esc_html__( 'Clicked through', 'review-requests-for-woocommerce' ),
				esc_html( self::local( $clicked ) )
			);
		}

		if ( $email && RRFW_Suppression::is_suppressed( $email ) ) {
			printf( '<span style="color:#b32d2e;">%s</span>', esc_html__( 'Unsubscribed', 'review-requests-for-woocommerce' ) );
		}

		if ( ! $confirmed && ! $clicked && ( ! $email || ! RRFW_Suppression::is_suppressed( $email ) ) ) {
			echo '<span style="color:#999;">—</span>';
		}

		echo '</td></tr>';
	}

	private static function render_pagination( $paged, $pages, $state ) {
		if ( $pages < 2 ) {
			return;
		}

		$base = admin_url( 'admin.php?page=rrfw-settings' );
		if ( '' !== $state ) {
			$base = add_query_arg( 'rrfw_state', $state, $base );
		}

		echo '<p class="tablenav-pages" style="margin-top:10px;">';

		if ( $paged > 1 ) {
			printf(
				'<a class="button" href="%s">&laquo; %s</a> ',
				esc_url( add_query_arg( 'rrfw_page', $paged - 1, $base ) ),
				esc_html__( 'Previous', 'review-requests-for-woocommerce' )
			);
		}

		printf(
			'<span style="padding:0 8px;">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages */
					__( 'Page %1$d of %2$d', 'review-requests-for-woocommerce' ),
					$paged,
					$pages
				)
			)
		);

		if ( $paged < $pages ) {
			printf(
				'<a class="button" href="%s">%s &raquo;</a>',
				esc_url( add_query_arg( 'rrfw_page', $paged + 1, $base ) ),
				esc_html__( 'Next', 'review-requests-for-woocommerce' )
			);
		}

		echo '</p>';
	}

	/**
	 * Recent opt-outs, with the addresses shown so they can be checked against a
	 * complaint or a support ticket.
	 */
	public static function render_suppressions() {
		global $wpdb;

		$table = RRFW_Install::suppression_table();
		$rows  = $wpdb->get_results( "SELECT email, reason, order_id, created_at FROM {$table} ORDER BY created_at DESC LIMIT 20" ); // phpcs:ignore

		echo '<h2>' . esc_html__( 'Unsubscribes', 'review-requests-for-woocommerce' ) . '</h2>';

		$count = RRFW_Suppression::count();

		if ( 0 === $count ) {
			echo '<p>' . esc_html__( 'Nobody has unsubscribed.', 'review-requests-for-woocommerce' ) . '</p>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of suppressed addresses */
					_n( '%d address will never be contacted.', '%d addresses will never be contacted.', $count, 'review-requests-for-woocommerce' ),
					$count
				)
			)
		);

		echo '<table class="wp-list-table widefat fixed striped" style="max-width:720px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Email', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:130px;">' . esc_html__( 'Reason', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:100px;">' . esc_html__( 'Order', 'review-requests-for-woocommerce' ) . '</th>';
		echo '<th style="width:170px;">' . esc_html__( 'When', 'review-requests-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( (array) $rows as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $row->email ) );
			printf( '<td>%s</td>', esc_html( $row->reason ) );

			if ( $row->order_id ) {
				$order = wc_get_order( $row->order_id );
				printf(
					'<td>%s</td>',
					$order
						? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
						: esc_html( '#' . $row->order_id )
				);
			} else {
				echo '<td>—</td>';
			}

			printf( '<td>%s</td>', esc_html( self::local( $row->created_at ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
