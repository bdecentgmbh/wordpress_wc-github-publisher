<?php
/**
 * Assembles downloaded release assets into a single deliverable file.
 *
 * @package WCGP
 */

namespace WCGP\Bundle;

use WCGP\GitHub\AssetDownloader;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one or more downloaded component zips into the file that gets attached to
 * the product:
 * - A single component is passed through unchanged (no wrapping).
 * - Several components are wrapped in one outer zip containing each component zip
 *   intact plus a generated INSTALL.md, then moved into WooCommerce's protected
 *   uploads directory.
 */
class Packager {

	/**
	 * Asset downloader (used for its protected-uploads sideload step).
	 *
	 * @var AssetDownloader
	 */
	private $downloader;

	/**
	 * Constructor.
	 *
	 * @param AssetDownloader $downloader Downloader providing move_into_uploads().
	 */
	public function __construct( AssetDownloader $downloader ) {
		$this->downloader = $downloader;
	}

	/**
	 * Build the deliverable and move it into the protected uploads directory.
	 *
	 * @param string $bundle_filename Final filename (e.g. "Video Time 1.2.3 — UNZIP ME.zip").
	 * @param string $product_name    Product title (for INSTALL.md).
	 * @param array  $components       Each: {
	 *                                   file:string (temp path), inner_name:string,
	 *                                   component:string, version:string,
	 *                                   target_dir:string, known:bool
	 *                                 }.
	 * @return array|WP_Error array( 'file' => path, 'url' => url ) or WP_Error.
	 */
	public function build( $bundle_filename, $product_name, $components ) {
		$components = array_values( $components );

		if ( empty( $components ) ) {
			return new WP_Error( 'wcgp_bundle', __( 'No components to package.', 'wc-github-publisher' ) );
		}

		// Single component: attach the asset as-is, under the friendly filename.
		if ( 1 === count( $components ) ) {
			$only   = $components[0];
			$result = $this->downloader->move_into_uploads( $only['file'], $bundle_filename );
			$this->cleanup( $components );
			return $result;
		}

		if ( ! class_exists( ZipArchive::class ) ) {
			$this->cleanup( $components );
			return new WP_Error( 'wcgp_zip', __( 'The PHP Zip extension is required to bundle multiple repositories.', 'wc-github-publisher' ) );
		}

		$zip_tmp = wp_tempnam( $bundle_filename );
		if ( ! $zip_tmp ) {
			$this->cleanup( $components );
			return new WP_Error( 'wcgp_tmp', __( 'Could not create a temporary file.', 'wc-github-publisher' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_tmp, ZipArchive::OVERWRITE ) ) {
			@unlink( $zip_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->cleanup( $components );
			return new WP_Error( 'wcgp_zip', __( 'Could not create the bundle archive.', 'wc-github-publisher' ) );
		}

		$used = array();
		foreach ( $components as $component ) {
			if ( empty( $component['file'] ) || ! file_exists( $component['file'] ) ) {
				continue;
			}
			$inner = $this->unique_name( $used, (string) $component['inner_name'] );
			$zip->addFile( $component['file'], $inner );
		}

		$zip->addFromString( 'INSTALL.md', InstallDoc::render( $product_name, $this->doc_components( $components ) ) );
		$zip->close();

		$result = $this->downloader->move_into_uploads( $zip_tmp, $bundle_filename );
		$this->cleanup( $components );
		return $result;
	}

	/**
	 * Map component records to the shape InstallDoc expects.
	 *
	 * @param array $components Components.
	 * @return array
	 */
	private function doc_components( $components ) {
		$out = array();
		foreach ( $components as $c ) {
			$out[] = array(
				'component'  => isset( $c['component'] ) ? $c['component'] : '',
				'version'    => isset( $c['version'] ) ? $c['version'] : '',
				'inner_name' => isset( $c['inner_name'] ) ? $c['inner_name'] : '',
				'target_dir' => isset( $c['target_dir'] ) ? $c['target_dir'] : '',
				'known'      => ! empty( $c['known'] ),
			);
		}
		return $out;
	}

	/**
	 * Ensure each inner filename is unique within the archive.
	 *
	 * @param array  $used Map of names already used (by reference).
	 * @param string $name Desired name.
	 * @return string
	 */
	private function unique_name( &$used, $name ) {
		$name = '' !== $name ? $name : 'component.zip';
		if ( ! isset( $used[ $name ] ) ) {
			$used[ $name ] = true;
			return $name;
		}
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$base = '' !== $ext ? substr( $name, 0, -( strlen( $ext ) + 1 ) ) : $name;
		$i    = 2;
		do {
			$candidate = $ext ? $base . '-' . $i . '.' . $ext : $base . '-' . $i;
			++$i;
		} while ( isset( $used[ $candidate ] ) );
		$used[ $candidate ] = true;
		return $candidate;
	}

	/**
	 * Delete component temp files.
	 *
	 * @param array $components Components.
	 */
	private function cleanup( $components ) {
		foreach ( $components as $c ) {
			if ( ! empty( $c['file'] ) && file_exists( $c['file'] ) ) {
				@unlink( $c['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
