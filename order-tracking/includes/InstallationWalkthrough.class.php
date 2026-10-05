<?php

/**
 * Class to handle everything related to the walk-through that runs on plugin activation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ewdotpInstallationWalkthrough {

	public function __construct() {

		add_action( 'admin_menu', array( $this, 'register_install_screen' ) );
		add_action( 'admin_head', array( $this, 'hide_install_screen_menu_item' ) );
		add_action( 'admin_init', array( $this, 'redirect' ), 9999 );

		add_action( 'admin_head', array( $this, 'admin_enqueue' ) );

		add_action( 'wp_ajax_ewd_otp_welcome_add_status', array( $this, 'add_status' ) );
		add_action( 'wp_ajax_ewd_otp_welcome_apply_workflow', array( $this, 'apply_workflow' ) );
		add_action( 'wp_ajax_ewd_otp_welcome_add_tracking_page', array( $this, 'add_tracking_page' ) );
		add_action( 'wp_ajax_ewd_otp_welcome_set_options', array( $this, 'set_options' ) );
		add_action( 'wp_ajax_ewd_otp_welcome_add_order', array( $this, 'add_order' ) );
	}

	/**
	 * On activation, redirect the user if they haven't used the plugin before
	 *
	 * @since 3.0.0
	 */
	public function redirect() {
		global $ewd_otp_controller;

		if ( ! get_transient( 'ewd-otp-getting-started' ) ) {
			return;
		}

		delete_transient( 'ewd-otp-getting-started' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET value controls read-only administrative routing; mutations use the walkthrough AJAX nonce.
		if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		if ( ! empty( $ewd_otp_controller->order_manager->get_matching_orders( array() ) ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'index.php?page=ewd-otp-getting-started' ) );
		exit;
	}

	/**
	 * Create the installation admin page
	 *
	 * @since 3.0.0
	 */
	public function register_install_screen() {

		add_dashboard_page(
			esc_html__( 'Order Tracking - Welcome!', 'order-tracking' ),
			esc_html__( 'Order Tracking - Welcome!', 'order-tracking' ),
			'manage_options',
			'ewd-otp-getting-started',
			array( $this, 'display_install_screen' )
		);
	}

	/**
	 * Hide the installation admin page from the WordPress sidebar menu
	 *
	 * @since 3.0.0
	 */
	public function hide_install_screen_menu_item() {

		remove_submenu_page( 'index.php', 'ewd-otp-getting-started' );
	}

	/**
	 * Lets the user create the statuses they want to assign to their orders
	 *
	 * @since 3.0.0
	 */
	public function add_status() {

		global $ewd_otp_controller;

		// Authenticate request
		if ( ! check_ajax_referer( 'ewd-otp-getting-started', 'nonce' )
			or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
		) {
			ewdotpHelper::admin_nopriv_ajax();
		}

		$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

		$status_name       = isset( $_POST['status_name'] ) ? sanitize_text_field( $_POST['status_name'] ) : '';
		$status_percentage = isset( $_POST['status_percentage'] ) ? sanitize_text_field( $_POST['status_percentage'] ) : '';

		if ( '' === $status_name || ! is_numeric( $status_percentage ) || (float) $status_percentage < 0 || (float) $status_percentage > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a status name and a numeric percentage from 0 to 100.', 'order-tracking' ) ), 400 );
		}

		foreach ( $statuses as $status ) {
			if ( 0 === strcasecmp( trim( (string) $status->status ), $status_name ) ) {
				wp_send_json_error( array( 'message' => __( 'That status name is already configured.', 'order-tracking' ) ), 409 );
			}
		}

		$statuses[] = array(
			'status'     => $status_name,
			'percentage' => $status_percentage,
			'email'      => '',
			'internal'   => 'no',
		);

		$ewd_otp_controller->settings->set_setting( 'statuses', json_encode( $statuses ) );

		$ewd_otp_controller->settings->save_settings();

		wp_send_json_success(
			array(
				'status'     => $status_name,
				'percentage' => (float) $status_percentage,
			)
		);
	}

	/** Apply a starter workflow only after an explicit replacement confirmation. */
	public function apply_workflow() {

		global $ewd_otp_controller;

		if ( ! check_ajax_referer( 'ewd-otp-getting-started', 'nonce' ) || ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) ) ) {
			ewdotpHelper::admin_nopriv_ajax();
		}

		$key     = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
		$current = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );
		if ( ! empty( $current ) && empty( $_POST['confirm_replace'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Confirm that the current status list may be replaced.', 'order-tracking' ) ), 409 );
		}

		$notification_id = '';
		foreach ( $current as $status ) {
			if ( ! empty( $status->email ) ) {
				$notification_id = $status->email;
				break;
			}
		}

		$statuses = $ewd_otp_controller->workflow_templates->get_statuses( $key, $notification_id );
		if ( empty( $statuses ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid workflow template.', 'order-tracking' ) ), 400 );
		}

		$ewd_otp_controller->settings->set_setting( 'statuses', wp_json_encode( $statuses ) );
		$ewd_otp_controller->settings->save_settings();

		wp_send_json_success( array( 'statuses' => $statuses ) );
	}
	/**
	 * Adds a new Order Tracking shortcode page
	 *
	 * @since 3.0.0
	 */
	public function add_tracking_page() {
		global $ewd_otp_controller;

		// Authenticate request
		if ( ! check_ajax_referer( 'ewd-otp-getting-started', 'nonce' )
			or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
		) {
			ewdotpHelper::admin_nopriv_ajax();
		}

		$args = array(
			'post_title'   => isset( $_POST['tracking_page_title'] ) ? sanitize_text_field( $_POST['tracking_page_title'] ) : '',
			'post_content' => '<!-- wp:paragraph --><p> [tracking-form] </p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		$page_id = wp_insert_post( $args, true );
		if ( is_wp_error( $page_id ) ) {
			wp_send_json_error( array( 'message' => __( 'The tracking page could not be created.', 'order-tracking' ) ), 500 );
		}

		$ewd_otp_controller->settings->set_setting( 'tracking-page-url', get_permalink( $page_id ) );
		$ewd_otp_controller->settings->save_settings();

		wp_send_json_success(
			array(
				'page_id' => absint( $page_id ),
				'url'     => get_permalink( $page_id ),
			)
		);
	}

	/**
	 * Set a number of key options selected during the walk-through process
	 *
	 * @since 3.0.0
	 */
	public function set_options() {
		global $ewd_otp_controller;

		// Authenticate request
		if ( ! check_ajax_referer( 'ewd-otp-getting-started', 'nonce' )
			or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
		) {
			ewdotpHelper::admin_nopriv_ajax();
		}

		$ewd_otp_options = get_option( 'ewd-otp-settings' );

		if ( isset( $_POST['order_information'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON is decoded and every retained member is sanitized below.
			$order_information                    = json_decode( wp_unslash( $_POST['order_information'] ) );
			$ewd_otp_options['order-information'] = is_array( $order_information ) ? array_map( 'sanitize_text_field', $order_information ) : array(); }
		if ( isset( $_POST['email_frequency'] ) ) {
			$ewd_otp_options['email-frequency'] = sanitize_text_field( wp_unslash( $_POST['email_frequency'] ) ); }
		if ( isset( $_POST['form_instructions'] ) ) {
			$ewd_otp_options['form-instructions'] = sanitize_textarea_field( wp_unslash( $_POST['form_instructions'] ) ); }
		if ( isset( $_POST['hide_blank_fields'] ) ) {
			$ewd_otp_options['hide-blank-fields'] = sanitize_text_field( wp_unslash( $_POST['hide_blank_fields'] ) ); }

		update_option( 'ewd-otp-settings', $ewd_otp_options );

		exit();
	}

	/**
	 * Add in a new order
	 *
	 * @since 3.0.0
	 */
	public function add_order() {
		global $ewd_otp_controller;

		// Authenticate request
		if ( ! check_ajax_referer( 'ewd-otp-getting-started', 'nonce' )
			or ! current_user_can( $ewd_otp_controller->settings->get_setting( 'access-role' ) )
		) {
			ewdotpHelper::admin_nopriv_ajax();
		}

		$order = new ewdotpOrder();

		$order->display = true;

		$order->name   = isset( $_POST['order_name'] ) ? sanitize_text_field( $_POST['order_name'] ) : '';
		$order->number = isset( $_POST['order_number'] ) ? sanitize_text_field( $_POST['order_number'] ) : '';
		$order->email  = isset( $_POST['order_email'] ) ? sanitize_email( $_POST['order_email'] ) : '';
		$order->status = $order->external_status = isset( $_POST['order_status'] ) ? sanitize_text_field( $_POST['order_status'] ) : '';

		$order->insert_order();

		$order->insert_order_status();

		exit();
	}

	/**
	 * Enqueue the admin assets necessary to run the walk-through and display it nicely
	 *
	 * @since 3.0.0
	 */
	public function admin_enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET value controls read-only administrative routing; mutations use the walkthrough AJAX nonce.
		if ( ! isset( $_GET['page'] ) || 'ewd-otp-getting-started' !== $_GET['page'] ) {
			return; }

		wp_enqueue_style( 'ewd-otp-admin-css', EWD_OTP_PLUGIN_URL . '/assets/css/ewd-otp-admin.css', array(), EWD_OTP_VERSION );
		wp_enqueue_style( 'ewd-otp-sap-admin-css', EWD_OTP_PLUGIN_URL . '/lib/simple-admin-pages/css/admin.css', array(), EWD_OTP_VERSION );
		wp_enqueue_style( 'ewd-otp-welcome-screen', EWD_OTP_PLUGIN_URL . '/assets/css/ewd-otp-welcome-screen.css', array(), EWD_OTP_VERSION );
		wp_enqueue_style( 'ewd-otp-admin-settings-css', EWD_OTP_PLUGIN_URL . '/lib/simple-admin-pages/css/admin-settings.css', array(), EWD_OTP_VERSION );

		wp_enqueue_script( 'ewd-otp-getting-started', EWD_OTP_PLUGIN_URL . '/assets/js/ewd-otp-welcome-screen.js', array( 'jquery' ), EWD_OTP_VERSION );
		wp_enqueue_script( 'ewd-otp-admin-settings-js', EWD_OTP_PLUGIN_URL . '/lib/simple-admin-pages/js/admin-settings.js', array( 'jquery' ), EWD_OTP_VERSION );
		wp_enqueue_script( 'ewd-otp-admin-spectrum-js', EWD_OTP_PLUGIN_URL . '/lib/simple-admin-pages/js/spectrum.js', array( 'jquery' ), EWD_OTP_VERSION );

		wp_localize_script(
			'ewd-otp-getting-started',
			'ewd_otp_getting_started',
			array(
				'nonce'            => wp_create_nonce( 'ewd-otp-getting-started' ),
				'confirm_replace'  => __( 'This replaces the current status list. Existing orders keep their saved statuses. Continue?', 'order-tracking' ),
				'request_error'    => __( 'The request could not be completed. Please try again.', 'order-tracking' ),
				'workflow_applied' => __( 'Workflow template applied.', 'order-tracking' ),
			)
		);
	}

	/** Return local, non-network tracking-page health information. */
	private function get_tracking_health() {

		global $ewd_otp_controller;

		$url         = (string) $ewd_otp_controller->settings->get_setting( 'tracking-page-url' );
		$mode        = $ewd_otp_controller->order_access->get_mode();
		$mode_labels = array(
			ewdotpOrderAccess::MODE_PUBLIC        => __( 'Public', 'order-tracking' ),
			ewdotpOrderAccess::MODE_EMAIL         => __( 'Email Verification', 'order-tracking' ),
			ewdotpOrderAccess::MODE_REQUIRE_LOGIN => __( 'Require Login', 'order-tracking' ),
		);

		$result = array(
			'access_mode' => isset( $mode_labels[ $mode ] ) ? $mode_labels[ $mode ] : $mode,
			'message'     => '',
		);
		if ( '' === $url ) {
			$result['message'] = __( 'No tracking page is configured. Create or select one before generating tracking links.', 'order-tracking' );
			return $result;
		}
		if ( ! wp_http_validate_url( $url ) ) {
			$result['message'] = __( 'The configured tracking-page URL is invalid.', 'order-tracking' );
			return $result;
		}

		$page_id = url_to_postid( $url );
		if ( $page_id ) {
			$content = (string) get_post_field( 'post_content', $page_id );
			if ( ! has_shortcode( $content, 'tracking-form' ) && ! has_block( 'order-tracking/ewd-otp-display-tracking-form-block', $content ) ) {
				$result['message'] = __( 'The configured local page does not contain a supported Tracking Form shortcode or block.', 'order-tracking' );
				return $result;
			}
		}

		$result['message'] = __( 'The tracking-page URL is configured and usable for tracking links.', 'order-tracking' );
		return $result;
	}

	/**
	 * Output the HTML of the walk-through screen
	 *
	 * @since 3.0.0
	 */
	public function display_install_screen() {

		global $ewd_otp_controller;

		$statuses = ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) );

		$order_information  = $ewd_otp_controller->settings->get_setting( 'order-information' );
		$email_frequency    = $ewd_otp_controller->settings->get_setting( 'email-frequency' );
		$form_instructions  = $ewd_otp_controller->settings->get_setting( 'form-instructions' );
		$hide_blank_fields  = $ewd_otp_controller->settings->get_setting( 'hide-blank-fields' );
		$workflow_templates = $ewd_otp_controller->workflow_templates->get_templates();
		$status_warnings    = $ewd_otp_controller->workflow_templates->validate( $statuses, in_array( 'order_graphic', (array) $order_information, true ) );
		$tracking_health    = $this->get_tracking_health();
		?>

		<div class='ewd-otp-welcome-screen'>
			
			<div class='ewd-otp-welcome-screen-header'>
				<h1><?php _e( 'Welcome to Order Tracking', 'order-tracking' ); ?></h1>
				<p><?php _e( 'Thanks for choosing Order Tracking! The following will help you get started with the setup by setting up your statuses, creating an order tracking page and configuring a few key options.', 'order-tracking' ); ?></p>
			</div>

			<div class="notice notice-info ewd-otp-tracking-health">
				<p><strong><?php esc_html_e( 'Tracking Setup', 'order-tracking' ); ?></strong></p>
				<?php /* translators: %s: configured access mode. */ ?>
				<p><?php echo esc_html( $tracking_health['message'] ); ?> <?php echo esc_html( sprintf( __( 'Access mode: %s.', 'order-tracking' ), $tracking_health['access_mode'] ) ); ?></p>
			</div>
			<div class='ewd-otp-welcome-screen-box ewd-otp-welcome-screen-statuses ewd-otp-welcome-screen-open' data-screen='statuses'>
				<h2><?php _e( '1. Statuses', 'order-tracking' ); ?></h2>
				<div class='ewd-otp-welcome-screen-box-content'>
			<p><?php esc_html_e( 'Create statuses or edit the default statuses so that they\'re meaningful for your visitors. “% Complete” controls the progress graphic; it does not create automatic transitions. Internal statuses are not customer-visible.', 'order-tracking' ); ?></p>
					<p><label for="ewd-otp-workflow-template"><strong><?php esc_html_e( 'Starter workflow', 'order-tracking' ); ?></strong></label><br>
					<select id="ewd-otp-workflow-template">
						<?php
						foreach ( $workflow_templates as $key => $template ) :
							?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $template['name'] ); ?> — <?php echo esc_html( $template['description'] ); ?></option>
						<?php endforeach; ?>
					</select> <button type="button" class="button ewd-otp-apply-workflow-template"><?php esc_html_e( 'Replace statuses with template', 'order-tracking' ); ?></button></p>
					<div class="ewd-otp-workflow-message" role="status" aria-live="polite"></div>
					<?php
					if ( $status_warnings ) :
						?>
						<div class="notice notice-warning inline"><ul>
						<?php
						foreach ( $status_warnings as $warning ) :
							?>
						<li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
					<div class='ewd-otp-welcome-screen-statuses-table'>
						<table class="wp-list-table striped widefat tags sorttable status-list">
							<thead>
								<tr>
					<th><?php esc_html_e( 'Status', 'order-tracking' ); ?></th>
					<th><?php esc_html_e( '% Complete', 'order-tracking' ); ?></th>
								</tr>
							</thead>
							<tfoot>
								<tr>
					<th><?php esc_html_e( 'Status', 'order-tracking' ); ?></th>
					<th><?php esc_html_e( '% Complete', 'order-tracking' ); ?></th>
								</tr>
							</tfoot>
							
							<tbody>
								<?php foreach ( $statuses as $key => $status ) { ?>
									<tr class="list-item edit-status-item">
										<td class="status"><input type='text' class='ewd-otp-welcome-edit-status-input' name='status[]' value='<?php echo esc_attr( $status->status ); ?>' disabled /></td>
										<td class="status-completed"><input type='text' class='ewd-otp-welcome-edit-status-input ewd-otp-edit-status-percentage-input' name='status_percentages[]' value='<?php echo esc_attr( $status->percentage ); ?>' disabled /></td>
									</tr>	
								<?php } ?>
							</tbody>
		
						</table>
					</div>

					<table class='form-table ewd-otp-welcome-screen-create-status'>
						<tr class='ewd-otp-welcome-screen-add-status-name ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Status Name', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<input type='text'>
							</td>
						</tr>
						<tr class='ewd-otp-welcome-screen-add-status-percentage ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Percentage Complete', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<input type='text'>
							</td>
						</tr>
						<tr>
							<th scope='row'></th>
							<td>
								<div class='ewd-otp-welcome-screen-add-status-button'><?php _e( 'Add Status', 'order-tracking' ); ?></div>
							</td>
						</tr>
					</table>

					<div class='ewd-otp-welcome-clear'></div>

					<div class='ewd-otp-welcome-screen-next-button' data-nextaction='tracking-page'><?php _e( 'Next', 'order-tracking' ); ?></div>

					<div class='ewd-otp-welcome-clear'></div>
				</div>
			</div>

			<div class='ewd-otp-welcome-screen-box ewd-otp-welcome-screen-tracking-page' data-screen='tracking-page'>
				<h2><?php _e( '2. Add an Order Tracking Page', 'order-tracking' ); ?></h2>
				<div class='ewd-otp-welcome-screen-box-content'>
					<p><?php _e( 'You can create a dedicated tracking page below, or skip this step and add your tracking form to a page you\'ve already created manually.', 'order-tracking' ); ?></p>
					<table class='form-table ewd-otp-welcome-screen-booking-page'>
						<tr class='ewd-otp-welcome-screen-add-tracking-page-name ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Page Title', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<input type='text'>
							</td>
						</tr>
						<tr>
							<th scope='row'></th>
							<td>
								<div class='ewd-otp-welcome-screen-add-tracking-page-button' data-nextaction='options'><?php _e( 'Create Page', 'order-tracking' ); ?></div>
							</td>
						</tr>
					</table>

					<div class='ewd-otp-welcome-clear'></div>
					<div class='ewd-otp-welcome-screen-next-button' data-nextaction='options'><?php _e( 'Next', 'order-tracking' ); ?></div>
					<div class='ewd-otp-welcome-screen-previous-button' data-previousaction='statuses'><?php _e( 'Previous', 'order-tracking' ); ?></div>
					<div class='ewd-otp-clear'></div>
				</div>
			</div>

			<div class='ewd-otp-welcome-screen-box ewd-otp-welcome-screen-options' data-screen='options'>
				<h2><?php _e( '3. Set Key Options', 'order-tracking' ); ?></h2>
				<div class='ewd-otp-welcome-screen-box-content'>
					<p><?php _e( 'Options can always be changed later, but here are a few that a lot of users want to set for themselves.', 'order-tracking' ); ?></p>
					<table class='form-table'>
						<tr>
							<th scope='row'><?php _e( 'Order Information Displayed', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<fieldset>
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_number' <?php echo ( in_array( 'order_number', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Order Number', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_name' <?php echo ( in_array( 'order_name', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Name', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_status' <?php echo ( in_array( 'order_status', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Status', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_location' <?php echo ( in_array( 'order_location', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Location', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_updated' <?php echo ( in_array( 'order_updated', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Updated Date', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_notes' <?php echo ( in_array( 'order_notes', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Notes', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='customer_notes' <?php echo ( in_array( 'customer_notes', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Customer Notes', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='checkbox' name='order_information[]' value='order_graphic' <?php echo ( in_array( 'order_graphic', $order_information ) ? 'checked' : '' ); ?> /><span class='sap-admin-checkbox'></span> <span><?php _e( 'Status Graphic', 'order-tracking' ); ?></span></label><br />
									<p class='description'><?php _e( 'Select what information should be displayed for each order.', 'order-tracking' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope='row'><?php _e( 'Order Email Frequency', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<fieldset>
									<legend class="screen-reader-text"><span>Order Email Frequency</span></legend>
									<label class='sap-admin-input-container'><input type='radio' name='email_frequency' value='change'
									<?php
									if ( 'change' === $email_frequency ) {
										echo "checked='checked'";}
									?>
				/><span class='sap-admin-radio-button'></span> <span><?php esc_html_e( 'On Change', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='radio' name='email_frequency' value='creation'
									<?php
									if ( 'creation' === $email_frequency ) {
										echo "checked='checked'";}
									?>
				/><span class='sap-admin-radio-button'></span> <span><?php esc_html_e( 'On Creation', 'order-tracking' ); ?></span></label><br />
									<label class='sap-admin-input-container'><input type='radio' name='email_frequency' value='never'
									<?php
									if ( 'never' === $email_frequency ) {
										echo "checked='checked'";}
									?>
				/><span class='sap-admin-radio-button'></span> <span><?php esc_html_e( 'Never', 'order-tracking' ); ?></span></label><br />
									<p><?php _e( 'How often should emails be sent to customers about the status of their orders?', 'order-tracking' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope='row'><?php _e( 'Order Form Instructions', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<fieldset>
									<legend class="screen-reader-text"><span>Order Form Instructions</span></legend>
									<label for='form_instructions'></label><textarea class='ewd-otp-textarea' name='form_instructions'> <?php echo esc_textarea( $form_instructions ); ?></textarea><br />
									<p><?php _e( 'The instructions that will display above the order form.', 'order-tracking' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope='row'><?php _e( 'Hide Blank Fields', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<fieldset>
									<div class='sap-admin-hide-radios'>
										<input type='checkbox' name='hide_blank_fields' value='1'>
									</div>
									<label class='sap-admin-switch'>
										<input type='checkbox' class='sap-admin-option-toggle' data-inputname='hide_blank_fields'
										<?php
										if ( '1' === $hide_blank_fields ) {
											echo 'checked'; }
										?>
										>
										<span class='sap-admin-switch-slider round'></span>
									</label>		
									<p class='description'><?php _e( 'Should fields which don\'t have a value (ex. customer name, custom fields) be hidden if they\'re empty?', 'order-tracking' ); ?></p>
								</fieldset>
							</td>
						</tr>
					</table>
		
					<div class='ewd-otp-welcome-screen-save-options-button'><?php _e( 'Save Options', 'order-tracking' ); ?></div>
					<div class='ewd-otp-welcome-clear'></div>
					<div class='ewd-otp-welcome-screen-next-button' data-nextaction='orders'><?php _e( 'Next', 'order-tracking' ); ?></div>
					<div class='ewd-otp-welcome-screen-previous-button' data-previousaction='tracking-page'><?php _e( 'Previous', 'order-tracking' ); ?></div>
					
					<div class='ewd-otp-clear'></div>
				</div>
			</div>

			<div class='ewd-otp-welcome-screen-box ewd-otp-welcome-screen-orders' data-screen='orders'>
				<h2><?php _e( '4. Create an Order', 'order-tracking' ); ?></h2>
				<div class='ewd-otp-welcome-screen-box-content'>
					<p><?php _e( 'Create your first orders. Don\'t worry, you can always add more later.', 'order-tracking' ); ?></p>
					<table class='form-table ewd-otp-welcome-screen-created-categories'>
						<tr class='ewd-otp-welcome-screen-add-order-name ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Order Name', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<input type='text'>
							</td>
						</tr>
						<tr class='ewd-otp-welcome-screen-add-order-number ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Order Number', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<input type='text'>
							</td>
						</tr>
						<tr class='ewd-otp-welcome-screen-add-order-email ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Order Email', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<input type='text'>
							</td>
						</tr>
						<tr class='ewd-otp-welcome-screen-add-order-status ewd-otp-welcome-screen-box-content-divs'>
							<th scope='row'><?php _e( 'Order Status', 'order-tracking' ); ?></th>
							<td class='ewd-otp-welcome-screen-option'>
								<select>
									<?php foreach ( $statuses as $key => $status ) { ?>

										<option value='<?php echo esc_attr( $status->status ); ?>'><?php echo esc_html( $status->status ); ?></option>
									<?php } ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope='row'></th>
							<td>
								<div class='ewd-otp-welcome-screen-add-order-button'><?php _e( 'Add Order', 'order-tracking' ); ?></div>
							</td>
						</tr>
						<tr></tr>
						<tr>
							<td colspan="2">
								<h3><?php _e( 'Created Orders', 'order-tracking' ); ?></h3>
								<table class='ewd-otp-welcome-screen-show-created-orders'>
									<tr>
										<th class='ewd-otp-welcome-screen-show-created-order-name'><?php _e( 'Name', 'order-tracking' ); ?></th>
										<th class='ewd-otp-welcome-screen-show-created-order-number'><?php _e( 'Order Number', 'order-tracking' ); ?></th>
										<th class='ewd-otp-welcome-screen-show-created-order-status'><?php _e( 'Status', 'order-tracking' ); ?></th>
									</tr>
								</table>
							</td>
						</tr>
					</table>

					<div class='ewd-otp-welcome-clear'></div>
					<div class='ewd-otp-welcome-screen-previous-button' data-previousaction='options'><?php _e( 'Previous', 'order-tracking' ); ?></div>
					<div class='ewd-otp-welcome-screen-finish-button'><a href='admin.php?page=ewd-otp-settings'><?php _e( 'Finish', 'order-tracking' ); ?></a></div>
					<div class='ewd-otp-clear'></div>
				</div>
			</div>

			<div class='ewd-otp-welcome-screen-skip-container'>
				<a href='admin.php?page=ewd-otp-settings'><div class='ewd-otp-welcome-screen-skip-button'><?php _e( 'Skip Setup', 'order-tracking' ); ?></div></a>
			</div>
		</div>

		<?php
	}
}
