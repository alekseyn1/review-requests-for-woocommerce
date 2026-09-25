<?php
/**
 * Enrolment, scheduling and sending.
 *
 * Built on Action Scheduler rather than wp-cron: it is database backed, survives page
 * caching, retries, and is inspectable under WooCommerce > Status > Scheduled Actions.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Scheduler {

	const HOOK  = 'rrfw_send_review_request';
	const GROUP = 'rrfw';

	const META_ENROLLED  = '_rrfw_enrolled';
	const META_COUNT     = '_rrfw_sent_count';
	const META_LAST_SENT = '_rrfw_last_sent';
	const META_NEXT_SEND = '_rrfw_next_send';
	const META_CLICKED   = '_rrfw_clicked_at';
	const META_CONFIRMED = '_rrfw_confirmed_at';
	const META_STATUS    = '_rrfw_status';

	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 20, 1 );

		// Any exit from "completed" should stop the sequence immediately.
		foreach ( array( 'refunded', 'cancelled', 'failed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'on_order_left_completed' ), 20, 1 );
		}

		add_action( self::HOOK, array( __CLASS__, 'run_scheduled_send' ), 10, 2 );
	}

	/**
	 * Automatic enrolment. Consent rests on the existing business relationship
	 * (CASL implied consent, ePrivacy soft opt-in, CAN-SPAM existing business
	 * relationship), not on a consent checkbox — which would not be valid consent anyway
	 * if it were bundled into terms and conditions.
	 */
	public static function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$existing = $order->get_meta( self::META_ENROLLED );

		if ( 'manual' === RRFW_Settings::get( 'enrolment_mode' ) ) {
			// Manual mode: nothing joins the loop until someone ticks it, either on the
			// order screen or with the Orders list bulk action. An explicit prior yes
			// still stands, so a deliberate choice is never undone by completion.
			if ( 'yes' !== $existing ) {
				$order->update_meta_data( self::META_ENROLLED, 'no' );
				$order->update_meta_data( self::META_STATUS, 'awaiting selection' );
				$order->save();
				return;
			}
		} else {
			// Automatic mode: enrol unless the order was deliberately excluded.
			if ( 'no' === $existing ) {
				return;
			}

			$order->update_meta_data( self::META_ENROLLED, 'yes' );
			$order->save();
		}

		// Record enrolment either way, but do not queue anything while the master switch
		// is off: the action would fire, find the plugin disabled, and mark the order
		// permanently skipped instead of sending once sending is turned on.
		if ( ! RRFW_Settings::is_enabled() ) {
			return;
		}

		self::schedule_next( $order, 0 );
	}

	public static function on_order_left_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Orders that were never enrolled have nothing to stop. Writing a status for them
		// filled the request report with cancelled and failed orders that had never been
		// part of the campaign.
		if ( 'yes' !== $order->get_meta( self::META_ENROLLED ) ) {
			return;
		}

		self::cancel_for_order( $order_id );
		$order->update_meta_data( self::META_STATUS, 'stopped' );
		$order->update_meta_data( self::META_NEXT_SEND, '' );
		$order->save();
	}

	/**
	 * Cancel every pending send for one order.
	 *
	 * Deliberately NOT as_unschedule_all_actions( HOOK, array( 'order_id' => $id ) ):
	 * Action Scheduler matches the args array exactly, and ours also carry an 'attempt'
	 * key, so that call silently matched nothing and left duplicates queued. Fetch the
	 * pending actions and compare the decoded order_id instead.
	 */
	public static function cancel_for_order( $order_id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return 0;
		}

		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 200,
			),
			'ids'
		);

		if ( empty( $action_ids ) ) {
			return 0;
		}

		$store     = ActionScheduler::store();
		$cancelled = 0;

		foreach ( $action_ids as $action_id ) {
			$action = $store->fetch_action( $action_id );

			if ( ! $action ) {
				continue;
			}

			$args = $action->get_args();

			if ( isset( $args['order_id'] ) && (int) $args['order_id'] === (int) $order_id ) {
				$store->cancel_action( $action_id );
				$cancelled++;
			}
		}

		return $cancelled;
	}

	/**
	 * Queue send number $attempt (0 = first request, 1+ = reminders).
	 */
	public static function schedule_next( $order, $attempt ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$settings = RRFW_Settings::all();
		$max      = 1 + (int) $settings['reminders'];

		if ( $attempt >= $max ) {
			$order->update_meta_data( self::META_STATUS, 'finished' );
			$order->update_meta_data( self::META_NEXT_SEND, '' );
			$order->save();
			return false;
		}

		$days = ( 0 === (int) $attempt )
			? (int) $settings['first_delay_days']
			: (int) $settings['reminder_gap_days'];

		// The first request is timed from when the order completed, not from when it was
		// enrolled. Enrolling an old order by hand should not restart the clock — the
		// customer has had the goods for months, so the wait is already served.
		// Reminders are relative to now, because the previous send just went out.
		$base = time();

		if ( 0 === (int) $attempt ) {
			$completed = $order->get_date_completed();

			if ( $completed ) {
				$base = $completed->getTimestamp();
			}
		}

		// Jitter so a batch of same-day orders does not arrive at Google as a burst.
		$timestamp = $base + ( $days * DAY_IN_SECONDS ) + wp_rand( 0, 6 * HOUR_IN_SECONDS );

		if ( $timestamp <= time() ) {
			// Already overdue. Spread these out rather than firing the whole backlog at
			// once; the daily cap then throttles whatever is left over.
			$timestamp = time() + wp_rand( 10 * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS );
		}

		self::cancel_for_order( $order->get_id() );

		as_schedule_single_action(
			$timestamp,
			self::HOOK,
			array(
				'order_id' => $order->get_id(),
				'attempt'  => (int) $attempt,
			),
			self::GROUP
		);

		$order->update_meta_data( self::META_NEXT_SEND, gmdate( 'Y-m-d H:i:s', $timestamp ) );
		$order->update_meta_data( self::META_STATUS, 'scheduled' );
		$order->save();

		return true;
	}

	/**
	 * Action Scheduler entry point. Eligibility is re-checked here, never trusted from
	 * scheduling time — weeks can pass and the order may have been refunded since.
	 */
	public static function run_scheduled_send( $order_id = 0, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$reason = self::ineligible_reason( $order );

		if ( null !== $reason ) {
			// A hold is a property of the shop, not the order: the master switch is off,
			// or the Place ID is missing. Re-queue instead of burning the order, so the
			// backlog resumes when sending is switched back on.
			if ( self::is_temporary_hold( $reason ) ) {
				self::hold( $order, (int) $attempt, $reason );
				return;
			}

			$order->add_order_note(
				sprintf(
					/* translators: %s: reason the review request was skipped */
					__( 'Review request skipped: %s', 'review-requests-for-woocommerce' ),
					$reason
				)
			);
			$order->update_meta_data( self::META_STATUS, 'skipped' );
			$order->update_meta_data( self::META_NEXT_SEND, '' );
			$order->save();
			return;
		}

		// Throttle. Rescheduling beats dropping the send.
		if ( self::daily_cap_reached() ) {
			as_schedule_single_action(
				time() + HOUR_IN_SECONDS + wp_rand( 0, HOUR_IN_SECONDS ),
				self::HOOK,
				array(
					'order_id' => (int) $order_id,
					'attempt'  => (int) $attempt,
				),
				self::GROUP
			);
			return;
		}

		self::send( $order, (int) $attempt );
	}

	/**
	 * Reasons that describe the shop being paused rather than the order being unsuitable.
	 * These must never permanently discard a queued send.
	 */
	public static function is_temporary_hold( $reason ) {
		return in_array(
			$reason,
			array(
				__( 'plugin is disabled', 'review-requests-for-woocommerce' ),
				__( 'no Google Place ID configured', 'review-requests-for-woocommerce' ),
			),
			true
		);
	}

	/**
	 * Park a send and look again tomorrow, without stacking an order note per retry.
	 */
	private static function hold( $order, $attempt, $reason ) {
		$retry_at = time() + DAY_IN_SECONDS;

		self::cancel_for_order( $order->get_id() );

		as_schedule_single_action(
			$retry_at,
			self::HOOK,
			array(
				'order_id' => $order->get_id(),
				'attempt'  => (int) $attempt,
			),
			self::GROUP
		);

		if ( 'on hold' !== $order->get_meta( self::META_STATUS ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: reason sending is paused */
					__( 'Review request on hold: %s. It will be retried automatically.', 'review-requests-for-woocommerce' ),
					$reason
				)
			);
		}

		$order->update_meta_data( self::META_STATUS, 'on hold' );
		$order->update_meta_data( self::META_NEXT_SEND, gmdate( 'Y-m-d H:i:s', $retry_at ) );
		$order->save();
	}

	/**
	 * Returns null when the order may be mailed, otherwise a human readable reason.
	 */
	public static function ineligible_reason( $order ) {
		$settings = RRFW_Settings::all();

		if ( ! RRFW_Settings::is_enabled() ) {
			return __( 'plugin is disabled', 'review-requests-for-woocommerce' );
		}

		if ( '' === RRFW_Settings::review_url() ) {
			return __( 'no Google Place ID configured', 'review-requests-for-woocommerce' );
		}

		if ( 'yes' !== $order->get_meta( self::META_ENROLLED ) ) {
			return __( 'order is not enrolled', 'review-requests-for-woocommerce' );
		}

		if ( ! $order->has_status( 'completed' ) ) {
			return __( 'order is no longer completed', 'review-requests-for-woocommerce' );
		}

		if ( $order->get_total_refunded() > 0 ) {
			return __( 'order has been refunded', 'review-requests-for-woocommerce' );
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return __( 'no valid billing email', 'review-requests-for-woocommerce' );
		}

		if ( RRFW_Suppression::is_suppressed( $email ) ) {
			return __( 'customer has unsubscribed', 'review-requests-for-woocommerce' );
		}

		if ( $order->get_meta( self::META_CONFIRMED ) ) {
			return __( 'customer already confirmed leaving a review', 'review-requests-for-woocommerce' );
		}

		if ( $order->get_meta( self::META_CLICKED ) ) {
			return __( 'customer already followed the review link', 'review-requests-for-woocommerce' );
		}

		$created = $order->get_date_created();
		if ( $created ) {
			$age_days = ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS;
			if ( $age_days > (int) $settings['max_order_age_days'] ) {
				// Implied consent from a purchase lapses; CASL puts that at two years.
				return __( 'order is outside the consent window', 'review-requests-for-woocommerce' );
			}
		}

		if ( (float) $settings['min_order_total'] > 0 && (float) $order->get_total() < (float) $settings['min_order_total'] ) {
			return __( 'order total below the configured minimum', 'review-requests-for-woocommerce' );
		}

		$sent = (int) $order->get_meta( self::META_COUNT );
		if ( $sent >= 1 + (int) $settings['reminders'] ) {
			return __( 'send limit reached', 'review-requests-for-woocommerce' );
		}

		return null;
	}

	/**
	 * Send one request. The Send now button routes through here too, so a manual send
	 * still cannot bypass suppression, refunds, or the consent window.
	 */
	public static function send( $order, $attempt = 0 ) {
		$reason = self::ineligible_reason( $order );
		if ( null !== $reason ) {
			return new WP_Error( 'rrfw_ineligible', $reason );
		}

		$sent_count = (int) $order->get_meta( self::META_COUNT );

		if ( RRFW_Settings::is_dry_run() ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: recipient email address */
					__( 'Review request DRY RUN — would have emailed %s. No mail was sent.', 'review-requests-for-woocommerce' ),
					$order->get_billing_email()
				)
			);
			$order->update_meta_data( self::META_STATUS, 'dry-run' );
			$order->save();
			return true;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( empty( $emails['RRFW_Email_Review_Request'] ) ) {
			return new WP_Error( 'rrfw_no_email_class', __( 'Review request email class is not registered.', 'review-requests-for-woocommerce' ) );
		}

		$sent = $emails['RRFW_Email_Review_Request']->trigger( $order->get_id(), (int) $attempt );

		if ( ! $sent ) {
			$order->add_order_note( __( 'Review request failed to send.', 'review-requests-for-woocommerce' ) );
			return new WP_Error( 'rrfw_send_failed', __( 'The mailer reported a failure.', 'review-requests-for-woocommerce' ) );
		}

		$sent_count++;
		$order->update_meta_data( self::META_COUNT, $sent_count );
		$order->update_meta_data( self::META_LAST_SENT, current_time( 'mysql', true ) );
		$order->update_meta_data( self::META_STATUS, 'sent' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: send number, 2: recipient email address */
				__( 'Review request #%1$d sent to %2$s.', 'review-requests-for-woocommerce' ),
				$sent_count,
				$order->get_billing_email()
			)
		);

		self::bump_daily_counter();
		self::schedule_next( $order, $sent_count );

		return true;
	}

	private static function daily_counter() {
		$counter = get_option( 'rrfw_daily_counter', array() );
		$today   = gmdate( 'Y-m-d' );

		if ( ! is_array( $counter ) || ( $counter['date'] ?? '' ) !== $today ) {
			$counter = array(
				'date'  => $today,
				'count' => 0,
			);
		}

		return $counter;
	}

	public static function daily_cap_reached() {
		$counter = self::daily_counter();
		return (int) $counter['count'] >= (int) RRFW_Settings::get( 'daily_cap' );
	}

	private static function bump_daily_counter() {
		$counter          = self::daily_counter();
		$counter['count'] = (int) $counter['count'] + 1;
		update_option( 'rrfw_daily_counter', $counter, false );
	}
}
