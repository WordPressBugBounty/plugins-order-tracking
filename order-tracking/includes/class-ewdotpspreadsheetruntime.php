<?php
/**
 * Lazy, trusted loader for the plugin-pinned spreadsheet runtime.
 *
 * @package OrderTracking
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

if ( ! class_exists( 'ewdotpSpreadsheetRuntime' ) ) {
	/**
	 * Loads and verifies the bundled spreadsheet runtime.
	 */
	class EwdotpSpreadsheetRuntime {

		/**
		 * Handle the load operation.
		 */
		public static function load() {
			if ( PHP_VERSION_ID < 70400 ) {
				return new WP_Error( 'ewd_otp_spreadsheet_php', __( 'Spreadsheet import and export require PHP 7.4 or newer.', 'order-tracking' ) );
			}

			$runtime_root = wp_normalize_path( EWD_OTP_PLUGIN_DIR . '/lib/PHPSpreadsheet/' );
			$autoload     = $runtime_root . 'vendor/autoload.php';

			if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet', false ) ) {
				if ( ! is_readable( $autoload ) ) {
					return new WP_Error( 'ewd_otp_spreadsheet_missing', __( 'The trusted spreadsheet component is unavailable.', 'order-tracking' ) );
				}

				try {
					require_once $autoload;
				} catch ( Throwable $exception ) {
					return new WP_Error( 'ewd_otp_spreadsheet_unavailable', __( 'The trusted spreadsheet component could not be loaded.', 'order-tracking' ) );
				}
			}

			if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
				return new WP_Error( 'ewd_otp_spreadsheet_unavailable', __( 'The trusted spreadsheet component could not be loaded.', 'order-tracking' ) );
			}

			$reflection = new ReflectionClass( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' );
			$source     = wp_normalize_path( (string) $reflection->getFileName() );
			if ( 0 !== strpos( $source, $runtime_root ) ) {
				return new WP_Error( 'ewd_otp_spreadsheet_conflict', __( 'A conflicting spreadsheet component is active. The import or export was stopped safely.', 'order-tracking' ) );
			}

			return true;
		}
	}
}
