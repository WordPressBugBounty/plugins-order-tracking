<?php

/**
 * Create a shortcode to display an order tracking form
 *
 * @since 3.0.0
 */
function ewd_otp_tracking_form_shortcode( $atts ) {
	global $ewd_otp_controller;

	// Define shortcode attributes
	$order_atts = array(
		'order_id'           => 0,
		'show_orders'        => 'no',
		'order_form_title'   => '',
		'order_field_text'   => '',
		'email_field_text'   => '',
		'order_instructions' => '',
		'submit_text'        => '',
		'notes_submit'       => '',
	);

	// Create filter so addons can modify the accepted attributes
	$order_atts = apply_filters( 'ewd_otp_tracking_form_shortcode_atts', $order_atts );

	// Extract the shortcode attributes
	$args = shortcode_atts( $order_atts, $atts );

	// Render booking form
	ewd_otp_load_view_files();

	// Possibly update order status and location from the front-end
	$update = ewd_otp_possibly_update_order();

	if ( $update ) {

		$args['update_message'] = $update;
	}

	$order_form = new ewdotpViewOrderForm( $args );

	$order_form->set_request_parameters();

	$order_form->set_order_from_id();

	$output = $order_form->render();

	return $output;
}
add_shortcode( 'tracking-form', 'ewd_otp_tracking_form_shortcode' );

/**
 * Create a shortcode to display a customer form
 *
 * @since 3.0.0
 */
