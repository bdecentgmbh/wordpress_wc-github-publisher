<?php
/**
 * Downloads a (possibly private) GitHub release asset into WooCommerce's
 * protected uploads directory.
 *
 * @package WCGP
 */

namespace WCGP\GitHub;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves GitHub's private-asset 302 redirect and streams the asset to disk.
 *
 * Two destinations are supported:
 * - {@see download()} / {@see download_archive()} land the file in
 *   `uploads/woocommerce_uploads/` so WooCommerce serves it under its own access
 *   rules (used for single-asset products).
 * - {@see download_to_temp()} / {@see download_archive_to_temp()} stop at a temp
 *   file, so a bundle of several assets can be assembled into one zip and only
 *   the final zip is moved into uploads.
 *
 * The token is never sent to the signed (storage) host.
 */
class AssetDownloader {

	/**
	 * GitHub client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client GitHub client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Download an asset and store it in the protected uploads directory.
	 *
	 * @param string $repo     "owner/repo".
	 * @param int    $asset_id GitHub asset id.
	 * @param string $filename Desired filename.
	 * @return array|WP_Error array( 'file' => absolute path, 'url' => url ) or WP_Error.
	 */
	public function download( $repo, $asset_id, $filename ) {
		$tmp = $this->download_to_temp( $repo, $asset_id, $filename );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		return $this->move_into_uploads( $tmp['file'], $tmp['name'] );
	}

	/**
	 * Download GitHub's auto-generated source archive for a ref into uploads.
	 *
	 * @param string $repo     "owner/repo".
	 * @param string $ref      Git ref (e.g. a release tag).
	 * @param string $filename Desired filename.
	 * @return array|WP_Error array( 'file' => absolute path, 'url' => url ) or WP_Error.
	 */
	public function download_archive( $repo, $ref, $filename ) {
		$tmp = $this->download_archive_to_temp( $repo, $ref, $filename );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		return $this->move_into_uploads( $tmp['file'], $tmp['name'] );
	}

	/**
	 * Download an asset to a temporary file (not moved into uploads).
	 *
	 * @param string $repo     "owner/repo".
	 * @param int    $asset_id GitHub asset id.
	 * @param string $filename Desired filename.
	 * @return array|WP_Error array( 'file' => temp path, 'name' => filename ) or WP_Error.
	 */
	public function download_to_temp( $repo, $asset_id, $filename ) {
		$repo = $this->client->normalize_repo( $repo );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$api_url = $this->client->api_base() . '/repos/' . $repo . '/releases/assets/' . (int) $asset_id;
		return $this->fetch_to_temp( $api_url, 'application/octet-stream', $filename );
	}

	/**
	 * Download GitHub's source archive for a ref to a temporary file.
	 *
	 * @param string $repo     "owner/repo".
	 * @param string $ref      Git ref (e.g. a release tag).
	 * @param string $filename Desired filename.
	 * @return array|WP_Error array( 'file' => temp path, 'name' => filename ) or WP_Error.
	 */
	public function download_archive_to_temp( $repo, $ref, $filename ) {
		$repo = $this->client->normalize_repo( $repo );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$api_url = $this->client->api_base() . '/repos/' . $repo . '/zipball/' . rawurlencode( $ref );
		return $this->fetch_to_temp( $api_url, '', $filename );
	}

	/**
	 * Fetch an authorized GitHub URL that redirects to a signed storage URL, and
	 * stream the result into a temporary file.
	 *
	 * @param string $api_url  Authorized GitHub endpoint (asset or archive).
	 * @param string $accept   Optional Accept header (asset endpoints need octet-stream).
	 * @param string $filename Desired filename.
	 * @return array|WP_Error array( 'file' => temp path, 'name' => filename ) or WP_Error.
	 */
	private function fetch_to_temp( $api_url, $accept, $filename ) {
		// Large Moodle zips can take a while; lift the time limit where allowed.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$headers = array(
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'wc-github-publisher',
			'Authorization'        => 'Bearer ' . $this->client->get_token_value(),
		);
		if ( '' !== $accept ) {
			$headers['Accept'] = $accept;
		}

