<?php
/**
 * Template: Entry detail sidebar meta-box — "Send to Google Sheets".
 *
 * Available variables (set by GFGS_Addon::render_entry_meta_box()):
 *   @var array    $gfgs_entry        GF entry array.
 *   @var object[] $gfgs_feeds        All feeds for this form (active and inactive).
 *   @var object[] $gfgs_active_feeds Active feeds only.
 *   @var string   $nonce        wp_create_nonce() value for 'gfgs_manual_send_{entry_id}'.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gfgs-entry-box">
	<p><?php esc_html_e( 'Manually send this entry to Google Sheets feeds.', 'spreadsheet-sync-for-gravity-forms' ); ?></p>

	<?php if ( ! empty( $gfgs_active_feeds ) ) : ?>
		<select id="gfgs-feed-select" style="width:100%;margin-bottom:8px;">
			<option value="all"><?php esc_html_e( 'All Active Feeds', 'spreadsheet-sync-for-gravity-forms' ); ?></option>
			<?php foreach ( $gfgs_active_feeds as $gfgs_feed ) : ?>
				<option value="<?php echo (int) $gfgs_feed->id; ?>">
					<?php echo esc_html( $gfgs_feed->feed_name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	<?php endif; ?>

	<button
		type="button"
		class="button button-primary gfgs-manual-send-btn"
		data-entry-id="<?php echo (int) $gfgs_entry['id']; ?>"
		data-nonce="<?php echo esc_attr( $nonce ); ?>"
		style="width:100%"
	>
		<?php esc_html_e( 'Send to Google Sheets', 'spreadsheet-sync-for-gravity-forms' ); ?>
	</button>
	<span class="gfgs-send-status" style="display:none;margin-top:8px;"></span>
</div>
