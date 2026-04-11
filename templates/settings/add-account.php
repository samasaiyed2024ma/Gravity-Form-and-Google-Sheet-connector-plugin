<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="gfgs-settings-wrap">

    <div class="gfgs-settings-header">
        <div class="gfgs-settings-header-inner">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets' ) ); ?>" class="gfgs-back-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h1><?php echo $pending_id ? esc_html__( 'Edit Google Account', 'GFGS' ) : esc_html__( 'Add New Google Account', 'GFGS' ); ?></h1>
                <p><?php esc_html_e( 'Connect a Google account to use with Gravity Forms feeds', 'GFGS' ); ?></p>
            </div>
        </div>
    </div>

    <?php if ( ! empty( $error_msg ) ) : ?>
        <div class="gfgs-alert gfgs-alert-error">
            <span>✕</span> <?php echo esc_html( $error_msg ); ?>
        </div>
    <?php endif; ?>

    <div class="gfgs-add-account-layout">

        <!-- Left: Form -->
        <div class="gfgs-add-account-form">

            <!-- Step 1 -->
            <div class="gfgs-card">
                <div class="gfgs-card-header">
                    <span class="gfgs-card-step">Step 1</span>
                    <h3><?php esc_html_e( 'Account Details', 'GFGS' ); ?></h3>
                </div>
                <div class="gfgs-card-body">
                    <div class="gfgs-form-row">
                        <label class="gfgs-label">
                            <?php esc_html_e( 'Account Name', 'GFGS' ); ?>
                            <span class="gfgs-hint">(<?php esc_html_e( 'for your reference', 'GFGS' ); ?>)</span>
                        </label>
                        <input type="text"
                               id="gfgs-account-name"
                               class="gfgs-input"
                               value="<?php echo esc_attr( $pending_account->account_name ?? '' ); ?>"
                               placeholder="e.g. My Business Account">
                    </div>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="gfgs-card">
                <div class="gfgs-card-header">
                    <span class="gfgs-card-step">Step 2</span>
                    <h3><?php esc_html_e( 'Google Cloud Credentials', 'GFGS' ); ?></h3>
                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="gfgs-card-header-link">
                        <?php esc_html_e( 'Open Google Cloud Console', 'GFGS' ); ?> ↗
                    </a>
                </div>
                <div class="gfgs-card-body">
                    <div class="gfgs-form-row">
                        <label class="gfgs-label">
                            <?php esc_html_e( 'Client ID', 'GFGS' ); ?>
                            <span class="gfgs-required">*</span>
                        </label>
                        <input type="text"
                               id="gfgs-client-id"
                               class="gfgs-input"
                               value="<?php echo esc_attr( $client_id ?? '' ); ?>"
                               placeholder="123456789-abc.apps.googleusercontent.com">
                        <p class="gfgs-field-hint">
                            <?php esc_html_e( 'Found in Google Cloud Console → APIs & Services → Credentials', 'GFGS' ); ?>
                        </p>
                    </div>
                    <div class="gfgs-form-row">
                        <label class="gfgs-label">
                            <?php esc_html_e( 'Client Secret', 'GFGS' ); ?>
                            <span class="gfgs-required">*</span>
                        </label>
                        <div class="gfgs-input-with-toggle">
                            <input type="password"
                                   id="gfgs-client-secret"
                                   class="gfgs-input"
                                   value="<?php echo esc_attr( $client_secret ?? '' ); ?>"
                                   placeholder="GOCSPX-…">
                            <button type="button" class="gfgs-toggle-secret" title="Show/hide">
                                <svg class="eye-show" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="eye-hide" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                                    <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="gfgs-form-row">
                        <label class="gfgs-label">
                            <?php esc_html_e( 'Authorized Redirect URI', 'GFGS' ); ?>
                        </label>
                        <div class="gfgs-copy-box">
                            <code><?php echo esc_html( $redirect_uri ); ?></code>
                            <button type="button" class="gfgs-copy-btn" data-copy="<?php echo esc_attr( $redirect_uri ); ?>">
                                <?php esc_html_e( 'Copy', 'GFGS' ); ?>
                            </button>
                        </div>
                        <p class="gfgs-field-hint">
                            <?php esc_html_e( 'Add this URL to your OAuth 2.0 client Authorized Redirect URIs.', 'GFGS' ); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Step 3 -->
            <div class="gfgs-card">
                <div class="gfgs-card-header">
                    <span class="gfgs-card-step">Step 3</span>
                    <h3><?php esc_html_e( 'Connect & Authorize', 'GFGS' ); ?></h3>
                </div>
                <div class="gfgs-card-body">

                    <?php if ( ! empty( $pending_account->refresh_token ) ) : ?>
                        <div class="gfgs-auth-status gfgs-auth-status--success">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                                <path d="M22 4L12 14.01l-3-3"/>
                            </svg>
                            <div>
                                <strong><?php esc_html_e( 'Account Authorized', 'GFGS' ); ?></strong>
                                <span><?php esc_html_e( 'This account is connected to Google.', 'GFGS' ); ?></span>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="gfgs-auth-status gfgs-auth-status--pending">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8v4M12 16h.01"/>
                            </svg>
                            <div>
                                <strong><?php esc_html_e( 'Authorization Required', 'GFGS' ); ?></strong>
                                <span><?php esc_html_e( 'Save your credentials, then click Connect with Google to authorize.', 'GFGS' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="gfgs-action-bar">
                        <button type="button"
                                id="gfgs-test-btn"
                                class="gfgs-btn gfgs-btn-outline"
                                <?php echo empty( $pending_account->refresh_token ) ? 'disabled' : ''; ?>>
                            <?php esc_html_e( 'Test Connection', 'GFGS' ); ?>
                        </button>
                        <button type="button" id="gfgs-save-connect-btn" class="gfgs-btn gfgs-btn-primary">
                            <?php echo ! empty( $pending_account->refresh_token )
                                ? esc_html__( 'Save Changes', 'GFGS' )
                                : esc_html__( 'Save & Connect with Google', 'GFGS' ); ?>
                        </button>
                    </div>
                    <div id="gfgs-connection-result" style="display:none; margin-top:12px;"></div>

                </div>
            </div>

        </div>

        <!-- Right: Mini Guide -->
        <div class="gfgs-add-account-guide">
            <div class="gfgs-mini-guide">
                <h4><?php esc_html_e( 'Quick Setup Guide', 'GFGS' ); ?></h4>
                <div class="gfgs-mini-step">
                    <a href="https://console.cloud.google.com/projectcreate" target="_blank" class="gfgs-mini-step-link">
                        <span class="gfgs-mini-num">1</span>
                        <span><?php esc_html_e( 'Create a Google Cloud project', 'GFGS' ); ?></span>
                    </a>
                </div>
                <div class="gfgs-mini-step">
                    <a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" class="gfgs-mini-step-link">
                        <span class="gfgs-mini-num">2</span>
                        <span><?php esc_html_e( 'Enable Google Sheets API', 'GFGS' ); ?></span>
                    </a>
                </div>
                <div class="gfgs-mini-step">
                    <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" class="gfgs-mini-step-link">
                        <span class="gfgs-mini-num">3</span>
                        <span><?php esc_html_e( 'Enable Google Drive API', 'GFGS' ); ?></span>
                    </a>
                </div>
                <div class="gfgs-mini-step">
                    <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" class="gfgs-mini-step-link">
                        <span class="gfgs-mini-num">4</span>
                        <span><?php esc_html_e( 'Configure OAuth Consent Screen', 'GFGS' ); ?></span>
                    </a>
                </div>
                <div class="gfgs-mini-step">
                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="gfgs-mini-step-link">
                        <span class="gfgs-mini-num">5</span>
                        <span><?php esc_html_e( 'Create OAuth 2.0 Client ID', 'GFGS' ); ?></span>
                    </a>
                </div>
                <div class="gfgs-mini-step active">
                    <span class="gfgs-mini-num">6</span>
                    <span><?php esc_html_e( 'Enter credentials here & connect', 'GFGS' ); ?></span>
                </div>
            </div>

            <div class="gfgs-mini-guide gfgs-mini-guide-tip" style="margin-top:16px;">
                <h4>💡 <?php esc_html_e( 'Tips', 'GFGS' ); ?></h4>
                <ul>
                    <li><?php esc_html_e( 'Set OAuth consent screen to External for personal accounts', 'GFGS' ); ?></li>
                    <li><?php esc_html_e( 'Add yourself as a Test User while the app is unverified', 'GFGS' ); ?></li>
                    <li><?php esc_html_e( 'The Authorized Redirect URI must match exactly — copy it using the button', 'GFGS' ); ?></li>
                </ul>
            </div>
        </div>

    </div>
</div>