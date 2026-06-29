<?php
/**
 * Thin GitHub REST client for listing releases and reading asset metadata.
 *
 * @package WCGP
 */

namespace WCGP\GitHub;

use WCGP\Security\TokenStore;
use WCGP\Status;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads release/asset metadata from the GitHub REST API. Release listings are
 * cached in a transient and revalidated with an ETag so repeated fetches are
 * cheap and do not burn the rate limit.
 */
class Client {

	const API = 'https://api.github.com';

	/**
	 * GitHub token (decrypted).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string|null $token Optional explicit token; defaults to the stored one.
	 */
	public function __construct( $token = null ) {
		$this->token = ( null !== $token ) ? $token : TokenStore::get_token();
	}

	/**
	 * Whether a token is available.
	 *
	 * @return bool
	 */
	public function has_token() {
		return ! empty( $this->token );
	}

	/**
	 * Base API URL.
	 *
	 * @return string
	 */
	public function api_base() {
		return self::API;
	}

	/**
	 * Raw token value (used by the downloader for the authorized 302 request).
	 *
	 * @return string
	 */
	public function get_token_value() {
		return $this->token;
	}

	/**
	 * Common request headers.
	 *
	 * @param array $extra Extra headers to merge.
	 * @return array
	 */
	private function headers( $extra = array() ) {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'wc-github-publisher',
		);
		if ( $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return array_merge( $headers, $extra );
	}

