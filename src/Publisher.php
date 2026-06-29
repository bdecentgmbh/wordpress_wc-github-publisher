<?php
/**
 * Downloads a GitHub asset once and attaches it as a WooCommerce downloadable
 * file to the resolved target(s) — the product itself (simple) or the matching
 * variations (variable / variable-subscription) — then prunes older managed
 * files. WooCommerce owns entitlement and serving from here on.
 *
 * @package WCGP
 */

namespace WCGP;

use WCGP\GitHub\Client;
use WCGP\GitHub\AssetDownloader;
use WCGP\Security\TokenStore;
use WCGP\Bundle\Packager;
use WCGP\Moodle\ComponentMap;
use WP_Error;
use WC_Product_Download;

defined( 'ABSPATH' ) || exit;

/**
 * Publisher.
 *
 * Data model:
 * - `_wcgp_repo`       (parent meta)  primary "owner/repo" (legacy / single-repo).
 * - `_wcgp_repos`      (parent meta)  ordered repo list for bundles (see {@see Repos}).
 * - `_wcgp_managed`    (per-target meta) attached download records for reconcile/prune.
 * - `_wcgp_published`  (parent meta)  index of publish actions for the UI/unpublish.
 */
class Publisher {

	const META_REPO      = '_wcgp_repo';
	const META_MANAGED   = '_wcgp_managed';
	const META_PUBLISHED = '_wcgp_published';

	/**
	 * Download an asset and attach it to the resolved target(s).
	 *
	 * @param int    $product_id Product id.
	 * @param string $repo       "owner/repo".
	 * @param array  $release    Release info (expects 'tag').
	 * @param array  $asset      Asset metadata (id, name, size, content_type).
	 * @param array  $targeting  { attribute?: string, value?: string }. For simple
	 *                           products this is ignored; for variable products an
	 *                           empty value or {@see Targets::ALL} means all variations.
	 * @return array|WP_Error Summary or WP_Error.
	 */
	public function publish( $product_id, $repo, $release, $asset, $targeting = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wcgp_product', __( 'Product not found.', 'wc-github-publisher' ) );
		}

		$client = new Client();
		if ( ! $client->has_token() ) {
			return new WP_Error( 'wcgp_token', __( 'No GitHub token configured. Add one in WooCommerce → GitHub Publisher.', 'wc-github-publisher' ) );
		}

		$attribute = isset( $targeting['attribute'] ) ? (string) $targeting['attribute'] : '';
		$value     = isset( $targeting['value'] ) && '' !== $targeting['value'] ? (string) $targeting['value'] : Targets::ALL;

		$target_ids = Targets::resolve( $product, $attribute, $value );
		if ( empty( $target_ids ) ) {
			return new WP_Error( 'wcgp_no_targets', __( 'No variations match that selection.', 'wc-github-publisher' ) );
		}

		$tag       = isset( $release['tag'] ) ? $release['tag'] : '';
		$kind      = isset( $asset['kind'] ) ? $asset['kind'] : 'asset';
		$asset_key = isset( $asset['key'] ) ? $asset['key'] : 'asset:' . (int) $asset['id'];

		// Human-friendly name from the product title + release version, e.g.
		// "Media Time 1.1 R3". Used as the download label and the stored filename
		// (spaces become dashes once WordPress sanitizes the upload filename).
		$label     = $this->build_label( $product->get_name(), $tag );
		$file_name = $this->build_filename( $label, $asset['name'] );

