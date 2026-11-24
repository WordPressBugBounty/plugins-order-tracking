<?php

/**
 * Base class for any view requested on the front end.
 *
 * @since 3.0.0
 */
class ewdotpView extends ewdotpBase {

	/**
	 * Post type to render
	 */
	public $post_type = null;

	/**
	 * Map types of content to the template which will render them
	 */
	public $content_map = array(
		'title' => 'content/title',
	);

	// Locations that should be searched for templates
	public $template_dirs;

	// Default labels, used a fallbacks if no admin inputted label exists
	public $label_defaults = array();

	// The classes that should be added to the main form div
	public $classes;

	// Nonce used by the various forms
	public $nonce;

	// The text used on the submit button of various forms
	public $submit_text;

	/**
	 * Initialize the class
	 *
	 * @since 3.0.0
	 */
	public function __construct( $args ) {

		// Parse the values passed
		$this->parse_args( $args );

		// Filter the content map so addons can customize what and how content
		// is output. Filters are specific to each view, so for this base view
		// you would use the filter 'us_content_map_ewdotpView'
		$this->content_map = apply_filters( 'ewd_otp_content_map_' . get_class( $this ), $this->content_map );
	}

	/**
	 * Render the view and enqueue required stylesheets
	 *
	 * @note This function should always be overridden by an extending class
	 * @since 3.0.0
	 */
	public function render() {

		$this->set_error(
			array(
				'type' => 'render() called on wrong class',
			)
		);
	}

	/**
	 * Load a template file for views
	 *
	 * First, it looks in the current theme's /ewd-otp-templates/ directory. Then it
	 * will check a parent theme's /ewd-otp-templates/ directory. If nothing is found
	 * there, it will retrieve the template from the plugin directory.

	 * @since 3.0.0
	 * @param string template Type of template to load (eg - reviews, review)
	 */
	function find_template( $template ) {
		if ( ! is_string( $template ) || ! preg_match( '/^[a-z0-9_-]+$/D', $template ) ) {
			return false;
		}
		$default_directory = apply_filters( 'ewd_otp_template_default_directory', EWD_OTP_PLUGIN_DIR . '/' . EWD_OTP_TEMPLATE_DIR . '/', $template, $this );
		if ( ! is_string( $default_directory ) ) {
			$default_directory = EWD_OTP_PLUGIN_DIR . '/' . EWD_OTP_TEMPLATE_DIR . '/';
		}

		$this->template_dirs = array(
			get_stylesheet_directory() . '/' . EWD_OTP_TEMPLATE_DIR . '/',
			get_template_directory() . '/' . EWD_OTP_TEMPLATE_DIR . '/',
			$default_directory,
		);

		$this->template_dirs = apply_filters( 'ewd_otp_template_directories', $this->template_dirs );

		foreach ( $this->template_dirs as $dir ) {
			if ( file_exists( $dir . $template . '.php' ) ) {
				return $dir . $template . '.php';
			}
		}

		return false;
	}

	/**
	 * Include a selected template in the shared view context after owner checks.
	 *
	 * @param string $template Fixed template basename, never a request path.
	 * @param array  $template_data Explicit local data for the selected owner.
	 * @param array  $template_context Stable owner operation metadata, not an access grant.
	 * @return void
	 */
	public function render_template( $template, $template_data = array(), $template_context = array() ) {
		$template_data    = is_array( $template_data ) ? $template_data : array();
		$template_context = is_array( $template_context ) ? $template_context : array();
		if ( true !== apply_filters( 'ewd_otp_template_render_allowed', true, $template, $this, $template_context ) ) {
			return;
		}
		$file                              = $this->find_template( $template );
		$template_context['template_file'] = $file;
		if ( $file && true === apply_filters( 'ewd_otp_template_render_allowed', true, $template, $this, $template_context ) ) {
			include $file;
		}
	}

	/**
	 * Enqueue stylesheets
	 */
	public function enqueue_assets() {

		// enqueue assets here
	}

