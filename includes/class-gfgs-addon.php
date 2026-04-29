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

/**
 * Class GFGS_Addon
 *
 * Main add-on class that extends GFFeedAddOn to provide Google Sheets integration.
 */
class GFGS_Addon extends GFFeedAddOn {

	// ── GFFeedAddOn required properties ───────────────────────────────────────

	/**
	 * Add-on version number.
	 *
	 * @var string
	 */
	protected $_version = GFGS_VERSION;

	/**
	 * Minimum Gravity Forms version required.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.6';

	/**
	 * Add-on slug (matches plugin text-domain).
	 *
	 * @var string
	 */
	protected $_slug = 'gf-google-sheets';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	protected $_full_path = GFGS_PLUGIN_FILE;

	/**
	 * Plugin basename (used by GF for menu links).
	 *
	 * @var string
	 */
	protected $_path = GFGS_PLUGIN_BASENAME;

	/**
	 * Long title displayed in GF settings.
	 *
	 * @var string
	 */
	protected $_title = 'Google Sheets';

	/**
	 * Short title used in GF navigation.
	 *
	 * @var string
	 */
	protected $_short_title = 'Google Sheets';

	/**
	 * Feed ordering is not supported by this add-on.
	 *
	 * @var bool
	 */
	protected $_supports_feed_ordering = false;

	/**
	 * This add-on provides feeds.
	 *
	 * @var bool
	 */
	protected $_supports_feed = true;

	/**
	 * Singleton instance.
	 *
	 * @var GFGS_Addon|null
	 */
	private static $instance = null;

	/**
	 * Google API client, initialised in init().
	 *
	 * @var GFGS_Google_API
	 */
	private GFGS_Google_API $api;

	// ── Singleton ─────────────────────────────────────────────────────────────

