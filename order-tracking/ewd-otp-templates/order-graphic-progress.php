<?php
/**
 * Render the order progress graphic.
 *
 * @package OrderTracking
 */

$progress_percentage = max( 0, min( 100, is_numeric( $this->order->current_status->percentage ) ? (float) $this->order->current_status->percentage : 0 ) );
?>
<div class='ewd-otp-tracking-graphic' role='group' aria-label='<?php echo esc_attr__( 'Order progress', 'order-tracking' ); ?>'>

	<?php /* translators: 1: current order status, 2: completion percentage. */ ?>
	<div id='ewd-otp-progressbar-<?php echo esc_attr( $this->get_option( 'tracking-graphic' ) ); ?>' role='progressbar' aria-valuemin='0' aria-valuemax='100' aria-valuenow='<?php echo esc_attr( $progress_percentage ); ?>' aria-valuetext='<?php echo esc_attr( sprintf( __( '%1$s: %2$s%% complete', 'order-tracking' ), $this->order->current_status->status, $progress_percentage ) ); ?>'>
		<div class='<?php echo esc_attr( $this->get_option( 'tracking-graphic' ) ); ?>' style='width: <?php echo esc_attr( $progress_percentage ); ?>%'></div>
	</div>
	
	<div class='ewd-otp-statuses'>

		<div class='ewd-otp-display-status' id='ewd-otp-initial-status'>
			<?php echo ( 0 === $progress_percentage ? esc_html( $this->order->current_status->status ) : esc_html( $this->get_starting_status() ) ); ?>
		</div>

		<?php if ( 0 !== $progress_percentage && 100 !== $progress_percentage ) { ?>

			<div class='ewd-otp-display-status' id='ewd-otp-current-status' aria-current='step' style='margin-left: <?php echo esc_attr( max( 5, min( 55, $progress_percentage - 10 ) ) ); ?>%'>
				<?php echo esc_html( $this->order->current_status->status ); ?>
			</div>

		<?php } ?>

		<div class='ewd-otp-display-status' id='ewd-otp-ending-status'>
			<?php echo ( 100 === $progress_percentage ? esc_html( $this->order->current_status->status ) : esc_html( $this->get_ending_status() ) ); ?>
		</div>

	</div>

</div>
