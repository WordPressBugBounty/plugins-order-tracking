<?php
/**
 * First-party starter workflow definitions and high-confidence diagnostics.
 *
 * @package OrderTracking
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

if ( ! class_exists( 'ewdotpWorkflowTemplates' ) ) {
	/**
	 * Provides starter workflow definitions and validation.
	 */
	class EwdotpWorkflowTemplates {

		/**
		 * Handle the get templates operation.
		 */
		public function get_templates() {
			return array(
				'general'    => $this->template(
					__( 'General Order Tracking', 'order-tracking' ),
					__( 'A simple general-purpose order workflow.', 'order-tracking' ),
					array(
						__( 'Received', 'order-tracking' ) => 10,
						__( 'Processing', 'order-tracking' ) => 40,
						__( 'Ready / Shipped', 'order-tracking' ) => 80,
						__( 'Complete', 'order-tracking' ) => 100,
					)
				),
				'production' => $this->template(
					__( 'Custom Production / Manufacturing', 'order-tracking' ),
					__( 'A production workflow with planning and quality review.', 'order-tracking' ),
					array(
						__( 'Order Received', 'order-tracking' )       => 10,
						__( 'Planning / Materials', 'order-tracking' ) => 25,
						__( 'In Production', 'order-tracking' )        => 50,
						__( 'Quality Check', 'order-tracking' )        => 75,
						__( 'Ready / Complete', 'order-tracking' )     => 100,
					)
				),
				'repair'     => $this->template(
					__( 'Repair / Service', 'order-tracking' ),
					__( 'A repair workflow from assessment through completion.', 'order-tracking' ),
					array(
						__( 'Received', 'order-tracking' ) => 10,
						__( 'Assessment', 'order-tracking' ) => 25,
						__( 'Awaiting Approval / Parts', 'order-tracking' ) => 40,
						__( 'In Service', 'order-tracking' ) => 65,
						__( 'Quality Check', 'order-tracking' ) => 85,
						__( 'Ready / Complete', 'order-tracking' ) => 100,
					)
				),
				'b2b'        => $this->template(
					__( 'B2B Fulfillment / Approval', 'order-tracking' ),
					__( 'A business workflow with approval and fulfillment stages.', 'order-tracking' ),
					array(
						__( 'Submitted', 'order-tracking' ) => 10,
						__( 'Review / Approval', 'order-tracking' ) => 30,
						__( 'Processing', 'order-tracking' ) => 50,
						__( 'Fulfillment', 'order-tracking' ) => 75,
						__( 'Ready / Shipped', 'order-tracking' ) => 90,
						__( 'Complete', 'order-tracking' ) => 100,
					)
				),
				'project'    => $this->template(
					__( 'Project / Application Status', 'order-tracking' ),
					__( 'A project or application review workflow.', 'order-tracking' ),
					array(
						__( 'Submitted', 'order-tracking' ) => 10,
						__( 'Review', 'order-tracking' )   => 30,
						__( 'In Progress', 'order-tracking' ) => 55,
						__( 'Awaiting Response', 'order-tracking' ) => 75,
						__( 'Final Review', 'order-tracking' ) => 90,
						__( 'Complete', 'order-tracking' ) => 100,
					)
				),
			);
		}

		/**
		 * Handle the get statuses operation.
		 *
		 * @param mixed $key key.
		 * @param mixed $notification_id notification id.
		 */
		public function get_statuses( $key, $notification_id = '' ) {
			$templates = $this->get_templates();
			if ( ! isset( $templates[ $key ] ) ) {
				return array(); }

			$statuses = array();
			foreach ( $templates[ $key ]['statuses'] as $status ) {
				$statuses[] = array(
					'status'     => $status['status'],
					'percentage' => $status['percentage'],
					'email'      => $notification_id,
					'internal'   => 'no',
				);
			}

			return $statuses;
		}

		/**
		 * Handle the validate operation.
		 *
		 * @param mixed $statuses statuses.
		 * @param mixed $progress_enabled progress enabled.
		 */
		public function validate( $statuses, $progress_enabled = true ) {
			$warnings     = array();
			$seen         = array();
			$percentages  = array();
			$has_terminal = false;

			if ( empty( $statuses ) ) {
				return array( __( 'No statuses are configured.', 'order-tracking' ) ); }

			foreach ( $statuses as $status ) {
				$name       = isset( $status->status ) ? trim( (string) $status->status ) : '';
				$percentage = isset( $status->percentage ) ? trim( (string) $status->percentage ) : '';
				$key        = strtolower( $name );

				if ( '' === $name ) {
					$warnings[] = __( 'A configured status has a blank name.', 'order-tracking' ); }
				if ( '' !== $key && isset( $seen[ $key ] ) ) {
					/* translators: %s: duplicated order status name. */
					$warnings[] = sprintf( __( 'The status “%s” is duplicated.', 'order-tracking' ), $name ); }
				$seen[ $key ] = true;

				if ( '' !== $percentage && ! is_numeric( $percentage ) ) {
					/* translators: %s: order status name. */
					$warnings[] = sprintf( __( 'The percentage for “%s” is not numeric.', 'order-tracking' ), $name );
				} elseif ( '' !== $percentage ) {
					$value         = (float) $percentage;
					$percentages[] = $value;
					if ( $value < 0 || $value > 100 ) {
						/* translators: %s: order status name. */
						$warnings[] = sprintf( __( 'The percentage for “%s” must be between 0 and 100.', 'order-tracking' ), $name ); }
					if ( 100.0 === $value ) {
						$has_terminal = true; }
				}
			}

			if ( $progress_enabled && ! $has_terminal ) {
				$warnings[] = __( 'The progress graphic has no status at 100% complete.', 'order-tracking' ); }
			if ( $progress_enabled && count( array_unique( $percentages ) ) === 1 && count( $percentages ) > 1 ) {
				$warnings[] = __( 'All visible statuses use the same percentage, so the progress graphic may not be informative.', 'order-tracking' ); }

			return array_values( array_unique( $warnings ) );
		}

		/**
		 * Handle the template operation.
		 *
		 * @param mixed $name name.
		 * @param mixed $description description.
		 * @param mixed $status_map status map.
		 */
		private function template( $name, $description, $status_map ) {
			$statuses = array();
			foreach ( $status_map as $status => $percentage ) {
				$statuses[] = array(
					'status'     => $status,
					'percentage' => $percentage,
					'internal'   => false,
				); }
			return array(
				'name'        => $name,
				'description' => $description,
				'statuses'    => $statuses,
			);
		}
	}
}
