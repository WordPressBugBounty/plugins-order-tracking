<?php
/**
 * Coordinated, repeatable OTP 3.6.0 database migration.
 *
 * @package OrderTracking
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpDatabaseMigration' ) ) {
	/**
	 * Coordinates resumable schema upgrades and their completion markers.
	 */
	class EwdotpDatabaseMigration {

		const SCHEMA_VERSION = 3;
		const SCHEMA_OPTION  = 'ewd-otp-db-schema-version';
		const PHASE_OPTION   = 'ewd-otp-db-migration-phase';
		const TARGET_OPTION  = 'ewd-otp-db-migration-target';
		const LOCK_OPTION    = 'ewd-otp-db-migration-lock';
		const ERROR_OPTION   = 'ewd-otp-db-migration-error';
		/**
		 * Runtime lock owner state.
		 *
		 * @var mixed
		 */
		private $lock_owner = '';
		/**
		 * Runtime lock value state.
		 *
		 * @var mixed
		 */
		private $lock_value = array();
		/**
		 * Runtime database lock held state.
		 *
		 * @var mixed
		 */
		private $database_lock_held = false;

		/**
		 * Initialize the integration.
		 */
		public function __construct() {
			add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		}

		/**
		 * Handle the activate operation.
		 */
		public function activate() {
			$this->run();
		}

		/**
		 * Handle the maybe migrate operation.
		 */
		public function maybe_migrate() {
			global $ewd_otp_controller;

			if ( $this->is_complete() ) {
				return; }

			$capability = is_object( $ewd_otp_controller ) ? $ewd_otp_controller->settings->get_setting( 'access-role' ) : 'manage_options';
			if ( ! current_user_can( $capability ) ) {
				return; }

			$this->run();
		}

		/**
		 * Handle the is ready operation.
		 */
		public function is_ready() {
			return self::SCHEMA_VERSION <= absint( get_option( self::SCHEMA_OPTION, 0 ) );
		}

		/**
		 * Handle the is complete operation.
		 */
		private function is_complete() {
			return $this->is_ready()
				&& 6 === absint( get_option( self::PHASE_OPTION, 0 ) )
				&& self::SCHEMA_VERSION === absint( get_option( self::TARGET_OPTION, 0 ) );
		}

		/**
		 * Handle the run operation.
		 */
		public function run() {
			if ( ! $this->acquire_lock() ) {
				return false; }

			try {
				$schema = absint( get_option( self::SCHEMA_OPTION, 0 ) );
				$phase  = absint( get_option( self::PHASE_OPTION, 0 ) );
				$target = absint( get_option( self::TARGET_OPTION, 0 ) );

				if ( $schema > self::SCHEMA_VERSION ) {
					return true;
				}
				if ( self::SCHEMA_VERSION === $schema ) {
					if ( 6 === $phase && self::SCHEMA_VERSION === $target ) {
						return true;
					}
					$verification = $this->run_phase_4();
					if ( is_wp_error( $verification ) || false === $verification ) {
						$message = is_wp_error( $verification ) ? $verification->get_error_message() : __( 'The current database schema could not be verified.', 'order-tracking' );
						update_option( self::ERROR_OPTION, sanitize_text_field( $message ), false );
						return false;
					}
					if ( ! $this->write_option( self::TARGET_OPTION, self::SCHEMA_VERSION ) || ! $this->write_option( self::PHASE_OPTION, 6 ) ) {
						return false;
					}
					delete_option( self::ERROR_OPTION );
					return true;
				}

				if ( self::SCHEMA_VERSION !== $target || 6 <= $phase ) {
					$phase = 0;
					if ( ! $this->write_option( self::TARGET_OPTION, self::SCHEMA_VERSION ) || ! $this->write_option( self::PHASE_OPTION, 0 ) ) {
						return false;
					}
				}

				while ( $phase < 6 ) {
					$next_phase = $phase + 1;
					$method     = 'run_phase_' . $next_phase;
					$result     = $this->$method();

					if ( is_wp_error( $result ) || false === $result ) {
						$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'An unknown database migration error occurred.', 'order-tracking' );
						update_option( self::ERROR_OPTION, sanitize_text_field( $message ), false );
						return false;
					}

					if ( 6 === $next_phase && ! $this->write_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION ) ) {
						update_option( self::ERROR_OPTION, __( 'The database schema completion marker could not be saved.', 'order-tracking' ), false );
						return false;
					}

					$phase = $next_phase;
					if ( ! $this->write_option( self::PHASE_OPTION, $phase ) ) {
						update_option( self::ERROR_OPTION, __( 'The database migration checkpoint could not be saved.', 'order-tracking' ), false );
						return false;
					}
					if ( ! $this->heartbeat_lock() ) {
						update_option( self::ERROR_OPTION, __( 'The database migration lock was lost before completion.', 'order-tracking' ), false );
						return false;
					}
				}

				delete_option( self::ERROR_OPTION );
				return $this->is_complete();
			} finally {
				$this->release_lock();
			}
		}

		/** Phase 1: add columns and initial index scaffolding. */
		private function run_phase_1() {
			global $ewd_otp_controller;

			$ewd_otp_controller->customer_manager->create_tables();
			$ewd_otp_controller->order_manager->create_tables();
			$ewd_otp_controller->sales_rep_manager->create_tables();

			return true;
		}

		/** Phase 2: convert all plugin tables to the selected full-Unicode collation. */
		private function run_phase_2() {
			global $wpdb;

			if ( 0 !== strpos( strtolower( (string) $wpdb->charset ), 'utf8mb4' ) ) {
				return new WP_Error( 'ewd_otp_utf8mb4_unavailable', __( 'Order Tracking 3.6.0 requires the WordPress database connection to support utf8mb4 before migration can finish.', 'order-tracking' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $this->get_table_names() as $table ) {
				if ( ! $this->heartbeat_lock() ) {
					return new WP_Error( 'ewd_otp_lock_lost', __( 'The database migration lock was lost.', 'order-tracking' ) ); }
				if ( function_exists( 'maybe_convert_table_to_utf8mb4' ) ) {
					maybe_convert_table_to_utf8mb4( $table );
				}

				$safe_table = str_replace( '`', '``', $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection must query the plugin-owned table directly.
				$collation = $wpdb->get_var( "SHOW TABLE STATUS LIKE '" . esc_sql( $table ) . "'", 14 );
				if ( empty( $collation ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection is not cacheable.
					$collation = $wpdb->get_var( "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . esc_sql( $table ) . "'" );
				}
				if ( 0 !== strpos( strtolower( (string) $collation ), 'utf8mb4' ) ) {
					$charset    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->charset );
					$db_collate = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->collate );
					$sql        = 'ALTER TABLE `' . $safe_table . '` CONVERT TO CHARACTER SET ' . $charset;
					if ( '' !== $db_collate ) {
						$sql .= ' COLLATE ' . $db_collate;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are reduced to safe characters above; schema changes cannot use placeholders.
					if ( false === $wpdb->query( $sql ) ) {
						/* translators: %s: database table name. */
						return new WP_Error( 'ewd_otp_utf8mb4_conversion_failed', sprintf( __( 'The database table %s could not be converted to utf8mb4.', 'order-tracking' ), $safe_table ) );
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the schema change immediately.
					$collation = $wpdb->get_var( "SHOW TABLE STATUS LIKE '" . esc_sql( $table ) . "'", 14 );
				}

				if ( 0 !== strpos( strtolower( (string) $collation ), 'utf8mb4' ) ) {
					/* translators: %s: database table name. */
					return new WP_Error( 'ewd_otp_utf8mb4_conversion_failed', sprintf( __( 'The database table %s could not be verified as utf8mb4.', 'order-tracking' ), $safe_table ) );
				}
			}

			return true;
		}

		/** Phase 3: assign uniqueness only to the canonical lowest-ID legacy row. */
		private function run_phase_3() {
			global $wpdb, $ewd_otp_controller;

			$table = $ewd_otp_controller->order_manager->orders_table_name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is plugin-owned and every row must be migrated.
			$rows = $wpdb->get_results( "SELECT Order_ID, Order_Number, Order_PayPal_Receipt_Number, Zendesk_ID FROM $table ORDER BY Order_ID ASC" );
			if ( null === $rows ) {
				return new WP_Error( 'ewd_otp_identity_read_failed', __( 'Order number migration could not read existing orders.', 'order-tracking' ) ); }

			$seen         = array();
			$paypal_seen  = array();
			$zendesk_seen = array();
			foreach ( $rows as $row_index => $row ) {
				if ( 0 === $row_index % 100 && ! $this->heartbeat_lock() ) {
					return new WP_Error( 'ewd_otp_lock_lost', __( 'The database migration lock was lost.', 'order-tracking' ) ); }
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Legacy database column name.
				$hash  = self::order_number_hash( $row->Order_Number );
				$value = null;
				if ( null !== $hash && ! isset( $seen[ $hash ] ) ) {
					$seen[ $hash ] = true;
					$value         = $hash;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration writes must be immediate.
				$result = $wpdb->update(
					$table,
					array( 'Order_Number_Unique_Hash' => $value ),
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Legacy database column name.
					array( 'Order_ID' => absint( $row->Order_ID ) ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $result ) {
					return new WP_Error( 'ewd_otp_identity_write_failed', __( 'Order number uniqueness migration could not be completed.', 'order-tracking' ) ); }

				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Legacy database column name.
				$paypal_hash = ewdotpOrderManager::paypal_receipt_hash( isset( $row->Order_PayPal_Receipt_Number ) ? $row->Order_PayPal_Receipt_Number : '' );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Legacy database column name.
				$zendesk_hash  = ewdotpOrderManager::zendesk_id_hash( isset( $row->Zendesk_ID ) ? $row->Zendesk_ID : 0 );
				$paypal_value  = $paypal_hash && ! isset( $paypal_seen[ $paypal_hash ] ) ? $paypal_hash : null;
				$zendesk_value = $zendesk_hash && ! isset( $zendesk_seen[ $zendesk_hash ] ) ? $zendesk_hash : null;
				if ( $paypal_value ) {
					$paypal_seen[ $paypal_hash ] = true; }
				if ( $zendesk_value ) {
					$zendesk_seen[ $zendesk_hash ] = true; }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration writes must be immediate.
				$result = $wpdb->update(
					$table,
					array(
						'Order_PayPal_Receipt_Hash' => $paypal_value,
						'Zendesk_Unique_Hash'       => $zendesk_value,
					),
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Legacy database column name.
					array( 'Order_ID' => absint( $row->Order_ID ) ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( false === $result ) {
					return new WP_Error( 'ewd_otp_external_identity_write_failed', __( 'External transaction identity migration could not be completed.', 'order-tracking' ) ); }
			}

			return true;
		}

		/** Phase 4: finalize and verify the indexed schema. */
		private function run_phase_4() {
			global $wpdb, $ewd_otp_controller;

			$ewd_otp_controller->customer_manager->create_tables();
			$ewd_otp_controller->order_manager->create_tables();
			$ewd_otp_controller->sales_rep_manager->create_tables();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table schema metadata.
			$indexes = $wpdb->get_col( "SHOW INDEX FROM {$ewd_otp_controller->order_manager->orders_table_name}", 2 );
			foreach ( array( 'order_number', 'order_number_unique_hash', 'paypal_receipt', 'paypal_receipt_unique_hash', 'zendesk_unique_hash' ) as $required ) {
				if ( ! in_array( $required, $indexes, true ) ) {
					/* translators: %s: required database index name. */
					return new WP_Error( 'ewd_otp_index_missing', sprintf( __( 'Required Order Tracking index %s is missing.', 'order-tracking' ), $required ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table schema metadata.
			$event_column = $wpdb->get_var( "SHOW COLUMNS FROM {$ewd_otp_controller->order_manager->orders_table_name} LIKE 'Zendesk_Event_Timestamp'" );
			if ( 'Zendesk_Event_Timestamp' !== $event_column ) {
				return new WP_Error( 'ewd_otp_column_missing', __( 'The Zendesk event ordering column is missing.', 'order-tracking' ) );
			}

			return true;
		}

		/** Phase 5: purge the legacy raw PayPal debug store. */
		private function run_phase_5() {
			delete_option( 'ewd_otp_debugging' );
			return true;
		}

		/** Phase 6: repeat final verification before the caller writes completion markers. */
		private function run_phase_6() {
			return $this->run_phase_4();
		}

		/**
		 * Handle the order number hash operation.
		 *
		 * @param mixed $order_number order number.
		 */
		public static function order_number_hash( $order_number ) {
			$order_number = trim( (string) $order_number );
			if ( '' === $order_number ) {
				return null; }

			if ( function_exists( 'remove_accents' ) ) {
				$order_number = remove_accents( $order_number ); }
			$order_number = function_exists( 'mb_strtolower' ) ? mb_strtolower( $order_number, 'UTF-8' ) : strtolower( $order_number );

			return hash( 'sha256', $order_number );
		}

		/**
		 * Handle the admin notice operation.
		 */
		public function admin_notice() {
			if ( $this->is_complete() || ! current_user_can( 'manage_options' ) ) {
				return; }

			$message = get_option( self::ERROR_OPTION, __( 'Order Tracking database migration is pending. Back up the database, then reload this administration page to retry.', 'order-tracking' ) );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * Handle the get table names operation.
		 */
		private function get_table_names() {
			global $ewd_otp_controller;

			return array_values(
				array_unique(
					array(
						$ewd_otp_controller->order_manager->orders_table_name,
						$ewd_otp_controller->order_manager->order_statuses_table_name,
						$ewd_otp_controller->order_manager->meta_table_name,
						$ewd_otp_controller->customer_manager->customers_table_name,
						$ewd_otp_controller->sales_rep_manager->sales_reps_table_name,
					)
				)
			);
		}

		/**
		 * Handle the acquire lock operation.
		 */
		private function acquire_lock() {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-scoped and cannot be cached.
			$database_lock = $wpdb->get_var( "SELECT GET_LOCK('ewd_otp_database_migration', 0)" );
			if ( '1' !== (string) $database_lock ) {
				return false; }
			$this->database_lock_held = true;

			$this->lock_owner = wp_generate_uuid4();
			$this->lock_value = array(
				'time'  => time(),
				'owner' => $this->lock_owner,
			);
			$lock             = get_option( self::LOCK_OPTION, array() );
			if ( empty( $lock ) && add_option( self::LOCK_OPTION, $this->lock_value, '', 'no' ) ) {
				return true; }

			// GET_LOCK is connection-scoped; once acquired, any option lease left by a
			// previous connection is stale and can be replaced with a compare-and-swap.
			if ( ! empty( $lock ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-swap on the WordPress options table.
				$result = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => maybe_serialize( $this->lock_value ) ),
					array(
						'option_name'  => self::LOCK_OPTION,
						'option_value' => maybe_serialize( $lock ),
					)
				);
				wp_cache_delete( self::LOCK_OPTION, 'options' );
				if ( 1 === $result ) {
					return true; }
			}

			$this->release_database_lock();
			return false;
		}

		/**
		 * Handle the release lock operation.
		 */
		private function release_lock() {
			global $wpdb;
			if ( '' !== $this->lock_owner ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Owner-checked lock release must be atomic.
				$wpdb->delete(
					$wpdb->options,
					array(
						'option_name'  => self::LOCK_OPTION,
						'option_value' => maybe_serialize( $this->lock_value ),
					)
				);
				wp_cache_delete( self::LOCK_OPTION, 'options' );
			}
			$this->lock_owner = '';
			$this->lock_value = array();
			$this->release_database_lock();
		}

		/**
		 * Handle the release database lock operation.
		 */
		private function release_database_lock() {
			global $wpdb;
			if ( ! $this->database_lock_held ) {
				return; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release is connection-scoped.
			$wpdb->get_var( "SELECT RELEASE_LOCK('ewd_otp_database_migration')" );
			$this->database_lock_held = false;
		}

		/**
		 * Handle the heartbeat lock operation.
		 */
		private function heartbeat_lock() {
			global $wpdb;
			if ( '' === $this->lock_owner ) {
				return false; }
			$next = array(
				'time'  => time(),
				'owner' => $this->lock_owner,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic owner-checked lease heartbeat.
			$result = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => maybe_serialize( $next ) ),
				array(
					'option_name'  => self::LOCK_OPTION,
					'option_value' => maybe_serialize( $this->lock_value ),
				)
			);
			if ( false === $result ) {
				return false; }
			$this->lock_value = $next;
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			$current = get_option( self::LOCK_OPTION, array() );
			return is_array( $current ) && isset( $current['owner'] ) && hash_equals( $this->lock_owner, (string) $current['owner'] );
		}

		/**
		 * Handle the write option operation.
		 *
		 * @param mixed $name name.
		 * @param mixed $value value.
		 */
		private function write_option( $name, $value ) {
			update_option( $name, $value, false );
			wp_cache_delete( $name, 'options' );
			$stored = get_option( $name, null );
			return is_scalar( $value ) ? (string) $value === (string) $stored : $value === $stored;
		}
	}
}
