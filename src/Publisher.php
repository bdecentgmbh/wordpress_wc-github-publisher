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
use WP_Error;
use WC_Product_Download;

defined( 'ABSPATH' ) || exit;

/**
 * Publisher.
 *
 * Data model:
 * - `_wcgp_repo`       (parent meta)  "owner/repo".
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

		$downloader = new AssetDownloader( $client );
		if ( 'zipball' === $kind ) {
			$stored = $downloader->download_archive( $repo, $tag, $asset['name'] );
		} else {
			$stored = $downloader->download( $repo, $asset['id'], $asset['name'] );
		}
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$download_id  = wp_generate_uuid4();
		$published_at = gmdate( 'c' );
		$record       = array(
			'publish_id'   => $download_id,
			'download_id'  => $download_id,
			'file'         => $stored['file'],
			'url'          => $stored['url'],
			'tag'          => $tag,
			'asset_id'     => (int) $asset['id'],
			'asset_key'    => $asset_key,
			'asset_name'   => $asset['name'],
			'published_at' => $published_at,
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
			'asset_id'      => (int) $asset['id'],
			'asset_key'     => $asset_key,
			'asset_name'    => $asset['name'],
			'tag'           => $tag,
			'file'          => $stored['file'],
			'url'           => $stored['url'],
			'attribute'     => $attribute,
			'value'         => $value,
			'variation_ids' => Targets::is_variable( $product ) ? array_map( 'intval', $target_ids ) : array(),
			'published_at'  => $published_at,
		);
		update_post_meta( $product_id, self::META_PUBLISHED, $index );
		update_post_meta( $product_id, self::META_REPO, $client->normalize_repo( $repo ) );

		// Delete files orphaned by pruning, and clean their index entries.
		$this->sweep_orphans( $product, $orphans );

		return array(
			'publish_id'   => $download_id,
			'asset_id'     => (int) $asset['id'],
			'asset_key'    => $asset_key,
			'asset_name'   => $asset['name'],
			'tag'          => $tag,
			'label'        => $this->build_label( $asset['name'], $tag ),
			'attribute'    => $attribute,
			'value'        => $value,
			'target_label' => $this->target_label( $attribute, $value ),
			'targets'      => count( $target_ids ),
			'published_at' => $published_at,
		);
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
			$download->set_name( $this->build_label( $entry['asset_name'], isset( $entry['tag'] ) ? $entry['tag'] : '' ) );
			$download->set_file( $entry['url'] );
			$downloads[ $entry['download_id'] ] = $download;

			$managed[] = array(
				'publish_id'   => $entry['publish_id'],
				'download_id'  => $entry['download_id'],
				'file'         => isset( $entry['file'] ) ? $entry['file'] : '',
				'url'          => $entry['url'],
				'tag'          => isset( $entry['tag'] ) ? $entry['tag'] : '',
				'asset_id'     => isset( $entry['asset_id'] ) ? (int) $entry['asset_id'] : 0,
				'asset_name'   => $entry['asset_name'],
				'published_at' => isset( $entry['published_at'] ) ? $entry['published_at'] : gmdate( 'c' ),
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
			$download->set_name( $this->build_label( $item['asset_name'], isset( $item['tag'] ) ? $item['tag'] : '' ) );
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
	 * Build a human-friendly download label.
	 *
	 * @param string $asset_name Asset filename.
	 * @param string $tag        Release tag.
	 * @return string
	 */
	public function build_label( $asset_name, $tag ) {
		return $tag ? $asset_name . ' (' . $tag . ')' : $asset_name;
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
		$download->set_name( $this->build_label( $record['asset_name'], $record['tag'] ) );
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
