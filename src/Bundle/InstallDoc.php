<?php
/**
 * Renders the INSTALL.md bundled inside a multi-component download.
 *
 * @package WCGP
 */

namespace WCGP\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Builds human-readable install instructions for a bundle of Moodle plugin zips,
 * covering both install routes: Moodle's "Install plugins from ZIP" web UI and
 * manual upload to the server filesystem.
 */
class InstallDoc {

	/**
	 * Render the INSTALL.md contents.
	 *
	 * @param string $product_name Product title (e.g. "Video Time").
	 * @param array  $components    List of components, each:
	 *                              { component, version, inner_name, target_dir, known }.
	 * @return string Markdown document.
	 */
	public static function render( $product_name, $components ) {
		$lines   = array();
		$lines[] = '# ' . $product_name . ' — installation';
		$lines[] = '';
		$lines[] = 'This package bundles ' . count( $components ) . ' Moodle plugin(s). You must **unzip this';
		$lines[] = 'archive first** — it is not itself a Moodle plugin and cannot be uploaded to';
		$lines[] = 'Moodle as-is.';
		$lines[] = '';
		$lines[] = '## Components';
		$lines[] = '';
		$lines[] = '| Plugin (component) | Version | File in this package | Install location |';
		$lines[] = '| --- | --- | --- | --- |';
		foreach ( $components as $c ) {
			$dir   = isset( $c['target_dir'] ) ? $c['target_dir'] : '';
			$note  = empty( $c['known'] ) ? ' *(verify)*' : '';
			$lines[] = sprintf(
				'| `%s` | %s | `%s` | `%s`%s |',
				isset( $c['component'] ) ? $c['component'] : '',
				isset( $c['version'] ) && '' !== $c['version'] ? $c['version'] : '—',
				isset( $c['inner_name'] ) ? $c['inner_name'] : '',
				$dir,
				$note
			);
		}
		$lines[] = '';
		$lines[] = '## Option A — install via the Moodle web interface (recommended)';
		$lines[] = '';
		$lines[] = 'For each `*.zip` in this package, go to **Site administration → Plugins →';
		$lines[] = 'Install plugins**, upload the zip, and follow the prompts. Install each';
		$lines[] = 'component, then complete the database upgrade when Moodle asks.';
		$lines[] = '';
		$lines[] = '## Option B — install manually on the server';
		$lines[] = '';
		$lines[] = 'Unzip each component into the matching location under your Moodle root, so the';
		$lines[] = 'plugin folder lands exactly at the path shown above. For example:';
		$lines[] = '';
		$lines[] = '```';
		foreach ( $components as $c ) {
			$inner = isset( $c['inner_name'] ) ? $c['inner_name'] : '';
			$dir   = isset( $c['target_dir'] ) ? $c['target_dir'] : '';
			$parent = self::parent_dir( $dir );
			$lines[] = sprintf( 'unzip %s -d <moodle>%s', $inner, $parent );
		}
		$lines[] = '```';
		$lines[] = '';
		$lines[] = 'Each plugin\'s folder must sit directly at its install location (e.g.';
		$lines[] = '`<moodle>' . ( isset( $components[0]['target_dir'] ) ? $components[0]['target_dir'] : '/...' ) . '`).';
		$lines[] = 'Then visit **Site administration → Notifications** to run the upgrade.';
		$lines[] = '';
		$lines[] = 'A *(verify)* note above means the install location was guessed from the repo';
		$lines[] = 'name and should be double-checked against the plugin\'s documentation.';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * The parent directory of a plugin's install path (where its zip is unpacked).
	 * e.g. "/mod/videotime" => "/mod".
	 *
	 * @param string $target_dir Plugin install directory.
	 * @return string
	 */
	private static function parent_dir( $target_dir ) {
		$target_dir = rtrim( (string) $target_dir, '/' );
		$slash      = strrpos( $target_dir, '/' );
		if ( false === $slash || 0 === $slash ) {
			return '/';
		}
		return substr( $target_dir, 0, $slash );
	}
}