	/**
	 * Print the details of an order, if one is selected
	 *
	 * @since 3.0.0
	 */
	public function maybe_print_order_tracking() {

		if ( empty( $this->order ) ) {
			return; }

		$template = $this->find_template( 'order-results' );

		if ( $template ) {
			include $template;
		}
	}

	/**
	 * Prints an action notification, if any action has happened
	 *
	 * @since 3.0.0
	 */
	public function maybe_print_update_message() {

		if ( empty( $this->update_message ) ) {
			return; }

		$template = $this->find_template( 'update-message' );

		if ( $template ) {
			include $template;
		}
	}

	/** Print the current error message template. */
	public function print_error_message() {
		if ( empty( $this->error_message ) ) {
			return; }

		$template = $this->find_template( 'error-message' );

		if ( $template ) {
			include $template;
		}
	}

	/**
	 * Print the details of an order, if one is selected
	 *
	 * @since 3.0.0
	 */
	public function print_custom_field() {

		if ( empty( $this->custom_field ) ) {
			return; }

		if ( 'file' === $this->custom_field->type ) {
			$template = 'custom-field-file';
		} elseif ( 'image' === $this->custom_field->type ) {
			$template = 'custom-field-image';
		} elseif ( 'link' === $this->custom_field->type ) {
			$template = 'custom-field-link';
		} else {
			$template = 'custom-field-default';
		}
		$this->render_template( $template );
	}

	/**
	 * Returns the custom fields designated for orders
	 *
	 * @since 3.0.0
	 */
	public function get_order_fields() {
		global $ewd_otp_controller;
		// Product-approved read-only history exception; this does not grant field editing or collection access.

		return $ewd_otp_controller->settings->get_order_custom_fields();
	}

	/**
	 * Returns whether the order number field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_number_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'order_number', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->number ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the order name field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_name_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'order_name', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->name ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the public order notes field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_notes_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'order_notes', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->notes_public ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the customer notes field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_customer_notes_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'customer_notes', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->customer_notes ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the customer name field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_customer_name_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'customer_name', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->customer ) || empty( $this->customer->name ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the customer email field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_customer_email_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'customer_email', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->customer ) || empty( $this->customer->email ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the sales rep first name field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_sales_rep_first_name_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'sales_rep_first_name', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->sales_rep ) || empty( $this->sales_rep->first_name ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the sales rep last name field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_sales_rep_last_name_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'sales_rep_last_name', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->sales_rep ) || empty( $this->sales_rep->last_name ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the sales rep email field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_sales_rep_email_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'sales_rep_email', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->sales_rep ) || empty( $this->sales_rep->email ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether a custom field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_custom_field_display( $custom_field ) {
		global $ewd_otp_controller;

		if ( ! $custom_field->display ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->custom_fields[ $custom_field->id ] ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the order status field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_status_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'order_status', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->external_status ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the order location field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_location_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'order_location', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->location ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Returns whether the order updated datetime field should be displayed or not
	 *
	 * @since 3.0.0
	 */
	public function get_order_updated_display() {
		global $ewd_otp_controller;

		if ( ! in_array( 'order_updated', $ewd_otp_controller->settings->get_setting( 'order-information' ), true ) ) {
			return false; }

		if ( $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' ) && ( empty( $this->order ) || empty( $this->order->status_updated ) ) ) {
			return false; }

		return true;
	}

	/**
	 * Return the name of the customer for this order, if any
	 *
	 * @since 3.0.13
	 */
	public function get_customer_name() {

		return empty( $this->customer->name ) ? '' : $this->customer->name;
	}

	/**
	 * Return the email of the customer for this order, if any
	 *
	 * @since 3.0.13
	 */
	public function get_customer_email() {

		return empty( $this->customer->email ) ? '' : $this->customer->email;
	}

	/**
	 * Return the first name of the sales rep for this order, if any
	 *
	 * @since 3.0.13
	 */
	public function get_sales_rep_first_name() {

		return empty( $this->sales_rep->first_name ) ? '' : $this->sales_rep->first_name;
	}

	/**
	 * Return the last name of the sales rep for this order, if any
	 *
	 * @since 3.0.13
	 */
	public function get_sales_rep_last_name() {

		return empty( $this->sales_rep->last_name ) ? '' : $this->sales_rep->last_name;
	}

