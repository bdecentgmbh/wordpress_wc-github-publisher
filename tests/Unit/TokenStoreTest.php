<?php
/**
 * Tests for the at-rest encryption of the GitHub token.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Security\TokenStore;

/**
 * @covers \WCGP\Security\TokenStore
 */
final class TokenStoreTest extends TestCase {

	public function test_encrypts_and_decrypts_round_trip(): void {
		$plaintext = 'github_pat_11ABCDEFG0123456789_secretvalue';
		$cipher    = TokenStore::encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $cipher, 'Stored value must not be plaintext.' );
		$this->assertStringNotContainsString( $plaintext, $cipher );
		$this->assertSame( $plaintext, TokenStore::decrypt( $cipher ) );
	}

	public function test_ciphertext_is_prefixed_for_versioning(): void {
		$cipher = TokenStore::encrypt( 'something' );
		// v1: = libsodium secretbox, o1: = openssl fallback.
		$this->assertMatchesRegularExpression( '/^(v1|o1):/', $cipher );
	}

	public function test_empty_plaintext_yields_empty_string(): void {
		$this->assertSame( '', TokenStore::encrypt( '' ) );
	}

	public function test_decrypting_garbage_returns_empty_string(): void {
		$this->assertSame( '', TokenStore::decrypt( 'not-a-valid-ciphertext' ) );
		$this->assertSame( '', TokenStore::decrypt( '' ) );
	}

	public function test_tampered_ciphertext_does_not_decrypt(): void {
		$cipher = TokenStore::encrypt( 'sensitive-token' );
		// Flip part of the payload after the version prefix.
		$tampered = substr( $cipher, 0, 3 ) . strrev( substr( $cipher, 3 ) );
		$this->assertSame( '', TokenStore::decrypt( $tampered ) );
	}
}
