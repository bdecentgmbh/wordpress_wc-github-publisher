<?php
/**
 * Encrypted storage for the GitHub token plus plugin settings.
 *
 * @package WCGP
 */

namespace WCGP\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the GitHub Personal Access Token encrypted at rest and exposes the
 * plugin's option bag. The encryption key is derived from WordPress salts, so a
 * raw database dump alone does not reveal the token.
 */
class TokenStore {

	const OPTION = 'wcgp_settings';

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'token'     => '', // Encrypted.
			'org'       => '',
			'retention' => 3,
			'allowlist' => '',
			'ttl'       => 600,
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key() {
		$material  = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$material .= defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
		$material .= defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';
		return hash( 'sha256', 'wcgp|' . $material, true );
	}

	/**
	 * Encrypt a plaintext string for storage.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted, prefixed, base64 value (or empty string).
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return 'v1:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		// Fallback for environments without libsodium.
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return 'o1:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Stored value from {@see encrypt()}.
	 * @return string Plaintext (or empty string on failure).
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'v1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw    = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $stored, 'o1:' ) ) {
			$raw    = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= 16 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$cipher = substr( $raw, 16 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	/**
	 * Get the decrypted GitHub token.
	 *
	 * @return string
	 */
	public static function get_token() {
		$settings = self::get_settings();
		return self::decrypt( $settings['token'] );
	}

	/**
	 * Whether a token is configured.
	 *
	 * @return bool
	 */
	public static function has_token() {
		return '' !== self::get_token();
	}
}