		// Step 1: request without following the redirect, so we can capture the
		// short-lived signed Location URL.
		$response = wp_remote_get(
			$api_url,
			array(
				'headers'     => $headers,
				'redirection' => 0,
				'timeout'     => 30,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$download_url = '';
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$download_url = wp_remote_retrieve_header( $response, 'location' );
		} elseif ( 200 === $code ) {
			// Some configurations return the bytes directly.
			return $this->store_bytes_to_temp( wp_remote_retrieve_body( $response ), $filename );
		}

		if ( empty( $download_url ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wcgp_download', sprintf( __( 'Unexpected response fetching the file (HTTP %d). Check token access.', 'wc-github-publisher' ), $code ) );
		}

		// Step 2: download the signed URL to a temp file. Crucially, do NOT send
		// the GitHub Authorization header to the storage host — it rejects it.
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'wcgp_tmp', __( 'Could not create a temporary file.', 'wc-github-publisher' ) );
		}

		$download = wp_remote_get(
			$download_url,
			array(
				'timeout'  => 600,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => array( 'User-Agent' => 'wc-github-publisher' ),
			)
		);
		if ( is_wp_error( $download ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $download;
		}
		$download_code = (int) wp_remote_retrieve_response_code( $download );
		if ( $download_code < 200 || $download_code >= 300 ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wcgp_download', sprintf( __( 'Download failed (HTTP %d).', 'wc-github-publisher' ), $download_code ) );
		}

		return array(
			'file' => $tmp,
			'name' => $filename,
		);
	}

	/**
	 * Store an in-memory body to a temporary file.
	 *
	 * @param string $bytes    File contents.
	 * @param string $filename Filename.
	 * @return array|WP_Error array( 'file' => temp path, 'name' => filename ) or WP_Error.
	 */
	private function store_bytes_to_temp( $bytes, $filename ) {
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'wcgp_tmp', __( 'Could not create a temporary file.', 'wc-github-publisher' ) );
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		WP_Filesystem();
		$wp_filesystem->put_contents( $tmp, $bytes );
		return array(
			'file' => $tmp,
			'name' => $filename,
		);
	}

	/**
	 * Move a temp file into `uploads/woocommerce_uploads/` via wp_handle_sideload.
	 *
	 * @param string $tmp      Temp file path.
	 * @param string $filename Desired filename.
	 * @return array|WP_Error
	 */
	public function move_into_uploads( $tmp, $filename ) {
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filename = sanitize_file_name( $filename ? $filename : basename( $tmp ) );

		// Make sure the protected base directory exists and denies direct access
		// (mirrors what WooCommerce creates on install).
		$uploads = wp_upload_dir();
		$this->ensure_protected_dir( trailingslashit( $uploads['basedir'] ) . 'woocommerce_uploads' );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$overrides  = array(
			'test_form' => false,
			'test_type' => false, // Trusted GitHub asset (e.g. a Moodle .zip).
		);

		$filter = function ( $dirs ) {
			$dirs['subdir'] = '/woocommerce_uploads' . $dirs['subdir'];
			$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
			$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );
		$result = wp_handle_sideload( $file_array, $overrides );
		remove_filter( 'upload_dir', $filter );

		if ( ! empty( $result['error'] ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'wcgp_sideload', $result['error'] );
		}

		return array(
			'file' => $result['file'],
			'url'  => $result['url'],
		);
	}

	/**
	 * Ensure a directory exists and is protected from direct web access.
	 *
	 * @param string $dir Absolute directory path.
	 */
	private function ensure_protected_dir( $dir ) {
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		WP_Filesystem();
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$wp_filesystem->put_contents( $dir . '/.htaccess', "deny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			$wp_filesystem->put_contents( $dir . '/index.html', '' );
		}
	}
}
