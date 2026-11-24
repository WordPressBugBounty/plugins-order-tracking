<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpWooCommerce' ) ) {
	/**
	 * Class to handle interactions with the WooCommerce platform
	 *
	 * @since 3.0.0
	 */
	class ewdotpWooCommerce {


		const REVERSION_RESULT_OPTION = 'ewd-otp-woocommerce-revert-result';
		const SYNC_FAILURE_OPTION     = 'ewd-otp-woocommerce-sync-failures';
		const REVERSION_BATCH_SIZE    = 50;
		const REVERSION_MAX_PER_RUN   = 500;

		/**
		 * Maintenance updates must not re-enter OTP's normal Woo synchronization.
		 *
		 * @var bool
		 */
		protected static $reversion_in_progress = false;
		public function __construct() {
			add_action( 'init', array( $this, 'add_hooks' ) );
		}

		/**
		 * Adds in the necessary hooks to handle WooCommerce integration
		 *
		 * @since 3.0.0
		 */
		public function add_hooks() {
			global $ewd_otp_controller;
			if ( $ewd_otp_controller->settings->get_raw_setting( 'woocommerce-replace-statuses' ) ) {
				add_filter( 'wc_order_statuses', array( $this, 'filter_statuses' ) );
			}
		}

		/**
		 * Automatically create an OTP order when admin creates an order
		 *
		 * @since 3.4.0
		 */
		public function maybe_add_admin_order( $post_id, $post ) {
			return $this->premium_call( 'maybe_add_admin_order', array( $post_id, $post ) );
		}

		/**
		 * Automatically create an OTP order after WC checkout, if enabled
		 *
		 * @param int            $post_id           WooCommerce order identifier.
		 * @param WC_Order|false $woocommerce_order Optional WooCommerce order.
		 * @since 3.0.0
		 */
		public function add_order( $post_id, $woocommerce_order = false ) {
			return $this->premium_call( 'add_order', array( $post_id, $woocommerce_order ) );
		}
		/**
		 * Update an WC order's OTP equivalent, when the WC order gets a new status
		 *
		 * @since 3.0.0
		 */
		public function update_order( $post_id, $old_status = '', $new_status = '' ) {
			return $this->premium_call( 'update_order', array( $post_id, $old_status, $new_status ) );
		}

		/**
		 * Preserve the documented OTP-to-Woo status direction using Woo CRUD.
		 *
		 * @param ewdotpOrder $order      OTP order.
		 * @param string      $old_status Previous OTP status.
		 * @param bool        $reconcile Explicit administrator reconciliation.
		 * @return bool
		 * @since 3.6.0
		 */
		public function update_woocommerce_status( $order, $old_status = '', $reconcile = false ) {
			return $this->premium_call( 'update_woocommerce_status', array( $order, $old_status, $reconcile ) );
		}

		/** Retry a bounded set of recorded OTP-to-WooCommerce status writes. */
		public function retry_failed_status_syncs() {
			return $this->premium_call( 'retry_failed_status_syncs', array() );
		}

		/** Explicit administrator reconciliation uses the current OTP status, never the queued target. */
		public function reconcile_current_status() {
			return $this->premium_call( 'reconcile_current_status', array() );
		}

		/**
		 * Return the non-sensitive failure of the last sync or retry invocation.
		 *
		 * @return array|null
		 */
		public function get_last_sync_error() {
			return $this->premium_call( 'get_last_sync_error', array() );
		}

		/** Show a fixed, administrator-only outcome after explicit reconciliation. */
		public function reconciliation_notice() {
			return $this->premium_call( 'reconciliation_notice', array() );
		}

		/**
		 * Replace the WC statuses with the OTP statuses for WC products
		 *
		 * @since 3.0.0
		 */
		public function filter_statuses( $wc_statuses ) {
			global $ewd_otp_controller;
			global $wp_post_statuses;

			$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

			foreach ( $statuses as $status ) {

				$sanitized_status = sanitize_title( $status->status, '', 'ewd_otp' );

				if ( '' === $sanitized_status ) {
					continue;
				}
				if ( ! isset( $wc_statuses[ 'wc-' . $sanitized_status ] ) ) {
					$wc_statuses[ 'wc-' . $sanitized_status ] = $status->status;
				}

				if ( ! empty( $wp_post_statuses[ 'wc-' . $sanitized_status ] ) ) {
					continue; }

				$args = array(
					'name'                      => 'wc-' . $sanitized_status,
					'label'                     => $status->status,
					'label_count'               => false,
					'exclude_from_search'       => null,
					'_builtin'                  => false,
					'internal'                  => null,
					'protected'                 => null,
					'private'                   => null,
					'publicly_queryable'        => null,
					'show_in_admin_status_list' => null,
					'show_in_admin_all_list'    => true,
					'post_type'                 => array( 'shop_order' ),
				);

				$wp_post_statuses[ 'wc-' . $sanitized_status ] = (object) $args;
			}

			return $wc_statuses;
		}

		/**
		 * Allow WC orders to be set one of the different OTP statuses
		 *
		 * @since 3.0.0
		 */
		public function add_custom_status_bulk_actions( $actions ) {
			return $this->premium_call( 'add_custom_status_bulk_actions', array( $actions ) );
		}

		/**
		 * Get the OTP equivalent for one or multiple WC statuses
		 *
		 * @since 3.0.0
		 */
		public function get_equivalent_status( $statuses ) {
			return $this->premium_call( 'get_equivalent_status', array( $statuses ) );
		}

		/**
		 * Return the OTP equivalent status for the parent_order_status query_param
		 *
		 * @since 3.0.0
		 */
		public function report_parent_statuses( $query_params ) {
			return $this->premium_call( 'report_parent_statuses', array( $query_params ) );
		}

		/**
		 * Adds the tracking form to an order's view order page
		 *
		 * @since 3.4.0
		 */
		public function add_tracking_form_to_order_view( $wc_order_id ) {
			return $this->premium_call( 'add_tracking_form_to_order_view', array( $wc_order_id ) );
		}

		/**
		 * Adds the tracking information for an order to that order's page
		 *
		 * @since 3.0.0
		 */
		public function add_tracking_to_order_page( $wc_order ) {
			return $this->premium_call( 'add_tracking_to_order_page', array( $wc_order ) );
		}

		/**
		 * Adds the order's current location to an order's admin page
		 *
		 * @since 3.0.0
		 */
		public function add_order_location( $wc_order ) {
			return $this->premium_call( 'add_order_location', array( $wc_order ) );
		}

		/**
		 * Update an order after receiving a notification form Zendesk
		 *
		 * @since 3.0.0
		 */
		public function save_wc_location( $post_id, $wc_order = false ) {
			return $this->premium_call( 'save_wc_location', array( $post_id, $wc_order ) );
		}

		/**
		 * Get the OTP equivalent status of a WC status
		 *
		 * @since 3.0.0
		 */
		public function get_wc_status( $wc_status ) {

			$wc_status = preg_replace( '/^wc-/', '', (string) $wc_status );
			$map       = $this->get_status_map();
			return isset( $map[ $wc_status ] ) ? $map[ $wc_status ] : '';
		}

		/** Return one validated mapping used by both synchronization directions. */
		protected function get_status_map() {

			global $ewd_otp_controller;

			$configured = array();
			foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) ) as $status ) {
				if ( isset( $status->status ) ) {
					$configured[] = (string) $status->status;
				}
			}

			if ( $ewd_otp_controller->settings->get_setting( 'woocommerce-replace-statuses' ) ) {
				$map = array();
				foreach ( $configured as $status ) {
					$map[ sanitize_title( $status, '', 'ewd_otp' ) ] = $status;
				}
				return $map;
			}

			$setting_map = array(
				'completed'  => 'woocommerce-paid-status',
				'pending'    => 'woocommerce-unpaid-status',
				'processing' => 'woocommerce-processing-status',
				'cancelled'  => 'woocommerce-cancelled-status',
				'on-hold'    => 'woocommerce-onhold-status',
				'failed'     => 'woocommerce-failed-status',
				'refunded'   => 'woocommerce-refunded-status',
			);
			$map         = array();
			foreach ( $setting_map as $wc_status => $setting ) {
				$otp_status = (string) $ewd_otp_controller->settings->get_raw_setting( $setting );
				if ( '' === $otp_status || ! in_array( $otp_status, $configured, true ) || in_array( $otp_status, $map, true ) ) {
					return array();
				}
				$map[ $wc_status ] = $otp_status;
			}
			return $map;
		}
		/**
		 * Restore OTP replacement statuses through Woo's active order data store.
		 *
		 * A direct data-store update intentionally bypasses WC_Order::save() and
		 * Woo's normal order-status transition pipeline. General order-update hooks
		 * (and classic WordPress post-status hooks) remain enabled.
		 *
		 * @since 3.0.0
		 * @return true|WP_Error
		 * @throws RuntimeException If a post-reversion WooCommerce query fails unexpectedly.
		 */
		public function revert_statuses() {
			global $ewd_otp_controller;

			if ( ! $ewd_otp_controller->settings->get_setting( 'woocommerce-replace-statuses' ) ) {
				return true;
			}
			if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
				return new WP_Error( 'ewd_otp_woocommerce_missing', __( 'WooCommerce must be active to safely revert custom order statuses before deactivation.', 'order-tracking' ) );
			}
			if ( self::$reversion_in_progress ) {
				return new WP_Error( 'ewd_otp_reversion_in_progress', __( 'WooCommerce status reversion is already running in this request.', 'order-tracking' ) );
			}

			$canonical = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed', 'refunded' );
			$settings  = array(
				'woocommerce-paid-status'       => 'completed',
				'woocommerce-unpaid-status'     => 'pending',
				'woocommerce-processing-status' => 'processing',
				'woocommerce-cancelled-status'  => 'cancelled',
				'woocommerce-onhold-status'     => 'on-hold',
				'woocommerce-failed-status'     => 'failed',
				'woocommerce-refunded-status'   => 'refunded',
			);
			$sources   = array();
			foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) ) as $status ) {
				if ( empty( $status->status ) ) {
					continue;
				}
				$source = sanitize_title( $status->status, '', 'ewd_otp' );
				if ( '' === $source || in_array( $source, $canonical, true ) ) {
					continue;
				}
				$target = 'processing';
				foreach ( $settings as $setting => $fallback ) {
					if ( $status->status === $ewd_otp_controller->settings->get_setting( $setting ) ) {
						$target = $fallback;
						break;
					}
				}
				$sources[ $source ] = $target;
			}
			if ( ! $sources ) {
				return true;
			}

			$failures  = array();
			$attempted = 0;

			self::$reversion_in_progress = true;
			try {
				foreach ( $sources as $source => $target ) {
					while ( $attempted < self::REVERSION_MAX_PER_RUN ) {
						try {
							$order_ids = wc_get_orders(
								array(
									'type'    => 'shop_order',
									'status'  => array( 'wc-' . $source ),
									'limit'   => min( self::REVERSION_BATCH_SIZE, self::REVERSION_MAX_PER_RUN - $attempted ),
									'return'  => 'ids',
									'exclude' => array_keys( $failures ),
								)
							);
							if ( ! is_array( $order_ids ) ) {
								throw new RuntimeException( 'WooCommerce order query did not return IDs.' );
							}
						} catch ( Throwable $exception ) {
							$failures[0] = __( 'WooCommerce could not query orders for status reversion.', 'order-tracking' );
							$this->log_reversion_failure( 0, $exception );
							break 2;
						}
						if ( ! $order_ids ) {
							break;
						}
						foreach ( $order_ids as $order_id ) {
							++$attempted;
							$order_id = absint( $order_id );
							try {
								$order = wc_get_order( $order_id );
								if ( ! $order || ! is_callable( array( $order, 'get_data_store' ) ) ) {
									throw new RuntimeException( 'WooCommerce order could not be loaded.' );
								}
								if ( sanitize_key( $order->get_status() ) !== $source ) {
									continue;
								}
								$order->set_status( $target );
								$data_store = $order->get_data_store();
								if ( ! $data_store || ! is_callable( array( $data_store, 'update' ) ) || false === $data_store->update( $order ) ) {
									throw new RuntimeException( 'WooCommerce data-store update failed.' );
								}
								$reloaded = wc_get_order( $order_id );
								if ( ! $reloaded || $target !== $reloaded->get_status() ) {
									throw new RuntimeException( 'WooCommerce did not persist the canonical status.' );
								}
							} catch ( Throwable $exception ) {
								$failures[ $order_id ] = __( 'WooCommerce could not persist the canonical status.', 'order-tracking' );
								$this->log_reversion_failure( $order_id, $exception );
							}
						}
					}
				}
			} finally {
				self::$reversion_in_progress = false;
			}

			$remaining = ! empty( $failures ) || $this->has_reversion_work( array_keys( $sources ) );
			update_option(
				self::REVERSION_RESULT_OPTION,
				array(
					'failures'      => $failures,
					'remaining'     => $remaining,
					'processed'     => $attempted,
					'completed_gmt' => current_time( 'mysql', true ),
				),
				false
			);
			if ( $failures ) {
				/* translators: %d: number of WooCommerce orders that failed reversion. */
				return new WP_Error( 'ewd_otp_reversion_failed', sprintf( __( '%d WooCommerce orders could not be reverted. Their IDs and errors were recorded; deactivation was stopped.', 'order-tracking' ), count( $failures ) ) );
			}
			if ( $remaining ) {
				return new WP_Error( 'ewd_otp_reversion_incomplete', __( 'WooCommerce status reversion reached its safe per-run limit. Retry deactivation to resume.', 'order-tracking' ) );
			}
			return true;
		}

		/**
		 * Log a non-sensitive maintenance failure without showing internals in the browser.
		 *
		 * @param int       $order_id WooCommerce order ID, or zero for a query failure.
		 * @param Throwable $exception Error class for diagnostics.
		 * @return void
		 */
		private function log_reversion_failure( $order_id, $exception ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			/* translators: 1: WooCommerce order ID; 2: failure type. */
			$message = sprintf( __( 'Order Tracking status reversion failed for Woo order %1$d (%2$s).', 'order-tracking' ), $order_id, get_class( $exception ) );
			wc_get_logger()->error( $message, array( 'source' => 'order-tracking' ) );
		}

		/**
		 * Determine whether custom-status orders remain to be reverted.
		 *
		 * @param string[] $statuses Custom WooCommerce statuses.
		 * @return bool
		 */
		private function has_reversion_work( $statuses ) {

			foreach ( $statuses as $status ) {
				$orders = wc_get_orders(
					array(
						'type'   => 'shop_order',
						'status' => array( 'wc-' . sanitize_key( $status ) ),
						'limit'  => 1,
						'return' => 'ids',
					)
				);
				if ( ! empty( $orders ) ) {
					return true;
				}
			}
			return false;
		}
		/**
		 * Forward a stable public operation to the selected compatible runtime.
		 *
		 * @param string $method Fixed public method name.
		 * @param array  $arguments Positional legacy arguments.
		 * @return mixed False when paid synchronization is unavailable.
		 */
		private function premium_call( $method, $arguments ) {
			global $ewd_otp_controller;
			$runtime = isset( $ewd_otp_controller->woocommerce ) ? $ewd_otp_controller->woocommerce : null;
			if ( $runtime === $this || ! is_object( $runtime ) || ! is_subclass_of( $runtime, __CLASS__ ) || ! is_callable( array( $runtime, $method ) ) || ! ewdotpEntitlement::allows( 'woocommerce' ) ) {
				return false;
			}
			return call_user_func_array( array( $runtime, $method ), $arguments );
		}
	}

}
