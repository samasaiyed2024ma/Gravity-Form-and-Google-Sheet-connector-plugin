<?php
/**
 * Template partial: Connected Accounts panel.
 *
 * Included by settings/account-list.php.
 * Available variables inherited from parent template:
 *   @var object[] $accounts        Connected Google accounts.
 *   @var string   $add_account_url URL to the Add Account view.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$account_count = count( $accounts );
?>
<div class="gfgs-accounts-panel">
	<div class="gfgs-panel-header">
		<h3><?php esc_html_e( 'Connected Accounts', GFGS ); ?></h3>
		<span class="gfgs-badge-count">
			<?php
			echo esc_html( $account_count );
			echo ' ';
			echo 1 === $account_count
				? esc_html__( 'account', GFGS )
				: esc_html__( 'accounts', GFGS );
			?>
		</span>
	</div>

	<?php if ( empty( $accounts ) ) : ?>
		<div class="gfgs-empty-accounts">
			<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
				<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/>
			</svg>
			<p><?php esc_html_e( 'No accounts connected yet.', GFGS ); ?></p>
			<a href="<?php echo esc_url( $add_account_url ); ?>" class="gfgs-btn gfgs-btn-primary">
				<?php esc_html_e( 'Connect Your First Account', GFGS ); ?>
			</a>
		</div>

	<?php else : ?>
		<div class="gfgs-account-list">
			<?php foreach ( $accounts as $account ) : ?>
				<div class="gfgs-account-card" data-id="<?php echo (int) $account->id; ?>">
					<div class="gfgs-google-account">
						<div class="gfgs-account-name">
							<div class="gfgs-account-avatar">
								<?php echo esc_html( strtoupper( substr( $account->email ?: $account->account_name, 0, 2 ) ) ); ?>
							</div>
							<div class="gfgs-account-info">
								<strong><?php echo esc_html( $account->account_name ); ?></strong>
								<span><?php echo esc_html( $account->email ?: __( 'Not yet authorized', GFGS ) ); ?></span>
							</div>
						</div>

						<div class="gfgs-account-status">
							<?php if ( $account->refresh_token ) : ?>
								<span class="gfgs-status-dot connected"></span>
								<span class="gfgs-status-label"><?php esc_html_e( 'Connected', GFGS ); ?></span>
							<?php else : ?>
								<span class="gfgs-status-dot pending"></span>
								<span class="gfgs-status-label"><?php esc_html_e( 'Pending Auth', GFGS ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<div class="gfgs-account-actions">
						<div class="gfgs-account-action-buttons">
							<a href="<?php echo esc_url( $add_account_url . '&gfgs_pending=' . (int) $account->id ); ?>"
							   class="gfgs-btn gfgs-btn-sm">
								<?php esc_html_e( 'Edit', GFGS ); ?>
							</a>
							<button type="button"
							        class="gfgs-btn gfgs-btn-sm gfgs-btn-danger gfgs-disconnect-account"
							        data-id="<?php echo (int) $account->id; ?>">
								<?php esc_html_e( 'Disconnect', GFGS ); ?>
							</button>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

</div>
