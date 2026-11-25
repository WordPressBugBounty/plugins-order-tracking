<?php
/**
 * One-time Black Friday 2026 admin promotion.
 *
 * @package OrderTracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Show the seasonal offer and retain site-wide dismissals until it ends. */
class EwdotpBlackFriday2026Notice {
	// Fixed EST (UTC-5) schedule, independent of the site's timezone.
	const START   = 1795669260; // November 26 at 00:01 EST.
	const END     = 1796101200; // December 1 at 00:00 EST, exclusive.
	const HIDDEN  = 'ewd-otp-bf2026-hidden';
	const SNOOZED = 'ewd-otp-bf2026-snoozed';
	const ACTION  = 'ewd_otp_bf2026_dismiss';
	const ASSET   = 'ewd-otp-bf2026-notice';

	/** Register the admin notice, its assets and the authenticated dismissal. */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'dismiss' ) );
	}

	/** Return the current Unix time. */
	protected function now() {
		return time();
	}

	/** Choose a promotion using local entitlement evidence only. */
	private function offer() {
		$now = $this->now();
		if ( $now < self::START || $now >= self::END || ! current_user_can( 'manage_options' ) || false !== get_transient( self::HIDDEN ) || false !== get_transient( self::SNOOZED ) ) {
			return '';
		}

		$state = EwdotpEntitlement::effective();
		if ( in_array( 'sms', $state['capabilities'], true ) || in_array( 'api_usage', $state['capabilities'], true ) ) {
			return '';
		}

		// Trial or legacy flags alone do not qualify for the paid-customer checkout.
		if ( in_array( 'premium', $state['capabilities'], true ) && ! empty( $state['licenses'] ) && is_array( $state['licenses'] ) ) {
			foreach ( $state['licenses'] as $license ) {
				if ( isset( $license['permission_level'], $license['plan'], $license['effective_status'], $license['deadline_at'] ) && 2 === $license['permission_level'] && 'premium' === $license['plan'] && in_array( $license['effective_status'], array( 'valid', 'valid_cached' ), true ) && is_int( $license['deadline_at'] ) && $license['deadline_at'] > $now ) {
					return 'premium';
				}
			}
		}
		return 'coupon';
	}

	/** Load the promotion assets only while the notice is visible. */
	public function enqueue_assets() {
		if ( '' === $this->offer() ) {
			return;
		}
		wp_enqueue_style( self::ASSET, EWD_OTP_PLUGIN_URL . '/assets/css/black-friday-2026-notice.css', array(), EWD_OTP_VERSION );
		wp_enqueue_script( self::ASSET, EWD_OTP_PLUGIN_URL . '/assets/js/black-friday-2026-notice.js', array( 'jquery' ), EWD_OTP_VERSION, true );
		wp_localize_script(
			self::ASSET,
			'ewd_otp_bf2026_notice',
			array(
				'url'    => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( self::ACTION ),
				'action' => self::ACTION,
				'error'  => __( 'Could not hide this notice. Please try again.', 'order-tracking' ),
			)
		);
	}

	/** Render the relevant offer with the same layout and controls as FDM. */
	public function render() {
		$offer = $this->offer();
		if ( '' === $offer ) {
			return;
		}
		?>
		<div class="notice notice-info ewd-otp-bf2026-notice">
			<img class="ewd-otp-bf2026-icon" src="<?php echo esc_url( EWD_OTP_PLUGIN_URL . '/assets/img/ewd-otp-icon.png' ); ?>" width="100" height="100" alt="">
			<div class="ewd-otp-bf2026-content">
				<?php if ( 'premium' === $offer ) : ?>
					<p><?php esc_html_e( 'Take advantage of our Black Friday sale to upgrade to Order Tracking Ultimate! As a thank you for being a Premium customer, upgrade to Ultimate for only $97 USD. Sale ends November 30 at 11:59:59 PM EST.', 'order-tracking' ); ?></p>
					<p class="ewd-otp-bf2026-actions">
						<a class="button button-primary" href="https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=P-30373656VR150541VNBWRQQQ" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade (USD)', 'order-tracking' ); ?></a>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Take advantage of our Black Friday sale to upgrade to Order Tracking Ultimate or Premium! Use code BF2026 at checkout to get 20% off the regular price. Sale ends November 30 at 11:59:59 PM EST.', 'order-tracking' ); ?></p>
					<p><a class="button button-primary" href="https://www.etoilewebdesign.com/plugins/order-tracking/?utm_source=plugin_admin_bf2026" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Order Tracking Plans', 'order-tracking' ); ?></a></p>
				<?php endif; ?>
				<p class="ewd-otp-bf2026-actions">
					<button type="button" class="button ewd-otp-bf2026-dismiss" data-dismissal="temporary"><?php esc_html_e( 'Hide Temporarily', 'order-tracking' ); ?></button>
					<button type="button" class="button ewd-otp-bf2026-dismiss" data-dismissal="permanent"><?php esc_html_e( "Don't Show Again", 'order-tracking' ); ?></button>
					<span class="ewd-otp-bf2026-status" role="status" aria-live="polite"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/** Save a site-wide dismissal for this campaign only. */
	public function dismiss() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not authorized to hide this notice.', 'order-tracking' ) ), 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the exact allowlisted values below are accepted.
		$mode = isset( $_POST['dismissal'] ) && is_string( $_POST['dismissal'] ) ? wp_unslash( $_POST['dismissal'] ) : '';
		if ( ! in_array( $mode, array( 'temporary', 'permanent' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid dismissal choice.', 'order-tracking' ) ), 400 );
		}
		$now = $this->now();
		if ( $now >= self::START && $now < self::END ) {
			$remaining = self::END - $now;
			$key       = 'permanent' === $mode ? self::HIDDEN : self::SNOOZED;
			$ttl       = 'permanent' === $mode ? $remaining : min( HOUR_IN_SECONDS, $remaining );
			// A stale tab's snooze cannot undo Don't Show Again.
			if ( ! set_transient( $key, 1, $ttl ) && false === get_transient( $key ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not hide this notice. Please try again.', 'order-tracking' ) ), 500 );
			}
		}
		wp_send_json_success();
	}
}
