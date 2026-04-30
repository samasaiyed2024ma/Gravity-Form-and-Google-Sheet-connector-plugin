<?php
/**
 * Asset manager for the GFGS plugin.
 *
 * Registers all CSS and JS files with WordPress once (on admin_enqueue_scripts)
 * so individual pages only need to call wp_enqueue_style() / wp_enqueue_script()
 * with the handle — no need to repeat URL + version + dependency logic.
 *
 * To add a new asset in the future:
 *   1. Register it in register_assets().
 *   2. Enqueue it where needed using the handle (e.g. 'gfgs-my-new-script').
 *
 * @package GFGS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Assets {

	/**
	 * CSS handles and their source files, registered by this manager.
	 *
	 * @since 1.0.0
	 * @var   array<string, string>  handle => filename (relative to assets/css/)
	 */
	private const STYLES = [
		'gfgs-variable'    => 'variable.css',
		'gfgs-admin'       => 'admin.css',
		'gfgs-components'  => 'components.css',
		'gfgs-feed-list'   => 'feed-list.css',
		'gfgs-feed-editor' => 'feed-editor.css',
	];

	/**
	 * JS handles, their source files, and their dependencies.
	 *
	 * Order matters: dependencies must be registered before dependents, but
	 * wp_register_script() handles resolution automatically based on the $deps array.
	 *
	 * @since 1.0.0
	 * @var   array<string, array{file: string, deps: string[]}>
	 */
	private const SCRIPTS = [
		'gfgs-common' => [
			'file' => 'admin.js',
			'deps' => [ 'jquery' ],
		],
		'gfgs-admin'  => [
			'file' => 'settings.js',
			'deps' => [ 'jquery', 'gfgs-common' ],
		],
		'gfgs-feed'   => [
			'file' => 'feed-list.js',
			'deps' => [ 'jquery', 'gfgs-common' ],
		],
	];

	/**
	 * CSS handles enqueued on every GFGS admin page (settings + feed editor).
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	private const COMMON_STYLES = [
		'gfgs-variable',
		'gfgs-components',
		'gfgs-feed-list',
		'gfgs-feed-editor',
		'gfgs-admin',
	];

	/**
	 * JS handles enqueued on every GFGS admin page.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	private const COMMON_SCRIPTS = [
		'gfgs-common',
		'gfgs-admin',
		'gfgs-feed',
	];

	/**
	 * Register the manager's hooks.
	 *
	 * Call this method once during plugin initialisation.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	/**
	 * Register (but do not enqueue) all plugin CSS and JS files.
	 *
	 * Fires on `admin_enqueue_scripts`. Individual pages enqueue specific
	 * handles as required.
	 *
	 * @return void
	 */
	public function register_assets() {
		foreach ( self::STYLES as $handle => $file ) {
			wp_register_style(
				$handle,
				GFGS_PLUGIN_URL . 'assets/css/' . $file,
				[],
				GFGS_VERSION
			);
		}

		foreach ( self::SCRIPTS as $handle => $config ) {
			wp_register_script(
				$handle,
				GFGS_PLUGIN_URL . 'assets/js/' . $config['file'],
				$config['deps'],
				GFGS_VERSION,
				true // Load in footer.
			);
		}
	}

	/**
	 * Enqueue all styles and scripts shared by every GFGS admin page.
	 *
	 * Call this at the top of plugin_settings_page() and feed_list_page().
	 *
	 * @return void
	 */
	public static function enqueue_admin_assets() {
		foreach ( self::COMMON_STYLES as $handle ) {
			wp_enqueue_style( $handle );
		}

		foreach ( self::COMMON_SCRIPTS as $handle ) {
			wp_enqueue_script( $handle );
		}
	}
}