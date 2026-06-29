<?php
/**
 * Tests for repo-name -> Moodle component / install-path derivation.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Moodle\ComponentMap;

/**
 * @covers \WCGP\Moodle\ComponentMap
 */
final class ComponentMapTest extends TestCase {

	/**
	 * @dataProvider repo_provider
	 */
	public function test_resolves_known_repos( string $repo, string $component, string $dir ): void {
		$resolved = ComponentMap::resolve( $repo );
		$this->assertSame( $component, $resolved['component'] );
		$this->assertSame( $dir, $resolved['target_dir'] );
		$this->assertTrue( $resolved['known'] );
	}

	public function repo_provider(): array {
		return array(
			'mod'    => array( 'bdecentgmbh/moodle-mod_videotime', 'mod_videotime', '/mod/videotime' ),
			'filter' => array( 'bdecentgmbh/moodle-filter_videotime', 'filter_videotime', '/filter/videotime' ),
			'tiny'   => array( 'bdecentgmbh/moodle-tiny_videotime', 'tiny_videotime', '/lib/editor/tiny/plugins/videotime' ),
			'format' => array( 'bdecentgmbh/moodle-format_kickstart', 'format_kickstart', '/course/format/kickstart' ),
			'local'  => array( 'bdecentgmbh/moodle-local_kickstart_pro', 'local_kickstart_pro', '/local/kickstart_pro' ),
		);
	}

	public function test_accepts_url_and_bare_names(): void {
		$this->assertSame( 'mod_videotime', ComponentMap::resolve( 'https://github.com/bdecentgmbh/moodle-mod_videotime' )['component'] );
		$this->assertSame( 'mod_videotime', ComponentMap::resolve( 'moodle-mod_videotime' )['component'] );
		$this->assertSame( 'mod_videotime', ComponentMap::resolve( 'bdecentgmbh/moodle-mod_videotime.git' )['component'] );
	}

	public function test_name_with_underscores_splits_on_first_only(): void {
		$resolved = ComponentMap::resolve( 'org/moodle-local_kickstart_pro' );
		$this->assertSame( 'local', $resolved['type'] );
		$this->assertSame( 'kickstart_pro', $resolved['name'] );
	}

	public function test_override_wins_over_derived_path(): void {
		$resolved = ComponentMap::resolve( 'org/moodle-mod_videotime', 'custom/place' );
		$this->assertSame( '/custom/place', $resolved['target_dir'] );
	}

	public function test_unknown_type_falls_back_and_is_flagged(): void {
		$resolved = ComponentMap::resolve( 'org/moodle-wibble_thing' );
		$this->assertFalse( $resolved['known'] );
		$this->assertSame( '/wibble/thing', $resolved['target_dir'] );
	}
}