	/**
	 * Retrieve or create the singleton instance.
	 *
	 * @return GFGS_Addon
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return a dashicon slug for the plugin's GF menu.
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return 'dashicons-media-document';
	}

	/**
	 * Enqueue scripts and data for the entry detail page.
	 *
	 * Nonce verification is intentionally omitted — this is a page load hook
	 * (admin_enqueue_scripts), not an AJAX request. The capability check below
	 * is the appropriate security control for this context.
	 *
	 * @param string $hook Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_entry_detail_assets( string $hook ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gravityforms_view_entries' ) ) {
			return;
		}

		// Nonce not applicable — read-only routing params on a page load.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';

		if ( isset( $_GET['lid'] ) ) {
			$entry_id = absint( $_GET['lid'] );
		} elseif ( isset( $_GET['entry_id'] ) ) {
			$entry_id = absint( $_GET['entry_id'] );
		} else {
			$entry_id = 0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'gf_entries' !== $page ) {
			return;
		}

		if ( 'entry' !== $view && ! $entry_id ) {
			return;
		}

		wp_register_script(
			'gfgs-common',
			GFGS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GFGS_VERSION,
			true
		);

		wp_enqueue_script( 'gfgs-common' );

		wp_localize_script(
			'gfgs-common',
			'gfgsEntryData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => $entry_id ? wp_create_nonce( 'gfgs_manual_send_' . $entry_id ) : '',
			)
		);
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
	public function init() {
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
		// No nonce check here — this is a browser redirect from Google, not an
		// AJAX request. handle_oauth_callback() performs its own capability check.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gfgs_oauth'] ) && 'callback' === $_GET['gfgs_oauth'] ) {
			add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		}

		// Entry detail meta-box.
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_entry_meta_box' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_entry_detail_assets' ) );
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
	private function register_ajax_actions() {
		$actions = array(
			'gfgs_get_sheets'         => 'ajax_get_sheets',
			'gfgs_get_spreadsheets'   => 'ajax_get_spreadsheets',
			'gfgs_get_sheet_headers'  => 'ajax_get_sheet_headers',
			'gfgs_manual_send'        => 'ajax_manual_send',
			'gfgs_delete_account'     => 'ajax_delete_account',
			'gfgs_save_feed'          => 'ajax_save_feed',
			'gfgs_delete_feed'        => 'ajax_delete_feed',
			'gfgs_bulk_action'        => 'ajax_bulk_action',
			'gfgs_duplicate_feed'     => 'ajax_duplicate_feed',
			'gfgs_toggle_feed'        => 'ajax_toggle_feed',
			'gfgs_test_connection'    => 'ajax_test_connection',
			'gfgs_save_account_creds' => 'ajax_save_account_creds',
			// Back-compat alias — same handler as save_account_creds.
			'gfgs_connect_account'    => 'ajax_save_account_creds',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// ── Template rendering ────────────────────────────────────────────────────

	/**
	 * Load a PHP template file and expose $data keys as local variables.
	 *
	 * Templates live in /templates/ relative to the plugin root.
	 * Pass a path without the leading slash, e.g. 'settings/account-list'.
	 *
	 * @param string               $template Relative template path (without .php extension).
	 * @param array<string, mixed> $data     Variables to extract into template scope.
	 *
	 * @return void
	 */
	private function render_template( $template, $data = array() ) {
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
	 * Nonce verification is intentionally omitted — these are read-only GET
	 * parameters used only to control page rendering, not to perform actions.
	 *
	 * @return void
	 */
	public function plugin_settings_page() {
		GFGS_Assets::enqueue_admin_assets();

		$redirect_uri    = $this->build_redirect_uri();
		$accounts        = GFGS_Database::get_accounts();
		$add_account_url = admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' );

		// Nonce not applicable — read-only routing params on a page load.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view          = isset( $_GET['gfgs_view'] ) ? sanitize_text_field( wp_unslash( $_GET['gfgs_view'] ) ) : 'list';
		$pending_id    = isset( $_GET['gfgs_pending'] ) ? absint( $_GET['gfgs_pending'] ) : 0;
		$connected_msg = isset( $_GET['connected'] ) ? __( 'Google account connected successfully!', 'spreadsheet-sync-for-gravity-forms' ) : '';
		$error_msg     = isset( $_GET['gfgs_error'] ) ? urldecode( sanitize_text_field( wp_unslash( $_GET['gfgs_error'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'gfgs-admin',
			'gfgsSettings',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'gfgs_nonce' ),
				'redirectUri'   => $redirect_uri,
				'accounts'      => array_map( static function ( $a ) { return (array) $a; }, $accounts ),
				'addAccountUrl' => $add_account_url,
				'connectedMsg'  => $connected_msg,
				'errorMsg'      => $error_msg,
			)
		);

		if ( 'add_account' === $view ) {
			$pending_account = $pending_id ? GFGS_Database::get_account( $pending_id ) : null;
			$token_data      = $pending_account ? json_decode( $pending_account->access_token, true ) : array();
			$client_id       = isset( $token_data['client_id'] ) ? $token_data['client_id'] : '';
			$client_secret   = isset( $token_data['client_secret'] ) ? $token_data['client_secret'] : '';

			$this->render_template(
				'settings/add-account',
				array(
					'pending_id'      => $pending_id,
					'pending_account' => $pending_account,
					'client_id'       => $client_id,
					'client_secret'   => $client_secret,
					'redirect_uri'    => $redirect_uri,
					'add_account_url' => $add_account_url,
					'error_msg'       => $error_msg,
				)
			);
		} else {
			$this->render_template(
				'settings/account-list',
				array(
					'accounts'        => $accounts,
					'redirect_uri'    => $redirect_uri,
					'add_account_url' => $add_account_url,
					'connected_msg'   => $connected_msg,
					'error_msg'       => $error_msg,
				)
			);
		}
	}

	/**
	 * Return an empty array — the full settings UI is rendered by plugin_settings_page().
	 *
	 * Required override to prevent GF from rendering its default settings fields.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array();
	}

	/**
	 * Helper to check whether a setting value is non-empty.
	 *
	 * @param mixed $value The setting value to evaluate.
	 *
	 * @return bool
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
	 * The 'state' parameter passed by Google serves as the CSRF token for this
	 * flow — it contains the pending account ID stored before the redirect.
	 * Standard WP nonce verification does not apply to OAuth callbacks.
	 *
	 * @return void
	 */
	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'spreadsheet-sync-for-gravity-forms' ) );
		}

		// 'state' is Google's CSRF token for the OAuth flow, not a WP nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$pending_id = isset( $_GET['state'] ) ? absint( $_GET['state'] ) : 0;
		$has_code   = ! empty( $_GET['code'] );
		$code       = $has_code ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect_uri  = $this->build_redirect_uri();
		$settings_base = 'admin.php?page=gf_settings&subview=gf-google-sheets';

		if ( ! $has_code ) {
			$error = rawurlencode( __( 'OAuth failed: no code returned.', 'spreadsheet-sync-for-gravity-forms' ) );
			wp_safe_redirect( admin_url( "{$settings_base}&gfgs_view=add_account&gfgs_pending={$pending_id}&gfgs_error={$error}" ) );
			exit;
		}

		$pending = $pending_id ? GFGS_Database::get_account( $pending_id ) : null;

		if ( ! $pending ) {
			$error = rawurlencode( __( 'OAuth failed: pending account not found.', 'spreadsheet-sync-for-gravity-forms' ) );
			wp_safe_redirect( admin_url( "{$settings_base}&gfgs_error={$error}" ) );
			exit;
		}

		$creds         = json_decode( $pending->access_token, true );
		$client_id     = isset( $creds['client_id'] ) ? $creds['client_id'] : '';
		$client_secret = isset( $creds['client_secret'] ) ? $creds['client_secret'] : '';

		$tokens = $this->api->exchange_code( $code, $client_id, $client_secret, $redirect_uri );

		if ( is_wp_error( $tokens ) ) {
			$error = rawurlencode( $tokens->get_error_message() );
			wp_safe_redirect( admin_url( "{$settings_base}&gfgs_view=add_account&gfgs_pending={$pending_id}&gfgs_error={$error}" ) );
			exit;
		}

		$user_info = $this->api->get_user_info( $tokens['access_token'] );

		if ( is_wp_error( $user_info ) ) {
			$email = $pending->email ? $pending->email : 'unknown@example.com';
		} else {
			$email = isset( $user_info['email'] ) ? $user_info['email'] : 'unknown';
		}

		GFGS_Database::save_account(
			array(
				'id'            => $pending_id,
				'account_name'  => $pending->account_name ? $pending->account_name : $email,
				'email'         => $email,
				'access_token'  => wp_json_encode(
					array(
						'access_token'  => $tokens['access_token'],
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
					)
				),
				'refresh_token' => isset( $tokens['refresh_token'] ) ? $tokens['refresh_token'] : '',
				'token_expires' => time() + absint( isset( $tokens['expires_in'] ) ? $tokens['expires_in'] : 3600 ),
			)
		);

		wp_safe_redirect( admin_url( "{$settings_base}&connected=1" ) );
		exit;
	}

	// ── Feed list page ────────────────────────────────────────────────────────

	/**
	 * Allow feeds to be created for any form.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return true;
	}

	/**
	 * Define the columns shown in the default GF feed list table.
	 *
	 * @return array<string, string>  column_key => label
	 */
	public function feed_list_columns() {
		return array(
			'feed_name'  => esc_html__( 'Feed Name', 'spreadsheet-sync-for-gravity-forms' ),
			'sheet_info' => esc_html__( 'Spreadsheet / Sheet', 'spreadsheet-sync-for-gravity-forms' ),
			'send_event' => esc_html__( 'Send On', 'spreadsheet-sync-for-gravity-forms' ),
		);
	}

	/**
	 * Render the custom feed list / editor page for a form.
	 *
	 * Nonce verification is intentionally omitted — $_GET['id'] is a read-only
	 * routing parameter used to identify the form, not to perform an action.
	 *
	 * @param array|int|null $form GF form array, form ID, or null (falls back to $_GET['id']).
	 *
	 * @return void
	 */
	public function feed_list_page( $form = null ) {
		$form_id = is_array( $form ) ? absint( $form['id'] ) : absint( $form );

		if ( ! $form_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		}

		if ( ! $form_id ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Could not determine form ID.', 'spreadsheet-sync-for-gravity-forms' )
			);
			return;
		}

		GFGS_Assets::enqueue_admin_assets();

		$form_data = GFAPI::get_form( $form_id );
		$feeds     = GFGS_Database::get_feeds_by_form( $form_id );
		$accounts  = GFGS_Database::get_accounts();

		wp_localize_script(
			'gfgs-feed',
			'gfgsData',
			array(
				'formId'        => $form_id,
				'feeds'         => array_map( array( $this, 'prepare_feed_for_js' ), $feeds ),
				'accounts'      => array_map( static function ( $a ) { return (array) $a; }, $accounts ),
				'fields'        => $this->build_field_list( $form_data ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'gfgs_nonce' ),
				'addAccountUrl' => admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_view=add_account' ),
				'feedEvents'    => $this->get_feed_events(),
				'i18n'          => array(
					'feedList'   => __( 'Google Sheets Feeds', 'spreadsheet-sync-for-gravity-forms' ),
					'addFeed'    => __( 'Add New Feed', 'spreadsheet-sync-for-gravity-forms' ),
					'noFeed'     => __( 'No feeds yet.', 'spreadsheet-sync-for-gravity-forms' ),
					'confirmDel' => __( 'Delete this feed?', 'spreadsheet-sync-for-gravity-forms' ),
				),
			)
		);

		$this->render_template(
			'feeds/feed-list',
			array(
				'form_id'  => $form_id,
				'feeds'    => $feeds,
				'accounts' => $accounts,
			)
		);
	}

	// ── Entry meta-box ────────────────────────────────────────────────────────

	/**
	 * Register a sidebar meta-box on the entry detail page when feeds exist.
	 *
	 * @param array $meta_boxes Registered meta boxes.
	 * @param array $entry      GF entry array.
	 * @param array $form       GF form array.
	 *
	 * @return array Modified meta boxes.
	 */
	public function add_entry_meta_box( $meta_boxes, $entry, $form ) {
		$feeds = GFGS_Database::get_feeds_by_form( absint( $form['id'] ) );

		if ( empty( $feeds ) ) {
			return $meta_boxes;
		}

		$meta_boxes['gfgs_manual_send'] = array(
			'title'    => esc_html__( 'Google Sheets', 'spreadsheet-sync-for-gravity-forms' ),
			'callback' => array( $this, 'render_entry_meta_box' ),
			'context'  => 'side',
		);

		return $meta_boxes;
	}

	/**
	 * Render the "Send to Google Sheets" meta-box on the entry detail page.
	 *
	 * @param array $args {
	 *     Context data passed by GF.
	 *
	 *     @type array $entry GF entry array.
	 *     @type array $form  GF form array.
	 * }
	 *
	 * @return void
	 */
	public function render_entry_meta_box( $args ) {
		$entry = $args['entry'];
		$feeds = GFGS_Database::get_feeds_by_form( absint( $entry['form_id'] ) );

		$active_feeds = array_filter(
			$feeds,
			static function ( $f ) {
				return (bool) $f->is_active;
			}
		);

		$this->render_template(
			'entry/meta-box',
			array(
				'entry'        => $entry,
				'feeds'        => $feeds,
				'active_feeds' => $active_feeds,
				'nonce'        => wp_create_nonce( 'gfgs_manual_send_' . $entry['id'] ),
			)
		);
	}

	// ── Helper: field list for JS ─────────────────────────────────────────────

	/**
	 * Build a flat list of form fields suitable for the JS feed editor.
	 *
	 * Composite fields (name, address, etc.) are expanded into their sub-inputs.
	 * Choice-based fields include their available choices for label mapping.
	 *
	 * @param array $form_data GF form array.
	 *
	 * @return array[] Array of field descriptor arrays {id, label, type, choices}.
	 */
	public function build_field_list( $form_data ) {
		$fields = array();

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
					$fields[] = array(
						'id'      => (string) $input['id'],
						'label'   => wp_strip_all_tags( $field->label . ' (' . $input['label'] . ')' ),
						'type'    => $field->type,
						'choices' => array(),
					);
				}
				continue;
			}

			// Standard fields: include choices if present.
			$choices = array();

			if ( ! empty( $field->choices ) && is_array( $field->choices ) ) {
				foreach ( $field->choices as $choice ) {
					$choices[] = array(
						'value' => isset( $choice['value'] ) ? $choice['value'] : '',
						'text'  => isset( $choice['text'] ) ? $choice['text'] : ( isset( $choice['value'] ) ? $choice['value'] : '' ),
					);
				}
			}

			$fields[] = array(
				'id'      => (string) $field->id,
				'label'   => wp_strip_all_tags( isset( $field->label ) ? $field->label : 'Field ' . $field->id ),
				'type'    => $field->type,
				'choices' => $choices,
			);
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
	private function get_feed_events() {
		return array(
			'form_submit'          => __( 'Form Submission', 'spreadsheet-sync-for-gravity-forms' ),
			'payment_completed'    => __( 'Payment Completed', 'spreadsheet-sync-for-gravity-forms' ),
			'payment_refunded'     => __( 'Payment Refunded', 'spreadsheet-sync-for-gravity-forms' ),
			'submission_completed' => __( 'After All Notifications Sent', 'spreadsheet-sync-for-gravity-forms' ),
			'entry_updated'        => __( 'Entry Updated', 'spreadsheet-sync-for-gravity-forms' ),
		);
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
	 * @param object $feed Decoded feed row from GFGS_Database.
	 *
	 * @return array<string, mixed>
	 */
	public function prepare_feed_for_js( $feed ) {
		return (array) $feed;
	}

	// ── Helper: redirect URI ──────────────────────────────────────────────────

	/**
	 * Build the standard OAuth redirect URI for this plugin's settings page.
	 *
	 * @return string Absolute admin URL.
	 */
	private function build_redirect_uri() {
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
	private function verify_ajax() {
		if ( ! check_ajax_referer( 'gfgs_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gravityforms_edit_forms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'spreadsheet-sync-for-gravity-forms' ) ) );
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
	public function ajax_save_account_creds() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$account_name  = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		$existing_id   = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $client_id || ! $client_secret ) {
			wp_send_json_error( array( 'message' => __( 'Client ID and Client Secret are required.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		$pending_id = GFGS_Database::save_account(
			array(
				'id'            => $existing_id ? $existing_id : null,
				'account_name'  => $account_name ? $account_name : __( 'My Google Account', 'spreadsheet-sync-for-gravity-forms' ),
				'email'         => '',
				'access_token'  => wp_json_encode(
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
					)
				),
				'refresh_token' => '',
				'token_expires' => 0,
			)
		);

		$auth_url = GFGS_Google_API::build_auth_url( $client_id, $this->build_redirect_uri(), $pending_id );

		wp_send_json_success(
			array(
				'pending_id' => $pending_id,
				'auth_url'   => $auth_url,
			)
		);
	}

	/**
	 * AJAX: Test that the stored credentials can successfully obtain a valid token.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		$account_id    = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $client_id || ! $client_secret ) {
			wp_send_json_error( array( 'message' => __( 'Client ID and Client Secret are required.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		if ( $account_id ) {
			$account = GFGS_Database::get_account( $account_id );

			if ( $account && $account->refresh_token ) {
				$result = $this->api->refresh_token( $account->refresh_token, $client_id, $client_secret );

				if ( ! is_wp_error( $result ) && ! empty( $result['access_token'] ) ) {
					wp_send_json_success( array( 'message' => __( 'Connection successful! Your credentials are valid.', 'spreadsheet-sync-for-gravity-forms' ) ) );
				}
			}
		}

		wp_send_json_error( array( 'message' => __( 'Could not verify connection. Please complete the Google authorization first.', 'spreadsheet-sync-for-gravity-forms' ) ) );
	}

	/**
	 * AJAX: Delete a Google account record.
	 *
	 * @return void
	 */
	public function ajax_delete_account() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$account_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;

		GFGS_Database::delete_account( $account_id );

		wp_send_json_success();
	}

	// ── AJAX handlers: spreadsheet/sheet discovery ────────────────────────────

	/**
	 * AJAX: Return the list of spreadsheets accessible by an account.
	 *
	 * @return void
	 */
	public function ajax_get_spreadsheets() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$account_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
		$result     = $this->api->list_spreadsheets( $account_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * AJAX: Return the sheet (tab) list for a spreadsheet.
	 *
	 * @return void
	 */
	public function ajax_get_sheets() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$account_id     = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
		$spreadsheet_id = isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->api->get_spreadsheet_sheets( $account_id, $spreadsheet_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * AJAX: Return the header row of a specific sheet.
	 *
	 * @return void
	 */
	public function ajax_get_sheet_headers() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$account_id     = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
		$spreadsheet_id = isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';
		$sheet_name     = isset( $_POST['sheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->api->get_sheet_headers( $account_id, $spreadsheet_id, $sheet_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}

	// ── AJAX handlers: feeds ──────────────────────────────────────────────────

	/**
	 * AJAX: Save (create or update) a feed.
	 *
	 * JSON fields (field_map, date_formats, conditions) are unslashed and decoded
	 * directly. sanitize_textarea_field is NOT used before json_decode because it
	 * strips characters (e.g. < > ") that are valid inside JSON strings and would
	 * silently corrupt the payload. Sanitization of individual values happens
	 * inside GFGS_Database::save_feed() before writing to the database.
	 *
	 * @return void
	 */
	public function ajax_save_feed() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// JSON fields: unslash only — sanitize_textarea_field strips valid JSON chars (<, >, ").
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$field_map_raw    = isset( $_POST['field_map'] ) ? wp_unslash( $_POST['field_map'] ) : '[]';
		$date_formats_raw = isset( $_POST['date_formats'] ) ? wp_unslash( $_POST['date_formats'] ) : '{}';
		$conditions_raw   = isset( $_POST['conditions'] ) ? wp_unslash( $_POST['conditions'] ) : '{}';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$field_map    = json_decode( $field_map_raw, true );
		$date_formats = json_decode( $date_formats_raw, true );
		$conditions   = json_decode( $conditions_raw, true );

		$data = array(
			'id'             => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
			'form_id'        => isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0,
			'feed_name'      => isset( $_POST['feed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_name'] ) ) : '',
			'account_id'     => isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0,
			'spreadsheet_id' => isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '',
			'sheet_id'       => isset( $_POST['sheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_id'] ) ) : '',
			'sheet_name'     => isset( $_POST['sheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_name'] ) ) : '',
			'field_map'      => is_array( $field_map ) ? $field_map : array(),
			'date_formats'   => is_array( $date_formats ) ? $date_formats : array(),
			'conditions'     => is_array( $conditions ) ? $conditions : array(),
			'send_event'     => isset( $_POST['send_event'] ) ? sanitize_text_field( wp_unslash( $_POST['send_event'] ) ) : 'form_submit',
			'is_active'      => isset( $_POST['is_active'] ) ? absint( $_POST['is_active'] ) : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $data['form_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		// save_feed() encodes array columns to JSON automatically.
		$feed_id = GFGS_Database::save_feed( $data );

		if ( is_wp_error( $feed_id ) ) {
			wp_send_json_error( array( 'message' => $feed_id->get_error_message() ) );
		}

		wp_send_json_success( array( 'feed_id' => $feed_id ) );
	}

	/**
	 * AJAX: Delete a feed record.
	 *
	 * @return void
	 */
	public function ajax_delete_feed() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$feed_id = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		GFGS_Database::delete_feed( $feed_id );

		wp_send_json_success();
	}

	/**
	 * AJAX: Handle bulk actions from the dropdown
	 * 
	 * @return void
	 */
	public function ajax_bulk_action(){
		$this->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		$feed_ids = isset( $_POST['feed_ids'] ) ? array_map( 'absint', (array) $_POST['feed_ids'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch($action){
			case 'delete_selected_feeds':
				if(empty($feed_ids)){
					wp_send_json_error(array('message' => 'No feeds selected'));
				}

				GFGS_Database::deleted_selected_feeds($feed_ids);
				wp_send_json_success( array( 'message' => 'Selected feeds deleted.' ) );
            	break;
			
		case 'delete_all_feeds':
            if ( ! $form_id ) {
                wp_send_json_error( array( 'message' => 'Invalid Form ID.' ) );
            }
            // Logic to delete everything for this form
            GFGS_Database::delete_all_feeds( $form_id );
            wp_send_json_success( array( 'message' => 'All feeds deleted.' ) );
            break;

        default:
            wp_send_json_error( array( 'message' => 'Unknown action.' ) );
            break;
		}
	}

	/**
	 * AJAX: Duplicate an existing feed.
	 *
	 * The new feed is created as inactive to prevent accidental duplicate sends.
	 *
	 * @return void
	 */
	public function ajax_duplicate_feed() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$feed_id = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $feed_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid feed ID.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		$original = GFGS_Database::get_feed( $feed_id );

		if ( ! $original ) {
			wp_send_json_error( array( 'message' => __( 'Feed not found.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		$data              = (array) $original;
		$data['feed_name'] = $data['feed_name'] . ' (Copy)';
		$data['is_active'] = 0;
		unset( $data['id'] );

		// save_feed() handles encoding array columns.
		$new_feed_id = GFGS_Database::save_feed( $data );

		if ( is_wp_error( $new_feed_id ) ) {
			wp_send_json_error( array( 'message' => $new_feed_id->get_error_message() ) );
		}

		$new_feed = GFGS_Database::get_feed( $new_feed_id );

		wp_send_json_success(
			array(
				'feed'    => $this->prepare_feed_for_js( $new_feed ),
				'feed_id' => $new_feed_id,
			)
		);
	}

	/**
	 * AJAX: Toggle a feed's active / inactive state.
	 *
	 * @return void
	 */
	public function ajax_toggle_feed() {
		$this->verify_ajax();

		// Nonce verified above via verify_ajax() / check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$feed_id = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		$active  = isset( $_POST['active'] ) ? absint( $_POST['active'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		GFGS_Database::toggle_feed( $feed_id, $active );
		wp_send_json_success();
	}

	// ── AJAX handlers: entry ──────────────────────────────────────────────────

	/**
	 * AJAX: Manually send an entry to one or all active feeds.
	 *
	 * Uses a per-entry nonce (gfgs_manual_send_{entry_id}) so the nonce is
	 * tied to the specific entry, not the global admin nonce. entry_id must be
	 * read before check_ajax_referer() because it forms part of the nonce action
	 * string — this is intentional and the value is immediately cast via absint().
	 *
	 * @return void
	 */
	public function ajax_manual_send() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- entry_id needed to build the nonce action string.
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( ! check_ajax_referer( 'gfgs_manual_send_' . $entry_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		if ( ! current_user_can( 'gravityforms_view_entries' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'spreadsheet-sync-for-gravity-forms' ) ) );
		}

		// Nonce verified above via check_ajax_referer().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$feed_id_raw = isset( $_POST['feed_id'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_id'] ) ) : 'all';
		$feed_id     = ( 'all' === $feed_id_raw ) ? null : absint( $feed_id_raw );

		$result = GFGS_Feed_Processor::manual_send( $entry_id, $feed_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}
}