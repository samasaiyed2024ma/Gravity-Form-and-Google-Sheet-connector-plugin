<?php

if(!defined('ABSPATH')) exit;
class GFGS_Addon extends GFAddOn{
    protected $_version = GFGS_VERSION;
    protected $_min_gravityforms_version = '2.6';
    protected $_slug = 'gf-google-sheets';
    protected $_full_path = GFGS_PLUGIN_FILE;
    protected $_path = GFGS_PLUGIN_BASENAME;
    protected $_title = 'Google Sheets';
    protected $_short_title = 'Google Sheets';  
    protected $_supports_feed_ordering = false;
    protected $_has_feed_list_page = true; // not a real GF property, but make sure:

    private static $_instance = null;
    private $api;

    public static function get_instance(){
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    // This is the actual GF hook for custom feed list:
    public function init()
    {
        $this->_path = GFGS_PLUGIN_BASENAME;
        parent::init();
        $this->api = new GFGS_Google_API();
        new GFGS_Feed_Processor();

        add_action('wp_ajax_gfgs_get_sheets', [$this, 'ajax_get_sheets']);
        add_action('wp_ajax_gfgs_get_spreadsheets', [$this, 'ajax_get_spreadsheets']);
        add_action('wp_ajax_gfgs_get_sheet_headers', [$this, 'ajax_get_sheet_headers']);
        add_action('wp_ajax_gfgs_manual_send', [$this, 'ajax_manual_send']);
        add_action('wp_ajax_gfgs_delete_account', [$this, 'ajax_delete_account']);
        add_action('wp_ajax_gfgs_save_feed', [$this, 'ajax_save_feed']);
        add_action('wp_ajax_gfgs_delete_feed', [$this, 'ajax_delete_feed']);
        add_action('wp_ajax_gfgs_toggle_feed', [$this, 'ajax_toggle_feed']);
        add_action('wp_ajax_gfgs_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_gfgs_save_account_creds', [$this, 'ajax_save_account_creds']);
        add_action('wp_ajax_gfgs_connect_account', [$this, 'ajax_connect_account']);

        // Oauth callback
        if(isset($_GET['gfgs_oauth']) && $_GET['gfgs_oauth'] === 'callback'){
            add_action('admin_init', [$this, 'handle_oauth_callback']);
        }

        add_filter('gform_entry_detail_meta_boxes', [$this, 'add_entry_meta_box'], 10, 3);
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }


    // ── Plugin Settings Page (fully custom) ───────────────────────────────────
    public static function register_addon() {
        if (!class_exists('GFAddOn')) {
            return;
        }

        GFAddOn::register('GFGS_Addon');
    }

    /**
     * Load a template file and pass data to it.
     */
    private function render_template( $template, $data = [] ) {
        $file = GFGS_PLUGIN_DIR . 'templates/' . $template . '.php';
        if ( ! file_exists( $file ) ) {
            error_log( 'GFGS Template not found: ' . $file );
            return;
        }
        // Extract data array as variables available inside template
        extract( $data );
        include $file;
    }  

    /**
     * Override the GF settings page with our custom UI.
     */
    public function plugin_settings_page(){
        wp_enqueue_style('gfgs-variable');
        wp_enqueue_style('gfgs-components');
        wp_enqueue_style('gfgs-feed-list');
        wp_enqueue_style('gfgs-feed-editor');
        wp_enqueue_style('gfgs-admin');
        wp_enqueue_script('gfgs-admin');
        wp_enqueue_script('gfgs-common');
        wp_enqueue_script('gfgs-feed');


        $redirect_uri = admin_url('admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_oauth=callback');
        $accounts = GFGS_Database::get_accounts();
        $view = isset($_GET['gfgs_view']) ? sanitize_text_field($_GET['gfgs_view']) : 'list';
        $pending_id = isset($_GET['gfgs_pending']) ? (int) $_GET['gfgs_pending'] : 0;
        $add_account_url = admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' );
        $connected_msg   = isset( $_GET['connected'] ) ? __( 'Google account connected successfully!', 'gf-google-sheets' ) : '';
        $error_msg       = isset( $_GET['gfgs_error'] ) ? urldecode( sanitize_text_field( $_GET['gfgs_error'] ) ) : '';

        wp_localize_script( 'gfgs-admin', 'gfgsSettings', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'gfgs_nonce' ),
            'redirectUri'    => $redirect_uri,
            'accounts'       => array_map( fn( $a ) => (array) $a, $accounts ),
            'addAccountUrl'  => $add_account_url,
            'connectedMsg'   => $connected_msg,
            'errorMsg'       => $error_msg,
        ] );

