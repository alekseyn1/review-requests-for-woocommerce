<?php
/**
 * Review request email (plain text).
 *
 * @var WC_Order $order
 * @var int      $attempt
 * @var string   $body_text
 * @var array    $item_rows
 * @var string   $review_url
 * @var string   $unsubscribe_url
 * @var string   $confirm_url
 * @var string   $business_address
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( $email_heading ) . " =\n\n";

printf(
	/* translators: %s: customer first name */
	esc_html__( 'Hi %s,', 'review-requests-for-woocommerce' ),
	esc_html( $order->get_billing_first_name() )
);
echo "\n\n";

// Body copy is configured in WooCommerce > Review Requests; strip any markup for plain text.
echo esc_html( wp_strip_all_tags( $body_text ) ) . "

";

if ( ! empty( $item_rows ) ) {
	echo esc_html__( 'Your order:', 'review-requests-for-woocommerce' ) . "
";
	foreach ( $item_rows as $row ) {
		echo '  - ' . esc_html( $row['name'] );
		if ( $row['qty'] > 1 ) {
			echo ' x' . esc_html( $row['qty'] );
		}
		echo "
";
	}
	echo "
";
}

echo esc_html__( 'Leave a review:', 'review-requests-for-woocommerce' ) . "\n" . esc_url_raw( $review_url ) . "\n\n";
echo esc_html__( 'Already left one? Let us know and we will stop reminding you:', 'review-requests-for-woocommerce' ) . "\n" . esc_url_raw( $confirm_url ) . "\n\n";
echo esc_html__( 'And if anything is not right with your order, do reply to this email as well. We would much rather know about it and put it right.', 'review-requests-for-woocommerce' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "----------\n\n";

printf(
	/* translators: %s: order number */
	esc_html__( 'You are receiving this because you bought from us (order %s).', 'review-requests-for-woocommerce' ),
	esc_html( $order->get_order_number() )
);
echo "\n";
echo esc_html__( 'Unsubscribe:', 'review-requests-for-woocommerce' ) . ' ' . esc_url_raw( $unsubscribe_url ) . "\n";

if ( trim( (string) $business_address ) !== '' ) {
	echo "\n" . esc_html( $business_address ) . "\n";
}
