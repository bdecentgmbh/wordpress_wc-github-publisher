<?php
/**
 * Product "GitHub" tab: configure one or more repositories, load their releases,
 * and publish a bundle (a single download combining one release asset per repo;
 * a wrapped zip with INSTALL.md when there is more than one). Supports simple
 * products and variable / variable-subscription products (publishing to
 * variations by attribute value).
 *
 * @package WCGP
 */

namespace WCGP\Admin;

use WCGP\GitHub\Client;
use WCGP\Publisher;
use WCGP\Repos;
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
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_repos' ) );
		// Re-attach managed files after WooCommerce processes the save, so a manual
		// "Update" never drops a published file.
		add_action( 'woocommerce_process_product_meta', array( $this, 'reconcile_product' ), 30 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 30, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_wcgp_fetch_bundle', array( $this, 'ajax_fetch_bundle' ) );
		add_action( 'wp_ajax_wcgp_publish_bundle', array( $this, 'ajax_publish_bundle' ) );
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
		$product     = wc_get_product( $post->ID );
		$entries     = Repos::get( $post->ID );
		$is_variable = $product && Targets::is_variable( $product );

		// Always render at least one (blank) repo row for a fresh product.
		if ( empty( $entries ) ) {
			$entries = array( array( 'repo' => '', 'primary' => true, 'path' => '' ) );
		}
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
				<p class="form-field" style="margin-bottom:4px;">
					<strong><?php esc_html_e( 'Repositories', 'wc-github-publisher' ); ?></strong>
				</p>
				<p class="description" style="padding:0 12px 6px;">
					<?php esc_html_e( 'Each repository contributes one release asset. With more than one repository, the download is a single zip bundling every component plus an INSTALL.md, and its name ends in "— UNZIP ME". Mark the repository whose version names the bundle as primary.', 'wc-github-publisher' ); ?>
				</p>
				<div id="wcgp-repos" data-primary-field="wcgp_repos_primary">
					<?php
					$i = 0;
					foreach ( $entries as $entry ) {
						$this->render_repo_row( $i, $entry );
						++$i;
					}
					?>
				</div>
				<p class="form-field" style="padding:0 12px;">
					<button type="button" class="button" id="wcgp-add-repo"><?php esc_html_e( 'Add repository', 'wc-github-publisher' ); ?></button>
				</p>
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
						<?php esc_html_e( 'Choose which variations receive the next published bundle — e.g. Platform: Moodle covers all its subscription periods.', 'wc-github-publisher' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="options_group">
				<p class="form-field">
					<button type="button" class="button" id="wcgp-load" data-product="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Load releases', 'wc-github-publisher' ); ?>
					</button>
					<button type="button" class="button-link" id="wcgp-refresh" title="<?php esc_attr_e( 'Force a fresh pull from GitHub', 'wc-github-publisher' ); ?>">
						<?php esc_html_e( 'Refresh', 'wc-github-publisher' ); ?>
					</button>
					<span class="spinner" style="float:none;margin-top:0;"></span>
				</p>
				<p class="description" style="padding:0 12px;">
					<?php esc_html_e( 'Save the product after changing repositories, then load releases. Publishing attaches the bundle immediately — reload to see it under the Downloadable files list.', 'wc-github-publisher' ); ?>
				</p>
				<div id="wcgp-composer" class="wcgp-composer"></div>
				<div class="wcgp-actions" id="wcgp-publish-wrap" style="display:none;">
					<button type="button" class="button button-primary" id="wcgp-publish-bundle" data-product="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Publish bundle', 'wc-github-publisher' ); ?>
					</button>
					<span id="wcgp-publish-status" class="description"></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one repository row in the repeater.
	 *
	 * @param int   $index Row index.
	 * @param array $entry { repo, primary, path }.
	 */
	private function render_repo_row( $index, $entry ) {
		$repo    = isset( $entry['repo'] ) ? $entry['repo'] : '';
		$path    = isset( $entry['path'] ) ? $entry['path'] : '';
		$primary = ! empty( $entry['primary'] );
		?>
		<div class="wcgp-repo-row">
			<input type="text" class="wcgp-repo-input" name="wcgp_repos[<?php echo esc_attr( $index ); ?>][repo]"
				value="<?php echo esc_attr( $repo ); ?>" placeholder="owner/moodle-mod_example" />
			<input type="text" class="wcgp-repo-path" name="wcgp_repos[<?php echo esc_attr( $index ); ?>][path]"
				value="<?php echo esc_attr( $path ); ?>" placeholder="<?php esc_attr_e( 'install path (optional)', 'wc-github-publisher' ); ?>"
				title="<?php esc_attr_e( 'Override the auto-derived Moodle install path for INSTALL.md', 'wc-github-publisher' ); ?>" />
			<label class="wcgp-primary-label">
				<input type="radio" name="wcgp_repos_primary" value="<?php echo esc_attr( $index ); ?>" <?php checked( $primary ); ?> />
				<?php esc_html_e( 'primary', 'wc-github-publisher' ); ?>
			</label>
			<button type="button" class="button-link wcgp-remove-repo" title="<?php esc_attr_e( 'Remove repository', 'wc-github-publisher' ); ?>">&times;</button>
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
		$label     = $publisher->label_for( $entry );
		$date      = ! empty( $entry['published_at'] ) ? mysql2date( get_option( 'date_format' ), $entry['published_at'] ) : '';
		$count     = ! empty( $entry['components'] ) ? count( (array) $entry['components'] ) : 0;
		$target    = '';
		if ( $is_variable ) {
			$vcount = isset( $entry['variation_ids'] ) ? count( (array) $entry['variation_ids'] ) : 0;
			$target = $publisher->target_label( isset( $entry['attribute'] ) ? $entry['attribute'] : '', isset( $entry['value'] ) ? $entry['value'] : Targets::ALL );
			/* translators: %d: number of variations. */
			$target = $target . ' · ' . sprintf( _n( '%d variation', '%d variations', $vcount, 'wc-github-publisher' ), $vcount );
		}
		?>
		<li class="wcgp-managed" data-publish="<?php echo esc_attr( $entry['publish_id'] ); ?>">
			<span class="wcgp-managed-label"><?php echo esc_html( $label ); ?></span>
			<?php if ( $count > 1 ) : ?>
				<span class="wcgp-managed-components">
					<?php
					/* translators: %d: number of bundled components. */
					echo esc_html( sprintf( _n( '%d component', '%d components', $count, 'wc-github-publisher' ), $count ) );
					?>
				</span>
			<?php endif; ?>
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
	 * Save the repository list on product save.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_repos( $post_id ) {
		// WooCommerce verifies its own product nonce before this fires.
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['wcgp_repos'] ) || ! is_array( $_POST['wcgp_repos'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$primary = isset( $_POST['wcgp_repos_primary'] ) ? sanitize_text_field( wp_unslash( $_POST['wcgp_repos_primary'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw     = wp_unslash( $_POST['wcgp_repos'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$rows = array();
		foreach ( (array) $raw as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'repo'    => isset( $row['repo'] ) ? sanitize_text_field( $row['repo'] ) : '',
				'path'    => isset( $row['path'] ) ? sanitize_text_field( $row['path'] ) : '',
				'primary' => ( (string) $key === $primary ),
			);
		}

		Repos::save( $post_id, $rows );
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
		$product     = $post ? wc_get_product( $post->ID ) : null;
		$is_variable = $product && Targets::is_variable( $product );

		wp_enqueue_style( 'wcgp-admin', WCGP_URL . 'assets/admin.css', array(), WCGP_VERSION );
		wp_enqueue_script( 'wcgp-admin', WCGP_URL . 'assets/admin.js', array( 'jquery' ), WCGP_VERSION, true );
		wp_localize_script(
			'wcgp-admin',
			'wcgpAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'fetchNonce'     => wp_create_nonce( 'wcgp_fetch' ),
				'publishNonce'   => wp_create_nonce( 'wcgp_publish' ),
				'unpublishNonce' => wp_create_nonce( 'wcgp_unpublish' ),
				'isVariable'     => (bool) $is_variable,
				'i18n'           => array(
					'primary'           => __( 'primary', 'wc-github-publisher' ),
					'installPath'       => __( 'install path (optional)', 'wc-github-publisher' ),
					'removeRepo'        => __( 'Remove repository', 'wc-github-publisher' ),
					'loading'           => __( 'Loading releases…', 'wc-github-publisher' ),
					'publishing'        => __( 'Building and publishing bundle…', 'wc-github-publisher' ),
					'published'         => __( 'Published', 'wc-github-publisher' ),
					'removing'          => __( 'Removing…', 'wc-github-publisher' ),
					'remove'            => __( 'Remove', 'wc-github-publisher' ),
					'removed'           => __( 'Removed', 'wc-github-publisher' ),
					'noRepos'           => __( 'Add at least one repository, then save the product.', 'wc-github-publisher' ),
					'noReleases'        => __( 'No releases found.', 'wc-github-publisher' ),
					'sourceZip'         => __( 'Source code (zip)', 'wc-github-publisher' ),
					'latest'            => __( 'latest', 'wc-github-publisher' ),
					'draft'             => __( 'draft', 'wc-github-publisher' ),
					'prerelease'        => __( 'pre-release', 'wc-github-publisher' ),
					'error'             => __( 'Something went wrong.', 'wc-github-publisher' ),
					'confirmPublish'    => __( 'Build and publish this bundle as a downloadable file?', 'wc-github-publisher' ),
					'confirmRemove'     => __( 'Remove this published file?', 'wc-github-publisher' ),
					'nothingPublished'  => __( 'Nothing published yet.', 'wc-github-publisher' ),
					'release'           => __( 'Release', 'wc-github-publisher' ),
					'asset'             => __( 'Asset', 'wc-github-publisher' ),
					/* translators: %d: number of bundled components. */
					'components'        => __( '%d components', 'wc-github-publisher' ),
				),
			)
		);
	}

	/**
	 * AJAX: list releases for every configured repository.
	 */
	public function ajax_fetch_bundle() {
		check_ajax_referer( 'wcgp_fetch', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-github-publisher' ) ) );
		}
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$force      = ! empty( $_POST['force'] );
		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this product.', 'wc-github-publisher' ) ) );
		}

		$client = new Client();
		if ( ! $client->has_token() ) {
			wp_send_json_error( array( 'message' => __( 'No GitHub token configured.', 'wc-github-publisher' ) ) );
		}

		$entries = Repos::get( $product_id );
		if ( empty( $entries ) ) {
			wp_send_json_error( array( 'message' => __( 'Add at least one repository, then save the product.', 'wc-github-publisher' ) ) );
		}

		$repos = array();
		foreach ( $entries as $entry ) {
			$norm     = $client->normalize_repo( $entry['repo'] );
			$repo_out = array(
				'repo'     => is_wp_error( $norm ) ? $entry['repo'] : $norm,
				'primary'  => ! empty( $entry['primary'] ),
				'error'    => '',
				'releases' => array(),
			);
			if ( is_wp_error( $norm ) ) {
				$repo_out['error'] = $norm->get_error_message();
			} else {
				$releases = $client->list_releases( $norm, $force );
				if ( is_wp_error( $releases ) ) {
					$repo_out['error'] = $releases->get_error_message();
				} else {
					$repo_out['releases'] = $releases;
				}
			}
			$repos[] = $repo_out;
		}

		wp_send_json_success( array( 'repos' => $repos ) );
	}

	/**
	 * AJAX: build and publish a bundle from the per-repo selections.
	 */
	public function ajax_publish_bundle() {
		check_ajax_referer( 'wcgp_publish', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-github-publisher' ) ) );
		}

		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$attribute  = isset( $_POST['attribute'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute'] ) ) : '';
		$value      = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this product.', 'wc-github-publisher' ) ) );
		}

		$selections = array();
		$raw        = isset( $_POST['selections'] ) ? wp_unslash( $_POST['selections'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( (array) $raw as $sel ) {
			if ( ! is_array( $sel ) ) {
				continue;
			}
			$selections[] = array(
				'repo'     => isset( $sel['repo'] ) ? sanitize_text_field( $sel['repo'] ) : '',
				'tag'      => isset( $sel['tag'] ) ? sanitize_text_field( $sel['tag'] ) : '',
				'kind'     => isset( $sel['kind'] ) ? sanitize_text_field( $sel['kind'] ) : 'asset',
				'asset_id' => isset( $sel['asset_id'] ) ? absint( $sel['asset_id'] ) : 0,
			);
		}

		$result = ( new Publisher() )->publish_bundle(
			$product_id,
			$selections,
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