	/**
	 * List releases for a repository.
	 *
	 * @param string $repo  "owner/repo" (URLs are tolerated).
	 * @param bool   $force Bypass the transient cache.
	 * @return array|WP_Error Array of shaped releases, or WP_Error.
	 */
	public function list_releases( $repo, $force = false ) {
		$repo = $this->normalize_repo( $repo );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$settings  = TokenStore::get_settings();
		$ttl       = max( 60, (int) $settings['ttl'] );
		$cache_key = 'wcgp_releases_' . md5( $repo );
		$etag_key  = 'wcgp_etag_' . md5( $repo );

		if ( $force ) {
			delete_transient( $cache_key );
		} else {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$headers = $this->headers();
		$etag    = get_option( $etag_key );
		if ( $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$response = wp_remote_get(
			self::API . '/repos/' . $repo . '/releases?per_page=30',
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->record_rate( $response );

		if ( 304 === $code ) {
			Status::clear_error();
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
			// Cache expired but GitHub says "not modified" — drop the ETag and refetch.
			delete_option( $etag_key );
			return $this->list_releases( $repo, true );
		}
		if ( 401 === $code || 403 === $code ) {
			$message       = $this->error_message( $response, __( 'GitHub authentication failed. Check the token and its repository access.', 'wc-github-publisher' ) );
			$remaining_hdr = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			$is_rate       = ( 403 === $code && '' !== $remaining_hdr && 0 === (int) $remaining_hdr );
			if ( $is_rate ) {
				$message = __( 'GitHub API rate limit exceeded. Try again later.', 'wc-github-publisher' );
			}
			Status::record_error( $is_rate ? 'rate' : 'auth', $message );
			return new WP_Error( $is_rate ? 'wcgp_rate' : 'wcgp_auth', $message );
		}
		if ( 404 === $code ) {
			/* translators: %s: owner/repo. */
			return new WP_Error( 'wcgp_not_found', sprintf( __( 'Repository "%s" not found, or the token has no access to it.', 'wc-github-publisher' ), $repo ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wcgp_api', $this->error_message( $response, sprintf( __( 'GitHub API error (HTTP %d).', 'wc-github-publisher' ), $code ) ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$releases = array_map(
			function ( $release ) use ( $repo ) {
				return $this->shape_release( $release, $repo );
			},
			$data
		);

		// The /releases list can omit (and mis-order) the actual latest release, so
		// merge in /releases/latest, mark it, and sort by publish date.
		$releases = $this->merge_latest( $repo, $releases );

		$new_etag = wp_remote_retrieve_header( $response, 'etag' );
		if ( $new_etag ) {
			update_option( $etag_key, $new_etag, false );
		}
		set_transient( $cache_key, $releases, $ttl );
		update_option( 'wcgp_meta_' . md5( $repo ), array( 'fetched_at' => time() ), false );
		Status::clear_error();

		return $releases;
	}

	/**
	 * Merge `/releases/latest` into a shaped list, mark the latest, and sort by
	 * publish date (newest first). The list endpoint can omit or mis-order the
	 * actual latest release, so this corrects it.
	 *
	 * @param string $repo     "owner/repo" (already normalized).
	 * @param array  $releases Shaped releases from the list endpoint.
	 * @return array
	 */
	private function merge_latest( $repo, $releases ) {
		$latest = $this->get_latest_release( $repo );
		if ( $latest ) {
			$found = false;
			foreach ( $releases as &$release ) {
				$release['latest'] = ( $release['id'] === $latest['id'] );
				if ( $release['latest'] ) {
					$found = true;
				}
			}
			unset( $release );
			if ( ! $found ) {
				$latest['latest'] = true;
				$releases[]       = $latest;
			}
		}

		usort(
			$releases,
			function ( $a, $b ) {
				// Newest published first; empty publish dates sort last.
				return strcmp( (string) $b['published_at'], (string) $a['published_at'] );
			}
		);

		return $releases;
	}

	/**
	 * Fetch and shape the repository's latest release. Returns null on 404 (no
	 * non-prerelease release) or any error — never fails the surrounding fetch.
	 *
	 * @param string $repo "owner/repo" (already normalized).
	 * @return array|null
	 */
	private function get_latest_release( $repo ) {
		$response = wp_remote_get(
			self::API . '/repos/' . $repo . '/releases/latest',
			array(
				'headers' => $this->headers(),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$this->record_rate( $response );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}
		return $this->shape_release( $data, $repo );
	}

	/**
	 * Get cached fetch metadata for a repository (fetch time + rate snapshot).
	 *
	 * @param string $repo "owner/repo".
	 * @return array{fetched_at:int, rate:?array}
	 */
	public function get_meta( $repo ) {
		$repo = $this->normalize_repo( $repo );
		if ( is_wp_error( $repo ) ) {
			return array(
				'fetched_at' => 0,
				'rate'       => null,
			);
		}
		$meta = get_option( 'wcgp_meta_' . md5( $repo ), array() );
		return array(
			'fetched_at' => isset( $meta['fetched_at'] ) ? (int) $meta['fetched_at'] : 0,
			'rate'       => Status::get_rate(),
		);
	}

	/**
	 * Record the rate-limit snapshot from a response's headers.
	 *
	 * @param array $response WP HTTP response.
	 */
	private function record_rate( $response ) {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
		if ( '' !== $remaining ) {
			Status::record_rate( (int) $remaining, (int) $reset );
		}
	}

	/**
	 * Fetch a single asset's metadata. Always live (no cache) so size and name
	 * are trusted at publish time.
	 *
	 * @param string $repo     "owner/repo".
	 * @param int    $asset_id GitHub asset id.
	 * @return array|WP_Error
	 */
	public function get_asset( $repo, $asset_id ) {
		$repo = $this->normalize_repo( $repo );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$response = wp_remote_get(
			self::API . '/repos/' . $repo . '/releases/assets/' . (int) $asset_id,
			array(
				'headers' => $this->headers(),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->record_rate( $response );
		if ( 401 === $code || 403 === $code ) {
			$message = $this->error_message( $response, __( 'GitHub authentication failed. Check the token and its repository access.', 'wc-github-publisher' ) );
			Status::record_error( 'auth', $message );
			return new WP_Error( 'wcgp_auth', $message );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wcgp_api', sprintf( __( 'Could not read asset metadata (HTTP %d).', 'wc-github-publisher' ), $code ) );
		}
		$asset = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $asset ) || empty( $asset['id'] ) ) {
			return new WP_Error( 'wcgp_api', __( 'Asset not found.', 'wc-github-publisher' ) );
		}
		return array(
			'id'           => (int) $asset['id'],
			'key'          => 'asset:' . (int) $asset['id'],
			'kind'         => 'asset',
			'name'         => isset( $asset['name'] ) ? $asset['name'] : '',
			'size'         => isset( $asset['size'] ) ? (int) $asset['size'] : 0,
			'content_type' => isset( $asset['content_type'] ) ? $asset['content_type'] : 'application/octet-stream',
		);
	}

	/**
	 * Normalize a release record to the fields the UI/publisher need. Uploaded
	 * assets are listed first, then GitHub's auto-generated source zip is appended
	 * so every release is publishable even when it has no uploaded assets.
	 *
	 * @param array  $release Raw GitHub release.
	 * @param string $repo    "owner/repo" (for the source-zip filename).
	 * @return array
	 */
	private function shape_release( $release, $repo ) {
		$assets = array();
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$assets[] = array(
					'id'           => isset( $asset['id'] ) ? (int) $asset['id'] : 0,
					'key'          => 'asset:' . ( isset( $asset['id'] ) ? (int) $asset['id'] : 0 ),
					'kind'         => 'asset',
					'name'         => isset( $asset['name'] ) ? $asset['name'] : '',
					'size'         => isset( $asset['size'] ) ? (int) $asset['size'] : 0,
					'content_type' => isset( $asset['content_type'] ) ? $asset['content_type'] : 'application/octet-stream',
				);
			}
		}

		$tag = isset( $release['tag_name'] ) ? $release['tag_name'] : '';
		if ( '' !== $tag ) {
			$base     = strpos( $repo, '/' ) !== false ? substr( strrchr( $repo, '/' ), 1 ) : $repo;
			$assets[] = array(
				'id'           => 0,
				'key'          => 'source:zip:' . $tag,
				'kind'         => 'zipball',
				'name'         => sanitize_file_name( $base . '-' . $tag . '.zip' ),
				'size'         => 0,
				'content_type' => 'application/zip',
			);
		}

		return array(
			'id'           => isset( $release['id'] ) ? (int) $release['id'] : 0,
			'tag'          => $tag,
			'name'         => isset( $release['name'] ) ? $release['name'] : '',
			'draft'        => ! empty( $release['draft'] ),
			'prerelease'   => ! empty( $release['prerelease'] ),
			'latest'       => false,
			'published_at' => isset( $release['published_at'] ) ? $release['published_at'] : '',
			'assets'       => $assets,
		);
	}

	/**
	 * Validate and normalize a repository reference to "owner/repo".
	 *
	 * @param string $repo Raw input (owner/repo or a GitHub URL).
	 * @return string|WP_Error
	 */
	public function normalize_repo( $repo ) {
		$repo = trim( (string) $repo );
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = preg_replace( '#\.git$#', '', $repo );
		$repo = trim( $repo, '/' );

		// Prepend the default organization when only a repo name was given.
		if ( '' !== $repo && false === strpos( $repo, '/' ) ) {
			$settings = TokenStore::get_settings();
			$org      = isset( $settings['org'] ) ? trim( $settings['org'] ) : '';
			if ( '' !== $org ) {
				$repo = $org . '/' . $repo;
			}
		}

		if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo ) ) {
			return new WP_Error( 'wcgp_repo', __( 'Repository must be in the form "owner/repo" (or just the repo name when a default organization is set).', 'wc-github-publisher' ) );
		}
		return $repo;
	}

	/**
	 * Build an error message, appending GitHub's own message when present.
	 *
	 * @param array  $response WP HTTP response.
	 * @param string $fallback Default message.
	 * @return string
	 */
	private function error_message( $response, $fallback ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			return $fallback . ' (' . $body['message'] . ')';
		}
		return $fallback;
	}
}
