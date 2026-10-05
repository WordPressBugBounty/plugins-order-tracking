<?php

/**
 * Class to handle importing orders into the plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ewdotpImport {
	public $status;
	public $message;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_install_screen' ) );

		$import_nonce = isset( $_POST['EWD_OTP_Import_Nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['EWD_OTP_Import_Nonce'] ) ) : '';
		$authorized   = '' !== $import_nonce && wp_verify_nonce( $import_nonce, 'EWD_OTP_Import' );
		if ( $authorized && isset( $_POST['ewd_otp_import_orders'] ) ) {
			add_action( 'admin_init', array( $this, 'import_orders' ) ); }
		if ( $authorized && isset( $_POST['ewd_otp_import_customers'] ) ) {
			add_action( 'admin_init', array( $this, 'import_customers' ) ); }
		if ( $authorized && isset( $_POST['ewd_otp_import_sales_reps'] ) ) {
			add_action( 'admin_init', array( $this, 'import_sales_reps' ) ); }
	}

	public function register_install_screen() {
		global $ewd_otp_controller;

		add_submenu_page(
			'ewd-otp-orders',
			'Import Menu',
			'Import',
			$ewd_otp_controller->settings->get_setting( 'access-role' ),
			'ewd-otp-import',
			array( $this, 'display_import_screen' )
		);
	}

	public function display_import_screen() {
		global $ewd_otp_controller;

		$import_permission = $ewd_otp_controller->permissions->check_permission( 'import' );
		?>
		<div class='wrap'>
			<h2>Import</h2>
			<?php if ( $import_permission ) { ?> 

				<h4><?php _e( 'Orders', 'order-tracking' ); ?></h4>
				<form method='post' enctype="multipart/form-data">
					
					<?php wp_nonce_field( 'EWD_OTP_Import', 'EWD_OTP_Import_Nonce' ); ?>

					<p>
			<label for="ewd_otp_orders_spreadsheet"><?php esc_html_e( 'Spreadsheet Containing Orders', 'order-tracking' ); ?></label><br />
						<input name="ewd_otp_orders_spreadsheet" type="file" value=""/>
					</p>
					<input type='submit' name='ewd_otp_import_orders' value='Import Orders' class='button button-primary' />
				</form>

				<h4><?php _e( 'Customers', 'order-tracking' ); ?></h4>
				<form method='post' enctype="multipart/form-data">
					
					<?php wp_nonce_field( 'EWD_OTP_Import', 'EWD_OTP_Import_Nonce' ); ?>

					<p>
			<label for="ewd_otp_customers_spreadsheet"><?php esc_html_e( 'Spreadsheet Containing Customers', 'order-tracking' ); ?></label><br />
						<input name="ewd_otp_customers_spreadsheet" type="file" value=""/>
					</p>
					<input type='submit' name='ewd_otp_import_customers' value='Import Customers' class='button button-primary' />
				</form>

				<h4><?php _e( 'Sales Reps', 'order-tracking' ); ?></h4>
				<form method='post' enctype="multipart/form-data">
					
					<?php wp_nonce_field( 'EWD_OTP_Import', 'EWD_OTP_Import_Nonce' ); ?>

					<p>
			<label for="ewd_otp_sales_reps_spreadsheet"><?php esc_html_e( 'Spreadsheet Containing Sales Reps', 'order-tracking' ); ?></label><br />
						<input name="ewd_otp_sales_reps_spreadsheet" type="file" value=""/>
					</p>
					<input type='submit' name='ewd_otp_import_sales_reps' value='Import Sales Reps' class='button button-primary' />
				</form>

			<?php } else { ?>
				<div class='ewd-otp-premium-locked'>
					<a href="https://www.etoilewebdesign.com/license-payment/?Selected=OTP&Quantity=1&utm_source=otp_import" target="_blank">Upgrade</a> to the premium version to use this feature
				</div>
			<?php } ?>
		</div>
		<?php
	}

	public function import_orders() {
		global $ewd_otp_controller;

		if ( ! $this->authorize_import_request() ) {
			return; }
		$field_name = 'ewd_otp_orders_spreadsheet';

		$update = $this->handle_spreadsheet_upload( $field_name );

		$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();

		if ( $update['message_type'] != 'Success' ) {

			$this->status  = false;
			$this->message = $update['message'];

			add_action( 'admin_notices', array( $this, 'display_notice' ) );

			return;
		}

		$excel_url = $update['path'];
		$runtime   = ewdotpSpreadsheetRuntime::load();
		if ( is_wp_error( $runtime ) ) {
			$this->set_error_notice( $runtime->get_error_message() );
			wp_delete_file( $excel_url );
			return;
		}

		// Build the workbook object out of the uploaded spreadsheet
		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $excel_url );
		} catch ( Exception $exception ) {
			$this->set_error_notice( __( 'The spreadsheet could not be read.', 'order-tracking' ) );
			wp_delete_file( $excel_url );
			return;
		}
		wp_delete_file( $excel_url );

		// Create a worksheet object out of the product sheet in the workbook
		$sheet = $spreadsheet->getActiveSheet();

		$allowable_custom_fields = array();
		foreach ( $custom_fields as $custom_field ) {
			$allowable_custom_fields[] = $custom_field->name; }
		// List of fields that can be accepted via upload
		$allowed_fields = array( 'Name', 'Number', 'Order Status', 'Location', 'Display', 'Notes Public', 'Notes Private', 'Email', 'Phone Number', 'Show in Admin Table', 'Sales Rep ID', 'Customer ID' );
		$header_error   = $this->validate_sheet_headers( $sheet, $allowed_fields, $custom_fields, array( 'Number' ) );
		if ( $header_error ) {
			$this->set_error_notice( $header_error );
			return; }

		// Get column names
		$highest_column       = $sheet->getHighestColumn();
		$highest_column_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest_column );
		for ( $column = 1; $column <= $highest_column_index; $column++ ) {

			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Name' ) {
				$name_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Number' ) {
				$number_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Order Status' ) {
				$status_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Location' ) {
				$location_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Display' || trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Show in Admin Table' ) {
				$display_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Notes Public' ) {
				$public_notes_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Notes Private' ) {
				$private_notes_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Email' ) {
				$email_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Phone Number' ) {
				$phone_number_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Sales Rep ID' ) {
				$sales_rep_id_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Customer ID' ) {
				$customer_id_column = $column; }

			foreach ( $custom_fields as $custom_field ) {

				if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === $custom_field->name ) {
					$custom_field->column = $column; }
			}
		}

		$name_column          = ! empty( $name_column ) ? $name_column : -1;
		$number_column        = ! empty( $number_column ) ? $number_column : -1;
		$status_column        = ! empty( $status_column ) ? $status_column : -1;
		$location_column      = ! empty( $location_column ) ? $location_column : -1;
		$display_column       = ! empty( $display_column ) ? $display_column : -1;
		$public_notes_column  = ! empty( $public_notes_column ) ? $public_notes_column : -1;
		$private_notes_column = ! empty( $private_notes_column ) ? $private_notes_column : -1;
		$email_column         = ! empty( $email_column ) ? $email_column : -1;
		$phone_number_column  = ! empty( $phone_number_column ) ? $phone_number_column : -1;
		$sales_rep_id_column  = ! empty( $sales_rep_id_column ) ? $sales_rep_id_column : -1;
		$customer_id_column   = ! empty( $customer_id_column ) ? $customer_id_column : -1;

		// Put the spreadsheet data into a multi-dimensional array to facilitate processing
		$highest_row = $sheet->getHighestRow();
		$data        = array();
		for ( $row = 2; $row <= $highest_row; $row++ ) {
			for ( $column = 1; $column <= $highest_column_index; $column++ ) {
				$data[ $row ][ $column ] = $sheet->getCellByColumnAndRow( $column, $row )->getValue();
			}
		}

		$preflight_error = $this->preflight_order_rows( $data, $number_column, $status_column, $location_column, $display_column );
		if ( $preflight_error ) {
			$this->set_error_notice( $preflight_error );
			return; }

		// Create/update records only after every row has passed structural preflight.
		$created = 0;
		$updated = 0;
		foreach ( $data as $source_row => $order_data ) {
			// Save the data into an array, so that an order can be updated based on the order
			// number if it exists already
			$order_data_array = array(
				'custom_fields' => array(),
			);

			foreach ( $order_data as $col_index => $value ) {

				if ( $col_index === $name_column ) {
					$order_data_array['name'] = sanitize_text_field( $value ); } elseif ( $col_index === $number_column ) {
					$order_data_array['number'] = sanitize_text_field( $value ); } elseif ( $col_index === $status_column ) {
						$order_data_array['status']          = sanitize_text_field( $value );
						$order_data_array['external_status'] = $order_data_array['status'];
					} elseif ( $col_index === $location_column ) {
						$order_data_array['location'] = sanitize_text_field( $value ); } elseif ( $col_index === $display_column && '' !== trim( (string) $value ) ) {
						$order_data_array['display'] = self::normalize_display( $value );
						} elseif ( $col_index === $public_notes_column ) {
							$order_data_array['notes_public'] = sanitize_textarea_field( $value ); } elseif ( $col_index === $private_notes_column ) {
											$order_data_array['notes_private'] = sanitize_textarea_field( $value ); } elseif ( $col_index === $email_column ) {
											$order_data_array['email'] = sanitize_email( $value ); } elseif ( $col_index === $phone_number_column ) {
												$order_data_array['phone_number'] = self::sanitize_phone( $value );
											} elseif ( $col_index === $sales_rep_id_column ) {
												$order_data_array['sales_rep'] = intval( $value ); } elseif ( $col_index === $customer_id_column ) {
																$order_data_array['customer'] = intval( $value ); } else {

													foreach ( $custom_fields as $custom_field ) {

														if ( $col_index === $custom_field->column ) {
																		$order_data_array['custom_fields'][ $custom_field->id ] = sanitize_text_field( $value ); }
													}
																}
			}

			// Create a new order object, and assign the imported values to it
			$order = new ewdotpOrder();

			$order_status = null;

			if ( ! empty( $order_data_array['number'] ) ) {

				$db_order_data = $ewd_otp_controller->order_manager->get_order_from_tracking_number( $order_data_array['number'] );

				if ( $db_order_data ) {

					$order->load_order( $db_order_data );

					$order_status = $ewd_otp_controller->order_manager->get_order_field( 'Order_Status', $order->id );
				}
			}

			if ( empty( $order->id ) ) {
				$order->display = true; }

			if ( ! empty( $order_data_array['name'] ) ) {
				$order->name = $order_data_array['name']; }
			if ( ! empty( $order_data_array['number'] ) ) {
				$order->number = $order_data_array['number']; }
			if ( ! empty( $order_data_array['status'] ) ) {
				$order->status          = $order_data_array['status'];
				$order->external_status = $order_data_array['status']; }
			if ( ! empty( $order_data_array['location'] ) ) {
				$order->location = $order_data_array['location']; }
			if ( isset( $order_data_array['display'] ) ) {
				$order->display = $order_data_array['display'];
			}
			if ( ! empty( $order_data_array['notes_public'] ) ) {
				$order->notes_public = $order_data_array['notes_public']; }
			if ( ! empty( $order_data_array['notes_private'] ) ) {
				$order->notes_private = $order_data_array['notes_private']; }
			if ( ! empty( $order_data_array['email'] ) ) {
				$order->email = $order_data_array['email']; }
			if ( ! empty( $order_data_array['phone_number'] ) ) {
				$order->phone_number = $order_data_array['phone_number']; }
			if ( ! empty( $order_data_array['sales_rep'] ) ) {
				$order->sales_rep = $order_data_array['sales_rep']; }
			if ( ! empty( $order_data_array['customer'] ) ) {
				$order->customer = $order_data_array['customer']; }
			if ( ! empty( $order_data_array['custom_fields'] ) ) {

				foreach ( $order_data_array['custom_fields'] as $field_id => $field_value ) {

					$order->custom_fields[ $field_id ] = $field_value;
				}
			}

			if ( empty( $order->customer ) and ! empty( $order->email ) and $ewd_otp_controller->settings->get_setting( 'allow-assign-orders-to-customers' ) ) {

				$order->customer = $ewd_otp_controller->customer_manager->get_customer_id_from_email( $order->email );
			}

			if ( empty( $order->id ) ) {

				if ( ! $order->insert_order_with_history() ) {
					/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
					$this->set_error_notice( sprintf( __( 'Import stopped at row %1$d after %2$d created and %3$d updated rows: %4$s', 'order-tracking' ), $source_row, $created, $updated, $ewd_otp_controller->order_manager->last_error ) );
					return;
				}
				++$created;
			} else {
				$status_changed = ! empty( $order_data_array['status'] ) && $order_data_array['status'] !== $order_status;
				$saved          = $status_changed ? $order->set_status( $order_data_array['status'] ) : $order->update_order();

				if ( false === $saved ) {
					/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
					$this->set_error_notice( sprintf( __( 'Import stopped at row %1$d after %2$d created and %3$d updated rows: %4$s', 'order-tracking' ), $source_row, $created, $updated, $ewd_otp_controller->order_manager->last_error ) );
					return;
				}
				++$updated;
			}
		}

		$this->status = true;
		/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
		$this->message = sprintf( __( 'Import complete: %1$d rows read, %2$d created, %3$d updated, 0 failed.', 'order-tracking' ), count( $data ), $created, $updated );
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

	/**
	 * Takes customer information from a spreadsheet and adds it to the database
	 *
	 * @since 3.3.5
	 */
	public function import_customers() {
		global $ewd_otp_controller;

		if ( ! $this->authorize_import_request() ) {
			return; }
		$field_name = 'ewd_otp_customers_spreadsheet';

		$update = $this->handle_spreadsheet_upload( $field_name );

		$custom_fields = $ewd_otp_controller->settings->get_customer_custom_fields();

		if ( $update['message_type'] != 'Success' ) {

			$this->status  = false;
			$this->message = $update['message'];

			add_action( 'admin_notices', array( $this, 'display_notice' ) );

			return;
		}

		$excel_url = $update['path'];
		$runtime   = ewdotpSpreadsheetRuntime::load();
		if ( is_wp_error( $runtime ) ) {
			$this->set_error_notice( $runtime->get_error_message() );
			wp_delete_file( $excel_url );
			return;
		}

		// Build the workbook object out of the uploaded spreadsheet
		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $excel_url );
		} catch ( Exception $exception ) {
			$this->set_error_notice( __( 'The spreadsheet could not be read.', 'order-tracking' ) );
			wp_delete_file( $excel_url );
			return;
		}
		wp_delete_file( $excel_url );

		// Create a worksheet object out of the product sheet in the workbook
		$sheet = $spreadsheet->getActiveSheet();

		$allowable_custom_fields = array();
		foreach ( $custom_fields as $custom_field ) {
			$allowable_custom_fields[] = $custom_field->name; }
		// List of fields that can be accepted via upload
		$allowed_fields = array( 'Customer ID', 'Number', 'Name', 'Email', 'Sales Rep ID', 'WP ID', 'FEUP ID' );
		$header_error   = $this->validate_sheet_headers( $sheet, $allowed_fields, $custom_fields, array( 'Number' ) );
		if ( $header_error ) {
			$this->set_error_notice( $header_error );
			return; }

		// Get column names
		$highest_column       = $sheet->getHighestColumn();
		$highest_column_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest_column );
		for ( $column = 1; $column <= $highest_column_index; $column++ ) {

			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Customer ID' ) {
				$customer_id_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Number' ) {
				$number_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Name' ) {
				$name_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Email' ) {
				$email_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Sales Rep ID' ) {
				$sales_rep_id_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'WP ID' ) {
				$wp_id_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'FEUP ID' ) {
				$feup_id_column = $column; }

			foreach ( $custom_fields as $custom_field ) {

				if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === $custom_field->name ) {
					$custom_field->column = $column; }
			}
		}

		$customer_id_column  = ! empty( $customer_id_column ) ? $customer_id_column : -1;
		$number_column       = ! empty( $number_column ) ? $number_column : -1;
		$name_column         = ! empty( $name_column ) ? $name_column : -1;
		$email_column        = ! empty( $email_column ) ? $email_column : -1;
		$sales_rep_id_column = ! empty( $sales_rep_id_column ) ? $sales_rep_id_column : -1;
		$wp_id_column        = ! empty( $wp_id_column ) ? $wp_id_column : -1;
		$feup_id_column      = ! empty( $feup_id_column ) ? $feup_id_column : -1;

		// Put the spreadsheet data into a multi-dimensional array to facilitate processing
		$highest_row = $sheet->getHighestRow();
		$data        = array();
		for ( $row = 2; $row <= $highest_row; $row++ ) {
			for ( $column = 1; $column <= $highest_column_index; $column++ ) {
				$data[ $row ][ $column ] = $sheet->getCellByColumnAndRow( $column, $row )->getValue();
			}
		}
		$preflight_error = $this->preflight_entity_rows( $data, $customer_id_column, $number_column, 'customer' );
		if ( $preflight_error ) {
			$this->set_error_notice( $preflight_error );
			return;
		}

		// Create the query to insert the customers one at a time into the database and then run it
		$created = 0;
		$updated = 0;
		foreach ( $data as $source_row => $customer_data ) {
			// Save the data into an array, so that a customer can be updated based on the customer
			// ID if it exists already
			$customer_data_array = array(
				'custom_fields' => array(),
			);

			foreach ( $customer_data as $col_index => $value ) {

				if ( $col_index === $customer_id_column ) {
					$customer_data_array['customer_id'] = intval( $value ); } elseif ( $col_index === $number_column ) {
					$customer_data_array['number'] = sanitize_text_field( $value ); } elseif ( $col_index === $name_column ) {
						$customer_data_array['name'] = sanitize_text_field( $value ); } elseif ( $col_index === $email_column ) {
						$customer_data_array['email'] = sanitize_email( $value ); } elseif ( $col_index === $sales_rep_id_column ) {
							$customer_data_array['sales_rep'] = intval( $value ); } elseif ( $col_index === $wp_id_column ) {
							$customer_data_array['wp_id'] = intval( $value ); } elseif ( $col_index === $feup_id_column ) {
								$customer_data_array['feup_id'] = intval( $value ); } else {

								foreach ( $custom_fields as $custom_field ) {

									if ( $col_index === $custom_field->column ) {
										$customer_data_array['custom_fields'][ $custom_field->id ] = sanitize_text_field( $value ); }
								}
								}
			}

			// Create a new customer object, and assign the imported values to it
			$customer = new ewdotpCustomer();

			if ( ! empty( $customer_data_array['customer_id'] ) ) {

				$db_customer_data = $ewd_otp_controller->customer_manager->get_customer_from_id( $customer_data_array['customer_id'] );

				if ( $db_customer_data ) {

					$customer->load_customer( $db_customer_data );
				}
			}

			if ( ! empty( $customer_data_array['number'] ) ) {
				$customer->number = $customer_data_array['number']; }
			if ( ! empty( $customer_data_array['name'] ) ) {
				$customer->name = $customer_data_array['name']; }
			if ( ! empty( $customer_data_array['email'] ) ) {
				$customer->email = $customer_data_array['email']; }
			if ( ! empty( $customer_data_array['sales_rep'] ) ) {
				$customer->sales_rep = $customer_data_array['sales_rep']; }
			if ( ! empty( $customer_data_array['wp_id'] ) ) {
				$customer->wp_id = $customer_data_array['wp_id']; }
			if ( ! empty( $customer_data_array['feup_id'] ) ) {
				$customer->feup_id = $customer_data_array['feup_id']; }
			if ( ! empty( $customer_data_array['custom_fields'] ) ) {

				foreach ( $customer_data_array['custom_fields'] as $field_id => $field_value ) {

					$customer->custom_fields[ $field_id ] = $field_value;
				}
			}

			if ( empty( $customer->id ) ) {

				if ( ! $customer->insert_customer() ) {
					/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
					$this->set_error_notice( sprintf( __( 'Customer import stopped at row %1$d after %2$d created and %3$d updated rows.', 'order-tracking' ), $source_row, $created, $updated ) );
					return;
				}
				++$created;

				do_action( 'ewd_otp_admin_customer_inserted', $customer );
			} else {

				if ( ! $customer->update_customer() ) {
					/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
					$this->set_error_notice( sprintf( __( 'Customer import stopped at row %1$d after %2$d created and %3$d updated rows.', 'order-tracking' ), $source_row, $created, $updated ) );
					return;
				}
				++$updated;
			}
		}

		$this->status = true;
		/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
		$this->message = sprintf( __( 'Customer import complete: %1$d rows read, %2$d created, %3$d updated, 0 failed.', 'order-tracking' ), count( $data ), $created, $updated );
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

	/**
	 * Takes sales rep information from a spreadsheet and adds it to the database
	 *
	 * @since 3.3.5
	 */
	public function import_sales_reps() {
		global $ewd_otp_controller;

		if ( ! $this->authorize_import_request() ) {
			return; }
		$field_name = 'ewd_otp_sales_reps_spreadsheet';

		$update = $this->handle_spreadsheet_upload( $field_name );

		$custom_fields = $ewd_otp_controller->settings->get_sales_rep_custom_fields();

		if ( $update['message_type'] != 'Success' ) {

			$this->status  = false;
			$this->message = $update['message'];

			add_action( 'admin_notices', array( $this, 'display_notice' ) );

			return;
		}

		$excel_url = $update['path'];
		$runtime   = ewdotpSpreadsheetRuntime::load();
		if ( is_wp_error( $runtime ) ) {
			$this->set_error_notice( $runtime->get_error_message() );
			wp_delete_file( $excel_url );
			return;
		}

		// Build the workbook object out of the uploaded spreadsheet
		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $excel_url );
		} catch ( Exception $exception ) {
			$this->set_error_notice( __( 'The spreadsheet could not be read.', 'order-tracking' ) );
			wp_delete_file( $excel_url );
			return;
		}
		wp_delete_file( $excel_url );

		// Create a worksheet object out of the product sheet in the workbook
		$sheet = $spreadsheet->getActiveSheet();

		$allowable_custom_fields = array();
		foreach ( $custom_fields as $custom_field ) {
			$allowable_custom_fields[] = $custom_field->name; }
		// List of fields that can be accepted via upload
		$allowed_fields = array( 'Sales Rep ID', 'Number', 'First Name', 'Last Name', 'Email', 'Phone Number', 'WP ID' );
		$header_error   = $this->validate_sheet_headers( $sheet, $allowed_fields, $custom_fields, array( 'Number' ) );
		if ( $header_error ) {
			$this->set_error_notice( $header_error );
			return; }

		// Get column names
		$highest_column       = $sheet->getHighestColumn();
		$highest_column_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest_column );
		for ( $column = 1; $column <= $highest_column_index; $column++ ) {

			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Sales Rep ID' ) {
				$sales_rep_id_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Number' ) {
				$number_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'First Name' ) {
				$first_name_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Last Name' ) {
				$last_name_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Email' ) {
				$email_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'Phone Number' ) {
				$phone_number_column = $column; }
			if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === 'WP ID' ) {
				$wp_id_column = $column; }

			foreach ( $custom_fields as $custom_field ) {

				if ( trim( $sheet->getCellByColumnAndRow( $column, 1 )->getValue() ) === $custom_field->name ) {
					$custom_field->column = $column; }
			}
		}

		$sales_rep_id_column = ! empty( $sales_rep_id_column ) ? $sales_rep_id_column : -1;
		$number_column       = ! empty( $number_column ) ? $number_column : -1;
		$first_name_column   = ! empty( $first_name_column ) ? $first_name_column : -1;
		$last_name_column    = ! empty( $last_name_column ) ? $last_name_column : -1;
		$email_column        = ! empty( $email_column ) ? $email_column : -1;
		$phone_number_column = ! empty( $phone_number_column ) ? $phone_number_column : -1;
		$wp_id_column        = ! empty( $wp_id_column ) ? $wp_id_column : -1;

		// Put the spreadsheet data into a multi-dimensional array to facilitate processing
		$highest_row = $sheet->getHighestRow();
		$data        = array();
		for ( $row = 2; $row <= $highest_row; $row++ ) {
			for ( $column = 1; $column <= $highest_column_index; $column++ ) {
				$data[ $row ][ $column ] = $sheet->getCellByColumnAndRow( $column, $row )->getValue();
			}
		}
		$preflight_error = $this->preflight_entity_rows( $data, $sales_rep_id_column, $number_column, 'sales_rep' );
		if ( $preflight_error ) {
			$this->set_error_notice( $preflight_error );
			return;
		}

		// Create the query to insert the sales reps one at a time into the database and then run it
		$created = 0;
		$updated = 0;
		foreach ( $data as $source_row => $sales_rep_data ) {
			// Save the data into an array, so that a sales rep can be updated based on the sales rep
			// ID if it exists already
			$sales_rep_data_array = array(
				'custom_fields' => array(),
			);

			foreach ( $sales_rep_data as $col_index => $value ) {

				if ( $col_index === $sales_rep_id_column ) {
					$sales_rep_data_array['sales_rep_id'] = intval( $value ); } elseif ( $col_index === $number_column ) {
					$sales_rep_data_array['number'] = sanitize_text_field( $value ); } elseif ( $col_index === $first_name_column ) {
						$sales_rep_data_array['first_name'] = sanitize_text_field( $value ); } elseif ( $col_index === $last_name_column ) {
						$sales_rep_data_array['last_name'] = sanitize_text_field( $value ); } elseif ( $col_index === $email_column ) {
							$sales_rep_data_array['email'] = sanitize_email( $value ); } elseif ( $col_index === $phone_number_column ) {
							$sales_rep_data_array['phone_number'] = self::sanitize_phone( $value );
							} elseif ( $col_index === $wp_id_column ) {
								$sales_rep_data_array['wp_id'] = intval( $value ); } else {

								foreach ( $custom_fields as $custom_field ) {

									if ( $col_index === $custom_field->column ) {
										$sales_rep_data_array['custom_fields'][ $custom_field->id ] = sanitize_text_field( $value ); }
								}
								}
			}

			// Create a new sales_rep object, and assign the imported values to it
			$sales_rep = new ewdotpSalesRep();

			if ( ! empty( $sales_rep_data_array['sales_rep_id'] ) ) {

				$db_sales_rep_data = $ewd_otp_controller->sales_rep_manager->get_sales_rep_from_id( $sales_rep_data_array['sales_rep_id'] );

				if ( $db_sales_rep_data ) {

					$sales_rep->load_sales_rep( $db_sales_rep_data );
				}
			}

			if ( ! empty( $sales_rep_data_array['number'] ) ) {
				$sales_rep->number = $sales_rep_data_array['number']; }
			if ( ! empty( $sales_rep_data_array['first_name'] ) ) {
				$sales_rep->first_name = $sales_rep_data_array['first_name']; }
			if ( ! empty( $sales_rep_data_array['last_name'] ) ) {
				$sales_rep->last_name = $sales_rep_data_array['last_name']; }
			if ( ! empty( $sales_rep_data_array['email'] ) ) {
				$sales_rep->email = $sales_rep_data_array['email']; }
			if ( ! empty( $sales_rep_data_array['phone_number'] ) ) {
				$sales_rep->phone_number = $sales_rep_data_array['phone_number']; }
			if ( ! empty( $sales_rep_data_array['wp_id'] ) ) {
				$sales_rep->wp_id = $sales_rep_data_array['wp_id']; }
			if ( ! empty( $sales_rep_data_array['custom_fields'] ) ) {

				foreach ( $sales_rep_data_array['custom_fields'] as $field_id => $field_value ) {

					$sales_rep->custom_fields[ $field_id ] = $field_value;
				}
			}

			if ( empty( $sales_rep->id ) ) {

				if ( ! $sales_rep->insert_sales_rep() ) {
					/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
					$this->set_error_notice( sprintf( __( 'Sales representative import stopped at row %1$d after %2$d created and %3$d updated rows.', 'order-tracking' ), $source_row, $created, $updated ) );
					return;
				}
				++$created;
				do_action( 'ewd_otp_admin_sales_rep_inserted', $sales_rep );
			} else {

				if ( ! $sales_rep->update_sales_rep() ) {
					/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
					$this->set_error_notice( sprintf( __( 'Sales representative import stopped at row %1$d after %2$d created and %3$d updated rows.', 'order-tracking' ), $source_row, $created, $updated ) );
					return;
				}
				++$updated;
			}
		}

		$this->status = true;
		/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
		$this->message = sprintf( __( 'Sales representative import complete: %1$d rows read, %2$d created, %3$d updated, 0 failed.', 'order-tracking' ), count( $data ), $created, $updated );
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

		/**
		 * Validate and load an uploaded spreadsheet.
		 *
		 * @param string $field_name Upload field name.
		 * @return array|WP_Error
		 */
	public function handle_spreadsheet_upload( $field_name ) {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The import nonce and capability are verified before the upload helper is called.
		if ( empty( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'No file was uploaded.', 'order-tracking' ),
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The import nonce and capability are verified before the upload helper is called; individual upload metadata is validated below.
		$file = wp_unslash( $_FILES[ $field_name ] );
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'The spreadsheet upload did not complete successfully.', 'order-tracking' ),
			);
		}

		$allowed_mimes = array(
			'csv'  => 'text/csv',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);
		$filetype      = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), $allowed_mimes );
		if ( empty( $filetype['ext'] ) || ! isset( $allowed_mimes[ $filetype['ext'] ] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'File must be a valid CSV, XLS or XLSX spreadsheet.', 'order-tracking' ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'The spreadsheet could not be stored for processing.', 'order-tracking' ),
			);
		}

		return array(
			'message_type' => 'Success',
			'path'         => $upload['file'],
		);
	}

		/**
		 * Verify authorization for an import request.
		 *
		 * @return true|WP_Error
		 */
	private function authorize_import_request() {

		global $ewd_otp_controller;

		if ( ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) ) {
			return false;
		}
		if ( ! $ewd_otp_controller->permissions->check_permission( 'import' ) ) {
			return false;
		}
		if ( empty( $_POST['EWD_OTP_Import_Nonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['EWD_OTP_Import_Nonce'] ) ), 'EWD_OTP_Import' );
	}

		/**
		 * Store a sanitized import error notice.
		 *
		 * @param string $message Error message.
		 * @return void
		 */
	private function set_error_notice( $message ) {

		$this->status  = false;
		$this->message = $message;
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

		/**
		 * Normalize an imported telephone value.
		 *
		 * @param mixed $value Telephone value.
		 * @return string
		 */
	public static function sanitize_phone( $value ) {

		$value = wp_strip_all_tags( (string) $value, true );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		return sanitize_text_field( $value );
	}

		/**
		 * Validate spreadsheet column headers.
		 *
		 * @param object $sheet           Spreadsheet worksheet.
		 * @param array  $allowed_fields  Allowed core fields.
		 * @param array  $custom_fields   Allowed custom fields.
		 * @param array  $required_fields Required fields.
		 * @return array|WP_Error
		 */
	private function validate_sheet_headers( $sheet, $allowed_fields, $custom_fields, $required_fields ) {

		$allowed = array_fill_keys( $allowed_fields, true );
		foreach ( $custom_fields as $custom_field ) {
			$allowed[ trim( $custom_field->name ) ] = true;
		}

		$seen       = array();
		$unknown    = array();
		$duplicates = array();
		$highest    = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $sheet->getHighestColumn() );
		for ( $column = 1; $column <= $highest; $column++ ) {
			$header = trim( (string) $sheet->getCellByColumnAndRow( $column, 1 )->getValue() );
			if ( '' === $header ) {
				continue;
			}
			if ( isset( $seen[ $header ] ) ) {
				$duplicates[] = $header;
			}
			$seen[ $header ] = true;
			if ( ! isset( $allowed[ $header ] ) ) {
				$unknown[] = $header;
			}
		}

		if ( $unknown ) {
			/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
			return sprintf( __( 'Unknown spreadsheet header(s): %s.', 'order-tracking' ), implode( ', ', array_unique( $unknown ) ) );
		}
		if ( $duplicates ) {
			/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
			return sprintf( __( 'Duplicate spreadsheet header(s): %s.', 'order-tracking' ), implode( ', ', array_unique( $duplicates ) ) );
		}
		foreach ( $required_fields as $required ) {
			if ( ! isset( $seen[ $required ] ) ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Required spreadsheet header missing: %s.', 'order-tracking' ), $required );
			}
		}

		return false;
	}

		/**
		 * Validate order rows before changing the database.
		 *
		 * @param array $rows            Spreadsheet rows.
		 * @param int   $number_column   Order number column.
		 * @param int   $status_column   Status column.
		 * @param int   $location_column Location column.
		 * @param int   $display_column  Display column.
		 * @return true|WP_Error
		 */
	private function preflight_order_rows( $rows, $number_column, $status_column, $location_column, $display_column ) {

		global $ewd_otp_controller;

		$statuses = array();
		foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) ) as $status ) {
			$statuses[] = (string) $status->status;
		}
		$locations = array();
		foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'locations' ) ) as $location ) {
			$locations[] = (string) $location->name;
		}

		$numbers = array();
		foreach ( $rows as $row_number => $row ) {
			$number = isset( $row[ $number_column ] ) ? trim( sanitize_text_field( $row[ $number_column ] ) ) : '';
			if ( '' === $number ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %d requires an Order Number.', 'order-tracking' ), $row_number );
			}
			$number_hash = ewdotpDatabaseMigration::order_number_hash( $number );
			if ( isset( $numbers[ $number_hash ] ) ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Rows %1$d and %2$d contain the same Order Number.', 'order-tracking' ), $numbers[ $number_hash ], $row_number );
			}
			$numbers[ $number_hash ] = $row_number;

			$status = isset( $row[ $status_column ] ) ? trim( sanitize_text_field( $row[ $status_column ] ) ) : '';
			if ( '' !== $status && ! in_array( $status, $statuses, true ) ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %1$d contains an unknown status: %2$s.', 'order-tracking' ), $row_number, $status );
			}

			$location = isset( $row[ $location_column ] ) ? trim( sanitize_text_field( $row[ $location_column ] ) ) : '';
			if ( '' !== $location && ! in_array( $location, $locations, true ) ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %1$d contains an unknown location: %2$s.', 'order-tracking' ), $row_number, $location );
			}

			$display = isset( $row[ $display_column ] ) ? trim( (string) $row[ $display_column ] ) : '';
			if ( '' !== $display && null === self::normalize_display( $display ) ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %d has an invalid Display value. Use yes/no, true/false, or 1/0.', 'order-tracking' ), $row_number );
			}
		}

		return false;
	}

		/**
		 * Validate customer or sales-representative rows before mutation.
		 *
		 * @param array  $rows          Spreadsheet rows.
		 * @param int    $id_column     Entity id column.
		 * @param int    $number_column Entity number column.
		 * @param string $type          Entity type.
		 * @return true|WP_Error
		 */
	private function preflight_entity_rows( $rows, $id_column, $number_column, $type ) {

		global $ewd_otp_controller;
		$numbers = array();
		foreach ( $rows as $row_number => $row ) {
			$number = isset( $row[ $number_column ] ) ? trim( sanitize_text_field( $row[ $number_column ] ) ) : '';
			if ( '' === $number ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %d requires a Number.', 'order-tracking' ), $row_number );
			}
			$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $number, 'UTF-8' ) : strtolower( $number );
			if ( isset( $numbers[ $normalized ] ) ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Rows %1$d and %2$d contain the same Number.', 'order-tracking' ), $numbers[ $normalized ], $row_number );
			}
			$numbers[ $normalized ] = $row_number;

			$entity_id = isset( $row[ $id_column ] ) ? absint( $row[ $id_column ] ) : 0;
			$existing  = $entity_id && 'customer' === $type
			? $ewd_otp_controller->customer_manager->get_customer_from_id( $entity_id )
				: ( $entity_id ? $ewd_otp_controller->sales_rep_manager->get_sales_rep_from_id( $entity_id ) : false );
			if ( $entity_id && ! $existing ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %1$d references a nonexistent %2$s ID.', 'order-tracking' ), $row_number, 'customer' === $type ? __( 'customer', 'order-tracking' ) : __( 'sales representative', 'order-tracking' ) );
			}

			$number_owner = 'customer' === $type
				? absint( $ewd_otp_controller->customer_manager->get_customer_id_from_number( $number ) )
				: absint( $ewd_otp_controller->sales_rep_manager->get_sales_rep_id_from_number( $number ) );
			if ( $number_owner && $number_owner !== $entity_id ) {
				/* translators: Import row numbers, result counts, field names, and validation values replace the placeholders. */
				return sprintf( __( 'Row %1$d uses a Number already assigned to another %2$s.', 'order-tracking' ), $row_number, 'customer' === $type ? __( 'customer', 'order-tracking' ) : __( 'sales representative', 'order-tracking' ) );
			}
		}
		return false;
	}

		/**
		 * Normalize an imported boolean display value.
		 *
		 * @param mixed $value Display value.
		 * @return bool
		 */
	public static function normalize_display( $value ) {

		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'yes', 'true', '1' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', 'false', '0' ), true ) ) {
			return false;
		}
		return null;
	}
	public function display_notice() {

		if ( $this->status ) {

			echo "<div class='updated'><p>" . esc_html( $this->message ) . '</p></div>';
		} else {

			echo "<div class='error'><p>" . esc_html( $this->message ) . '</p></div>';
		}
	}
}
