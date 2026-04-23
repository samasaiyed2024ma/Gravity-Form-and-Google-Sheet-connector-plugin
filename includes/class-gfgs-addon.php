<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFGS_Addon extends GFFeedAddOn {

    protected $_version                  = GFGS_VERSION;
    protected $_min_gravityforms_version = '2.6';
    protected $_slug                     = 'gf-google-sheets';
    protected $_full_path                = GFGS_PLUGIN_FILE;
    protected $_path                     = GFGS_PLUGIN_BASENAME;
    protected $_title                    = 'Google Sheets';
    protected $_short_title              = 'Google Sheets';
    protected $_supports_feed_ordering   = false;
    protected $_supports_feed            = true;

    private static $_instance = null;
    private $api;

    public function get_menu_icon() {
        return 'dashicons-media-document';
    }

    public static function get_instance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    // This is the actual GF hook for custom feed list:
    public function init() {
        $this->_path = GFGS_PLUGIN_BASENAME;
        parent::init();

        $this->api = new GFGS_Google_API();
        new GFGS_Feed_Processor();

        add_action( 'wp_ajax_gfgs_get_sheets',         [ $this, 'ajax_get_sheets' ] );
        add_action( 'wp_ajax_gfgs_get_spreadsheets',   [ $this, 'ajax_get_spreadsheets' ] );
        add_action( 'wp_ajax_gfgs_get_sheet_headers',  [ $this, 'ajax_get_sheet_headers' ] );
        add_action( 'wp_ajax_gfgs_manual_send',        [ $this, 'ajax_manual_send' ] );
        add_action( 'wp_ajax_gfgs_delete_account',     [ $this, 'ajax_delete_account' ] );
        add_action( 'wp_ajax_gfgs_save_feed',          [ $this, 'ajax_save_feed' ] );
        add_action( 'wp_ajax_gfgs_delete_feed',        [ $this, 'ajax_delete_feed' ] );
        add_action( 'wp_ajax_gfgs_toggle_feed',        [ $this, 'ajax_toggle_feed' ] );
        add_action( 'wp_ajax_gfgs_test_connection',    [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_gfgs_save_account_creds', [ $this, 'ajax_save_account_creds' ] );
        add_action( 'wp_ajax_gfgs_connect_account',    [ $this, 'ajax_connect_account' ] );
        add_action( 'wp_ajax_gfgs_render_feed_editor', [ $this, 'ajax_render_feed_editor' ] );

        // Oauth callback
        if ( isset( $_GET['gfgs_oauth'] ) && $_GET['gfgs_oauth'] === 'callback' ) {
            add_action( 'admin_init', [ $this, 'handle_oauth_callback' ] );
        }

        add_filter( 'gform_entry_detail_meta_boxes', [ $this, 'add_entry_meta_box' ], 10, 3 );
        add_action( 'admin_enqueue_scripts',          [ $this, 'enqueue_admin_scripts' ] );
    }

    // ── Plugin Registration ───────────────────────────────────────────────────

    public static function register_addon() {
        if ( ! class_exists( 'GFAddOn' ) ) return;
        GFAddOn::register( 'GFGS_Addon' );
    }

    // ── Template Rendering (Load a template file and pass data to it) ────────────────────────────────────────────────────

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

    // ── Plugin Settings Page ──────────────────────────────────────────────────

    public function plugin_settings_page() {
        wp_enqueue_style( 'gfgs-variable' );
        wp_enqueue_style( 'gfgs-components' );
        wp_enqueue_style( 'gfgs-feed-list' );
        wp_enqueue_style( 'gfgs-feed-editor' );
        wp_enqueue_style( 'gfgs-admin' );
        wp_enqueue_script( 'gfgs-admin' );
        wp_enqueue_script( 'gfgs-common' );
        wp_enqueue_script( 'gfgs-feed' );

        $redirect_uri    = admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_oauth=callback' );
        $accounts        = GFGS_Database::get_accounts();
        $view            = isset( $_GET['gfgs_view'] ) ? sanitize_text_field( $_GET['gfgs_view'] ) : 'list';
        $pending_id      = isset( $_GET['gfgs_pending'] ) ? (int) $_GET['gfgs_pending'] : 0;
        $add_account_url = admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' );
        $connected_msg   = isset( $_GET['connected'] ) ? __( 'Google account connected successfully!', 'GFGS' ) : '';
        $error_msg       = isset( $_GET['gfgs_error'] ) ? urldecode( sanitize_text_field( $_GET['gfgs_error'] ) ) : '';

        wp_localize_script( 'gfgs-admin', 'gfgsSettings', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'gfgs_nonce' ),
            'redirectUri'   => $redirect_uri,
            'accounts'      => array_map( fn( $a ) => (array) $a, $accounts ),
            'addAccountUrl' => $add_account_url,
            'connectedMsg'  => $connected_msg,
            'errorMsg'      => $error_msg,
        ] );

        if ( $view === 'add_account' ) {
            $pending_account = $pending_id ? GFGS_Database::get_account( $pending_id ) : null;
            $client_id       = $pending_account ? ( json_decode( $pending_account->access_token, true )['client_id']     ?? '' ) : '';
            $client_secret   = $pending_account ? ( json_decode( $pending_account->access_token, true )['client_secret'] ?? '' ) : '';

            $this->render_template( 'settings/add-account', [
                'pending_id'      => $pending_id,
                'pending_account' => $pending_account,
                'client_id'       => $client_id,
                'client_secret'   => $client_secret,
                'redirect_uri'    => $redirect_uri,
                'add_account_url' => $add_account_url,
                'error_msg'       => $error_msg,
            ] );
        } else {
            $this->render_template( 'settings/account-list', [
                'accounts'        => $accounts,
                'redirect_uri'    => $redirect_uri,
                'add_account_url' => $add_account_url,
                'connected_msg'   => $connected_msg,
                'error_msg'       => $error_msg,
            ] );
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

    // ── OAuth Callback ────────────────────────────────────────────────────────

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

    // ── Feed List Page ────────────────────────────────────────────────────────

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

    public function feed_list_page( $form = null ) {
        $form_id = is_array( $form ) ? (int) $form['id'] : intval( $form );
        if ( ! $form_id ) {
            $form_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        }
        if ( ! $form_id ) {
            echo '<div class="notice notice-error"><p>Could not determine form ID.</p></div>';
            return;
        }

        wp_enqueue_style( 'gfgs-variable' );
        wp_enqueue_style( 'gfgs-components' );
        wp_enqueue_style( 'gfgs-feed-list' );
        wp_enqueue_style( 'gfgs-feed-editor' );
        wp_enqueue_style( 'gfgs-admin' );
        wp_enqueue_script( 'gfgs-admin' );
        wp_enqueue_script( 'gfgs-common' );
        wp_enqueue_script( 'gfgs-feed' );

        $form_data = GFAPI::get_form( $form_id );
        $feeds     = GFGS_Database::get_feeds_by_form( $form_id );
        $accounts  = GFGS_Database::get_accounts();
        $fields    = $this->build_field_list( $form_data );

        wp_localize_script( 'gfgs-feed', 'gfgsData', [
            'formId'        => $form_id,
            'feeds'         => array_map( fn( $f ) => $this->prepare_feed_for_js( $f ), $feeds ),
            'accounts'      => array_map( fn( $a ) => (array) $a, $accounts ),
            'fields'        => $fields,
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'gfgs_nonce' ),
            'addAccountUrl' => admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' ),
            'feedEvents'    => [
                'form_submit'          => __( 'Form Submission', 'GFGS' ),
                'payment_completed'    => __( 'Payment Completed', 'GFGS' ),
                'payment_refunded'     => __( 'Payment Refunded', 'GFGS' ),
                'submission_completed' => __( 'After All Notifications Sent', 'GFGS' ),
                'entry_updated'        => __( 'Entry Updated', 'GFGS' ),
            ],
            'i18n'          => [
                'feedList'  => __( 'Google Sheets Feeds', 'GFGS' ),
                'addFeed'   => __( 'Add New Feed', 'GFGS' ),
                'noFeed'    => __( 'No feeds yet.', 'GFGS' ),
                'confirmDel'=> __( 'Delete this feed?', 'GFGS' ),
            ],
        ] );

        $this->render_template( 'feeds/feed-list', [
            'form_id'  => $form_id,
            'feeds'    => $feeds,
            'accounts' => $accounts,
        ] );
    }

    // ── Field List Builder ────────────────────────────────────────────────────

    public function build_field_list( $form_data ) {
        $fields = [];
        if ( empty( $form_data['fields'] ) ) return $fields;

        foreach ( $form_data['fields'] as $field ) {
            if ( ! is_object( $field ) ) continue;

            if ( isset( $field->inputs ) && is_array( $field->inputs ) ) {
                foreach ( $field->inputs as $input ) {
                    $fields[] = [
                        'id'      => (string) $input['id'],
                        'label'   => strip_tags( $field->label . ' (' . $input['label'] . ')' ),
                        'type'    => $field->type,
                        'choices' => [],
                    ];
                }
            } else {
                $choices = [];
                if ( ! empty( $field->choices ) && is_array( $field->choices ) ) {
                    foreach ( $field->choices as $choice ) {
                        $choices[] = [
                            'value' => $choice['value'] ?? '',
                            'text'  => $choice['text']  ?? $choice['value'] ?? '',
                        ];
                    }
                }
                $fields[] = [
                    'id'      => (string) $field->id,
                    'label'   => strip_tags( $field->label ?? 'Field ' . $field->id ),
                    'type'    => $field->type,
                    'choices' => $choices,
                ];
            }
        }

        return $fields;
    }

    /**
     * Decode a feed object
     */
    private function prepare_feed_for_js( $feed ) {
        $arr = (array) $feed;

        // Decode JSON fields
        foreach ( [ 'field_map', 'conditions', 'date_formats' ] as $key ) {
            if ( isset( $arr[ $key ] ) && is_string( $arr[ $key ] ) ) {
                $arr[ $key ] = json_decode( $arr[ $key ], true ) ?: [];
            }
            if ( $key === 'conditions' ) {
                $arr[ $key ] = wp_parse_args( (array) ( $arr[ $key ] ?? [] ), [
                    'enabled' => false,
                    'action'  => 'send',
                    'logic'   => 'all',
                    'rules'   => [],
                ] );
            } elseif ( ! isset( $arr[ $key ] ) ) {
                $arr[ $key ] = [];
            }
        }

        // Normalise field_map: support both old 'column'/'field_id' and new 'sheet_column'/'field_id'
        $arr['field_map'] = array_map( function ( $m ) {
            return [
                'sheet_column' => $m['sheet_column'] ?? $m['column']   ?? '',
                'field_id'     => $m['field_id']     ?? $m['gf_field'] ?? '',
                'field_type'   => $m['field_type']   ?? 'standard',
            ];
        }, (array) $arr['field_map'] );

        // Cast to int so JS receives 0 or 1, not the string "0" which is truthy in JS
    	$arr['is_active'] = (int) ( $arr['is_active'] ?? 1 );

        return $arr;
    }

    // ── Entry Meta Box ────────────────────────────────────────────────────────

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
        $feeds = GFGS_Database::get_feeds_by_form( $entry['form_id'] );
        ?>
        <div class="gfgs-entry-box">
            <p><?php esc_html_e( 'Manually send this entry to Google Sheets feeds.', 'GFGS' ); ?></p>

            <?php if ( ! empty( $feeds ) ) : ?>
                <select id="gfgs-feed-select" style="width:100%;margin-bottom:8px;">
                    <option value="all"><?php esc_html_e( 'All Active Feeds', 'GFGS' ); ?></option>
                    <?php foreach ( $feeds as $feed ) : ?>
                        <?php if ( $feed->is_active ) : ?>
                            <option value="<?php echo (int) $feed->id; ?>">
                                <?php echo esc_html( $feed->feed_name ); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="button"
                    class="button button-primary gfgs-manual-send-btn"
                    data-entry-id="<?php echo (int) $entry['id']; ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'gfgs_manual_send_' . $entry['id'] ) ); ?>"
                    style="width:100%">
                <?php esc_html_e( 'Send to Google Sheets', 'GFGS' ); ?>
            </button>
            <span class="gfgs-send-status" style="display:none;margin-top:8px;display:block;"></span>
        </div>

        <script>
        (function($){
            $(document).on('click', '.gfgs-manual-send-btn', function(){
                var $btn    = $(this);
                var $status = $btn.siblings('.gfgs-send-status');
                var entryId = $btn.data('entry-id');
                var nonce   = $btn.data('nonce');
                var feedId  = $('#gfgs-feed-select').val() || 'all';

                $btn.prop('disabled', true).text('Sending…');
                $status.hide().text('');

                $.post(ajaxurl, {
                    action:   'gfgs_manual_send',
                    nonce:    nonce,
                    entry_id: entryId,
                    feed_id:  feedId,
                }, function(res){
                    $btn.prop('disabled', false).text('Send to Google Sheets');
                    if (res.success) {
                        $status.show().css('color','green').text('✓ Sent ' + res.data.sent + ' feed(s) successfully.');
                    } else {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Send failed.';
                        $status.show().css('color','red').text('✗ ' + msg);
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).text('Send to Google Sheets');
                    $status.show().css('color','red').text('✗ Network error. Please try again.');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── Admin Assets ──────────────────────────────────────────────────────────

    public function enqueue_admin_scripts( $hook ) {
        wp_register_style( 'gfgs-variable',   GFGS_PLUGIN_URL . 'assets/css/variable.css',    [], GFGS_VERSION );
        wp_register_style( 'gfgs-admin',      GFGS_PLUGIN_URL . 'assets/css/admin.css',       [], GFGS_VERSION );
        wp_register_style( 'gfgs-components', GFGS_PLUGIN_URL . 'assets/css/components.css',  [], GFGS_VERSION );
        wp_register_style( 'gfgs-feed-list',  GFGS_PLUGIN_URL . 'assets/css/feed-list.css',   [], GFGS_VERSION );
        wp_register_style( 'gfgs-feed-editor',GFGS_PLUGIN_URL . 'assets/css/feed-editor.css', [], GFGS_VERSION );

        wp_register_script( 'gfgs-common', GFGS_PLUGIN_URL . 'assets/js/admin.js',     [ 'jquery' ],              GFGS_VERSION, true );
        wp_register_script( 'gfgs-admin',  GFGS_PLUGIN_URL . 'assets/js/settings.js',  [ 'jquery', 'gfgs-common' ], GFGS_VERSION, true );
        wp_register_script( 'gfgs-feed',   GFGS_PLUGIN_URL . 'assets/js/feed-list.js', [ 'jquery', 'gfgs-common' ], GFGS_VERSION, true );
    }

    // ── AJAX Helpers ──────────────────────────────────────────────────────────

    private function verify_ajax() {
        if ( ! check_ajax_referer( 'gfgs_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'GFGS' ) ] );
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gravityforms_edit_forms' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'GFGS' ) ] );
        }
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

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
            wp_send_json_error( [ 'message' => __( 'Client ID and Client Secret are required.', 'GFGS' ) ] );
        }

        // Check if updating existing pending account
        $existing_id = (int) ( $_POST['account_id'] ?? 0 );

        $pending_id = GFGS_Database::save_account( [
            'id'            => $existing_id ?: null,
            'account_name'  => $account_name ?: __( 'My Google Account', 'GFGS' ),
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
        $auth_url     = GFGS_Google_API::OAUTH_AUTH_URL . '?' . http_build_query( [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $pending_id,
        ] );

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
            wp_send_json_error( [ 'message' => __( 'Client ID and Client Secret are required.', 'GFGS' ) ] );
        }

        // If account has tokens already, try to use them
        if ( $account_id ) {
            $account = GFGS_Database::get_account( $account_id );
            if ( $account && $account->refresh_token ) {
                $result = $this->api->refresh_token_with_creds( $account->refresh_token, $client_id, $client_secret );
                if ( ! is_wp_error( $result ) && ! empty( $result['access_token'] ) ) {
                    wp_send_json_success( [ 'message' => __( 'Connection successful! Your credentials are valid.', 'GFGS' ) ] );
                }
            }
        }

        wp_send_json_error( [ 'message' => __( 'Could not verify connection. Please complete the Google authorization first.', 'GFGS' ) ] );
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
        $account_id     = (int) ( $_POST['account_id'] ?? 0 );
        $spreadsheet_id = sanitize_text_field( $_POST['spreadsheet_id'] ?? '' );
        $result         = $this->api->get_spreadsheet_sheets( $account_id, $spreadsheet_id );
        is_wp_error( $result )
            ? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
            : wp_send_json_success( $result );
    }

    public function ajax_get_sheet_headers() {
        $this->verify_ajax();
        $account_id     = (int) ( $_POST['account_id'] ?? 0 );
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
            'id'             => (int)  ( $_POST['id']             ?? 0 ),
            'form_id'        => (int)  ( $_POST['form_id']        ?? 0 ),
            'feed_name'      => sanitize_text_field( $_POST['feed_name']      ?? '' ),
            'account_id'     => (int)  ( $_POST['account_id']     ?? 0 ),
            'spreadsheet_id' => sanitize_text_field( $_POST['spreadsheet_id'] ?? '' ),
            'sheet_id'       => sanitize_text_field( $_POST['sheet_id']       ?? '' ),
            'sheet_name'     => sanitize_text_field( $_POST['sheet_name']     ?? '' ),
            'field_map'      => json_decode( wp_unslash( $_POST['field_map']    ?? '[]' ), true ) ?: [],
            'date_formats'   => json_decode( wp_unslash( $_POST['date_formats'] ?? '{}' ), true ) ?: [],
            'conditions'     => json_decode( wp_unslash( $_POST['conditions']   ?? '{}' ), true ) ?: [],
            'send_event'     => sanitize_text_field( $_POST['send_event'] ?? 'form_submit' ),
            'is_active'      => (int)  ( $_POST['is_active'] ?? 1 ),
        ];

        if ( ! $data['form_id'] ) {
            wp_send_json_error( [ 'message' => __( 'Invalid form ID.', 'GFGS' ) ] );
        }

        // Re-encode date_formats alongside field_map for storage
        foreach ( [ 'field_map', 'date_formats', 'conditions' ] as $key ) {
            if ( is_array( $data[ $key ] ) ) {
                $data[ $key ] = wp_json_encode( $data[ $key ] );
            }
        }

        $feed_id = GFGS_Database::save_feed_raw( $data );
        if ( is_wp_error( $feed_id ) ) {
            wp_send_json_error( [ 'message' => $feed_id->get_error_message() ] );
        }
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
        $active  = (int) ( $_POST['active']  ?? 0 );
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

        // Verify nonce properly
        if ( ! check_ajax_referer( 'gfgs_manual_send_' . $entry_id, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ] );
        }
        if ( ! current_user_can( 'gravityforms_view_entries' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? 'all' );
        $result  = GFGS_Feed_Processor::manual_send( $entry_id, $feed_id === 'all' ? null : (int) $feed_id );

        is_wp_error( $result )
            ? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
            : wp_send_json_success( $result );
    }

	/**
     * AJAX: Render the feed editor HTML for a given feed/form.
     * Called by feed-list.js startEditing() when user clicks Add New or Edit.
     */
    public function ajax_render_feed_editor() {
        // Editor is now fully JS-rendered
        $this->verify_ajax();
        wp_send_json_error( [ 'message' => 'This endpoint is no longer used.' ] );
    }

    /**
     * AJAX: Connect account (alias for save_account_creds flow).
     * Kept for back-compat with any JS calling gfgs_connect_account directly.
     */
    public function ajax_connect_account() {
        $this->ajax_save_account_creds();
    }
}