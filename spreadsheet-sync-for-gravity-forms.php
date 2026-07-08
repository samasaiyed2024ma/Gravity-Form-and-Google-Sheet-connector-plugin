<?php
/**
 * Plugin Name: Connect Gravity Forms with Google Sheets
 * Description: Connect Gravity Forms with Google Sheets. Map fields, create feeds, and automatically send form submissions to Google Sheets.
 * Version: 1.0.0
 * Author: Mervan Agency
 * Text Domain: spreadsheet-sync-for-gravity-forms
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Requires PHP: 7.4
 */


// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'GFGS_VERSION',         '1.0.0' );
define( 'GFGS_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'GFGS_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'GFGS_PLUGIN_FILE',     __FILE__ );
define( 'GFGS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

final class GF_Google_Sheets {

	/** @var GF_Google_Sheets|null Singleton instance. */
	private static ?GF_Google_Sheets $instance = null;

    public static function instance(){
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
	 * Constructor — registers hooks only; no heavy work here.
	 */
    private function __construct() {
        register_activation_hook( GFGS_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( GFGS_PLUGIN_FILE, [ $this, 'deactivate' ] );

        // Priority 5 ensures we run before other add-ons
        add_action( 'gform_loaded', [ $this, 'load_addon' ], 5 );
        add_filter( 'gform_logging_extensions', [$this, 'gfgs_register_logging_extension'] );
    }

    /**
	 * Load all plugin classes and register the add-on once Gravity Forms is ready.
	 * Fires on the `gform_loaded` action.
     * 
	 * @return void
	 */
    public function load_addon() {
        if ( ! class_exists( 'GFForms' ) ) {
            add_action( 'admin_notices', static function () {
                	printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'GF Google Sheets requires Gravity Forms to be installed and active.', 'spreadsheet-sync-for-gravity-forms' )
				);
            } );
            return;
        }

        // Load GF addon frameworks before any class that extends them.
        GFForms::include_addon_framework();
        GFForms::include_feed_addon_framework();

        // Core infrastructure. 
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-logger.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-database.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-google-api.php';

        // Business logic.
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-feed-processor.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-field-mapper.php';

        // UI / admin layer.
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-assets.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-addon.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-plugin-details.php';

        // Register the feed add-on with Gravity Forms.
        GFAddOn::register( 'GFGS_Addon' );
    
        add_action( 'admin_footer', [ $this, 'deactivate_modal_html' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_deactivate_script' ] );
        add_action( 'wp_ajax_gfgs_set_remove_data_flag', [ $this, 'ajax_set_remove_data_flag' ] );

        // Stand-alone UI helpers (not a GF add-on, just hooks into WP admin).
        new GFGS_Plugin_Details();
    }

    /**
     * Register the logging extension with Gravity Forms.
     * This makes the plugin show up in Gravity Forms > Settings > Logging.
     * 
     * @param  array<string, string> $extensions Existing extensions keyed by slug.
     * @return array<string, string>
     */
    public function gfgs_register_logging_extension( $extensions ) {
        $extensions['spreadsheet-sync-for-gravity-forms'] = esc_html__( 'Google Sheets Connector', 'spreadsheet-sync-for-gravity-forms' );
        return $extensions;
    }

    /**
     * Enqueue the deactivation-dialog script on the Plugins screen only.
     *
     * @return void
     */
    public function enqueue_deactivate_script() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'plugins' ) {
            return;
        }

        wp_enqueue_script(
            'gfgs-deactivate',
            GFGS_PLUGIN_URL . 'assets/js/deactivate.js',
            [ 'jquery' ],
            GFGS_VERSION,
            true
        );

        wp_enqueue_style(
            'gfgs-deactivate',
            GFGS_PLUGIN_URL . 'assets/css/deactivate.css',
            [],
            GFGS_VERSION
        );

        wp_localize_script( 'gfgs-deactivate', 'gfgsDeactivate', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'gfgs_deactivate' ),
            'pluginFile'=> GFGS_PLUGIN_BASENAME,
        ] );
    }

    /**
     * Output the deactivation confirmation modal HTML into the admin footer.
     * Shown only on the Plugins screen.
     *
     * @return void
     */
    public function deactivate_modal_html() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'plugins' ) {
            return;
        }
        ?>
        <div id="gfgs-deactivate-modal" style="display:none;">
			<div id="gfgs-deactivate-overlay"></div>
			<div id="gfgs-deactivate-dialog">
				<h3><?php esc_html_e( 'Deactivate Google Sheets Connector', 'spreadsheet-sync-for-gravity-forms' ); ?></h3>
				<p><?php esc_html_e( 'Would you like to remove all feeds and database tables created by this plugin?', 'spreadsheet-sync-for-gravity-forms' ); ?></p>
				<p><strong><?php esc_html_e( 'This cannot be undone.', 'spreadsheet-sync-for-gravity-forms' ); ?></strong></p>
				<label>
					<input type="checkbox" id="gfgs-remove-data">
					<?php esc_html_e( 'Yes, delete all plugin data (feeds & tables)', 'spreadsheet-sync-for-gravity-forms' ); ?>
				</label>
				<div id="gfgs-deactivate-actions">
					<button id="gfgs-deactivate-cancel" class="button"><?php esc_html_e( 'Cancel', 'spreadsheet-sync-for-gravity-forms' ); ?></button>
					<button id="gfgs-deactivate-confirm" class="button button-primary"><?php esc_html_e( 'Deactivate', 'spreadsheet-sync-for-gravity-forms' ); ?></button>
				</div>
			</div>
		</div>
        <?php
    }

    /**
     * AJAX handler — stores the user's data-removal preference in a transient
     * so the deactivate() hook can read it immediately after.
     *
     * @return void
     */
    public function ajax_set_remove_data_flag() {
        check_ajax_referer( 'gfgs_deactivate', 'nonce' );

        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $remove = ! empty( $_POST['remove_data'] );
        if ( $remove ) {
            update_option( 'gfgs_remove_data_on_deactivate', 1, false );
        } else {
            delete_option( 'gfgs_remove_data_on_deactivate' );
        }

        wp_send_json_success();
    }

    /**
	 * Plugin activation callback — creates database tables.
     * 
	 * @return void
	 */
    public function activate() {
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-database.php';
        GFGS_Database::create_tables();
    }

	/**
	 * Plugin deactivation callback.
	 *
     * If the user opted to remove all data on deactivation,
     * drops the plugin's custom database tables and clears
     * any related options.
	 *
	 * @return void
	 */
    public function deactivate() {
        if(get_option('gfgs_remove_data_on_deactivate')){
            require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-database.php';
            GFGS_Database::drop_tables();
            delete_option('gfgs_remove_data_on_deactivate');
        }
    }
}

// Boot the plugin.
GF_Google_Sheets::instance();