<?php
/**
 * Records the last GitHub API error and the latest rate-limit snapshot, so the
 * admin can be warned about token/auth problems and quota exhaustion.
 *
 * @package WCGP
 */

namespace WCGP;

defined( 'ABSPATH' ) || exit;

/**
 * Small neutral status store shared between the GitHub client and the admin
 * notices. Kept dependency-free so the client does not reach into the admin.
 */
class Status {

	const ERR_OPTION  = 'wcgp_last_error';
	const RATE_OPTION = 'wcgp_rate';

	/**
	 * Record an API error.
	 *
	 * @param string $code    Short error code (e.g. "auth", "rate", "api").
	 * @param string $message Human-readable message.
	 */
	public static function record_error( $code, $message ) {
		update_option(
			self::ERR_OPTION,
			array(
				'code'    => (string) $code,
				'message' => (string) $message,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Clear the recorded error (called after a successful API call).
	 */
	public static function clear_error() {
		if ( false !== get_option( self::ERR_OPTION, false ) ) {
			delete_option( self::ERR_OPTION );
		}
	}

	/**
	 * Get the recorded error.
	 *
	 * @return array|null
	 */
	public static function get_error() {
		$error = get_option( self::ERR_OPTION, null );
		return is_array( $error ) ? $error : null;
	}

	/**
	 * Record the latest rate-limit snapshot.
	 *
	 * @param int $remaining Remaining requests.
	 * @param int $reset     Reset timestamp (epoch seconds).
	 */
	public static function record_rate( $remaining, $reset ) {
		update_option(
			self::RATE_OPTION,
			array(
				'remaining' => (int) $remaining,
				'reset'     => (int) $reset,
				'time'      => time(),
			),
			false
		);
	}

	/**
	 * Get the latest rate-limit snapshot.
	 *
	 * @return array|null
	 */
	public static function get_rate() {
		$rate = get_option( self::RATE_OPTION, null );
		return is_array( $rate ) ? $rate : null;
	}
}