	/**
	 * Return the email of the sales rep for this order, if any
	 *
	 * @since 3.0.13
	 */
	public function get_sales_rep_email() {

		return empty( $this->sales_rep->email ) ? '' : $this->sales_rep->email;
	}

	/**
	 * Returns the target attribute for a shortcode form
	 *
	 * @since 3.0.0
	 */
	public function get_form_target() {
		global $ewd_otp_controller;

		return $ewd_otp_controller->settings->get_setting( 'new-window' ) ? 'target="_blank"' : '';
	}

	public function get_option( $option_name ) {
		global $ewd_otp_controller;

		return ! empty( $this->$option_name ) ? $this->$option_name : $ewd_otp_controller->settings->get_setting( $option_name );
	}

	public function get_label( $label_name ) {
		if ( empty( $this->label_defaults ) ) {
			$this->set_label_defaults(); }
		$default = isset( $this->label_defaults[ $label_name ] ) ? $this->label_defaults[ $label_name ] : '';
		return apply_filters( 'ewd_otp_view_label', $default, $label_name, $this );
	}

	public function set_label_defaults() {

		$this->label_defaults = array(
			'label-order-form-title'                  => __( 'Track an Order', 'order-tracking' ),
			'label-order-form-number'                 => __( 'Order Number', 'order-tracking' ),
			'label-order-form-number-placeholder'     => '',
			'label-order-form-email'                  => __( 'Order E-mail Address', 'order-tracking' ),
			'label-order-form-email-placeholder'      => '',
			'label-order-form-button'                 => __( 'Track', 'order-tracking' ),
			'label-retrieving-results'                => __( 'Retrieving results...', 'order-tracking' ),
			'label-order-information'                 => __( 'Order Information', 'order-tracking' ),
			'label-order-number'                      => __( 'Order Number', 'order-tracking' ),
			'label-order-name'                        => __( 'Order Name', 'order-tracking' ),
			'label-order-notes'                       => __( 'Order Notes', 'order-tracking' ),
			'label-customer-notes'                    => __( 'Customer Notes', 'order-tracking' ),
			'label-order-status'                      => __( 'Order Status', 'order-tracking' ),
			'label-order-location'                    => __( 'Order Location', 'order-tracking' ),
			'label-order-updated'                     => __( 'Order Updated', 'order-tracking' ),
			'label-order-current-location'            => __( 'Current Location', 'order-tracking' ),
			'label-order-print-button'                => __( 'Print', 'order-tracking' ),
			'label-order-add-note-button'             => __( 'Add Note', 'order-tracking' ),
			'label-order-update-status'               => __( 'Update Status', 'order-tracking' ),
			'label-customer-display-number'       => __( 'Customer Number', 'order-tracking' ),
			'label-customer-display-name'         => __( 'Customer Name', 'order-tracking' ),
			'label-customer-display-email'        => __( 'Customer Email', 'order-tracking' ),
			'label-sales-rep-display-number'      => __( 'Sales Rep Number', 'order-tracking' ),
			'label-sales-rep-display-first-name'  => __( 'Sales Rep First Name', 'order-tracking' ),
			'label-sales-rep-display-last-name'   => __( 'Sales Rep Last Name', 'order-tracking' ),
			'label-sales-rep-display-email'       => __( 'Sales Rep Email', 'order-tracking' ),
		);

		$this->label_defaults = apply_filters( 'ewd_otp_view_label_defaults', $this->label_defaults, $this );
	}

	public function add_custom_styling() {
		global $ewd_otp_controller;
		$css  = apply_filters( 'ewd_otp_premium_styling', '', $this );
		$css  = is_string( $css ) ? $css : '';
		$css .= $ewd_otp_controller->settings->get_setting( 'custom-css' );
		if ( '' !== $css ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Preserve the Free stylesheet contract; remove HTML tags without encoding CSS selectors/quotes.
			echo '<style>' . wp_strip_all_tags( $css ) . '</style>';
		}
	}
}
