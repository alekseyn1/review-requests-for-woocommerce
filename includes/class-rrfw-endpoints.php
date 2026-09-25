<?php
/**
 * Public endpoints reached from the email: the review click-through, the unsubscribe,
 * and the customer's own "I left a review" confirmation.
 *
 * These run on template_redirect rather than admin-ajax so they work for logged-out
 * visitors without booting the admin.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Endpoints {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
	}

	/**
	 * The review link always points at our own site first, so a click can be recorded
	 * before the customer is forwarded to Google.
	 */
	public static function click_url( $order ) {
		return RRFW_Tokens::url( 'go', $order->get_id(), $order->get_billing_email() );
	}

	public static function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed HMAC link, not a form.
		$action = isset( $_GET['rrfw'] ) ? sanitize_key( wp_unslash( $_GET['rrfw'] ) ) : '';

		if ( ! in_array( $action, array( 'go', 'unsub', 'confirm' ), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['oid'] ) ? absint( wp_unslash( $_GET['oid'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sig = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! RRFW_Tokens::verify( $action, $order_id, $order->get_billing_email(), $sig ) ) {
			self::render( __( 'Link not recognised', 'review-requests-for-woocommerce' ), __( 'This link is no longer valid. If you would like to unsubscribe, please reply to any of our emails and we will take care of it.', 'review-requests-for-woocommerce' ) );
		}

		switch ( $action ) {
			case 'go':
				self::handle_click( $order );
				break;

			case 'unsub':
				self::handle_unsubscribe( $order );
				break;

			case 'confirm':
				self::handle_confirm( $order );
				break;
		}
	}

	/**
	 * Record the click, stop further reminders, then forward to Google.
	 *
	 * A click proves intent, not that a review was posted — but it is a good enough
	 * signal to stop nagging someone who has already been sent to the review form.
	 */
	private static function handle_click( $order ) {
		if ( ! $order->get_meta( RRFW_Scheduler::META_CLICKED ) ) {
			$order->update_meta_data( RRFW_Scheduler::META_CLICKED, current_time( 'mysql', true ) );
			$order->update_meta_data( RRFW_Scheduler::META_STATUS, 'clicked' );
			$order->update_meta_data( RRFW_Scheduler::META_NEXT_SEND, '' );
			$order->save();
			$order->add_order_note( __( 'Customer followed the review link.', 'review-requests-for-woocommerce' ) );
			RRFW_Scheduler::cancel_for_order( $order->get_id() );
		}

		$destination = RRFW_Settings::review_url();

		if ( '' === $destination ) {
			self::render(
				__( 'Thank you', 'review-requests-for-woocommerce' ),
				__( 'Thanks for your interest in leaving a review. Our review link is temporarily unavailable — please try again shortly.', 'review-requests-for-woocommerce' )
			);
		}

		wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- deliberate off-site redirect to Google.
		exit;
	}

	/**
	 * Honour the opt-out for the person, not just this order, and accept the
	 * RFC 8058 POST without an interstitial.
	 */
	private static function handle_unsubscribe( $order ) {
		$email = $order->get_billing_email();

		RRFW_Suppression::add( $email, 'unsubscribe', $order->get_id() );
		RRFW_Scheduler::cancel_for_order( $order->get_id() );

		$order->update_meta_data( RRFW_Scheduler::META_ENROLLED, 'no' );
		$order->update_meta_data( RRFW_Scheduler::META_STATUS, 'unsubscribed' );
		$order->update_meta_data( RRFW_Scheduler::META_NEXT_SEND, '' );
		$order->save();
		$order->add_order_note( __( 'Customer unsubscribed from review requests.', 'review-requests-for-woocommerce' ) );

		// One-click unsubscribe from the mail client: no page needed.
		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			status_header( 200 );
			exit;
		}

		self::render(
			__( 'You have been unsubscribed', 'review-requests-for-woocommerce' ),
			sprintf(
				/* translators: %s: the customer's email address */
				__( 'We will not email %s about reviews again. Your order and any support you need are unaffected.', 'review-requests-for-woocommerce' ),
				esc_html( $email )
			)
		);
	}

	/**
	 * The only honest signal that a review was actually left: the customer saying so.
	 */
	private static function handle_confirm( $order ) {
		if ( ! $order->get_meta( RRFW_Scheduler::META_CONFIRMED ) ) {
			$order->update_meta_data( RRFW_Scheduler::META_CONFIRMED, current_time( 'mysql', true ) );
			$order->update_meta_data( RRFW_Scheduler::META_STATUS, 'confirmed' );
			$order->update_meta_data( RRFW_Scheduler::META_NEXT_SEND, '' );
			$order->save();
			$order->add_order_note( __( 'Customer confirmed they left a review.', 'review-requests-for-woocommerce' ) );
			RRFW_Scheduler::cancel_for_order( $order->get_id() );
		}

		self::render(
			__( 'Thank you', 'review-requests-for-woocommerce' ),
			__( 'Thanks for letting us know — we really appreciate it. You will not receive any more review reminders.', 'review-requests-for-woocommerce' )
		);
	}

	private static function render( $title, $message ) {
		wp_die(
			'<h1>' . esc_html( $title ) . '</h1><p>' . wp_kses_post( $message ) . '</p>' .
			'<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Return to the shop', 'review-requests-for-woocommerce' ) . '</a></p>',
			esc_html( $title ),
			array( 'response' => 200 )
		);
	}
}
