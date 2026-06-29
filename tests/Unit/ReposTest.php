<?php
/**
 * Tests for repo-list normalization.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Repos;

/**
 * @covers \WCGP\Repos::normalize
 * @covers \WCGP\Repos::primary
 */
final class ReposTest extends TestCase {

	public function test_trims_and_drops_blank_rows(): void {
		$entries = Repos::normalize( array(
			array( 'repo' => '  org/moodle-mod_videotime  ' ),
			array( 'repo' => '' ),
			array( 'nonsense' => true ),
		) );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'org/moodle-mod_videotime', $entries[0]['repo'] );
	}

	public function test_deduplicates_case_insensitively(): void {
		$entries = Repos::normalize( array(
			array( 'repo' => 'org/Repo' ),
			array( 'repo' => 'org/repo' ),
		) );
		$this->assertCount( 1, $entries );
	}

	public function test_defaults_first_entry_as_primary(): void {
		$entries = Repos::normalize( array(
			array( 'repo' => 'org/a' ),
			array( 'repo' => 'org/b' ),
		) );
		$this->assertTrue( $entries[0]['primary'] );
		$this->assertFalse( $entries[1]['primary'] );
		$this->assertSame( 'org/a', Repos::primary( $entries )['repo'] );
	}

	public function test_keeps_first_flagged_primary_and_only_one(): void {
		$entries = Repos::normalize( array(
			array( 'repo' => 'org/a' ),
			array( 'repo' => 'org/b', 'primary' => true ),
			array( 'repo' => 'org/c', 'primary' => true ),
		) );
		$this->assertFalse( $entries[0]['primary'] );
		$this->assertTrue( $entries[1]['primary'] );
		$this->assertFalse( $entries[2]['primary'] );
		$this->assertSame( 'org/b', Repos::primary( $entries )['repo'] );
	}

	public function test_carries_path_override(): void {
		$entries = Repos::normalize( array(
			array( 'repo' => 'org/a', 'path' => ' /custom/place/ ' ),
		) );
		$this->assertSame( '/custom/place/', $entries[0]['path'] );
	}

	public function test_empty_input_yields_empty_list(): void {
		$this->assertSame( array(), Repos::normalize( array() ) );
		$this->assertSame( array(), Repos::normalize( 'not-an-array' ) );
		$this->assertNull( Repos::primary( array() ) );
	}
}
