<?php
/**
 * Admin notices for GitHub API/auth errors and rate-limit exhaustion.
 *
 * @package WCGP
 */

namespace WCGP\Admin;

use WCGP\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces the last recorded GitHub error (e.g. an expired token) as a
 * dismissible admin notice with a quick link to the settings page.
 */
class Notices {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_wcgp_dismiss_error', array( $this, 'dismiss' ) );
	}

	/**
	 * Render the notice when an error is recorded.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$error = Status::get_error();
		if ( ! $error ) {
			return;
		}

		$is_rate  = isset( $error['code'] ) && 'rate' === $error['code'];
		$settings = admin_url( 'admin.php?page=' . SettingsPage::SLUG );
		$dismiss  = wp_nonce_url( admin_url( 'admin-post.php?action=wcgp_dismiss_error' ), 'wcgp_dismiss_error' );

		$intro = $is_rate
			? __( 'GitHub Publisher: the GitHub API rate limit has been reached. Try again later.', 'wc-github-publisher' )
			: __( 'GitHub Publisher: the last GitHub request failed. Your token may be invalid or expired.', 'wc-github-publisher' );
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html( $intro ); ?></strong>
				<?php if ( ! empty( $error['message'] ) ) : ?>
					<br /><span><?php echo esc_html( $error['message'] ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Update token', 'wc-github-publisher' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'wc-github-publisher' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Clear the recorded error and return to the referring page.
	 */
	public function dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wc-github-publisher' ) );
		}
		check_admin_referer( 'wcgp_dismiss_error' );
		Status::clear_error();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
