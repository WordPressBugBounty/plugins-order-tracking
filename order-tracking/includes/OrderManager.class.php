<?php
/**
 * Class to handle all order database interactions for the Order Tracking plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpOrderManager' ) ) {
	class ewdotpOrderManager {

		// The name of the orders table, set in the constructor
		public $orders_table_name;

		// The name of the orders statuses table, set in the constructor
		public $order_statuses_table_name;

		// The name of the meta table, set in the constructor
		public $meta_table_name;

		// Array containing the arguments for the query
		public $args = array();

		// Array containing retrieved order objects
		public $orders = array();

		/**
		 * Last persistence error.
		 *
		 * @var string
		 */
		public $last_error = '';
		public function __construct() {
			global $wpdb;

			$this->orders_table_name         = $wpdb->prefix . 'EWD_OTP_Orders';
			$this->order_statuses_table_name = $wpdb->prefix . 'EWD_OTP_Order_Statuses';
			$this->meta_table_name           = $wpdb->prefix . 'EWD_OTP_Fields_Meta';

			if ( get_transient( 'ewd-otp-update-tables' ) ) {

				add_action( 'plugins_loaded', array( $this, 'create_tables' ) );
			}
		}

		/**
		 * Creates the tables used to store orders and their meta information
		 *
		 * @since 3.0.0
		 */
		public function create_tables() {

			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			$sql             = "CREATE TABLE $this->orders_table_name (
  			Order_ID mediumint(9) NOT NULL AUTO_INCREMENT,
  			Order_Name text DEFAULT '' NOT NULL,
			Order_Number text DEFAULT '' NOT NULL,
			Order_Number_Unique_Hash char(64) DEFAULT NULL,
			Order_Status text DEFAULT '' NOT NULL,
			Order_External_Status text DEFAULT '' NOT NULL,
			Order_Location text DEFAULT '' NOT NULL,
			Order_Notes_Public text DEFAULT '' NOT NULL,
			Order_Notes_Private text DEFAULT '' NOT NULL,
			Order_Customer_Notes text DEFAULT '' NOT NULL,
			Order_Email text DEFAULT '' NOT NULL,
			Order_Phone_Number text DEFAULT '' NOT NULL,
			Sales_Rep_ID mediumint(9) DEFAULT 0 NOT NULL,
			Customer_ID mediumint(9) DEFAULT 0 NOT NULL,
			WooCommerce_ID mediumint(9) DEFAULT 0 NOT NULL,
			Zendesk_ID mediumint(9) DEFAULT 0 NOT NULL,
			Zendesk_Unique_Hash char(64) DEFAULT NULL,
			Zendesk_Event_Timestamp bigint(20) unsigned DEFAULT 0 NOT NULL,
			Order_Status_Updated datetime DEFAULT '0000-00-00 00:00:00' NULL,
			Order_Status_Updated_GMT datetime DEFAULT NULL,
			Order_Display text DEFAULT '' NOT NULL,
			Order_Payment_Price text DEFAULT '' NOT NULL,
			Order_Payment_Completed text DEFAULT '' NOT NULL,
			Order_PayPal_Receipt_Number text DEFAULT '' NOT NULL,
			Order_PayPal_Receipt_Hash char(64) DEFAULT NULL,
			Order_View_Count mediumint(9) DEFAULT 0 NOT NULL,
			Order_Tracking_Link_Clicked text DEFAULT '' NOT NULL,
			Order_Tracking_Link_Code text DEFAULT '' NOT NULL,
			PRIMARY KEY  (Order_ID),
			KEY order_number (Order_Number(191)),
			UNIQUE KEY order_number_unique_hash (Order_Number_Unique_Hash),
			KEY customer_id (Customer_ID),
			KEY sales_rep_id (Sales_Rep_ID),
			KEY woocommerce_id (WooCommerce_ID),
			KEY zendesk_id (Zendesk_ID),
			UNIQUE KEY zendesk_unique_hash (Zendesk_Unique_Hash),
			KEY status_updated_gmt (Order_Status_Updated_GMT),
			KEY paypal_receipt (Order_PayPal_Receipt_Number(191)),
			UNIQUE KEY paypal_receipt_unique_hash (Order_PayPal_Receipt_Hash)
    		)
			$charset_collate;";

			dbDelta( $sql );

			$sql = "CREATE TABLE $this->order_statuses_table_name (
  			Order_Status_ID mediumint(9) NOT NULL AUTO_INCREMENT,
			Order_ID mediumint(9) DEFAULT 0 NOT NULL,
			Order_Status text DEFAULT '' NOT NULL,
			Order_Location text DEFAULT '' NOT NULL,
			Order_Internal_Status text DEFAULT '' NOT NULL,
			Order_Status_Created datetime DEFAULT '0000-00-00 00:00:00' NULL,
			Order_Status_Created_GMT datetime DEFAULT NULL,
			PRIMARY KEY  (Order_Status_ID),
			KEY order_created (Order_ID, Order_Status_Created),
			KEY order_created_gmt (Order_ID, Order_Status_Created_GMT)
    		)
			$charset_collate;";

			dbDelta( $sql );

			$sql = "CREATE TABLE $this->meta_table_name (
  			Meta_ID mediumint(9) NOT NULL AUTO_INCREMENT,
  			Field_ID mediumint(9) DEFAULT '0',
			Order_ID mediumint(9) DEFAULT '0',
			Customer_ID mediumint(9) DEFAULT '0',
			Sales_Rep_ID mediumint(9) DEFAULT '0',
			Meta_Value text DEFAULT '' NOT NULL,
			PRIMARY KEY  (Meta_ID),
			KEY order_field (Order_ID, Field_ID),
			KEY customer_field (Customer_ID, Field_ID),
			KEY sales_rep_field (Sales_Rep_ID, Field_ID)
    		)
			$charset_collate;";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			dbDelta( $sql );
		}

		/**
		 * Returns a single order given its order ID
		 *
		 * @since 3.0.0
		 */
		public function get_order_from_id( $order_id ) {
			global $wpdb;

			$db_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->orders_table_name WHERE Order_ID=%d", $order_id ) );

			return $db_order;
		}

		/**
		 * Returns a single order given its Zendesk ID
		 *
		 * @since 3.0.0
		 */
		public function get_order_from_zendesk_id( $zendesk_id ) {
			global $wpdb;

			$db_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->orders_table_name WHERE Zendesk_ID=%d", $zendesk_id ) );

			return ! empty( $db_order ) ? $db_order : null;
		}

		/**
		 * Returns a single order given its WooCommerce ID
		 *
		 * @since 3.0.0
		 */
		public function get_order_from_woocommerce_id( $post_id ) {

			global $wpdb;

			$db_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->orders_table_name WHERE WooCommerce_ID=%d", $post_id ) );

			return ! empty( $db_order ) ? $db_order : null;
		}

		/**
		 * Returns a single order given its order number
		 *
		 * @since 3.0.0
		 */
		public function get_order_from_tracking_number( $order_number ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted plugin-owned table identifiers cannot use value placeholders; values remain prepared.
			$db_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->orders_table_name WHERE Order_Number=%s ORDER BY Order_ID ASC LIMIT 1", $order_number ) );
			return ! empty( $db_order ) ? $db_order : null;
		}

		/**
		 * Returns orders matching the arguments supplied
		 *
		 * @since 3.0.0
		 */
		public function get_matching_orders( $args ) {

			$this->orders = array();

			$defaults = array(
				'orders_per_page' => 20,
				'order'           => 'ASC',
				'paged'           => 1,
				'date_range'      => '',
			);

			$this->args = wp_parse_args( $args, $defaults );

			$this->prepare_args();

			$this->run_query();

			return $this->orders;
		}

		/**
		 * Return the counts for orders being displayed on the admin orders page
		 *
		 * @since 3.0.0
		 */
		public function get_order_counts( $args ) {
			global $wpdb;
			global $ewd_otp_controller;

			$this->args = $args;

			$this->prepare_args();

			$args = $this->args;

			$query_string = "SELECT Order_Status, count( * ) AS num_orders
			FROM $this->orders_table_name
			WHERE 1=%d
		";

			$query_args = array( 1 );

			if ( ! empty( $args['number'] ) ) {

				$query_string .= ' AND Order_Number=%s';
				$query_args[]  = sanitize_text_field( $args['number'] );
			}

			if ( ! empty( $args['display'] ) ) {

				$query_string .= ' AND Order_Display=%s';
				$query_args[]  = 'Yes';
			}

			if ( ! empty( $args['after'] ) ) {

				$query_string .= ' AND Order_Status_Updated>=%s';
				$query_args[]  = $args['after'];
			}

			if ( ! empty( $args['before'] ) ) {

				$query_string .= ' AND Order_Status_Updated<=%s';
				$query_args[]  = $args['before'];
			}

			if ( ! empty( $args['date'] ) ) {

				$query_string .= ' AND DATE(Order_Status_Updated)=%s';
				$query_args[]  = $args['date'];
			}

			$query_string .= ' GROUP BY Order_Status';

			$count_results = $wpdb->get_results( $wpdb->prepare( $query_string, $query_args ) );

			$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

			$counts = array();

			foreach ( $statuses as $status ) {

				$counts[ sanitize_title( $status->status, '', 'ewd_otp' ) ] = 0;
			}

			foreach ( $count_results as $count ) {

				$counts[ sanitize_title( $count->Order_Status, '', 'ewd_otp' ) ] = $count->num_orders;
			}

			$counts['total'] = array_sum( $counts );

			return $counts;
		}

		/**
		 * Count the bounded filter set exposed by the authenticated v2 API.
		 *
		 * @param array $args Validated query arguments.
		 * @return int
		 * @since 3.6.0
		 */
		public function count_matching_orders( $args ) {

			global $wpdb;

			$query  = "SELECT COUNT(*) FROM $this->orders_table_name WHERE 1=1";
			$values = array();
			foreach ( array(
				'status'   => 'Order_Status',
				'location' => 'Order_Location',
			) as $key => $column ) {
				if ( empty( $args[ $key ] ) ) {
					continue;
				}
				$query   .= " AND $column=%s";
				$values[] = $args[ $key ];
			}
			foreach ( array(
				'email_search' => 'Order_Email',
				'phone_search' => 'Order_Phone_Number',
			) as $key => $column ) {
				if ( empty( $args[ $key ] ) ) {
						continue;
				}
				$query   .= " AND $column LIKE %s";
				$values[] = '%' . $wpdb->esc_like( $args[ $key ] ) . '%';
			}
			if ( ! empty( $args['after'] ) ) {
				$query   .= ' AND ((Order_Status_Updated_GMT IS NOT NULL AND Order_Status_Updated_GMT>=%s) OR (Order_Status_Updated_GMT IS NULL AND Order_Status_Updated>=%s))';
				$values[] = get_gmt_from_date( $args['after'] );
				$values[] = $args['after'];
			}
			if ( ! empty( $args['before'] ) ) {
				$query   .= ' AND ((Order_Status_Updated_GMT IS NOT NULL AND Order_Status_Updated_GMT<=%s) OR (Order_Status_Updated_GMT IS NULL AND Order_Status_Updated<=%s))';
				$values[] = get_gmt_from_date( $args['before'] );
				$values[] = $args['before'];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Trusted plugin-owned table identifiers cannot use value placeholders; values remain prepared.
			return absint( $values ? $wpdb->get_var( $wpdb->prepare( $query, $values ) ) : $wpdb->get_var( $query ) );
		}

		/**
		 * Prepares the arguments before the query is run
		 *
		 * @since 3.0.0
		 */
		public function prepare_args() {

			$args = $this->args;

			if ( is_string( $args['date_range'] ) ) {

				if ( ! empty( $args['start_date'] ) || ! empty( $args['end_date'] ) ) {

					if ( ! empty( $args['start_date'] ) ) {
						$args['after'] = sanitize_text_field( $args['start_date'] ) . ( ( isset( $args['start_time'] ) and $args['start_time'] ) ? $args['start_time'] : '' );
					}

					if ( ! empty( $args['end_date'] ) ) {
						$args['before'] = sanitize_text_field( $args['end_date'] ) . ( ( isset( $args['end_time'] ) and $args['end_time'] ) ? $args['end_time'] : ' 23:59' );
					}
				} elseif ( $args['date_range'] === 'today' ) {

					$today          = current_datetime()->setTime( 0, 0, 0 );
					$args['after']  = $today->format( 'Y-m-d H:i:s' );
					$args['before'] = $today->modify( '+1 day' )->format( 'Y-m-d H:i:s' );

				} elseif ( $args['date_range'] === 'week' ) {

					$week           = current_datetime()->modify( 'monday this week' )->setTime( 0, 0, 0 );
					$args['after']  = $week->format( 'Y-m-d H:i:s' );
					$args['before'] = $week->modify( '+7 days' )->format( 'Y-m-d H:i:s' );
				} elseif ( $args['date_range'] === 'past' ) {

					$args['before'] = current_datetime()->format( 'Y-m-d H:i:s' );
				}
			}

			$this->args = $args;

			return $this->args;
		}

		/**
		 * Create and run the SQL query based on the arguments received
		 *
		 * @since 3.0.0
		 */
		public function run_query() {
			global $wpdb;

			$args = $this->args;

			$query_string = "SELECT * FROM $this->orders_table_name WHERE 1=%d";

			$query_args = array( 1 );

			if ( ! empty( $args['id'] ) ) {

				$query_string .= ' AND Order_ID=%d';
				$query_args[]  = intval( $args['id'] );
			}

			if ( ! empty( $args['name'] ) ) {

				$query_string .= ' AND Order_Name=%s';
				$query_args[]  = $args['name'];
			}

			if ( ! empty( $args['number'] ) ) {

				$query_string .= ' AND Order_Number=%s';
				$query_args[]  = $args['number'];
			}

			if ( ! empty( $args['location'] ) ) {

				$query_string .= ' AND Order_Location=%s';
				$query_args[]  = $args['location'];
			}

			if ( ! empty( $args['status'] ) ) {

				if ( is_array( $args['status'] ) ) {
					$status_placeholder = implode( ', ', array_fill( 0, count( $args['status'] ), '%s' ) );
					$query_string      .= " AND Order_Status IN ( $status_placeholder )";
					$query_args         = array_merge( $query_args, $args['status'] );
				} else {
					$query_string .= ' AND Order_Status=%s';
					$query_args[]  = $args['status'];
				}
			}

			if ( ! empty( $args['location'] ) ) {

				if ( is_array( $args['location'] ) ) {
					$location_placeholder = implode( ', ', array_fill( 0, count( $args['location'] ), '%s' ) );
					$query_string        .= " AND Order_Location IN ( $location_placeholder )";
					$query_args           = array_merge( $query_args, $args['location'] );
				} else {
					$query_string .= ' AND Order_Location=%s';
					$query_args[]  = $args['location'];
				}
			}

			if ( ! empty( $args['email'] ) ) {

				$query_string .= ' AND Order_Email=%s';
				$query_args[]  = $args['email'];
			}

			if ( ! empty( $args['email_search'] ) ) {

				$query_string .= ' AND Order_Email LIKE %s';
				$query_args[]  = '%' . $wpdb->esc_like( $args['email_search'] ) . '%';
			}

			if ( ! empty( $args['phone_search'] ) ) {

				$query_string .= ' AND Order_Phone_Number LIKE %s';
				$query_args[]  = '%' . $wpdb->esc_like( $args['phone_search'] ) . '%';
			}
			if ( ! empty( $args['customer'] ) ) {

				if ( is_array( $args['customer'] ) ) {
					$cstm_plchldr  = implode( ', ', array_fill( 0, count( $args['customer'] ), '%d' ) );
					$query_string .= " AND Customer_ID IN ( $cstm_plchldr )";
					$query_args    = array_merge( $query_args, $args['customer'] );
				} else {
					$query_string .= ' AND Customer_ID=%d';
					$query_args[]  = intval( $args['customer'] );
				}
			}

			if ( ! empty( $args['sales_rep'] ) ) {

				if ( is_array( $args['sales_rep'] ) ) {
					$sls_rp_plchldr = implode( ', ', array_fill( 0, count( $args['sales_rep'] ), '%d' ) );
					$query_string  .= " AND Sales_Rep_ID IN ( $sls_rp_plchldr )";
					$query_args     = array_merge( $query_args, $args['sales_rep'] );
				} else {
					$query_string .= ' AND Sales_Rep_ID=%d';
					$query_args[]  = intval( $args['sales_rep'] );
				}
			}

			if ( ! empty( $args['display'] ) ) {

				if ( strtolower( $args['display'] ) == 'no' ) {

					$query_string .= ' AND Order_Display=%s';
					$query_args[]  = 'No';
				} else {

					$query_string .= ' AND Order_Display=%s';
					$query_args[]  = 'Yes';
				}
			}

			if ( ! empty( $args['payment_completed'] ) ) {

				$query_string .= ' AND Order_Payment_Completed=%s';
				$query_args[]  = $args['payment_completed'];
			}

			if ( ! empty( $args['after'] ) ) {

				$query_string .= ' AND ((Order_Status_Updated_GMT IS NOT NULL AND Order_Status_Updated_GMT>=%s) OR (Order_Status_Updated_GMT IS NULL AND Order_Status_Updated>=%s))';
				$query_args[]  = get_gmt_from_date( $args['after'] );
				$query_args[]  = $args['after'];
			}
			if ( ! empty( $args['before'] ) ) {

				$query_string .= ' AND ((Order_Status_Updated_GMT IS NOT NULL AND Order_Status_Updated_GMT<=%s) OR (Order_Status_Updated_GMT IS NULL AND Order_Status_Updated<=%s))';
				$query_args[]  = get_gmt_from_date( $args['before'] );
				$query_args[]  = $args['before'];
			}

			if ( ! empty( $args['date'] ) ) {

				$day_start     = sanitize_text_field( $args['date'] ) . ' 00:00:00';
				$day_end       = sanitize_text_field( $args['date'] ) . ' 23:59:59';
				$query_string .= ' AND ((Order_Status_Updated_GMT IS NOT NULL AND Order_Status_Updated_GMT BETWEEN %s AND %s) OR (Order_Status_Updated_GMT IS NULL AND Order_Status_Updated BETWEEN %s AND %s))';
				$query_args[]  = get_gmt_from_date( $day_start );
				$query_args[]  = get_gmt_from_date( $day_end );
				$query_args[]  = $day_start;
				$query_args[]  = $day_end;
			}

			if ( ! empty( $args['orderby'] ) ) {

				$orderby_map = array(
					'date'         => 'COALESCE(Order_Status_Updated_GMT, Order_Status_Updated)',
					'Order_ID'     => 'Order_ID',
					'Order_Number' => 'Order_Number',
					'Order_Name'   => 'Order_Name',
					'Order_Status' => 'Order_Status',
				);
				$orderby     = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'Order_ID';

				$query_string .= ' ORDER BY ' . $orderby . ' ' . ( strtolower( $args['order'] ) === 'desc' ? 'DESC' : 'ASC' ) . ', Order_ID ASC';
			}

			if ( $args['orders_per_page'] > 0 ) {

				$query_string .= ' LIMIT ' . intval( ( $args['paged'] - 1 ) * $args['orders_per_page'] ) . ', ' . intval( $args['orders_per_page'] );
			}

			$db_orders = $wpdb->get_results( $wpdb->prepare( $query_string, $query_args ) );

			foreach ( $db_orders as $db_order ) {

				$order = new ewdotpOrder();

				$order->load_order( $db_order );

				$this->orders[] = $order;
			}
		}

		/**
		 * Returns the value for a given field/order id pair
		 *
		 * @since 3.0.0
		 */
		public function get_order_status_history( $order_id ) {

			global $wpdb;

			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $this->order_statuses_table_name WHERE Order_ID=%d ORDER BY Order_Status_Created", $order_id ) );
		}

		/**
		 * Find the order already associated with a PayPal transaction.
		 *
		 * @param string $receipt_number PayPal transaction identifier.
		 * @return int
		 * @since 3.6.0
		 */
		public function get_order_id_from_paypal_receipt( $receipt_number ) {

			global $wpdb;

			if ( '' === (string) $receipt_number ) {
				return 0;
			}

			return absint(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
				$wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted plugin-owned table identifiers cannot use value placeholders; values remain prepared.
						"SELECT Order_ID FROM $this->orders_table_name WHERE Order_PayPal_Receipt_Number=%s ORDER BY Order_ID ASC LIMIT 1",
						$receipt_number
					)
				)
			);
		}

		/**
		 * Atomically claim a PayPal transaction and transition one unpaid order.
		 *
		 * @param int    $order_id       Order identifier.
		 * @param string $receipt_number PayPal transaction identifier.
		 * @return string `transitioned`, `duplicate`, or `rejected`.
		 */
		public function claim_paypal_payment( $order_id, $receipt_number ) {

			global $wpdb;

			$order_id       = absint( $order_id );
			$receipt_number = trim( sanitize_text_field( $receipt_number ) );
			if ( ! $order_id || '' === $receipt_number ) {
				return 'rejected';
			}
			$receipt_hash = self::paypal_receipt_hash( $receipt_number );

			$sql = "UPDATE {$this->orders_table_name} AS target
			LEFT JOIN {$this->orders_table_name} AS owner
				ON owner.Order_PayPal_Receipt_Hash=%s AND owner.Order_ID<>target.Order_ID
			SET target.Order_Payment_Completed='Yes',
				target.Order_PayPal_Receipt_Number=%s,
				target.Order_PayPal_Receipt_Hash=%s
			WHERE target.Order_ID=%d
				AND target.Order_Payment_Completed<>'Yes'
				AND owner.Order_ID IS NULL";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Trusted plugin-owned table identifiers cannot use value placeholders; values remain prepared.
			$result = $wpdb->query( $wpdb->prepare( $sql, $receipt_hash, $receipt_number, $receipt_hash, $order_id ) );
			if ( 1 === $result ) {
				return 'transitioned';
			}

			$stored = $this->get_order_from_id( $order_id );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			if ( $stored && 'Yes' === $stored->Order_Payment_Completed
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			&& hash_equals( (string) $stored->Order_PayPal_Receipt_Number, $receipt_number ) ) {
				return 'duplicate';
			}

			return 'rejected';
		}

		/**
		 * Hash a PayPal transaction identifier for uniqueness enforcement.
		 *
		 * @param string $receipt_number PayPal transaction identifier.
		 * @return string|null
		 */
		public static function paypal_receipt_hash( $receipt_number ) {

				$receipt_number = trim( (string) $receipt_number );
				return '' === $receipt_number ? null : hash( 'sha256', 'paypal:' . $receipt_number );
		}

		/**
		 * Hash a Zendesk ticket identifier for uniqueness enforcement.
		 *
		 * @param int $zendesk_id Zendesk ticket identifier.
		 * @return string|null
		 */
		public static function zendesk_id_hash( $zendesk_id ) {

				$zendesk_id = absint( $zendesk_id );
				return $zendesk_id ? hash( 'sha256', 'zendesk:' . $zendesk_id ) : null;
		}

		/**
		 * Return one status-history row for ownership validation.
		 *
		 * @param int $order_status_id Status-history identifier.
		 * @return object|null
		 * @since 3.6.0
		 */
		public function get_order_status( $order_status_id ) {

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			return $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted plugin-owned table identifiers cannot use value placeholders; values remain prepared.
				$wpdb->prepare( "SELECT * FROM $this->order_statuses_table_name WHERE Order_Status_ID=%d", absint( $order_status_id ) )
			);
		}
		/**
		 * Returns the value for a given field/order id pair
		 *
		 * @since 3.0.0
		 */
		public function get_order_field( $field, $order_id ) {
			global $wpdb;

			$db_order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->orders_table_name WHERE Order_ID=%d", $order_id ) );

			$order = new ewdotpOrder();
			$order->load_order( $db_order );

			return ! empty( $order->$field ) ? $order->$field : '';
		}

		/**
		 * Returns the value for a given custom_field/order pair
		 *
		 * @since 3.0.0
		 */
		public function get_field_value( $custom_field_id, $order_id ) {
			global $wpdb;

			return $wpdb->get_var( $wpdb->prepare( "SELECT Meta_Value FROM $this->meta_table_name WHERE Field_ID=%d AND Order_ID=%d ORDER BY Meta_ID DESC", $custom_field_id, $order_id ) );
		}

		/**
		 * Accepts an order object, inserts it into the database, and returns the ID of the newly inserted order
		 *
		 * @since 3.0.0
		 */
		public function insert_order( $order ) {

			global $wpdb;
			global $ewd_otp_controller;

			$this->last_error = '';
			if ( ! $ewd_otp_controller->database_migration->is_ready() ) {
				$this->last_error = __( 'The Order Tracking database upgrade must finish before orders can be saved.', 'order-tracking' );
				return false;
			}

			$order->number = trim( sanitize_text_field( $order->number ) );
			if ( '' === $order->number ) {
				$this->last_error = __( 'Order Number is required.', 'order-tracking' );
				return false;
			}
			if ( $this->get_order_from_tracking_number( $order->number ) ) {
				$this->last_error = __( 'That Order Number is already in use.', 'order-tracking' );
				return false;
			}

			$order->number_unique_hash = ewdotpDatabaseMigration::order_number_hash( $order->number );
			$order->status_updated     = current_time( 'mysql' );
			$order->status_updated_gmt = current_time( 'mysql', true );

			$query_args = array(
				'Order_Name'                  => ! empty( $order->name ) ? $order->name : '',
				'Order_Number'                => ! empty( $order->number ) ? $order->number : '',
				'Order_Number_Unique_Hash'    => $order->number_unique_hash,
				'Order_Status'                => ! empty( $order->status ) ? $order->status : '',
				'Order_External_Status'       => ! empty( $order->external_status ) ? $order->external_status : '',
				'Order_Location'              => ! empty( $order->location ) ? $order->location : '',
				'Order_Notes_Public'          => ! empty( $order->notes_public ) ? $order->notes_public : '',
				'Order_Notes_Private'         => ! empty( $order->notes_private ) ? $order->notes_private : '',
				'Order_Customer_Notes'        => ! empty( $order->customer_notes ) ? $order->customer_notes : '',
				'Order_Email'                 => ! empty( $order->email ) ? $order->email : '',
				'Order_Phone_Number'          => ! empty( $order->phone_number ) ? $order->phone_number : '',
				'Customer_ID'                 => ! empty( $order->customer ) ? $order->customer : 0,
				'Sales_Rep_ID'                => ! empty( $order->sales_rep ) ? $order->sales_rep : 0,
				'WooCommerce_ID'              => ! empty( $order->woocommerce_id ) ? $order->woocommerce_id : 0,
				'Zendesk_ID'                  => ! empty( $order->zendesk_id ) ? $order->zendesk_id : 0,
				'Zendesk_Unique_Hash'         => self::zendesk_id_hash( $order->zendesk_id ),
				'Zendesk_Event_Timestamp'     => absint( $order->zendesk_event_timestamp ),
				'Order_Status_Updated'        => $order->status_updated,
				'Order_Status_Updated_GMT'    => $order->status_updated_gmt,
				'Order_Display'               => ! empty( $order->display ) ? 'Yes' : 'No',
				'Order_Payment_Price'         => ! empty( $order->payment_price ) ? $order->payment_price : '',
				'Order_Payment_Completed'     => ! empty( $order->payment_completed ) ? 'Yes' : 'No',
				'Order_PayPal_Receipt_Number' => ! empty( $order->paypal_receipt_number ) ? $order->paypal_receipt_number : '',
				'Order_PayPal_Receipt_Hash'   => self::paypal_receipt_hash( $order->paypal_receipt_number ),
				'Order_View_Count'            => ! empty( $order->views ) ? $order->views : 0,
				'Order_Tracking_Link_Clicked' => ! empty( $order->tracking_link_clicked ) ? 'Yes' : 'No',
				'Order_Tracking_Link_Code'    => ! empty( $order->tracking_link_code ) ? $order->tracking_link_code : '',
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			$inserted = $wpdb->insert(
				$this->orders_table_name,
				$query_args
			);

			if ( false === $inserted ) {
				$this->last_error = __( 'The order could not be saved. Its Order Number may already be in use.', 'order-tracking' );
				return false;
			}

			$order_id = $wpdb->insert_id;

			if ( ! $order_id ) {
				return $order_id; }

			$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();

			foreach ( $custom_fields as $custom_field ) {

				if ( empty( $order->custom_fields[ $custom_field->id ] ) ) {
					continue; }

				$query_args = array(
					'Field_ID'   => $custom_field->id,
					'Order_ID'   => $order_id,
					'Meta_Value' => $order->custom_fields[ $custom_field->id ],
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
				$meta_result = $wpdb->insert(
					$this->meta_table_name,
					$query_args
				);
				if ( false === $meta_result ) {
					$this->last_error = __( 'The order custom fields could not be saved.', 'order-tracking' );
					return false;
				}
			}
			return $order_id;
		}

		/**
		 * Accepts an order object, updates it in the database, and returns the ID if successful or false otherwise
		 *
		 * @since 3.0.0
		 */
		public function update_order( $order ) {

			global $wpdb;
			global $ewd_otp_controller;

			$this->last_error = '';
			if ( empty( $order->id ) || ! $ewd_otp_controller->database_migration->is_ready() ) {
				$this->last_error = __( 'The Order Tracking database upgrade must finish before orders can be saved.', 'order-tracking' );
				return false;
			}

			$stored_order = $this->get_order_from_id( $order->id );
			if ( empty( $stored_order ) ) {
				return false;
			}

			$order->number = trim( sanitize_text_field( $order->number ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			$number_changed = (string) $stored_order->Order_Number !== (string) $order->number;
			if ( $number_changed ) {
				if ( '' === $order->number ) {
					$this->last_error = __( 'Order Number is required.', 'order-tracking' );
					return false;
				}
				$matching_order = $this->get_order_from_tracking_number( $order->number );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
				if ( $matching_order && absint( $matching_order->Order_ID ) !== absint( $order->id ) ) {
					$this->last_error = __( 'That Order Number is already in use.', 'order-tracking' );
					return false;
				}
				$order->number_unique_hash = ewdotpDatabaseMigration::order_number_hash( $order->number );
			} else {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
				$order->number_unique_hash = isset( $stored_order->Order_Number_Unique_Hash ) ? $stored_order->Order_Number_Unique_Hash : null;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
			if ( (string) $stored_order->Order_Status !== (string) $order->status || (string) $stored_order->Order_Location !== (string) $order->location ) {
				$order->status_updated     = current_time( 'mysql' );
				$order->status_updated_gmt = current_time( 'mysql', true );
			}

			$query_args = array(
				'Order_Name'                  => ! empty( $order->name ) ? $order->name : '',
				'Order_Number'                => ! empty( $order->number ) ? $order->number : '',
				'Order_Number_Unique_Hash'    => $order->number_unique_hash,
				'Order_Status'                => ! empty( $order->status ) ? $order->status : '',
				'Order_External_Status'       => ! empty( $order->external_status ) ? $order->external_status : '',
				'Order_Location'              => ! empty( $order->location ) ? $order->location : '',
				'Order_Notes_Public'          => ! empty( $order->notes_public ) ? $order->notes_public : '',
				'Order_Notes_Private'         => ! empty( $order->notes_private ) ? $order->notes_private : '',
				'Order_Customer_Notes'        => ! empty( $order->customer_notes ) ? $order->customer_notes : '',
				'Order_Email'                 => ! empty( $order->email ) ? $order->email : '',
				'Order_Phone_Number'          => ! empty( $order->phone_number ) ? $order->phone_number : '',
				'Customer_ID'                 => ! empty( $order->customer ) ? $order->customer : 0,
				'Sales_Rep_ID'                => ! empty( $order->sales_rep ) ? $order->sales_rep : 0,
				'WooCommerce_ID'              => ! empty( $order->woocommerce_id ) ? $order->woocommerce_id : 0,
				'Zendesk_ID'                  => ! empty( $order->zendesk_id ) ? $order->zendesk_id : 0,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
				'Zendesk_Unique_Hash'         => absint( $order->zendesk_id ) === (int) $stored_order->Zendesk_ID ? $stored_order->Zendesk_Unique_Hash : self::zendesk_id_hash( $order->zendesk_id ),
				'Zendesk_Event_Timestamp'     => absint( $order->zendesk_event_timestamp ),
				'Order_Status_Updated'        => ! empty( $order->status_updated ) ? $order->status_updated : current_time( 'mysql' ),
				'Order_Status_Updated_GMT'    => ! empty( $order->status_updated_gmt ) ? $order->status_updated_gmt : null,
				'Order_Display'               => ! empty( $order->display ) ? 'Yes' : 'No',
				'Order_Payment_Price'         => ! empty( $order->payment_price ) ? $order->payment_price : '',
				'Order_Payment_Completed'     => ! empty( $order->payment_completed ) ? 'Yes' : 'No',
				'Order_PayPal_Receipt_Number' => ! empty( $order->paypal_receipt_number ) ? $order->paypal_receipt_number : '',
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names mirror fixed legacy database columns returned by wpdb.
				'Order_PayPal_Receipt_Hash'   => (string) $stored_order->Order_PayPal_Receipt_Number === (string) $order->paypal_receipt_number ? $stored_order->Order_PayPal_Receipt_Hash : self::paypal_receipt_hash( $order->paypal_receipt_number ),
				'Order_View_Count'            => ! empty( $order->views ) ? $order->views : 0,
				'Order_Tracking_Link_Clicked' => ! empty( $order->tracking_link_clicked ) ? 'Yes' : 'No',
				'Order_Tracking_Link_Code'    => ! empty( $order->tracking_link_code ) ? $order->tracking_link_code : '',
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			$db_result = $wpdb->update(
				$this->orders_table_name,
				$query_args,
				array( 'Order_ID' => $order->id )
			);

			if ( false === $db_result ) {
				$this->last_error = __( 'The order could not be saved. Its Order Number may already be in use.', 'order-tracking' );
				return false;
			}

			$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();
			foreach ( $custom_fields as $custom_field ) {

				$wpdb->get_var( $wpdb->prepare( "SELECT Meta_Value from $this->meta_table_name WHERE Field_ID=%d AND Order_ID=%d ORDER BY Meta_ID DESC", $custom_field->id, $order->id ) );

				$update = $wpdb->num_rows ? true : false;

				if ( empty( $order->custom_fields[ $custom_field->id ] ) ) {

					$where_args = array(
						'Field_ID' => $custom_field->id,
						'Order_ID' => $order->id,
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
					$meta_result = $wpdb->delete(
						$this->meta_table_name,
						$where_args
					);
				} elseif ( $update ) {

					$query_args = array(
						'Meta_Value' => $order->custom_fields[ $custom_field->id ],
					);

					$where_args = array(
						'Field_ID' => $custom_field->id,
						'Order_ID' => $order->id,
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
					$meta_result = $wpdb->update(
						$this->meta_table_name,
						$query_args,
						$where_args
					);
				} else {

					$query_args = array(
						'Meta_Value' => $order->custom_fields[ $custom_field->id ],
						'Field_ID'   => $custom_field->id,
						'Order_ID'   => $order->id,
					);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
					$meta_result = $wpdb->insert(
						$this->meta_table_name,
						$query_args
					);
				}
				if ( false === $meta_result ) {
						$this->last_error = __( 'The order custom fields could not be saved.', 'order-tracking' );
					return false;
				}
			}
			return $order->id;
		}

		/**
		 * Adds a new order status to the database
		 *
		 * @since 3.0.0
		 */
		public function update_order_status( $order ) {
			global $wpdb;

			$query_args = array(
				'Order_ID'                 => $order->id,
				'Order_Status'             => ! empty( $order->status ) ? $order->status : '',
				'Order_Location'           => ! empty( $order->location ) ? $order->location : '',
				'Order_Internal_Status'    => ! empty( $order->status ) ? $order->status : '',
				'Order_Status_Created'     => ! empty( $order->status_updated ) ? $order->status_updated : current_time( 'mysql' ),
				'Order_Status_Created_GMT' => ! empty( $order->status_updated_gmt ) ? $order->status_updated_gmt : current_time( 'mysql', true ),
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional access to plugin-owned tables intentionally bypasses the object cache.
			$result = $wpdb->insert(
				$this->order_statuses_table_name,
				$query_args
			);

			return false !== $result;
		}

		/**
		 * Removs an existing order status
		 *
		 * @since 3.0.1
		 */
		public function delete_order_status( $order_status_id ) {
			global $wpdb;

			$wpdb->delete(
				$this->order_statuses_table_name,
				array( 'Order_Status_ID' => $order_status_id )
			);
		}

		/**
		 * Accepts an order id, deletes the corresponding order
		 *
		 * @since 3.0.0
		 */
		public function delete_order( $order_id ) {
			global $wpdb;

			$wpdb->delete(
				$this->orders_table_name,
				array( 'Order_ID' => $order_id )
			);

			$wpdb->delete(
				$this->order_statuses_table_name,
				array( 'Order_ID' => $order_id )
			);

			$wpdb->delete(
				$this->meta_table_name,
				array( 'Order_ID' => $order_id )
			);
		}

		/**
		 * Accepts an order id, sets the payment_received field for the corresponding order to Yes
		 *
		 * @since 3.0.0
		 */
		public function set_order_paid( $order_id ) {
			global $wpdb;

			$query_args = array(
				'Order_Payment_Completed' => 'Yes',
			);

			$wpdb->update(
				$this->orders_table_name,
				$query_args,
				array( 'Order_ID' => $order_id )
			);
		}

		/**
		 * Accepts an order id, updates the status of the corresponding order
		 *
		 * @since 3.0.0
		 */
		public function set_order_status( $order_id, $status ) {
			global $wpdb;

			$order = new ewdotpOrder();
			$order->load_order_from_id( $order_id );

			$order->set_status( $status );
		}

		/**
		 * Increment the views for a given order_id
		 *
		 * @since 3.0.4
		 */
		public function increase_order_views( $order_id ) {
			global $wpdb;

			$wpdb->query( $wpdb->prepare( "UPDATE $this->orders_table_name SET Order_View_Count=Order_View_Count+1 WHERE Order_ID=%d", $order_id ) );
		}

		/**
		 * Accepts an email and order id, and verify that they match the saved data,
		 *
		 * @since 3.0.0
		 */
		public function verify_order_email( $email, $order_id ) {
			global $wpdb;

			$order_email = $wpdb->get_var( $wpdb->prepare( "SELECT Order_Email FROM $this->orders_table_name WHERE Order_ID=%d", $order_id ) );

			if ( $order_email == $email ) {

				return true;
			}

			return false;
		}
	}
}