function ewd_otp_customer_form_shortcode( $atts ) {
	global $ewd_otp_controller;

	// Define shortcode attributes
	$customer_atts = array(
		'order_form_title'   => '',
		'order_field_text'   => '',
		'email_field_text'   => '',
		'order_instructions' => '',
		'submit_text'        => '',
	);

	$verify_email = $ewd_otp_controller->settings->get_setting( 'email-verification' );

	if ( ! empty( $_POST['ewd_otp_identifier_number'] ) and ( ! empty( $_POST['ewd_otp_form_type'] ) and $_POST['ewd_otp_form_type'] == 'customer_form' ) ) {

		$customer_id = intval( $_POST['ewd_otp_identifier_number'] );
	} elseif ( get_current_user_id() ) {

		$customer_id = $ewd_otp_controller->customer_manager->get_customer_id_from_wp_id( get_current_user_id() );

		$verify_email = false;
	} elseif ( class_exists( 'FEUP_User' ) ) {

		$feup_user = new FEUP_User();

		$customer_id = $ewd_otp_controller->customer_manager->get_customer_id_from_feup_id( $feup_user->Get_User_ID() );

		$verify_email = false;
	}

	if ( ! empty( $customer_id ) ) {

		$customer = new ewdotpCustomer();

		$customer->load_customer_from_id( $customer_id );

		if ( ! $verify_email or $customer->verify_customer_email( sanitize_email( $_POST['ewd_otp_form_email'] ) ) ) {

			$customer_atts['customer'] = $customer;
		}
	}

	if ( ! empty( $_POST['ewd_otp_customer_download'] ) and $ewd_otp_controller->settings->get_setting( 'allow-customer-downloads' ) ) {

		$customer_id = intval( $_POST['ewd_otp_customer_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Collection downloads require the signed collection proof validated immediately below.
		$collection_token = isset( $_POST['ewd_otp_collection_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ewd_otp_collection_token'] ) ) : '';
		$customer         = new ewdotpCustomer();

		$customer->load_customer_from_id( $customer_id );

		$access = $ewd_otp_controller->order_access->authorize_collection( $customer, 'customer', array( 'collection_token' => $collection_token ), null, true );
		if ( $access['authorized'] ) {

			$ewd_otp_controller->exports->nonce_check    = false;
			$ewd_otp_controller->exports->frontend_scope = 'customer';
			$ewd_otp_controller->exports->customer_id    = $customer_id;
			$ewd_otp_controller->exports->after          = date( 'Y-m-d H:i:s', strtotime( '-365 days' ) );

			$ewd_otp_controller->exports->run_export();
		}
	}

	// Create filter so addons can modify the accepted attributes
	$customer_atts = apply_filters( 'ewd_otp_customer_form_shortcode_atts', $customer_atts );

	// Extract the shortcode attributes
	$args = shortcode_atts( $customer_atts, $atts );

	// Render booking form
	ewd_otp_load_view_files();

	// Possibly update order status and location from the front-end
	$update = ewd_otp_possibly_update_order();

	if ( $update ) {

		$args['update_message'] = $update;
	}

	$customer_form = new ewdotpViewCustomerForm( $args );

	$customer_form->set_request_parameters();

	$output = $customer_form->render();

	return $output;
}
add_shortcode( 'customer-form', 'ewd_otp_customer_form_shortcode' );

/**
 * Create a shortcode to display a sales rep form
 *
 * @since 3.0.0
 */
function ewd_otp_sales_rep_form_shortcode( $atts ) {
	global $ewd_otp_controller;

	if ( ! $ewd_otp_controller->permissions->check_permission( 'sales_reps' ) ) {
		return; }

	// Define shortcode attributes
	$sales_rep_atts = array(
		'order_form_title'   => '',
		'order_field_text'   => '',
		'email_field_text'   => '',
		'order_instructions' => '',
		'submit_text'        => '',
	);

	if ( get_current_user_id() ) {

		$sales_rep_id = $ewd_otp_controller->sales_rep_manager->get_sales_rep_id_from_wp_id( get_current_user_id() );
	}

	if ( ! empty( $sales_rep_id ) ) {

		$sales_rep = new ewdotpSalesRep();

		$sales_rep->load_sales_rep_from_id( $sales_rep_id );

		$sales_rep_atts['sales_rep'] = $sales_rep;
	}

	if ( ! empty( $_POST['ewd_otp_sales_rep_download'] ) and $ewd_otp_controller->settings->get_setting( 'allow-sales-rep-downloads' ) ) {

		$sales_rep_id = intval( $_POST['ewd_otp_sales_rep_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Collection downloads require the signed collection proof validated immediately below.
		$collection_token = isset( $_POST['ewd_otp_collection_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ewd_otp_collection_token'] ) ) : '';
		$sales_rep        = new ewdotpSalesRep();

		$sales_rep->load_sales_rep_from_id( $sales_rep_id );

		$access = $ewd_otp_controller->order_access->authorize_collection( $sales_rep, 'sales_rep', array( 'collection_token' => $collection_token ), null, true );
		if ( $access['authorized'] ) {

			$ewd_otp_controller->exports->nonce_check    = false;
			$ewd_otp_controller->exports->frontend_scope = 'sales_rep';
			$ewd_otp_controller->exports->sales_rep_id   = $sales_rep_id;
			$ewd_otp_controller->exports->after          = date( 'Y-m-d H:i:s', strtotime( '-365 days' ) );

			$ewd_otp_controller->exports->run_export();
		}
	}

	// Create filter so addons can modify the accepted attributes
	$sales_rep_atts = apply_filters( 'ewd_otp_sales_rep_form_shortcode_atts', $sales_rep_atts );

	// Extract the shortcode attributes
	$args = shortcode_atts( $sales_rep_atts, $atts );

	// Render booking form
	ewd_otp_load_view_files();

	// Possibly update order status and location from the front-end
	$update = ewd_otp_possibly_update_order();

	if ( $update ) {

		$args['update_message'] = $update;
	}

	$sales_rep_form = new ewdotpViewSalesRepForm( $args );

	$sales_rep_form->set_request_parameters();

	$output = $sales_rep_form->render();

	return $output;
}
add_shortcode( 'sales-rep-form', 'ewd_otp_sales_rep_form_shortcode' );

/**
 * Create a shortcode to display the customer order form
 *
 * @since 3.0.0
 */
function ewd_otp_customer_order_form_shortcode( $atts ) {
	global $ewd_otp_controller;

	if ( ! $ewd_otp_controller->permissions->check_permission( 'customer_orders' ) ) {
		return; }

	// Define shortcode attributes
	$customer_order_atts = array(
		'location'                  => '',
		'customer_name_field_text'  => '',
		'customer_email_field_text' => '',
		'customer_notes_field_text' => '',
		'success_redirect_page'     => '',
		'submit_text'               => '',
	);

	// Create filter so addons can modify the accepted attributes
	$customer_order_atts = apply_filters( 'ewd_otp_customer_order_form_shortcode_atts', $customer_order_atts );

	// Extract the shortcode attributes
	$args = shortcode_atts( $customer_order_atts, $atts );

	// Handle order submission
	if ( isset( $_POST['ewd_otp_form_type'] ) and $_POST['ewd_otp_form_type'] == 'customer_order_form' ) {

		$args['order_submitted'] = true;

		$order  = new ewdotpOrder();
		$status = $order->process_client_order_submission();

		if ( ! $status ) {

			$args['update_message'] = '';

			foreach ( $order->validation_errors as $validation_error ) {

				$args['update_message'] .= '<br />' . $validation_error['message'];
			}
		} else {

			$args['update_message'] = $ewd_otp_controller->settings->get_setting( 'label-customer-order-thank-you' ) . ' ' . $order->number;

			if ( ! empty( $args['success_redirect_page'] ) ) {
				header( 'location:' . esc_url_raw( $args['success_redirect_page'] ) );
				exit(); }
		}
	}

	// Render booking form
	ewd_otp_load_view_files();

	$customer_order_form = new ewdotpViewCustomerOrderForm( $args );

	$customer_order_form->set_request_parameters();

	$output = $customer_order_form->render();

	return $output;
}
add_shortcode( 'customer-order', 'ewd_otp_customer_order_form_shortcode' );

/**
 * Create a shortcode to display a standalone tracking number search input
 *
 * @since 3.2.4
 */
function ewd_otp_order_number_search_shortcode( $atts ) {

	wp_enqueue_style( 'ewd-otp-css' );

	$ewd_otp_order_number_search_atts = array(
		'tracking_page_url'  => '',
		'search_label'       => '',
		'search_placeholder' => 'Please enter order number here',
		'submit_label'       => 'Track',
	);

	$args = shortcode_atts( $ewd_otp_order_number_search_atts, $atts );

	$output  = "<div class='ewd-otp-order-number-search'>";
	$output .= "<form method='post' action='" . esc_attr( $args['tracking_page_url'] ) . "'>";
	$output .= "<div class='ewd-otp-order-number-search-label'>" . sanitize_text_field( $args['search_label'] ) . '</div>';
	$output .= "<div class='ewd-otp-order-number-search-input'><input type='text' name='tracking_number' placeholder='" . esc_attr( $args['search_placeholder'] ) . "'/></div>";
	$output .= "<input type='submit' class='ewd-otp-order-number-search-submit' name='ewd_otp_order_number_search_submit' value='" . esc_attr( $args['submit_label'] ) . "' />";
	$output .= '</form>';
	$output .= '</div>';

	return $output;
}
add_shortcode( 'order-number-search', 'ewd_otp_order_number_search_shortcode' );

function ewd_otp_load_view_files() {

	$files = array(
		EWD_OTP_PLUGIN_DIR . '/views/Base.class.php', // This will load all default classes.
	);

	$files = apply_filters( 'ewd_otp_load_view_files', $files );

	foreach ( $files as $file ) {
		require_once $file;
	}
}

function ewd_otp_possibly_update_order() {
	global $ewd_otp_controller;

	if ( empty( $_POST['ewd_otp_update_status_and_location'] ) ) {
		return false; }

	$order_id = intval( $_POST['ewd_otp_order_id'] );

	if ( empty( $order_id ) ) {
		return false;
	}

	$nonce = isset( $_POST['ewd_otp_update_order_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ewd_otp_update_order_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'ewd_otp_update_order_' . $order_id ) ) {
		return false; }
	$order = new ewdotpOrder();

	$order->load_order_from_id( $order_id );
	if ( empty( $order->id ) ) {
		return false; }
	$current_user_cannot    = ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) );
	$order_has_no_sales_rep = 1 > intval( $order->sales_rep );
	$different_sales_rep    = 1 > intval( $order->sales_rep ) ? true : ( get_current_user_id() !== intval( $order->get_sales_rep_wp_id() ) );

	if ( $current_user_cannot and ( $order_has_no_sales_rep or $different_sales_rep ) ) {
		return false;
	}

	$locations         = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'locations' ) );
	$allowed_locations = array();
	foreach ( $locations as $location ) {
		$allowed_locations[] = (string) $location->name;
	}

	$statuses         = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );
	$allowed_statuses = array();
	foreach ( $statuses as $status ) {
		$allowed_statuses[] = (string) $status->status;
	}

	$location = isset( $_POST['ewd_otp_order_location'] ) ? sanitize_text_field( wp_unslash( $_POST['ewd_otp_order_location'] ) ) : '';
	$status   = isset( $_POST['ewd_otp_order_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ewd_otp_order_status'] ) ) : '';

	if ( '' !== $location && ! in_array( $location, $allowed_locations, true ) ) {
		return false;
	}
	if ( '' !== $status && ! in_array( $status, $allowed_statuses, true ) ) {
		return false;
	}

	if ( '' === $location && '' === $status ) {
		return false;
	}
	if ( '' !== $location && $location === $order->location && ( '' === $status || $status === $order->status ) ) {
		return false;
	}
	if ( '' === $location && '' !== $status && $status === $order->status ) {
		return false;
	}

	if ( '' !== $location && '' !== $status ) {
		if ( $status === $order->status ) {
			if ( ! $order->set_location( $location ) ) {
				return false;
			}
		} else {
				$old_location    = $order->location;
				$order->location = $location;
			if ( ! $order->set_status( $status ) ) {
				$order->location = $old_location;
				return false;
			}
		}
	} elseif ( '' !== $location ) {
		if ( ! $order->set_location( $location ) ) {
			return false;
		}
	} elseif ( ! $order->set_status( $status ) ) {
		return false;
	}
	return __( 'Order information has been updated.', 'order-tracking' );
}

