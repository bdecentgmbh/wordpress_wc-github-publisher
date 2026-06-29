<?php
/**
 * PHPUnit bootstrap.
 *
 * These are pure unit tests: rather than spin up a full WordPress + WooCommerce
 * + MySQL integration stack, we stub the handful of WordPress functions and
 * constants that the units under test actually touch, then exercise the plugin's
 * own logic (naming, repo normalization, token encryption, target matching).
 *
 * @package WCGP
 */

declare( strict_types=1 );

error_reporting( E_ALL ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_reporting_error_reporting

// The plugin files bail unless ABSPATH is defined; give them a value.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Deterministic salts so TokenStore's salt-derived key is stable across a run.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'unit-test-auth-key-0123456789' );
}
if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'unit-test-auth-salt-9876543210' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key-abcdef' );
}

/**
 * Mutable option store backing the get_option() stub. Tests can set values via
 * wcgp_test_set_option() to drive code paths (e.g. the default-org repo rule).
 *
 * @var array<string,mixed>
 */
$GLOBALS['wcgp_test_options'] = array();

/**
 * Set a fake option value for the current test.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 */
function wcgp_test_set_option( $name, $value ) {
	$GLOBALS['wcgp_test_options'][ $name ] = $value;
}

/**
 * Reset all fake option values.
 */
function wcgp_test_reset_options() {
	$GLOBALS['wcgp_test_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['wcgp_test_options'] )
			? $GLOBALS['wcgp_test_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		$args = is_array( $args ) ? $args : array();
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	// A faithful-enough copy of WordPress's sanitize_file_name() for the parts the
	// tests rely on: it strips special characters and collapses whitespace/dashes
	// to a single dash (this is why stored filenames use dashes, not spaces).
	function sanitize_file_name( $filename ) {
		$special_chars = array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr( 0 ) );
		$filename      = str_replace( $special_chars, '', $filename );
		$filename      = preg_replace( '/[\r\n\t -]+/', '-', $filename );
		return trim( $filename, '.-_' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// PSR-4 autoloader for the plugin's own classes (mirrors the fallback loader in
// the main plugin file).
spl_autoload_register(
	function ( $class ) {
		$prefix = 'WCGP\\';
		$len    = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
		$relative = substr( $class, $len );
		$file     = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
