<?php

/**
 * Class to export orders created by the plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
class ewdotpExport {

	// Set whether a valid nonce is needed before exporting orders
	public $nonce_check = true;
	/**
	 * Public collection scope, or an empty string for administration.
	 *
	 * @var string
	 */
	public $frontend_scope = '';
	/**
	 * all the messages to display
	 * array(
	 *   'error' => 'Some error!',
	 *   'info'  => 'Some info msg',
	 *   'success' => 'Some success',
	 *   'warning' => 'Some warning!'
	 * )
	 *
	 * @var array
	 */
	public $messages = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'maybe_run_export' ) );
		add_action( 'admin_menu', array( $this, 'register_install_screen' ) );
	}

	/**
	 * Handle submitted exports after WordPress has loaded pluggable functions.
	 *
	 * @return void
	 */
	public function maybe_run_export() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- run_export() verifies this request before producing a download.
		if ( isset( $_POST['ewd_otp_export'] ) ) {
			$this->run_export();
		}
	}

	public function register_install_screen() {
		global $ewd_otp_controller;

		add_submenu_page(
			'ewd-otp-orders',
			'Export Menu',
			'Export',
			$ewd_otp_controller->settings->get_setting( 'access-role' ),
			'ewd-otp-export',
			array( $this, 'display_export_screen' )
		);

		// This is required to enqueue style however, we are not registering/rendering this
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
				'id'          => 'ewd-otp-export',
				'title'       => __( 'Export', 'order-tracking' ),
				'menu_title'  => __( 'Export', 'order-tracking' ),
				'parent_menu' => 'ewd-otp-orders',
				'description' => '',
			)
		);
	}

	public function display_export_screen() {
		global $ewd_otp_controller;

		$export_permission = $ewd_otp_controller->permissions->check_permission( 'export' );

		?>
		<div class="wrap sap-settings-page">
			<h1>Export</h1>

			<?php $this->display_messages(); ?>

			<?php if ( $export_permission ) { ?> 
				<form method='post'>
					<?php
						wp_nonce_field( 'EWD_OTP_Export', 'EWD_OTP_Export_Nonce' );

						// Fetch order status
						$order_status_list = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );
						$customer_list     = $this->get_customer_list();
						$sales_rep_list    = $this->get_sales_rep_list();

						// set type being exported to orders if not set
						$_POST['type-of-record'] = isset( $_POST['type-of-record'] ) ? $_POST['type-of-record'] : 'order';
					?>

					<table class="form-table ewd-otp-export-filters" role="presentation">

						<tr class="row type-of-record">
							<th><?php _e( 'Type of Record', 'order-tracking' ); ?></th>
							<td>
								<fieldset>
									<label for="type-of-record-order" class="sap-admin-input-container">
										<input type="radio" name="type-of-record" value="order" id="type-of-record-order" <?php echo $_POST['type-of-record'] == 'order' ? 'checked' : ''; ?>>
										<span class="sap-admin-radio-button"></span>
										<span><?php _e( 'Order', 'order-tracking' ); ?></span>
									</label>
									<label for="type-of-record-customer" class="sap-admin-input-container">
										<input type="radio" name="type-of-record" value="customer" id="type-of-record-customer" <?php echo $_POST['type-of-record'] == 'customer' ? 'checked' : ''; ?>>
										<span class="sap-admin-radio-button"></span>
										<span>Customer</span>
									</label>
									<label for="type-of-record-sales-rep" class="sap-admin-input-container">
										<input type="radio" name="type-of-record" value="sales-rep" id="type-of-record-sales-rep" <?php echo $_POST['type-of-record'] == 'sales-rep' ? 'checked' : ''; ?>>
										<span class="sap-admin-radio-button"></span>
										<span>Sales Rep</span>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr class="row by-status <?php echo $_POST['type-of-record'] != 'order' ? 'ewd-otp-hidden' : ''; ?>" >
							<th><?php _e( 'Orders by Status', 'order-tracking' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $order_status_list as $record ) : ?>
										<label for="type-of-record-<?php echo esc_attr( $record->status ); ?>" class="sap-admin-input-container">
											<input type="checkbox" name="order-by-status[]" value="<?php echo $record->status; ?>" id="type-of-record-<?php echo $record->status; ?>" <?php echo isset( $_POST['order-by-status'] ) && in_array( $record->status, $_POST['order-by-status'] ) ? 'checked' : ''; ?>>
											<span class="sap-admin-checkbox"></span>
											<span><?php echo esc_html( $record->status ); ?></span>
										</label>
									<?php endforeach ?>
								</fieldset>
							</td>
						</tr>

						<tr class="row date-range <?php echo $_POST['type-of-record'] != 'order' ? 'ewd-otp-hidden' : ''; ?>">
							<th><?php _e( 'Orders from a Specific Date Range', 'order-tracking' ); ?></th>
							<td>
								<fieldset>
									<label for="date-range-today" class="sap-admin-input-container">
										<input type="radio" name="date_range" value="today" id="date-range-today" <?php echo isset( $_POST['date_range'] ) && 'today' == $_POST['date_range'] ? 'checked' : ''; ?>>
										<span class="sap-admin-radio-button"></span>
										<span><?php /* translators: %s is today's date. */ printf( esc_html__( 'Today (%s)', 'order-tracking' ), esc_html( wp_date( get_option( 'date_format' ), strtotime( 'today' ) ) ) ); ?></span>
									</label>
									<label for="date-range-week" class="sap-admin-input-container">
										<input type="radio" name="date_range" value="week" id="date-range-week" <?php echo isset( $_POST['date_range'] ) && 'week' == $_POST['date_range'] ? 'checked' : ''; ?>>
										<span class="sap-admin-radio-button"></span>
										<span><?php /* translators: 1: first date of the current week, 2: last date of the current week. */ printf( esc_html__( 'This Week (%1$s - %2$s)', 'order-tracking' ), esc_html( wp_date( get_option( 'date_format' ), strtotime( 'monday this week' ) ) ), esc_html( wp_date( get_option( 'date_format' ), strtotime( 'sunday this week' ) ) ) ); ?></span>
									</label>
									<label for="date-range-past" class="sap-admin-input-container">
										<input type="radio" name="date_range" value="past" id="date-range-past" <?php echo isset( $_POST['date_range'] ) && 'past' == $_POST['date_range'] ? 'checked' : ''; ?>>
										<span class="sap-admin-radio-button"></span>
										<span><?php _e( 'Past', 'order-tracking' ); ?></span>
									</label>
									<label for="date-range-from"><?php _e( 'From', 'order-tracking' ); ?></label>
									<?php // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Administrative exports verify their nonce; public exports require a signed collection proof before this path. ?>
									<input type="date" name="start_date" id="date-range-from" value="<?php echo isset( $_POST['start_date'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) ) : ''; ?>">
									<label for="date-range-to"><?php _e( 'To', 'order-tracking' ); ?></label>
									<?php // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Administrative exports verify their nonce; public exports require a signed collection proof before this path. ?>
									<input type="date" name="end_date" id="date-range-to" value="<?php echo isset( $_POST['end_date'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) ) : ''; ?>">
								</fieldset>
							</td>
						</tr>

						<tr class="row customer-list <?php echo $_POST['type-of-record'] != 'order' ? 'ewd-otp-hidden' : ''; ?>">
							<th><?php _e( 'Orders from specific Customer(s)', 'order-tracking' ); ?></th>
							<td>
								<fieldset>
									<select name="order-of-customer[]" multiple>
										<?php
										foreach ( $customer_list as $record ) {
											$selected = isset( $_POST['order-of-customer'] ) && in_array( $record->id, $_POST['order-of-customer'] ) ? 'selected' : '';
											echo "<option value='{$record->id}' {$selected}>{$record->id} - {$record->name} ( {$record->email} )</option>";
										}
										?>
									</select>
								</fieldset>
							</td>
						</tr>

						<tr class="row sales-rep-list <?php echo $_POST['type-of-record'] != 'order' ? 'ewd-otp-hidden' : ''; ?>">
							<th><?php _e( 'Orders for specific Sales Rep(s)', 'order-tracking' ); ?></th>
							<td>
								<fieldset>
									<select name="order-of-sales-rep[]" multiple>
										<?php
										foreach ( $sales_rep_list as $record ) {
											$selected = isset( $_POST['order-of-sales-rep'] ) && in_array( $record->id, $_POST['order-of-sales-rep'] ) ? 'selected' : '';
											$l_name   = ! empty( $record->last_name ) ? " {$record->last_name}" : '';
											echo "<option value='{$record->id}' {$selected}>{$record->id} - {$record->first_name}{$l_name} ( {$record->email} )</option>";
										}
										?>
									</select>
								</fieldset>
							</td>
						</tr>

					</table>

					<label for="ewd-otp-export-format"><?php esc_html_e( 'Format', 'order-tracking' ); ?></label>
					<select name="format-type" id="ewd-otp-export-format">
						<option value="csv">CSV</option>
						<option value="xls">XLS</option>
					</select>
					<input type='submit' name='ewd_otp_export' value='Export to Spreadsheet' class='button button-primary'>
					&nbsp;
					<a href="" class='button'><?php _e( 'Clear Form', 'order-tracking' ); ?></a>

				</form>
			<?php } else { ?>
				<div class='ewd-otp-premium-locked'>
					<a href="https://www.etoilewebdesign.com/license-payment/?Selected=OTP&Quantity=1&utm_source=otp_export" target="_blank">Upgrade</a> to the premium version to use this feature
				</div>
			<?php } ?>
		</div>
		<?php
	}


	public function run_export() {

		global $ewd_otp_controller;

		if ( ( '' === $this->frontend_scope && ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) ) ||
			! $ewd_otp_controller->permissions->check_permission( 'export' ) ) {
			return;
		}
		if ( $this->nonce_check && ! isset( $_POST['EWD_OTP_Export_Nonce'] ) ) {
			return;
		}

		if ( $this->nonce_check && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['EWD_OTP_Export_Nonce'] ) ), 'EWD_OTP_Export' ) ) {
			return;
		}

		$records = array(
			'header' => array(),
			'data'   => array(),
		);

		$record_type = '' !== $this->frontend_scope
			? 'order'
			: ( isset( $_POST['type-of-record'] ) ? sanitize_key( wp_unslash( $_POST['type-of-record'] ) ) : 'order' );

		if ( 'order' === $record_type ) {
			$records = $this->get_order_data();
		} elseif ( 'customer' === $record_type ) {
			$records = $this->get_customer_data();
		} elseif ( 'sales-rep' === $record_type ) {
			$records = $this->get_sales_rep_data();
		}

		if ( 1 > count( $records['data'] ) ) {
			$this->warning( 'No records found to export!' );
			return;
		}

		$format = isset( $_POST['format-type'] ) && 'xls' === sanitize_key( wp_unslash( $_POST['format-type'] ) ) ? 'xls' : 'csv';
		if ( 'xls' === $format ) {
			$runtime = ewdotpSpreadsheetRuntime::load();
			if ( is_wp_error( $runtime ) ) {
				$this->warning( $runtime->get_error_message() );
				return;
			}
		}

		if ( ob_get_level() ) {
			ob_clean();
		}
		header( 'Content-Type: ' . ( 'xls' === $format ? 'application/vnd.ms-excel' : 'text/csv; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $records['filename'] . '.' . $format . '"' );
		header( 'Cache-Control: max-age=0' );
		if ( 'xls' === $format ) {
			$spreadsheet = self::build_spreadsheet( $records );
			$writer      = new Xls( $spreadsheet );
			$writer->save( 'php://output' );
			$spreadsheet->disconnectWorksheets();
		} else {
			$output = fopen( 'php://output', 'w' );
			self::write_csv( $records, $output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is a response stream, not a filesystem file.
			fclose( $output );
		}
		die();
	}

	/**
	 * Stream CSV without PhpSpreadsheet's PHP 8.5-deprecated column iteration.
	 *
	 * @param array<string,mixed> $records Export data.
	 * @param resource            $stream  Writable CSV stream.
	 * @return void
	 */
	public static function write_csv( $records, $stream ) {
		fputcsv( $stream, array_map( array( __CLASS__, 'escape_spreadsheet_value' ), $records['header'] ), ',', '"', '' );
		foreach ( $records['data'] as $row ) {
			fputcsv( $stream, array_map( array( __CLASS__, 'escape_spreadsheet_value' ), $row ), ',', '"', '' );
		}
	}

	/**
	 * Build the same spreadsheet used by the download path, including columns beyond Z.
	 *
	 * @param array<string,mixed> $records Export data.
	 * @return Spreadsheet
	 */
	public static function build_spreadsheet( $records ) {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->setActiveSheetIndex( 0 );

		// Adding header
		$row = 1;
		$col = 1;
		foreach ( $records['header'] as $value ) {
			$spreadsheet->getActiveSheet()->setCellValueByColumnAndRow( $col, $row, self::escape_spreadsheet_value( $value ) );
			++$col;
		}

		// start while loop to get data
		$row = 2;
		foreach ( $records['data'] as $record ) {
			$col = 1;
			foreach ( $record as $value ) {
				$spreadsheet->getActiveSheet()->setCellValueByColumnAndRow( $col, $row, self::escape_spreadsheet_value( $value ) );
				++$col;
			}
			++$row;
		}

		return $spreadsheet;
	}

	/**
	 * Prevent user-controlled text from becoming an active spreadsheet formula.
	 *
	 * @param mixed $value Spreadsheet cell value.
	 * @return mixed
	 * @since 3.6.0
	 */
	public static function escape_spreadsheet_value( $value ) {

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}
	/**
	 * Build the safely projected order export data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_order_data() {
		global $ewd_otp_controller;

		// Front-end exports intentionally omit private/admin identity and contact fields.
		$order_header = '' !== $this->frontend_scope
			? array( 'Name', 'Number', 'Order Status', 'Order Status Updated (Read-Only)', 'Location', 'Notes Public' )
			: array( 'Name', 'Number', 'Order Status', 'Order Status Updated (Read-Only)', 'Location', 'Display', 'Notes Public', 'Notes Private', 'Email', 'Phone Number', 'Customer', 'Sales Rep' );
		// Add custom fields to column headers
		$custom_fields = $ewd_otp_controller->settings->get_order_custom_fields();
		if ( '' !== $this->frontend_scope ) {
			$custom_fields = array_filter(
				$custom_fields,
				function ( $custom_field ) {

					return ! empty( $custom_field->front_end_display );
				}
			);
		}
		foreach ( $custom_fields as $custom_field ) {

			$order_header[] = $custom_field->name;
		}

		$args = array(
			'display'         => true,
			'orders_per_page' => -1,
		);

		// Order status
		if ( isset( $_POST['order-by-status'] ) && 0 < count( $_POST['order-by-status'] ) ) {

			$args['status'] = array_map( 'sanitize_text_field', $_POST['order-by-status'] );
		}

		// Order for Date-range
		if ( ! empty( $this->after ) ) {

			// Used to let sales-rep and customers download their data from front-end
			$args['after'] = $this->after;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Administrative exports verify their nonce; public exports require a signed collection proof before this path.
		} elseif ( isset( $_POST['date_range'] ) ) {

			$args['date_range'] = sanitize_text_field( $_POST['date_range'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Administrative exports verify their nonce; public exports require a signed collection proof before this path.
		} elseif ( isset( $_POST['start_date'] ) || isset( $_POST['end_date'] ) ) {

			// just to pass if in order_manager->prepare_args()
			$args['date_range'] = 'custom';
			$args['start_date'] = sanitize_text_field( $_POST['start_date'] );
			$args['end_date']   = sanitize_text_field( $_POST['end_date'] );
		}

		// Order of Customer('s)
		if ( ! empty( $this->customer_id ) ) {

			// Used to let sales-rep and customers download their data from front-end
			$args['customer'] = $this->customer_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Administrative exports verify their nonce; public exports require a signed collection proof before this path.
		} elseif ( isset( $_POST['order-of-customer'] ) && 0 < count( $_POST['order-of-customer'] ) ) {

			$args['customer'] = array_map( 'sanitize_text_field', $_POST['order-of-customer'] );
		}

		// Order of Sales Rep('s)
		if ( ! empty( $this->sales_rep_id ) ) {

			// Used to let sales-rep and customers download their data from front-end
			$args['sales_rep'] = $this->sales_rep_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Administrative exports verify their nonce; public exports require a signed collection proof before this path.
		} elseif ( isset( $_POST['order-of-sales-rep'] ) ) {

			$args['sales_rep'] = array_map( 'sanitize_text_field', $_POST['order-of-sales-rep'] );
		}

		// fetching orders
		$orders = $ewd_otp_controller->order_manager->get_matching_orders( $args );

		$data = array();

		foreach ( $orders as $order ) {

			$record = '' !== $this->frontend_scope
			? array( $order->name, $order->number, $order->status, $order->status_updated, $order->location, $order->notes_public )
				: array( $order->name, $order->number, $order->status, $order->status_updated, $order->location, ( $order->display ? 'Yes' : 'No' ), $order->notes_public, $order->notes_private, $order->email, $order->phone_number, max( $order->customer, 0 ), max( $order->sales_rep, 0 ) );
			// Adding custom field data
			foreach ( $custom_fields as $custom_field ) {

				$record[] = $ewd_otp_controller->order_manager->get_field_value( $custom_field->id, $order->id );
			}

			$data[] = $record;
		}

		return array(
			'header'   => $order_header,
			'data'     => $data,
			'filename' => 'order_export',
		);
	}

	public function get_customer_data() {
		global $ewd_otp_controller;

		// Print out the regular customer field labels
		$customer_header = array(
			'Customer ID',
			'Number',
			'Name',
			'Email',
			'Sales Rep ID',
			'WP ID',
			'FEUP ID',
		);

		// Add custom fields to column headers
		$custom_fields = $ewd_otp_controller->settings->get_customer_custom_fields();

		foreach ( $custom_fields as $custom_field ) {

			$customer_header[] = $custom_field->name;
		}

		$args = array(
			'customers_per_page' => -1,
		);

		// fetching customers
		$customers = $ewd_otp_controller->customer_manager->get_matching_customers( $args );

		$data = array();

		foreach ( $customers as $customer ) {

			$record = array(
				$customer->id,
				$customer->number,
				$customer->name,
				$customer->email,
				max( $customer->sales_rep, 0 ),
				max( $customer->wp_id, 0 ),
				max( $customer->feup_id, 0 ),
			);

			// Adding custom field data
			foreach ( $custom_fields as $custom_field ) {

				$record[] = $ewd_otp_controller->customer_manager->get_field_value( $custom_field->id, $customer->id );
			}

			$data[] = $record;
		}

		return array(
			'header'   => $customer_header,
			'data'     => $data,
			'filename' => 'customer_export',
		);
	}

	public function get_sales_rep_data() {
		global $ewd_otp_controller;

		// Print out the regular sales rep field labels
		$sales_rep_header = array(
			'Sales Rep ID',
			'Number',
			'First Name',
			'Last Name',
			'Email',
			'Phone Number',
			'WP ID',
		);

		// Add custom fields to column headers
		$custom_fields = $ewd_otp_controller->settings->get_sales_rep_custom_fields();

		foreach ( $custom_fields as $custom_field ) {

			$sales_rep_header[] = $custom_field->name;
		}

		$args = array(
			'sales_reps_per_page' => -1,
		);

		// fetching sales reps
		$sales_reps = $ewd_otp_controller->sales_rep_manager->get_matching_sales_reps( $args );

		$data = array();

		foreach ( $sales_reps as $sales_rep ) {

			$record = array(
				$sales_rep->id,
				$sales_rep->number,
				$sales_rep->first_name,
				$sales_rep->last_name,
				$sales_rep->email,
				$sales_rep->phone_number,
				max( $sales_rep->wp_id, 0 ),
			);

			// Adding custom field data
			foreach ( $custom_fields as $custom_field ) {

				$record[] = $ewd_otp_controller->sales_rep_manager->get_field_value( $custom_field->id, $sales_rep->id );
			}

			$data[] = $record;
		}

		return array(
			'header'   => $sales_rep_header,
			'data'     => $data,
			'filename' => 'sales_rep_export',
		);
	}

	public function get_customer_list() {
		global $ewd_otp_controller;

		$args = array(
			'orderby'            => 'Customer_Name',
			'order'              => 'asc',
			'customers_per_page' => -1,
		);

		return $ewd_otp_controller->customer_manager->get_matching_customers( $args );
	}

	public function get_sales_rep_list() {
		global $ewd_otp_controller;

		$args = array(
			'orderby'             => 'Sales_Rep_First_Name',
			'order'               => 'asc',
			'sales_reps_per_page' => -1,
		);

		return $ewd_otp_controller->sales_rep_manager->get_matching_sales_reps( $args );
	}

	public function display_messages() {

		foreach ( $this->messages as $type => $msgs ) {

			echo "<div class='notice notice-{$type}''>";
			foreach ( $msgs as $msg ) {
				echo "<p>{$msg}</p>";
			}
			echo '</div>';
		}
	}

	public function warning( $msg ) {

		if ( ! isset( $this->messages['warning'] ) ) {

			$this->messages['warning'] = array();
		}

		$this->messages['warning'][] = $msg;
	}

	public function error( $msg ) {

		if ( ! isset( $this->messages['error'] ) ) {

			$this->messages['error'] = array();
		}

		$this->messages['error'][] = $msg;
	}

	public function success( $msg ) {

		if ( ! isset( $this->messages['success'] ) ) {

			$this->messages['success'] = array();
		}

		$this->messages['success'][] = $msg;
	}
}
