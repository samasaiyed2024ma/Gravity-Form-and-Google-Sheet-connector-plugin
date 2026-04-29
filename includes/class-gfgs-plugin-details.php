<?php
/**
 * Plugin Details modal for the GFGS plugin.
 *
 * Renders the "View Details" thickbox modal on the plugins list page —
 * showing description, installation steps, FAQ, and changelog tabs.
 *
 * This class has no dependency on GF or any other GFGS class and can be
 * instantiated directly from the main plugin bootstrap.
 *
 * To add a new modal tab in the future:
 *   1. Add the tab key + label to get_tabs().
 *   2. Add a private render_tab_{key}() method.
 *   3. Call it from render_modal() inside the gfgs-modal-body block.
 *
 * @package GFGS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Plugin_Details {

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'plugin_row_meta',       [ $this, 'add_plugin_meta_links' ], 10, 2 );
		add_action( 'admin_footer',          [ $this, 'render_modal' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// ── Plugin row meta ───────────────────────────────────────────────────────

	/**
	 * Add "View Details" and "Plugin Homepage" links to the plugin row.
	 *
	 * Fires on `plugin_row_meta`. Returns $links unchanged for other plugins.
	 *
	 * @param  string[] $links Existing meta links.
	 * @param  string   $file  Plugin basename of the current row.
	 * @return string[]        Modified meta links.
	 */
	public function add_plugin_meta_links( array $links, string $file ): array {
		if ( $file !== GFGS_PLUGIN_BASENAME ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="#TB_inline?width=880&height=600&inlineId=gfgs-plugin-details" class="thickbox" title="%s">%s</a>',
			esc_attr__( 'Connect Gravity Forms with Google Sheets', 'spreadsheet-sync-for-gravity-forms' ),
			esc_html__( 'View Details', 'spreadsheet-sync-for-gravity-forms' )
		);

		$links[] = sprintf(
			'<a href="https://mervanagency.io" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_html__( 'Visit Plugin Site', 'spreadsheet-sync-for-gravity-forms' )
		);

		return $links;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Enqueue the modal CSS and JS on the plugins list page only.
	 *
	 * @param  string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

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

	// ── Modal ─────────────────────────────────────────────────────────────────

	/**
	 * Output the hidden thickbox modal markup in the admin footer.
	 *
	 * Only rendered on the plugins screen to avoid unnecessary output elsewhere.
	 *
	 * @return void
	 */
	public function render_modal(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
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

	// ── Modal sections ────────────────────────────────────────────────────────

	/**
	 * Render the modal header (logo, title, version, author).
	 *
	 * @return void
	 */
	private function render_header(): void {
		?>
		<div class="gfgs-modal-header">
			<div class="gfgs-modal-title">
				<svg width="36" height="36" viewBox="0 0 24 24" fill="none" aria-hidden="true">
					<rect width="24" height="24" rx="6" fill="#0F9D58"/>
					<path d="M7 8h10M7 12h10M7 16h6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
				</svg>
				<div>
					<h2><?php esc_html_e( 'Connect Gravity Forms with Google Sheets', 'spreadsheet-sync-for-gravity-forms' ); ?></h2>
					<div class="gfgs-modal-meta">
						<span class="gfgs-modal-version">
							<?php
							/* translators: %s: version number */
							printf( esc_html__( 'Version %s', 'spreadsheet-sync-for-gravity-forms' ), esc_html( GFGS_VERSION ) );
							?>
						</span>
						<span class="gfgs-modal-author">
							<?php esc_html_e( 'By', 'spreadsheet-sync-for-gravity-forms' ); ?>
							<a href="https://mervanagency.io" target="_blank" rel="noopener noreferrer">Mervan Agency</a>
						</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the tab navigation bar.
	 *
	 * @return void
	 */
	private function render_tabs(): void {
		?>
		<div class="gfgs-modal-tabs">
			<?php foreach ( $this->get_tabs() as $key => $label ) : ?>
				<button
					class="gfgs-tab-btn <?php echo 'description' === $key ? 'active' : ''; ?>"
					data-tab="<?php echo esc_attr( $key ); ?>"
					type="button"
				>
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the right-hand sidebar with plugin metadata and action buttons.
	 *
	 * @return void
	 */
	private function render_sidebar(): void {
		$meta_rows = [
			__( 'Version:', 'spreadsheet-sync-for-gravity-forms' )            => esc_html( GFGS_VERSION ),
			__( 'Author:', 'spreadsheet-sync-for-gravity-forms' )              => '<a href="https://mervanagency.io" target="_blank" rel="noopener noreferrer">Mervan Agency</a>',
			__( 'Last Updated:', 'spreadsheet-sync-for-gravity-forms' )        => esc_html__( 'April 2026', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Requires WordPress:', 'spreadsheet-sync-for-gravity-forms' )  => '5.8 ' . esc_html__( 'or higher', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Compatible up to:', 'spreadsheet-sync-for-gravity-forms' )    => '6.9',
			__( 'Requires PHP:', 'spreadsheet-sync-for-gravity-forms' )        => '7.4 ' . esc_html__( 'or higher', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Requires:', 'spreadsheet-sync-for-gravity-forms' )            => 'Gravity Forms 2.6+',
		];
		?>
		<div class="gfgs-modal-sidebar">
			<?php foreach ( $meta_rows as $label => $value ) : ?>
				<div class="gfgs-sidebar-row">
					<span class="gfgs-sidebar-label"><?php echo esc_html( $label ); ?></span>
					<span><?php echo wp_kses( $value, [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></span>
				</div>
			<?php endforeach; ?>

			<div class="gfgs-sidebar-block">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_settings&subview=gf-google-sheets' ) ); ?>"
				   class="button button-primary gfgs-sidebar-btn">
					<?php esc_html_e( 'Go to Settings', 'spreadsheet-sync-for-gravity-forms' ); ?>
				</a>
				<a href="https://mervanagency.io"
				   target="_blank"
				   rel="noopener noreferrer"
				   class="button gfgs-sidebar-btn">
					<?php esc_html_e( 'Plugin Homepage', 'spreadsheet-sync-for-gravity-forms' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	// ── Tab content ───────────────────────────────────────────────────────────

	/**
	 * Render the Description tab.
	 *
	 * @return void
	 */
	private function render_tab_description(): void {
		$features = [
			__( 'Connect multiple Google accounts', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Map any form field to any sheet column', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Multiple trigger events (submit, payment, entry update)', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Conditional logic support per feed', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Support for all Gravity Forms field types', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Manual send from entry detail page', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Multiple feeds per form', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Secure OAuth 2.0 authentication', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Entry notes for success and error logging', 'spreadsheet-sync-for-gravity-forms' ),
		];

		$field_types = [
			__( 'Text / Textarea', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Email / Phone / Number', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Select / Radio', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Checkbox / Multi-select', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Name / Address', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Date / Time', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'File Upload', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'List Fields', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Product Fields', 'spreadsheet-sync-for-gravity-forms' ),
			__( 'Entry Meta (ID, IP, URL)', 'spreadsheet-sync-for-gravity-forms' ),
		];
		?>
		<div class="gfgs-tab-content active" id="gfgs-tab-description">
			<p class="gfgs-tab-intro">
				<?php esc_html_e( 'Automatically send Gravity Forms submissions to Google Sheets. Map form fields to sheet columns, set conditions, and manage multiple feeds.', 'spreadsheet-sync-for-gravity-forms' ); ?>
			</p>

			<h4><?php esc_html_e( 'Key Features', 'spreadsheet-sync-for-gravity-forms' ); ?></h4>
			<ul class="gfgs-feature-list">
				<?php foreach ( $features as $feature ) : ?>
					<li>
						<span class="gfgs-feature-icon" aria-hidden="true">✅</span>
						<?php echo esc_html( $feature ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h4><?php esc_html_e( 'Supported Field Types', 'spreadsheet-sync-for-gravity-forms' ); ?></h4>
			<div class="gfgs-field-types-grid">
				<?php foreach ( $field_types as $type ) : ?>
					<span class="gfgs-field-type-badge"><?php echo esc_html( $type ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Installation tab.
	 *
	 * @return void
	 */
	private function render_tab_installation(): void {
		$steps = [
			[
				'title' => __( 'Upload & Activate', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Go to Plugins → Add New → Upload Plugin, select the zip file, and click Install Now. Once finished, click Activate.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Ensure Gravity Forms is Active', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'This plugin is an add-on. Make sure Gravity Forms (v2.6+) is installed and activated on your site.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Go to Forms → Settings → Google Sheets', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Navigate to the plugin settings page to get started.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Create a Google Cloud Project', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Go to Google Cloud Console, create a project, and enable the Google Sheets API and Google Drive API.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Configure OAuth Consent Screen', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Set user type to External and add your email as a test user.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Create OAuth 2.0 Credentials', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Create a Web Application OAuth client and add your redirect URI from the plugin settings page.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Connect Your Google Account', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Click Add New Account, enter your Client ID and Secret, then authorize with Google.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'title' => __( 'Create a Feed', 'spreadsheet-sync-for-gravity-forms' ),
				'desc'  => __( 'Open any Gravity Form, go to Settings → Google Sheets, and click Add New Feed to start mapping fields.', 'spreadsheet-sync-for-gravity-forms' ),
			],
		];
		?>
		<div class="gfgs-tab-content" id="gfgs-tab-installation">
			<ol class="gfgs-install-steps">
				<?php foreach ( $steps as $step ) : ?>
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

	/**
	 * Render the FAQ tab (accordion).
	 *
	 * @return void
	 */
	private function render_tab_faq(): void {
		$faqs = [
			[
				'q' => __( 'Does this plugin require Gravity Forms?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'Yes, Gravity Forms must be installed and activated. Version 2.6 or higher is required.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'q' => __( 'Can I connect multiple Google accounts?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'Yes, you can connect as many Google accounts as needed. Each feed can use a different account.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'q' => __( 'Can I send one form to multiple spreadsheets?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'Yes, you can create multiple feeds per form, each sending to a different spreadsheet or sheet tab.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'q' => __( 'What happens if the Google API call fails?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'The error is logged in the entry notes. You can manually resend from the entry detail page using the Send to Google Sheets button.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'q' => __( 'Does it support conditional logic?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'Yes, each feed supports conditional logic so you can control exactly when entries are sent to Google Sheets.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'q' => __( 'Is my data secure?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'Yes. OAuth tokens are stored securely in your WordPress database. Data is sent directly from your site to Google — nothing passes through our servers.', 'spreadsheet-sync-for-gravity-forms' ),
			],
			[
				'q' => __( 'Will it work on localhost?', 'spreadsheet-sync-for-gravity-forms' ),
				'a' => __( 'Google OAuth requires a publicly accessible URL. For local development, use a tunneling tool like ngrok or ddev share to get a public URL.', 'spreadsheet-sync-for-gravity-forms' ),
			],
		];
		?>
		<div class="gfgs-tab-content" id="gfgs-tab-faq">
			<?php foreach ( $faqs as $faq ) : ?>
				<div class="gfgs-faq-item">
					<button class="gfgs-faq-question" type="button">
						<?php echo esc_html( $faq['q'] ); ?>
						<span class="gfgs-faq-icon" aria-hidden="true">+</span>
					</button>
					<div class="gfgs-faq-answer">
						<p><?php echo esc_html( $faq['a'] ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the Changelog tab.
	 *
	 * @return void
	 */
	private function render_tab_changelog(): void {
		$changelog = [
			[
				'version' => '1.0.0',
				'date'    => '',
				'label'   => __( 'Initial Release', 'spreadsheet-sync-for-gravity-forms' ),
				'changes' => [
					__( 'Connect multiple Google accounts via OAuth 2.0', 'spreadsheet-sync-for-gravity-forms' ),
					__( 'Create feeds with field mapping', 'spreadsheet-sync-for-gravity-forms' ),
					__( 'Support for all Gravity Forms field types', 'spreadsheet-sync-for-gravity-forms' ),
					__( 'Conditional logic per feed', 'spreadsheet-sync-for-gravity-forms' ),
					__( 'Multiple trigger events', 'spreadsheet-sync-for-gravity-forms' ),
					__( 'Manual send from entry detail page', 'spreadsheet-sync-for-gravity-forms' ),
					__( 'Entry notes for success and error logging', 'spreadsheet-sync-for-gravity-forms' ),
				],
			],
		];
		?>
		<div class="gfgs-tab-content" id="gfgs-tab-changelog">
			<?php foreach ( $changelog as $release ) : ?>
				<div class="gfgs-changelog-version">
					<div class="gfgs-changelog-header">
						<span class="gfgs-version-badge"><?php echo esc_html( $release['version'] ); ?></span>
						<strong><?php echo esc_html( $release['label'] ); ?></strong>
						<?php if ( $release['date'] ) : ?>
							<span class="gfgs-changelog-date"><?php echo esc_html( $release['date'] ); ?></span>
						<?php endif; ?>
					</div>
					<ul>
						<?php foreach ( $release['changes'] as $change ) : ?>
							<li><?php echo esc_html( $change ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// ── Data helpers ──────────────────────────────────────────────────────────

	/**
	 * Return the tab key → label map used to build the nav and content panels.
	 *
	 * Add a new entry here when adding a new tab.
	 *
	 * @return array<string, string>
	 */
	private function get_tabs(): array {
		return [
			'description'  => __( 'Description', 'spreadsheet-sync-for-gravity-forms' ),
			'installation' => __( 'Installation', 'spreadsheet-sync-for-gravity-forms' ),
			'faq'          => __( 'FAQ', 'spreadsheet-sync-for-gravity-forms' ),
			'changelog'    => __( 'Changelog', 'spreadsheet-sync-for-gravity-forms' ),
		];
	}
}