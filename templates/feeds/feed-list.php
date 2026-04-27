<?php
/**
 * Template: Feed List page — root mount point for the JS feed editor SPA.
 *
 * The entire UI (feed table + editor) is rendered client-side by feed-list.js.
 * This file only provides the mount element and the localised gfgsData object
 * already injected by GFGS_Addon::feed_list_page() via wp_localize_script().
 *
 * Available variables (set by GFGS_Addon::feed_list_page()):
 *   @var int      $form_id  GF form ID.
 *   @var object[] $feeds    Decoded feed objects for this form.
 *   @var object[] $accounts Connected Google accounts.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="gfgs-app"></div>
