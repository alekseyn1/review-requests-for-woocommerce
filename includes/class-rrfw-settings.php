<?php
/**
 * Plugin settings.
 *
 * Defaults are deliberately conservative: sending off, dry-run on, and a consent
 * window well inside what the strictest common anti-spam regime allows.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Settings {

	const OPTION = 'rrfw_settings';

	/**
	 * Defaults.
	 *
	 * Two separate limits apply to how old an order may be.
	 *
	 * The operational window defaults to one year: recent buyers are the only ones worth
	 * asking, and a two-year-old purchase reads as an odd thing to be emailed about.
	 *
	 * The hard ceiling is 730 days, enforced in sanitize() and not raisable. That number
	 * comes from Canada's CASL, where implied consent from a purchase expires two years
	 * after the transaction. It is the strictest of the common regimes, so honouring it
	 * keeps the plugin safe under CAN-SPAM and ePrivacy soft opt-in as well.
	 */
	public static function defaults() {
		return array(
			'enabled'            => 'no',
			'dry_run'            => 'yes',
			'enrolment_mode'     => 'auto',
			'body_first'         => '',
			'body_reminder'      => '',
			// Ordered list of category rules; the first whose category matches an order wins.
			'variants'           => array(),
			// Superseded by 'variants'. Retained so upgrade_variants() can fold them in.
			'variant_category'   => '',
			'variant_subject'    => '',
			'variant_body_first' => '',
			'variant_body_reminder' => '',
			'from_name'          => '',
			'from_email'         => '',
			'place_id'           => '',
			'first_delay_days'   => 14,
			'reminders'          => 1,
			'reminder_gap_days'  => 14,
			'max_order_age_days' => 365,
			'daily_cap'          => 25,
			'business_address'   => '',
			'min_order_total'    => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Shipped wording, used whenever the matching setting is left blank. Kept in code
	 * rather than written into the option on activation, so a shop that never touches the
	 * copy still picks up improvements to the defaults.
	 */
	public static function default_copy( $key ) {
		$copy = array(
			'body_first'    =>
				__( 'Your order has been with you for a little while now, so you have hopefully had a chance to put it to use.', 'review-requests-for-woocommerce' )
				. "\n\n"
				. __( 'If you have a few minutes, we would really appreciate an honest review on Google. It takes about a minute, and it helps other people decide whether we are right for them.', 'review-requests-for-woocommerce' ),

			'body_reminder' =>
				__( 'We wrote a little while ago about your order and did not want to let it slip — if you have a minute, a short review would genuinely help other people shopping with us.', 'review-requests-for-woocommerce' ),
		);

		return $copy[ $key ] ?? '';
	}

	/**
	 * Stored value if set, shipped default otherwise.
	 */
	public static function copy( $key ) {
		$stored = trim( (string) self::get( $key ) );

		return ( '' !== $stored ) ? $stored : self::default_copy( $key );
	}

	/**
	 * Folds the original single category rule into the ordered list. Idempotent: it only
	 * acts while the list is empty and a legacy category is present.
	 */
	public static function upgrade_variants() {
		$all = self::all();

		if ( ! empty( $all['variants'] ) || '' === trim( (string) $all['variant_category'] ) ) {
			return false;
		}

		$all['variants'] = array(
			array(
				'category'      => $all['variant_category'],
				'subject'       => $all['variant_subject'],
				'body_first'    => $all['variant_body_first'],
				'body_reminder' => $all['variant_body_reminder'],
			),
		);

		foreach ( array( 'variant_category', 'variant_subject', 'variant_body_first', 'variant_body_reminder' ) as $legacy ) {
			$all[ $legacy ] = '';
		}

		update_option( self::OPTION, $all );

		return true;
	}

	public static function is_enabled() {
		return 'yes' === self::get( 'enabled' );
	}

	public static function is_dry_run() {
		return 'yes' === self::get( 'dry_run' );
	}

	/**
	 * The Google review destination. Place ID form is the documented one and survives
	 * business profile edits better than a short g.page link.
	 */
	public static function review_url() {
		$place_id = trim( (string) self::get( 'place_id' ) );
		if ( '' === $place_id ) {
			return '';
		}
		return 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id );
	}

	public static function register() {
		register_setting(
			'rrfw_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$out = self::defaults();

		$out['enabled'] = ( ! empty( $input['enabled'] ) ) ? 'yes' : 'no';
		$out['dry_run'] = ( ! empty( $input['dry_run'] ) ) ? 'yes' : 'no';

		$mode                  = ( $input['enrolment_mode'] ?? 'auto' );
		$out['enrolment_mode'] = in_array( $mode, array( 'auto', 'manual' ), true ) ? $mode : 'auto';

		// Email copy. wp_kses_post so a shop can use light markup (<strong>, <a>) without
		// being able to inject script into its own outgoing mail.
		foreach ( array( 'body_first', 'body_reminder', 'variant_body_first', 'variant_body_reminder' ) as $field ) {
			$out[ $field ] = wp_kses_post( trim( (string) ( $input[ $field ] ?? '' ) ) );
		}

		// Category rules. Rows without a category, or ticked for removal, are dropped —
		// that is also how the always-present blank row at the end disappears on save.
		$variants = array();
		$raw      = $input['variants'] ?? array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$category = sanitize_title( $row['category'] ?? '' );

				if ( '' === $category || ! empty( $row['remove'] ) ) {
					continue;
				}

				$variants[] = array(
					'category'      => $category,
					'subject'       => sanitize_text_field( $row['subject'] ?? '' ),
					'body_first'    => wp_kses_post( trim( (string) ( $row['body_first'] ?? '' ) ) ),
					'body_reminder' => wp_kses_post( trim( (string) ( $row['body_reminder'] ?? '' ) ) ),
				);
			}
		}

		$out['variants'] = $variants;

		// Legacy single-variant fields are no longer edited; preserve whatever is stored
		// so an upgrade that has not run yet is not silently discarded by a settings save.
		$existing = get_option( self::OPTION, array() );
		foreach ( array( 'variant_category', 'variant_subject', 'variant_body_first', 'variant_body_reminder' ) as $legacy ) {
			$out[ $legacy ] = is_array( $existing ) && isset( $existing[ $legacy ] ) ? $existing[ $legacy ] : '';
		}

		$out['from_name'] = sanitize_text_field( $input['from_name'] ?? '' );

		// Blank means "inherit WooCommerce's sender". An invalid address is discarded
		// rather than saved, so a typo cannot silently break delivery.
		$from_email = trim( (string) ( $input['from_email'] ?? '' ) );
		$out['from_email'] = ( '' !== $from_email && is_email( $from_email ) ) ? sanitize_email( $from_email ) : '';

		$out['place_id']         = sanitize_text_field( $input['place_id'] ?? '' );
		$out['business_address'] = sanitize_textarea_field( $input['business_address'] ?? '' );

		$out['first_delay_days']  = max( 0, absint( $input['first_delay_days'] ?? 14 ) );
		$out['reminders']         = min( 3, absint( $input['reminders'] ?? 1 ) );
		$out['reminder_gap_days'] = max( 1, absint( $input['reminder_gap_days'] ?? 14 ) );
		$out['daily_cap']         = max( 1, absint( $input['daily_cap'] ?? 25 ) );
		$out['min_order_total']   = max( 0, (float) ( $input['min_order_total'] ?? 0 ) );

		// Never allow the consent window past the strictest regime's ceiling (CASL, 2 years).
		$out['max_order_age_days'] = min( 730, max( 1, absint( $input['max_order_age_days'] ?? 730 ) ) );

		return $out;
	}
}
