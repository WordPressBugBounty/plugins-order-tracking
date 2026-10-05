<?php
/**
 * Evaluate compatibility between Order Tracking and EWD Premium Helper.
 *
 * @package OrderTracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpHelperCompatibility' ) ) {
	/**
	 * Evaluates helper presence, API compatibility, and entitlement state.
	 */
	class EwdotpHelperCompatibility {

		/**
		 * Runtime state state.
		 *
		 * @var mixed
		 */
		private $state = 'missing';
		/**
		 * Runtime details state.
		 *
		 * @var mixed
		 */
		private $details = array();

		/**
		 * Initialize the integration.
		 */
		public function __construct() {

			add_action( 'plugins_loaded', array( $this, 'refresh' ), 100 );
			add_action( 'admin_notices', array( $this, 'display_admin_notice' ) );

			$this->refresh();
		}

		/**
		 * Handle the get descriptor operation.
		 */
		public static function get_descriptor() {

			return array(
				'plugin_version'         => EWD_OTP_VERSION,
				'api_version'            => EWD_OTP_HELPER_API_VERSION,
				'min_helper_version'     => EWD_OTP_MIN_HELPER_VERSION,
				'max_helper_api_version' => EWD_OTP_MAX_HELPER_API_VERSION,
			);
		}

		/**
		 * Handle the refresh operation.
		 */
		public function refresh() {

			global $ewd_otp_controller;

			$helper_active     = defined( 'EWDPH_VERSION' );
			$helper_installed  = $helper_active || file_exists( WP_PLUGIN_DIR . '/ewd-premium-helper/ewd-premium-helper.php' );
			$helper_descriptor = array();

			if ( class_exists( 'ewdphOTPCompatibility' ) ) {
				$helper_descriptor = ewdphOTPCompatibility::get_descriptor();
			}

			$premium_active = is_object( $ewd_otp_controller )
				&& is_object( $ewd_otp_controller->permissions )
				&& $ewd_otp_controller->permissions->check_permission( 'premium' );

			$result                        = self::evaluate( $helper_active, $helper_installed, $helper_descriptor, $premium_active );
			$result['presence_state']      = $helper_active ? 'active' : ( $helper_installed ? 'installed_inactive' : 'not_installed' );
			$result['compatibility_state'] = $this->normalize_compatibility_state( $result['state'], $helper_active );
			$result['entitlement_state']   = $this->resolve_entitlement_state( $premium_active );

			$this->state   = $result['state'];
			$this->details = $result;

			do_action( 'ewd_otp_helper_compatibility_state', $this->state, $this->details );
		}

		/**
		 * Handle the evaluate operation.
		 *
		 * @param mixed $helper_active helper active.
		 * @param mixed $helper_installed helper installed.
		 * @param mixed $helper_descriptor helper descriptor.
		 * @param mixed $premium_active premium active.
		 */
		public static function evaluate( $helper_active, $helper_installed, $helper_descriptor, $premium_active ) {

			if ( ! $helper_active ) {
				return array( 'state' => $helper_installed ? 'installed_inactive' : 'missing' );
			}

			if ( empty( $helper_descriptor ) || ! is_array( $helper_descriptor ) ) {
				return array( 'state' => 'active_legacy_no_descriptor' );
			}

			$helper_version = isset( $helper_descriptor['plugin_version'] ) ? $helper_descriptor['plugin_version'] : '0';
			$helper_api     = isset( $helper_descriptor['api_version'] ) ? absint( $helper_descriptor['api_version'] ) : 0;
			$free_api_min   = isset( $helper_descriptor['supported_free_api_min'] ) ? absint( $helper_descriptor['supported_free_api_min'] ) : 0;
			$free_api_max   = isset( $helper_descriptor['supported_free_api_max'] ) ? absint( $helper_descriptor['supported_free_api_max'] ) : 0;

			if ( version_compare( $helper_version, EWD_OTP_MIN_HELPER_VERSION, '<' )
				|| $helper_api < EWD_OTP_HELPER_API_VERSION
				|| EWD_OTP_HELPER_API_VERSION < $free_api_min ) {
				return array(
					'state'          => 'too_old',
					'helper_version' => $helper_version,
					'helper_api'     => $helper_api,
				);
			}

			if ( $helper_api > EWD_OTP_MAX_HELPER_API_VERSION
				|| EWD_OTP_HELPER_API_VERSION > $free_api_max ) {
				return array(
					'state'          => 'unsupported_newer_api',
					'helper_version' => $helper_version,
					'helper_api'     => $helper_api,
				);
			}

			return array(
				'state'          => $premium_active ? 'compatible' : 'compatible_premium_inactive',
				'helper_version' => $helper_version,
				'helper_api'     => $helper_api,
			);
		}

		/**
		 * Handle the get state operation.
		 */
		public function get_state() {
			return $this->state;
		}

		/**
		 * Handle the get details operation.
		 */
		public function get_details() {
			return $this->details;
		}

		/**
		 * Handle the get lifecycle status operation.
		 */
		public function get_lifecycle_status() {
			return array(
				'presence'      => isset( $this->details['presence_state'] ) ? $this->details['presence_state'] : 'not_installed',
				'compatibility' => isset( $this->details['compatibility_state'] ) ? $this->details['compatibility_state'] : 'not_applicable',
				'entitlement'   => isset( $this->details['entitlement_state'] ) ? $this->details['entitlement_state'] : 'free_no_entitlement',
			);
		}

		/**
		 * Handle the get lifecycle labels operation.
		 */
		public function get_lifecycle_labels() {
			$status = $this->get_lifecycle_status();
			$labels = array(
				'presence'      => array(
					'not_installed'      => __( 'Not installed', 'order-tracking' ),
					'installed_inactive' => __( 'Installed but inactive', 'order-tracking' ),
					'active'             => __( 'Active', 'order-tracking' ),
				),
				'compatibility' => array(
					'not_applicable'       => __( 'Not applicable', 'order-tracking' ),
					'compatible'           => __( 'Compatible', 'order-tracking' ),
					'helper_too_old'       => __( 'Helper needs updating', 'order-tracking' ),
					'free_api_unsupported' => __( 'Order Tracking needs updating', 'order-tracking' ),
					'legacy_unsupported'   => __( 'Legacy Helper unsupported', 'order-tracking' ),
				),
				'entitlement'   => array(
					'free_no_entitlement' => __( 'Free features', 'order-tracking' ),
					'trial_active'        => __( 'Trial active', 'order-tracking' ),
					'paid_active'         => __( 'Paid access active', 'order-tracking' ),
					'key_missing'         => __( 'Product key not activated', 'order-tracking' ),
				),
			);

			return array(
				'presence'      => isset( $labels['presence'][ $status['presence'] ] ) ? $labels['presence'][ $status['presence'] ] : $status['presence'],
				'compatibility' => isset( $labels['compatibility'][ $status['compatibility'] ] ) ? $labels['compatibility'][ $status['compatibility'] ] : $status['compatibility'],
				'entitlement'   => isset( $labels['entitlement'][ $status['entitlement'] ] ) ? $labels['entitlement'][ $status['entitlement'] ] : $status['entitlement'],
			);
		}

		/**
		 * Handle the normalize compatibility state operation.
		 *
		 * @param mixed $state state.
		 * @param mixed $helper_active helper active.
		 */
		private function normalize_compatibility_state( $state, $helper_active ) {
			if ( ! $helper_active ) {
				return 'not_applicable';
			}
			if ( in_array( $state, array( 'compatible', 'compatible_premium_inactive' ), true ) ) {
				return 'compatible';
			}
			if ( 'too_old' === $state ) {
				return 'helper_too_old';
			}
			if ( 'unsupported_newer_api' === $state ) {
				return 'free_api_unsupported';
			}
			return 'legacy_unsupported';
		}

		/**
		 * Handle the resolve entitlement state operation.
		 *
		 * @param mixed $premium_active premium active.
		 */
		private function resolve_entitlement_state( $premium_active ) {
			$trial_active = 'Yes' === get_option( 'EWD_OTP_Trial_Happening' )
				&& time() < absint( get_option( 'EWD_OTP_Trial_Expiry_Time' ) );
			if ( $trial_active ) {
				return 'trial_active';
			}
			if ( $premium_active ) {
				return 'paid_active';
			}
			if ( defined( 'EWDPH_VERSION' ) && '' === trim( (string) get_option( 'EWD_OTP_License_Key', '' ) ) ) {
				return 'key_missing';
			}
			return 'free_no_entitlement';
		}

		/**
		 * Handle the is compatible operation.
		 */
		public function is_compatible() {
			return in_array( $this->state, array( 'compatible', 'compatible_premium_inactive' ), true );
		}

		/**
		 * Handle the guard incompatible helper callback operation.
		 */
		public function guard_incompatible_helper_callback() {

			global $ewd_premium_helper;

			$this->refresh();

			if ( $this->is_compatible() || ! is_object( $ewd_premium_helper ) ) {
				return;
			}

			remove_action( 'ewd_otp_initialized', array( $ewd_premium_helper, 'extend_ewd_otp_plugin' ) );
		}

		/**
		 * Handle the display admin notice operation.
		 */
		public function display_admin_notice() {

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! in_array( $this->state, array( 'active_legacy_no_descriptor', 'too_old', 'unsupported_newer_api' ), true ) ) {
				return;
			}

			if ( 'unsupported_newer_api' === $this->state ) {
				$message = __( 'EWD Premium Helper uses a newer Order Tracking compatibility API. Update Order Tracking before premium features can load.', 'order-tracking' );
			} else {
				$message = sprintf(
					/* translators: %s: minimum compatible Premium Helper version. */
					esc_html__( 'EWD Premium Helper is not compatible with this version of Order Tracking. Update Premium Helper to version %s or newer. Free tracking features remain available.', 'order-tracking' ),
					esc_html( EWD_OTP_MIN_HELPER_VERSION )
				);
			}

			?>
		<div class="notice notice-error"><p><?php echo wp_kses_post( $message ); ?></p></div>
			<?php
		}
	}
}
