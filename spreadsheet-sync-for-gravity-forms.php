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
	 * Currently a no-op. Add cleanup logic here
	 * (e.g. clearing scheduled events).
	 *
	 * @return void
	 */
    public function deactivate() {}
}

// Boot the plugin.
GF_Google_Sheets::instance();