        if( $view === 'add_account'){
            // Hydrate pending account creds for the add-account form
            $pending_account = $pending_id ? GFGS_Database::get_account($pending_id) : null ; 
            
            $client_id       = $pending_account ? ( json_decode( $pending_account->access_token, true )['client_id'] ?? '' ) : '';
            $client_secret   = $pending_account ? ( json_decode( $pending_account->access_token, true )['client_secret'] ?? '' ) : '';

            $this->render_template('settings/add-account', [
                'pending_id' => $pending_id,
                'pending_account' => $pending_account,
                'client_id'       => $client_id,
                'client_secret'   => $client_secret,
                'redirect_uri' => $redirect_uri,
                'add_account_url' => $add_account_url,
                'error_msg'       => $error_msg,
            ]);
        }
        else{
            $this->render_template('settings/account-list', [
                'accounts' => $accounts,
                'redirect_uri' => $redirect_uri,
                'add_account_url' => $add_account_url,
                'connected_msg' => $connected_msg,
                'error_msg' => $error_msg,
            ]);
        }
    }

    /**
     * Return empty — fully override plugin_settings_page() above.
     */
    public function plugin_settings_fields() {
        return [];
    }
    
    /**
     * Optional: Helper to show a checkmark if the setting is filled.
     */
    public function is_valid_setting( $value ) {
        return ! empty( $value );
    }

    // ── OAuth callback ─────────────────────────────────────────────────────────

    public function handle_oauth_callback() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $pending_id = isset( $_GET['state'] ) ? (int) sanitize_text_field( $_GET['state'] ) : 0;

        if ( empty( $_GET['code'] ) ) {
            $error = urlencode( __( 'OAuth failed: no code returned.', 'GFGS' ) );
            wp_redirect( admin_url( "admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account&gfgs_pending={$pending_id}&gfgs_error={$error}" ) );
            exit;
        }

        // Get stored credentials for this pending account
        $pending = $pending_id ? GFGS_Database::get_account( $pending_id ) : null;
        if ( ! $pending ) {
            $error = urlencode( __( 'OAuth failed: pending account not found.', 'GFGS' ) );
            wp_redirect( admin_url( "admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_error={$error}" ) );
            exit;
        }

        // Temporarily set the API credentials for this account
        $creds = json_decode( $pending->access_token, true );
        add_filter( 'gfgs_override_credentials', fn() => [
            'client_id'     => $creds['client_id']     ?? '',
            'client_secret' => $creds['client_secret'] ?? '',
        ] );

        $tokens = $this->api->exchage_code_with_creds(
            sanitize_text_field( $_GET['code'] ),
            $creds['client_id']     ?? '',
            $creds['client_secret'] ?? '',
            admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_oauth=callback' )
        );

        if ( is_wp_error( $tokens ) ) {
            $error = urlencode( $tokens->get_error_message() );
            wp_redirect( admin_url( "admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account&gfgs_pending={$pending_id}&gfgs_error={$error}" ) );
            exit;
        }

        $user_info = $this->api->get_userinfo_with_token( $tokens['access_token'] );
        $email     = is_wp_error( $user_info ) ? ( $pending->email ?: 'unknown@example.com' ) : ( $user_info['email'] ?? 'unknown' );

          // Update the pending account record with real tokens
        GFGS_Database::save_account( [
            'id'            => $pending_id,
            'account_name'  => $pending->account_name ?: $email,
            'email'         => $email,
            'access_token'  => wp_json_encode( [
                'access_token'  => $tokens['access_token'],
                'client_id'     => $creds['client_id']     ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
            ] ),
            'refresh_token' => $tokens['refresh_token'] ?? '',
            'token_expires' => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
        ] );

        wp_redirect( admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&connected=1' ) );
        exit;
    }

    // ── GF Feed: Feed settings page ───────────────────────────────────────────

    public function can_create_feed() {
        return true;
    }

    public function feed_list_columns() {
        return [
            'feed_name'  => esc_html__( 'Feed Name', 'GFGS' ),
            'sheet_info' => esc_html__( 'Spreadsheet / Sheet', 'GFGS' ),
            'send_event' => esc_html__( 'Send On', 'GFGS' ),
        ];
    }

    public function feed_list_page( $form_id ) {
        wp_enqueue_style('gfgs-variable');
        wp_enqueue_style('gfgs-components');
        wp_enqueue_style('gfgs-feed-list');
        wp_enqueue_style('gfgs-feed-editor');
        wp_enqueue_style('gfgs-admin');
        // wp_enqueue_script('gfgs-admin');
        // wp_enqueue_script('gfgs-common');
        wp_enqueue_script('gfgs-feed');

        $form     = GFAPI::get_form( $form_id );
        $feeds    = GFGS_Database::get_feeds_by_form( $form_id );
        $accounts = GFGS_Database::get_accounts();

        wp_localize_script( 'gfgs-feed', 'gfgsData', [
            'formId' => $form_id,
            'feeds' => array_map( fn($f) => (array)$f, $feeds ),
            'accounts' => array_map( fn($a) => (array)$a, $accounts ),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gfgs_nonce'),
        ]);

        $this->render_template('feeds/feed-list', [
            'form_id' => $form_id,
            'feeds' => $feeds,
            'accounts' => $accounts,
        ]);
    }

    public function form_settings_page( $form_id = 0 ) {
        $this->feed_list_page( $form_id );
    }

    // ── Entry meta box ─────────────────────────────────────────────────────────
    public function add_entry_meta_box( $meta_boxes, $entry, $form ) {
        $feeds = GFGS_Database::get_feeds_by_form( $form['id'] );
        if ( empty( $feeds ) ) return $meta_boxes;

        $meta_boxes['gfgs_manual_send'] = [
            'title'    => esc_html__( 'Google Sheets', 'GFGS' ),
            'callback' => [ $this, 'render_entry_meta_box' ],
            'context'  => 'side',
        ];
        return $meta_boxes;
    }

    public function render_entry_meta_box( $args ) {
        $entry = $args['entry'];
        ?>
        <div class="gfgs-entry-box">
            <p><?php esc_html_e( 'Manually send this entry to all active Google Sheets feeds.', 'gf-google-sheets' ); ?></p>
            <button type="button"
                    class="button button-primary gfgs-manual-send-btn"
                    data-entry-id="<?php echo (int) $entry['id']; ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'gfgs_manual_send_' . $entry['id'] ) ); ?>">
                <?php esc_html_e( 'Send to Google Sheets', 'gf-google-sheets' ); ?>
            </button>
            <span class="gfgs-send-status" style="display:none;margin-left:8px;"></span>
        </div>
        <?php
    }

    // ── Admin assets ──────────────────────────────────────────────────────────

    public function enqueue_admin_scripts( $hook ) {
        wp_register_style(
            'gfgs-variable',
            GFGS_PLUGIN_URL . 'assets/css/variable.css',
            [],
            GFGS_VERSION
        );

        wp_register_style(
            'gfgs-admin',
            GFGS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            GFGS_VERSION
        );
    
        wp_register_style(
            'gfgs-components',
            GFGS_PLUGIN_URL . 'assets/css/components.css',
            [],
            GFGS_VERSION
        );

        wp_register_style(
            'gfgs-feed-list',
            GFGS_PLUGIN_URL . 'assets/css/feed-list.css',
            [],
            GFGS_VERSION
        );

        wp_register_style(
            'gfgs-feed-editor',
            GFGS_PLUGIN_URL . 'assets/css/feed-editor.css',
            [],
            GFGS_VERSION
        );

        // Shared helpers
        wp_register_script(
            'gfgs-common',
            GFGS_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            GFGS_VERSION,
            true
        );

        // Settings page
        wp_register_script(
            'gfgs-admin',
            GFGS_PLUGIN_URL . 'assets/js/settings.js',
            [ 'jquery', 'gfgs-common' ],
            GFGS_VERSION,
            true
        );

        // Feed list page
        wp_register_script(
            'gfgs-feed',
            GFGS_PLUGIN_URL . 'assets/js/feed-list.js',
            [ 'jquery', 'gfgs-common' ],
            GFGS_VERSION,
            true
        );
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    private function verify_ajax() {
        if ( ! check_ajax_referer( 'gfgs_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'gf-google-sheets' ) ] );
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gravityforms_edit_forms' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'gf-google-sheets' ) ] );
        }
    }

    /**
     * Save account credentials (client_id + secret + name) and return the pending account ID.
     * The actual OAuth flow is initiated from the front-end by redirecting to Google.
     */
    public function ajax_save_account_creds() {
        $this->verify_ajax();

        $account_name  = sanitize_text_field( $_POST['account_name']  ?? '' );
        $client_id     = sanitize_text_field( $_POST['client_id']     ?? '' );
        $client_secret = sanitize_text_field( $_POST['client_secret'] ?? '' );

        if ( ! $client_id || ! $client_secret ) {
            wp_send_json_error( [ 'message' => __( 'Client ID and Client Secret are required.', 'gf-google-sheets' ) ] );
        }

        // Check if updating existing pending account
        $existing_id = (int) ( $_POST['account_id'] ?? 0 );

        $pending_id = GFGS_Database::save_account( [
            'id'            => $existing_id ?: null,
            'account_name'  => $account_name ?: __( 'My Google Account', 'gf-google-sheets' ),
            'email'         => '',
            'access_token'  => wp_json_encode( [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ] ),
            'refresh_token' => '',
            'token_expires' => 0,
        ] );

        // Build OAuth URL using per-account credentials
        $redirect_uri = admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_oauth=callback' );
        $auth_url     = add_query_arg( [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $pending_id,
        ], GFGS_Google_API::OAUTH_AUTH_URL );

        wp_send_json_success( [
            'pending_id' => $pending_id,
            'auth_url'   => $auth_url,
        ] );
    }

    /**
     * Test connection: verify credentials can get a valid token and reach the API.
     */
    public function ajax_test_connection() {
        $this->verify_ajax();

        $client_id     = sanitize_text_field( $_POST['client_id']     ?? '' );
        $client_secret = sanitize_text_field( $_POST['client_secret'] ?? '' );
        $account_id    = (int) ( $_POST['account_id'] ?? 0 );

        if ( ! $client_id || ! $client_secret ) {
            wp_send_json_error( [ 'message' => __( 'Client ID and Client Secret are required.', 'gf-google-sheets' ) ] );
        }

        // If account has tokens already, try to use them
        if ( $account_id ) {
            $account = GFGS_Database::get_account( $account_id );
            if ( $account && $account->refresh_token ) {
                $result = $this->api->refresh_token_with_creds(
                    $account->refresh_token,
                    $client_id,
                    $client_secret
                );
                if ( ! is_wp_error( $result ) && ! empty( $result['access_token'] ) ) {
                    wp_send_json_success( [ 'message' => __( 'Connection successful! Your credentials are valid.', 'gf-google-sheets' ) ] );
                }
            }
        }

        wp_send_json_error( [ 'message' => __( 'Could not verify connection. Please complete the Google authorization first.', 'gf-google-sheets' ) ] );
    }

      public function ajax_get_spreadsheets() {
        $this->verify_ajax();
        $account_id = (int) ( $_POST['account_id'] ?? 0 );
        $result     = $this->api->list_spreadsheets( $account_id );
        is_wp_error( $result )
            ? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
            : wp_send_json_success( $result );
    }

    public function ajax_get_sheets() {
        $this->verify_ajax();
        $account_id     = (int)    ( $_POST['account_id']     ?? 0 );
        $spreadsheet_id = sanitize_text_field( $_POST['spreadsheet_id'] ?? '' );
        $result         = $this->api->get_spreadsheet_sheets( $account_id, $spreadsheet_id );
        is_wp_error( $result )
            ? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
            : wp_send_json_success( $result );
    }

    public function ajax_get_sheet_headers() {
        $this->verify_ajax();
        $account_id     = (int)    ( $_POST['account_id']     ?? 0 );
        $spreadsheet_id = sanitize_text_field( $_POST['spreadsheet_id'] ?? '' );
        $sheet_name     = sanitize_text_field( $_POST['sheet_name']     ?? '' );
        $result         = $this->api->get_sheet_headers( $account_id, $spreadsheet_id, $sheet_name );
        is_wp_error( $result )
            ? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
            : wp_send_json_success( $result );
    }

    public function ajax_save_feed() {
        $this->verify_ajax();
        $data = [
            'id'             => (int)   ( $_POST['id']             ?? 0 ),
            'form_id'        => (int)   ( $_POST['form_id']        ?? 0 ),
            'feed_name'      => sanitize_text_field( $_POST['feed_name']      ?? '' ),
            'account_id'     => (int)   ( $_POST['account_id']     ?? 0 ),
            'spreadsheet_id' => sanitize_text_field( $_POST['spreadsheet_id'] ?? '' ),
            'sheet_id'       => sanitize_text_field( $_POST['sheet_id']       ?? '' ),
            'sheet_name'     => sanitize_text_field( $_POST['sheet_name']     ?? '' ),
            'field_map'      => json_decode( wp_unslash( $_POST['field_map'] ?? '[]' ), true ) ?: [],
            'conditions'     => json_decode( wp_unslash( $_POST['conditions'] ?? '{}' ), true ) ?: [],
            'send_event'     => sanitize_text_field( $_POST['send_event']     ?? 'form_submit' ),
            'is_active'      => (int)   ( $_POST['is_active']      ?? 1 ),
        ];

        if ( ! $data['form_id'] ) {
            wp_send_json_error( [ 'message' => __( 'Invalid form ID.', 'gf-google-sheets' ) ] );
        }

        $feed_id = GFGS_Database::save_feed( $data );
        wp_send_json_success( [ 'feed_id' => $feed_id ] );
    }

    public function ajax_delete_feed() {
        $this->verify_ajax();
        $feed_id = (int) ( $_POST['feed_id'] ?? 0 );
        GFGS_Database::delete_feed( $feed_id );
        wp_send_json_success();
    }

    public function ajax_toggle_feed() {
        $this->verify_ajax();
        $feed_id = (int)  ( $_POST['feed_id'] ?? 0 );
        $active  = (bool) ( $_POST['active']  ?? false );
        GFGS_Database::toggle_feed( $feed_id, $active );
        wp_send_json_success();
    }

    public function ajax_delete_account() {
        check_ajax_referer( 'gfgs_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        GFGS_Database::delete_account( (int) ( $_POST['account_id'] ?? 0 ) );
        wp_send_json_success();
    }

    public function ajax_manual_send() {
        $entry_id = (int) ( $_POST['entry_id'] ?? 0 );
        check_ajax_referer( 'gfgs_manual_send_' . $entry_id, 'nonce' );
        if ( ! current_user_can( 'gravityforms_view_entries' ) ) wp_send_json_error();
        $result = GFGS_Feed_Processor::manual_send( $entry_id );
        is_wp_error( $result )
            ? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
            : wp_send_json_success( $result );
    }
}