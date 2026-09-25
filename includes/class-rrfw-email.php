<?php
/**
 * The review request email.
 *
 * Subclassing WC_Email rather than calling wp_mail() directly buys the standard
 * WooCommerce wrapper, the Settings > Emails screen, placeholder handling, and the
 * child theme override path the royal-child theme already uses for other emails.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

class RRFW_Email_Review_Request extends WC_Email {

	/**
	 * Which send this is: 0 for the first request, 1+ for reminders.
	 *
	 * @var int
	 */
	public $attempt = 0;

	public function __construct() {
		$this->id             = 'rrfw_review_request';
		$this->customer_email = true;
		$this->title          = __( 'Review request', 'review-requests-for-woocommerce' );
		$this->description    = __( 'Asks the customer to leave a Google review after their order is completed.', 'review-requests-for-woocommerce' );

		$this->template_html  = 'emails/customer-review-request.php';
		$this->template_plain = 'emails/plain/customer-review-request.php';
		$this->template_base  = RRFW_PATH . 'templates/';

		$this->placeholders = array(
			'{order_number}' => '',
			'{customer_name}' => '',
			'{site_title}'   => $this->get_blogname(),
		);

		parent::__construct();

		// This email is triggered by the scheduler, never by a status change directly.
		$this->manual = false;
	}

	/**
	 * Whether an order contains anything from a given product category.
	 *
	 * Provided for template overrides: a shop selling one headline product alongside
	 * accessories usually wants different wording for each, and the override can branch
	 * on this rather than reimplementing the term walk. Child categories are included, so
	 * the check survives the tree being reorganised.
	 *
	 * Returns false when the category does not exist, so a typo produces the neutral
	 * wording rather than silently applying the wrong branch to every order.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @param string   $slug  product_cat slug.
	 * @return bool
	 */
	public function order_has_product_in_category( $order, $slug ) {
		$parent = get_term_by( 'slug', $slug, 'product_cat' );

		if ( ! $parent ) {
			return false;
		}

		$term_ids = array_merge( array( (int) $parent->term_id ), (array) get_term_children( $parent->term_id, 'product_cat' ) );

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			// Variations carry no terms of their own; the parent holds them.
			$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$own_terms  = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

			if ( is_wp_error( $own_terms ) ) {
				continue;
			}

			if ( array_intersect( $term_ids, $own_terms ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Items as the template needs them: name, quantity and a thumbnail. Deliberately no
	 * prices — this is a review request, not a receipt.
	 */
	public function get_item_rows( $order ) {
		$rows = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$image   = '';

			if ( $product ) {
				$image_id = $product->get_image_id();

				if ( ! $image_id && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$image_id = $parent ? $parent->get_image_id() : 0;
				}

				if ( $image_id ) {
					$image = (string) wp_get_attachment_image_url( $image_id, 'thumbnail' );
				}
			}

			$rows[] = array(
				'name'  => $item->get_name(),
				'qty'   => (int) $item->get_quantity(),
				'image' => $image,
			);
		}

		return $rows;
	}

	/**
	 * First category rule matching this order, or null.
	 *
	 * Rules are evaluated in the order they are listed, so a product sitting in two
	 * categories resolves predictably: the higher rule wins. A blank or deleted category
	 * simply never matches, so removing a category degrades to the standard wording rather
	 * than to an empty email.
	 *
	 * @return array|null
	 */
	public function matching_variant( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		foreach ( (array) RRFW_Settings::get( 'variants' ) as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$slug = trim( (string) ( $variant['category'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			if ( $this->order_has_product_in_category( $order, $slug ) ) {
				return $variant;
			}
		}

		return null;
	}

	/**
	 * Whether any category rule applies to this order.
	 */
	public function variant_applies( $order ) {
		return null !== $this->matching_variant( $order );
	}

	/**
	 * Body copy for this send: the matching rule if it fills the field in, the configured
	 * default otherwise, and the shipped wording if that is blank too.
	 */
	public function get_body_text( $order, $attempt ) {
		$key     = ( (int) $attempt > 0 ) ? 'body_reminder' : 'body_first';
		$variant = $this->matching_variant( $order );

		if ( $variant ) {
			$text = trim( (string) ( $variant[ $key ] ?? '' ) );

			if ( '' !== $text ) {
				return $this->format_string( $text );
			}
		}

		return $this->format_string( RRFW_Settings::copy( $key ) );
	}

	public function get_default_subject() {
		return __( 'How are you getting on with your order, {customer_name}?', 'review-requests-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'How did we do?', 'review-requests-for-woocommerce' );
	}

	/**
	 * Reminder wording differs from the first ask, so the customer does not receive
	 * the identical message twice.
	 */
	public function get_subject() {
		if ( $this->attempt > 0 ) {
			$subject = __( 'A quick favour, {customer_name}?', 'review-requests-for-woocommerce' );

			return apply_filters(
				'woocommerce_email_subject_' . $this->id,
				$this->format_string( $subject ),
				$this->object,
				$this
			);
		}

		// A variant subject, when one is configured and this order matches, beats the
		// standard subject — the subject cannot be reached from a template override, so
		// this is the only way to vary it without code.
		$variant = $this->matching_variant( $this->object );

		if ( $variant ) {
			$subject_override = trim( (string) ( $variant['subject'] ?? '' ) );

			if ( '' !== $subject_override ) {
				return apply_filters(
					'woocommerce_email_subject_' . $this->id,
					$this->format_string( $subject_override ),
					$this->object,
					$this
				);
			}
		}

		return parent::get_subject();
	}

	public function trigger( $order_id, $attempt = 0 ) {
		$this->setup_locale();

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->restore_locale();
			return false;
		}

		$this->object    = $order;
		$this->attempt   = (int) $attempt;
		$this->recipient = $order->get_billing_email();

		$this->placeholders['{order_number}']  = $order->get_order_number();
		$this->placeholders['{customer_name}'] = $order->get_billing_first_name();

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			$this->restore_locale();
			return false;
		}

		$sent = $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);

		$this->restore_locale();

		return $sent;
	}

	/**
	 * Sender overrides, scoped to this email only.
	 *
	 * Overriding the methods rather than filtering woocommerce_email_from_address keeps
	 * every other WooCommerce email untouched. Blank settings fall through to the
	 * WooCommerce defaults.
	 *
	 * Delivery constraint worth remembering: this site sends via the Gmail API, which
	 * only honours a From address verified in that account under "Send mail as", and
	 * only while WP Mail SMTP's "Force From Email" is off. Otherwise Google rewrites it.
	 */
	public function get_from_name( $from_name = '' ) {
		$custom = trim( (string) RRFW_Settings::get( 'from_name' ) );

		if ( '' !== $custom ) {
			return wp_specialchars_decode( esc_html( $custom ), ENT_QUOTES );
		}

		return parent::get_from_name( $from_name );
	}

	public function get_from_address( $from_email = '' ) {
		$custom = trim( (string) RRFW_Settings::get( 'from_email' ) );

		if ( '' !== $custom && is_email( $custom ) ) {
			return sanitize_email( $custom );
		}

		return parent::get_from_address( $from_email );
	}

	/**
	 * RFC 8058 one-click unsubscribe. Gmail and Yahoo effectively require these headers
	 * from bulk senders, and their absence is a deliverability problem in itself.
	 */
	public function get_headers() {
		$headers = parent::get_headers();

		if ( $this->object instanceof WC_Order ) {
			$unsub = RRFW_Tokens::url( 'unsub', $this->object->get_id(), $this->object->get_billing_email() );

			$headers .= 'List-Unsubscribe: <' . esc_url_raw( $unsub ) . ">\r\n";
			$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		}

		return $headers;
	}

	protected function template_args() {
		$order = $this->object;

		return array(
			'order'          => $order,
			'email_heading'  => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'attempt'        => $this->attempt,
			'body_text'      => $this->get_body_text( $order, $this->attempt ),
			'item_rows'      => $this->get_item_rows( $order ),
			'review_url'     => RRFW_Endpoints::click_url( $order ),
			'unsubscribe_url' => RRFW_Tokens::url( 'unsub', $order->get_id(), $order->get_billing_email() ),
			'confirm_url'    => RRFW_Tokens::url( 'confirm', $order->get_id(), $order->get_billing_email() ),
			'business_address' => RRFW_Settings::get( 'business_address' ),
			'sent_to_admin'  => false,
			'plain_text'     => false,
			'email'          => $this,
		);
	}

	public function get_content_html() {
		$args               = $this->template_args();
		$args['plain_text'] = false;

		return wc_get_template_html( $this->template_html, $args, '', $this->template_base );
	}

	public function get_content_plain() {
		$args               = $this->template_args();
		$args['plain_text'] = true;

		return wc_get_template_html( $this->template_plain, $args, '', $this->template_base );
	}

	/**
	 * Default the WooCommerce email settings screen to something sensible.
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['description'] = __( 'Sending is also gated by the plugin-level enable switch and dry-run setting.', 'review-requests-for-woocommerce' );
		}
	}
}
