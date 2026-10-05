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
		 * WooCommerce orders currently being synchronized.
		 *
		 * @var array<int,bool>
		 */
		private $syncing = array();

		/**
		 * Maintenance updates must not re-enter OTP's normal Woo synchronization.
		 *
		 * @var bool
		 */
		private static $reversion_in_progress = false;
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

			if ( empty( $ewd_otp_controller->settings->get_setting( 'woocommerce-integration' ) ) ) {
				return; }

			add_action( 'woocommerce_new_order', array( $this, 'add_order' ), 10, 2 );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'add_order' ), 10, 1 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'update_order' ) );
			add_action( 'ewd_otp_status_updated', array( $this, 'update_woocommerce_status' ), 10, 2 );
			add_action( 'ewd_otp_retry_woocommerce_status_syncs', array( $this, 'retry_failed_status_syncs' ) );
			if ( $ewd_otp_controller->settings->get_setting( 'woocommerce-replace-statuses' ) ) {

				add_filter( 'wc_order_statuses', array( $this, 'filter_statuses' ) );
				add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_custom_status_bulk_actions' ), 99 );
				add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_custom_status_bulk_actions' ), 99 );
				add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_bacs_process_payment_order_status', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_default_order_status', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( $this, 'get_equivalent_status' ) );
				add_filter( 'woocommerce_reports_order_statuses', array( $this, 'get_equivalent_status' ) );

				add_filter( 'woocommerce_reports_get_order_report_data_args', array( $this, 'report_parent_statuses' ) );
			}

			if ( $ewd_otp_controller->settings->get_setting( 'woocommerce-show-on-view-order' ) ) {

				add_action( 'woocommerce_view_order', array( $this, 'add_tracking_form_to_order_view' ) );
			}

			if ( $ewd_otp_controller->settings->get_setting( 'woocommerce-show-on-order-page' ) ) {

				add_action( 'woocommerce_order_details_after_order_table', array( $this, 'add_tracking_to_order_page' ) );
			}

			if ( $ewd_otp_controller->settings->get_setting( 'woocommerce-locations-enabled' ) ) {

				add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'add_order_location' ) );
				add_action( 'woocommerce_update_order', array( $this, 'save_wc_location' ), 10, 2 );
			}
		}

		/**
		 * Automatically create an OTP order when admin creates an order
		 *
		 * @since 3.4.0
		 */
		public function maybe_add_admin_order( $post_id, $post ) {

			$woocommerce_order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : false;
			if ( ! $woocommerce_order || 'auto-draft' === $woocommerce_order->get_status() ) {
				return;
			}
			$this->add_order( $post_id, $woocommerce_order );
		}

		/**
		 * Automatically create an OTP order after WC checkout, if enabled
		 *
		 * @param int            $post_id           WooCommerce order identifier.
		 * @param WC_Order|false $woocommerce_order Optional WooCommerce order.
		 * @since 3.0.0
		 */
		public function add_order( $post_id, $woocommerce_order = false ) {

			global $ewd_otp_controller;
			if ( self::$reversion_in_progress ) {
				return;
			}

			$woocommerce_order = is_a( $woocommerce_order, 'WC_Order' ) ? $woocommerce_order : wc_get_order( $post_id );
			if ( ! $woocommerce_order || ! is_a( $woocommerce_order, 'WC_Order' ) ) {
				return;
			}

			$post_id = absint( $woocommerce_order->get_id() );
			if ( $ewd_otp_controller->order_manager->get_order_from_woocommerce_id( $post_id ) ) {
				return; }
			$order = new ewdotpOrder();

			$order->name    = __( 'WooCommerce Order #', 'order-tracking' ) . $woocommerce_order->get_order_number();
			$order->number  = $ewd_otp_controller->settings->get_setting( 'woocommerce-prefix' ) . $post_id . ( ! $ewd_otp_controller->settings->get_setting( 'woocommerce-disable-random-suffix' ) ? ewd_random_string( 4 ) : '' );
			$order->email   = sanitize_email( $woocommerce_order->get_billing_email() );
			$order->display = true;

			$order->woocommerce_id    = $post_id;
			$order->payment_completed = true;

			$order->status          = $this->get_wc_status( $woocommerce_order->get_status() );
			$order->external_status = $order->status;
			if ( $woocommerce_order->get_customer_id() ) {
				$order->customer = $ewd_otp_controller->customer_manager->get_customer_id_from_wp_id( $woocommerce_order->get_customer_id() ); } else {
				$order->customer = $ewd_otp_controller->customer_manager->get_customer_id_from_name( trim( $woocommerce_order->get_billing_first_name() . ' ' . $woocommerce_order->get_billing_last_name() ) ); }
				$custom_fields = (array) get_option( 'ewd-otp-custom-fields', array() );
				foreach ( $custom_fields  as $custom_field ) {

					if ( 'none' === $custom_field->equivalent ) {
						continue; }

					$order->custom_fields[ $custom_field->id ] = sanitize_text_field( $woocommerce_order->get_meta( $custom_field->equivalent, true ) );
				}

				if ( ! $order->insert_order_with_history() ) {
					return; }
		}
		/**
		 * Update an WC order's OTP equivalent, when the WC order gets a new status
		 *
		 * @since 3.0.0
		 */
		public function update_order( $post_id, $old_status = '', $new_status = '' ) {

			global $ewd_otp_controller;

			$post_id = absint( $post_id );
			if ( self::$reversion_in_progress || isset( $this->syncing[ $post_id ] ) ) {
				return;
			}
			$wc_order = wc_get_order( $post_id );
			if ( ! $wc_order || ! is_a( $wc_order, 'WC_Order' ) ) {
				return; }
			$woocommerce_order = $ewd_otp_controller->order_manager->get_order_from_woocommerce_id( $post_id );

			if ( ! $woocommerce_order ) {
				return; }

			$order = new ewdotpOrder();
			$order->load_order( $woocommerce_order );

			$mapped_status = $this->get_wc_status( $new_status ? $new_status : $wc_order->get_status() );
			if ( '' === $mapped_status || $mapped_status === $order->status ) {
				return;
			}

			$this->syncing[ $post_id ] = true;
			try {
				if ( ! $order->set_status( $mapped_status ) ) {
					return false;
				}
			} finally {
				unset( $this->syncing[ $post_id ] );
			}
		}

		/**
		 * Preserve the documented OTP-to-Woo status direction using Woo CRUD.
		 *
		 * @param ewdotpOrder $order      OTP order.
		 * @param string      $old_status Previous OTP status.
		 * @return bool
		 * @since 3.6.0
		 */
		public function update_woocommerce_status( $order, $old_status = '' ) {
			global $ewd_otp_controller;
			unset( $old_status );
			if ( self::$reversion_in_progress ) {
				return true;
			}

			if ( ! is_object( $order ) || empty( $order->woocommerce_id ) ) {
					return true;
			}
			$wc_order_id = absint( $order->woocommerce_id );
			if ( isset( $this->syncing[ $wc_order_id ] ) ) {
				return true;
			}

			$wc_order = wc_get_order( $wc_order_id );
			if ( ! $wc_order ) {
				$this->record_sync_failure( $order, '', __( 'The WooCommerce order could not be loaded.', 'order-tracking' ) );
				return false;
			}
			$target = array_search( $order->status, $this->get_status_map(), true );

			if ( empty( $target ) || $wc_order->get_status() === $target ) {
					$this->clear_sync_failure( $wc_order_id );
				return true;
			}
			$this->syncing[ $wc_order_id ] = true;
			try {
				$result = $wc_order->update_status( $target, __( 'Synchronized from Order Tracking.', 'order-tracking' ), true );
				if ( true !== $result ) {
					$this->record_sync_failure( $order, $target, __( 'WooCommerce returned false while saving the status.', 'order-tracking' ) );
					return false;
				}
				$this->clear_sync_failure( $wc_order_id );
				return true;
			} catch ( Throwable $exception ) {
				$this->record_sync_failure( $order, $target, $exception->getMessage() );
				return false;
			} finally {
				unset( $this->syncing[ $wc_order_id ] );
			}
		}

		/** Retry a bounded set of recorded OTP-to-WooCommerce status writes. */
		public function retry_failed_status_syncs() {
			if ( self::$reversion_in_progress ) {
				return;
			}

			$failures = (array) get_option( self::SYNC_FAILURE_OPTION, array() );
			foreach ( array_slice( $failures, 0, 25, true ) as $wc_order_id => $failure ) {
				$wc_order = wc_get_order( absint( $wc_order_id ) );
				$target   = isset( $failure['target_status'] ) ? sanitize_key( $failure['target_status'] ) : '';
				if ( ! $wc_order || '' === $target ) {
					continue;
				}

				try {
					$result = $wc_order->update_status( $target, __( 'Retry synchronized from Order Tracking.', 'order-tracking' ), true );
					if ( true === $result ) {
						$this->clear_sync_failure( $wc_order_id );
						continue;
					}
					$this->refresh_sync_failure( $wc_order_id, __( 'WooCommerce returned false while retrying the status.', 'order-tracking' ) );
				} catch ( Throwable $exception ) {
						$this->refresh_sync_failure( $wc_order_id, $exception->getMessage() );
				}
			}

			if ( get_option( self::SYNC_FAILURE_OPTION, array() ) ) {
				$this->schedule_sync_retry();
			}
		}

		/**
		 * Record a failed outbound WooCommerce synchronization.
		 *
		 * @param ewdotpOrder $order   OTP order.
		 * @param string      $target  Target WooCommerce status.
		 * @param string      $message Failure message.
		 * @return void
		 */
		private function record_sync_failure( $order, $target, $message ) {

				$failures             = (array) get_option( self::SYNC_FAILURE_OPTION, array() );
			$wc_order_id              = absint( $order->woocommerce_id );
			$previous                 = isset( $failures[ $wc_order_id ] ) ? $failures[ $wc_order_id ] : array();
			$failures[ $wc_order_id ] = array(
				'order_id'         => absint( $order->id ),
				'target_status'    => sanitize_key( $target ),
				'attempts'         => 1 + absint( isset( $previous['attempts'] ) ? $previous['attempts'] : 0 ),
				'last_error'       => sanitize_text_field( $message ),
				'last_attempt_gmt' => current_time( 'mysql', true ),
			);
			update_option( self::SYNC_FAILURE_OPTION, $failures, false );
			$this->schedule_sync_retry();
		}

		/**
		 * Refresh retry metadata for a failed synchronization.
		 *
		 * @param int    $wc_order_id WooCommerce order identifier.
		 * @param string $message     Failure message.
		 * @return void
		 */
		private function refresh_sync_failure( $wc_order_id, $message ) {

			$failures = (array) get_option( self::SYNC_FAILURE_OPTION, array() );
			if ( ! isset( $failures[ $wc_order_id ] ) ) {
				return;
			}
			$failures[ $wc_order_id ]['attempts']         = 1 + absint( $failures[ $wc_order_id ]['attempts'] );
			$failures[ $wc_order_id ]['last_error']       = sanitize_text_field( $message );
			$failures[ $wc_order_id ]['last_attempt_gmt'] = current_time( 'mysql', true );
			update_option( self::SYNC_FAILURE_OPTION, $failures, false );
		}

		/**
		 * Remove a completed synchronization retry.
		 *
		 * @param int $wc_order_id WooCommerce order identifier.
		 * @return void
		 */
		private function clear_sync_failure( $wc_order_id ) {

			$failures = (array) get_option( self::SYNC_FAILURE_OPTION, array() );
			unset( $failures[ absint( $wc_order_id ) ] );
			update_option( self::SYNC_FAILURE_OPTION, $failures, false );
		}

		/** Schedule a bounded outbound synchronization retry. */
		private function schedule_sync_retry() {

			if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'ewd_otp_retry_woocommerce_status_syncs' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ewd_otp_retry_woocommerce_status_syncs' );
			}
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
			global $ewd_otp_controller;

			$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

			if ( isset( $actions['mark_processing'] ) ) {
				unset( $actions['mark_processing'] ); }
			if ( isset( $actions['mark_on-hold'] ) ) {
				unset( $actions['mark_on-hold'] ); }
			if ( isset( $actions['mark_completed'] ) ) {
				unset( $actions['mark_completed'] ); }

			foreach ( $statuses as $status ) {

				$sanitized_status = sanitize_title( $status->status, '', 'ewd_otp' );

				$actions[ 'mark_' . $sanitized_status ] = __( 'Change status to ', 'order-tracking' ) . $status->status;
			}

			return $actions;
		}

		/**
		 * Get the OTP equivalent for one or multiple WC statuses
		 *
		 * @since 3.0.0
		 */
		public function get_equivalent_status( $statuses ) {
			global $ewd_otp_controller;

			$statuses_array = is_array( $statuses ) ? $statuses : (array) $statuses;

			$equivalent_statuses = array(
				'completed'  => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-paid-status' ), '', 'ewd_otp' ),
				'pending'    => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-unpaid-status' ), '', 'ewd_otp' ),
				'processing' => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-processing-status' ), '', 'ewd_otp' ),
				'cancelled'  => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-cancelled-status' ), '', 'ewd_otp' ),
				'on-hold'    => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-onhold-status' ), '', 'ewd_otp' ),
				'failed'     => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-failed-status' ), '', 'ewd_otp' ),
				'refunded'   => sanitize_title( $ewd_otp_controller->settings->get_setting( 'woocommerce-refunded-status' ), '', 'ewd_otp' ),
			);

			$return_statuses = array();
			foreach ( $statuses_array as $key => $status ) {

				$return_statuses[ $key ] = isset( $equivalent_statuses[ $status ] ) && '' !== $equivalent_statuses[ $status ] ? $equivalent_statuses[ $status ] : $status;
			}

			return is_array( $statuses ) ? $return_statuses : reset( $return_statuses );
		}

		/**
		 * Return the OTP equivalent status for the parent_order_status query_param
		 *
		 * @since 3.0.0
		 */
		public function report_parent_statuses( $query_params ) {

			if ( isset( $query_params['parent_order_status'] ) ) {

				$equivalent_status                   = $this->get_equivalent_status( $query_params['parent_order_status'] );
				$query_params['parent_order_status'] = $equivalent_status;
			}

			return $query_params;
		}

		/**
		 * Adds the tracking form to an order's view order page
		 *
		 * @since 3.4.0
		 */
		public function add_tracking_form_to_order_view( $wc_order_id ) {
			global $ewd_otp_controller;

			$db_order = $ewd_otp_controller->order_manager->get_order_from_woocommerce_id( $wc_order_id );

			if ( empty( $db_order ) ) {
				return; }

			$order = new ewdotpOrder();
			$order->load_order( $db_order );

			echo do_shortcode( '[order-tracking order_id=\'' . $order->id . '\']' );
		}

		/**
		 * Adds the tracking information for an order to that order's page
		 *
		 * @since 3.0.0
		 */
		public function add_tracking_to_order_page( $wc_order ) {
			global $ewd_otp_controller;

			$db_order = $ewd_otp_controller->order_manager->get_order_from_woocommerce_id( $wc_order->get_id() );
			if ( empty( $db_order ) ) {
				return; }

			$order = new ewdotpOrder();
			$order->load_order( $db_order );
			$order->load_order_status_history();

			?>
	
		<h2>
			<?php _e( 'Tracking Information', 'order-tracking' ); ?>
		</h2>

		<table class='shop_table shop_table_responsive'>
			
			<thead>

				<tr>
					
					<th><?php _e( 'Order Status', 'order-tracking' ); ?></th>
					<th><?php _e( 'Order Location', 'order-tracking' ); ?></th>
					<th><?php _e( 'Updated', 'order-tracking' ); ?></th>

				</tr>

			</thead>

			<tbody>
				
				<?php foreach ( $order->status_history as $status ) { ?>
					
					<tr>
					
						<td><?php echo esc_html( $status->status ); ?></td>
						<td><?php echo esc_html( $status->location ); ?></td>
						<td><?php echo esc_html( $status->updated_fmtd ); ?></td>
					</tr>

				<?php } ?>
			
			</tbody>
		
		</table>

			<?php

			if ( empty( $ewd_otp_controller->settings->get_setting( 'tracking-page-url' ) ) ) {
				return; }

			$tracking_token = $order->generate_tracking_token();
			if ( '' === $tracking_token ) {
				return;
			}
			$args = array(
				'tracking_number' => $order->number,
				'tracking_token'  => $tracking_token,
			);
			echo '<p><a href="' . esc_url( add_query_arg( $args, $ewd_otp_controller->settings->get_setting( 'tracking-page-url' ) ) ) . '">' . __( 'View Detailed Tracking Information', 'order-tracking' ) . '</a></p>';
		}

		/**
		 * Adds the order's current location to an order's admin page
		 *
		 * @since 3.0.0
		 */
		public function add_order_location( $wc_order ) {
			global $ewd_otp_controller;

			$db_order = $ewd_otp_controller->order_manager->get_order_from_woocommerce_id( $wc_order->get_id() );
			if ( empty( $db_order ) ) {
				return; }

			$order = new ewdotpOrder();
			$order->load_order( $db_order );

			$locations = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'locations' ) );

			?>
		
		<p class="form-field form-field-wide wc-order-status">
		
			<label for="order_location"><?php _e( 'Location:', 'order-tracking' ); ?></label>
			<?php wp_nonce_field( 'ewd_otp_save_wc_location_' . absint( $wc_order->get_id() ), 'ewd_otp_wc_location_nonce' ); ?>

			<select id="order_location" name="order_location" class="wc-enhanced-select">
				
				<?php foreach ( $locations as $location ) { ?>
					
					<option value="<?php echo esc_attr( $location->name ); ?>" <?php echo ( $order->location == $location->name ? 'selected' : '' ); ?>>
						<?php echo esc_html( $location->name ); ?>
					</option>

				<?php } ?>

			</select>

		</p>

			<?php
		}

		/**
		 * Update an order after receiving a notification form Zendesk
		 *
		 * @since 3.0.0
		 */
		public function save_wc_location( $post_id, $wc_order = false ) {

			global $ewd_otp_controller;
			if ( self::$reversion_in_progress ) {
				return;
			}

			$post_id  = absint( $post_id );
			$wc_order = is_a( $wc_order, 'WC_Order' ) ? $wc_order : wc_get_order( $post_id );
			if ( ! $wc_order || ! $ewd_otp_controller->permissions->check_permission( 'locations' ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers both order-edit capabilities.
			if ( ! current_user_can( 'edit_shop_order', $post_id ) && ! current_user_can( 'edit_shop_orders' ) ) {
				return;
			}
			if ( ! isset( $_POST['ewd_otp_wc_location_nonce'], $_POST['order_location'] ) ) {
				return;
			}
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ewd_otp_wc_location_nonce'] ) ), 'ewd_otp_save_wc_location_' . $post_id ) ) {
				return;
			}

			$submitted_location = sanitize_text_field( wp_unslash( $_POST['order_location'] ) );
			$allowed_locations  = array();
			foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'locations' ) ) as $location ) {
				$allowed_locations[] = (string) $location->name;
			}
			if ( ! in_array( $submitted_location, $allowed_locations, true ) ) {
				return; }
			$db_order = $ewd_otp_controller->order_manager->get_order_from_woocommerce_id( $post_id );

			if ( empty( $db_order ) ) {
				return; }

			$order = new ewdotpOrder();
			$order->load_order( $db_order );

			if ( $submitted_location === $order->location ) {
				return;
			}

			return $order->set_location( $submitted_location );
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
		private function get_status_map() {

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
				$otp_status = (string) $ewd_otp_controller->settings->get_setting( $setting );
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
	}

}
