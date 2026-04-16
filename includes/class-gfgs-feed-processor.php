<?php

if(!defined('ABSPATH')) exit;

/**
 * Listens to Gravtiy form hooks and dispatchs feed processing
 */
class GFGS_Feed_Processor{
    public function __construct()
    {
        // standard form submission
        add_action('gform_after_submission', [$this, 'on_form_submit'], 10, 2);
        
        // After all notificaiton/confirmaiton
        add_action('gform_after_send_email', [$this, 'on_after_send_email'], 10, 8); 

        //Payment events
        add_action('gform_post_payment_completed', [$this, 'on_payment_completed'], 10, 2);
        add_action('gform_post_payment_refunded', [$this, 'on_payment_refunded'], 10, 2);
        add_action('gform_post_fulfillment', [$this, 'on_fulfillment'], 10, 4);

        // Entry updates
        add_action('gform_after_update_entry', [$this, 'on_entry_updated'], 10, 3);
    }

    // ── Hook handlers ─────────────────────────────────────────────────────────
    public function on_form_submit($entry, $form){
        $this->process_form_feeds($entry, $form, 'form_submit');
    }

    public function on_after_send_email($is_success, $to, $from, $subject, $message, $headers, $sttachments, $entry){
        if(!$entry) return;

        $form = GFAPI::get_form($entry['form_id']);
        $this->process_form_feeds($entry, $form, 'submission_completed');
    }

    public function on_payment_completed( $entry, $action ) {
        $form = GFAPI::get_form( $entry['form_id'] );
        $this->process_form_feeds( $entry, $form, 'payment_completed' );
    }

    public function on_payment_refunded( $entry, $action ) {
        $form = GFAPI::get_form( $entry['form_id'] );
        $this->process_form_feeds( $entry, $form, 'payment_refunded' );
    }

    public function on_fulfillment( $entry, $feed, $transaction_id, $amount ) {
        $form = GFAPI::get_form( $entry['form_id'] );
        $this->process_form_feeds( $entry, $form, 'payment_fulfilled' );
    }

    public function on_entry_updated( $form, $entry_id, $original_entry ) {
        $entry = GFAPI::get_entry( $entry_id );
        $this->process_form_feeds( $entry, $form, 'entry_updated' );
    }

    // ── Core processing ───────────────────────────────────────────────────────
    
    /**
     * Find all active feeds for a form that match a send_event and process them.
     */
    public function process_form_feeds($entry, $form, string $event){
        $feeds = GFGS_Database::get_feeds_by_form($form['id']);
        foreach($feeds as $feed){
            if(!$feed->is_active) continue;

            if($feed->send_event !== $event) continue;
            $this->process_single_feed($feed, $entry, $form);
        }
    }

    /**
     * Process a single feed: check conditions, build row, send to Sheets.
     */
    public function process_single_feed( $feed, $entry, $form ) {
        $conditions = is_array( $feed->conditions ) ? $feed->conditions : [];
 
        // Check conditional logic
        if ( ! GFGS_Field_Mapper::check_conditions( $conditions, $entry, $form ) ) {
            $this->log( "Feed #{$feed->id} skipped (conditions not met) for entry #{$entry['id']}" );
            return;
        }
 
        $field_map   = is_array( $feed->field_map )   ? $feed->field_map   : [];
        $date_formats = is_array( $feed->date_formats ) ? $feed->date_formats : [];
 
        // Build row using the new schema key names
        $row = GFGS_Field_Mapper::build_row( $field_map, $entry, $form, $date_formats );
 
        if ( empty( $row ) ) {
            $this->log( "Feed #{$feed->id}: no mapped fields, skipping entry #{$entry['id']}" );
            return;
        }
 
        $api    = new GFGS_Google_API();
        $result = $api->append_row(
            $feed->account_id,
            $feed->spreadsheet_id,
            $feed->sheet_name,
            $row
        );
 
        if ( is_wp_error( $result ) ) {
            $this->log( "Feed #{$feed->id} error: " . $result->get_error_message(), 'error' );
            GFFormsModel::add_note(
                $entry['id'], 0, 'GF Google Sheets',
                sprintf( __( 'Error sending to Google Sheets (Feed: %s): %s', 'GFGS' ), $feed->feed_name, $result->get_error_message() ),
                'error'
            );
        } else {
            $this->log( "Feed #{$feed->id} sent row for entry #{$entry['id']}" );
            GFFormsModel::add_note(
                $entry['id'], 0, 'GF Google Sheets',
                sprintf( __( 'Entry sent to Google Sheets (Feed: %s)', 'GFGS' ), $feed->feed_name ),
                'success'
            );
        }
    }

    // ── Manual send (triggered from entry detail) ─────────────────────────────
    /**
     * Manually send an entry to all feeds, or a specific feed.
     *
     * @param int      $entry_id
     * @param int|null $feed_id  null = all active feeds; int = specific feed only
     */
    public static function manual_send( $entry_id, $feed_id = null ) {
        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) ) return $entry;
 
        $form  = GFAPI::get_form( $entry['form_id'] );
        $feeds = GFGS_Database::get_feeds_by_form( $form['id'] );
 
        $sent   = 0;
        $errors = [];
 
        foreach ( $feeds as $feed ) {
            // If a specific feed_id was requested, skip all others
            if ( $feed_id !== null && (int) $feed->id !== (int) $feed_id ) continue;
            if ( ! $feed->is_active ) continue;
 
            $processor = new self();
            try {
                $processor->process_single_feed( $feed, $entry, $form );
                $sent++;
            } catch ( \Exception $e ) {
                $errors[] = $e->getMessage();
            }
        }
 
        if ( $sent === 0 && $feed_id !== null ) {
            return new WP_Error( 'no_feed', 'Feed not found or is inactive.' );
        }
 
        return [ 'sent' => $sent, 'errors' => $errors ];
    }

    private function log( string $message, string $level = 'notice' ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[GF Google Sheets] ' . $message );
        }
    }
}