<?php
/**
 * Render order status history.
 *
 * @package OrderTracking
 */

?>
<div class="ewd-otp-status-history" role="list" aria-label="<?php echo esc_attr__( 'Order status history', 'order-tracking' ); ?>">
<?php foreach ( $this->order->status_history as $status_history ) { ?>

	<div class='ewd-otp-status-label' role='listitem'>
	
		<div class='ewd-otp-statuses'>
			<?php echo esc_html( $status_history->status ); ?>
		</div>
	
		<div class='ewd-otp-statuses'>
			<?php echo esc_html( $status_history->location ); ?>
		</div>
	
		<div class='ewd-otp-statuses'>
			<?php echo esc_html( gmdate( $this->get_option( 'date-format' ), strtotime( $status_history->updated_fmtd ) ) ); ?>
		</div>
	
	</div>

<?php } ?>
</div>
