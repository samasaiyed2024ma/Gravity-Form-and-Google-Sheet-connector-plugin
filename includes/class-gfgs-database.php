<?php
/**
 * Database abstraction layer for the GFGS plugin.
 *
 * Manages two custom tables:
 *   - {prefix}gfgs_accounts — OAuth credentials per Google account.
 *   - {prefix}gfgs_feeds    — Feed configuration per Gravity Form.
 *
 * All public methods are static so callers do not need to hold an instance.
 *
 * To add a new table in the future:
 *   1. Define a new *_TABLE constant.
 *   2. Add a `$sql_*` block inside create_tables().
 *   3. Add corresponding CRUD static methods following the existing pattern.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Database {

    // ── Table name constants (without the wpdb prefix) ────────────────────────
    const FEEDS_TABLE    = 'gfgs_feeds';
    const ACCOUNTS_TABLE = 'gfgs_accounts';

    /**
	 * JSON-encoded feed columns that are automatically encoded on save
	 * and decoded on retrieval.
	 *
	 * @var string[]
	 */
	private const FEED_JSON_COLUMNS = [ 'field_map', 'date_formats', 'conditions' ];

   	// ── Schema ────────────────────────────────────────────────────────────────

    /**
	 * Create (or upgrade) the plugin's custom tables using dbDelta.
	 *
	 * Safe to call on every activation — dbDelta only applies changes.
	 * @return void
	 */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql_accounts = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::ACCOUNTS_TABLE . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_name  VARCHAR(255)    NOT NULL,
            email         VARCHAR(255)    NOT NULL DEFAULT '',
            access_token  LONGTEXT        NOT NULL,
            refresh_token TEXT            NOT NULL DEFAULT '',
            token_expires BIGINT          NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_feeds = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::FEEDS_TABLE . " (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id        BIGINT UNSIGNED NOT NULL,
            feed_name      VARCHAR(255)    NOT NULL,
            account_id     BIGINT UNSIGNED NOT NULL,
            spreadsheet_id VARCHAR(255)    NOT NULL DEFAULT '',
            sheet_id       VARCHAR(255)    NOT NULL DEFAULT '',
            sheet_name     VARCHAR(255)    NOT NULL DEFAULT '',
            field_map      LONGTEXT        NOT NULL DEFAULT '',
            date_formats   LONGTEXT        NOT NULL DEFAULT '',
            conditions     LONGTEXT        NOT NULL DEFAULT '',
            send_event     VARCHAR(100)    NOT NULL DEFAULT 'form_submit',
            is_active      TINYINT(1)      NOT NULL DEFAULT 1,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY account_id (account_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_accounts );
        dbDelta( $sql_feeds );

        update_option( 'gfgs_db_version', GFGS_VERSION );
    }

    // ── Accounts CRUD ──────────────────────────────────────────────────────────────

    /**
	 * Insert or update an account record.
	 *
	 * Pass `id` in $data to perform an UPDATE; omit it (or set to 0 / null)
	 * to perform an INSERT.
	 *
	 * @param  array<string, mixed> $data Column → value pairs.
	 * @return int  The account ID (existing on update, new on insert).
	 */

    public static function save_account( array $data ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::ACCOUNTS_TABLE;

        if ( ! empty( $data['id'] ) ) {
            $id = (int) $data['id'];
            unset( $data['id'] );
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            return $id;
        }

        $wpdb->insert( $table, $data );
        return (int) $wpdb->insert_id;
    }


	/**
	 * Retrieve all accounts ordered by name (sensitive columns excluded).
	 *
	 * @return object[] Array of row objects: {id, account_name, email, token_expires, refresh_token}.
	 */
    public static function get_accounts(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, account_name, email, token_expires, refresh_token
             FROM {$wpdb->prefix}" . self::ACCOUNTS_TABLE . "
             ORDER BY account_name ASC"
        );
    }

    /**
	 * Retrieve a single account by ID (all columns).
	 *
	 * @param  int         $id Account ID.
	 * @return object|null Row object or null if not found.
	 */
    public static function get_account( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::ACCOUNTS_TABLE . " WHERE id = %d", $id
        ) ) ?: null;
    }

    /**
	 * Delete an account record.
	 *
	 * @param  int  $id Account ID.
	 * @return void
	 */
    public static function delete_account( int $id ): void {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . self::ACCOUNTS_TABLE, [ 'id' => $id ] );
    }

    // ── Feeds CRUD ─────────────────────────────────────────────────────────────────

	/**
	 * Insert or update a feed, auto-encoding array columns to JSON.
	 *
	 * Use this method when $data's JSON columns may still be PHP arrays.
	 * For pre-encoded data (e.g. coming directly from AJAX $_POST) use
	 * save_feed_raw() instead to avoid double-encoding.
	 *
	 * @param  array<string, mixed> $data Feed data.
	 * @return int|WP_Error New / updated feed ID, or WP_Error on failure.
	 */
    public static function save_feed( array $data ) {
        foreach ( self::FEED_JSON_COLUMNS as $key ) {
            if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
                $data[ $key ] = wp_json_encode( $data[ $key ] );
            }
        }

        return self::save_feed_raw( $data );
    }

	/**
	 * Insert or update a feed with pre-encoded JSON strings for array columns.
     *   
	 * @param  array<string, mixed> $data Feed data with JSON strings for array columns.
	 * @return int|WP_Error New / updated feed ID, or WP_Error on failure.
	 */
    public static function save_feed_raw( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . self::FEEDS_TABLE;

        if ( ! empty( $data['id'] ) ) {
            $id = (int) $data['id'];
            unset( $data['id'] );

            $result = $wpdb->update( $table, $data, [ 'id' => $id ] );

            if ( $result === false ) {
                return new WP_Error( 'db_update_error', $wpdb->last_error );
            }

            return $id;
        }

        $result = $wpdb->insert( $table, $data );

        if ( $result === false ) {
            return new WP_Error( 'db_insert_error', $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

	/**
	 * Retrieve all feeds for a given form, decoded and normalised.
	 *
	 * @param  int      $form_id Gravity Forms form ID.
	 * @return object[] Array of decoded feed row objects.
	 */
    public static function get_feeds_by_form( int $form_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::FEEDS_TABLE . " WHERE form_id = %d ORDER BY id ASC", $form_id
        ) );

        return array_map( [ __CLASS__, 'decode_feed' ], $rows );
    }

    /**
	 * Retrieve a single feed by ID, decoded and normalised.
	 *
	 * @param  int         $id Feed ID.
	 * @return object|null Decoded feed row object or null if not found.
	 */
    public static function get_feed( int $id ): ?object {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::FEEDS_TABLE . " WHERE id = %d", $id
        ) );

        return $row ? self::decode_feed( $row ) : null;
    }

    /**
	 * Delete a feed record.
	 *
	 * @param  int  $id Feed ID.
	 * @return void
	 */
    public static function delete_feed( int $id ): void {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . self::FEEDS_TABLE, [ 'id' => $id ] );
    }

    /**
	 * Toggle a feed's active state.
	 *
	 * @param  int  $id     Feed ID.
	 * @param  int  $active 1 = active, 0 = inactive.
	 * @return void
	 */
    public static function toggle_feed( int $id, int $active ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . self::FEEDS_TABLE,
            [ 'is_active' => $active ],
            [ 'id'        => $id ]
        );
    }

  	// ── Internal helpers ──────────────────────────────────────────────────────
    
    /**
	 * Decode JSON columns and normalise field_map key names for a raw feed row.
	 *
	 * This is the single authoritative place for:
	 *   - Decoding JSON strings to arrays.
	 *   - Migrating old field_map key names ('column'/'gf_field') to the
	 *     current schema ('sheet_column'/'field_id').
	 *   - Providing default structures for missing keys.
	 *
	 * @param  object $row Raw database row object.
	 * @return object Normalised row object.
	 */
    private static function decode_feed( object $row ): object {
        // Decode JSON columns.
        foreach ( self::FEED_JSON_COLUMNS as $key ) {
            if ( isset( $row->$key ) && is_string( $row->$key ) ) {
                $row->$key = json_decode( $row->$key, true ) ?: [];
            }
            if ( ! isset( $row->$key ) ) {
                $row->$key = [];
            }
        }

        // Apply default structure to the conditions column.
		if ( is_array( $row->conditions ) ) {
			$row->conditions = wp_parse_args(
				$row->conditions,
				[
					'enabled' => false,
					'action'  => 'send',
					'logic'   => 'all',
					'rules'   => [],
				]
			);
		}

		// Normalise field_map: migrate old key names ('column'/'gf_field')
		// to the current schema ('sheet_column'/'field_id').
        if ( is_array( $row->field_map ) ) {
            $row->field_map = array_map( 
                static function ( array $m ): array {
                    return [
                        'sheet_column' => $m['sheet_column'] ?? $m['column']   ?? '',
                        'field_id'     => $m['field_id']     ?? $m['gf_field'] ?? '',
                        'field_type'   => $m['field_type']   ?? 'standard',
                    ];
                }, 
                $row->field_map 
            );
        }

        // Cast is_active to int so JS receives 0 or 1, not the string "0"
		// which is truthy in JavaScript.
		$row->is_active = (int) ( $row->is_active ?? 1 );

        return $row;
    }
}