<?php
/**
 * Product "GitHub" tab: link a repo, fetch releases, choose a target, and
 * publish assets. Supports simple products and variable / variable-subscription
 * products (publishing to variations by attribute value).
 *
 * @package WCGP
 */

namespace WCGP\Admin;

use WCGP\GitHub\Client;
use WCGP\Publisher;
use WCGP\Targets;
use WCGP\Security\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the product-data tab and its AJAX endpoints, and guarantees that
 * plugin-managed downloadable files survive ordinary product/variation saves.
 */
class ProductGitHubTab {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_repo' ) );
		// Re-attach managed files after WooCommerce processes the save, so a manual
		// "Update" never drops a published file.
		add_action( 'woocommerce_process_product_meta', array( $this, 'reconcile_product' ), 30 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 30, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_wcgp_fetch_releases', array( $this, 'ajax_fetch' ) );
		add_action( 'wp_ajax_wcgp_publish_asset', array( $this, 'ajax_publish' ) );
		add_action( 'wp_ajax_wcgp_unpublish', array( $this, 'ajax_unpublish' ) );
	}

	/**
	 * Register the tab.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['wcgp'] = array(
			'label'    => __( 'GitHub', 'wc-github-publisher' ),
			'target'   => 'wcgp_product_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Render the tab panel.
	 */
	public function panel() {
		global $post;
		$product    = wc_get_product( $post->ID );
		$repo       = get_post_meta( $post->ID, Publisher::META_REPO, true );
		$is_variable = $product && Targets::is_variable( $product );
		?>
		<div id="wcgp_product_data" class="panel woocommerce_options_panel">
			<?php if ( ! TokenStore::has_token() ) : ?>
				<p style="padding:12px 12px 0;">
					<?php
					printf(
						wp_kses_post(
							/* translators: %s: settings URL. */
							__( 'No GitHub token configured yet. Add one under <a href="%s">WooCommerce → GitHub Publisher</a>.', 'wc-github-publisher' )
						),
						esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) )
					);
					?>
				</p>
			<?php endif; ?>

			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'          => '_wcgp_repo',
						'label'       => __( 'GitHub repository', 'wc-github-publisher' ),
						'placeholder' => 'owner/repo',
						'description' => __( 'The repository whose releases you want to publish, e.g. myorg/my-moodle-plugin. If a default organization is set, you can enter just the repo name.', 'wc-github-publisher' ),
						'desc_tip'    => true,
						'value'       => $repo,
					)
				);
				?>
			</div>

			<?php $this->render_published_list( $post->ID, $product ); ?>

			<?php if ( $is_variable ) : ?>
				<div class="options_group">
					<p class="form-field">
						<label for="wcgp-target"><?php esc_html_e( 'Publish to', 'wc-github-publisher' ); ?></label>
						<select id="wcgp-target" style="width:60%;">
							<option value="<?php echo esc_attr( Targets::ALL ); ?>"><?php esc_html_e( 'All variations', 'wc-github-publisher' ); ?></option>
							<?php foreach ( Targets::get_variation_attributes( $product ) as $attr ) : ?>
								<optgroup label="<?php echo esc_attr( $attr['label'] ); ?>">
									<?php foreach ( $attr['values'] as $val ) : ?>
										<option value="<?php echo esc_attr( $attr['name'] . '::' . $val['slug'] ); ?>">
											<?php echo esc_html( $attr['label'] . ': ' . $val['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="description" style="padding:0 12px;">
						<?php esc_html_e( 'Choose which variations receive the next published asset — e.g. Platform: Moodle covers all its subscription periods.', 'wc-github-publisher' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="options_group">
				<p class="form-field">
					<button type="button" class="button" id="wcgp-fetch" data-product="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Fetch releases', 'wc-github-publisher' ); ?>
					</button>
					<button type="button" class="button-link" id="wcgp-refresh" title="<?php esc_attr_e( 'Force a fresh pull from GitHub', 'wc-github-publisher' ); ?>">
						<?php esc_html_e( 'Refresh', 'wc-github-publisher' ); ?>
					</button>
					<span class="spinner" style="float:none;margin-top:0;"></span>
					<span id="wcgp-meta" class="description"></span>
				</p>
				<p class="description" style="padding:0 12px;">
					<?php esc_html_e( 'Publishing attaches the file immediately — saving the product is not required. Reload to see it under the variation Downloadable files list.', 'wc-github-publisher' ); ?>
				</p>
				<div id="wcgp-releases" style="padding:0 12px 12px;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Currently published" list from the parent publish index.
	 *
	 * @param int              $product_id Product id.
	 * @param \WC_Product|null $product    Product object.
	 */
	private function render_published_list( $product_id, $product ) {
		$index       = ( new Publisher() )->get_index( $product_id );
		$is_variable = $product && Targets::is_variable( $product );
		?>
		<div class="options_group" id="wcgp-published-group">
			<p class="form-field" style="margin-bottom:4px;">
				<strong><?php esc_html_e( 'Currently published', 'wc-github-publisher' ); ?></strong>
			</p>
			<ul id="wcgp-published" style="padding:0 12px 8px;margin:0;">
				<?php
				if ( $index ) {
					foreach ( $index as $entry ) {
						$this->render_published_row( $entry, $is_variable );
					}
				} else {
					echo '<li class="wcgp-empty">' . esc_html__( 'Nothing published yet.', 'wc-github-publisher' ) . '</li>';
				}
				?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render a single publish-index row.
	 *
	 * @param array $entry       Publish index entry.
	 * @param bool  $is_variable Whether the product is variable.
	 */
	private function render_published_row( $entry, $is_variable ) {
		$publisher = new Publisher();
		$label     = $publisher->build_label( $entry['asset_name'], isset( $entry['tag'] ) ? $entry['tag'] : '' );
		$date      = ! empty( $entry['published_at'] ) ? mysql2date( get_option( 'date_format' ), $entry['published_at'] ) : '';
		$target    = '';
		if ( $is_variable ) {
			$count  = isset( $entry['variation_ids'] ) ? count( (array) $entry['variation_ids'] ) : 0;
			$target = $publisher->target_label( isset( $entry['attribute'] ) ? $entry['attribute'] : '', isset( $entry['value'] ) ? $entry['value'] : Targets::ALL );
			/* translators: %d: number of variations. */
			$target = $target . ' · ' . sprintf( _n( '%d variation', '%d variations', $count, 'wc-github-publisher' ), $count );
		}
		?>
		<li class="wcgp-managed" data-publish="<?php echo esc_attr( $entry['publish_id'] ); ?>" data-key="<?php echo esc_attr( $this->entry_key( $entry ) ); ?>">
			<span class="wcgp-managed-label"><?php echo esc_html( $label ); ?></span>
			<?php if ( $target ) : ?>
				<span class="wcgp-managed-target">→ <?php echo esc_html( $target ); ?></span>
			<?php endif; ?>
			<?php if ( $date ) : ?>
				<span class="wcgp-managed-date">— <?php echo esc_html( $date ); ?></span>
			<?php endif; ?>
			<button type="button" class="button-link wcgp-remove" data-publish="<?php echo esc_attr( $entry['publish_id'] ); ?>">
				<?php esc_html_e( 'Remove', 'wc-github-publisher' ); ?>
			</button>
			<span class="wcgp-status"></span>
		</li>
		<?php
	}

	/**
	 * Stable asset key for a publish-index entry (with a fallback for entries
	 * created before asset keys were stored).
	 *
	 * @param array $entry Publish index entry.
	 * @return string
	 */
	private function entry_key( $entry ) {
		if ( ! empty( $entry['asset_key'] ) ) {
			return $entry['asset_key'];
		}
		return 'asset:' . ( isset( $entry['asset_id'] ) ? (int) $entry['asset_id'] : 0 );
	}

	/**
	 * Save the repository field on product save.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_repo( $post_id ) {
		// WooCommerce verifies its own product nonce before this fires.
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['_wcgp_repo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, Publisher::META_REPO, sanitize_text_field( wp_unslash( $_POST['_wcgp_repo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * Re-attach a simple product's managed files after a product save. (For
	 * variable products the files live on variations; see {@see save_variation()}.)
	 *
	 * @param int $post_id Product id.
	 */
	public function reconcile_product( $post_id ) {
		( new Publisher() )->reconcile_target( $post_id );
	}

	/**
	 * On variation save: re-attach the variation's managed files and apply any
	 * parent mappings that match it (auto-covering newly created variations).
	 *
	 * @param int $variation_id Variation id.
	 * @param int $loop         Loop index (unused).
	 */
	public function save_variation( $variation_id, $loop ) {
		$publisher = new Publisher();
		$publisher->reconcile_target( $variation_id );
		$publisher->apply_mappings_to_variation( $variation_id );
	}

	/**
	 * Enqueue admin assets on the product edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		global $post;
		$product         = $post ? wc_get_product( $post->ID ) : null;
		$is_variable     = $product && Targets::is_variable( $product );
		$index          = $post ? ( new Publisher() )->get_index( $post->ID ) : array();
		$published_keys = array();
		foreach ( $index as $entry ) {
			$published_keys[] = $this->entry_key( $entry );
		}

		wp_enqueue_style( 'wcgp-admin', WCGP_URL . 'assets/admin.css', array(), WCGP_VERSION );
		wp_enqueue_script( 'wcgp-admin', WCGP_URL . 'assets/admin.js', array( 'jquery' ), WCGP_VERSION, true );
		wp_localize_script(
			'wcgp-admin',
			'wcgpAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'fetchNonce'      => wp_create_nonce( 'wcgp_fetch' ),
				'publishNonce'    => wp_create_nonce( 'wcgp_publish' ),
				'unpublishNonce'  => wp_create_nonce( 'wcgp_unpublish' ),
				'isVariable'      => (bool) $is_variable,
				'publishedKeys'   => array_values( array_unique( $published_keys ) ),
				'i18n'            => array(
					'fetching'         => __( 'Fetching releases…', 'wc-github-publisher' ),
					'publishing'       => __( 'Publishing…', 'wc-github-publisher' ),
					'published'        => __( 'Published', 'wc-github-publisher' ),
					'publish'          => __( 'Publish', 'wc-github-publisher' ),
					'publishSel'       => __( 'Publish selected', 'wc-github-publisher' ),
					'removing'         => __( 'Removing…', 'wc-github-publisher' ),
					'remove'           => __( 'Remove', 'wc-github-publisher' ),
					'removed'          => __( 'Removed', 'wc-github-publisher' ),
					'noReleases'       => __( 'No releases found for this repository.', 'wc-github-publisher' ),
					'noAssets'         => __( 'No downloadable assets in this release.', 'wc-github-publisher' ),
					'draft'            => __( 'draft', 'wc-github-publisher' ),
					'prerelease'       => __( 'pre-release', 'wc-github-publisher' ),
					'latest'           => __( 'latest', 'wc-github-publisher' ),
					'error'            => __( 'Something went wrong.', 'wc-github-publisher' ),
					'confirm'          => __( 'Publish this asset as a downloadable file?', 'wc-github-publisher' ),
					'confirmRemove'    => __( 'Remove this published file?', 'wc-github-publisher' ),
					'nothingPublished' => __( 'Nothing published yet.', 'wc-github-publisher' ),
					/* translators: %s: relative time, e.g. "5m". */
					'cachedAgo'        => __( 'Cached %s ago', 'wc-github-publisher' ),
					/* translators: %d: number of remaining GitHub API requests. */
					'rateLeft'         => __( 'API: %d left', 'wc-github-publisher' ),
					/* translators: %d: number of variations. */
					'variations'       => __( '%d variations', 'wc-github-publisher' ),
				),
			)
		);
	}

	/**
	 * AJAX: list releases for the entered repository.
	 */
	public function ajax_fetch() {
		check_ajax_referer( 'wcgp_fetch', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-github-publisher' ) ) );
		}
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$force = ! empty( $_POST['force'] );

		$client = new Client();
		if ( ! $client->has_token() ) {
			wp_send_json_error( array( 'message' => __( 'No GitHub token configured.', 'wc-github-publisher' ) ) );
		}

		$releases = $client->list_releases( $repo, $force );
		if ( is_wp_error( $releases ) ) {
			wp_send_json_error( array( 'message' => $releases->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'releases' => $releases,
				'meta'     => $client->get_meta( $repo ),
			)
		);
	}

	/**
	 * AJAX: publish an asset to a product/variation target.
	 */
	public function ajax_publish() {
		check_ajax_referer( 'wcgp_publish', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-github-publisher' ) ) );
		}

		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$repo       = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$asset_id   = isset( $_POST['asset'] ) ? absint( $_POST['asset'] ) : 0;
		$kind       = isset( $_POST['kind'] ) ? sanitize_text_field( wp_unslash( $_POST['kind'] ) ) : 'asset';
		$tag        = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '';
		$attribute  = isset( $_POST['attribute'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute'] ) ) : '';
		$value      = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this product.', 'wc-github-publisher' ) ) );
		}

		$client = new Client();

		if ( 'zipball' === $kind ) {
			// Source archive: build a trusted filename server-side from repo + tag.
			$normalized = $client->normalize_repo( $repo );
			if ( is_wp_error( $normalized ) ) {
				wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
			}
			if ( '' === $tag ) {
				wp_send_json_error( array( 'message' => __( 'Missing release tag.', 'wc-github-publisher' ) ) );
			}
			$base  = strpos( $normalized, '/' ) !== false ? substr( strrchr( $normalized, '/' ), 1 ) : $normalized;
			$asset = array(
				'id'           => 0,
				'kind'         => 'zipball',
				'key'          => 'source:zip:' . $tag,
				'name'         => sanitize_file_name( $base . '-' . $tag . '.zip' ),
				'size'         => 0,
				'content_type' => 'application/zip',
			);
		} else {
			if ( ! $asset_id ) {
				wp_send_json_error( array( 'message' => __( 'Missing asset.', 'wc-github-publisher' ) ) );
			}
			// Re-fetch asset metadata from GitHub — never trust the client for name/size.
			$asset = $client->get_asset( $repo, $asset_id );
			if ( is_wp_error( $asset ) ) {
				wp_send_json_error( array( 'message' => $asset->get_error_message() ) );
			}
		}

		$result = ( new Publisher() )->publish(
			$product_id,
			$repo,
			array( 'tag' => $tag ),
			$asset,
			array(
				'attribute' => $attribute,
				'value'     => $value,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: remove a published entry from a product (and all its targets).
	 */
	public function ajax_unpublish() {
		check_ajax_referer( 'wcgp_unpublish', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-github-publisher' ) ) );
		}

		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$publish_id = isset( $_POST['publish'] ) ? sanitize_text_field( wp_unslash( $_POST['publish'] ) ) : '';

		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this product.', 'wc-github-publisher' ) ) );
		}
		if ( '' === $publish_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing file reference.', 'wc-github-publisher' ) ) );
		}

		$result = ( new Publisher() )->unpublish( $product_id, $publish_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}
}
