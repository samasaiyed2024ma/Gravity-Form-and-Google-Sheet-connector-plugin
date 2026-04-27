<?php
/**
 * Feed processor for the GFGS plugin.
 *
 * Listens to Gravity Forms hooks and dispatches feed processing whenever
 * a relevant form event occurs (submission, payment, entry update).
 *
 * Each event type maps to a `send_event` slug stored on the feed row.
 * The processor finds all active feeds whose send_event matches, evaluates
 * conditional logic, builds the data row, and appends it to Google Sheets.
 *
 * To support a new trigger event in the future:
 *   1. Add a new add_action() call in __construct() that points to a handler.
 *   2. Add the handler method (call process_form_feeds() with a unique event slug).
 *   3. Expose the slug in the feed editor JS (gfgsData.feedEvents).
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Feed_Processor{

    /** @var GFGS_Google_API Injected API client. */
	private GFGS_Google_API $api;

    /**
	 * Constructor — wire up Gravity Forms action hooks.
	 *
	 * Accepts an optional API client for testability (dependency injection).
	 * In production the default instance is used.
	 *
	 * @param  GFGS_Google_API|null $api Optional Google API client.
	 */
	public function __construct( ?GFGS_Google_API $api = null ) {
		$this->api = $api ?? new GFGS_Google_API();
 
		// Standard form submission.
		add_action( 'gform_after_submission',    [ $this, 'on_form_submit' ],    10, 2 );
 
		// After all notifications/confirmations have been sent.
		add_action( 'gform_after_send_email',    [ $this, 'on_after_send_email' ], 10, 8 );
 
		// Payment lifecycle events.
		add_action( 'gform_post_payment_completed', [ $this, 'on_payment_completed' ], 10, 2 );
		add_action( 'gform_post_payment_refunded',  [ $this, 'on_payment_refunded' ],  10, 2 );
		add_action( 'gform_post_fulfillment',       [ $this, 'on_fulfillment' ],       10, 4 );
 
		// Entry updates (e.g. manual edits in the admin).
		add_action( 'gform_after_update_entry', [ $this, 'on_entry_updated' ], 10, 3 );
	}

    // ── Hook handlers ─────────────────────────────────────────────────────────
    
    /**
	 * Handle the standard form submission event.
	 *
	 * @param  array $entry GF entry array.
	 * @param  array $form  GF form array.
	 * @return void
	 */
	public function on_form_submit( array $entry, array $form ): void {
		$this->process_form_feeds( $entry, $form, 'form_submit' );
	}

    /**
	 * Handle the "after all notifications sent" event.
	 *
	 * Note: $entry is the 8th argument passed by gform_after_send_email.
	 *
	 * @param  bool        $is_success    Whether the email sent successfully.
	 * @param  string      $to            Recipient address.
	 * @param  string      $from          Sender address.
	 * @param  string      $subject       Email subject.
	 * @param  string      $message       Email body.
	 * @param  array       $headers       Email headers.
	 * @param  array       $attachments   Email attachments.
	 * @param  array|false $entry         GF entry array or false.
	 * @return void
	 */
    public function on_after_send_email(
		bool $is_success,
		string $to,
		string $from,
		string $subject,
		string $message,
		array $headers,
		array $attachments,
		$entry
	): void {
		if ( ! $entry ) {
			return;
		}
 
		$form = GFAPI::get_form( $entry['form_id'] );
		$this->process_form_feeds( $entry, $form, 'submission_completed' );
	}

    /**
	 * Handle a successful payment event.
	 *
	 * @param  array $entry  GF entry array.
	 * @param  array $action Payment action data.
	 * @return void
	 */
	public function on_payment_completed( array $entry, array $action ): void {
		$form = GFAPI::get_form( $entry['form_id'] );
		$this->process_form_feeds( $entry, $form, 'payment_completed' );
	}

	/**
	 * Handle a payment refund event.
	 *
	 * @param  array $entry  GF entry array.
	 * @param  array $action Payment action data.
	 * @return void
	 */
	public function on_payment_refunded( array $entry, array $action ): void {
		$form = GFAPI::get_form( $entry['form_id'] );
		$this->process_form_feeds( $entry, $form, 'payment_refunded' );
	}

	/**
	 * Handle a payment fulfillment event.
	 *
	 * @param  array  $entry          GF entry array.
	 * @param  array  $feed           GF payment feed that triggered fulfillment.
	 * @param  string $transaction_id Transaction identifier.
	 * @param  float  $amount         Transaction amount.
	 * @return void
	 */
	public function on_fulfillment( array $entry, array $feed, string $transaction_id, float $amount ): void {
		$form = GFAPI::get_form( $entry['form_id'] );
		$this->process_form_feeds( $entry, $form, 'payment_fulfilled' );
	}

	/**
	 * Handle an entry update event.
	 *
	 * @param  array $form           GF form array.
	 * @param  int   $entry_id       Updated entry ID.
	 * @param  array $original_entry Entry data before the update.
	 * @return void
	 */
	public function on_entry_updated( array $form, int $entry_id, array $original_entry ): void {
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			GFGS_Logger::error( "on_entry_updated: could not retrieve entry #{$entry_id}" );
			return;
		}
 
		$this->process_form_feeds( $entry, $form, 'entry_updated' );
	}

    // ── Core processing ───────────────────────────────────────────────────────
    
	/**
	 * Find all active feeds for a form that match a send_event and process them.
	 *
	 * @param  array  $entry GF entry array.
	 * @param  array  $form  GF form array.
	 * @param  string $event send_event slug (e.g. 'form_submit').
	 * @return void
	 */
	public function process_form_feeds( array $entry, array $form, string $event ): void {
		$feeds = GFGS_Database::get_feeds_by_form( (int) $form['id'] );
 
		foreach ( $feeds as $feed ) {
			if ( ! $feed->is_active || $feed->send_event !== $event ) {
				continue;
			}
 
			$this->process_single_feed( $feed, $entry, $form );
		}
	}

	/**
	 * Process a single feed: evaluate conditions, build the row, send to Sheets.
	 *
	 * @param  object $feed  Decoded feed row object from GFGS_Database.
	 * @param  array  $entry GF entry array.
	 * @param  array  $form  GF form array.
	 * @return void
	 */
	public function process_single_feed( object $feed, array $entry, array $form ): bool {
		$conditions  = is_array( $feed->conditions )  ? $feed->conditions  : [];
		$field_map   = is_array( $feed->field_map )   ? $feed->field_map   : [];
		$date_formats = is_array( $feed->date_formats ) ? $feed->date_formats : [];
 
		// Evaluate conditional logic — bail early if conditions are not met.
		if ( ! GFGS_Field_Mapper::check_conditions( $conditions, $entry, $form ) ) {
			GFGS_Logger::debug( "Feed #{$feed->id} skipped (conditions not met) for entry #{$entry['id']}" );
			return false;
		}
 
		// Build the flat value row from the field mappings.
		$row = GFGS_Field_Mapper::build_row( $field_map, $entry, $form, $date_formats );
 
		if ( empty( $row ) ) {
			GFGS_Logger::debug( "Feed #{$feed->id}: no mapped fields, skipping entry #{$entry['id']}" );
			return false;
		}
 
		// Append the row to Google Sheets.
		$result = $this->api->append_row(
			(int) $feed->account_id,
			$feed->spreadsheet_id,
			$feed->sheet_name,
			$row
		);
 
		if ( is_wp_error( $result ) ) {
			GFGS_Logger::error( "Feed #{$feed->id} error: " . $result->get_error_message() );
 
			GFFormsModel::add_note(
				$entry['id'],
				0,
				'GF Google Sheets',
				sprintf(
					/* translators: 1: feed name, 2: error message */
					__( 'Error sending to Google Sheets (Feed: %1$s): %2$s', GFGS ),
					$feed->feed_name,
					$result->get_error_message()
				),
				'error'
			);
			return false;
		} else {
			GFGS_Logger::debug( "Feed #{$feed->id} sent row for entry #{$entry['id']}" );
 
			GFFormsModel::add_note(
				$entry['id'],
				0,
				'GF Google Sheets',
				sprintf(
					/* translators: %s: feed name */
					__( 'Entry sent to Google Sheets (Feed: %s)', GFGS ),
					$feed->feed_name
				),
				'success'
			);
			return true;
		}
	}

    // ── Manual send (triggered from entry detail) ─────────────────────────────
 	/**
	 * Manually send an entry to one or all active feeds.
	 *
	 * Called from the entry detail meta box via the AJAX handler.
	 *
	 * @param  int      $entry_id GF entry ID.
	 * @param  int|null $feed_id  Specific feed ID, or null to send to all active feeds.
	 * @return array|WP_Error  Array {sent: int, errors: string[]} or WP_Error if entry not found.
	 */
	public static function manual_send( int $entry_id, ?int $feed_id = null ) {
		$entry = GFAPI::get_entry( $entry_id );
 
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
 
		$form  = GFAPI::get_form( $entry['form_id'] );
		$feeds = GFGS_Database::get_feeds_by_form( (int) $form['id'] );
 
		$sent   = 0;
		$errors = [];
 
		foreach ( $feeds as $feed ) {
			// Skip feeds that don't match the requested feed_id, or are inactive.
			if ( null !== $feed_id && (int) $feed->id !== $feed_id ) {
				continue;
			}
 
			if ( ! $feed->is_active ) {
				continue;
			}
 
			$processor = new self();
 
			try {
				if($processor->process_single_feed( $feed, $entry, $form )){
				$sent++;
				}
			} catch ( \Exception $e ) {
				$errors[] = $e->getMessage();
			}
		}
 
		if ( 0 === $sent && null !== $feed_id ) {
			return new WP_Error( 'no_feed', __( 'Feed not found or is inactive.', GFGS ) );
		}
 
		return [ 'sent' => $sent, 'errors' => $errors ];
	}
}