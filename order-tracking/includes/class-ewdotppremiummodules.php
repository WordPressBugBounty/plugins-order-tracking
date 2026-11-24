<?php
/**
 * Storage-free dispatch to explicitly compatible premium implementations.
 *
 * @package OrderTracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** No premium business implementation, remote loading or cached access grants. */
class EwdotpPremiumModules {
	/**
	 * Dispatch one supported operation after checking its current owner and rights.
	 *
	 * Callbacks remain responsible for actor/proof/nonce and execution-time checks.
	 *
	 * @param string $operation Fixed operation identifier.
	 * @param array  $arguments Positional arguments, never a requested file path.
	 * @return mixed|WP_Error Operation result or unavailable error.
	 */
	public static function call( $operation, $arguments = array() ) {
		$capabilities = array(
			'customer_shortcode'       => 'customers',
			'sales_rep_shortcode'      => 'sales_reps',
			'customer_order_shortcode' => 'customer_orders',
			'customer_orders_ajax'     => 'customers',
			'sales_rep_orders_ajax'    => 'sales_reps',
			'client_order_submission'  => 'customer_orders',
			'client_order_validation'  => 'customer_orders',
			'send_text'                => 'sms',
			'sales_rep_notification'   => 'sales_reps',
			'payment_render'           => 'payments',
			'map_render'               => 'premium',
			'graphic_render'           => 'premium',
			'shipments_render'         => 'woocommerce',
		);
		if ( ! is_string( $operation ) || ! isset( $capabilities[ $operation ] ) || ! is_array( $arguments ) || ! defined( 'EWD_OTP_HELPER_API_VERSION' ) || 3 !== EWD_OTP_HELPER_API_VERSION || ! class_exists( 'ewdphOTPCompatibility' ) ) {
			return self::unavailable();
		}
		$descriptor = ewdphOTPCompatibility::get_descriptor();
		if ( ! is_array( $descriptor ) || ! isset( $descriptor['api_version'], $descriptor['supported_free_api_min'], $descriptor['supported_free_api_max'], $descriptor['plugin_version'] ) || 3 !== $descriptor['api_version'] || ! is_int( $descriptor['supported_free_api_min'] ) || ! is_int( $descriptor['supported_free_api_max'] ) || $descriptor['supported_free_api_min'] > 3 || $descriptor['supported_free_api_max'] < 3 || ! is_string( $descriptor['plugin_version'] ) || version_compare( $descriptor['plugin_version'], '0.3.0', '<' ) ) {
			return self::unavailable();
		}
		$outbound = in_array( $operation, array( 'send_text', 'sales_rep_notification', 'payment_render' ), true );
		if ( ! ewdotpEntitlement::allows( $capabilities[ $operation ], $outbound ) ) {
			return self::unavailable();
		}
		$operations = apply_filters( 'ewd_otp_premium_module_operations', array() );
		if ( ! is_array( $operations ) || ! isset( $operations[ $operation ] ) || ! is_callable( $operations[ $operation ] ) ) {
			return self::unavailable();
		}
		return call_user_func_array( $operations[ $operation ], array_values( $arguments ) );
	}

	/**
	 * Return a non-sensitive safe failure, with no input or license data.
	 *
	 * @return WP_Error
	 */
	public static function unavailable() {
		return new WP_Error( 'ewd_otp_module_unavailable', __( 'This feature requires a compatible active EWD Premium Helper and current access.', 'order-tracking' ) );
	}
}