		$downloader = new AssetDownloader( $client );
		if ( 'zipball' === $kind ) {
			$stored = $downloader->download_archive( $repo, $tag, $file_name );
		} else {
			$stored = $downloader->download( $repo, $asset['id'], $file_name );
		}
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $this->finalize(
			$product,
			$stored,
			array(
				'tag'           => $tag,
				'asset_id'      => (int) $asset['id'],
				'asset_key'     => $asset_key,
				'asset_name'    => $asset['name'],
				'download_name' => $label,
				'repo'          => $client->normalize_repo( $repo ),
			),
			$attribute,
			$value,
			$target_ids
		);
	}

	/**
	 * Download one release asset from each configured repository, package them into
	 * a single deliverable (a wrapped zip with INSTALL.md when there is more than
	 * one), and attach it to the resolved target(s).
	 *
	 * @param int   $product_id Product id.
	 * @param array $selections Per-repo selection: [ { repo, tag, kind, asset_id } ].
	 *                          Defaults to the latest release of each repo, chosen
	 *                          by the bundle composer.
	 * @param array $targeting  { attribute?: string, value?: string }.
	 * @return array|WP_Error Summary or WP_Error.
	 */
	public function publish_bundle( $product_id, $selections, $targeting = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wcgp_product', __( 'Product not found.', 'wc-github-publisher' ) );
		}

		$client = new Client();
		if ( ! $client->has_token() ) {
			return new WP_Error( 'wcgp_token', __( 'No GitHub token configured. Add one in WooCommerce → GitHub Publisher.', 'wc-github-publisher' ) );
		}

		$entries = Repos::get( $product_id );
		if ( empty( $entries ) ) {
			return new WP_Error( 'wcgp_no_repos', __( 'No repositories are configured for this product.', 'wc-github-publisher' ) );
		}

		$attribute  = isset( $targeting['attribute'] ) ? (string) $targeting['attribute'] : '';
		$value      = isset( $targeting['value'] ) && '' !== $targeting['value'] ? (string) $targeting['value'] : Targets::ALL;
		$target_ids = Targets::resolve( $product, $attribute, $value );
		if ( empty( $target_ids ) ) {
			return new WP_Error( 'wcgp_no_targets', __( 'No variations match that selection.', 'wc-github-publisher' ) );
		}

		// Index the incoming selections by normalized repo.
		$by_repo = array();
		foreach ( (array) $selections as $sel ) {
			if ( ! is_array( $sel ) || empty( $sel['repo'] ) ) {
				continue;
			}
			$norm = $client->normalize_repo( $sel['repo'] );
			if ( ! is_wp_error( $norm ) ) {
				$by_repo[ $norm ] = $sel;
			}
		}

		$downloader  = new AssetDownloader( $client );
		$components  = array();
		$summary     = array();
		$primary_tag = '';

		foreach ( $entries as $entry ) {
			$norm = $client->normalize_repo( $entry['repo'] );
			if ( is_wp_error( $norm ) ) {
				$this->cleanup_components( $components );
				return $norm;
			}
			$sel = isset( $by_repo[ $norm ] ) ? $by_repo[ $norm ] : null;
			$tag = $sel && isset( $sel['tag'] ) ? (string) $sel['tag'] : '';
			if ( '' === $tag ) {
				$this->cleanup_components( $components );
				/* translators: %s: repository. */
				return new WP_Error( 'wcgp_bundle', sprintf( __( 'No release selected for %s.', 'wc-github-publisher' ), $norm ) );
			}

			$kind     = isset( $sel['kind'] ) ? (string) $sel['kind'] : 'asset';
			$asset_id = isset( $sel['asset_id'] ) ? (int) $sel['asset_id'] : 0;

			if ( 'zipball' === $kind ) {
				$base = strpos( $norm, '/' ) !== false ? substr( strrchr( $norm, '/' ), 1 ) : $norm;
				$name = sanitize_file_name( $base . '-' . $tag . '.zip' );
				$tmp  = $downloader->download_archive_to_temp( $norm, $tag, $name );
			} else {
				// Re-fetch asset metadata from GitHub — never trust the client for name.
				$asset = $client->get_asset( $norm, $asset_id );
				if ( is_wp_error( $asset ) ) {
					$this->cleanup_components( $components );
					return $asset;
				}
				$name = $asset['name'];
				$tmp  = $downloader->download_to_temp( $norm, $asset_id, $name );
			}
			if ( is_wp_error( $tmp ) ) {
				$this->cleanup_components( $components );
				return $tmp;
			}

			$map          = ComponentMap::resolve( $norm, $entry['path'] );
			$components[] = array(
				'file'       => $tmp['file'],
				'inner_name' => $name,
				'component'  => $map['component'],
				'version'    => $this->format_version( $tag ),
				'target_dir' => $map['target_dir'],
				'known'      => $map['known'],
			);
			$summary[]    = array(
				'component' => $map['component'],
				'repo'      => $norm,
				'tag'       => $tag,
				'version'   => $this->format_version( $tag ),
			);

			if ( ! empty( $entry['primary'] ) ) {
				$primary_tag = $tag;
			}
		}

		if ( '' === $primary_tag && ! empty( $summary ) ) {
			$primary_tag = $summary[0]['tag'];
		}

		$label    = $this->bundle_label( $product->get_name(), $primary_tag, count( $components ) );
		$filename = $this->build_filename( $label, 'bundle.zip' );

		$packager = new Packager( $downloader );
		$stored   = $packager->build( $filename, $product->get_name(), $components );
		if ( is_wp_error( $stored ) ) {
			return $stored; // Packager has already cleaned up the component temp files.
		}

		return $this->finalize(
			$product,
			$stored,
			array(
				'tag'           => $primary_tag,
				'asset_id'      => 0,
				'asset_key'     => 'bundle:' . md5( wp_json_encode( $summary ) ),
				'asset_name'    => $filename,
				'download_name' => $label,
			),
			$attribute,
			$value,
			$target_ids,
			$summary
		);
	}

	/**
	 * Record a stored file against the target(s): attach to each, write the parent
	 * publish-index entry, prune, and sweep orphaned files. Shared by single-asset
	 * and bundle publishing.
	 *
	 * @param \WC_Product $product    Parent product.
	 * @param array       $stored     { file, url } from the downloader/packager.
	 * @param array       $meta       { tag, asset_id, asset_key, asset_name,
	 *                                  download_name, repo? }.
	 * @param string      $attribute  Target attribute.
	 * @param string      $value      Target value (or Targets::ALL).
	 * @param int[]       $target_ids Resolved target ids.
	 * @param array       $components Optional component summary for the UI.
	 * @return array Summary.
	 */
	private function finalize( $product, $stored, $meta, $attribute, $value, $target_ids, $components = array() ) {
		$product_id   = $product->get_id();
		$download_id  = wp_generate_uuid4();
		$published_at = gmdate( 'c' );

		$record = array(
			'publish_id'    => $download_id,
			'download_id'   => $download_id,
			'file'          => $stored['file'],
			'url'           => $stored['url'],
			'tag'           => isset( $meta['tag'] ) ? $meta['tag'] : '',
			'asset_id'      => isset( $meta['asset_id'] ) ? (int) $meta['asset_id'] : 0,
			'asset_key'     => isset( $meta['asset_key'] ) ? $meta['asset_key'] : '',
			'asset_name'    => isset( $meta['asset_name'] ) ? $meta['asset_name'] : '',
			'download_name' => isset( $meta['download_name'] ) ? $meta['download_name'] : '',
			'published_at'  => $published_at,
		);

		$orphans = array();
		foreach ( $target_ids as $target_id ) {
			foreach ( $this->attach_to_target( $target_id, $stored, $record, $download_id ) as $pid => $pfile ) {
				$orphans[ $pid ] = $pfile;
			}
		}

		// Parent-level publish index entry (drives UI, unpublish, auto-coverage).
		$index   = $this->get_index( $product_id );
		$index[] = array(
			'publish_id'    => $download_id,
			'download_id'   => $download_id,
			'asset_id'      => $record['asset_id'],
			'asset_key'     => $record['asset_key'],
			'asset_name'    => $record['asset_name'],
			'download_name' => $record['download_name'],
			'tag'           => $record['tag'],
			'file'          => $stored['file'],
			'url'           => $stored['url'],
			'attribute'     => $attribute,
			'value'         => $value,
			'variation_ids' => Targets::is_variable( $product ) ? array_map( 'intval', $target_ids ) : array(),
			'components'    => array_values( $components ),
			'published_at'  => $published_at,
		);
		update_post_meta( $product_id, self::META_PUBLISHED, $index );
		if ( ! empty( $meta['repo'] ) ) {
			update_post_meta( $product_id, self::META_REPO, $meta['repo'] );
		}

		// Delete files orphaned by pruning, and clean their index entries.
		$this->sweep_orphans( $product, $orphans );

		return array(
			'publish_id'   => $download_id,
			'asset_id'     => $record['asset_id'],
			'asset_key'    => $record['asset_key'],
			'asset_name'   => $record['asset_name'],
			'tag'          => $record['tag'],
			'label'        => $record['download_name'],
			'attribute'    => $attribute,
			'value'        => $value,
			'target_label' => $this->target_label( $attribute, $value ),
			'targets'      => count( $target_ids ),
			'components'   => array_values( $components ),
			'published_at' => $published_at,
		);
	}

	/**
	 * Delete any component temp files (used when a bundle aborts mid-assembly).
	 *
	 * @param array $components Partial component list with temp 'file' paths.
	 */
	private function cleanup_components( $components ) {
		foreach ( (array) $components as $c ) {
			if ( ! empty( $c['file'] ) && file_exists( $c['file'] ) ) {
				@unlink( $c['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Remove a published entry: detach the file from every target, delete it from
	 * disk when no longer referenced, and drop it from the index.
	 *
	 * @param int    $product_id Product id.
	 * @param string $publish_id Publish entry id.
	 * @return array|WP_Error
	 */
	public function unpublish( $product_id, $publish_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wcgp_product', __( 'Product not found.', 'wc-github-publisher' ) );
		}

		$index = $this->get_index( $product_id );
		$entry = null;
		foreach ( $index as $candidate ) {
			if ( isset( $candidate['publish_id'] ) && $candidate['publish_id'] === $publish_id ) {
				$entry = $candidate;
				break;
			}
		}
		if ( null === $entry ) {
			return new WP_Error( 'wcgp_not_managed', __( 'That file is not managed by this plugin.', 'wc-github-publisher' ) );
		}

		$download_id = $entry['download_id'];
		foreach ( $this->all_target_ids( $product ) as $target_id ) {
			$target = wc_get_product( $target_id );
			if ( ! $target ) {
				continue;
			}
			$downloads = $target->get_downloads();
			if ( isset( $downloads[ $download_id ] ) ) {
				unset( $downloads[ $download_id ] );
				$target->set_downloads( $downloads );
				$target->save();
			}
			$managed = $this->get_managed( $target_id );
			$kept    = array();
			$changed = false;
			foreach ( $managed as $item ) {
				if ( isset( $item['download_id'] ) && $item['download_id'] === $download_id ) {
					$changed = true;
					continue;
				}
				$kept[] = $item;
			}
			if ( $changed ) {
				update_post_meta( $target_id, self::META_MANAGED, array_values( $kept ) );
			}
		}

		$this->sweep_orphans( $product, array( $download_id => isset( $entry['file'] ) ? $entry['file'] : '' ) );

		return array(
			'publish_id' => $publish_id,
			'count'      => count( $this->get_index( $product_id ) ),
		);
	}

	/**
	 * Apply any parent mappings that match a (newly saved) variation but are not
	 * yet attached to it — so new period variations are auto-covered.
	 *
	 * @param int $variation_id Variation id.
	 */
	public function apply_mappings_to_variation( $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->get_parent_id() ) {
			return;
		}
		$parent_id = $variation->get_parent_id();
		$index     = $this->get_index( $parent_id );
		if ( empty( $index ) ) {
			return;
		}

		$managed     = $this->get_managed( $variation_id );
		$have         = array();
		foreach ( $managed as $item ) {
			if ( ! empty( $item['download_id'] ) ) {
				$have[ $item['download_id'] ] = true;
			}
		}

		$downloads    = $variation->get_downloads();
		$attached_any = false;
		$index_dirty  = false;
		foreach ( $index as $k => $entry ) {
			$value = isset( $entry['value'] ) ? $entry['value'] : Targets::ALL;
			$attr  = isset( $entry['attribute'] ) ? $entry['attribute'] : '';
			$match = ( Targets::ALL === $value || '' === $attr ) || Targets::variation_matches( $variation, $attr, $value );
			if ( ! $match || isset( $have[ $entry['download_id'] ] ) ) {
				continue;
			}
			$download = new WC_Product_Download();
			$download->set_id( $entry['download_id'] );
			$download->set_name( $this->label_for( $entry ) );
			$download->set_file( $entry['url'] );
			$downloads[ $entry['download_id'] ] = $download;

			$managed[] = array(
				'publish_id'    => $entry['publish_id'],
				'download_id'   => $entry['download_id'],
				'file'          => isset( $entry['file'] ) ? $entry['file'] : '',
				'url'           => $entry['url'],
				'tag'           => isset( $entry['tag'] ) ? $entry['tag'] : '',
				'asset_id'      => isset( $entry['asset_id'] ) ? (int) $entry['asset_id'] : 0,
				'asset_name'    => $entry['asset_name'],
				'download_name' => $this->label_for( $entry ),
				'published_at'  => isset( $entry['published_at'] ) ? $entry['published_at'] : gmdate( 'c' ),
			);
			$attached_any = true;

			$covered = isset( $entry['variation_ids'] ) ? (array) $entry['variation_ids'] : array();
			if ( ! in_array( (int) $variation_id, $covered, true ) ) {
				$covered[]                      = (int) $variation_id;
				$index[ $k ]['variation_ids'] = $covered;
				$index_dirty                  = true;
			}
		}

		if ( $attached_any ) {
			$variation->set_downloadable( true );
			$variation->set_virtual( true );
			$variation->set_downloads( $downloads );
			$variation->save();
			update_post_meta( $variation_id, self::META_MANAGED, $managed );
		}
		if ( $index_dirty ) {
			update_post_meta( $parent_id, self::META_PUBLISHED, array_values( $index ) );
		}
	}

	/**
	 * Re-attach a target's managed files (used after a product/variation save so a
	 * manual update never drops a published file).
	 *
	 * @param int $target_id Product or variation id.
	 */
	public function reconcile_target( $target_id ) {
		$managed = $this->get_managed( $target_id );
		if ( empty( $managed ) ) {
			return;
		}
		$target = wc_get_product( $target_id );
		if ( ! $target ) {
			return;
		}
		$downloads = $target->get_downloads();
		$changed   = false;
		foreach ( $managed as $item ) {
			if ( empty( $item['download_id'] ) || isset( $downloads[ $item['download_id'] ] ) ) {
				continue;
			}
			$download = new WC_Product_Download();
			$download->set_id( $item['download_id'] );
			$download->set_name( $this->label_for( $item ) );
			$download->set_file( $item['url'] );
			$downloads[ $item['download_id'] ] = $download;
			$changed                           = true;
		}
		if ( $changed ) {
			$target->set_downloadable( true );
			$target->set_downloads( $downloads );
			$target->save();
		}
	}

	/**
	 * Build a human-friendly download label from the product title and release
	 * version, e.g. ( "Media Time", "v1.1-r3" ) => "Media Time 1.1 R3".
	 *
	 * @param string $product_name Product title.
	 * @param string $tag          Release tag.
	 * @return string
	 */
	public function build_label( $product_name, $tag ) {
		$version = $this->format_version( $tag );
		return '' !== $version ? trim( $product_name . ' ' . $version ) : $product_name;
	}

	/**
	 * Build the label for a published bundle. Multi-component bundles get a
	 * " — UNZIP ME" suffix so customers know the download must be unpacked before
	 * it can be installed into Moodle.
	 *
	 * @param string $product_name    Product title.
	 * @param string $primary_tag     Tag of the primary repo (drives the version).
	 * @param int    $component_count Number of components in the bundle.
	 * @return string
	 */
	public function bundle_label( $product_name, $primary_tag, $component_count ) {
		$label = $this->build_label( $product_name, $primary_tag );
		return $component_count > 1 ? $label . ' — UNZIP ME' : $label;
	}

	/**
	 * Turn a release tag into a display version: drop a leading "v", turn dashes
	 * into spaces, and capitalise a letter that prefixes a number (e.g. the "r" in
	 * a Moodle release marker). "v1.1-r3" => "1.1 R3".
	 *
	 * @param string $tag Release tag.
	 * @return string
	 */
	private function format_version( $tag ) {
		$version = preg_replace( '/^v(?=\d)/i', '', (string) $tag );
		$version = str_replace( '-', ' ', $version );
		$version = preg_replace_callback(
			'/(?<=\s|^)([a-z])(?=\d)/i',
			static function ( $m ) {
				return strtoupper( $m[1] );
			},
			$version
		);
		return trim( $version );
	}

	/**
	 * Build the on-disk filename from the display label, preserving the asset's
	 * extension. "Media Time 1.1 R3" + ".zip" => "Media Time 1.1 R3.zip" (WordPress
	 * then sanitises spaces to dashes when the file is stored).
	 *
	 * @param string $label    Display label.
	 * @param string $original Original asset filename (for its extension).
	 * @return string
	 */
	private function build_filename( $label, $original ) {
		$ext = pathinfo( (string) $original, PATHINFO_EXTENSION );
		// Normalize the em dash used in bundle labels ("— UNZIP ME") to a hyphen so
		// the on-disk filename stays ASCII-clean (WordPress keeps multibyte dashes).
		$base = str_replace( '—', '-', (string) $label );
		return $ext ? $base . '.' . $ext : $base;
	}

	/**
	 * Resolve a record's download label, preferring the stored display name and
	 * falling back to the legacy "asset (tag)" form for records created before the
	 * product-title naming was introduced.
	 *
	 * @param array $record Managed/index record.
	 * @return string
	 */
	public function label_for( $record ) {
		if ( ! empty( $record['download_name'] ) ) {
			return $record['download_name'];
		}
		$name = isset( $record['asset_name'] ) ? $record['asset_name'] : '';
		$tag  = isset( $record['tag'] ) ? $record['tag'] : '';
		return $tag ? $name . ' (' . $tag . ')' : $name;
	}

	/**
	 * Build a short label describing a publish target.
	 *
	 * @param string $attribute Attribute name.
	 * @param string $value     Attribute value (or Targets::ALL).
	 * @return string
	 */
	public function target_label( $attribute, $value ) {
		if ( '' === $attribute || Targets::ALL === $value ) {
			return __( 'All variations', 'wc-github-publisher' );
		}
		return wc_attribute_label( $attribute ) . ' = ' . Targets::value_label( $attribute, $value );
	}

	/**
	 * Attach the stored file to one target and prune that target to the latest N.
	 *
	 * @param int    $target_id   Product or variation id.
	 * @param array  $stored      { file, url }.
	 * @param array  $record      Managed record template.
	 * @param string $download_id Download id.
	 * @return array Map of pruned download_id => file path (deferred deletion).
	 */
	private function attach_to_target( $target_id, $stored, $record, $download_id ) {
		$target = wc_get_product( $target_id );
		if ( ! $target ) {
			return array();
		}

		$downloads = $target->get_downloads();
		$download  = new WC_Product_Download();
		$download->set_id( $download_id );
		$download->set_name( $this->label_for( $record ) );
		$download->set_file( $stored['url'] );
		$downloads[ $download_id ] = $download;

		$target->set_downloadable( true );
		$target->set_virtual( true );
		$target->set_downloads( $downloads );
		$target->save();

		$managed   = $this->get_managed( $target_id );
		$managed[] = $record;

		list( $managed, $removed ) = $this->prune_target( $target, $managed );
		update_post_meta( $target_id, self::META_MANAGED, $managed );

		return $removed;
	}

	/**
	 * Keep only the latest N managed files on a target. File deletion is deferred
	 * to {@see sweep_orphans()} because files may be shared across variations.
	 *
	 * @param \WC_Product $target  Target product/variation.
	 * @param array       $managed Managed records (oldest first).
	 * @return array [ kept_records, removed_map(download_id => file) ].
	 */
	private function prune_target( $target, $managed ) {
		$keep_n = max( 1, (int) TokenStore::get_settings()['retention'] );
		if ( count( $managed ) <= $keep_n ) {
			return array( array_values( $managed ), array() );
		}

		$remove = array_slice( $managed, 0, count( $managed ) - $keep_n );
		$keep   = array_slice( $managed, count( $managed ) - $keep_n );

		$downloads = $target->get_downloads();
		$removed   = array();
		foreach ( $remove as $old ) {
			if ( isset( $downloads[ $old['download_id'] ] ) ) {
				unset( $downloads[ $old['download_id'] ] );
			}
			$removed[ $old['download_id'] ] = isset( $old['file'] ) ? $old['file'] : '';
		}
		$target->set_downloads( $downloads );
		$target->save();

		return array( array_values( $keep ), $removed );
	}

	/**
	 * Delete candidate files that are no longer referenced by any target, and
	 * remove their entries from the parent publish index.
	 *
	 * @param \WC_Product $product    Parent product.
	 * @param array       $candidates Map of download_id => file path.
	 */
	private function sweep_orphans( $product, $candidates ) {
		if ( empty( $candidates ) ) {
			return;
		}
		$referenced = $this->referenced_download_ids( $product );
		$index      = $this->get_index( $product->get_id() );
		$dirty      = false;

		foreach ( $candidates as $download_id => $file ) {
			if ( in_array( $download_id, $referenced, true ) ) {
				continue; // Still attached somewhere.
			}
			if ( $file && file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			foreach ( $index as $k => $entry ) {
				if ( isset( $entry['download_id'] ) && $entry['download_id'] === $download_id ) {
					unset( $index[ $k ] );
					$dirty = true;
				}
			}
		}

		if ( $dirty ) {
			update_post_meta( $product->get_id(), self::META_PUBLISHED, array_values( $index ) );
		}
	}

	/**
	 * All download ids currently referenced across every target's managed list.
	 *
	 * @param \WC_Product $product Parent product.
	 * @return string[]
	 */
	private function referenced_download_ids( $product ) {
		$ids = array();
		foreach ( $this->all_target_ids( $product ) as $target_id ) {
			foreach ( $this->get_managed( $target_id ) as $item ) {
				if ( ! empty( $item['download_id'] ) ) {
					$ids[] = $item['download_id'];
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Target ids for a product: the product itself, or all its variations.
	 *
	 * @param \WC_Product $product Product.
	 * @return int[]
	 */
	private function all_target_ids( $product ) {
		if ( Targets::is_variable( $product ) ) {
			return array_map( 'intval', $product->get_children() );
		}
		return array( $product->get_id() );
	}

	/**
	 * Get a target's managed records.
	 *
	 * @param int $target_id Product/variation id.
	 * @return array
	 */
	private function get_managed( $target_id ) {
		$managed = get_post_meta( $target_id, self::META_MANAGED, true );
		return is_array( $managed ) ? $managed : array();
	}

	/**
	 * Get the parent publish index.
	 *
	 * @param int $product_id Parent product id.
	 * @return array
	 */
	public function get_index( $product_id ) {
		$index = get_post_meta( $product_id, self::META_PUBLISHED, true );
		return is_array( $index ) ? $index : array();
	}
}
