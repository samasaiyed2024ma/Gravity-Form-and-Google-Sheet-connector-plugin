<?php
/**
 * Template partial: Google Cloud setup guide strip.
 *
 * Included by settings/account-list.php.
 * Available variables inherited from parent template:
 *   @var string $redirect_uri  OAuth redirect URI (used for the copy box).
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gfgs-setup-guide">
	<div class="gfgs-guide-header">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
			<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
		</svg>
		<strong><?php esc_html_e( 'How to set up Google Sheets integration', GFGS ); ?></strong>
	</div>

	<ol class="gfgs-guide-steps">
		<li>
			<span class="gfgs-step-num">1</span>
			<div>
				<strong><?php esc_html_e( 'Create a Google Cloud Project', GFGS ); ?></strong>
				<p>
					<?php esc_html_e( 'Open', GFGS ); ?>
					<a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Google Cloud Console', GFGS ); ?>
					</a>
					<?php esc_html_e( ', create or select a project.', GFGS ); ?>
				</p>
			</div>
		</li>

		<li>
			<span class="gfgs-step-num">2</span>
			<div>
				<strong><?php esc_html_e( 'Enable APIs', GFGS ); ?></strong>
				<p><?php esc_html_e( 'Enable the Google Sheets API and Google Drive API from the API Library.', GFGS ); ?></p>
				<a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener noreferrer" class="gfgs-guide-link">
					<?php esc_html_e( 'Enable Sheets API →', GFGS ); ?>
				</a>
				<a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener noreferrer" class="gfgs-guide-link">
					<?php esc_html_e( 'Enable Drive API →', GFGS ); ?>
				</a>
			</div>
		</li>

		<li>
			<span class="gfgs-step-num">3</span>
			<div>
				<strong><?php esc_html_e( 'Configure OAuth Consent Screen', GFGS ); ?></strong>
				<p><?php esc_html_e( 'Go to APIs & Services → OAuth Consent Screen. Set type to External, fill in the app name, and add your email as a test user.', GFGS ); ?></p>
				<a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener noreferrer" class="gfgs-guide-link">
					<?php esc_html_e( 'Open Consent Screen →', GFGS ); ?>
				</a>
			</div>
		</li>

		<li>
			<span class="gfgs-step-num">4</span>
			<div>
				<strong><?php esc_html_e( 'Create OAuth 2.0 Credentials', GFGS ); ?></strong>
				<p><?php esc_html_e( 'Go to APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID. Choose Web Application.', GFGS ); ?></p>
				<p><?php esc_html_e( 'Add this as an Authorized Redirect URI:', GFGS ); ?></p>
				<div class="gfgs-copy-box">
					<code><?php echo esc_html( $redirect_uri ); ?></code>
					<button type="button" class="gfgs-copy-btn" data-copy="<?php echo esc_attr( $redirect_uri ); ?>">
						<?php esc_html_e( 'Copy', GFGS ); ?>
					</button>
				</div>
				<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" class="gfgs-guide-link">
					<?php esc_html_e( 'Open Credentials →', GFGS ); ?>
				</a>
			</div>
		</li>

		<li>
			<span class="gfgs-step-num">5</span>
			<div>
				<strong><?php esc_html_e( 'Click "Add New Account"', GFGS ); ?></strong>
				<p><?php esc_html_e( 'Paste your Client ID and Client Secret, then click Connect with Google.', GFGS ); ?></p>
			</div>
		</li>
	</ol>
</div>