if ( ! function_exists( 'ewd_otp_decode_infinite_table_setting' ) ) {
	function ewd_otp_decode_infinite_table_setting( $values ) {

		if ( empty( $values ) ) {
			return array(); }

		return is_array( json_decode( html_entity_decode( $values ) ) ) ? json_decode( html_entity_decode( $values ) ) : array();
	}
}

// add an output buffer layer for the plugin
add_action( 'init', 'ewd_otp_add_ob_start' );
add_action( 'shutdown', 'ewd_otp_flush_ob_end' );

// If there's an IPN request, add our setup function to potentially handle it
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- PayPal IPN authenticity is verified with PayPal before any mutation.
if ( isset( $_POST['ipn_track_id'] ) ) {
	add_action( 'init', 'ewd_otp_setup_paypal_ipn', 11 ); }

/**
 * Sets up the PayPal IPN process
 *
 * @since 3.0.0
 */
if ( ! function_exists( 'ewd_otp_setup_paypal_ipn' ) ) {
	function ewd_otp_setup_paypal_ipn() {
		global $ewd_otp_controller;

		if ( empty( $ewd_otp_controller->settings->get_setting( 'allow-order-payments' ) ) ) {
			return; }

		ewd_otp_handle_paypal_ipn();
	}
} // endif;

