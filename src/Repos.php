<?php
/**
 * Stores and normalizes the list of GitHub repositories a product publishes from.
 *
 * @package WCGP
 */

namespace WCGP;

defined( 'ABSPATH' ) || exit;

/**
 * A product can bundle several repositories (e.g. Video Time ships as
 * mod_videotime + filter_videotime + tiny_videotime). This stores that ordered
 * list, normalizes it, and stays backward-compatible with the single-repo
 * `_wcgp_repo` meta from before bundles existed.
 */
class Repos {

	const META = '_wcgp_repos';

	/**
	 * Legacy single-repo meta key (pre-bundle).
	 */
	const LEGACY_META = '_wcgp_repo';

	/**
	 * Get a product's normalized repo list.
	 *
	 * @param int $product_id Product id.
	 * @return array<int,array{repo:string,primary:bool,path:string}>
	 */
	public static function get( $product_id ) {
		$raw = get_post_meta( $product_id, self::META, true );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			return self::normalize( $raw );
		}

		// Back-compat: surface a legacy single repo as a one-entry list.
		$legacy = get_post_meta( $product_id, self::LEGACY_META, true );
		if ( is_string( $legacy ) && '' !== trim( $legacy ) ) {
			return self::normalize( array( array( 'repo' => $legacy, 'primary' => true ) ) );
		}

		return array();
	}

	/**
	 * Persist a product's repo list (and keep the legacy meta in sync with the
	 * primary repo so anything still reading it keeps working).
	 *
	 * @param int   $product_id Product id.
	 * @param array $raw        Raw rows from the form.
	 * @return array Normalized list that was stored.
	 */
	public static function save( $product_id, $raw ) {
		$entries = self::normalize( $raw );
		update_post_meta( $product_id, self::META, $entries );

		$primary = self::primary( $entries );
		if ( $primary ) {
			update_post_meta( $product_id, self::LEGACY_META, $primary['repo'] );
		} else {
			delete_post_meta( $product_id, self::LEGACY_META );
		}
		return $entries;
	}

	/**
	 * Normalize raw repo rows: trim, drop blanks, de-duplicate, and guarantee
	 * exactly one primary (the first flagged one, else the first entry).
	 *
	 * Pure (no WordPress calls) so it can be unit-tested directly.
	 *
	 * @param array $raw Raw rows, each { repo, primary?, path? }.
	 * @return array<int,array{repo:string,primary:bool,path:string}>
	 */
	public static function normalize( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$entries = array();
		$seen    = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$repo = isset( $row['repo'] ) ? trim( (string) $row['repo'] ) : '';
			if ( '' === $repo ) {
				continue;
			}
			$key = strtolower( $repo );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$entries[] = array(
				'repo'    => $repo,
				'primary' => ! empty( $row['primary'] ),
				'path'    => isset( $row['path'] ) ? trim( (string) $row['path'] ) : '',
			);
		}

		if ( empty( $entries ) ) {
			return array();
		}

		// Exactly one primary: keep the first flagged, default to the first entry.
		$primary_index = null;
		foreach ( $entries as $i => $entry ) {
			if ( $entry['primary'] ) {
				$primary_index = $i;
				break;
			}
		}
		if ( null === $primary_index ) {
			$primary_index = 0;
		}
		foreach ( $entries as $i => &$entry ) {
			$entry['primary'] = ( $i === $primary_index );
		}
		unset( $entry );

		return $entries;
	}

	/**
	 * The primary entry of a normalized list (or null when empty).
	 *
	 * @param array $entries Normalized entries.
	 * @return array{repo:string,primary:bool,path:string}|null
	 */
	public static function primary( $entries ) {
		foreach ( (array) $entries as $entry ) {
			if ( ! empty( $entry['primary'] ) ) {
				return $entry;
			}
		}
		return ! empty( $entries ) ? $entries[0] : null;
	}
}
