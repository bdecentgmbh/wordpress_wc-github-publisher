<?php
/**
 * Settings page: GitHub token and publish options.
 *
 * @package WCGP
 */

namespace WCGP\Admin;

use WCGP\Security\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce submenu page for the encrypted token and retention settings.
 */
class SettingsPage {

	const SLUG = 'wcgp-settings';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'GitHub Publisher', 'wc-github-publisher' ),
			__( 'GitHub Publisher', 'wc-github-publisher' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Persist submitted settings.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['wcgp_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcgp_settings_nonce'] ) ), 'wcgp_save_settings' ) ) {
			return;
		}

		$settings = TokenStore::get_settings();

		// Token is write-only: only change it when a new value is submitted.
		$new_token = isset( $_POST['wcgp_token'] ) ? trim( (string) wp_unslash( $_POST['wcgp_token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $new_token ) {
			$settings['token'] = TokenStore::encrypt( $new_token );
		}
		if ( isset( $_POST['wcgp_clear_token'] ) ) {
			$settings['token'] = '';
		}

		$settings['org']       = isset( $_POST['wcgp_org'] ) ? sanitize_text_field( wp_unslash( $_POST['wcgp_org'] ) ) : '';
		$settings['retention'] = isset( $_POST['wcgp_retention'] ) ? max( 1, (int) $_POST['wcgp_retention'] ) : 3;
		$settings['ttl']       = isset( $_POST['wcgp_ttl'] ) ? max( 60, (int) $_POST['wcgp_ttl'] ) : 600;
		$settings['allowlist'] = isset( $_POST['wcgp_allowlist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wcgp_allowlist'] ) ) : '';

		update_option( TokenStore::OPTION, $settings );

		add_settings_error( 'wcgp', 'saved', __( 'Settings saved.', 'wc-github-publisher' ), 'updated' );
	}

	/**
	 * Render the settings form.
	 */
	public function render() {
		$settings  = TokenStore::get_settings();
		$has_token = TokenStore::has_token();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GitHub Publisher', 'wc-github-publisher' ); ?></h1>
			<?php settings_errors( 'wcgp' ); ?>
			<p><?php esc_html_e( 'Configure the GitHub token used to read your release repositories. Files are published to products from the GitHub tab on each product.', 'wc-github-publisher' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'wcgp_save_settings', 'wcgp_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcgp_token"><?php esc_html_e( 'GitHub token', 'wc-github-publisher' ); ?></label></th>
						<td>
							<input type="password" name="wcgp_token" id="wcgp_token" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo $has_token ? esc_attr__( '•••••••• (saved)', 'wc-github-publisher' ) : 'github_pat_…'; ?>" />
							<p class="description">
								<?php esc_html_e( 'Fine-grained Personal Access Token with read-only "Contents" access to your release repositories. Stored encrypted; leave blank to keep the current token.', 'wc-github-publisher' ); ?>
							</p>
							<?php if ( $has_token ) : ?>
								<label><input type="checkbox" name="wcgp_clear_token" value="1" /> <?php esc_html_e( 'Remove the saved token', 'wc-github-publisher' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcgp_org"><?php esc_html_e( 'Default organization / owner', 'wc-github-publisher' ); ?></label></th>
						<td>
							<input type="text" name="wcgp_org" id="wcgp_org" class="regular-text" value="<?php echo esc_attr( $settings['org'] ); ?>" placeholder="bdecentgmbh" />
							<p class="description"><?php esc_html_e( 'Prepended when a product\'s repository is entered without an owner (e.g. "my-plugin" becomes "your-org/my-plugin"). Leave blank to always require owner/repo.', 'wc-github-publisher' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcgp_retention"><?php esc_html_e( 'Versions to keep per product', 'wc-github-publisher' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" name="wcgp_retention" id="wcgp_retention" value="<?php echo esc_attr( $settings['retention'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How many published versions stay attached to a product. Older managed files are detached and deleted automatically. Manually added files are never touched.', 'wc-github-publisher' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcgp_ttl"><?php esc_html_e( 'Release cache (seconds)', 'wc-github-publisher' ); ?></label></th>
						<td>
							<input type="number" min="60" step="1" name="wcgp_ttl" id="wcgp_ttl" value="<?php echo esc_attr( $settings['ttl'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How long the list of releases is cached before refetching from GitHub.', 'wc-github-publisher' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
