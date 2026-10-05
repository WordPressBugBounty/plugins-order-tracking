<?php
/**
 * PayPal IPN authenticity and order-payment integrity handler.
 *
 * @package OrderTracking
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpPayPal' ) ) {
	/**
	 * Authenticates PayPal IPN messages before applying payment state.
	 */
	class EwdotpPayPal {

		/**
		 * Authenticate and apply an IPN payload.
		 *
		 * @param mixed $raw_body raw body.
		 */
		public function handle( $raw_body = null ) {
			$raw_body = null === $raw_body ? file_get_contents( 'php://input' ) : (string) $raw_body;
			if ( '' === $raw_body ) {
				return false; }

			$endpoint = apply_filters( 'ewd_otp_paypal_ipn_endpoint', 'https://www.paypal.com/cgi-bin/webscr' );
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 30,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => 'cmd=_notify-validate&' . $raw_body,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false; }

			$code = wp_remote_retrieve_response_code( $response );
			$body = trim( wp_remote_retrieve_body( $response ) );
			if ( 200 !== $code || 'VERIFIED' !== $body ) {
				return false; }

			$payload = array();
			parse_str( $raw_body, $payload );

			return $this->apply_verified_payload( $payload );
		}

		/**
		 * Validate verified PayPal semantics and perform one idempotent mutation.
		 *
		 * @param mixed $payload payload.
		 */
		public function apply_verified_payload( $payload ) {
			global $ewd_otp_controller;

			$payload  = is_array( $payload ) ? $payload : array();
			$order_id = isset( $payload['custom'] ) ? absint( $payload['custom'] ) : 0;
			$order    = new ewdotpOrder();
			$order->load_order_from_id( $order_id );

			if ( empty( $order->id ) ) {
				return false; }
			if ( ! isset( $payload['payment_status'] ) || 'Completed' !== $payload['payment_status'] ) {
				return false; }
			if ( ! isset( $payload['mc_gross'] ) || ! self::decimals_equal( $payload['mc_gross'], $order->payment_price ) ) {
				return false; }

			$expected_currency = strtoupper( trim( (string) $ewd_otp_controller->settings->get_setting( 'pricing-currency-code' ) ) );
			$currency          = isset( $payload['mc_currency'] ) ? strtoupper( trim( sanitize_text_field( $payload['mc_currency'] ) ) ) : '';
			if ( '' === $expected_currency || $currency !== $expected_currency ) {
				return false; }

			$expected_merchant = strtolower( trim( sanitize_email( $ewd_otp_controller->settings->get_setting( 'paypal-email-address' ) ) ) );
			$merchant          = ! empty( $payload['receiver_email'] ) ? $payload['receiver_email'] : ( isset( $payload['business'] ) ? $payload['business'] : '' );
			$merchant          = strtolower( trim( sanitize_email( $merchant ) ) );
			if ( '' === $expected_merchant || '' === $merchant || ! hash_equals( $expected_merchant, $merchant ) ) {
				return false; }

			$transaction_id = isset( $payload['txn_id'] ) ? sanitize_text_field( $payload['txn_id'] ) : '';
			if ( '' === $transaction_id ) {
				return false; }

			$claim = $ewd_otp_controller->order_manager->claim_paypal_payment( $order->id, $transaction_id );
			if ( 'rejected' === $claim ) {
				return false; }
			if ( 'duplicate' === $claim ) {
				return true; }

			$order->payment_completed     = true;
			$order->paypal_receipt_number = $transaction_id;
			do_action( 'ewd_otp_order_paid', $order );
			return true;
		}

		/**
		 * Normalize a positive decimal without using binary floating point.
		 *
		 * @param mixed $value value.
		 */
		public static function normalize_decimal( $value ) {
			$value = trim( (string) $value );
			if ( ! preg_match( '/^\+?\d+(?:\.\d+)?$/', $value ) ) {
				return false; }

			$value    = ltrim( $value, '+' );
			$parts    = explode( '.', $value, 2 );
			$integer  = ltrim( $parts[0], '0' );
			$integer  = '' === $integer ? '0' : $integer;
			$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';

			return '' === $fraction ? $integer : $integer . '.' . $fraction;
		}

		/**
		 * Handle the decimals equal operation.
		 *
		 * @param mixed $left left.
		 * @param mixed $right right.
		 */
		public static function decimals_equal( $left, $right ) {
			$left  = self::normalize_decimal( $left );
			$right = self::normalize_decimal( $right );

			return false !== $left && false !== $right && hash_equals( $left, $right );
		}
	}
}
