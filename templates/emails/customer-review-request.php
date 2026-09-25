<?php
/**
 * Review request email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/customer-review-request.php
 *
 * @var WC_Order                  $order
 * @var string                    $email_heading
 * @var int                       $attempt          0 for the first ask, 1+ for reminders.
 * @var string                    $body_text        Resolved body copy, blank lines = paragraphs.
 * @var array                     $item_rows        name / qty / image per line item.
 * @var string                    $review_url       Signed redirect that records the click.
 * @var string                    $unsubscribe_url
 * @var string                    $confirm_url
 * @var string                    $business_address
 * @var RRFW_Email_Review_Request $email            Call $email->order_has_product_in_category(
 *                                                 $order, 'slug' ) to vary wording by what
 *                                                 was bought.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php
printf(
	/* translators: %s: customer first name */
	esc_html__( 'Hi %s,', 'review-requests-for-woocommerce' ),
	esc_html( $order->get_billing_first_name() )
);
?></p>

<?php
// Body copy comes from WooCommerce > Review Requests, which resolves the category variant
// and falls back to the shipped wording. Blank lines there become paragraphs here.
echo wp_kses_post( wpautop( wptexturize( $body_text ) ) );
?>

<?php if ( ! empty( $item_rows ) ) : ?>
	<p style="margin-bottom:8px;color:#666;font-size:13px;"><?php esc_html_e( 'Your order:', 'review-requests-for-woocommerce' ); ?></p>
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin-bottom:8px;">
		<?php foreach ( $item_rows as $row ) : ?>
			<tr>
				<?php if ( $row['image'] ) : ?>
					<td width="60" valign="middle" style="padding:8px 12px 8px 0;border-bottom:1px solid #ececec;">
						<img src="<?php echo esc_url( $row['image'] ); ?>" alt="" width="48" height="48"
							style="width:48px;height:48px;object-fit:cover;border-radius:3px;display:block;" />
					</td>
				<?php else : ?>
					<td width="60" style="padding:8px 12px 8px 0;border-bottom:1px solid #ececec;">&nbsp;</td>
				<?php endif; ?>
				<td valign="middle" style="padding:8px 0;border-bottom:1px solid #ececec;font-size:14px;">
					<?php echo esc_html( $row['name'] ); ?>
					<?php if ( $row['qty'] > 1 ) : ?>
						<span style="color:#888;">&times; <?php echo esc_html( $row['qty'] ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<?php
// Table-based button: Outlook's Word rendering engine drops padding on a bare anchor,
// so the colour and radius live on the cell and the anchor supplies the hit area.
?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0;">
	<tr>
		<td align="center" bgcolor="#1a73e8" style="border-radius:4px;">
			<a href="<?php echo esc_url( $review_url ); ?>"
				style="display:inline-block;padding:13px 30px;border-radius:4px;font-family:Roboto,'Helvetica Neue',Arial,sans-serif;font-size:15px;font-weight:500;line-height:20px;color:#ffffff;text-decoration:none;letter-spacing:.15px;">
				<?php esc_html_e( 'Leave a review on Google', 'review-requests-for-woocommerce' ); ?>
			</a>
		</td>
	</tr>
</table>

<p style="font-size:13px;color:#666;">
	<?php
	printf(
		/* translators: %1$s: opening link tag, %2$s: closing link tag */
		esc_html__( 'Already left one? %1$sLet us know%2$s and we will stop reminding you.', 'review-requests-for-woocommerce' ),
		'<a href="' . esc_url( $confirm_url ) . '">',
		'</a>'
	);
	?>
</p>

<?php
// Deliberately "as well", never "instead": offering support as an alternative to
// reviewing would divert unhappy customers away from Google, which is review gating.
?>
<p><?php esc_html_e( 'And if anything is not right with your order, do reply to this email as well — we would much rather know about it and put it right.', 'review-requests-for-woocommerce' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>

<hr style="border:none;border-top:1px solid #e0e0e0;margin:26px 0 14px;" />

<p style="font-size:12px;color:#888;line-height:1.6;">
	<?php
	printf(
		/* translators: %1$s: order number */
		esc_html__( 'You are receiving this because you bought from us (order %1$s).', 'review-requests-for-woocommerce' ),
		esc_html( $order->get_order_number() )
	);
	?>
	<br />
	<a href="<?php echo esc_url( $unsubscribe_url ); ?>"><?php esc_html_e( 'Unsubscribe from review requests', 'review-requests-for-woocommerce' ); ?></a>
	<?php if ( trim( (string) $business_address ) !== '' ) : ?>
		<br /><br /><?php echo nl2br( esc_html( $business_address ) ); ?>
	<?php endif; ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
