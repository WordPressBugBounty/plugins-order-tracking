<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpDeactivationSurvey' ) ) {
	/**
	 * Class to handle plugin deactivation survey
	 *
	 * @since 3.0.0
	 */
	class ewdotpDeactivationSurvey {

		public function __construct() {

			add_action( 'current_screen', array( $this, 'maybe_add_survey' ) );
			add_action( 'wp_ajax_ewd_otp_submit_deactivation_survey', array( $this, 'submit_survey' ) );
		}

		public function maybe_add_survey() {
			if ( in_array( get_current_screen()->id, array( 'plugins', 'plugins-network' ), true ) ) {
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_deactivation_scripts' ) );
				add_action( 'admin_footer', array( $this, 'add_deactivation_html' ) );
			}
		}

		public function enqueue_deactivation_scripts() {
			wp_enqueue_style( 'ewd-otp-deactivation-css', EWD_OTP_PLUGIN_URL . '/assets/css/plugin-deactivation.css' );
			wp_enqueue_script( 'ewd-otp-deactivation-js', EWD_OTP_PLUGIN_URL . '/assets/js/plugin-deactivation.js', array( 'jquery' ) );

			wp_localize_script(
				'ewd-otp-deactivation-js',
				'ewd_otp_deactivation_data',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'ewd_otp_deactivation_survey' ),
				)
			);
		}

		/** Process an authorized deactivation survey submission. */
		public function submit_survey() {

			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to submit this survey.', 'order-tracking' ) ), 403 );
			}

			check_ajax_referer( 'ewd_otp_deactivation_survey', 'nonce' );

			$reasons = array(
				1 => 'No longer needed',
				2 => 'Switching to a different plugin',
				3 => 'Could not get the plugin to work',
				4 => 'Temporary deactivation',
				5 => 'Other',
			);
			$code    = isset( $_POST['code'] ) ? absint( $_POST['code'] ) : 0;
			if ( ! isset( $reasons[ $code ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Please select a valid reason.', 'order-tracking' ) ), 400 );
			}

			$response = wp_remote_post(
				'https://www.etoilewebdesign.com/UPCP-Key-Check/Deactivation_Surveys.php',
				array(
					'timeout' => 5,
					'body'    => array(
						'code'         => $code,
						'reason'       => $reasons[ $code ],
						'details'      => isset( $_POST['details'] ) ? sanitize_text_field( wp_unslash( $_POST['details'] ) ) : '',
						'install_time' => absint( get_option( 'ewd-otp-installation-time' ) ),
						'site'         => site_url(),
						'plugin'       => 'Order Tracking',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => __( 'Survey delivery failed.', 'order-tracking' ) ) );
			}

			wp_send_json_success();
		}
		/** Render the deactivation survey dialog. */
		public function add_deactivation_html() {

			$options = array(
				1 => array(
					'title' => esc_html__( 'I no longer need the plugin', 'order-tracking' ),
				),
				2 => array(
					'title'   => esc_html__( 'I\'m switching to a different plugin', 'order-tracking' ),
					'details' => esc_html__( 'Please share which plugin', 'order-tracking' ),
				),
				3 => array(
					'title'   => esc_html__( 'I couldn\'t get the plugin to work', 'order-tracking' ),
					'details' => esc_html__( 'Please share what wasn\'t working', 'order-tracking' ),
				),
				4 => array(
					'title' => esc_html__( 'It\'s a temporary deactivation', 'order-tracking' ),
				),
				5 => array(
					'title'   => esc_html__( 'Other', 'order-tracking' ),
					'details' => esc_html__( 'Please share the reason', 'order-tracking' ),
				),
			);
			?>
		<div class="ewd-otp-deactivate-survey-modal" id="ewd-otp-deactivate-survey-order-tracking">
			<div class="ewd-otp-deactivate-survey-wrap">
				<form class="ewd-otp-deactivate-survey" method="post">
					<span class="ewd-otp-deactivate-survey-title"><span class="dashicons dashicons-testimonial"></span><?php echo ' ' . __( 'Quick Feedback', 'order-tracking' ); ?><span id="ewd-otp-deactivation-cancel">X</span></span>
					<span class="ewd-otp-deactivate-survey-desc"><?php echo __( 'Need help? Contact us at <a href="mailto:contact@etoilewebdesign.com">contact@etoilewebdesign.com</a> for a quick reply!<br/> Please share why you are deactivating Order Tracking:', 'order-tracking' ); ?></span>
					<div class="ewd-otp-deactivate-survey-options">
						<?php foreach ( $options as $id => $option ) : ?>
							<div class="ewd-otp-deactivate-survey-option">
								<label for="ewd-otp-deactivate-survey-option-order-tracking-<?php echo $id; ?>" class="ewd-otp-deactivate-survey-option-label">
									<input id="ewd-otp-deactivate-survey-option-order-tracking-<?php echo $id; ?>" class="ewd-otp-deactivate-survey-option-input" type="radio" name="code" value="<?php echo $id; ?>" />
									<span class="ewd-otp-deactivate-survey-option-reason"><?php echo $option['title']; ?></span>
								</label>
								<?php if ( ! empty( $option['details'] ) ) : ?>
									<input class="ewd-otp-deactivate-survey-option-details" type="text" placeholder="<?php echo $option['details']; ?>" />
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<p><?php esc_html_e( 'Submitting sends your selected reason, optional details, this site URL, and plugin installation timing to Etoile Web Design for product-support analysis. Skip sends nothing.', 'order-tracking' ); ?></p>
					<div class="ewd-otp-deactivate-survey-footer">
						<button type="submit" class="ewd-otp-deactivate-survey-submit button button-primary button-large"><?php _e( 'Submit and Deactivate', 'order-tracking' ); ?></button>
						<a href="#" class="ewd-otp-deactivate-survey-deactivate"><?php _e( 'Skip and Deactivate', 'order-tracking' ); ?></a>
					</div>
				</form>
			</div>
		</div>
			<?php
		}
	}

}
