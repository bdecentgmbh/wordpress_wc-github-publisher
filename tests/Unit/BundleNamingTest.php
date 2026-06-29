<?php
/**
 * Tests for bundle label / filename derivation.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Publisher;
use ReflectionMethod;

/**
 * @covers \WCGP\Publisher::bundle_label
 */
final class BundleNamingTest extends TestCase {

	/**
	 * @var Publisher
	 */
	private $publisher;

	protected function setUp(): void {
		$this->publisher = new Publisher();
	}

	public function test_multi_component_bundle_gets_unzip_me_suffix(): void {
		$label = $this->publisher->bundle_label( 'Video Time', 'v1.2.3', 3 );
		$this->assertSame( 'Video Time 1.2.3 — UNZIP ME', $label );
	}

	public function test_single_component_has_no_suffix(): void {
		$label = $this->publisher->bundle_label( 'Video Time', 'v1.2.3', 1 );
		$this->assertSame( 'Video Time 1.2.3', $label );
	}

	public function test_uses_primary_version_from_tag(): void {
		// The Moodle r-marker formatting is reused from build_label/format_version.
		$label = $this->publisher->bundle_label( 'Media Time', 'v1.1-r3', 2 );
		$this->assertSame( 'Media Time 1.1 R3 — UNZIP ME', $label );
	}

	public function test_stored_filename_is_sanitized(): void {
		$label  = $this->publisher->bundle_label( 'Video Time', 'v1.2.3', 3 );
		$method = new ReflectionMethod( Publisher::class, 'build_filename' );
		$method->setAccessible( true );
		$filename = $method->invoke( $this->publisher, $label, 'bundle.zip' );

		// build_filename normalizes the em dash to a hyphen for filesystem safety.
		$this->assertSame( 'Video Time 1.2.3 - UNZIP ME.zip', $filename );
		// On disk: spaces + hyphens collapsed to single dashes.
		$this->assertSame( 'Video-Time-1.2.3-UNZIP-ME.zip', sanitize_file_name( $filename ) );
	}
}
