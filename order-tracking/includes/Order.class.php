<?php
/**
 * Class to act as a wrapper for a single order
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpOrder' ) ) {
	class ewdotpOrder {

		// Object properties from database
		public $id = 0;

		public $name;
		public $number;
		/**
		 * Stable uniqueness hash for the order number.
		 *
		 * @var string|null
		 */
		public $number_unique_hash;
		public $email;
		public $phone_number;

		public $status;
		public $external_status;
		public $location;

		public $notes_public;
		public $notes_private;
		public $customer_notes;

		public $customer;
		public $sales_rep;
		public $woocommerce_id;
		public $zendesk_id;
		/**
		 * Latest applied Zendesk event timestamp in milliseconds.
		 *
		 * @var int
		 */
		public $zendesk_event_timestamp = 0;
		public $status_updated;
		/**
		 * UTC timestamp for the latest status update.
		 *
		 * @var string
		 */
		public $status_updated_gmt;
		public $status_updated_fmtd;

		public $display;

		public $payment_price;
		public $payment_completed;
		public $paypal_receipt_number;

		public $views;

		public $tracking_link_clicked;
		public $tracking_link_code;

		// Stores all of the custom field values for an order
		public $custom_fields = array();

		// Current status of the order
		public $current_status;

		// Current location of the order
		public $current_location;

		// Stores all of the previous statuses for an order
		public $status_history = array();

		// Store any errors recorded on order submission
		public $validation_errors = array();

		/**
		 * Load an order based on a specific database record
		 *
		 * @since 3.0.0
		 */
		public function load_order( $db_order ) {
			global $ewd_otp_controller;

			if ( ! is_object( $db_order ) ) {
				return false; }

			$this->id = is_object( $db_order ) ? $db_order->Order_ID : 0;

			$this->name   = is_object( $db_order ) ? $db_order->Order_Name : '';
			$this->number = is_object( $db_order ) ? $db_order->Order_Number : '';
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			$this->number_unique_hash = is_object( $db_order ) && isset( $db_order->Order_Number_Unique_Hash ) ? $db_order->Order_Number_Unique_Hash : null;
			$this->email              = is_object( $db_order ) ? $db_order->Order_Email : '';
			$this->phone_number       = is_object( $db_order ) ? $db_order->Order_Phone_Number : '';

			$this->status          = is_object( $db_order ) ? $db_order->Order_Status : '';
			$this->external_status = is_object( $db_order ) ? $db_order->Order_External_Status : '';
			$this->location        = is_object( $db_order ) ? $db_order->Order_Location : '';

			$this->notes_public   = is_object( $db_order ) ? $db_order->Order_Notes_Public : '';
			$this->notes_private  = is_object( $db_order ) ? $db_order->Order_Notes_Private : '';
			$this->customer_notes = is_object( $db_order ) ? $db_order->Order_Customer_Notes : '';

			$this->customer       = is_object( $db_order ) ? $db_order->Customer_ID : 0;
			$this->sales_rep      = is_object( $db_order ) ? $db_order->Sales_Rep_ID : 0;
			$this->woocommerce_id = is_object( $db_order ) ? $db_order->WooCommerce_ID : 0;
			$this->zendesk_id     = is_object( $db_order ) ? $db_order->Zendesk_ID : 0;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			$this->zendesk_event_timestamp = is_object( $db_order ) && isset( $db_order->Zendesk_Event_Timestamp ) ? (int) $db_order->Zendesk_Event_Timestamp : 0;
			$this->status_updated          = is_object( $db_order ) ? $db_order->Order_Status_Updated : '';
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			$this->status_updated_gmt  = is_object( $db_order ) && isset( $db_order->Order_Status_Updated_GMT ) ? $db_order->Order_Status_Updated_GMT : null;
			$this->status_updated_fmtd = $this->date_formatted( $this->status_updated, $this->status_updated_gmt );
			$this->display             = is_object( $db_order ) ? ( $db_order->Order_Display == 'Yes' ? true : false ) : false;

			$this->payment_price         = is_object( $db_order ) ? $db_order->Order_Payment_Price : 0;
			$this->payment_completed     = is_object( $db_order ) ? ( $db_order->Order_Payment_Completed == 'Yes' ? true : false ) : false;
			$this->paypal_receipt_number = is_object( $db_order ) ? $db_order->Order_PayPal_Receipt_Number : '';

			$this->views = is_object( $db_order ) ? $db_order->Order_View_Count : 0;

			$this->tracking_link_clicked = is_object( $db_order ) ? ( $db_order->Order_Tracking_Link_Clicked == 'Yes' ? true : false ) : false;
			$this->tracking_link_code    = is_object( $db_order ) ? $db_order->Order_Tracking_Link_Code : '';

			$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();

			$this->custom_fields = array();
			foreach ( $custom_fields as $custom_field ) {

				$this->custom_fields[ $custom_field->id ] = $ewd_otp_controller->order_manager->get_field_value( $custom_field->id, $this->id );
			}

			$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

			foreach ( $statuses as $status ) {

				if ( $this->external_status === $status->status ) {
					$this->current_status = $status; }
			}

			$locations = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'locations' ) );

			foreach ( $locations as $location ) {

				if ( $this->location === $location->name ) {
					$this->current_location = $location; }
			}

			return true;
		}

		/**
		 * Loads an order based on its ID
		 *
		 * @since 3.0.0
		 */
		public function load_order_from_id( $order_id ) {
			global $ewd_otp_controller;

			$db_order = $ewd_otp_controller->order_manager->get_order_from_id( $order_id );

			if ( empty( $db_order ) ) {
				return false; }

			return $this->load_order( $db_order );
		}

		/**
		 * Loads an order based on its tracking number
		 *
		 * @since 3.0.0
		 */
		public function load_order_from_tracking_number( $order_number ) {
			global $ewd_otp_controller;

			$db_order = $ewd_otp_controller->order_manager->get_order_from_tracking_number( $order_number );

			if ( empty( $db_order ) ) {
				return false; }

			return $this->load_order( $db_order );
		}

		/**
		 * Returns the WordPress user ID for this order's sales rep
		 *
		 * @since 3.0.0
		 */
		public function get_sales_rep_wp_id() {

			if ( empty( $this->sales_rep ) ) {
				return 0; }

			$sales_rep = new ewdotpSalesRep();

			$sales_rep->load_sales_rep_from_id( $this->sales_rep );

			return ! empty( $sales_rep->wp_id ) ? $sales_rep->wp_id : 0;
		}

		/**
		 * Loads an order's status history
		 *
		 * @since 3.0.0
		 */
		public function load_order_status_history() {
			global $ewd_otp_controller;

			$this->status_history = array();

			$db_status_history = $ewd_otp_controller->order_manager->get_order_status_history( $this->id );

			foreach ( $db_status_history as $db_status ) {

				$status_history_object = new stdClass();

				$status_history_object->id              = $db_status->Order_Status_ID;
				$status_history_object->status          = $db_status->Order_Status;
				$status_history_object->location        = $db_status->Order_Location;
				$status_history_object->internal_status = $db_status->Order_Internal_Status;
				$status_history_object->updated         = $db_status->Order_Status_Created;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
				$status_history_object->updated_gmt  = isset( $db_status->Order_Status_Created_GMT ) ? $db_status->Order_Status_Created_GMT : null;
				$status_history_object->updated_fmtd = $this->date_formatted( $status_history_object->updated, $status_history_object->updated_gmt );
				$this->status_history[]              = $status_history_object;
			}
		}

		/**
		 * Verify that the submitted email matches the one belonging to the order
		 *
		 * @since 3.0.0
		 */
		public function verify_order_email( $email_address ) {

			$email_address = strtolower( trim( sanitize_email( $email_address ) ) );
			$order_email   = strtolower( trim( sanitize_email( $this->email ) ) );

			return '' !== $email_address && '' !== $order_email && hash_equals( $order_email, $email_address );
		}

		/**
		 * Verify that the submitted user ID matches either the customer or sales rep for this order
		 *
		 * @since 3.0.15
		 */
		public function verify_order_user( $user_id ) {

			global $ewd_otp_controller;

			return is_object( $ewd_otp_controller->order_access )
			? $ewd_otp_controller->order_access->is_authorized_user( $this, $user_id )
			: $this->verify_order_user_association( $user_id );
		}

		/**
		 * Verify only explicit Customer/Sales Rep WordPress-user associations.
		 *
		 * @param int $user_id WordPress user identifier.
		 * @return bool
		 * @since 3.6.0
		 */
		public function verify_order_user_association( $user_id ) {

			global $ewd_otp_controller;

			$user_id = absint( $user_id );
			if ( empty( $user_id ) ) {
				return false; }
			if ( $this->customer === $ewd_otp_controller->customer_manager->get_customer_id_from_wp_id( $user_id ) ) {
				return true; }

			if ( $this->sales_rep === $ewd_otp_controller->sales_rep_manager->get_sales_rep_id_from_wp_id( $user_id ) ) {
				return true; }

			return false;
		}

		/**
		 * Validates a submitted order, and calls insert_order if validated
		 *
		 * @since 3.0.0
		 */
		public function process_client_order_submission() {
			global $ewd_otp_controller;

			$this->validate_submission();
			if ( $this->is_valid_submission() === false ) {
				return false;
			}

			if ( ! $this->insert_order() ) {
				return false; }
			if ( ! empty( $this->status ) ) {
				$this->insert_order_status(); }

			do_action( 'ewd_otp_insert_customer_order', $this );

			return true;
		}

		/**
		 * Validate submission data. Expects to find data in $_POST.
		 *
		 * @since 3.0.0
		 */
		public function validate_submission() {
			global $ewd_otp_controller;

			$this->validation_errors = array();

			// reCAPTCHA
			if ( $ewd_otp_controller->settings->get_setting( 'use-captcha' ) == 'recaptcha' ) {

				if ( ! isset( $_POST['g-recaptcha-response'] ) ) {

					$this->validation_errors[] = array(
						'field'     => 'recaptcha',
						'error_msg' => 'No reCAPTCHA code',
						'message'   => __( 'Please fill out the reCAPTCHA box  before submitting.', 'order-tracking' ),
					);

				} else {

					$secret_key = $ewd_otp_controller->settings->get_setting( 'captcha-secret-key' );
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The AJAX controller verifies the ewd-otp-js nonce before constructing and validating this submission.
					$captcha       = sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) );
					$http_response = wp_remote_post(
						'https://www.google.com/recaptcha/api/siteverify',
						array(
							'timeout' => 10,
							'body'    => array(
								'secret'   => $secret_key,
								'response' => $captcha,
							),
						)
					);
					$response_code = is_wp_error( $http_response ) ? 0 : wp_remote_retrieve_response_code( $http_response );
					$response      = 200 === $response_code ? json_decode( wp_remote_retrieve_body( $http_response ), true ) : null;

					if ( ! is_array( $response ) || empty( $response['success'] ) ) {

						$message                   = __( 'Please fill out the reCAPTCHA box again and re-submit. If the problem continues, please contact the site administrator.', 'order-tracking' );
						$this->validation_errors[] = array(
							'field'     => 'recaptcha',
							'error_msg' => 'Invalid reCAPTCHA verification',
							'message'   => $message,
						);
					}
				}
			}

			// Order Basics
			$this->number = $this->generate_unique_order_number(
				$ewd_otp_controller->settings->get_setting( 'customer-order-number-prefix' ),
				$ewd_otp_controller->settings->get_setting( 'customer-order-number-suffix' )
			);
			if ( '' === $this->number ) {
				$this->validation_errors[] = array(
					'field'     => 'order_number',
					'error_msg' => 'Order number generation failed',
					'message'   => __( 'A unique order number could not be generated. Please try again.', 'order-tracking' ),
				);
			}
			$this->name           = empty( $_POST['ewd_otp_order_name'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_order_name'] );
			$this->email          = empty( $_POST['ewd_otp_order_email'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_order_email'] );
			$this->location       = empty( $_POST['ewd_otp_location'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_location'] );
			$this->customer_notes = empty( $_POST['ewd_otp_customer_notes'] ) ? '' : sanitize_textarea_field( $_POST['ewd_otp_customer_notes'] );

			$this->external_status = $this->status = $ewd_otp_controller->settings->get_setting( 'default-customer-order-form-status' );
			$this->display         = true;

			if ( empty( $this->status ) ) {

				$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

				$status = reset( $statuses );

				$this->external_status = $this->status = $status->status;
			}

			// Customer
			$user = wp_get_current_user();

			if ( $user ) {

				$this->customer = $ewd_otp_controller->customer_manager->get_customer_id_from_wp_id( $user->ID );
			} elseif ( function_exists( 'FEUP_User' ) ) {

				$feup_user = new FEUP_User();

				if ( $feup_user->Is_Logged_In() ) {

					$this->customer = $ewd_otp_controller->customer_manager->get_customer_id_from_feup_id( $feup_user->Get_User_ID() );
				}
			}

			if ( empty( $this->customer ) and ! empty( $this->email ) and $ewd_otp_controller->settings->get_setting( 'allow-assign-orders-to-customers' ) ) {

				$this->customer = $ewd_otp_controller->customer_manager->get_customer_id_from_email( $this->email );
			}

			// Sales Rep
			if ( ! empty( $this->customer ) ) {

				$this->sales_rep = $ewd_otp_controller->customer_manager->get_customer_field( 'Sales_Rep_ID', $this->customer );
			}

			if ( empty( $this->sales_rep ) ) {

				$this->sales_rep = empty( $_POST['ewd_otp_sales_rep'] ) ? $ewd_otp_controller->settings->get_setting( 'default-sales-rep' ) : intval( $_POST['ewd_otp_sales_rep'] );
			}

			$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();

			foreach ( $custom_fields as $custom_field ) {

				$input_name = 'ewd_otp_custom_field_' . $custom_field->id;

				if ( 'checkbox' === $custom_field->type ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The AJAX controller verifies the ewd-otp-js nonce before constructing and validating this submission.
					$this->custom_fields[ $custom_field->id ] = ( empty( $_POST[ $input_name ] ) || ! is_array( $_POST[ $input_name ] ) ) ? array() : sanitize_text_field( implode( ',', array_map( 'sanitize_text_field', wp_unslash( $_POST[ $input_name ] ) ) ) ); } elseif ( 'textarea' === $custom_field->type ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The AJAX controller verifies the ewd-otp-js nonce before constructing and validating this submission.
					$this->custom_fields[ $custom_field->id ] = empty( $_POST[ $input_name ] ) ? false : sanitize_textarea_field( wp_unslash( $_POST[ $input_name ] ) ); } else {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The AJAX controller verifies the ewd-otp-js nonce before constructing and validating this submission.
						$this->custom_fields[ $custom_field->id ] = empty( $_POST[ $input_name ] ) ? false : sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) ); }
			}

			do_action( 'ewd_otp_validate_order_submission', $this );
		}

		/**
		 * Validates a submitted order, and calls insert_order if validated
		 *
		 * @since 3.0.0
		 */
		public function process_admin_order_submission() {
			global $ewd_otp_controller;

			$this->validate_admin_submission();

			if ( $this->is_valid_submission() === false ) {
				return false;
			}

			if ( $this->id ) {

				$old_status = $ewd_otp_controller->order_manager->get_order_field( 'status', $this->id );

				if ( false === $this->update_order() ) {
					$this->validation_errors[] = $ewd_otp_controller->order_manager->last_error;
					return false;
				}
				if ( $this->status != $old_status ) {

					$this->insert_order_status();
				}

				do_action( 'ewd_otp_admin_order_updated', $this, $old_status );
			} else {

				if ( ! $this->insert_order() ) {
					return false; }
				$this->insert_order_status();

				do_action( 'ewd_otp_admin_order_inserted', $this );
			}

			return true;
		}

		/**
		 * Validate submission data entered via the admin page
		 *
		 * @since 3.0.0
		 */
		public function validate_admin_submission() {
			global $ewd_otp_controller;

			$this->validation_errors = array();

			if ( ! isset( $_POST['ewd-otp-admin-nonce'] )
			or ! wp_verify_nonce( $_POST['ewd-otp-admin-nonce'], 'ewd-otp-admin-nonce' )
			) {
				$this->validation_errors[] = __( 'The request has been rejected because it does not appear to have come from this site.', 'order-tracking' );
			}

			// Order Data
			$this->number = empty( $_POST['ewd_otp_number'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_number'] );

			$stored_order = $this->id ? $ewd_otp_controller->order_manager->get_order_from_id( $this->id ) : null;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			$number_changed = ! $stored_order || (string) $stored_order->Order_Number !== (string) $this->number;
			if ( $number_changed && '' === trim( $this->number ) ) {
				$this->validation_errors[] = __( 'Order Number is required.', 'order-tracking' );
			} elseif ( $number_changed ) {
				$matching_order = $ewd_otp_controller->order_manager->get_order_from_tracking_number( $this->number );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
				if ( $matching_order && absint( $matching_order->Order_ID ) !== absint( $this->id ) ) {
					$this->validation_errors[] = __( 'That Order Number is already in use.', 'order-tracking' );
				}
			}
			$this->name  = empty( $_POST['ewd_otp_name'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_name'] );
			$this->email = empty( $_POST['ewd_otp_email'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_email'] );

			$this->status   = $this->external_status = empty( $_POST['ewd_otp_status'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_status'] );
			$this->location = empty( $_POST['ewd_otp_location'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_location'] );
			$this->display  = ( empty( $_POST['ewd_otp_display'] ) or $_POST['ewd_otp_display'] == 'no' ) ? false : true;

			$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

			// Overwrite the external status of the order if the new status is an internal one
			foreach ( $statuses as $status ) {

				if ( $this->status == $status->status and $status->internal == 'yes' ) {

					$this->external_status = $ewd_otp_controller->order_manager->get_order_field( 'Order_External_Status', $this->id );
				}
			}

			$this->notes_public   = empty( $_POST['ewd_otp_public_notes'] ) ? '' : sanitize_textarea_field( $_POST['ewd_otp_public_notes'] );
			$this->notes_private  = empty( $_POST['ewd_otp_private_notes'] ) ? '' : sanitize_textarea_field( $_POST['ewd_otp_private_notes'] );
			$this->customer_notes = empty( $_POST['ewd_otp_customer_notes'] ) ? '' : sanitize_textarea_field( $_POST['ewd_otp_customer_notes'] );

			$this->customer  = empty( $_POST['ewd_otp_customer'] ) ? 0 : sanitize_text_field( $_POST['ewd_otp_customer'] );
			$this->sales_rep = empty( $_POST['ewd_otp_sales_rep'] ) ? 0 : sanitize_text_field( $_POST['ewd_otp_sales_rep'] );

			$this->payment_price         = empty( $_POST['ewd_otp_payment_price'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_payment_price'] );
			$this->payment_completed     = ( ! empty( $_POST['ewd_otp_payment_completed'] ) and $_POST['ewd_otp_payment_completed'] == 'yes' ) ? true : false;
			$this->paypal_receipt_number = empty( $_POST['ewd_otp_paypal_receipt_number'] ) ? '' : sanitize_text_field( $_POST['ewd_otp_paypal_receipt_number'] );

			$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();

			foreach ( $custom_fields as $custom_field ) {

				$input_name = 'ewd-otp-custom-field-' . $custom_field->id;

				if ( 'checkbox' === $custom_field->type ) {
					$this->custom_fields[ $custom_field->id ] = ( empty( $_POST[ $input_name ] ) || ! is_array( $_POST[ $input_name ] ) ) ? '' : sanitize_text_field( implode( ',', array_map( 'sanitize_text_field', wp_unslash( $_POST[ $input_name ] ) ) ) ); } elseif ( 'textarea' === $custom_field->type ) {
					$this->custom_fields[ $custom_field->id ] = empty( $_POST[ $input_name ] ) ? false : sanitize_textarea_field( wp_unslash( $_POST[ $input_name ] ) ); } elseif ( 'file' === $custom_field->type || 'image' === $custom_field->type ) {
						$this->custom_fields[ $custom_field->id ] = ! empty( $_FILES[ $input_name ]['name'] ) ? $this->handle_file_upload( $input_name ) : ( ! empty( $_POST[ $input_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) ) : '' ); } else {
						$this->custom_fields[ $custom_field->id ] = empty( $_POST[ $input_name ] ) ? false : sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) ); }
			}

			do_action( 'ewd_otp_validate_order_submission', $this );
		}

		/**
		 * Takes an input name, uploads the file from that input if it exists, returns the file URL
		 *
		 * @since 3.0.0
		 */
		public function handle_file_upload( $input_name ) {

			if ( ! function_exists( 'wp_handle_upload' ) ) {

				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$args = array(
				'test_form' => false,
			);

			$uploaded_file = wp_handle_upload( $_FILES[ $input_name ], $args );

			if ( $uploaded_file && empty( $uploaded_file['error'] ) ) {

				return $uploaded_file['url'];
			} else {

				return false;
			}
		}

		/**
		 * Check if submission is valid
		 *
		 * @since 3.0.0
		 */
		public function is_valid_submission() {

			if ( ! count( $this->validation_errors ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Takes a status, correctly updates the orders status based on whether its an internal or external status
		 *
		 * @since 3.0.0
		 */
		public function set_status( $new_status ) {

			global $wpdb, $ewd_otp_controller;
			$old_status = $ewd_otp_controller->order_manager->get_order_field( 'status', $this->id );

			$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

			$internal_status = false;

			foreach ( $statuses as $status ) {

				if ( $status->status === $new_status && 'yes' === $status->internal ) {
					$internal_status = true; }
			}

			$previous = array( $this->status, $this->external_status, $this->status_updated, $this->status_updated_gmt );
			if ( ! $internal_status ) {
				$this->external_status = $new_status;
			}

			$this->status = $new_status;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			if ( false === $wpdb->query( 'START TRANSACTION' ) || false === $this->update_order() || false === $this->insert_order_status() ) {
				$wpdb->query( 'ROLLBACK' );
				list( $this->status, $this->external_status, $this->status_updated, $this->status_updated_gmt ) = $previous;
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					list( $this->status, $this->external_status, $this->status_updated, $this->status_updated_gmt ) = $previous;
				return false;
			}

			if ( ! $internal_status ) {

				do_action( 'ewd_otp_status_updated', $this, $old_status );
			}

			return true;
		}

		/**
		 * Takes a location and updates the orders location
		 *
		 * @since 3.0.0
		 */
		public function set_location( $new_location ) {

			global $wpdb, $ewd_otp_controller;
			$old_location = $this->location;

			$previous_updated     = $this->status_updated;
			$previous_updated_gmt = $this->status_updated_gmt;
			$this->location       = $new_location;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			if ( false === $wpdb->query( 'START TRANSACTION' ) || false === $this->update_order() || false === $this->insert_order_status() ) {
				$wpdb->query( 'ROLLBACK' );
				$this->location           = $old_location;
				$this->status_updated     = $previous_updated;
				$this->status_updated_gmt = $previous_updated_gmt;
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					$this->location       = $old_location;
				$this->status_updated     = $previous_updated;
				$this->status_updated_gmt = $previous_updated_gmt;
				return false;
			}

			do_action( 'ewd_otp_location_updated', $this, $old_location );
			return true;
		}

		/**
		 * Takes a tracking link code and updates the tracking code value in the database for this order
		 *
		 * @since 3.0.0
		 */
		public function set_tracking_link_code( $tracking_code ) {
			$this->tracking_link_code = $tracking_code;

			$this->update_order();
		}

		/**
		 * Generate or reproduce the current bearer token without storing it in plaintext.
		 *
		 * @param bool $rotate Whether to rotate the token seed.
		 * @since 3.6.0
		 * @return string Raw token for inclusion in the newly generated URL.
		 */
		public function generate_tracking_token( $rotate = false ) {

			$stored = (string) $this->tracking_link_code;
			if ( ! $rotate && preg_match( '/^v2:([A-Za-z0-9_-]{32,}):([a-f0-9]{64})$/', $stored, $matches ) ) {
				$token = hash_hmac( 'sha256', 'ewd-otp-tracking|' . absint( $this->id ) . '|' . $matches[1], wp_salt( 'auth' ) );
				if ( hash_equals( $matches[2], hash( 'sha256', $token ) ) ) {
					return $token;
				}
			}

			$previous_code    = $this->tracking_link_code;
			$previous_clicked = $this->tracking_link_clicked;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes, not obfuscation.
			$seed  = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
			$token = hash_hmac( 'sha256', 'ewd-otp-tracking|' . absint( $this->id ) . '|' . $seed, wp_salt( 'auth' ) );

			$this->tracking_link_code    = 'v2:' . $seed . ':' . hash( 'sha256', $token );
			$this->tracking_link_clicked = false;
			if ( false === $this->update_order() ) {
				$this->tracking_link_code    = $previous_code;
				$this->tracking_link_clicked = $previous_clicked;
				return '';
			}

			return $token;
		}

		/** Explicitly revoke the prior bearer and issue a new one. */
		public function rotate_tracking_token() {

			return $this->generate_tracking_token( true );
		}
		/**
		 * Takes a tracking link code and verifies that it matches the one currently saved for the order
		 *
		 * @since 3.0.0
		 */
		public function verify_tracking_link( $tracking_code ) {

			$tracking_code = (string) $tracking_code;
			$stored_code   = (string) $this->tracking_link_code;

			if ( '' === $tracking_code || '' === $stored_code ) {
				return false;
			}

			if ( 0 === strpos( $stored_code, 'sha256:' ) ) {
				$stored_hash = substr( $stored_code, 7 );
				return 64 === strlen( $stored_hash ) && hash_equals( $stored_hash, hash( 'sha256', $tracking_code ) );
			}

			if ( preg_match( '/^v2:([A-Za-z0-9_-]{32,}):([a-f0-9]{64})$/', $stored_code, $matches ) ) {
					$expected = hash_hmac( 'sha256', 'ewd-otp-tracking|' . absint( $this->id ) . '|' . $matches[1], wp_salt( 'auth' ) );
					return hash_equals( $matches[2], hash( 'sha256', $expected ) ) && hash_equals( $expected, $tracking_code );
			}

			return hash_equals( $stored_code, $tracking_code );
		}

		/**
		 * Update the tracking link clicked flag for this order
		 *
		 * @since 3.0.0
		 */
		public function set_tracking_link_clicked() {

			$this->tracking_link_clicked = true;

			$this->update_order();
		}

		/**
		 * Increase the view count for this order
		 *
		 * @since 3.0.0
		 */
		public function increase_view_count() {
			global $ewd_otp_controller;

			$ewd_otp_controller->order_manager->increase_order_views( $this->id );
		}

		/**
		 * Insert a new order into the database
		 *
		 * @since 3.0.0
		 */
		public function insert_order() {
			global $ewd_otp_controller;

			$id = $ewd_otp_controller->order_manager->insert_order( $this );
			if ( ! $id ) {
				$this->validation_errors[] = $ewd_otp_controller->order_manager->last_error;
				return false;
			}

			$this->id = $id;
			return true;
		}

		/**
		 * Insert an order and its initial status history as one durable unit.
		 *
		 * @since 3.6.0
		 * @return bool Whether both records were committed.
		 */
		public function insert_order_with_history() {

			global $wpdb;

			$previous_id = $this->id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return false;
			}

			if ( ! $this->insert_order() || ! $this->insert_order_status() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
				$wpdb->query( 'ROLLBACK' );
				$this->id = $previous_id;
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					$this->id = $previous_id;
				return false;
			}

			do_action( 'ewd_otp_admin_order_inserted', $this );
			return true;
		}
		/**
		 * Generate a unique customer-facing order number with bounded retries.
		 *
		 * @param string $prefix Order number prefix.
		 * @param string $suffix Order number suffix.
		 * @return string
		 * @since 3.6.0
		 */
		private function generate_unique_order_number( $prefix, $suffix ) {

			global $ewd_otp_controller;

			for ( $attempt = 0; $attempt < 10; $attempt++ ) {
				$number = (string) $prefix . ewd_random_string( 5 ) . (string) $suffix;
				if ( ! $ewd_otp_controller->order_manager->get_order_from_tracking_number( $number ) ) {
					return $number;
				}
			}

			return '';
		}
		/**
		 * Insert the current order status into immutable history.
		 *
		 * @return int|false
		 */
		public function insert_order_status() {

			global $ewd_otp_controller;

			if ( ! $this->id ) {
				return false; }
			if ( empty( $this->status_updated_gmt ) ) {
				$this->status_updated     = current_time( 'mysql' );
				$this->status_updated_gmt = current_time( 'mysql', true );
			}

			return $ewd_otp_controller->order_manager->update_order_status( $this );
		}

		/**
		 * Update an order already in the database
		 *
		 * @since 3.0.0
		 */
		public function update_order() {

			global $ewd_otp_controller;

			return $ewd_otp_controller->order_manager->update_order( $this );
		}
		/**
		 * Returns the date/time formatted based on the use WP timezone settings and formats
		 *
		 * @since 3.0.0
		 */
		public function date_formatted( $input, $gmt_input = null ) {

			global $ewd_otp_controller;

			if ( ! empty( $gmt_input ) && '0000-00-00 00:00:00' !== $gmt_input ) {
				$timestamp = strtotime( $gmt_input . ' UTC' );
				return false === $timestamp ? $input : wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
			}

			$output = $input;
			if ( $ewd_otp_controller->settings->get_setting( 'use-wp-timezone' ) ) {

				$wp_tz      = wp_timezone();
				$current_tz = new DateTime( $input, new DateTimeZone( date_default_timezone_get() ) );
				$offset     = $wp_tz->getOffset( $current_tz );

				$output = date(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					( $current_tz->format( 'U' ) + $offset )
				);
			}

			return $output;
		}
	}
}
