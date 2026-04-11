<?php
/**
 * Plugin Name: Connect Gravity Forms with Google Sheets
 * Description: Connect Gravity Forms with Google Sheets. Map fields, create feeds, and automatically send form submissions to Google Sheets.
 * Version: 1.0
 * Author: Mervan Agency
 * Author URI: mervanagency.io
 * License: GPL-2.0+
 * Text Domain: gf-google-sheets
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GFGS_VERSION',         '1.0.0' );
define( 'GFGS_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'GFGS_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'GFGS_PLUGIN_FILE',     __FILE__ );
define( 'GFGS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GFGS',                 'gf-google-sheets' );

final class GF_Google_Sheets {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( GFGS_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( GFGS_PLUGIN_FILE, [ $this, 'deactivate' ] );
        add_action( 'gform_loaded', [ $this, 'load_addon' ], 5 );
    }

    public function load_addon() {
        if ( ! class_exists( 'GFForms' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>' .
                     esc_html__( 'GF Google Sheets requires Gravity Forms to be installed and active.', 'gf-google-sheets' ) .
                     '</p></div>';
            } );
            return;
        }

        // 1. Load the addon framework first
        GFForms::include_addon_framework();

        // 2. Now it's safe to load all classes that depend on GFAddOn
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-database.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-google-api.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-feed-processor.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-field-mapper.php';
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-addon.php';

        // 3. Register the addon
        GFAddOn::register( 'GFGS_Addon' );
    }

    public function activate() {
        // Database class needs to be loaded for activation
        require_once GFGS_PLUGIN_DIR . 'includes/class-gfgs-database.php';
        GFGS_Database::create_tables();
    }

    public function deactivate() {}
}

GF_Google_Sheets::instance();