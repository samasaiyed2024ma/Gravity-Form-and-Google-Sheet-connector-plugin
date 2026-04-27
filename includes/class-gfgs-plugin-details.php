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
			esc_attr__( 'Connect Gravity Forms with Google Sheets', GFGS ),
			esc_html__( 'View Details', GFGS )
		);

		$links[] = sprintf(
			'<a href="https://mervanagency.io" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_html__( 'Visit Plugin Site', GFGS )
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
					<h2><?php esc_html_e( 'Connect Gravity Forms with Google Sheets', GFGS ); ?></h2>
					<div class="gfgs-modal-meta">
						<span class="gfgs-modal-version">
							<?php
							/* translators: %s: version number */
							printf( esc_html__( 'Version %s', GFGS ), esc_html( GFGS_VERSION ) );
							?>
						</span>
						<span class="gfgs-modal-author">
							<?php esc_html_e( 'By', GFGS ); ?>
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
			__( 'Version:', GFGS )            => esc_html( GFGS_VERSION ),
			__( 'Author:', GFGS )              => '<a href="https://mervanagency.io" target="_blank" rel="noopener noreferrer">Mervan Agency</a>',
			__( 'Last Updated:', GFGS )        => esc_html__( 'April 2026', GFGS ),
			__( 'Requires WordPress:', GFGS )  => '5.8 ' . esc_html__( 'or higher', GFGS ),
			__( 'Compatible up to:', GFGS )    => '6.7',
			__( 'Requires PHP:', GFGS )        => '7.4 ' . esc_html__( 'or higher', GFGS ),
			__( 'Requires:', GFGS )            => 'Gravity Forms 2.6+',
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
					<?php esc_html_e( 'Go to Settings', GFGS ); ?>
				</a>
				<a href="https://mervanagency.io"
				   target="_blank"
				   rel="noopener noreferrer"
				   class="button gfgs-sidebar-btn">
					<?php esc_html_e( 'Plugin Homepage', GFGS ); ?>
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
			__( 'Connect multiple Google accounts', GFGS ),
			__( 'Map any form field to any sheet column', GFGS ),
			__( 'Multiple trigger events (submit, payment, entry update)', GFGS ),
			__( 'Conditional logic support per feed', GFGS ),
			__( 'Support for all Gravity Forms field types', GFGS ),
			__( 'Manual send from entry detail page', GFGS ),
			__( 'Multiple feeds per form', GFGS ),
			__( 'Secure OAuth 2.0 authentication', GFGS ),
			__( 'Entry notes for success and error logging', GFGS ),
		];

		$field_types = [
			__( 'Text / Textarea', GFGS ),
			__( 'Email / Phone / Number', GFGS ),
			__( 'Select / Radio', GFGS ),
			__( 'Checkbox / Multi-select', GFGS ),
			__( 'Name / Address', GFGS ),
			__( 'Date / Time', GFGS ),
			__( 'File Upload', GFGS ),
			__( 'List Fields', GFGS ),
			__( 'Product Fields', GFGS ),
			__( 'Entry Meta (ID, IP, URL)', GFGS ),
		];
		?>
		<div class="gfgs-tab-content active" id="gfgs-tab-description">
			<p class="gfgs-tab-intro">
				<?php esc_html_e( 'Automatically send Gravity Forms submissions to Google Sheets. Map form fields to sheet columns, set conditions, and manage multiple feeds.', GFGS ); ?>
			</p>

			<h4><?php esc_html_e( 'Key Features', GFGS ); ?></h4>
			<ul class="gfgs-feature-list">
				<?php foreach ( $features as $feature ) : ?>
					<li>
						<span class="gfgs-feature-icon" aria-hidden="true">✅</span>
						<?php echo esc_html( $feature ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h4><?php esc_html_e( 'Supported Field Types', GFGS ); ?></h4>
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
				'title' => __( 'Upload & Activate', GFGS ),
				'desc'  => __( 'Go to Plugins → Add New → Upload Plugin, select the zip file, and click Install Now. Once finished, click Activate.', GFGS ),
			],
			[
				'title' => __( 'Ensure Gravity Forms is Active', GFGS ),
				'desc'  => __( 'This plugin is an add-on. Make sure Gravity Forms (v2.6+) is installed and activated on your site.', GFGS ),
			],
			[
				'title' => __( 'Go to Forms → Settings → Google Sheets', GFGS ),
				'desc'  => __( 'Navigate to the plugin settings page to get started.', GFGS ),
			],
			[
				'title' => __( 'Create a Google Cloud Project', GFGS ),
				'desc'  => __( 'Go to Google Cloud Console, create a project, and enable the Google Sheets API and Google Drive API.', GFGS ),
			],
			[
				'title' => __( 'Configure OAuth Consent Screen', GFGS ),
				'desc'  => __( 'Set user type to External and add your email as a test user.', GFGS ),
			],
			[
				'title' => __( 'Create OAuth 2.0 Credentials', GFGS ),
				'desc'  => __( 'Create a Web Application OAuth client and add your redirect URI from the plugin settings page.', GFGS ),
			],
			[
				'title' => __( 'Connect Your Google Account', GFGS ),
				'desc'  => __( 'Click Add New Account, enter your Client ID and Secret, then authorize with Google.', GFGS ),
			],
			[
				'title' => __( 'Create a Feed', GFGS ),
				'desc'  => __( 'Open any Gravity Form, go to Settings → Google Sheets, and click Add New Feed to start mapping fields.', GFGS ),
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
				'q' => __( 'Does this plugin require Gravity Forms?', GFGS ),
				'a' => __( 'Yes, Gravity Forms must be installed and activated. Version 2.6 or higher is required.', GFGS ),
			],
			[
				'q' => __( 'Can I connect multiple Google accounts?', GFGS ),
				'a' => __( 'Yes, you can connect as many Google accounts as needed. Each feed can use a different account.', GFGS ),
			],
			[
				'q' => __( 'Can I send one form to multiple spreadsheets?', GFGS ),
				'a' => __( 'Yes, you can create multiple feeds per form, each sending to a different spreadsheet or sheet tab.', GFGS ),
			],
			[
				'q' => __( 'What happens if the Google API call fails?', GFGS ),
				'a' => __( 'The error is logged in the entry notes. You can manually resend from the entry detail page using the Send to Google Sheets button.', GFGS ),
			],
			[
				'q' => __( 'Does it support conditional logic?', GFGS ),
				'a' => __( 'Yes, each feed supports conditional logic so you can control exactly when entries are sent to Google Sheets.', GFGS ),
			],
			[
				'q' => __( 'Is my data secure?', GFGS ),
				'a' => __( 'Yes. OAuth tokens are stored securely in your WordPress database. Data is sent directly from your site to Google — nothing passes through our servers.', GFGS ),
			],
			[
				'q' => __( 'Will it work on localhost?', GFGS ),
				'a' => __( 'Google OAuth requires a publicly accessible URL. For local development, use a tunneling tool like ngrok or ddev share to get a public URL.', GFGS ),
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
				'label'   => __( 'Initial Release', GFGS ),
				'changes' => [
					__( 'Connect multiple Google accounts via OAuth 2.0', GFGS ),
					__( 'Create feeds with field mapping', GFGS ),
					__( 'Support for all Gravity Forms field types', GFGS ),
					__( 'Conditional logic per feed', GFGS ),
					__( 'Multiple trigger events', GFGS ),
					__( 'Manual send from entry detail page', GFGS ),
					__( 'Entry notes for success and error logging', GFGS ),
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
			'description'  => __( 'Description', GFGS ),
			'installation' => __( 'Installation', GFGS ),
			'faq'          => __( 'FAQ', GFGS ),
			'changelog'    => __( 'Changelog', GFGS ),
		];
	}
}