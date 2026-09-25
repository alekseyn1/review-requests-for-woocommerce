<?php
/**
 * Admin surface: the settings page and the per-order control box.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Admin {

	public static function init() {
		add_action( 'admin_init', array( 'RRFW_Settings', 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50, 1 );
		add_action( 'wp_ajax_rrfw_send_now', array( __CLASS__, 'ajax_send_now' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Review Requests', 'review-requests-for-woocommerce' ),
			__( 'Review Requests', 'review-requests-for-woocommerce' ),
			'manage_woocommerce',
			'rrfw-settings',
			array( __CLASS__, 'settings_page' )
		);
	}

	/**
	 * Registered against both the legacy post screen and the HPOS orders screen, so the
	 * box keeps working if custom order tables are switched on later.
	 */
	public static function add_meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'rrfw_review_request',
				__( 'Google review request', 'review-requests-for-woocommerce' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public static function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;

		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Not available for this order.', 'review-requests-for-woocommerce' ) . '</p>';
			return;
		}

		wp_nonce_field( 'rrfw_save_order_' . $order->get_id(), 'rrfw_nonce' );

		$meta_enrolled = $order->get_meta( RRFW_Scheduler::META_ENROLLED );

		// No decision recorded yet. In auto mode the order WILL be enrolled the moment it
		// completes, so show it as included. Rendering it unchecked was a live bug: setting
		// an order to Completed on this screen posts an unchecked box, and save_meta_box
		// then writes 'no' in the same request that auto-enrolment wrote 'yes' — silently
		// cancelling the send. Every admin-completed order was dropped this way.
		// An explicit 'yes'/'no' always wins; ineligible_reason() still requires 'yes'.
		if ( '' === $meta_enrolled ) {
			$enrolled = ( 'auto' === RRFW_Settings::get( 'enrolment_mode' ) );
		} else {
			$enrolled = ( 'yes' === $meta_enrolled );
		}
		$count     = (int) $order->get_meta( RRFW_Scheduler::META_COUNT );
		$status    = $order->get_meta( RRFW_Scheduler::META_STATUS );
		$last_sent = $order->get_meta( RRFW_Scheduler::META_LAST_SENT );
		$next_send = $order->get_meta( RRFW_Scheduler::META_NEXT_SEND );
		$clicked   = $order->get_meta( RRFW_Scheduler::META_CLICKED );
		$confirmed = $order->get_meta( RRFW_Scheduler::META_CONFIRMED );
		$email     = $order->get_billing_email();
		$blocked   = RRFW_Scheduler::ineligible_reason( $order );

		echo '<p><strong>' . esc_html__( 'Status:', 'review-requests-for-woocommerce' ) . '</strong> ';
		echo esc_html( $status ? $status : __( 'not started', 'review-requests-for-woocommerce' ) );
		echo '</p>';

		echo '<ul style="margin:0 0 10px;font-size:12px;line-height:1.7;">';
		printf( '<li>%s <strong>%d</strong></li>', esc_html__( 'Requests sent:', 'review-requests-for-woocommerce' ), $count );

		if ( $last_sent ) {
			printf( '<li>%s %s</li>', esc_html__( 'Last sent:', 'review-requests-for-woocommerce' ), esc_html( $last_sent ) );
		}
		if ( $next_send ) {
			printf( '<li>%s %s UTC</li>', esc_html__( 'Next:', 'review-requests-for-woocommerce' ), esc_html( $next_send ) );
		}
		if ( $clicked ) {
			printf( '<li style="color:#1d7a3f;">%s %s</li>', esc_html__( 'Clicked through:', 'review-requests-for-woocommerce' ), esc_html( $clicked ) );
		}
		if ( $confirmed ) {
			printf( '<li style="color:#1d7a3f;"><strong>%s</strong> %s</li>', esc_html__( 'Confirmed review:', 'review-requests-for-woocommerce' ), esc_html( $confirmed ) );
		}
		if ( $email && RRFW_Suppression::is_suppressed( $email ) ) {
			printf( '<li style="color:#b32d2e;"><strong>%s</strong></li>', esc_html__( 'Customer has unsubscribed', 'review-requests-for-woocommerce' ) );
		}
		echo '</ul>';

		echo '<p><label><input type="checkbox" name="rrfw_enrolled" value="yes" ' . checked( $enrolled, true, false ) . ' /> ';
		echo esc_html__( 'Include this order in review requests', 'review-requests-for-woocommerce' ) . '</label></p>';

		// Records the state this form was rendered with, so save_meta_box can tell a
		// deliberate change from a stale tab submitted after the state moved on.
		echo '<input type="hidden" name="rrfw_enrolled_was" value="' . ( $enrolled ? 'yes' : 'no' ) . '" />';

		if ( 'manual' === RRFW_Settings::get( 'enrolment_mode' ) ) {
			echo '<p style="font-size:11px;color:#666;margin-top:-6px;">' .
				esc_html__( 'Manual mode: orders are only asked when you tick this.', 'review-requests-for-woocommerce' ) . '</p>';
		}

		if ( null !== $blocked ) {
			echo '<p style="color:#b32d2e;font-size:12px;margin:0 0 8px;">';
			printf(
				/* translators: %s: reason sending is blocked */
				esc_html__( 'Cannot send: %s.', 'review-requests-for-woocommerce' ),
				esc_html( $blocked )
			);
			echo '</p>';
		}

		$disabled = ( null !== $blocked ) ? ' disabled="disabled"' : '';

		echo '<p><button type="button" class="button button-primary" id="rrfw-send-now" data-order="' . esc_attr( $order->get_id() ) . '"' . $disabled . '>';
		echo esc_html__( 'Send review request now', 'review-requests-for-woocommerce' );
		echo '</button> <span id="rrfw-send-result" style="font-size:12px;"></span></p>';

		if ( RRFW_Settings::is_dry_run() ) {
			echo '<p style="font-size:11px;color:#996800;">' . esc_html__( 'Dry-run mode is on — nothing is actually emailed.', 'review-requests-for-woocommerce' ) . '</p>';
		}

		self::render_probable_match( $order );
		self::render_inline_script();
	}

	/**
	 * A soft reconciliation hint only. Optional, and off unless a supported review table
	 * is present.
	 *
	 * Google exposes no reviewer email and abbreviates surnames, so this can never be an
	 * authoritative join between a review and an order. It surfaces a candidate for a
	 * human to eyeball, nothing more.
	 *
	 * Out of the box it reads WP Review Slider Pro's table if that plugin is installed.
	 * Point it at a different table with the rrfw_review_lookup_table filter, which must
	 * expose reviewer_name, created_time and rating columns. Return an empty string to
	 * switch the panel off entirely.
	 */
	private static function render_probable_match( $order ) {
		global $wpdb;

		$table = apply_filters( 'rrfw_review_lookup_table', $wpdb->prefix . 'wpfb_reviews', $order );

		if ( ! is_string( $table ) || '' === $table ) {
			return;
		}

		// Only ever a plugin-prefixed table name; never interpolate caller input unchecked.
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return;
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$first_name = trim( (string) $order->get_billing_first_name() );
		if ( '' === $first_name ) {
			return;
		}

		$completed = $order->get_date_completed();
		$since     = $completed ? gmdate( 'Y-m-d H:i:s', $completed->getTimestamp() ) : '1970-01-01 00:00:00';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reviewer_name, created_time, rating FROM {$table} WHERE reviewer_name LIKE %s AND created_time >= %s ORDER BY created_time DESC LIMIT 3", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $first_name ) . '%',
				$since
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		echo '<hr /><p style="font-size:12px;margin-bottom:4px;"><strong>' . esc_html__( 'Possible matching review', 'review-requests-for-woocommerce' ) . '</strong><br />';
		echo '<span style="color:#666;">' . esc_html__( 'Name-based guess only — verify before trusting.', 'review-requests-for-woocommerce' ) . '</span></p>';
		echo '<ul style="font-size:12px;margin:0;">';

		foreach ( $rows as $row ) {
			printf(
				'<li>%s — %s (%s%s)</li>',
				esc_html( $row->reviewer_name ),
				esc_html( gmdate( 'Y-m-d', strtotime( $row->created_time ) ) ),
				esc_html( (string) $row->rating ),
				esc_html__( ' stars', 'review-requests-for-woocommerce' )
			);
		}

		echo '</ul>';
	}

	private static function render_inline_script() {
		$ajax_nonce = wp_create_nonce( 'rrfw_send_now' );
		?>
		<script>
		( function () {
			var btn = document.getElementById( 'rrfw-send-now' );
			if ( ! btn || btn.dataset.rrfwBound ) { return; }
			btn.dataset.rrfwBound = '1';
			btn.addEventListener( 'click', function () {
				var out = document.getElementById( 'rrfw-send-result' );
				btn.disabled = true;
				out.textContent = <?php echo wp_json_encode( __( 'Sending…', 'review-requests-for-woocommerce' ) ); ?>;
				var body = new FormData();
				body.append( 'action', 'rrfw_send_now' );
				body.append( 'order_id', btn.dataset.order );
				body.append( '_wpnonce', <?php echo wp_json_encode( $ajax_nonce ); ?> );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						out.textContent = res.data && res.data.message ? res.data.message : '';
						out.style.color = res.success ? '#1d7a3f' : '#b32d2e';
						if ( res.success ) { setTimeout( function () { location.reload(); }, 900 ); }
						else { btn.disabled = false; }
					} )
					.catch( function () {
						out.textContent = <?php echo wp_json_encode( __( 'Request failed.', 'review-requests-for-woocommerce' ) ); ?>;
						out.style.color = '#b32d2e';
						btn.disabled = false;
					} );
			} );
		}() );
		</script>
		<?php
	}

	public static function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['rrfw_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rrfw_nonce'] ) ), 'rrfw_save_order_' . $order_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$previous = $order->get_meta( RRFW_Scheduler::META_ENROLLED );

		// Effective state right now, using the same auto-mode default the box renders with.
		$current = ( '' === $previous )
			? ( 'auto' === RRFW_Settings::get( 'enrolment_mode' ) ? 'yes' : 'no' )
			: $previous;

		$rendered = isset( $_POST['rrfw_enrolled_was'] )
			? sanitize_key( wp_unslash( $_POST['rrfw_enrolled_was'] ) )
			: '';

		// Optimistic concurrency. If the state moved since this form was rendered, the tab
		// is stale — applying it would silently undo whatever changed in between. This is
		// how an order enrolled on 9 Sep came back un-enrolled: a pre-fix tab, submitted
		// after the fix, posted an unchecked box.
		if ( '' !== $rendered && $rendered !== $current ) {
			$order->add_order_note(
				__( 'Review request setting not changed: the order screen was out of date. Reload and try again.', 'review-requests-for-woocommerce' )
			);
			return;
		}

		$enrolled = isset( $_POST['rrfw_enrolled'] ) ? 'yes' : 'no';

		$order->update_meta_data( RRFW_Scheduler::META_ENROLLED, $enrolled );
		$order->save();

		if ( 'no' === $enrolled && 'no' !== $previous ) {
			RRFW_Scheduler::cancel_for_order( $order_id );
			$order->update_meta_data( RRFW_Scheduler::META_NEXT_SEND, '' );
			$order->update_meta_data( RRFW_Scheduler::META_STATUS, 'stopped' );
			$order->save();
		}

		if ( 'yes' === $enrolled && 'yes' !== $previous && $order->has_status( 'completed' ) ) {
			RRFW_Scheduler::schedule_next( $order, (int) $order->get_meta( RRFW_Scheduler::META_COUNT ) );
		}
	}

	public static function ajax_send_now() {
		check_ajax_referer( 'rrfw_send_now' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'review-requests-for-woocommerce' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'review-requests-for-woocommerce' ) ), 404 );
		}

		$result = RRFW_Scheduler::send( $order, (int) $order->get_meta( RRFW_Scheduler::META_COUNT ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Sent.', 'review-requests-for-woocommerce' ) ) );
	}

	/**
	 * Email wording, then the ordered list of category rules. Rendered last on the screen
	 * because the rule list grows without bound and would otherwise push the operational
	 * settings off the fold.
	 */
	private static function render_wording_rows( $s ) {
		?>
		<tr><td colspan="2" style="padding:26px 0 0;">
			<h2 style="margin:0;"><?php esc_html_e( 'Email wording', 'review-requests-for-woocommerce' ); ?></h2>
			<p class="description" style="margin:4px 0 0;">
				<?php esc_html_e( 'Leave blank to use the wording the plugin ships with. Blank lines start new paragraphs. Placeholders: {customer_name}, {order_number}, {site_title}. Subject and heading live in WooCommerce > Settings > Emails > Review request.', 'review-requests-for-woocommerce' ); ?>
			</p>
		</td></tr>
		<tr>
			<th scope="row"><label for="rrfw_body_first"><?php esc_html_e( 'First request', 'review-requests-for-woocommerce' ); ?></label></th>
			<td><textarea id="rrfw_body_first" name="rrfw_settings[body_first]" rows="5" class="large-text" placeholder="<?php echo esc_attr( RRFW_Settings::default_copy( 'body_first' ) ); ?>"><?php echo esc_textarea( $s['body_first'] ); ?></textarea></td>
		</tr>
		<tr>
			<th scope="row"><label for="rrfw_body_reminder"><?php esc_html_e( 'Reminder', 'review-requests-for-woocommerce' ); ?></label></th>
			<td><textarea id="rrfw_body_reminder" name="rrfw_settings[body_reminder]" rows="3" class="large-text" placeholder="<?php echo esc_attr( RRFW_Settings::default_copy( 'body_reminder' ) ); ?>"><?php echo esc_textarea( $s['body_reminder'] ); ?></textarea></td>
		</tr>

		<tr><td colspan="2" style="padding:26px 0 0;">
			<h2 style="margin:0;"><?php esc_html_e( 'Wording by product category', 'review-requests-for-woocommerce' ); ?></h2>
			<p class="description" style="margin:4px 0 8px;">
				<?php esc_html_e( 'Send different wording when an order contains something from a given category. Child categories count. Any field left blank falls back to the wording above.', 'review-requests-for-woocommerce' ); ?>
				<br />
				<?php esc_html_e( 'Rules are checked top to bottom and the first match wins, so put the most specific category first if a product could sit in two.', 'review-requests-for-woocommerce' ); ?>
			</p>
		</td></tr>
		<tr><td colspan="2" style="padding-top:0;">
			<div id="rrfw-variants">
				<?php
				$variants = (array) ( $s['variants'] ?? array() );
				$index    = 0;

				foreach ( $variants as $variant ) {
					self::render_variant_row( $index, (array) $variant );
					$index++;
				}

				// Always one empty row so a rule can be added without any scripting.
				self::render_variant_row( $index, array() );
				?>
			</div>
			<p>
				<button type="button" class="button" id="rrfw-add-variant"><?php esc_html_e( 'Add another rule', 'review-requests-for-woocommerce' ); ?></button>
			</p>
		</td></tr>
		<?php
		self::render_variant_script( $index );
	}

	private static function render_variant_row( $index, $variant ) {
		$category = (string) ( $variant['category'] ?? '' );
		$filled   = ( '' !== $category );
		?>
		<fieldset class="rrfw-variant" data-index="<?php echo esc_attr( $index ); ?>"
			style="border:1px solid #dcdcde;background:#fff;padding:12px 16px;margin:0 0 10px;max-width:760px;">
			<p style="margin:0 0 8px;">
				<label style="font-weight:600;margin-right:6px;"><?php esc_html_e( 'Category', 'review-requests-for-woocommerce' ); ?></label>
				<?php
				wp_dropdown_categories(
					array(
						'taxonomy'          => 'product_cat',
						'name'              => 'rrfw_settings[variants][' . $index . '][category]',
						'selected'          => $category,
						'value_field'       => 'slug',
						'hierarchical'      => true,
						'hide_empty'        => false,
						'show_option_none'  => __( '— select a category —', 'review-requests-for-woocommerce' ),
						'option_none_value' => '',
					)
				);
				?>
				<?php if ( $filled ) : ?>
					<label style="margin-left:14px;color:#b32d2e;">
						<input type="checkbox" name="rrfw_settings[variants][<?php echo esc_attr( $index ); ?>][remove]" value="1" />
						<?php esc_html_e( 'Remove this rule on save', 'review-requests-for-woocommerce' ); ?>
					</label>
				<?php endif; ?>
			</p>
			<p style="margin:0 0 6px;">
				<input type="text" class="large-text" name="rrfw_settings[variants][<?php echo esc_attr( $index ); ?>][subject]"
					value="<?php echo esc_attr( (string) ( $variant['subject'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Subject — blank uses the standard subject', 'review-requests-for-woocommerce' ); ?>" />
			</p>
			<p style="margin:0 0 6px;">
				<textarea class="large-text" rows="4" name="rrfw_settings[variants][<?php echo esc_attr( $index ); ?>][body_first]"
					placeholder="<?php esc_attr_e( 'First request body', 'review-requests-for-woocommerce' ); ?>"><?php echo esc_textarea( (string) ( $variant['body_first'] ?? '' ) ); ?></textarea>
			</p>
			<p style="margin:0;">
				<textarea class="large-text" rows="3" name="rrfw_settings[variants][<?php echo esc_attr( $index ); ?>][body_reminder]"
					placeholder="<?php esc_attr_e( 'Reminder body', 'review-requests-for-woocommerce' ); ?>"><?php echo esc_textarea( (string) ( $variant['body_reminder'] ?? '' ) ); ?></textarea>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Clones the trailing blank row. Without scripting the page still works — saving a
	 * filled row re-renders with a fresh blank one.
	 */
	private static function render_variant_script( $next_index ) {
		?>
		<script>
		( function () {
			var btn = document.getElementById( 'rrfw-add-variant' );
			var box = document.getElementById( 'rrfw-variants' );
			if ( ! btn || ! box || btn.dataset.rrfwBound ) { return; }
			btn.dataset.rrfwBound = '1';
			var next = <?php echo (int) $next_index; ?> + 1;
			btn.addEventListener( 'click', function () {
				var last = box.querySelector( '.rrfw-variant:last-of-type' );
				if ( ! last ) { return; }
				var copy = last.cloneNode( true );
				copy.dataset.index = next;
				copy.querySelectorAll( '[name]' ).forEach( function ( el ) {
					el.name = el.name.replace( /\[variants\]\[\d+\]/, '[variants][' + next + ']' );
					if ( el.tagName === 'SELECT' ) { el.selectedIndex = 0; }
					else if ( el.type === 'checkbox' ) { el.checked = false; }
					else { el.value = ''; }
				} );
				box.appendChild( copy );
				next++;
			} );
		}() );
		</script>
		<?php
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s = RRFW_Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review Requests', 'review-requests-for-woocommerce' ); ?></h1>

			<?php if ( '' === trim( (string) $s['business_address'] ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Add your postal address below — CAN-SPAM, CASL and most equivalent regimes require one in every commercial email.', 'review-requests-for-woocommerce' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'rrfw_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable sending', 'review-requests-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="rrfw_settings[enabled]" value="yes" <?php checked( $s['enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Master switch for all review requests', 'review-requests-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dry run', 'review-requests-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="rrfw_settings[dry_run]" value="yes" <?php checked( $s['dry_run'], 'yes' ); ?> />
								<?php esc_html_e( 'Log to the order notes instead of emailing. Leave on until you have tested.', 'review-requests-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who gets asked', 'review-requests-for-woocommerce' ); ?></th>
						<td>
							<label><input type="radio" name="rrfw_settings[enrolment_mode]" value="auto" <?php checked( $s['enrolment_mode'], 'auto' ); ?> />
								<?php esc_html_e( 'Automatic — every completed order is included unless I exclude it', 'review-requests-for-woocommerce' ); ?></label><br />
							<label><input type="radio" name="rrfw_settings[enrolment_mode]" value="manual" <?php checked( $s['enrolment_mode'], 'manual' ); ?> />
								<?php esc_html_e( 'Manual — nothing is sent until I pick the orders myself', 'review-requests-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Either way you can include or exclude individual orders from the order screen, or several at once from the Orders list.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_place_id"><?php esc_html_e( 'Google Place ID', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="rrfw_place_id" name="rrfw_settings[place_id]" value="<?php echo esc_attr( $s['place_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Customers are sent to search.google.com/local/writereview for this place.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Send from', 'review-requests-for-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="rrfw_settings[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'woocommerce_email_from_name' ) ); ?>" />
							<br />
							<input type="email" class="regular-text" name="rrfw_settings[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'woocommerce_email_from_address' ) ); ?>" style="margin-top:4px;" />
							<p class="description">
								<?php esc_html_e( 'Leave blank to use the WooCommerce sender shown greyed out above. Applies to review requests only.', 'review-requests-for-woocommerce' ); ?>
								<br />
								<strong><?php esc_html_e( 'This site sends through the Gmail API:', 'review-requests-for-woocommerce' ); ?></strong>
								<?php esc_html_e( 'any address used here must be verified in that account under "Send mail as", and the "Force From Email" option in WP Mail SMTP must stay off, or Google will rewrite it.', 'review-requests-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_first_delay"><?php esc_html_e( 'Days after completion', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" min="0" id="rrfw_first_delay" name="rrfw_settings[first_delay_days]" value="<?php echo esc_attr( $s['first_delay_days'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Orders are marked complete at dispatch, so allow for delivery and first use.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_reminders"><?php esc_html_e( 'Reminders', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" min="0" max="3" id="rrfw_reminders" name="rrfw_settings[reminders]" value="<?php echo esc_attr( $s['reminders'] ); ?>" />
							<?php esc_html_e( 'follow-ups, spaced', 'review-requests-for-woocommerce' ); ?>
							<input type="number" min="1" name="rrfw_settings[reminder_gap_days]" value="<?php echo esc_attr( $s['reminder_gap_days'] ); ?>" style="width:70px;" />
							<?php esc_html_e( 'days apart', 'review-requests-for-woocommerce' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_daily_cap"><?php esc_html_e( 'Daily cap', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" min="1" id="rrfw_daily_cap" name="rrfw_settings[daily_cap]" value="<?php echo esc_attr( $s['daily_cap'] ); ?>" />
							<p class="description"><?php esc_html_e( 'A sudden burst of new reviews looks inorganic to Google and risks being filtered.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_max_age"><?php esc_html_e( 'Consent window (days)', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" min="1" max="730" id="rrfw_max_age" name="rrfw_settings[max_order_age_days]" value="<?php echo esc_attr( $s['max_order_age_days'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Orders older than this are never contacted. Default 365, hard-capped at 730 — implied consent from a purchase expires after two years under CASL, the strictest of the common regimes.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_min_total"><?php esc_html_e( 'Minimum order total', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" min="0" step="0.01" id="rrfw_min_total" name="rrfw_settings[min_order_total]" value="<?php echo esc_attr( $s['min_order_total'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Set above zero to skip small accessory-only orders. Zero sends for every order.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rrfw_address"><?php esc_html_e( 'Postal address', 'review-requests-for-woocommerce' ); ?></label></th>
						<td>
							<textarea id="rrfw_address" name="rrfw_settings[business_address]" rows="3" class="large-text"><?php echo esc_textarea( $s['business_address'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Printed in the footer of every request. Legally required.', 'review-requests-for-woocommerce' ); ?></p>
						</td>
					</tr>

					<?php self::render_wording_rows( $s ); ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php RRFW_Report::render(); ?>

			<?php RRFW_Report::render_suppressions(); ?>

		</div>
		<?php
	}
}
