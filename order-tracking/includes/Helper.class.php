<?php
if ( !defined( 'ABSPATH' ) ) exit;

if ( !class_exists( 'ewdotpHelper' ) ) {
/**
 * Class to to provide helper functions
 *
 * @since 3.0.17
 */
class ewdotpHelper {

  // Hold the class instance.
  private static $instance = null;

  // Links for the help button
  private static $documentation_link = 'https://doc.etoilewebdesign.com/plugins/order-tracking/';
  private static $tutorials_link = 'https://www.youtube.com/watch?v=ylJ6CET7ppU&list=PLEndQUuhlvSqa6Txwj1-Ohw8Bj90CIRl0';
  private static $faq_link = 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/faq';
  private static $wp_forum_support_link = 'https://wordpress.org/support/plugin/order-tracking/';
  private static $support_center_link = 'https://etoilewebdesign.com/support-center';

  // Values for when to trigger the help button to display
  private static $post_types = array();
  private static $taxonomies = array();
  private static $additional_pages = array(
    'ewd-otp-dashboard',
    'ewd-otp-orders',
    'ewd-otp-add-edit-order',
    'ewd-otp-customers',
    'ewd-otp-add-edit-customer',
    'ewd-otp-sales-reps',
    'ewd-otp-add-edit-sales-rep',
    'ewd-otp-sales-rep-orders',
    'ewd-otp-export',
    'ewd-otp-import',
    'ewd-otp-custom-fields',
    'ewd-otp-settings',
    'ewd-otp-about-us'
  );

  /**
   * The constructor is private
   * to prevent initiation with outer code.
   * 
   **/
  private function __construct() {}

  /**
   * The object is created from within the class itself
   * only if the class has no instance.
   */
  public static function getInstance() {

    if ( self::$instance == null ) {

      self::$instance = new ewdotpHelper();
    }
 
    return self::$instance;
  }

  /**
   * Register OTP help with AIAA when available.
   *
   * @since 5.1.0
   */
  public static function aiaa_add_filter() {

    add_filter( 'ait_aiaa_third_party_information', array( __CLASS__, 'add_help_to_aiaa' ), 20, 2 );
  }

  /**
   * Add OTP help content to AIAA's third-party Help tab.
   *
   * @param array $items
   * @param array $context
   * @return array
   *
   * @since 5.1.0
   */
  public static function add_help_to_aiaa( $items, $context ) {

    $items   = is_array( $items ) ? $items : array();
    $context = is_array( $context ) ? $context : array();

    $screen_id = isset( $context['screen_id'] ) ? (string) $context['screen_id'] : '';
    $post_type = isset( $context['post_type'] ) ? (string) $context['post_type'] : '';
    $taxonomy  = isset( $context['taxonomy'] ) ? (string) $context['taxonomy'] : '';

    if ( ! self::aiaa_matches_context( $screen_id, $post_type, $taxonomy ) ) { return $items; }

    $page_details = self::get_page_details_for_context( $context );

    $tutorial_links = array();
    if ( ! empty( $page_details['tutorials'] ) && is_array( $page_details['tutorials'] ) ) {

      foreach ( $page_details['tutorials'] as $tutorial ) {

        if ( empty( $tutorial['url'] ) || empty( $tutorial['title'] ) ) { continue; }

        $tutorial_links[] = array(
          'title' => (string) $tutorial['title'],
          'url'   => (string) $tutorial['url'],
        );
      }
    }

    $general_links = array();
    if ( ! empty( self::$documentation_link ) ) {
      $general_links[] = array(
        'title' => __( 'Documentation', 'order-tracking' ),
        'url'   => self::$documentation_link,
      );
    }
    if ( ! empty( self::$tutorials_link ) ) {
      $general_links[] = array(
        'title' => __( 'YouTube Tutorials', 'order-tracking' ),
        'url'   => self::$tutorials_link,
      );
    }
    if ( ! empty( self::$faq_link ) ) {
      $general_links[] = array(
        'title' => __( 'FAQ', 'order-tracking' ),
        'url'   => self::$faq_link,
      );
    }
    if ( ! empty( self::$wp_forum_support_link ) ) {
      $general_links[] = array(
        'title' => __( 'WP Forum Support', 'order-tracking' ),
        'url'   => self::$wp_forum_support_link,
      );
    }
    if ( ! empty( self::$support_center_link ) ) {
      $general_links[] = array(
        'title' => __( 'Support Center', 'order-tracking' ),
        'url'   => self::$support_center_link,
      );
    }

    $help_links = array();
    if ( ! empty( $tutorial_links ) ) { $help_links[ __( 'Tutorials', 'order-tracking' ) ] = $tutorial_links; }
    if ( ! empty( $general_links ) ) { $help_links[ __( 'General', 'order-tracking' ) ] = $general_links; }

    $items[] = array(
      'id'              => 'otp_help',
      'title'           => __( 'Order Tracking Help', 'order-tracking' ),
      'description'     => ! empty( $page_details['description'] ) ? '<p>' . esc_html( $page_details['description'] ) . '</p>' : '',
      'help_links'      => $help_links,
      'source'          => array(
        'type' => 'plugin',
        'name' => 'Order Tracking',
        'slug' => 'order-tracking',
      ),
      'target_callback' => array( __CLASS__, 'aiaa_target_callback' ),
      'priority'        => 20,
      'capability'      => 'manage_options',
      'icon'            => 'dashicons-editor-help',
    );

    return $items;
  }

  /**
   * AIAA advanced targeting callback.
   *
   * @param array $context
   * @param array $item
   * @return bool
   *
   * @since 5.1.0
   */
  public static function aiaa_target_callback( $context, $item ) {

    $context = is_array( $context ) ? $context : array();

    $screen_id = isset( $context['screen_id'] ) ? (string) $context['screen_id'] : '';
    $post_type = isset( $context['post_type'] ) ? (string) $context['post_type'] : '';
    $taxonomy  = isset( $context['taxonomy'] ) ? (string) $context['taxonomy'] : '';

    return self::aiaa_matches_context( $screen_id, $post_type, $taxonomy );
  }

  /**
   * Shared matcher for AIAA context.
   *
   * @param string $screen_id
   * @param string $post_type
   * @param string $taxonomy
   * @return bool
   *
   * @since 5.1.0
   */
  private static function aiaa_matches_context( $screen_id, $post_type, $taxonomy ) {

    if ( ! empty( $post_type ) && in_array( $post_type, self::$post_types, true ) ) { return true; }

    if ( ! empty( $taxonomy ) && in_array( $taxonomy, self::$taxonomies, true ) ) { return true; }

    if ( ! empty( $screen_id ) && ! empty( self::$additional_pages ) ) {

      foreach ( self::$additional_pages as $slug ) {

        if ( empty( $slug ) ) { continue; }

        if ( strpos( $screen_id, $slug ) !== false ) { return true; }
      }
    }

    return false;
  }

  /**
   * Handle ajax requests in admin area for logged out users
   * @since 5.1.0
   */
  public static function admin_nopriv_ajax() {

    wp_send_json_error(
      array(
        'error' => 'loggedout',
        'msg'   => sprintf( __( 'You have been logged out. Please %slogin again%s.', 'order-tracking' ), '<a href="' . wp_login_url( admin_url( 'admin.php?page=ewd-otp-dashboard' ) ) . '">', '</a>' ),
      )
    );
  }

  /**
   * Handle ajax requests where an invalid nonce is passed with the request
   * @since 5.1.0
   */
  public static function bad_nonce_ajax() {

    wp_send_json_error(
      array(
        'error' => 'badnonce',
        'msg'   => __( 'The request has been rejected because it does not appear to have come from this site.', 'order-tracking' ),
      )
    );
  }

  /**
   * Escapes PHP data being passed to JS, recursively
   * @since 5.1.0
   */
  public static function escape_js_recursive( $values ) {

    $return_values = array();

    foreach ( (array) $values as $key => $value ) {

      if ( is_array( $value ) ) {

        $value = ewdotpHelper::escape_js_recursive( $value );
      }
      elseif ( ! is_scalar( $value ) ) { 

        continue;
      }
      else {

        $value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
      }
      
      $return_values[ $key ] = $value;
    }

    return $return_values;
  }

  public static function get_page_details_for_context( $context ) {
    $context = is_array( $context ) ? $context : array();
    $request = isset( $context['request'] ) && is_array( $context['request'] ) ? $context['request'] : array();

    $page = isset( $request['page'] ) ? sanitize_text_field( $request['page'] ) : '';
    $tab = isset( $request['tab'] ) ? sanitize_text_field( $request['tab'] ) : '';
    $taxonomy = isset( $context['taxonomy'] ) ? sanitize_text_field( $context['taxonomy'] ) : '';
    $post_type = isset( $context['post_type'] ) ? sanitize_text_field( $context['post_type'] ) : '';

    if ( ! $page && ! empty( $context['screen_id'] ) ) {
      foreach ( self::$additional_pages as $slug ) {
        if ( strpos( (string) $context['screen_id'], $slug ) !== false ) {
          $page = $slug;
          break;
        }
      }
    }

    return self::get_page_details_by_values( $page, $tab, $taxonomy, $post_type );
  }

  private static function get_page_details_by_values( $page, $tab, $taxonomy, $post_type ) {
    $page_details = array(
      'ewd-otp-dashboard' => array(
        'description' => __( 'A quick overview of your Order Tracking plugin at a glance. See a summary of recent orders. Also provides access to upgrade options and helpful links to documentation, support, and tutorial videos to help you get the most out of the plugin.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/add-tracking-form-to-page',
            'title' => 'Add Tracking Form to a Page'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/blocks-shortcodes/',
            'title' => 'Block and Shortcodes'
          ),
        )
      ),
      'ewd-otp-orders' => array(
        'description' => __( 'The central hub for managing all your orders. View the full orders list with filters for All, Today, This Week, or a specific date and time. Search by order number or other available fields, and use bulk actions to delete orders or update multiple orders to a specific status at once. Add, edit, or delete individual orders, assign them to customers or sales reps, update status and location, and add public or private notes.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/',
            'title' => 'Orders'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/create',
            'title' => 'Create an Order'
          ),
        )
      ),
      'ewd-otp-add-edit-order' => array(
        'description' => __( 'Add a new order or update an existing one. Fill in order details including status, location, customer and sales rep assignment, notes, and any custom fields. All changes are saved directly from this screen, keeping order management quick and centralized.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/',
            'title' => 'Orders'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/create',
            'title' => 'Create an Order'
          ),
        )
      ),
      'ewd-otp-customers' => array(
        'description' => __( 'Manage all your customers from one screen. Add new customers directly from this page and search existing records by number, name, or email. The overview table displays each customer\'s ID, number, name, email, and assigned sales rep at a glance. Click any record to edit details or assign orders to that customer.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/',
            'title' => 'Customers'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/create',
            'title' => 'Create a Customer'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/tracking-form',
            'title' => 'Customer Tracking Form'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/wordpress-user',
            'title' => 'Associate Customer with WordPress User'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/customer-order-form',
            'title' => 'Customer Order Form'
          ),
        )
      ),
      'ewd-otp-add-edit-customer' => array(
        'description' => __( 'Add a new customer or update an existing one. Fill in customer details including number, name, and email, and optionally link the record to a WordPress username or a sales rep. A table at the bottom displays all orders associated with that customer, giving you a full overview without leaving the screen.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/',
            'title' => 'Customers'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/create',
            'title' => 'Create a Customer'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/tracking-form',
            'title' => 'Customer Tracking Form'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/wordpress-user',
            'title' => 'Associate Customer with WordPress User'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/customer-order-form',
            'title' => 'Customer Order Form'
          ),
        )
      ),
      'ewd-otp-sales-reps' => array(
        'description' => __( 'Manage all your sales representatives from one screen. Add new sales reps directly from this page and search existing records by number, first name, last name, or email. The overview table displays each rep\'s ID, number, first name, last name, and email. Sales reps can log in to view and manage only the orders assigned to them.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/',
            'title' => 'Sales Reps'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/sales-reps/create',
            'title' => 'Create a Sales Rep'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/sales-reps/tracking-form',
            'title' => 'Sales Rep Tracking Form'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/sales-reps/wordpress-user',
            'title' => 'Associate Sales Rep with WordPress User'
          ),
        )
      ),
      'ewd-otp-add-edit-sales-rep' => array(
        'description' => __( 'Add a new sales rep or update an existing one. Fill in details including number, first name, last name, and email, and optionally link the record to a WordPress username. A table at the bottom displays all orders currently assigned to that sales rep, giving you a full overview without leaving the screen.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/customers/',
            'title' => 'Sales Reps'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/sales-reps/create',
            'title' => 'Create a Sales Rep'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/sales-reps/tracking-form',
            'title' => 'Sales Rep Tracking Form'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/sales-reps/wordpress-user',
            'title' => 'Associate Sales Rep with WordPress User'
          ),
        )
      ),
      'ewd-otp-export' => array(
        'description' => __( 'Export your data to a spreadsheet file for reporting, record keeping, or backup. Choose the type of record to export — Orders, Customers, or Sales Reps. For orders, narrow results by status, date range (Today, This Week, a past period, or a custom from/to range), specific customers, or specific sales reps. Useful for sharing data outside of WordPress or migrating records to another system.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/export',
            'title' => 'Export Orders'
          ),
        )
      ),
      'ewd-otp-import' => array(
        'description' => __( 'Import records in bulk by uploading a spreadsheet file. Supports separate imports for Orders, Customers, and Sales Reps, each with their own dedicated file upload. A fast way to populate the plugin with existing data, migrate from another system, or batch-add records without manual entry.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/import',
            'title' => 'Import Orders'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/files/spreadsheet-templates/otp.xlsx',
            'title' => 'Sample Import Spreadsheet'
          ),
        )
      ),
      'ewd-otp-custom-fields' => array(
        'description' => __( 'Create additional fields to capture extra information on orders, customers, and sales reps. Add fields like expected delivery date, tracking number, product dimensions, or any other data relevant to your workflow. Custom fields appear on order records and can be displayed on the front-end tracking page.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/custom-fields/',
            'title' => 'Custom Fields'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/custom-fields/create',
            'title' => 'Create and Edit Custom Fields'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/custom-fields/orders',
            'title' => 'Using Custom Fields with Orders'
          ),
        )
      ),
      'ewd-otp-settings' => array(
        'description' => __( 'Core plugin configuration. Control which order information fields are displayed on the tracking form, hide blank fields, and set form instructions and date/time format. Configure notification frequency, enable email verification for order lookups, set your status tracking URL, and toggle options like AJAX reloads, new window results, a print button, and WordPress timezone usage.', 'order-tracking' ),
        'tutorials'   => array()
      ),
      'ewd-otp-settings-ewd-otp-basic-tab' => array(
        'description' => __( 'Core plugin configuration. Control which order information fields are displayed on the tracking form, hide blank fields, and set form instructions and date/time format. Configure notification frequency, enable email verification for order lookups, set your status tracking URL, and toggle options like AJAX reloads, new window results, a print button, and WordPress timezone usage.', 'order-tracking' ),
        'tutorials'   => array()
      ),
      'ewd-otp-settings-ewd-otp-premium-tab' => array(
        'description' => __( 'Advanced configuration for premium users. Control admin menu access by role, choose a status tracking graphic style, and configure customer and sales rep download permissions. Manage the customer order form behavior including default status, order number prefix/suffix, sales rep assignment, captcha, and admin notifications. Additional options cover sales rep status notifications and automatic customer assignment based on email address.', 'order-tracking' ),
        'tutorials'   => array()
      ),
      'ewd-otp-settings-ewd-otp-statuses-tab' => array(
        'description' => __( 'Create and manage the order statuses used throughout the plugin. Add custom statuses, configure which ones are internal-only (visible to admins and sales reps only), and assign notification emails to trigger when an order is moved to a specific status.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/statuses',
            'title' => 'Statuses'
          ),
        )
      ),
      'ewd-otp-settings-ewd-otp-locations-tab' => array(
        'description' => __( 'Define and manage the locations used to track where an order is in its journey. Add new locations, edit existing ones, and use them to give customers more granular visibility into the progress of their order.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/orders/locations',
            'title' => 'Locations'
          ),
        )
      ),
      'ewd-otp-settings-ewd-otp-notifications-tab' => array(
        'description' => __( 'Configure the notifications sent to customers when their order status changes. Customize the content of each notification, associate different messages with different statuses, and control when and how update notifications are triggered.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/notifications/',
            'title' => 'Notifications'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/notifications/email',
            'title' => 'Create and Edit Emails'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/notifications/ultimate-wp-mail',
            'title' => 'Custom Email Templates'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/notifications/notifications',
            'title' => 'Enable Notifications'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/notifications/sms',
            'title' => 'SMS Notifications'
          ),
        )
      ),
      'ewd-otp-settings-ewd-otp-woocommerce-tab' => array(
        'description' => __( 'Configure the integration between Order Tracking and WooCommerce. Automatically create tracking orders whenever a WooCommerce purchase is completed, and map WooCommerce order data to the corresponding tracking fields for a seamless post-purchase experience.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/woocommerce/',
            'title' => 'WooCommerce Integration'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/woocommerce/settings',
            'title' => 'WooCommerce Integration Settings'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/woocommerce/faq',
            'title' => 'Troubleshooting WooCommerce FAQs'
          ),
        )
      ),
      'ewd-otp-settings-ewd-otp-payments-tab' => array(
        'description' => __( 'Enable and configure payment options for orders. Allow customers to pay directly through your site via PayPal when placing or viewing an order, reducing manual payment collection and streamlining your order management workflow.', 'order-tracking' ),
        'tutorials'   => array()
      ),
      'ewd-otp-settings-ewd-otp-styling-tab' => array(
        'description' => __( 'Customize the visual appearance of the front-end tracking form and order display.', 'order-tracking' ),
        'tutorials'   => array()
      ),
      'ewd-otp-settings-ewd-otp-labelling-tab' => array(
        'description' => __( 'Rename the default field labels used throughout the plugin to match your business terminology.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/labelling/',
            'title' => 'Labelling and Translation'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/labelling/translating',
            'title' => 'Translating'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/labelling/poedit',
            'title' => 'Translating with Poedit'
          ),
        )
      ),
      'ewd-otp-settings-ewd-otp-zendesk-tab' => array(
        'description' => __( 'Configure the integration between Order Tracking and Zendesk. Link your Zendesk account to connect order management with your support ticket workflow, giving your team better context when handling customer inquiries related to specific orders.', 'order-tracking' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/order-tracking/user/settings/zendesk',
            'title' => 'Linking Your Zendesk Account'
          ),
        )
      ),
    );

    $page = $page ? sanitize_text_field( $page ) : '';
    $tab = $tab ? sanitize_text_field( $tab ) : '';
    $taxonomy = $taxonomy ? sanitize_text_field( $taxonomy ) : '';
    $post_type = $post_type ? sanitize_text_field( $post_type ) : '';

    if ( $page && $tab && isset( $page_details[ $page . '-' . $tab ] ) ) { return $page_details[ $page . '-' . $tab ]; }

    if ( $page && isset( $page_details[ $page ] ) ) { return $page_details[ $page ]; }

    if ( $taxonomy && isset( $page_details[ $taxonomy ] ) ) { return $page_details[ $taxonomy ]; }

    if ( $post_type && isset( $page_details[ $post_type ] ) ) { return $page_details[ $post_type ]; }

    return array( 'description' => '', 'tutorials' => array() );
  }
}

add_action( 'plugins_loaded', array( 'ewdotpHelper', 'aiaa_add_filter' ), 20 );

}