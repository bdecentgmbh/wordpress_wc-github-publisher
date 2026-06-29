<?php
/**
 * Tests for the download label / filename derivation in Publisher.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Publisher;
use ReflectionMethod;

/**
 * @covers \WCGP\Publisher
 */
final class PublisherNamingTest extends TestCase {

	/**
	 * @var Publisher
	 */
	private $publisher;

	protected function setUp(): void {
		$this->publisher = new Publisher();
	}

	/**
	 * Invoke a private/protected method by reflection.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args ) {
		$method = new ReflectionMethod( Publisher::class, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->publisher, $args );
	}

	public function test_build_label_combines_product_title_and_version(): void {
		$this->assertSame( 'Media Time 1.1 R3', $this->publisher->build_label( 'Media Time', 'v1.1-r3' ) );
	}

	/**
	 * @dataProvider version_provider
	 */
	public function test_format_version( string $tag, string $expected ): void {
		$this->assertSame( $expected, $this->call( 'format_version', array( $tag ) ) );
	}

	public function version_provider(): array {
		return array(
			'moodle r-marker'   => array( 'v1.1-r3', '1.1 R3' ),
			'plain semver'      => array( '1.4.5', '1.4.5' ),
			'v-prefixed semver' => array( 'v2.0', '2.0' ),
			'empty tag'         => array( '', '' ),
		);
	}

	public function test_build_label_without_tag_is_just_the_title(): void {
		$this->assertSame( 'Media Time', $this->publisher->build_label( 'Media Time', '' ) );
	}

	public function test_build_filename_keeps_extension(): void {
		$name = $this->call( 'build_filename', array( 'Media Time 1.1 R3', 'moodle-tool_mediatime-v1.1-r3.zip' ) );
		$this->assertSame( 'Media Time 1.1 R3.zip', $name );
	}

	public function test_build_filename_without_extension(): void {
		$name = $this->call( 'build_filename', array( 'Media Time 1.1 R3', 'noext' ) );
		$this->assertSame( 'Media Time 1.1 R3', $name );
	}

	public function test_stored_filename_sanitizes_spaces_to_dashes(): void {
		// The display name keeps spaces, but the on-disk filename (after WordPress's
		// sanitize_file_name) uses dashes — this documents that contract.
		$display = $this->publisher->build_label( 'Media Time', 'v1.1-r3' );
		$file    = $this->call( 'build_filename', array( $display, 'asset.zip' ) );
		$this->assertSame( 'Media-Time-1.1-R3.zip', sanitize_file_name( $file ) );
	}

	public function test_label_for_prefers_stored_download_name(): void {
		$record = array(
			'download_name' => 'Media Time 1.1 R3',
			'asset_name'    => 'moodle-tool_mediatime-v1.1-r3.zip',
			'tag'           => 'v1.1-r3',
		);
		$this->assertSame( 'Media Time 1.1 R3', $this->publisher->label_for( $record ) );
	}

	public function test_label_for_falls_back_to_legacy_form(): void {
		// Records published before the product-title naming have no download_name.
		$record = array(
			'asset_name' => 'moodle-tool_mediatime-v1.1-r3.zip',
			'tag'        => 'v1.1-r3',
		);
		$this->assertSame( 'moodle-tool_mediatime-v1.1-r3.zip (v1.1-r3)', $this->publisher->label_for( $record ) );
	}
}
