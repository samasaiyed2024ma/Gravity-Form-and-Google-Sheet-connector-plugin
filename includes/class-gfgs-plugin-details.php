<?php

if(!defined('ABSPATH')) exit;

class GFGS_Plugin_Details{
    public function __construct()
    {
        add_filter('plugin_row_meta', [$this, 'add_view_details_link'], 10, 2);
        add_action('admin_footer', [$this, 'render_modal']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    // ── Add "View Details" link under plugin description ──────────────────────
    public function add_view_details_link($link, $file){
        if($file !== GFGS_PLUGIN_BASENAME) return $link;
        
        $links[] = '<a href="#TB_inline?width=880&height=600&inlineId=gfgs-plugin-details" class="thickbox" title="'. esc_attr__('Connect Gravity Forms with Google Sheets', 'GFGS') .'"> 
                '. esc_html__('View Details', 'GFGS') .'    
                </a>';
        $links[] = '<a href="https://mervanagency.io" target="_blank">' 
                . esc_html__('Visit Plugin Site', 'GFGS') . 
                '</a>';

        return $links;
    }   

    // ── Enqueue assets only on plugins page ───────────────────────────────────

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'plugins.php' ) return;

        add_thickbox();

        wp_enqueue_style(
            'gfgs-plugin-details',
            GFGS_PLUGIN_URL . 'assets/css/plugin-details.css',
            [],
            GFGS_VERSION
        );

        wp_enqueue_script(
            'gfgs-plugin-details',
            GFGS_PLUGIN_URL . 'assets/js/plugin-details.js',
            [ 'jquery' ],
            GFGS_VERSION,
            true
        );
    }

    // ── Render modal HTML ─────────────────────────────────────────────────────

