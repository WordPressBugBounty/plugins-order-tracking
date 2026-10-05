<?php
/**
 * Signed Zendesk webhook integration.
 *
 * @package OrderTracking
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

if ( ! class_exists( 'ewdotpZendesk' ) ) {
	/**
	 * Authenticates, serializes, and applies Zendesk ticket events.
	 */
	class EwdotpZendesk {

		const REPLAY_WINDOW = 300;
		/**
		 * Runtime invocation key state.
		 *
		 * @var mixed
		 */
		private $invocation_key = '';
		/**
		 * Runtime invocation value state.
		 *
		 * @var mixed
		 */
		private $invocation_value = array();
		/**
		 * Runtime ticket lock state.
		 *
		 * @var mixed
		 */
		private $ticket_lock = '';

		/**
		 * Initialize the integration.
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Handle the register routes operation.
		 */
		public function register_routes() {
			global $ewd_otp_controller;

			if ( ! is_object( $ewd_otp_controller ) || ! is_object( $ewd_otp_controller->permissions ) || ! $ewd_otp_controller->permissions->check_permission( 'zendesk' ) ) {
				return; }

			register_rest_route(
				'order-tracking-zendesk/v1',
				'/webhook',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_webhook' ),
					'permission_callback' => array( $this, 'verify_request' ),
				)
			);
		}

		/**
		 * Handle the verify request operation.
		 *
		 * @param mixed $request request.
		 */
		public function verify_request( $request ) {
			global $ewd_otp_controller;

			$secret    = (string) $ewd_otp_controller->settings->get_setting( 'zendesk-signing-secret' );
			$signature = (string) $request->get_header( 'x-zendesk-webhook-signature' );
			$timestamp = (string) $request->get_header( 'x-zendesk-webhook-signature-timestamp' );

			if ( '' === $secret || '' === $signature || '' === $timestamp ) {
				return new WP_Error( 'ewd_otp_zendesk_auth_missing', __( 'Zendesk webhook authentication is not configured or is missing.', 'order-tracking' ), array( 'status' => 401 ) );
			}

			$timestamp_epoch = ctype_digit( $timestamp ) ? (int) $timestamp : strtotime( $timestamp );
			if ( ! $timestamp_epoch || abs( time() - $timestamp_epoch ) > self::REPLAY_WINDOW ) {
				return new WP_Error( 'ewd_otp_zendesk_stale', __( 'The Zendesk webhook timestamp is outside the allowed replay window.', 'order-tracking' ), array( 'status' => 401 ) );
			}

			$expected = self::compute_signature( $timestamp, $request->get_body(), $secret );
			if ( ! hash_equals( $expected, trim( $signature ) ) ) {
				return new WP_Error( 'ewd_otp_zendesk_signature', __( 'The Zendesk webhook signature is invalid.', 'order-tracking' ), array( 'status' => 403 ) );
			}

			return true;
		}

		/**
		 * Handle the compute signature operation.
		 *
		 * @param mixed $timestamp timestamp.
		 * @param mixed $body body.
		 * @param mixed $secret secret.
		 */
		public static function compute_signature( $timestamp, $body, $secret ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Zendesk's signed webhook protocol requires base64 output.
			return base64_encode( hash_hmac( 'sha256', (string) $timestamp . (string) $body, (string) $secret, true ) );
		}

		/**
		 * Handle the handle webhook operation.
		 *
		 * @param mixed $request request.
		 */
		public function handle_webhook( $request ) {
			global $ewd_otp_controller;

			$invocation_id = sanitize_text_field( $request->get_header( 'x-zendesk-webhook-invocation-id' ) );
			$payload       = json_decode( $request->get_body(), true );
			if ( ! is_array( $payload ) || JSON_ERROR_NONE !== json_last_error() ) {
				return $this->error( 'invalid_json', __( 'The webhook body must be valid JSON.', 'order-tracking' ), 400 );
			}

			$ticket_id       = isset( $payload['ticket_id'] ) ? absint( $payload['ticket_id'] ) : 0;
			$title           = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';
			$status_key      = isset( $payload['status'] ) ? sanitize_text_field( $payload['status'] ) : '';
			$email           = isset( $payload['requester_email'] ) ? sanitize_email( $payload['requester_email'] ) : '';
			$event_timestamp = isset( $payload['event_timestamp'] ) ? $this->parse_event_timestamp( $payload['event_timestamp'] ) : 0;

			if ( ! $ticket_id || '' === $title || '' === $status_key || '' === $invocation_id || ! $event_timestamp ) {
				return $this->error( 'invalid_payload', __( 'ticket_id, title, status, event_timestamp, and the invocation ID header are required.', 'order-tracking' ), 400, $ticket_id );
			}

			$mapped_status = $this->map_status( $status_key );
			if ( '' === $mapped_status ) {
				return $this->error( 'unmapped_status', __( 'The Zendesk status has no configured Order Tracking mapping.', 'order-tracking' ), 422, $ticket_id );
			}

			$claim = $this->claim_invocation( $invocation_id );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
			if ( 'duplicate' === $claim ) {
				return new WP_REST_Response(
					array(
						'result'    => 'duplicate',
						'persisted' => true,
					),
					200
				);
			}

			if ( ! $this->acquire_ticket_lock( $ticket_id ) ) {
				$this->release_invocation();
				return $this->error( 'ticket_locked', __( 'This Zendesk ticket is already being processed; retry later.', 'order-tracking' ), 409, $ticket_id );
			}

			try {
				$db_order = $ewd_otp_controller->order_manager->get_order_from_zendesk_id( $ticket_id );
				$order    = new ewdotpOrder();
				$result   = 'created';

				if ( $db_order ) {
					$order->load_order( $db_order );
					$result = $this->apply_event_to_order( $order, $title, $email, $mapped_status, $event_timestamp );
					if ( is_wp_error( $result ) ) {
						$this->release_invocation();
						return $result;
					}
				} else {
					$order->name                    = $title;
					$order->number                  = $this->unique_order_number( $ticket_id, $title );
					$order->email                   = $email;
					$order->status                  = $mapped_status;
					$order->external_status         = $mapped_status;
					$order->zendesk_id              = $ticket_id;
					$order->zendesk_event_timestamp = $event_timestamp;
					$order->display                 = true;
					$order->notes_public            = __( 'Ticket created via Zendesk', 'order-tracking' );

					if ( ! $order->insert_order_with_history() ) {
						$raced_order = $ewd_otp_controller->order_manager->get_order_from_zendesk_id( $ticket_id );
						if ( ! $raced_order ) {
							$this->release_invocation();
							return $this->error( 'database_error', __( 'The Zendesk order could not be created.', 'order-tracking' ), 500, $ticket_id );
						}
						$order->load_order( $raced_order );
						$result = $this->apply_event_to_order( $order, $title, $email, $mapped_status, $event_timestamp );
						if ( is_wp_error( $result ) ) {
							$this->release_invocation();
							return $result;
						}
					}
				}

				if ( ! $this->complete_invocation() ) {
					$this->release_invocation();
					return $this->error( 'database_error', __( 'The webhook completion marker could not be saved.', 'order-tracking' ), 500, $ticket_id );
				}
				$this->record_result( $ticket_id, $result );

				return new WP_REST_Response(
					array(
						'result'    => $result,
						'order_id'  => absint( $order->id ),
						'persisted' => true,
					),
					200
				);
			} finally {
				$this->release_ticket_lock();
			}
		}

		/**
		 * Handle the apply event to order operation.
		 *
		 * @param mixed $order order.
		 * @param mixed $title title.
		 * @param mixed $email email.
		 * @param mixed $mapped_status mapped status.
		 * @param mixed $event_timestamp event timestamp.
		 */
		private function apply_event_to_order( $order, $title, $email, $mapped_status, $event_timestamp ) {
			if ( $event_timestamp <= (int) $order->zendesk_event_timestamp ) {
				return 'stale_ignored';
			}

			$changed = false;
			if ( $title !== $order->name ) {
				$order->name = $title;
				$changed     = true;
			}
			if ( '' !== $email && $email !== $order->email ) {
				$order->email = $email;
				$changed      = true;
			}
			$order->zendesk_event_timestamp = $event_timestamp;
			$changed                        = true;

			if ( $mapped_status !== $order->status ) {
				if ( ! $order->set_status( $mapped_status ) ) {
					return $this->error( 'database_error', __( 'The linked order status could not be updated.', 'order-tracking' ), 500, $order->zendesk_id );
				}
				return 'updated';
			}

			if ( $changed && ! $order->update_order() ) {
				return $this->error( 'database_error', __( 'The linked order could not be updated.', 'order-tracking' ), 500, $order->zendesk_id );
			}
			return 'updated';
		}

		/**
		 * Handle the parse event timestamp operation.
		 *
		 * @param mixed $value value.
		 */
		private function parse_event_timestamp( $value ) {
			if ( is_numeric( $value ) ) {
				$timestamp = (int) $value;
				return $timestamp < 100000000000 ? $timestamp * 1000 : $timestamp;
			}
			$timestamp = strtotime( sanitize_text_field( $value ) );
			return false === $timestamp ? 0 : $timestamp * 1000;
		}

		/**
		 * Handle the acquire ticket lock operation.
		 *
		 * @param mixed $ticket_id ticket id.
		 */
		private function acquire_ticket_lock( $ticket_id ) {
			global $wpdb;

			$this->ticket_lock = 'ewd_otp_zd_' . substr( hash( 'sha256', (string) absint( $ticket_id ) ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks are connection-scoped.
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $this->ticket_lock ) );
			if ( '1' === (string) $result ) {
				return true;
			}
			$this->ticket_lock = '';
			return false;
		}

		/**
		 * Handle the release ticket lock operation.
		 */
		private function release_ticket_lock() {
			global $wpdb;

			if ( '' === $this->ticket_lock ) {
				return;
			}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release is connection-scoped.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->ticket_lock ) );
			$this->ticket_lock = '';
		}

		/**
		 * Handle the claim invocation operation.
		 *
		 * @param mixed $invocation_id invocation id.
		 */
		private function claim_invocation( $invocation_id ) {
			$this->invocation_key   = 'ewd_otp_zd_claim_' . hash( 'sha256', $invocation_id );
			$this->invocation_value = array(
				'owner' => wp_generate_uuid4(),
				'state' => 'pending',
				'time'  => time(),
			);
			if ( add_option( $this->invocation_key, $this->invocation_value, '', 'no' ) ) {
				return 'claimed'; }

			$current = get_option( $this->invocation_key, array() );
			if ( is_array( $current ) && 'done' === ( isset( $current['state'] ) ? $current['state'] : '' ) ) {
				return 'duplicate'; }
			if ( is_array( $current ) && ! empty( $current['time'] ) && time() - absint( $current['time'] ) > self::REPLAY_WINDOW ) {
				global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-swap for an invocation lease.
				$result = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => maybe_serialize( $this->invocation_value ) ),
					array(
						'option_name'  => $this->invocation_key,
						'option_value' => maybe_serialize( $current ),
					)
				);
				wp_cache_delete( $this->invocation_key, 'options' );
				if ( 1 === $result ) {
					return 'claimed'; }
			}
			return new WP_Error( 'ewd_otp_zendesk_in_progress', __( 'This Zendesk invocation is already being processed; retry later.', 'order-tracking' ), array( 'status' => 409 ) );
		}

		/**
		 * Handle the complete invocation operation.
		 */
		private function complete_invocation() {
			global $wpdb;
			if ( ! $this->invocation_key ) {
				return false; }
			$completed          = $this->invocation_value;
			$completed['state'] = 'done';
			$completed['time']  = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic owner-checked completion of an invocation lease.
			$result = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => maybe_serialize( $completed ) ),
				array(
					'option_name'  => $this->invocation_key,
					'option_value' => maybe_serialize( $this->invocation_value ),
				)
			);
			if ( 1 === $result ) {
				$this->invocation_value = $completed;
				wp_cache_delete( $this->invocation_key, 'options' );
				return true; }
			return false;
		}

		/**
		 * Handle the release invocation operation.
		 */
		private function release_invocation() {
			global $wpdb;
			if ( ! $this->invocation_key ) {
				return; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Owner-checked failed invocation cleanup must be atomic.
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $this->invocation_key,
					'option_value' => maybe_serialize( $this->invocation_value ),
				)
			);
			wp_cache_delete( $this->invocation_key, 'options' );
		}

		/**
		 * Handle the map status operation.
		 *
		 * @param mixed $status_key status key.
		 */
		private function map_status( $status_key ) {
			global $ewd_otp_controller;

			$configured = array();
			foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'statuses' ) ) as $status ) {
				$configured[] = (string) $status->status;
			}

			foreach ( ewd_otp_decode_infinite_table_setting( $ewd_otp_controller->settings->get_setting( 'zendesk-status-mappings' ) ) as $mapping ) {
				$zendesk_status = isset( $mapping->zendesk_status ) ? strtolower( trim( (string) $mapping->zendesk_status ) ) : '';
				$otp_status     = isset( $mapping->otp_status ) ? (string) $mapping->otp_status : '';
				if ( strtolower( trim( $status_key ) ) === $zendesk_status && in_array( $otp_status, $configured, true ) ) {
					return $otp_status; }
			}

			return '';
		}

		/**
		 * Handle the unique order number operation.
		 *
		 * @param mixed $ticket_id ticket id.
		 * @param mixed $title title.
		 */
		private function unique_order_number( $ticket_id, $title ) {
			global $ewd_otp_controller;

			$base = sanitize_text_field( $ticket_id . ' - ' . $title );
			if ( ! $ewd_otp_controller->order_manager->get_order_from_tracking_number( $base ) ) {
				return $base; }

			return $base . ' [Zendesk ' . absint( $ticket_id ) . '-' . substr( hash( 'sha256', $base ), 0, 8 ) . ']';
		}

		/**
		 * Handle the error operation.
		 *
		 * @param mixed $code code.
		 * @param mixed $message message.
		 * @param mixed $status status.
		 * @param mixed $ticket_id ticket id.
		 */
		private function error( $code, $message, $status, $ticket_id = 0 ) {
			$this->record_result( $ticket_id, $code );
			return new WP_Error( 'ewd_otp_zendesk_' . $code, $message, array( 'status' => $status ) );
		}

		/**
		 * Handle the record result operation.
		 *
		 * @param mixed $ticket_id ticket id.
		 * @param mixed $result result.
		 */
		private function record_result( $ticket_id, $result ) {
			update_option(
				'ewd-otp-zendesk-last-result',
				array(
					'timestamp_gmt' => current_time( 'mysql', true ),
					'ticket_id'     => absint( $ticket_id ),
					'result'        => sanitize_key( $result ),
				),
				false
			);
		}
	}
}
