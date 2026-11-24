<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpSettings' ) ) {
	/**
	 * Class to handle configurable settings for Order Tracking
	 *
	 * @since 3.0.0
	 */
	class ewdotpSettings {

		public $order_information_options = array();

		public $sales_rep_options = array();

		public $status_options = array();

		public $currency_options = array();

		public $notification_options = array();

		/**
		 * Legacy public slot populated only by the owning paid module.
		 *
		 * @var array
		 */
		public $country_phone_array = array();

		/**
		 * Default values for settings
		 *
		 * @since 3.0.0
		 */
		public $defaults = array();

		/**
		 * Stored values for settings
		 *
		 * @since 3.0.0
		 */
		public $settings = array();

		public function __construct() {
			add_filter( 'pre_update_option_ewd-otp-settings', array( $this, 'protect_settings_request' ), 10, 2 );

			add_action( 'init', array( $this, 'set_defaults' ) );
			add_action( 'init', array( $this, 'repair_empty_statuses' ), 10 );

			add_action( 'init', array( $this, 'set_field_options' ), 11 );

			add_action( 'init', array( $this, 'load_settings_panel' ), 12 );
		}

		/**
		 * Load the plugin's default settings
		 *
		 * @since 3.0.0
		 */
		public function set_defaults() {

			$order_information_defaults = array(
				'order_number',
				'order_name',
				'order_status',
				'order_updated',
				'order_notes',
			);

			$this->defaults = array(

				'order-information'              => $order_information_defaults,

				'date-format'                    => _x( 'd-m-Y H:i:s', 'Default date format for display. Available options here https://www.php.net/manual/en/datetime.format.php', 'order-tracking' ),
				'form-instructions'              => __( 'Enter the order number you would like to track in the form below.', 'order-tracking' ),

				'access-role'                    => 'manage_options',
				'tracking-graphic'               => 'default',


				'woocommerce-integration'        => true,
				'woocommerce-replace-statuses'   => true,

				'woocommerce-paid-status'        => __( 'Completed', 'order-tracking' ),
				'woocommerce-unpaid-status'      => __( 'Pending Payment', 'order-tracking' ),
				'woocommerce-processing-status'  => __( 'Processing', 'order-tracking' ),
				'woocommerce-cancelled-status'   => __( 'Cancelled', 'order-tracking' ),
				'woocommerce-onhold-status'      => __( 'On Hold', 'order-tracking' ),
				'woocommerce-failed-status'      => __( 'Failed', 'order-tracking' ),
				'woocommerce-refunded-status'    => __( 'Refunded', 'order-tracking' ),

				'admin-email'                    => get_option( 'admin_email' ),
				'email-messages'                 => array(),

				'label-retrieving-results'       => __( 'Retrieving Results...', 'order-tracking' ),
			);

			$this->defaults = apply_filters( 'ewd_otp_defaults', $this->defaults, $this );
		}

		/**
		 * Put all of the available possible select options into key => value arrays
		 *
		 * @since 3.0.0
		 */
		public function set_field_options() {
			global $ewd_otp_controller;

			$this->order_information_options = array(
				'order_number'         => __( 'Order Number', 'order-tracking' ),
				'order_name'           => __( 'Name', 'order-tracking' ),
				'order_status'         => __( 'Status', 'order-tracking' ),
				'order_location'       => __( 'Location', 'order-tracking' ),
				'order_updated'        => __( 'Updated Date', 'order-tracking' ),
				'order_notes'          => __( 'Notes', 'order-tracking' ),
				'customer_notes'       => __( 'Customer Notes', 'order-tracking' ),
				'order_graphic'        => __( 'Status Graphic', 'order-tracking' ),
				'order_map'            => __( 'Tracking Map', 'order-tracking' ),
				'customer_name'        => __( 'Customer Name', 'order-tracking' ),
				'customer_email'       => __( 'Customer Email', 'order-tracking' ),
				'sales_rep_first_name' => __( 'Sales Rep First Name', 'order-tracking' ),
				'sales_rep_last_name'  => __( 'Sales Rep Last Name', 'order-tracking' ),
				'sales_rep_email'      => __( 'Sales Rep Email', 'order-tracking' ),
			);

			$this->order_information_options = apply_filters( 'ewd_otp_order_information_options', $this->order_information_options, $this );

			$statuses = ewd_otp_decode_infinite_table_setting( $this->get_setting( 'statuses' ) );

			foreach ( $statuses as $status ) {

				$this->status_options[ $status->status ] = $status->status;
			}

			$this->status_options = apply_filters( 'ewd_otp_status_options', $this->status_options, $this );

			$emails = ewd_otp_decode_infinite_table_setting( $this->get_setting( 'email-messages' ) );

			foreach ( $emails as $email ) {

				$this->notification_options['Emails'][ $email->id ] = $email->name;
			}

			if ( post_type_exists( 'uwpm_mail_template' ) ) {

				$this->notification_options[-1] = '';

				$args = array(
					'post_type'   => 'uwpm_mail_template',
					'numberposts' => -1,
				);

				$uwpm_emails = get_posts( $args );

				foreach ( $uwpm_emails as $uwpm_email ) {

					$email_id = $uwpm_email->ID * -1;

					$this->notification_options['Ultimate WP Mail'][ $email_id ] = $uwpm_email->post_title;
				}
			}

			$this->notification_options = apply_filters( 'ewd_otp_notification_options', $this->notification_options, $this );

			$args = array(
				'sales_reps_per_page' => -1,
			);

			$sales_reps = $ewd_otp_controller->sales_rep_manager->get_matching_sales_reps( $args );

			foreach ( $sales_reps as $sales_rep ) {

				$this->sales_rep_options[ $sales_rep->id ] = $sales_rep->first_name . ' ' . $sales_rep->last_name;
			}

			$this->sales_rep_options = apply_filters( 'ewd_otp_sales_rep_options', $this->sales_rep_options, $this );
			do_action( 'ewd_otp_field_options', $this );
		}

		/**
		 * Get a setting's value or fallback to a default if one exists
		 *
		 * @since 3.0.0
		 */
		public function get_setting( $setting ) {
			$value = $this->get_raw_setting( $setting );
			if ( 'order-information' === $setting && is_array( $value ) ) {
				$contact_fields = array(
					'customers'  => array( 'customer_name', 'customer_email' ),
					'sales_reps' => array( 'sales_rep_first_name', 'sales_rep_last_name', 'sales_rep_email' ),
				);
				foreach ( $contact_fields as $feature => $fields ) {
					if ( array_intersect( $value, $fields ) && ! ewdotpEntitlement::administer( $feature ) ) {
						$value = array_values( array_diff( $value, $fields ) );
					}
				}
			}
			$capability = self::setting_capability( $setting );
			// Historical definitions/privacy/settlement evidence are shared integrity data.
			$retained = array( 'locations', 'woocommerce-integration', 'woocommerce-replace-statuses', 'woocommerce-disable-status-revert', 'paypal-email-address', 'pricing-currency-code', 'zendesk-signing-secret', 'zendesk-status-mappings', 'ultimate-purchase-email', 'sms-country-code', 'admin-phone-number', 'sms-messages' );
			if ( '' !== $capability && ! in_array( $setting, $retained, true ) && ( null === $value || '' === $value ) ) {
				return '';
			}
			if ( '' === $capability || in_array( $setting, $retained, true ) || ewdotpEntitlement::allows( $capability ) ) {
				return $value;
			}
			if ( 'labelling' === $capability && array_key_exists( $setting, $this->defaults ) ) {
				return $this->defaults[ $setting ];
			}
			return 'tracking-graphic' === $setting ? 'default' : ( is_array( $value ) ? array() : ( is_bool( $value ) ? false : '' ) );
		}

		/**
		 * Read retained configuration for safety, settlement and administrator recovery.
		 *
		 * @param string $setting Setting name.
		 * @return mixed
		 */
		public function get_raw_setting( $setting ) {

			if ( empty( $this->settings ) ) {
				$stored_settings = get_option( 'ewd-otp-settings', array() );
				$this->settings  = is_array( $stored_settings ) ? $stored_settings : array();
			}

			if ( array_key_exists( $setting, $this->settings ) ) {
				$value = $this->settings[ $setting ];
				if ( 'date-format' === $setting && ( ! is_string( $value ) || '' === trim( $value ) ) ) {
					$value = isset( $this->defaults[ $setting ] ) ? $this->defaults[ $setting ] : 'd-m-Y H:i:s';
				}
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Preserve the existing public settings filters used by integrations.
				return apply_filters( 'ewd-otp-settings-' . $setting, $value );
			}

			if ( array_key_exists( $setting, $this->defaults ) ) {
				return apply_filters( 'ewd-otp-settings-' . $setting, $this->defaults[ $setting ] );
			}

			return apply_filters( 'ewd-otp-settings-' . $setting, null );
		}

		/**
		 * Classify paid fields without treating shared privacy/statuses as paid switches.
		 *
		 * @param string $setting Setting name.
		 * @return string
		 */
		public static function setting_capability( $setting ) {
			$prefixes = array(
				'styling-'     => 'styling',
				'label-'       => 'labelling',
				'woocommerce-' => 'woocommerce',
				'zendesk-'     => 'zendesk',
			);
			foreach ( $prefixes as $prefix => $capability ) {
				if ( 0 === strpos( $setting, $prefix ) ) {
					return in_array( $setting, array( 'woocommerce-replace-statuses', 'woocommerce-disable-status-revert' ), true ) ? '' : $capability;
				}
			}
			$groups = array(
				'premium'         => array( 'tracking-graphic', 'customer-notes-email' ),
				'customers'       => array( 'allow-customer-downloads', 'allow-assign-orders-to-customers' ),
				'sales_reps'      => array( 'allow-sales-rep-downloads', 'sales-rep-status-notifications', 'sales-rep-status-email' ),
				'customer_orders' => array( 'customer-order-email', 'default-customer-order-form-status', 'allow-sales-rep-selection', 'customer-order-number-prefix', 'customer-order-number-suffix', 'default-sales-rep', 'use-captcha', 'captcha-site-key', 'captcha-secret-key' ),
				'locations'       => array( 'locations' ),
				'payments'        => array( 'allow-order-payments', 'default-payment-status', 'paypal-email-address', 'pricing-currency-code', 'thank-you-url' ),
				'sms'             => array( 'ultimate-purchase-email', 'sms-country-code', 'admin-phone-number', 'sms-messages' ),
			);
			foreach ( $groups as $capability => $fields ) {
				if ( in_array( $setting, $fields, true ) ) {
					return $capability;
				}
			}
			return '';
		}

		/**
		 * Keep unavailable paid configuration unchanged, including direct settings posts.
		 *
		 * @param mixed $value Proposed sanitized settings.
		 * @param mixed $old Existing settings.
		 * @return array
		 */
		public function preserve_unavailable_settings( $value, $old ) {
			$value = is_array( $value ) ? $value : array();
			$old   = is_array( $old ) ? $old : array();
			foreach ( array_unique( array_merge( array_keys( $value ), array_keys( $old ) ) ) as $setting ) {
				$capability = self::setting_capability( $setting );
				if ( '' === $capability || ewdotpEntitlement::allows( $capability ) ) {
					continue;
				}
				if ( array_key_exists( $setting, $old ) ) {
					$value[ $setting ] = $old[ $setting ];
				} else {
					unset( $value[ $setting ] );
				}
			}
			return $value;
		}

		/**
		 * Guard Settings API writes, leaving trusted upgrade conversion lossless.
		 *
		 * @param mixed $value Proposed settings.
		 * @param mixed $old Retained settings.
		 * @return mixed
		 */
		public function protect_settings_request( $value, $old ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress options.php authenticates the registered setting group before this option filter.
			$is_settings_post = isset( $_POST['option_page'] ) && 'ewd-otp-settings' === $_POST['option_page'];
			return $is_settings_post ? $this->preserve_unavailable_settings( $value, $old ) : $value;
		}

		/**
		 * Set a setting to a particular value
		 *
		 * @since 3.0.0
		 */
		public function set_setting( $setting, $value ) {

			if ( ! is_array( $this->settings ) ) {
				$this->settings = array();
			}
			$this->settings[ $setting ] = $value;
		}
		/**
		 * Save all settings, to be used with set_setting
		 *
		 * @since 3.0.0
		 */
		public function save_settings() {
			$this->settings = $this->preserve_unavailable_settings( $this->settings, get_option( 'ewd-otp-settings', array() ) );
			update_option( 'ewd-otp-settings', $this->settings );
		}

		/**
		 * Load the admin settings page
		 *
		 * @since 3.0.0
		 * @sa https://github.com/NateWr/simple-admin-pages
		 */
		public function load_settings_panel() {

			global $ewd_otp_controller;

			require_once EWD_OTP_PLUGIN_DIR . '/lib/simple-admin-pages/simple-admin-pages.php';
			$sap      = sap_initialize_library(
				$args = array(
					'version' => '2.7.4',
					'lib_url' => EWD_OTP_PLUGIN_URL . '/lib/simple-admin-pages/',
					'theme'   => 'purple',
				)
			);

			$sap->add_page(
				'submenu',
				array(
					'id'          => 'ewd-otp-settings',
					'title'       => __( 'Settings', 'order-tracking' ),
					'menu_title'  => __( 'Settings', 'order-tracking' ),
					'parent_menu' => 'ewd-otp-orders',
					'description' => '',
					'capability'  => $this->get_setting( 'access-role' ),
					'default_tab' => 'ewd-otp-basic-tab',
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'             => 'ewd-otp-basic-tab',
					'title'          => __( 'Basic', 'order-tracking' ),
					'is_tab'         => true,
					'rank'           => 1,
					'tutorial_yt_id' => 'v8t0Z06Y_XY',
					'icon'           => 'text',
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'    => 'ewd-otp-general',
					'title' => __( 'General', 'order-tracking' ),
					'tab'   => 'ewd-otp-basic-tab',
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'textarea',
				array(
					'id'          => 'custom-css',
					'title'       => __( 'Custom CSS', 'order-tracking' ),
					'description' => __( 'You can add custom CSS styles to your tracking form/page in the box above.', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'checkbox',
				array(
					'id'          => 'order-information',
					'title'       => __( 'Order Information Displayed', 'order-tracking' ),
					'description' => __( 'What information should be displayed for your orders?', 'order-tracking' ),
					'options'     => $this->order_information_options,
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'toggle',
				array(
					'id'          => 'hide-blank-fields',
					'title'       => __( 'Hide Blank Fields', 'order-tracking' ),
					'description' => __( 'Should fields which don\'t have a value (ex. customer name, custom fields) be hidden if they\'re empty?', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'textarea',
				array(
					'id'          => 'form-instructions',
					'title'       => __( 'Form Instructions', 'order-tracking' ),
					'description' => __( 'The instructions that will display above the order form.', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'text',
				array(
					'id'          => 'date-format',
					'title'       => __( 'Date/Time Format', 'order-tracking' ),
					'description' => __( 'The format to use when displaying dates. Leave blank to use the default format. Possible options can be: <a href="https://www.php.net/manual/en/datetime.format.php">found here</a>', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'radio',
				array(
					'id'          => 'email-frequency',
					'title'       => __( 'Order Notification Frequency', 'order-tracking' ),
					'description' => __( 'How often should notifications be sent to customers about the status of their orders?', 'order-tracking' ),
					'options'     => array(
						'status_change' => __( 'On Status Change', 'order-tracking' ),
						'change'        => __( 'On Save', 'order-tracking' ),
						'creation'      => __( 'On Creation', 'order-tracking' ),
						'never'         => __( 'Never', 'order-tracking' ),
					),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'toggle',
				array(
					'id'          => 'disable-ajax-loading',
					'title'       => __( 'Disable AJAX Reloads', 'order-tracking' ),
					'description' => __( 'Should the use of AJAX to display search results without reloading the page be disabled?', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'toggle',
				array(
					'id'          => 'new-window',
					'title'       => __( 'New Window', 'order-tracking' ),
					'description' => __( 'Should search results open in a new window? (Doesn\'t work with AJAX reloads)', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'toggle',
				array(
					'id'          => 'display-print-button',
					'title'       => __( 'Display "Print" Button', 'order-tracking' ),
					'description' => __( 'Should a "Print" button be added to tracking form results, so that visitors can print their order information more easily?', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'toggle',
				array(
					'id'          => 'email-verification',
					'title'       => __( 'Email Verification', 'order-tracking' ),
					'description' => __( 'Do visitors need to also enter the email address associated with an order to be able to view order information? Manual customer and sales representative number lookups require matching email or a valid collection proof even when this single-order setting is off. Automatic linked-account portals remain available. Require Login uses linked-account or staff authorization instead.', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'text',
				array(
					'id'          => 'tracking-page-url',
					'title'       => __( 'Status Tracking URL', 'order-tracking' ),
					'description' => __( 'The URL of your tracking page, required if you want to include a tracking link in your message body, on the WooCommerce order page, etc.', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-general',
				'toggle',
				array(
					'id'          => 'use-wp-timezone',
					'title'       => __( 'Use WP Timezone', 'order-tracking' ),
					'description' => __( 'New status timestamps always use the timezone configured in WordPress. This setting is retained only to control how legacy timestamps created before version 3.6.0 are interpreted.', 'order-tracking' ),
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'             => 'ewd-otp-statuses-tab',
					'title'          => __( 'Statuses', 'order-tracking' ),
					'is_tab'         => true,
					'rank'           => 3,
					'tutorial_yt_id' => 'ih7qJEuOgPY',
					'icon'           => 'post-status',
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'    => 'ewd-otp-statuses',
					'title' => __( 'Statuses', 'order-tracking' ),
					'tab'   => 'ewd-otp-statuses-tab',
				)
			);

			$statuses_description = __( 'Statuses let your customers know the current status of their order.', 'order-tracking' ) . '<br />';

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-statuses',
				'infinite_table',
				array(
					'id'          => 'statuses',
					'title'       => __( 'Statuses', 'order-tracking' ),
					'add_label'   => __( '+ ADD', 'order-tracking' ),
					'del_label'   => __( 'Delete', 'order-tracking' ),
					'description' => $statuses_description,
					'fields'      => array(
						'status'     => array(
							'type'     => 'text',
							'label'    => 'Status',
							'required' => true,
						),
						'percentage' => array(
							'type'     => 'text',
							'label'    => '&#37; Complete',
							'required' => false,
						),
						'email'      => array(
							'type'    => 'select',
							'label'   => __( 'Notification', 'order-tracking' ),
							'options' => $this->notification_options,
						),
						'internal'   => array(
							'type'    => 'select',
							'label'   => __( 'Internal Status', 'order-tracking' ),
							'options' => array(
								'no'  => __( 'No', 'order-tracking' ),
								'yes' => __( 'Yes', 'order-tracking' ),
							),
						),
					),
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'             => 'ewd-otp-notifications-tab',
					'title'          => __( 'Notifications', 'order-tracking' ),
					'is_tab'         => true,
					'rank'           => 5,
					'tutorial_yt_id' => 'IDi__KeytMQ',
					'icon'           => 'bell',
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'    => 'ewd-otp-notifications',
					'title' => __( 'Emails', 'order-tracking' ),
					'tab'   => 'ewd-otp-notifications-tab',
				)
			);

			$emails_description  = __( 'What should be in the messages sent to users? You can put [order-name], [order-number], [order-status], [order-notes], [customer-notes] and [order-time] into the message, to include current order name, order number, order status, public order notes or the time the order was updated.', 'order-tracking' ) . '<br />';
			$emails_description .= __( 'You can also use [tracking-link], [customer-name], [customer-number], [customer-id], [sales-rep], [sales-rep-number] or the slug of a custom field enclosed in square brackets to include those fields in the email.', 'order-tracking' );

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-notifications',
				'infinite_table',
				array(
					'id'          => 'email-messages',
					'title'       => __( 'Email Messages', 'order-tracking' ),
					'add_label'   => __( '+ ADD', 'order-tracking' ),
					'del_label'   => __( 'Delete', 'order-tracking' ),
					'description' => $emails_description,
					'fields'      => array(
						'id'      => array(
							'type'     => 'hidden',
							'label'    => 'ID',
							'required' => true,
							'classes'  => array( 'sap-hidden' ),
						),
						'name'    => array(
							'type'     => 'text',
							'label'    => 'Name',
							'required' => true,
						),
						'subject' => array(
							'type'     => 'text',
							'label'    => 'Subject',
							'required' => true,
						),
						'message' => array(
							'type'     => 'editor',
							'label'    => 'Message',
							'required' => true,
						),
					),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-notifications',
				'text',
				array(
					'id'          => 'admin-email',
					'title'       => __( 'Admin Email', 'order-tracking' ),
					'description' => __( 'What email should customer note and customer order notifications be sent to, if they\'ve been set in the "Premium" area of the "Settings" page? Leave blank to use the WordPress admin email address.', 'order-tracking' ),
				)
			);

			/**
			 * Premium options preview only, except for some free WC options
			 */
			// "Premium" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'                 => 'ewd-otp-premium-tab',
					'title'              => __( 'Premium', 'order-tracking' ),
					'is_tab'             => true,
					'rank'               => 2,
					'tutorial_yt_id'     => 'DDQO1Wkahf0',
					'show_submit_button' => $this->show_submit_button( 'premium' ),
					'icon'               => 'awards',
				)
			);
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-premium-tab-body',
					'tab'      => 'ewd-otp-premium-tab',
					'callback' => $this->premium_info( 'premium' ),
				)
			);

			// "Locations" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'                 => 'ewd-otp-locations-tab',
					'title'              => __( 'Locations', 'order-tracking' ),
					'is_tab'             => true,
					'rank'               => 4,
					'tutorial_yt_id'     => 'hptAmlqQ4G0',
					'show_submit_button' => $this->show_submit_button( 'locations' ),
					'icon'               => 'location',
				)
			);
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-locations-tab-body',
					'tab'      => 'ewd-otp-locations-tab',
					'callback' => $this->premium_info( 'locations' ),
				)
			);

			// "Payments" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'                 => 'ewd-otp-payments-tab',
					'title'              => __( 'Payments', 'order-tracking' ),
					'is_tab'             => true,
					'rank'               => 7,
					'tutorial_yt_id'     => 'oDt9BGvVdtQ',
					'show_submit_button' => $this->show_submit_button( 'payments' ),
					'icon'               => 'money-alt',
				)
			);
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-payments-tab-body',
					'tab'      => 'ewd-otp-payments-tab',
					'callback' => $this->premium_info( 'payments' ),
				)
			);

			// "WooCommerce" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'             => 'ewd-otp-woocommerce-tab',
					'title'          => __( 'WooCommerce', 'order-tracking' ),
					'is_tab'         => true,
					'rank'           => 6,
					'tutorial_yt_id' => 'zWTGldvnnc8',
					'icon'           => 'cart',
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array_merge(
					array(
						'id'    => 'ewd-otp-woocommerce-general-options',
						'title' => __( 'WooCommerce Options', 'order-tracking' ),
						'tab'   => 'ewd-otp-woocommerce-tab',
					)
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-woocommerce-general-options',
				'toggle',
				array(
					'id'          => 'woocommerce-integration',
					'title'       => __( 'WooCommerce Integration', 'order-tracking' ),
					'description' => __( 'Should WooCommerce orders be automatically created inside of the Status Tracking plugin?', 'order-tracking' ),
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-woocommerce-general-options',
				'toggle',
				array(
					'id'                   => 'woocommerce-replace-statuses',
					'title'                => __( 'Replace WooCommerce Statuses with Status Tracking Statuses', 'order-tracking' ),
					'description'          => __( 'Should order tracking statuses replace the default WooCommerce statuses?', 'order-tracking' ),
					'conditional_on'       => 'woocommerce-integration',
					'conditional_on_value' => true,
				)
			);

			$sap->add_setting(
				'ewd-otp-settings',
				'ewd-otp-woocommerce-general-options',
				'toggle',
				array(
					'id'                   => 'disable-woocommerce-revert-statuses',
					'title'                => __( 'Disable Revert WooCommerce Statuses on Deactivation', 'order-tracking' ),
					'description'          => __( 'Should WooCommerce orders having their statuses returned to one of the defaults when the plugin is deactivated be disabled? This is disabled by default if WooCommerce integration is not enabled, but could result in orders with custom statuses being hidden from the WooCommerce admin orders table after deactivation.', 'order-tracking' ),
					'conditional_on'       => 'woocommerce-integration',
					'conditional_on_value' => true,
				)
			);

			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-woocommerce-tab-body',
					'tab'      => 'ewd-otp-woocommerce-tab',
					'callback' => $this->premium_info( 'woocommerce' ),
				)
			);

			// "Zendesk" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'                 => 'ewd-otp-zendesk-tab',
					'title'              => __( 'Zendesk', 'order-tracking' ),
					'is_tab'             => true,
					'rank'               => 10,
					'tutorial_yt_id'     => 'r00ewZ8l0z8',
					'show_submit_button' => $this->show_submit_button( 'zendesk' ),
					'icon'               => 'feedback',
				)
			);
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-zendesk-tab-body',
					'tab'      => 'ewd-otp-zendesk-tab',
					'callback' => $this->premium_info( 'zendesk' ),
				)
			);

			// "Labelling" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'                 => 'ewd-otp-labelling-tab',
					'title'              => __( 'Labelling', 'order-tracking' ),
					'is_tab'             => true,
					'rank'               => 9,
					'tutorial_yt_id'     => 'oGimPjCPTdU',
					'show_submit_button' => $this->show_submit_button( 'labelling' ),
					'icon'               => 'translation',
				)
			);
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-labelling-tab-body',
					'tab'      => 'ewd-otp-labelling-tab',
					'callback' => $this->premium_info( 'labelling' ),
				)
			);

			// "Styling" Tab
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'                 => 'ewd-otp-styling-tab',
					'title'              => __( 'Styling', 'order-tracking' ),
					'is_tab'             => true,
					'rank'               => 8,
					'tutorial_yt_id'     => 'c75VcMG11a8',
					'show_submit_button' => $this->show_submit_button( 'styling' ),
					'icon'               => 'welcome-widgets-menus',
				)
			);
			$sap->add_section(
				'ewd-otp-settings',
				array(
					'id'       => 'ewd-otp-styling-tab-body',
					'tab'      => 'ewd-otp-styling-tab',
					'callback' => $this->premium_info( 'styling' ),
				)
			);

			$sap = apply_filters( 'ewd_otp_settings_page', $sap, $this );

			$sap->add_admin_menus();
		}

		public function show_submit_button( $permission_type = '' ) {
			global $ewd_otp_controller;

			if ( $ewd_otp_controller->permissions->check_permission( $permission_type ) ) {
				return true;
			}

			return false;
		}

		public function premium_info( $section_and_perm_type ) {
			global $ewd_otp_controller;

			$is_premium_user     = $ewd_otp_controller->permissions->check_permission( $section_and_perm_type );
			$is_helper_installed = defined( 'EWDPH_PLUGIN_FNAME' ) && is_plugin_active( EWDPH_PLUGIN_FNAME );

			if ( $is_premium_user || $is_helper_installed ) {
				return false;
			}

			$content = '';

			$premium_features = '
			<div class="ewd-otp-settings-preview-other-features">
				<div id="ewd-otp-dashboard-new-footer-one">
					<div class="ewd-otp-dashboard-new-footer-one-inside">
						<div class="ewd-otp-dashboard-new-footer-one-left">
							<div class="ewd-otp-dashboard-new-footer-one-title">' . __( 'The premium version also gives you access to the following features:', 'order-tracking' ) . '</div>
							<ul class="ewd-otp-dashboard-new-footer-one-benefits">
								<li>' . __( 'Create & Assign Orders to Sales Reps', 'order-tracking' ) . '</li>
								<li>' . __( 'Create & Tie Orders to Customers', 'order-tracking' ) . '</li>
								<li>' . __( 'Custom Fields', 'order-tracking' ) . '</li>
								<li>' . __( 'Advanced WooCommerce Features', 'order-tracking' ) . '</li>
								<li>' . __( 'Advanced Display & Styling Options', 'order-tracking' ) . '</li>
								<li>' . __( 'Front-End Customer Order Form', 'order-tracking' ) . '</li>
								<li>' . __( 'Import/Export Orders', 'order-tracking' ) . '</li>
								<li>' . __( 'Set Up Status Locations', 'order-tracking' ) . '</li>
								<li>' . __( 'Email Support', 'order-tracking' ) . '</li>
							</ul>
						</div>
						<div class="ewd-otp-dashboard-new-footer-one-buttons">
							<a class="ewd-otp-dashboard-new-upgrade-button" href="https://www.etoilewebdesign.com/license-payment/?Selected=OTP&Quantity=1&utm_source=otp_settings&utm_content=' . $section_and_perm_type . '" target="_blank">' . __( 'UPGRADE NOW', 'order-tracking' ) . '</a>
						</div>
					</div>
				</div>
			</div>
		';

			switch ( $section_and_perm_type ) {

				case 'premium':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'Premium', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'The premium options let you change the tracking graphic, configure notification emails, customize the order form and more.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/premium1.png" alt="OTP premium screenshot one">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/premium2.png" alt="OTP premium screenshot two">
						</div>
						' . $premium_features . '
					</div>
				';

					break;

				case 'locations':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'Locations', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'You can create locations, which can be assigned to orders, so your customers know exactly where their orders are. You can also specify latitude and longitude coordinates for each location, allowing it to display on a map on the tracking page.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/locations.png" alt="OTP locations screenshot">
						</div>
						' . $premium_features . '
					</div>
				';

					break;

				case 'payments':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'Payments', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'The payment options let you enable and configure the ability to accept payment for orders via PayPal.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/payments.png" alt="OTP payments screenshot">
						</div>
						' . $premium_features . '
					</div>
				';

					break;

				case 'woocommerce':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'WooCommerce', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'With the premium version of the plugin, you gain access to advanced WooCommerce options, such as the ability to show the tracking status right on the WooCommerce order and tracking pages, the ability to control the order location in WooCommerce, the ability to customize the order number with prefix and suffix, and the ability to link WooCommerce statuses with status in the Order Tracking plugin.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/woocommerce1.png" alt="OTP woocommerce screenshot one">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/woocommerce2.png" alt="OTP woocommerce screenshot two">
						</div>
						' . $premium_features . '
					</div>
				';

					break;

				case 'zendesk':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'Sendesk', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'This lets you enable Zendesk integration, so, every time you get a new ticket in Zendesk, it creates an order in the plugin.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/zendesk.png" alt="OTP zendesk screenshot">
						</div>
						' . $premium_features . '
					</div>
				';

					break;

				case 'labelling':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'Labelling', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'The labelling options let you change the wording of the different labels that appear on the front end of the plugin. You can use this to translate them, customize the wording for your purpose, etc.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/labelling1.png" alt="OTP labelling screenshot one">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/labelling2.png" alt="OTP labelling screenshot two">
						</div>
						' . $premium_features . '
					</div>
				';

					break;

				case 'styling':
					$content = '
					<div class="ewd-otp-settings-preview">
						<h2>' . __( 'Styling', 'order-tracking' ) . '<span>' . __( 'Premium', 'order-tracking' ) . '</span></h2>
						<p>' . __( 'The styling options let you modify the color, font size, font family, border, margin and padding of the various elements found in your tracking forms and orders.', 'order-tracking' ) . '</p>
						<div class="ewd-otp-settings-preview-images">
							<img src="' . EWD_OTP_PLUGIN_URL . '/assets/img/premium-screenshots/styling.png" alt="OTP styling screenshot">
						</div>
						' . $premium_features . '
					</div>
				';

					break;
			}

			return function () use ( $content ) {

				echo wp_kses_post( $content );
			};
		}

		/**
		 * Create a set of default statuses and emails if none exist
		 *
		 * @since 3.0.0
		 */
		public function create_default_statuses_and_emails() {

			$statuses = ewd_otp_decode_infinite_table_setting( $this->get_setting( 'statuses' ) );

			if ( ! empty( $statuses ) ) {
				return; }

			$emails = array(
				array(
					'id'      => 1,
					'name'    => __( 'Default', 'order-tracking' ),
					'subject' => __( 'Order Status Update', 'order-tracking' ),
					'message' => __( 'Hello [order-name], You have an update for your order [order-number]!', 'order-tracking' ),
				),
			);

			if ( empty( $this->get_setting( 'email-messages' ) ) ) {
				$this->set_setting( 'email-messages', wp_json_encode( $emails ) );
			}

			$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

			$statuses = array();

			if ( ! empty( $wc_statuses ) ) {

				foreach ( $wc_statuses as $wc_status_slug => $wc_status ) {

					$status_item = array(
						'status'     => $wc_status,
						'percentage' => '0',
						'email'      => 1,
						'internal'   => 'no',
					);

					if ( 'wc-pending' === $wc_status_slug ) {
						$status_item['percentage'] = '25';
					} elseif ( 'wc-processing' === $wc_status_slug || 'wc-on-hold' === $wc_status_slug ) {
						$status_item['percentage'] = '50';
					} elseif ( 'wc-completed' === $wc_status_slug ) {
						$status_item['percentage'] = '100';
					}

					$statuses[] = $status_item;
				}
			} else {
				$statuses = array(
					array(
						'status'     => __( 'Pending Payment', 'order-tracking' ),
						'percentage' => '25',
						'email'      => 1,
						'internal'   => 'no',
					),
					array(
						'status'     => __( 'Processing', 'order-tracking' ),
						'percentage' => '50',
						'email'      => 1,
						'internal'   => 'no',
					),
					array(
						'status'     => __( 'On Hold', 'order-tracking' ),
						'percentage' => '50',
						'email'      => 1,
						'internal'   => 'no',
					),
					array(
						'status'     => __( 'Completed', 'order-tracking' ),
						'percentage' => '100',
						'email'      => 1,
						'internal'   => 'no',
					),
					array(
						'status'     => __( 'Cancelled', 'order-tracking' ),
						'percentage' => '0',
						'email'      => 1,
						'internal'   => 'no',
					),
					array(
						'status'     => __( 'Refunded', 'order-tracking' ),
						'percentage' => '0',
						'email'      => 1,
						'internal'   => 'no',
					),
					array(
						'status'     => __( 'Failed', 'order-tracking' ),
						'percentage' => '0',
						'email'      => 1,
						'internal'   => 'no',
					),
				);
			}

			$this->set_setting( 'statuses', json_encode( $statuses ) );

			$this->save_settings();
		}

		/** Restore only the legacy empty-status configuration during an upgrade. */
		public function repair_empty_statuses() {
			$stored = get_option( 'ewd-otp-settings', array() );
			if ( ! is_array( $stored ) || ! array_key_exists( 'statuses', $stored ) ) {
				return;
			}
			if ( ! empty( ewd_otp_decode_infinite_table_setting( $stored['statuses'] ) ) ) {
				return;
			}
			$this->create_default_statuses_and_emails();
		}

		/**
		 * Load all custom fields
		 *
		 * @since 3.0.0
		 */
		public function get_custom_fields() {

			$custom_fields = is_array( get_option( 'ewd-otp-custom-fields' ) ) ? get_option( 'ewd-otp-custom-fields' ) : array();

			return $custom_fields;
		}

		/**
		 * Load retained order definitions, including read-only historical display after license loss.
		 * This is not an editing grant; administration and creation retain their capability/cohort guards.
		 *
		 * @since 3.0.0
		 */
		public function get_order_custom_fields() {

			$custom_fields = is_array( get_option( 'ewd-otp-custom-fields' ) ) ? get_option( 'ewd-otp-custom-fields' ) : array();

			$return_fields = array();

			foreach ( $custom_fields as $custom_field ) {

				if ( 'orders' === $custom_field->function ) {
					$return_fields[] = $custom_field; }
			}

			return $return_fields;
		}

		/**
		 * Load the custom fields related to customers
		 *
		 * @since 3.0.0
		 */
		public function get_customer_custom_fields() {

			$custom_fields = is_array( get_option( 'ewd-otp-custom-fields' ) ) ? get_option( 'ewd-otp-custom-fields' ) : array();

			$return_fields = array();

			foreach ( $custom_fields as $custom_field ) {

				if ( 'customers' === $custom_field->function ) {
					$return_fields[] = $custom_field; }
			}

			return $return_fields;
		}

		/**
		 * Load the custom fields related to sales reps
		 *
		 * @since 3.0.0
		 */
		public function get_sales_rep_custom_fields() {

			$custom_fields = is_array( get_option( 'ewd-otp-custom-fields' ) ) ? get_option( 'ewd-otp-custom-fields' ) : array();

			$return_fields = array();

			foreach ( $custom_fields as $custom_field ) {

				if ( 'sales_reps' === $custom_field->function ) {
					$return_fields[] = $custom_field; }
			}

			return $return_fields;
		}
	}
} // endif;