/**
 * Handle PayPal IPN requests
 *
 * @since 3.0.0
 */
if ( ! function_exists( 'ewd_otp_handle_paypal_ipn' ) ) {
	function ewd_otp_handle_paypal_ipn() {

		global $ewd_otp_controller;

		return is_object( $ewd_otp_controller->paypal ) ? $ewd_otp_controller->paypal->handle() : false;
	}
} // endif;

/**
 * Opens a buffer when handling PayPal IPN requests
 *
 * @since 3.0.0
 */
if ( ! function_exists( 'ewd_otp_add_ob_start' ) ) {
	function ewd_otp_add_ob_start() {
		ob_start();
	}
} // endif;

/**
 * Closes a buffer when handling PayPal IPN requests
 *
 * @since 3.0.0
 */
if ( ! function_exists( 'ewd_otp_flush_ob_end' ) ) {
	function ewd_otp_flush_ob_end() {
		if ( ob_get_length() ) {
			ob_end_clean(); }
	}
} // endif;

if ( ! function_exists( 'ewd_hex_to_rgb' ) ) {
	function ewd_hex_to_rgb( $hex ) {

		$hex = str_replace( '#', '', $hex );

		// return if the string isn't a color code
		if ( strlen( $hex ) !== 3 && strlen( $hex ) !== 6 ) {
			return '0,0,0'; }

		if ( strlen( $hex ) == 3 ) {
			$r = hexdec( substr( $hex, 0, 1 ) . substr( $hex, 0, 1 ) );
			$g = hexdec( substr( $hex, 1, 1 ) . substr( $hex, 1, 1 ) );
			$b = hexdec( substr( $hex, 2, 1 ) . substr( $hex, 2, 1 ) );
		} else {
			$r = hexdec( substr( $hex, 0, 2 ) );
			$g = hexdec( substr( $hex, 2, 2 ) );
			$b = hexdec( substr( $hex, 4, 2 ) );
		}

		$rgb = $r . ', ' . $g . ', ' . $b;

		return $rgb;
	}
}

if ( ! function_exists( 'ewd_format_classes' ) ) {
	function ewd_format_classes( $classes ) {

		if ( count( $classes ) ) {
			return ' class="' . esc_attr( join( ' ', $classes ) ) . '"';
		}
	}
}

if ( ! function_exists( 'ewd_add_frontend_ajax_url' ) ) {
	/** Print the front-end AJAX endpoint for legacy scripts. */
	function ewd_add_frontend_ajax_url() {
		?>

	<script type="text/javascript">
		var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
	</script>
		<?php
	}
}

if ( ! function_exists( 'ewd_random_string' ) ) {
	function ewd_random_string( $length = 10 ) {

		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$randstring = '';

		for ( $i = 0; $i < $length; $i++ ) {

			$randstring .= $characters[ rand( 0, strlen( $characters ) - 1 ) ];
		}

		return $randstring;
	}
}
