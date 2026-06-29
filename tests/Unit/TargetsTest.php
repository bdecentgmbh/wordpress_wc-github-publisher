<?php
/**
 * Tests for variation/attribute target matching.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Targets;

/**
 * Minimal stand-in for WC_Product_Variation exposing only get_attributes().
 */
final class FakeVariation {
	/** @var array<string,string> */
	private $attributes;

	public function __construct( array $attributes ) {
		$this->attributes = $attributes;
	}

	public function get_attributes(): array {
		return $this->attributes;
	}
}

/**
 * @covers \WCGP\Targets::variation_matches
 */
final class TargetsTest extends TestCase {

	public function test_exact_value_matches(): void {
		$variation = new FakeVariation( array( 'attribute_pa_platform' => 'moodle' ) );
		$this->assertTrue( Targets::variation_matches( $variation, 'pa_platform', 'moodle' ) );
	}

	public function test_match_is_case_insensitive(): void {
		$variation = new FakeVariation( array( 'attribute_pa_platform' => 'Moodle' ) );
		$this->assertTrue( Targets::variation_matches( $variation, 'pa_platform', 'moodle' ) );
	}

	public function test_any_value_matches_everything(): void {
		// A variation set to "Any" for the attribute (empty value) matches any value.
		$variation = new FakeVariation( array( 'attribute_pa_platform' => '' ) );
		$this->assertTrue( Targets::variation_matches( $variation, 'pa_platform', 'moodle' ) );
	}

	public function test_different_value_does_not_match(): void {
		$variation = new FakeVariation( array( 'attribute_pa_platform' => 'wordpress' ) );
		$this->assertFalse( Targets::variation_matches( $variation, 'pa_platform', 'moodle' ) );
	}

	public function test_attribute_not_used_by_variation_does_not_match(): void {
		$variation = new FakeVariation( array( 'attribute_pa_color' => 'blue' ) );
		$this->assertFalse( Targets::variation_matches( $variation, 'pa_platform', 'moodle' ) );
	}

	public function test_key_prefix_is_normalized(): void {
		// The selector passes "pa_platform" while the variation stores it under the
		// "attribute_" prefix; normalization must bridge the two.
		$variation = new FakeVariation( array( 'attribute_pa_platform' => 'moodle' ) );
		$this->assertTrue( Targets::variation_matches( $variation, 'attribute_pa_platform', 'moodle' ) );
	}
}
