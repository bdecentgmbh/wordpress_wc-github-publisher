<?php
/**
 * Tests for repository reference normalization in the GitHub client.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\GitHub\Client;
use WP_Error;

/**
 * @covers \WCGP\GitHub\Client::normalize_repo
 */
final class ClientRepoTest extends TestCase {

	/**
	 * @var Client
	 */
	private $client;

	protected function setUp(): void {
		wcgp_test_reset_options();
		// Pass an explicit token so the constructor does not read settings.
		$this->client = new Client( 'test-token' );
	}

	/**
	 * @dataProvider valid_provider
	 */
	public function test_normalizes_valid_references( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->client->normalize_repo( $input ) );
	}

	public function valid_provider(): array {
		return array(
			'owner/repo'        => array( 'bdecentgmbh/moodle-tool', 'bdecentgmbh/moodle-tool' ),
			'https url'         => array( 'https://github.com/bdecentgmbh/moodle-tool', 'bdecentgmbh/moodle-tool' ),
			'http url'          => array( 'http://github.com/bdecentgmbh/moodle-tool', 'bdecentgmbh/moodle-tool' ),
			'.git suffix'       => array( 'bdecentgmbh/moodle-tool.git', 'bdecentgmbh/moodle-tool' ),
			'trailing slash'    => array( 'bdecentgmbh/moodle-tool/', 'bdecentgmbh/moodle-tool' ),
			'surrounding space' => array( '  bdecentgmbh/moodle-tool  ', 'bdecentgmbh/moodle-tool' ),
		);
	}

	public function test_prepends_default_org_for_bare_repo(): void {
		wcgp_test_set_option( 'wcgp_settings', array( 'org' => 'bdecentgmbh' ) );
		$this->assertSame( 'bdecentgmbh/my-plugin', $this->client->normalize_repo( 'my-plugin' ) );
	}

	public function test_bare_repo_without_default_org_is_an_error(): void {
		// No org configured -> a bare repo name cannot be resolved.
		$result = $this->client->normalize_repo( 'my-plugin' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcgp_repo', $result->get_error_code() );
	}

	public function test_rejects_garbage_input(): void {
		$result = $this->client->normalize_repo( 'not a repo at all!' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcgp_repo', $result->get_error_code() );
	}
}
