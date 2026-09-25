<?php
/**
 * Signed links for email. No PII in the query string, and no nonces: an emailed link
 * must stay valid for weeks and work for a logged-out visitor.
 */

defined( 'ABSPATH' ) || exit;

class RRFW_Tokens {

	/**
	 * The email address is bound into the signature but never travels in the URL, so a
	 * token cannot be replayed against a different customer or a re-used order id.
	 */
	public static function sign( $action, $order_id, $email ) {
		$secret = RRFW_Install::ensure_secret();
		$data   = $action . '|' . (int) $order_id . '|' . strtolower( trim( (string) $email ) );
		return hash_hmac( 'sha256', $data, $secret );
	}

	public static function verify( $action, $order_id, $email, $token ) {
		$expected = self::sign( $action, $order_id, $email );
		return hash_equals( $expected, (string) $token );
	}

	public static function url( $action, $order_id, $email ) {
		return add_query_arg(
			array(
				'rrfw'  => rawurlencode( $action ),
				'oid'  => (int) $order_id,
				'sig'  => self::sign( $action, $order_id, $email ),
			),
			home_url( '/' )
		);
	}
}
