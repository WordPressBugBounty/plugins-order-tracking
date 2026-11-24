<?php
/**
 * Safe legacy controller-slot adapter while the paid owner is unavailable.
 *
 * @package OrderTracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Refuse execution rather than dereference a missing paid service. */
class EwdotpUnavailablePremiumModule {
	/**
	 * Return an unavailable result for a legacy operation.
	 *
	 * @param string $method    Legacy operation name.
	 * @param array  $arguments Legacy arguments.
	 * @return WP_Error
	 */
	public function __call( $method, $arguments ) {
		return ewdotpPremiumModules::unavailable();
	}
}
