<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFGS_Database {

    const FEEDS_TABLE    = 'gfgs_feeds';
    const ACCOUNTS_TABLE = 'gfgs_accounts';

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_accounts = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::ACCOUNTS_TABLE . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_name  VARCHAR(255)    NOT NULL,
            email         VARCHAR(255)    NOT NULL,
            access_token  LONGTEXT        NOT NULL,
            refresh_token TEXT            NOT NULL,
            token_expires BIGINT          NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_feeds = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::FEEDS_TABLE . " (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id        BIGINT UNSIGNED NOT NULL,
            feed_name      VARCHAR(255)    NOT NULL,
            account_id     BIGINT UNSIGNED NOT NULL,
            spreadsheet_id VARCHAR(255)    DEFAULT '',
            sheet_id       VARCHAR(255)    DEFAULT '',
            sheet_name     VARCHAR(255)    DEFAULT '',
            field_map      LONGTEXT        DEFAULT '',
            date_formats   LONGTEXT        DEFAULT '',
            conditions     LONGTEXT        DEFAULT '',
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

    // ── Accounts ──────────────────────────────────────────────────────────────

    public static function save_account( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::ACCOUNTS_TABLE;

        if ( ! empty( $data['id'] ) ) {
            $id = (int) $data['id'];
            unset( $data['id'] );
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            return $id;
        }

        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function get_accounts() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, account_name, email, token_expires, refresh_token
             FROM {$wpdb->prefix}" . self::ACCOUNTS_TABLE . "
             ORDER BY account_name ASC"
        );
    }

    public static function get_account( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::ACCOUNTS_TABLE . " WHERE id = %d",
            (int) $id
        ) );
    }

    public static function delete_account( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::ACCOUNTS_TABLE, [ 'id' => (int) $id ] );
    }

    // ── Feeds ─────────────────────────────────────────────────────────────────

    /**
     * Save a feed. Accepts pre-encoded JSON strings for array fields.
     */
    public static function save_feed_raw( $data ) {
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

        return $wpdb->insert_id;
    }

    /**
     * Save a feed. Accepts raw arrays for field_map / date_formats / conditions
     * and encodes them automatically.
     */
    public static function save_feed( $data ) {
        foreach ( [ 'field_map', 'date_formats', 'conditions' ] as $key ) {
            if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
                $data[ $key ] = wp_json_encode( $data[ $key ] );
            }
        }
        return self::save_feed_raw( $data );
    }

    public static function get_feeds_by_form( $form_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::FEEDS_TABLE . " WHERE form_id = %d ORDER BY id ASC",
            (int) $form_id
        ) );
        return array_map( [ __CLASS__, 'decode_feed' ], $rows );
    }

    public static function get_feed( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::FEEDS_TABLE . " WHERE id = %d",
            (int) $id
        ) );
        return $row ? self::decode_feed( $row ) : null;
    }

    public static function delete_feed( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::FEEDS_TABLE, [ 'id' => (int) $id ] );
    }

    public static function toggle_feed( $id, $active ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::FEEDS_TABLE,
            [ 'is_active' => (int) $active ],
            [ 'id'        => (int) $id ]
        );
    }

    private static function decode_feed( $row ) {
        foreach ( [ 'field_map', 'date_formats', 'conditions' ] as $key ) {
            if ( isset( $row->$key ) && is_string( $row->$key ) ) {
                $row->$key = json_decode( $row->$key, true ) ?: [];
            }
            if ( ! isset( $row->$key ) ) {
                $row->$key = [];
            }
        }

        // Normalise field_map keys: old schema used 'column'/'gf_field', new uses 'sheet_column'/'field_id'
        if ( is_array( $row->field_map ) ) {
            $row->field_map = array_map( function ( $m ) {
                return [
                    'sheet_column' => $m['sheet_column'] ?? $m['column']   ?? '',
                    'field_id'     => $m['field_id']     ?? $m['gf_field'] ?? '',
                    'field_type'   => $m['field_type']   ?? 'standard',
                ];
            }, $row->field_map );
        }

        return $row;
    }
}