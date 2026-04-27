<?php
/**
 * Template: Entry detail sidebar meta-box — "Send to Google Sheets".
 *
 * Available variables (set by GFGS_Addon::render_entry_meta_box()):
 *   @var array    $entry        GF entry array.
 *   @var object[] $feeds        All feeds for this form (active and inactive).
 *   @var object[] $active_feeds Active feeds only.
 *   @var string   $nonce        wp_create_nonce() value for 'gfgs_manual_send_{entry_id}'.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gfgs-entry-box">
	<p><?php esc_html_e( 'Manually send this entry to Google Sheets feeds.', GFGS ); ?></p>

	<?php if ( ! empty( $active_feeds ) ) : ?>
		<select id="gfgs-feed-select" style="width:100%;margin-bottom:8px;">
			<option value="all"><?php esc_html_e( 'All Active Feeds', GFGS ); ?></option>
			<?php foreach ( $active_feeds as $feed ) : ?>
				<option value="<?php echo (int) $feed->id; ?>">
					<?php echo esc_html( $feed->feed_name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	<?php endif; ?>

	<button
		type="button"
		class="button button-primary gfgs-manual-send-btn"
		data-entry-id="<?php echo (int) $entry['id']; ?>"
		data-nonce="<?php echo esc_attr( $nonce ); ?>"
		style="width:100%"
	>
		<?php esc_html_e( 'Send to Google Sheets', GFGS ); ?>
	</button>
	<span class="gfgs-send-status" style="display:none;margin-top:8px;"></span>
</div>
