<?php
/**
 * Read-only Free consumer of Helper's OTP entitlement contract.
 *
 * @package OrderTracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Local capability checks do not trust legacy flags or perform remote requests. */
class EwdotpEntitlement {
	/**
	 * Get a supported local contract, or a safe denied projection.
	 *
	 * @return array
	 */
	public static function effective() {
		$denied = array(
			'effective_status' => 'unverifiable',
			'capabilities'     => array(),
			'environment'      => 'unknown',
			'resume_cutoff'    => 0,
		);
		if ( ! defined( 'EWDPH_VERSION' ) || ! class_exists( 'ewdphOTPCompatibility' ) ) {
			return $denied;
		}
		$descriptor = ewdphOTPCompatibility::get_descriptor();
		$api        = EWD_OTP_HELPER_API_VERSION;
		if ( ! is_array( $descriptor ) || ! isset( $descriptor['api_version'], $descriptor['entitlement_contract_version'], $descriptor['supported_free_api_min'], $descriptor['supported_free_api_max'], $descriptor['plugin_version'] ) || $api !== $descriptor['api_version'] || 1 !== $descriptor['entitlement_contract_version'] || ! is_int( $descriptor['supported_free_api_min'] ) || ! is_int( $descriptor['supported_free_api_max'] ) || $descriptor['supported_free_api_min'] > $api || $descriptor['supported_free_api_max'] < $api || ! is_string( $descriptor['plugin_version'] ) || version_compare( $descriptor['plugin_version'], EWD_OTP_MIN_HELPER_VERSION, '<' ) ) {
			return $denied;
		}
		$contract = apply_filters( 'ewd_otp_entitlement_contract', null );
		if ( ! is_array( $contract ) || ! isset( $contract['contract_version'], $contract['product'], $contract['helper_api_version'], $contract['effective_status'], $contract['capabilities'], $contract['environment'] ) || 1 !== $contract['contract_version'] || 'OTP' !== $contract['product'] || $api !== $contract['helper_api_version'] || ! is_array( $contract['capabilities'] ) || ! in_array( $contract['effective_status'], array( 'valid', 'valid_cached', 'legacy_transition', 'trial', 'missing', 'invalid', 'unauthorized', 'expired', 'unverifiable' ), true ) || ! in_array( $contract['environment'], array( 'production', 'staging', 'development', 'local', 'unknown' ), true ) ) {
			return $denied;
		}
		$allowed = array( 'customers', 'sales_reps', 'customer_orders', 'custom_fields', 'styling', 'premium', 'woocommerce', 'zendesk', 'import', 'export', 'statuses', 'locations', 'payments', 'labelling', 'sms', 'api_usage' );
		foreach ( $contract['capabilities'] as $capability ) {
			if ( ! is_string( $capability ) || ! in_array( $capability, $allowed, true ) ) {
				return $denied;
			}
		}
		if ( count( $contract['capabilities'] ) !== count( array_unique( $contract['capabilities'] ) ) || ( $contract['capabilities'] && ! in_array( $contract['effective_status'], array( 'valid', 'valid_cached', 'legacy_transition', 'trial' ), true ) ) ) {
			return $denied;
		}
		if ( 'trial' === $contract['effective_status'] && ( in_array( 'sms', $contract['capabilities'], true ) || in_array( 'api_usage', $contract['capabilities'], true ) ) ) {
			return $denied;
		}
		if ( in_array( $contract['effective_status'], array( 'trial', 'legacy_transition' ), true ) && ( ! isset( $contract['allowance_expires_at'], $contract['allowance_started_at'] ) || ! is_int( $contract['allowance_expires_at'] ) || ! is_int( $contract['allowance_started_at'] ) || $contract['allowance_started_at'] <= 0 || $contract['allowance_started_at'] > time() || $contract['allowance_expires_at'] > $contract['allowance_started_at'] + 604800 || time() >= $contract['allowance_expires_at'] ) ) {
			return $denied;
		}
		if ( in_array( $contract['effective_status'], array( 'valid', 'valid_cached' ), true ) ) {
			if ( ! isset( $contract['licenses'] ) || ! is_array( $contract['licenses'] ) ) {
				return $denied;
			}
			$proven = array();
			foreach ( $contract['licenses'] as $slot => $license ) {
				if ( ! in_array( $slot, array( 'primary', 'premium' ), true ) ) {
					return $denied;
				}
				if ( ! is_array( $license ) || ! isset( $license['effective_status'], $license['capabilities'] ) || ! is_array( $license['capabilities'] ) ) {
					return $denied;
				}
				if ( ! $license['capabilities'] ) {
					continue;
				}
				if ( ! isset( $license['permission_level'], $license['plan'] ) || ! in_array( $license['permission_level'], array( 2, 3 ), true ) || ! array_key_exists( 'license_expires_at', $license ) || ( 'premium' === $slot && 2 !== $license['permission_level'] ) ) {
					return $denied;
				}
				$expected = 2 === $license['permission_level'] ? array_slice( $allowed, 0, 14 ) : $allowed;
				if ( $expected !== $license['capabilities'] || ( 2 === $license['permission_level'] && ( 'premium' !== $license['plan'] || null !== $license['license_expires_at'] ) ) || ( 3 === $license['permission_level'] && ( 'ultimate' !== $license['plan'] || ! is_int( $license['license_expires_at'] ) || time() >= $license['license_expires_at'] ) ) ) {
					return $denied;
				}
				if ( ! in_array( $license['effective_status'], array( 'valid', 'valid_cached' ), true ) || ! isset( $license['deadline_at'], $license['last_success_at'] ) || ! is_int( $license['deadline_at'] ) || ! is_int( $license['last_success_at'] ) || $license['last_success_at'] <= 0 || $license['last_success_at'] > time() || $license['deadline_at'] > $license['last_success_at'] + 604800 || time() >= $license['deadline_at'] ) {
					return $denied;
				}
				if ( null !== $license['license_expires_at'] && $license['deadline_at'] > $license['license_expires_at'] ) {
					return $denied;
				}
				foreach ( $license['capabilities'] as $capability ) {
					if ( ! is_string( $capability ) || ! in_array( $capability, $allowed, true ) ) {
						return $denied;
					}
				}
				$proven = array_values( array_unique( array_merge( $proven, $license['capabilities'] ) ) );
			}
			if ( array_diff( $contract['capabilities'], $proven ) ) {
				return $denied;
			}
		}
		if ( isset( $contract['resume_cutoff'] ) && ( ! is_int( $contract['resume_cutoff'] ) || $contract['resume_cutoff'] < 0 || $contract['resume_cutoff'] > time() ) ) {
			return $denied;
		}
		return $contract;
	}