    public function render_modal() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'plugins' ) return;
        ?>
        <div id="gfgs-plugin-details" style="display:none;">
            <div class="gfgs-modal-wrap">

                <?php $this->render_header(); ?>
                <?php $this->render_tabs(); ?>
                <div class="gfgs-modal-main-container">
                    <div class="gfgs-modal-content-left">
                        <div class="gfgs-modal-body">
                            <?php $this->render_tab_description(); ?>
                            <?php $this->render_tab_installation(); ?>
                            <?php $this->render_tab_faq(); ?>
                            <?php $this->render_tab_changelog(); ?>
                        </div>
                    </div>
                    <?php $this->render_sidebar(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_header(){
        ?>
            <div class="gfgs-modal-header">
                <div class="gfgs-modal-title">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none">
                        <rect width="24" height="24" rx="6" fill="#0F9D58"/>
                        <path d="M7 8h10M7 12h10M7 16h6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                    </svg>

                    <div>
                        <h2><?php esc_html__('Connect Gravity Form with Google Sheets', 'GFGS'); ?></h2>
                        <div class="gfgs-modal-meta">
                            <span class="gfgs-modal-version">
                                <?php echo esc_html('Version' . GFGS_VERSION); ?>
                            </span>
                            <span class="gfgs-modal-author">
                                <?php esc_html_e('By', 'GFGS'); ?>
                                <a href="https://mervanagency.io" target="_blank">Mervan Agency</a>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }

    private function render_sidebar(){
        ?>
            <div class="gfgs-modal-sidebar">
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Version:', 'GFGS' ); ?></span>
                    <span><?php echo esc_html( GFGS_VERSION ); ?></span>
                </div>
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Author:', 'GFGS' ); ?></span>
                    <a href="https://mervanagency.io" target="_blank">Mervan Agency</a>
                </div>
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Last Updated:', 'GFGS' ); ?></span>
                    <span><?php esc_html_e( 'April 2026', 'GFGS' ); ?></span>
                </div>
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Requires WordPress:', 'GFGS' ); ?></span>
                    <span>5.8 <?php esc_html_e( 'or higher', 'GFGS' ); ?></span>
                </div>
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Compatible up to:', 'GFGS' ); ?></span>
                    <span>6.7</span>
                </div>
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Requires PHP:', 'GFGS' ); ?></span>
                    <span>7.4 <?php esc_html_e( 'or higher', 'GFGS' ); ?></span>
                </div>
                <div class="gfgs-sidebar-row">
                    <span class="gfgs-sidebar-label"><?php esc_html_e( 'Requires:', 'GFGS' ); ?></span>
                    <span>Gravity Forms 2.6+</span>
                </div>

                <div class="gfgs-sidebar-block">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets' ) ); ?>"
                        class="button button-primary gfgs-sidebar-btn">
                        <?php esc_html_e( 'Go to Settings', 'GFGS' ); ?>
                    </a>
                    <a href="https://mervanagency.io" 
                        target="_blank"
                        class="button gfgs-sidebar-btn">
                        <?php esc_html_e( 'Plugin Homepage', 'GFGS' ); ?>
                    </a>
                </div>
            </div>
        <?php
    }

    private function render_tabs(){
        $tabs = [
            'description' => __('Description', 'GFGS'),
            'installation' => __('Installation', 'GFGS'),
            'faq' => __('FAQ', 'GFGS'),
            'changelog' => __('Changelog', 'GFGS'),
        ];
        ?>
            <div class="gfgs-modal-tabs">
                <?php foreach($tabs as $key => $label): ?>
                    <button class="gfgs-tab-btn <?php echo $key === 'description' ? 'active' : ''; ?>" data-tab="<?php echo esc_attr($key); ?>" >
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php
    }

    private function render_tab_description(){
        ?>
            <div class="gfgs-tab-content active" id="gfgs-tab-description">
                <p class="gfgs-tab-intro">
                    <?php esc_html_e('Automatically send Gravity Forms submissions to Google Sheets. Map form fields to sheet columns, set conditions, and manage multiple feeds.', 'GFGS'); ?>
                </p>

                <h4>
                    <?php esc_html__('Key Features', 'GFGS'); ?>                   
                </h4>
                <ul class="gfgs-feature-list">
                    <?php
                    $features = [
                        __( 'Connect multiple Google accounts', 'GFGS' ),
                        __( 'Map any form field to any sheet column', 'GFGS' ),
                        __( 'Multiple trigger events (submit, payment, entry update)', 'GFGS' ),
                        __( 'Conditional logic support per feed', 'GFGS' ),
                        __( 'Support for all Gravity Forms field types', 'GFGS' ),
                        __( 'Manual send from entry detail page', 'GFGS' ),
                        __( 'Multiple feeds per form', 'GFGS' ),
                        __( 'Secure OAuth 2.0 authentication', 'GFGS' ),
                        __( 'Entry notes for success and error logging', 'GFGS' ),
                    ];
                    foreach ( $features as $feature ) : ?>
                        <li>
                            <span class="gfgs-feature-icon">✅</span>
                            <?php echo esc_html( $feature ); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <h4><?php esc_html_e('Supported Field Types', 'GFGS'); ?></h4>
                <div class="gfgs-field-types-grid">
                    <?php
                    $field_types = [
                        __( 'Text / Textarea', 'GFGS' ),
                        __( 'Email / Phone / Number', 'GFGS' ),
                        __( 'Select / Radio', 'GFGS' ),
                        __( 'Checkbox / Multi-select', 'GFGS' ),
                        __( 'Name / Address', 'GFGS' ),
                        __( 'Date / Time', 'GFGS' ),
                        __( 'File Upload', 'GFGS' ),
                        __( 'List Fields', 'GFGS' ),
                        __( 'Product Fields', 'GFGS' ),
                        __( 'Entry Meta (ID, IP, URL)', 'GFGS' ),
                    ];
                    foreach ( $field_types as $type ) : ?>
                        <span class="gfgs-field-type-badge"><?php echo esc_html( $type ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php
    }

    private function render_tab_installation(){
        ?>
        <div class="gfgs-tab-content" id="gfgs-tab-installation">
            <ol class="gfgs-install-steps">
                <?php
                $steps = [
                    [
                        'title' => __('Upload & Activate', 'GFGS'),
                        'desc' => __('Go to plugins → Add New → Upload upload plugin, select the zip file, and click Install now. Once finished, click Activate.', 'GFGS'),
                    ],
                    [
                        'title' => __('Ensure Gravity Form is Active' ,'GFGS'),
                        'desc' => __('This plugin is an add-on. Make sure Gravity Forms (v2.6+) is installed and activated on your site.', 'GFGS'),
                    ],
                    [
                        'title' => __( 'Go to Forms → Settings → Google Sheets', 'GFGS' ),
                        'desc'  => __( 'Navigate to the plugin settings page to get started.', 'GFGS' ),
                    ],
                    [
                        'title' => __( 'Create a Google Cloud Project', 'GFGS' ),
                        'desc'  => __( 'Go to Google Cloud Console, create a project, and enable Google Sheets API and Google Drive API.', 'GFGS' ),
                    ],
                    [
                        'title' => __( 'Configure OAuth Consent Screen', 'GFGS' ),
                        'desc'  => __( 'Set user type to External and add your email as a test user.', 'GFGS' ),
                    ],
                    [
                        'title' => __( 'Create OAuth 2.0 Credentials', 'GFGS' ),
                        'desc'  => __( 'Create a Web Application OAuth client and add your redirect URI from the plugin settings page.', 'GFGS' ),
                    ],
                    [
                        'title' => __( 'Connect Your Google Account', 'GFGS' ),
                        'desc'  => __( 'Click Add New Account, enter your Client ID and Secret, then authorize with Google.', 'GFGS' ),
                    ],
                    [
                        'title' => __( 'Create a Feed', 'GFGS' ),
                        'desc'  => __( 'Open any Gravity Form, go to Settings → Google Sheets, and click Add New Feed to start mapping fields.', 'GFGS' ),
                    ],
                ];
                foreach ( $steps as $step ) : ?>
                    <li>
                        <div class="gfgs-step-content">
                            <strong><?php echo esc_html( $step['title'] ); ?></strong>
                            <p><?php echo esc_html( $step['desc'] ); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
    }

    private function render_tab_faq(){
        ?>
        <div class="gfgs-tab-content" id="gfgs-tab-faq">
            <?php
            $faqs = [
                [
                    'q' => __( 'Does this plugin require Gravity Forms?', 'GFGS' ),
                    'a' => __( 'Yes, Gravity Forms must be installed and activated. Version 2.6 or higher is required.', 'GFGS' ),
                ],
                [
                    'q' => __( 'Can I connect multiple Google accounts?', 'GFGS' ),
                    'a' => __( 'Yes, you can connect as many Google accounts as needed. Each feed can use a different account.', 'GFGS' ),
                ],
                [
                    'q' => __( 'Can I send one form to multiple spreadsheets?', 'GFGS' ),
                    'a' => __( 'Yes, you can create multiple feeds per form, each sending to a different spreadsheet or sheet tab.', 'GFGS' ),
                ],
                [
                    'q' => __( 'What happens if the Google API call fails?', 'GFGS' ),
                    'a' => __( 'The error is logged in the entry notes. You can manually resend from the entry detail page using the Send to Google Sheets button.', 'GFGS' ),
                ],
                [
                    'q' => __( 'Does it support conditional logic?', 'GFGS' ),
                    'a' => __( 'Yes, each feed supports conditional logic so you can control exactly when entries are sent to Google Sheets.', 'GFGS' ),
                ],
                [
                    'q' => __( 'Is my data secure?', 'GFGS' ),
                    'a' => __( 'Yes. OAuth tokens are stored securely in your WordPress database. Data is sent directly from your site to Google — nothing passes through our servers.', 'GFGS' ),
                ],
                [
                    'q' => __( 'Will it work on localhost?', 'GFGS' ),
                    'a' => __( 'Google OAuth requires a publicly accessible URL. For local development, use a tunneling tool like ngrok or ddev share to get a public URL.', 'GFGS' ),
                ],
            ];
            foreach ( $faqs as $faq ) : ?>
                <div class="gfgs-faq-item">
                    <button class="gfgs-faq-question" type="button">
                        <?php echo esc_html( $faq['q'] ); ?>
                        <span class="gfgs-faq-icon">+</span>
                    </button>
                    <div class="gfgs-faq-answer">
                        <p><?php echo esc_html( $faq['a'] ); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_tab_changelog(){
        ?>
        <div class="gfgs-tab-content" id="gfgs-tab-changelog">
        <?php
            $changelog = [
                [
                    'version' => '1.0.0',
                    'date' => '',
                    'label' => __('Initial Release', 'GFGS'),
                    'changes' => [
                        __('Connect multiple Google accounts via OAuth 2.0', 'GFGS'),
                        __('Create feeds with field mapping', 'GFGS'),
                        __('Support for all Gravity Forms field types', 'GFGS'),
                        __('Conditional logic per feed', 'GFGS'),
                        __('Multiple trigger events', 'GFGS'),
                        __('Manual send from entry detail page', 'GFGS'),
                        __('Entry notes for success and error logging', 'GFGS'),
                    ],
                ],
            ];

            foreach($changelog as $release) : ?>
                <div class="gfgs-changelog-version">
                    <div class="gfgs-changelog-header">
                        <span class="gfgs-version-badge"><?php echo esc_html($release['version']); ?></span>
                        <strong><?php echo esc_html( $release['label'] ); ?></strong>
                        <span class="gfgs-changelog-date"><?php echo esc_html(  $release['date'] ) ?></span>
                    </div>
                    <ul>
                        <?php foreach($release['changes'] as $changes): ?>
                            <li><?php echo esc_html($changes); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_footer(){
        ?>
        <div class="gfgs-modal-footer">
            <a href="<?php echo esc_html( admin_url('admin.php?page=gf_settings&subview=gf-google-sheets') ); ?>" class="button button-primary">
                <?php esc_html_e('Go to Settings', 'GFGS'); ?>
            </a>
            <a href="https://mervanagency.io" target="_blank" class="button">
                <?php esc_html_e('Visit Plugin Site', 'GFGS'); ?>
            </a>
        </div>
        <?php
    }
}