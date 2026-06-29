<?php
/**
 * Tests for INSTALL.md rendering.
 *
 * @package WCGP
 */

declare( strict_types=1 );

namespace WCGP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCGP\Bundle\InstallDoc;

/**
 * @covers \WCGP\Bundle\InstallDoc
 */
final class InstallDocTest extends TestCase {

	private function components(): array {
		return array(
			array(
				'component'  => 'mod_videotime',
				'version'    => '1.2.3',
				'inner_name' => 'moodle-mod_videotime-1.2.3.zip',
				'target_dir' => '/mod/videotime',
				'known'      => true,
			),
			array(
				'component'  => 'tiny_videotime',
				'version'    => '0.4.0',
				'inner_name' => 'moodle-tiny_videotime-0.4.0.zip',
				'target_dir' => '/lib/editor/tiny/plugins/videotime',
				'known'      => true,
			),
		);
	}

	public function test_lists_every_component(): void {
		$doc = InstallDoc::render( 'Video Time', $this->components() );
		$this->assertStringContainsString( 'mod_videotime', $doc );
		$this->assertStringContainsString( 'tiny_videotime', $doc );
	}

	public function test_includes_versions_and_inner_filenames(): void {
		$doc = InstallDoc::render( 'Video Time', $this->components() );
		$this->assertStringContainsString( '1.2.3', $doc );
		$this->assertStringContainsString( 'moodle-mod_videotime-1.2.3.zip', $doc );
	}

	public function test_includes_target_dirs_and_unzip_parent_dir(): void {
		$doc = InstallDoc::render( 'Video Time', $this->components() );
		$this->assertStringContainsString( '/lib/editor/tiny/plugins/videotime', $doc );
		// Manual-install example unzips into the parent directory.
		$this->assertStringContainsString( 'unzip moodle-mod_videotime-1.2.3.zip -d <moodle>/mod', $doc );
	}

	public function test_flags_unverified_paths(): void {
		$components   = $this->components();
		$components[] = array(
			'component'  => 'wibble_thing',
			'version'    => '',
			'inner_name' => 'moodle-wibble_thing.zip',
			'target_dir' => '/wibble/thing',
			'known'      => false,
		);
		$doc = InstallDoc::render( 'Video Time', $components );
		$this->assertStringContainsString( '(verify)', $doc );
	}
}
