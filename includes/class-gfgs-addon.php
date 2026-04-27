<?php
/**
 * Gravity Forms Feed Add-on for Google Sheets.
 *
 * Extends GFFeedAddOn to integrate with the Gravity Forms UI:
 *   - Plugin settings page (Google account management / OAuth).
 *   - Feed list / editor per form.
 *   - Entry detail meta box (manual send).
 *   - All AJAX endpoints consumed by the front-end JS.
 *
 * Architecture notes:
 *   - Asset registration is delegated to GFGS_Assets; this class only enqueues.
 *   - Nonce verification + capability checks are centralised in verify_ajax().
 *   - Database I/O goes through GFGS_Database; Google API calls go through GFGS_Google_API.
 *   - feed_list_page() / plugin_settings_page() both call GFGS_Assets::enqueue_admin_assets()
 *     — no duplicate inline registration.
 *   - The entry meta-box JS is loaded via a registered script handle, not inline.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Addon extends GFFeedAddOn {

	// ── GFFeedAddOn required properties ───────────────────────────────────────

    /** @var string */
    protected $_version = GFGS_VERSION;
	
    /** @var string Minimum Gravity Forms version. */
    protected $_min_gravityforms_version = '2.6';

   	/** @var string Add-on slug (matches plugin text-domain). */
    protected $_slug = 'gf-google-sheets';

    /** @var string Absolute path to the main plugin file. */
    protected $_full_path = GFGS_PLUGIN_FILE;
    
    /** @var string Plugin basename (used by GF for menu links). */
    protected $_path = GFGS_PLUGIN_BASENAME;

   	/** @var string Long title displayed in GF settings. */
    protected $_title = 'Google Sheets';

   	/** @var string Short title used in GF navigation. */
    protected $_short_title = 'Google Sheets';
  	
    /** @var bool Feed ordering is not supported by this add-on. */
    protected $_supports_feed_ordering = false;

   	/** @var bool This add-on provides feeds. */
    protected $_supports_feed = true;

	/** @var GFGS_Addon|null Singleton instance. */
    private static ?GFGS_Addon $_instance = null;

   	/** @var GFGS_Google_API Google API client, initialised in init(). */
    private GFGS_Google_API $api;

    // ── Singleton ─────────────────────────────────────────────────────────────
 
	/**
	 * Retrieve or create the singleton instance.
	 *
	 * @return GFGS_Addon
	 */
    public static function get_instance(): GFGS_Addon {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
	 * Return a dashicon slug for the plugin's GF menu.
	 *
	 * @return string
	 */
    public function get_menu_icon():string {
        return 'dashicons-media-document';
    }
	
	public function enqueue_entry_detail_assets( string $hook ): void {
		$page     = sanitize_text_field( wp_unslash( $_GET['page']     ?? '' ) );
		$view     = sanitize_text_field( wp_unslash( $_GET['view']     ?? '' ) );
		$entry_id = (int) ( $_GET['lid'] ?? $_GET['entry_id'] ?? 0 );

		if ( 'gf_entries' !== $page ) {
			return;
		}

		if ( 'entry' !== $view && ! $entry_id ) {
			return;
		}

		wp_register_script(
			'gfgs-common',
			GFGS_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			GFGS_VERSION,
			true
		);

		wp_enqueue_script( 'gfgs-common' );

		wp_localize_script( 'gfgs-common', 'gfgsEntryData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => $entry_id ? wp_create_nonce( 'gfgs_manual_send_' . $entry_id ) : '',
		] );
	}

 	// ── GFAddOn lifecycle ─────────────────────────────────────────────────────
 
	/**
	 * Initialise the add-on after GF loads.
	 *
	 * Registers all AJAX actions, the OAuth callback, the entry meta-box hook,
	 * and the asset manager hook.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->_path = GFGS_PLUGIN_BASENAME;
		parent::init();
 
		$this->api = new GFGS_Google_API();
 
		// Instantiate the feed processor — it registers its own GF hooks.
		new GFGS_Feed_Processor( $this->api );
 
		// Register and initialise the asset manager.
		$assets = new GFGS_Assets();
		$assets->register_hooks();
 
		// Register AJAX endpoints.
		$this->register_ajax_actions();
 
		// Handle OAuth callback when the state param is present.
		if ( isset( $_GET['gfgs_oauth'] ) && 'callback' === $_GET['gfgs_oauth'] ) {
			add_action( 'admin_init', [ $this, 'handle_oauth_callback' ] );
		}
 
		// Entry detail meta-box.
		add_filter( 'gform_entry_detail_meta_boxes', [ $this, 'add_entry_meta_box' ], 10, 3 );
		
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_entry_detail_assets' ] );

	}

    // ── AJAX action registration ───────────────────────────────────────────────
 
	/**
	 * Register all wp_ajax_* actions for this add-on.
	 *
	 * Centralised here so it is easy to see all available endpoints at a glance.
	 * All handlers follow the naming convention ajax_{action_slug}().
	 *
	 * @return void
	 */
	private function register_ajax_actions(): void {
		$actions = [
			'gfgs_get_sheets'         => 'ajax_get_sheets',
			'gfgs_get_spreadsheets'   => 'ajax_get_spreadsheets',
			'gfgs_get_sheet_headers'  => 'ajax_get_sheet_headers',
			'gfgs_manual_send'        => 'ajax_manual_send',
			'gfgs_delete_account'     => 'ajax_delete_account',
			'gfgs_save_feed'          => 'ajax_save_feed',
			'gfgs_delete_feed'        => 'ajax_delete_feed',
			'gfgs_duplicate_feed'     => 'ajax_duplicate_feed',
			'gfgs_toggle_feed'        => 'ajax_toggle_feed',
			'gfgs_test_connection'    => 'ajax_test_connection',
			'gfgs_save_account_creds' => 'ajax_save_account_creds',
			// Back-compat alias — same handler as save_account_creds.
			'gfgs_connect_account'    => 'ajax_save_account_creds',
		];
 
		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	// ── Template rendering ────────────────────────────────────────────────────
 
	/**
	 * Load a PHP template file and expose $data keys as local variables.
	 *
	 * Templates live in /templates/ relative to the plugin root.
	 * Pass a path without the leading slash, e.g. 'settings/account-list'.
	 *
	 * @param  string               $template Relative template path (without .php extension).
	 * @param  array<string, mixed> $data     Variables to extract into template scope.
	 * @return void
	 */
	private function render_template( string $template, array $data = [] ): void {
		$file = GFGS_PLUGIN_DIR . 'templates/' . $template . '.php';
 
		if ( ! file_exists( $file ) ) {
			GFGS_Logger::error( 'Template not found: ' . $file );
			return;
		}
 
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data );
		include $file;
	}

	// ── Plugin settings page ──────────────────────────────────────────────────
 
	/**
	 * Render the plugin-level settings page (account management).
	 *
	 * Called by the GF add-on framework when the user visits the settings tab.
	 *
	 * @return void
	 */
	public function plugin_settings_page(): void {
		GFGS_Assets::enqueue_admin_assets();
 
		$redirect_uri    = $this->build_redirect_uri();
		$accounts        = GFGS_Database::get_accounts();
		$view            = isset( $_GET['gfgs_view'] ) ? sanitize_text_field( wp_unslash( $_GET['gfgs_view'] ) ) : 'list';
		$pending_id      = isset( $_GET['gfgs_pending'] ) ? (int) $_GET['gfgs_pending'] : 0;
		$add_account_url = admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' );
		$connected_msg   = isset( $_GET['connected'] ) ? __( 'Google account connected successfully!', GFGS ) : '';
		$error_msg       = isset( $_GET['gfgs_error'] ) ? urldecode( sanitize_text_field( wp_unslash( $_GET['gfgs_error'] ) ) ) : '';
 
		wp_localize_script( 'gfgs-admin', 'gfgsSettings', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'gfgs_nonce' ),
			'redirectUri'   => $redirect_uri,
			'accounts'      => array_map( static fn( $a ) => (array) $a, $accounts ),
			'addAccountUrl' => $add_account_url,
			'connectedMsg'  => $connected_msg,
			'errorMsg'      => $error_msg,
		] );
 
		if ( 'add_account' === $view ) {
			$pending_account = $pending_id ? GFGS_Database::get_account( $pending_id ) : null;
			$token_data      = $pending_account ? json_decode( $pending_account->access_token, true ) : [];
			$client_id       = $token_data['client_id']     ?? '';
			$client_secret   = $token_data['client_secret'] ?? '';
 
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
	 * Return an empty array — the full settings UI is rendered by plugin_settings_page().
	 *
	 * Required override to prevent GF from rendering its default settings fields.
	 *
	 * @return array
	 */
	public function plugin_settings_fields(): array {
		return [];
	}
    
    /**
     * Optional: Helper to show a checkmark if the setting is filled.
     */
    public function is_valid_setting( $value ) {
        return ! empty( $value );
    }

	// ── OAuth callback ────────────────────────────────────────────────────────
 
	/**
	 * Handle the OAuth 2.0 redirect from Google.
	 *
	 * Fires on admin_init when ?gfgs_oauth=callback is present in the URL.
	 * Exchanges the authorization code for tokens and persists them.
	 *
	 * @return void
	 */
	public function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', GFGS ) );
		}
 
		$pending_id   = isset( $_GET['state'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['state'] ) ) : 0;
		$redirect_uri = $this->build_redirect_uri();
 
		// Build the base redirect URL for error and success redirects.
		$settings_base = 'admin.php?page=gf_settings&subview=gf-google-sheets';
 
		if ( empty( $_GET['code'] ) ) {
			$error = urlencode( __( 'OAuth failed: no code returned.', GFGS ) );
			wp_redirect( admin_url( "{$settings_base}&gfgs_view=add_account&gfgs_pending={$pending_id}&gfgs_error={$error}" ) );
			exit;
		}
 
		$pending = $pending_id ? GFGS_Database::get_account( $pending_id ) : null;
		if ( ! $pending ) {
			$error = urlencode( __( 'OAuth failed: pending account not found.', GFGS ) );
			wp_redirect( admin_url( "{$settings_base}&gfgs_error={$error}" ) );
			exit;
		}
 
		$creds         = json_decode( $pending->access_token, true );
		$client_id     = $creds['client_id']     ?? '';
		$client_secret = $creds['client_secret'] ?? '';
		$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
 
		$tokens = $this->api->exchange_code( $code, $client_id, $client_secret, $redirect_uri );
 
		if ( is_wp_error( $tokens ) ) {
			$error = urlencode( $tokens->get_error_message() );
			wp_redirect( admin_url( "{$settings_base}&gfgs_view=add_account&gfgs_pending={$pending_id}&gfgs_error={$error}" ) );
			exit;
		}
 
		$user_info = $this->api->get_user_info( $tokens['access_token'] );
		$email     = is_wp_error( $user_info )
			? ( $pending->email ?: 'unknown@example.com' )
			: ( $user_info['email'] ?? 'unknown' );
 
		GFGS_Database::save_account( [
			'id'            => $pending_id,
			'account_name'  => $pending->account_name ?: $email,
			'email'         => $email,
			'access_token'  => wp_json_encode( [
				'access_token'  => $tokens['access_token'],
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			] ),
			'refresh_token' => $tokens['refresh_token'] ?? '',
			'token_expires' => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
		] );
 
		wp_redirect( admin_url( "{$settings_base}&connected=1" ) );
		exit;
	}

 	// ── Feed list page ────────────────────────────────────────────────────────
 
	/**
	 * Allow feeds to be created for any form.
	 *
	 * @return bool
	 */
	public function can_create_feed(): bool {
		return true;
	}

	/**
	 * Define the columns shown in the default GF feed list table.
	 *
	 * @return array<string, string>  column_key => label
	 */
	public function feed_list_columns(): array {
		return [
			'feed_name'  => esc_html__( 'Feed Name', GFGS ),
			'sheet_info' => esc_html__( 'Spreadsheet / Sheet', GFGS ),
			'send_event' => esc_html__( 'Send On', GFGS ),
		];
	}

	/**
	 * Render the custom feed list / editor page for a form.
	 *
	 * @param  array|int|null $form GF form array, form ID, or null (falls back to $_GET['id']).
	 * @return void
	 */
	public function feed_list_page( $form = null ): void {
		$form_id = is_array( $form ) ? (int) $form['id'] : (int) $form;
 
		if ( ! $form_id ) {
			$form_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		}
 
		if ( ! $form_id ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Could not determine form ID.', GFGS ) );
			return;
		}
 
		GFGS_Assets::enqueue_admin_assets();
 
		$form_data = GFAPI::get_form( $form_id );
		$feeds     = GFGS_Database::get_feeds_by_form( $form_id );
		$accounts  = GFGS_Database::get_accounts();
 
		wp_localize_script( 'gfgs-feed', 'gfgsData', [
			'formId'        => $form_id,
			'feeds'         => array_map( [ $this, 'prepare_feed_for_js' ], $feeds ),
			'accounts'      => array_map( static fn( $a ) => (array) $a, $accounts ),
			'fields'        => $this->build_field_list( $form_data ),
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'gfgs_nonce' ),
			'addAccountUrl' => admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' ),
			'feedEvents'    => $this->get_feed_events(),
			'i18n'          => [
				'feedList'   => __( 'Google Sheets Feeds', GFGS ),
				'addFeed'    => __( 'Add New Feed', GFGS ),
				'noFeed'     => __( 'No feeds yet.', GFGS ),
				'confirmDel' => __( 'Delete this feed?', GFGS ),
			],
		] );
 
		$this->render_template( 'feeds/feed-list', [
			'form_id'  => $form_id,
			'feeds'    => $feeds,
			'accounts' => $accounts,
		] );
	}

    // ── Entry meta-box ────────────────────────────────────────────────────────
 
	/**
	 * Register a sidebar meta-box on the entry detail page when feeds exist.
	 *
	 * @param  array  $meta_boxes Registered meta boxes.
	 * @param  array  $entry      GF entry array.
	 * @param  array  $form       GF form array.
	 * @return array  Modified meta boxes.
	 */
	public function add_entry_meta_box( array $meta_boxes, array $entry, array $form ): array {
		$feeds = GFGS_Database::get_feeds_by_form( (int) $form['id'] );
 
		if ( empty( $feeds ) ) {
			return $meta_boxes;
		}
 
		$meta_boxes['gfgs_manual_send'] = [
			'title'    => esc_html__( 'Google Sheets', GFGS ),
			'callback' => [ $this, 'render_entry_meta_box' ],
			'context'  => 'side',
		];
 
		return $meta_boxes;
	}

    /**
	 * Render the "Send to Google Sheets" meta-box on the entry detail page.
	 *
	 * @param  array $args {
	 *     @type array $entry GF entry array.
	 *     @type array $form  GF form array.
	 * }
	 * @return void
	 */
	public function render_entry_meta_box( array $args ): void {
		$entry = $args['entry'];
		$feeds = GFGS_Database::get_feeds_by_form( (int) $entry['form_id'] );
 
		$active_feeds = array_filter( $feeds, static fn( $f ) => (bool) $f->is_active );
 
		$this->render_template( 'entry/meta-box', [
			'entry'        => $entry,
			'feeds'        => $feeds,
			'active_feeds' => $active_feeds,
			'nonce'        => wp_create_nonce( 'gfgs_manual_send_' . $entry['id'] ),
		] );
	}

    // ── Helper: field list for JS ─────────────────────────────────────────────
 
	/**
	 * Build a flat list of form fields suitable for the JS feed editor.
	 *
	 * Composite fields (name, address, etc.) are expanded into their sub-inputs.
	 * Choice-based fields include their available choices for label mapping.
	 *
	 * @param  array $form_data GF form array.
	 * @return array[] Array of field descriptor arrays {id, label, type, choices}.
	 */
	public function build_field_list( array $form_data ): array {
		$fields = [];
 
		if ( empty( $form_data['fields'] ) ) {
			return $fields;
		}
 
		foreach ( $form_data['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
 
			// Composite fields: expand each sub-input as a separate entry.
			if ( isset( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					$fields[] = [
						'id'      => (string) $input['id'],
						'label'   => wp_strip_all_tags( $field->label . ' (' . $input['label'] . ')' ),
						'type'    => $field->type,
						'choices' => [],
					];
				}
				continue;
			}
 
			// Standard fields: include choices if present.
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
				'label'   => wp_strip_all_tags( $field->label ?? 'Field ' . $field->id ),
				'type'    => $field->type,
				'choices' => $choices,
			];
		}
 
		return $fields;
	}

    // ── Helper: feed events ───────────────────────────────────────────────────
 
	/**
	 * Return the available send_event slugs with their human-readable labels.
	 *
	 * Used to populate the "Send On" select in the feed editor.
	 * Add new event slugs here when expanding GFGS_Feed_Processor.
	 *
	 * @return array<string, string>  slug => label
	 */
	private function get_feed_events(): array {
		return [
			'form_submit'          => __( 'Form Submission', GFGS ),
			'payment_completed'    => __( 'Payment Completed', GFGS ),
			'payment_refunded'     => __( 'Payment Refunded', GFGS ),
			'submission_completed' => __( 'After All Notifications Sent', GFGS ),
			'entry_updated'        => __( 'Entry Updated', GFGS ),
		];
	}

 	// ── Helper: prepare feed for JS ───────────────────────────────────────────
 
	/**
	 * Convert a feed row object (already decoded by GFGS_Database) to a plain
	 * array safe to pass to wp_localize_script().
	 *
	 * GFGS_Database::decode_feed() is the single place where JSON is parsed and
	 * field_map keys are normalised. This method only casts the object to array
	 * and ensures is_active is an integer.
	 *
	 * @param  object $feed Decoded feed row from GFGS_Database.
	 * @return array<string, mixed>
	 */
	public function prepare_feed_for_js( object $feed ): array {
		return (array) $feed;
	}

    // ── Helper: redirect URI ──────────────────────────────────────────────────
 
	/**
	 * Build the standard OAuth redirect URI for this plugin's settings page.
	 *
	 * @return string  Absolute admin URL.
	 */
	private function build_redirect_uri(): string {
		return admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_oauth=callback' );
	}
 
	// ── AJAX: centralised verification ───────────────────────────────────────
 
	/**
	 * Verify the AJAX nonce and check the caller has sufficient capabilities.
	 *
	 * On failure, sends a JSON error response and terminates execution (wp_send_json_error
	 * calls wp_die internally).
	 *
	 * @return void
	 */
	private function verify_ajax(): void {
		if ( ! check_ajax_referer( 'gfgs_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', GFGS ) ] );
		}
 
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gravityforms_edit_forms' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', GFGS ) ] );
		}
	}

	// ── AJAX handlers: accounts ───────────────────────────────────────────────
 
	/**
	 * AJAX: Save OAuth client credentials and return the pending account ID
	 * and the Google authorisation URL.
	 *
	 * This does NOT complete the OAuth flow; the JS redirects the user to
	 * Google's consent screen using the returned auth_url.
	 *
	 * @return void
	 */
	public function ajax_save_account_creds(): void {
		$this->verify_ajax();
 
		$account_name  = sanitize_text_field( wp_unslash( $_POST['account_name']  ?? '' ) );
		$client_id     = sanitize_text_field( wp_unslash( $_POST['client_id']     ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
 
		if ( ! $client_id || ! $client_secret ) {
			wp_send_json_error( [ 'message' => __( 'Client ID and Client Secret are required.', GFGS ) ] );
		}
 
		$existing_id = (int) ( $_POST['account_id'] ?? 0 );
 
		$pending_id = GFGS_Database::save_account( [
			'id'            => $existing_id ?: null,
			'account_name'  => $account_name ?: __( 'My Google Account', GFGS ),
			'email'         => '',
			'access_token'  => wp_json_encode( [
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			] ),
			'refresh_token' => '',
			'token_expires' => 0,
		] );
 
		$auth_url = GFGS_Google_API::build_auth_url( $client_id, $this->build_redirect_uri(), $pending_id );
 
		wp_send_json_success( [
			'pending_id' => $pending_id,
			'auth_url'   => $auth_url,
		] );
	}

	/**
	 * AJAX: Test that the stored credentials can successfully obtain a valid token.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		$this->verify_ajax();
 
		$client_id     = sanitize_text_field( wp_unslash( $_POST['client_id']     ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		$account_id    = (int) ( $_POST['account_id'] ?? 0 );
 
		if ( ! $client_id || ! $client_secret ) {
			wp_send_json_error( [ 'message' => __( 'Client ID and Client Secret are required.', GFGS ) ] );
		}
 
		if ( $account_id ) {
			$account = GFGS_Database::get_account( $account_id );
 
			if ( $account && $account->refresh_token ) {
				$result = $this->api->refresh_token( $account->refresh_token, $client_id, $client_secret );
 
				if ( ! is_wp_error( $result ) && ! empty( $result['access_token'] ) ) {
					wp_send_json_success( [ 'message' => __( 'Connection successful! Your credentials are valid.', GFGS ) ] );
				}
			}
		}
 
		wp_send_json_error( [ 'message' => __( 'Could not verify connection. Please complete the Google authorization first.', GFGS ) ] );
	}

    /**
	 * AJAX: Delete a Google account record.
	 *
	 * @return void
	 */
	public function ajax_delete_account(): void {
		$this->verify_ajax();
		GFGS_Database::delete_account( (int) ( $_POST['account_id'] ?? 0 ) );
		wp_send_json_success();
	}

	// ── AJAX handlers: spreadsheet/sheet discovery ────────────────────────────
 
	/**
	 * AJAX: Return the list of spreadsheets accessible by an account.
	 *
	 * @return void
	 */
	public function ajax_get_spreadsheets(): void {
		$this->verify_ajax();
 
		$account_id = (int) ( $_POST['account_id'] ?? 0 );
		$result     = $this->api->list_spreadsheets( $account_id );
 
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}

 	/**
	 * AJAX: Return the sheet (tab) list for a spreadsheet.
	 *
	 * @return void
	 */
	public function ajax_get_sheets(): void {
		$this->verify_ajax();
 
		$account_id     = (int) ( $_POST['account_id'] ?? 0 );
		$spreadsheet_id = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
		$result         = $this->api->get_spreadsheet_sheets( $account_id, $spreadsheet_id );
 
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}

	/**
	 * AJAX: Return the header row of a specific sheet.
	 *
	 * @return void
	 */
	public function ajax_get_sheet_headers(): void {
		$this->verify_ajax();
 
		$account_id     = (int) ( $_POST['account_id'] ?? 0 );
		$spreadsheet_id = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
		$sheet_name     = sanitize_text_field( wp_unslash( $_POST['sheet_name']     ?? '' ) );
		$result         = $this->api->get_sheet_headers( $account_id, $spreadsheet_id, $sheet_name );
 
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}

	// ── AJAX handlers: feeds ──────────────────────────────────────────────────
 
	/**
	 * AJAX: Save (create or update) a feed.
	 *
	 * @return void
	 */
	public function ajax_save_feed(): void {
		$this->verify_ajax();
 
		$data = [
			'id'             => (int) ( $_POST['id']         ?? 0 ),
			'form_id'        => (int) ( $_POST['form_id']    ?? 0 ),
			'feed_name'      => sanitize_text_field( wp_unslash( $_POST['feed_name']      ?? '' ) ),
			'account_id'     => (int) ( $_POST['account_id'] ?? 0 ),
			'spreadsheet_id' => sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) ),
			'sheet_id'       => sanitize_text_field( wp_unslash( $_POST['sheet_id']       ?? '' ) ),
			'sheet_name'     => sanitize_text_field( wp_unslash( $_POST['sheet_name']     ?? '' ) ),
			'field_map'      => json_decode( wp_unslash( $_POST['field_map']    ?? '[]' ), true ) ?: [],
			'date_formats'   => json_decode( wp_unslash( $_POST['date_formats'] ?? '{}' ), true ) ?: [],
			'conditions'     => json_decode( wp_unslash( $_POST['conditions']   ?? '{}' ), true ) ?: [],
			'send_event'     => sanitize_text_field( wp_unslash( $_POST['send_event'] ?? 'form_submit' ) ),
			'is_active'      => (int) ( $_POST['is_active'] ?? 1 ),
		];
 
		if ( ! $data['form_id'] ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form ID.', GFGS ) ] );
		}
 
		// save_feed() encodes array columns to JSON automatically.
		$feed_id = GFGS_Database::save_feed( $data );
 
		if ( is_wp_error( $feed_id ) ) {
			wp_send_json_error( [ 'message' => $feed_id->get_error_message() ] );
		}
 
		wp_send_json_success( [ 'feed_id' => $feed_id ] );
	}

	/**
	 * AJAX: Delete a feed record.
	 *
	 * @return void
	 */
	public function ajax_delete_feed(): void {
		$this->verify_ajax();
 
		GFGS_Database::delete_feed( (int) ( $_POST['feed_id'] ?? 0 ) );
		wp_send_json_success();
	}

	/**
	 * AJAX: Duplicate an existing feed.
	 *
	 * The new feed is created as inactive to prevent accidental duplicate sends.
	 *
	 * @return void
	 */
	public function ajax_duplicate_feed(): void {
		$this->verify_ajax();
 
		$feed_id = (int) ( $_POST['feed_id'] ?? 0 );
 
		if ( ! $feed_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid feed ID.', GFGS ) ] );
		}
 
		$original = GFGS_Database::get_feed( $feed_id );
 
		if ( ! $original ) {
			wp_send_json_error( [ 'message' => __( 'Feed not found.', GFGS ) ] );
		}
 
		$data              = (array) $original;
		$data['feed_name'] = $data['feed_name'] . ' (Copy)';
		$data['is_active'] = 0;
		unset( $data['id'] );
 
		// save_feed() handles encoding array columns.
		$new_feed_id = GFGS_Database::save_feed( $data );
 
		if ( is_wp_error( $new_feed_id ) ) {
			wp_send_json_error( [ 'message' => $new_feed_id->get_error_message() ] );
		}
 
		$new_feed = GFGS_Database::get_feed( $new_feed_id );
 
		wp_send_json_success( [
			'feed'    => $this->prepare_feed_for_js( $new_feed ),
			'feed_id' => $new_feed_id,
		] );
	}

	/**
	 * AJAX: Toggle a feed's active / inactive state.
	 *
	 * @return void
	 */
	public function ajax_toggle_feed(): void {
		$this->verify_ajax();
 
		$feed_id = (int) ( $_POST['feed_id'] ?? 0 );
		$active  = (int) ( $_POST['active']  ?? 0 );
 
		GFGS_Database::toggle_feed( $feed_id, $active );
		wp_send_json_success();
	}

	// ── AJAX handlers: entry ──────────────────────────────────────────────────
 
	/**
	 * AJAX: Manually send an entry to one or all active feeds.
	 *
	 * Uses a per-entry nonce (gfgs_manual_send_{entry_id}) so the nonce is
	 * tied to the specific entry, not the global admin nonce.
	 *
	 * @return void
	 */
	public function ajax_manual_send(): void {
		$entry_id = (int) ( $_POST['entry_id'] ?? 0 );
 
		if ( ! check_ajax_referer( 'gfgs_manual_send_' . $entry_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', GFGS ) ] );
		}
 
		if ( ! current_user_can( 'gravityforms_view_entries' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', GFGS ) ] );
		}
 
		$feed_id_raw = sanitize_text_field( wp_unslash( $_POST['feed_id'] ?? 'all' ) );
		$feed_id     = ( 'all' === $feed_id_raw ) ? null : (int) $feed_id_raw;
 
		$result = GFGS_Feed_Processor::manual_send( $entry_id, $feed_id );
 
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}
}