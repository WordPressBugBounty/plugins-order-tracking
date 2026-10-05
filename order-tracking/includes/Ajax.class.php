<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpAJAX' ) ) {
	/**
	 * Class to handle AJAX interactions for Order Tracking
	 *
	 * @since 3.0.0
	 */
	class ewdotpAJAX {

		public function __construct() {

			add_action( 'wp_ajax_ewd_otp_get_order', array( $this, 'get_order' ) );
			add_action( 'wp_ajax_nopriv_ewd_otp_get_order', array( $this, 'get_order' ) );

			add_action( 'wp_ajax_ewd_otp_get_customer_orders', array( $this, 'get_customer_orders' ) );
			add_action( 'wp_ajax_nopriv_ewd_otp_get_customer_orders', array( $this, 'get_customer_orders' ) );

			add_action( 'wp_ajax_ewd_otp_get_sales_rep_orders', array( $this, 'get_sales_rep_orders' ) );
			add_action( 'wp_ajax_nopriv_ewd_otp_get_sales_rep_orders', array( $this, 'get_sales_rep_orders' ) );

			add_action( 'wp_ajax_ewd_otp_update_customer_note', array( $this, 'update_customer_note' ) );
			add_action( 'wp_ajax_nopriv_ewd_otp_update_customer_note', array( $this, 'update_customer_note' ) );

			add_action( 'wp_ajax_ewd_otp_delete_order', array( $this, 'admin_delete_order' ) );
			add_action( 'wp_ajax_ewd_otp_hide_order', array( $this, 'admin_hide_order' ) );
			add_action( 'wp_ajax_ewd_otp_delete_customer', array( $this, 'admin_delete_customer' ) );
			add_action( 'wp_ajax_ewd_otp_delete_sales_rep', array( $this, 'admin_delete_sales_rep' ) );
		}

		/**
		 * Returns the output for a single order, given its tracking number and (optionally) email
		 *
		 * @since 3.0.0
		 */
		public function get_order() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-js', 'nonce' ) ) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			$order_number = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
			$proof        = $ewd_otp_controller->order_access->normalize_proof( $_POST );
			$order        = new ewdotpOrder();

			$order->load_order_from_tracking_number( $order_number );
			if ( empty( $order->id ) ) {

				wp_send_json_error(
					array(
						'output' => __(
							'There are no order statuses for tracking number: ',
							'order-tracking'
						) . $order_number,
					)
				);
			}

			$access = $ewd_otp_controller->order_access->authorize( $order, $proof );

			if ( ! $access['authorized'] ) {

				wp_send_json_error(
					array(
						'output' => $ewd_otp_controller->order_access->get_failure_message( $access['reason'] ),
					)
				);
			}

			if ( $access['token_valid'] ) {
				$order->set_tracking_link_clicked();
			}
			$order->load_order_status_history();

			$customer = new ewdotpCustomer();

			$customer->load_customer_from_id( $order->customer );

			$sales_rep = new ewdotpSalesRep();

			$sales_rep->load_sales_rep_from_id( $order->sales_rep );

			$args = array(
				'order'                   => $order,
				'customer'                => $customer,
				'sales_rep'               => $sales_rep,
				'notes_submit'            => isset( $_POST['customer_notes_label'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_notes_label'] ) ) : '',
				'access_email'            => $access['email'],
				'access_token'            => $access['token'],
				'access_collection_token' => $proof['collection_token'],
			);

			$order_view = new ewdotpViewOrderForm( $args );

			$order_view->set_order_form_options();

			ob_start();

			$order_view->maybe_print_order_results();

			$output = ob_get_clean();

			wp_send_json_success(
				array(
					'output' => $output,
				)
			);

			die();
		}

		/**
		 * Returns the customer order table for a given customer id
		 *
		 * @since 3.0.0
		 */
		public function get_customer_orders() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-js', 'nonce' ) ) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			$customer = new ewdotpCustomer();

			$customer->load_customer_from_number( sanitize_text_field( trim( $_POST['customer_number'] ) ) );

			$access = $ewd_otp_controller->order_access->authorize_collection( $customer, 'customer', $_POST );
			if ( ! $access['authorized'] ) {
				wp_send_json_error(
					array(
						'output' => $ewd_otp_controller->order_access->get_failure_message( $access['reason'] ),
					)
				);
			}

			$args = array(
				'customer'     => $customer,
				'access_proof' => $ewd_otp_controller->order_access->create_collection_proof( 'customer', $customer->id ),
			);

			$customer_view = new ewdotpViewCustomerForm( $args );

			$customer_view->set_customer_orders();

			ob_start();

			$customer_view->maybe_print_customer_results();

			$output = ob_get_clean();

			if ( ! $output ) {

				$customer_view->error_message = __( 'No orders were found associated with the submitted customer number', 'order-tracking' );

				ob_start();

				$customer_view->print_error_message();

				$output = ob_get_clean();
			}

			wp_send_json_success(
				array(
					'output' => $output,
				)
			);

			die();
		}

		/**
		 * Returns the sales rep order table for a given sales rep id
		 *
		 * @since 3.0.0
		 */
		public function get_sales_rep_orders() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-js', 'nonce' ) ) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			$sales_rep = new ewdotpSalesRep();

			$sales_rep->load_sales_rep_from_number( sanitize_text_field( trim( $_POST['sales_rep_number'] ) ) );

			$access = $ewd_otp_controller->order_access->authorize_collection( $sales_rep, 'sales_rep', $_POST );
			if ( ! $access['authorized'] ) {
				wp_send_json_error(
					array(
						'output' => $ewd_otp_controller->order_access->get_failure_message( $access['reason'] ),
					)
				);
			}

			$args = array(
				'sales_rep'    => $sales_rep,
				'access_proof' => $ewd_otp_controller->order_access->create_collection_proof( 'sales_rep', $sales_rep->id ),
			);

			$sales_rep_view = new ewdotpViewSalesRepForm( $args );

			$sales_rep_view->set_sales_rep_orders();

			ob_start();

			$sales_rep_view->maybe_print_sales_rep_results();

			$output = ob_get_clean();

			if ( ! $output ) {

				$sales_rep_view->error_message = __( 'No orders were found associated with the submitted sales rep number.', 'order-tracking' );

				ob_start();

				$sales_rep_view->print_error_message();

				$output = ob_get_clean();
			}

			wp_send_json_success(
				array(
					'output' => $output,
				)
			);

			die();
		}

		/**
		 * Updates the customer note for an order
		 *
		 * @since 3.0.0
		 */
		public function update_customer_note() {

			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-js', 'nonce' ) ) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			$order_number   = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
			$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$customer_notes = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_notes'] ) ) : '';
			$proof          = $ewd_otp_controller->order_access->normalize_proof( $_POST );
			$order          = new ewdotpOrder();

			$order->load_order_from_tracking_number( $order_number );

			if ( empty( $order->id ) || absint( $order->id ) !== $order_id ) {
				wp_send_json_error( array( 'output' => __( 'The order could not be verified.', 'order-tracking' ) ) );
			}

			$access = $ewd_otp_controller->order_access->authorize( $order, $proof );

			if ( ! $access['authorized'] ) {
				wp_send_json_error(
					array( 'output' => $ewd_otp_controller->order_access->get_failure_message( $access['reason'] ) )
				);
			}

			$order->customer_notes = $customer_notes;

			if ( false === $order->update_order() ) {
				wp_send_json_error( array( 'output' => __( 'The customer note could not be saved. Please try again.', 'order-tracking' ) ) );
			}
			do_action( 'ewd_otp_customer_note_updated', $order );

			wp_send_json_success(
				array(
					'output' => __( 'Customer note has been successfully updated.', 'order-tracking' ),
				)
			);

			die();
		}

		/**
		 * Deletes a single order via the admin page
		 *
		 * @since 3.0.0
		 */
		public function admin_delete_order() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-admin-js', 'nonce' ) ) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$order    = new ewdotpOrder();
			$order->load_order_from_id( $order_id );

			if ( ! $order_id || ! $order->id ) {
				wp_send_json_error( array( 'message' => __( 'The order could not be found.', 'order-tracking' ) ), 404 );
			}
			if ( ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) and
					( ! current_user_can( 'publish_posts' ) or get_current_user_id() != $order->get_sales_rep_wp_id() )
				) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			$ewd_otp_controller->order_manager->delete_order( $order_id );

			if ( $ewd_otp_controller->order_manager->get_order_from_id( $order_id ) ) {
				wp_send_json_error( array( 'message' => __( 'The order could not be deleted.', 'order-tracking' ) ), 500 );
			}

			wp_send_json_success(
				array(
					'order_id'  => $order_id,
					'action'    => 'delete',
					'persisted' => true,
				)
			);
		}

		/**
		 * Hides an order from the admin page
		 *
		 * @since 3.0.0
		 */
		public function admin_hide_order() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-admin-js', 'nonce' )
				or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
			) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			if ( ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) ) {
				return; }

			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$order    = new ewdotpOrder();

			$order->load_order_from_id( $order_id );

			if ( ! $order_id || ! $order->id ) {
				wp_send_json_error( array( 'message' => __( 'The order could not be found.', 'order-tracking' ) ), 404 );
			}
			$order->display = false;

			if ( ! $order->update_order() ) {
				wp_send_json_error( array( 'message' => __( 'The order could not be hidden.', 'order-tracking' ) ), 500 );
			}

			wp_send_json_success(
				array(
					'order_id'  => $order_id,
					'action'    => 'hide',
					'persisted' => true,
				)
			);
		}

		/**
		 * Deletes a single customer via the admin page
		 *
		 * @since 3.0.0
		 */
		public function admin_delete_customer() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-admin-js', 'nonce' )
				or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
			) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			if ( ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) ) {
				return; }

			$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id || ! $ewd_otp_controller->customer_manager->get_customer_from_id( $customer_id ) ) {
				wp_send_json_error( array( 'message' => __( 'The customer could not be found.', 'order-tracking' ) ), 404 );
			}
			if ( ! $ewd_otp_controller->customer_manager->delete_customer( $customer_id ) ) {
				wp_send_json_error( array( 'message' => __( 'The customer could not be deleted.', 'order-tracking' ) ), 500 );
			}
			wp_send_json_success(
				array(
					'customer_id' => $customer_id,
					'action'      => 'delete',
					'persisted'   => true,
				)
			);
		}

		/**
		 * Deletes a single sales rep via the admin page
		 *
		 * @since 3.0.0
		 */
		public function admin_delete_sales_rep() {
			global $ewd_otp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-otp-admin-js', 'nonce' )
				or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
			) {
				ewdotpHelper::admin_nopriv_ajax();
			}

			if ( ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) ) {
				return; }

			$sales_rep_id = isset( $_POST['sales_rep_id'] ) ? absint( $_POST['sales_rep_id'] ) : 0;
			if ( ! $sales_rep_id || ! $ewd_otp_controller->sales_rep_manager->get_sales_rep_from_id( $sales_rep_id ) ) {
				wp_send_json_error( array( 'message' => __( 'The sales representative could not be found.', 'order-tracking' ) ), 404 );
			}
			if ( ! $ewd_otp_controller->sales_rep_manager->delete_sales_rep( $sales_rep_id ) ) {
				wp_send_json_error( array( 'message' => __( 'The sales representative could not be deleted.', 'order-tracking' ) ), 500 );
			}
			wp_send_json_success(
				array(
					'sales_rep_id' => $sales_rep_id,
					'action'       => 'delete',
					'persisted'    => true,
				)
			);
		}
	}
}
