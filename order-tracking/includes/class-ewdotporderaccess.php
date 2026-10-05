<?php
/**
 * Central order read and public customer-note authorization policy.
 *
 * @package OrderTracking
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdotpOrderAccess' ) ) {
	/**
	 * Centralizes order and collection authorization decisions.
	 */
	class EwdotpOrderAccess {

		const MODE_PUBLIC        = 'public';
		const MODE_EMAIL         = 'email_verification';
		const MODE_REQUIRE_LOGIN = 'require_login';

		/**
		 * Normalize the supported current and legacy proof names.
		 *
		 * @param mixed $source source.
		 */
		public function normalize_proof( $source ) {
			$source = is_array( $source ) ? $source : array();

			$token            = $this->first_value( $source, array( 'tracking_token', 'tracking_link_code', 'tl_code', 'token' ) );
			$email            = $this->first_value( $source, array( 'order_email', 'email' ) );
			$collection_token = $this->first_value( $source, array( 'collection_token', 'order_collection_token' ) );

			return array(
				'token'            => sanitize_text_field( wp_unslash( $token ) ),
				'email'            => sanitize_email( wp_unslash( $email ) ),
				'collection_token' => sanitize_text_field( wp_unslash( $collection_token ) ),
			);
		}

		/**
		 * Return the active customer access mode.
		 */
		public function get_mode() {
			global $ewd_otp_controller;

			if ( $ewd_otp_controller->settings->get_setting( 'require-login' ) || (bool) apply_filters( 'ewd_otp_require_login', false ) ) {
				return self::MODE_REQUIRE_LOGIN;
			}

			if ( $ewd_otp_controller->settings->get_setting( 'email-verification' ) ) {
				return self::MODE_EMAIL;
			}

			return self::MODE_PUBLIC;
		}

		/**
		 * Decide whether the supplied actor/proof can read or update customer notes.
		 *
		 * @param mixed $order order.
		 * @param mixed $proof proof.
		 * @param mixed $user_id WordPress user ID.
		 */
		public function authorize( $order, $proof = array(), $user_id = null ) {
			$proof          = $this->normalize_proof( $proof );
			$mode           = $this->get_mode();
			$user_id        = null === $user_id ? get_current_user_id() : absint( $user_id );
			$token_supplied = '' !== $proof['token'];
			$token_valid    = $token_supplied && is_object( $order ) && $order->verify_tracking_link( $proof['token'] );
			$staff          = $this->is_staff();
			$owner          = $this->is_authorized_user( $order, $user_id );
			$account_email  = $this->has_matching_account_email( $order, $user_id );
			$collection     = $this->verify_order_collection_proof( $order, $proof['collection_token'], $user_id );

			$result = array(
				'authorized'     => false,
				'reason'         => 'not_authorized',
				'mode'           => $mode,
				'token_supplied' => $token_supplied,
				'token_valid'    => $token_valid,
				'email'          => $proof['email'],
				'token'          => $proof['token'],
			);

			if ( ! is_object( $order ) || empty( $order->id ) ) {
				$result['reason'] = 'order_not_found';
				return $result;
			}

			if ( self::MODE_REQUIRE_LOGIN === $mode ) {
				if ( empty( $user_id ) ) {
					$result['reason'] = 'login_required';
					return $result;
				}

				$result['authorized'] = $staff || $owner;
				$result['reason']     = $result['authorized'] ? 'authorized' : 'not_authorized';
				return $result;
			}

			if ( self::MODE_PUBLIC === $mode ) {
				$result['authorized'] = true;
				$result['reason']     = 'authorized';
				return $result;
			}

			$result['authorized'] = $token_valid || $collection || $staff || $owner || $account_email || $order->verify_order_email( $proof['email'] );
			$result['reason']     = $result['authorized'] ? 'authorized' : ( $token_supplied ? 'invalid_token' : 'invalid_email' );

			return $result;
		}

		/**
		 * Authorize a customer or sales-representative collection.
		 *
		 * @param mixed $entity entity.
		 * @param mixed $type type.
		 * @param mixed $proof proof.
		 * @param mixed $user_id WordPress user ID.
		 * @param mixed $require_proof Whether an explicit collection proof is required.
		 */
		public function authorize_collection( $entity, $type, $proof = array(), $user_id = null, $require_proof = false ) {
			$proof   = $this->normalize_proof( $proof );
			$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
			$mode    = $this->get_mode();
			$result  = array(
				'authorized' => false,
				'reason'     => 'not_authorized',
				'mode'       => $mode,
			);

			if ( ! is_object( $entity ) || empty( $entity->id ) || ! in_array( $type, array( 'customer', 'sales_rep' ), true ) ) {
				$result['reason'] = 'order_not_found';
				return $result;
			}

			if ( $require_proof && ! $this->verify_collection_proof( $proof['collection_token'], $type, $entity->id, $user_id ) ) {
				$result['reason'] = 'invalid_token';
				return $result;
			}

			if ( $this->is_staff() ) {
				$result['authorized'] = true;
				$result['reason']     = 'authorized';
				return $result;
			}

			if ( self::MODE_REQUIRE_LOGIN === $mode ) {
				if ( ! $user_id ) {
					$result['reason'] = 'login_required';
					return $result;
				}

				$result['authorized'] = absint( isset( $entity->wp_id ) ? $entity->wp_id : 0 ) === $user_id;
			} elseif ( self::MODE_EMAIL === $mode ) {
				$user                 = $user_id ? get_userdata( $user_id ) : false;
				$user_email           = is_object( $user ) ? $this->normalize_email( $user->user_email ) : '';
				$entity_email         = $this->normalize_email( isset( $entity->email ) ? $entity->email : '' );
				$result['authorized'] = $this->verify_collection_proof( $proof['collection_token'], $type, $entity->id, $user_id )
					|| ( $user_id && absint( isset( $entity->wp_id ) ? $entity->wp_id : 0 ) === $user_id )
					|| ( '' !== $user_email && '' !== $entity_email && $user_email === $entity_email )
					|| ( '' !== $this->normalize_email( $proof['email'] )
						&& $this->normalize_email( $proof['email'] ) === $entity_email );
			} else {
				$result['authorized'] = true;
			}

			$result['reason'] = $result['authorized'] ? 'authorized' : 'not_authorized';
			return $result;
		}

		/**
		 * Create a short-lived bearer proof for an already authorized collection.
		 *
		 * @param mixed $type      Collection type.
		 * @param mixed $entity_id Collection entity ID.
		 * @param mixed $user_id   WordPress user ID.
		 */
		public function create_collection_proof( $type, $entity_id, $user_id = null ) {
			$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
			$payload = implode( '|', array( $type, absint( $entity_id ), $user_id, time() + 600 ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding, not obfuscation.
			$encoded = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
			return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		}

		/**
		 * Verify an opaque collection proof without exposing stored email addresses.
		 *
		 * @param mixed $token     Opaque proof token.
		 * @param mixed $type      Collection type.
		 * @param mixed $entity_id Collection entity ID.
		 * @param mixed $user_id   WordPress user ID.
		 */
		public function verify_collection_proof( $token, $type, $entity_id, $user_id = null ) {
			$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
			$parts   = explode( '.', (string) $token, 2 );
			if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
				return false; }

			$encoded  = strtr( $parts[0], '-_', '+/' );
			$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the signed URL-safe proof protocol.
			$payload = base64_decode( $encoded, true );
			$values  = false === $payload ? array() : explode( '|', $payload );
			return 4 === count( $values )
				&& (string) $type === $values[0]
				&& absint( $entity_id ) === absint( $values[1] )
				&& absint( $values[2] ) === $user_id
			&& absint( $values[3] ) >= time();
		}

		/**
		 * Handle the verify order collection proof operation.
		 *
		 * @param mixed $order order.
		 * @param mixed $token token.
		 * @param mixed $user_id user id.
		 */
		private function verify_order_collection_proof( $order, $token, $user_id ) {
			if ( ! is_object( $order ) || '' === (string) $token ) {
				return false; }
			return ( ! empty( $order->customer ) && $this->verify_collection_proof( $token, 'customer', $order->customer, $user_id ) )
				|| ( ! empty( $order->sales_rep ) && $this->verify_collection_proof( $token, 'sales_rep', $order->sales_rep, $user_id ) );
		}

		/**
		 * Return a non-sensitive message for a denied public request.
		 *
		 * @param mixed $reason reason.
		 */
		public function get_failure_message( $reason ) {
			switch ( $reason ) {
				case 'login_required':
					return __( 'You must be logged in to view this order.', 'order-tracking' );
				case 'invalid_email':
					return __( 'The submitted email could not be verified for this order.', 'order-tracking' );
				case 'invalid_token':
					return __( 'This tracking link is invalid or has expired.', 'order-tracking' );
				case 'order_not_found':
					return __( 'No matching order could be found.', 'order-tracking' );
				default:
					return __( 'You are not authorized to view this order.', 'order-tracking' );
			}
		}

		/**
		 * Check an explicit linked Customer/Sales Rep identity.
		 *
		 * @param mixed $order order.
		 * @param mixed $user_id user id.
		 */
		public function is_authorized_user( $order, $user_id ) {
			if ( ! is_object( $order ) || empty( $user_id ) ) {
				return false;
			}

			return $order->verify_order_user_association( $user_id );
		}

		/**
		 * Handle the is staff operation.
		 */
		private function is_staff() {
			global $ewd_otp_controller;

			$capability = $ewd_otp_controller->settings->get_setting( 'access-role' );
			return ! empty( $capability ) && current_user_can( $capability );
		}

		/**
		 * Handle the has matching account email operation.
		 *
		 * @param mixed $order order.
		 * @param mixed $user_id user id.
		 */
		private function has_matching_account_email( $order, $user_id ) {
			if ( ! is_object( $order ) || ! $user_id ) {
				return false;
			}
			$user        = get_userdata( $user_id );
			$user_email  = is_object( $user ) ? $this->normalize_email( $user->user_email ) : '';
			$order_email = $this->normalize_email( isset( $order->email ) ? $order->email : '' );
			return '' !== $user_email && '' !== $order_email && $user_email === $order_email;
		}

		/**
		 * Handle the normalize email operation.
		 *
		 * @param mixed $email email.
		 */
		private function normalize_email( $email ) {
			return strtolower( trim( sanitize_email( $email ) ) );
		}

		/**
		 * Handle the first value operation.
		 *
		 * @param mixed $source source.
		 * @param mixed $keys keys.
		 */
		private function first_value( $source, $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $source[ $key ] ) && '' !== (string) $source[ $key ] ) {
					return $source[ $key ];
				}
			}

			return '';
		}
	}
}