	/**
	 * Check a paid operation; outbound writes/sends require production intent.
	 *
	 * @param string $capability Named operation capability.
	 * @param bool   $outbound Whether this causes external sends/writes/charges.
	 * @return bool
	 */
	public static function allows( $capability, $outbound = false ) {
		$state = self::effective();
		return in_array( $capability, $state['capabilities'], true ) && ( ! $outbound || 'production' === $state['environment'] );
	}

	/**
	 * Preserve proven historical Free administration, never paid portal rights.
	 *
	 * @param string $feature Customers, sales representatives, or custom fields.
	 * @return bool
	 */
	public static function legacy_free( $feature ) {
		$origin = get_option( 'ewd-otp-release-n-origin-v1', array() );
		return in_array( $feature, array( 'customers', 'sales_reps', 'custom_fields' ), true ) && is_array( $origin ) && isset( $origin['installation_time'] ) && is_int( $origin['installation_time'] ) && $origin['installation_time'] > 0 && $origin['installation_time'] < 1664742505;
	}

	/**
	 * Resolve paid or grandfathered administration; actor checks remain mandatory.
	 *
	 * @param string $feature Administrative feature.
	 * @return bool
	 */
	public static function administer( $feature ) {
		return self::allows( $feature ) || self::legacy_free( $feature );
	}
}
