<?php
/**
 * Template: Settings → Account List view.
 *
 * Available variables (set by GFGS_Addon::plugin_settings_page()):
 *   @var object[] $accounts        Connected Google accounts.
 *   @var string   $redirect_uri    OAuth redirect URI.
 *   @var string   $add_account_url URL to the Add Account view.
 *   @var string   $connected_msg   Success message after OAuth (may be empty).
 *   @var string   $error_msg       Error message (may be empty).
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gfgs-settings-wrap">

	<?php if ( $connected_msg ) : ?>
		<div class="gfgs-alert gfgs-alert-success">
			<span aria-hidden="true">✓</span> <?php echo esc_html( $connected_msg ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $error_msg ) : ?>
		<div class="gfgs-alert gfgs-alert-error">
			<span aria-hidden="true">✕</span> <?php echo esc_html( $error_msg ); ?>
		</div>
	<?php endif; ?>

	<div class="gfgs-settings-header">
		<div class="gfgs-settings-header-inner">
			<span class="gfgs-logo">
				<svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true">
					<rect width="24" height="24" rx="6" fill="#0F9D58"/>
					<path d="M7 8h10M7 12h10M7 16h6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
				</svg>
			</span>
			<div>
				<h1><?php esc_html_e( 'Google Sheets Integration', GFGS ); ?></h1>
				<p><?php esc_html_e( 'Manage connected Google accounts for Gravity Forms', GFGS ); ?></p>
			</div>
		</div>
		<a href="<?php echo esc_url( $add_account_url ); ?>" class="gfgs-btn gfgs-btn-primary">
			+ <?php esc_html_e( 'Add New Account', GFGS ); ?>
		</a>
	</div>

	<div class="gfgs-settings-body">
		<?php include __DIR__ . '/partials/setup-guide.php'; ?>
		<?php include __DIR__ . '/partials/account-panel.php'; ?>
	</div>

</div>
