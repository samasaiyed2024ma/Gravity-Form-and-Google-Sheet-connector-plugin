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
 * Caching strategy:
 *   - wp_cache_get() / wp_cache_set() wrap every SELECT query.
 *   - Cache is invalidated (wp_cache_delete()) on every INSERT, UPDATE, or DELETE.
 *   - Cache group: 'gfgs'  (one group makes bulk-invalidation easy).
 *   - Cache keys follow the pattern:  gfgs_{table}_{qualifier}
 *     e.g. gfgs_accounts_all, gfgs_account_5, gfgs_feeds_form_12, gfgs_feed_3
 *
 * To add a new table in the future:
 *   1. Define a new *_TABLE constant.
 *   2. Add a `$sql_*` block inside create_tables().
 *   3. Add corresponding CRUD static methods following the existing pattern.
 *   4. Add cache-invalidation calls matching the new cache keys.
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GFGS_Database
 *
 * Static database abstraction layer for accounts and feeds.
 */
class GFGS_Database {

	// ── Constants ──────────────────────────────────────────────────────────────

	/**
	 * Feeds table name (without prefix).
	 *
	 * @var string
	 */
	const FEEDS_TABLE = 'gfgs_feeds';

	/**
	 * Accounts table name (without prefix).
	 *
	 * @var string
	 */
	const ACCOUNTS_TABLE = 'gfgs_accounts';

	/**
	 * Object cache group for all GFGS cache keys.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'gfgs';

	/**
	 * Default cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = 3600;

	/**
	 * Feed columns that are stored as JSON and decoded on retrieval.
	 *
	 * @var string[]
	 */
	private static $feed_json_columns = array( 'field_map', 'date_formats', 'conditions' );

	// ── Schema ─────────────────────────────────────────────────────────────────

	/**
	 * Create (or upgrade) the plugin's custom tables using dbDelta.
	 *
	 * Safe to call on every activation — dbDelta only applies changes.
	 *
	 * @return void
	 */
	public static function create_tables() {
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

	// ── Accounts CRUD ──────────────────────────────────────────────────────────

	/**
	 * Insert or update an account record.
	 *
	 * Pass `id` in $data to perform an UPDATE; omit it (or set to 0 / null)
	 * to perform an INSERT.
	 *
	 * Cache invalidated: gfgs_accounts_all, gfgs_account_{id}
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 *
	 * @return int The account ID (existing on update, new on insert).
	 */
	public static function save_account( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . self::ACCOUNTS_TABLE;

		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			unset( $data['id'] );

			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			wp_cache_delete( 'gfgs_account_' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'gfgs_accounts_all', self::CACHE_GROUP );

			return $id;
		}

		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$new_id = (int) $wpdb->insert_id;

		wp_cache_delete( 'gfgs_accounts_all', self::CACHE_GROUP );

		return $new_id;
	}

	/**
	 * Retrieve all accounts ordered by name (sensitive columns excluded).
	 *
	 * Result is cached under the key 'gfgs_accounts_all'.
	 *
	 * @return object[] Array of row objects: {id, account_name, email, token_expires, refresh_token}.
	 */
	public static function get_accounts() {
		$cache_key = 'gfgs_accounts_all';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::ACCOUNTS_TABLE;

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			'SELECT id, account_name, email, token_expires, refresh_token FROM '
			. esc_sql( $table )
			. ' ORDER BY account_name ASC'
		);

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Retrieve a single account by ID (all columns).
	 *
	 * Result is cached under the key 'gfgs_account_{id}'.
	 * A null result (no row found) is stored as the sentinel string '##null##'
	 * to distinguish a cached miss from a cold cache (wp_cache_get returns false).
	 *
	 * @param int $id Account ID.
	 *
	 * @return object|null Row object or null if not found.
	 */
	public static function get_account( $id ) {
		$id        = (int) $id;
		$cache_key = 'gfgs_account_' . $id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return '##null##' === $cached ? null : $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::ACCOUNTS_TABLE;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $table ) . ' WHERE id = %d',
				$id
			)
		);

		wp_cache_set( $cache_key, $row ? $row : '##null##', self::CACHE_GROUP, self::CACHE_TTL );

		return $row ? $row : null;
	}

	/**
	 * Delete an account record.
	 *
	 * Cache invalidated: gfgs_accounts_all, gfgs_account_{id}
	 *
	 * @param int $id Account ID.
	 *
	 * @return void
	 */
	public static function delete_account( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->delete( $wpdb->prefix . self::ACCOUNTS_TABLE, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		wp_cache_delete( 'gfgs_account_' . $id, self::CACHE_GROUP );
		wp_cache_delete( 'gfgs_accounts_all', self::CACHE_GROUP );
	}

	// ── Feeds CRUD ─────────────────────────────────────────────────────────────

	/**
	 * Insert or update a feed, auto-encoding array columns to JSON.
	 *
	 * Use this method when $data's JSON columns may still be PHP arrays.
	 * For pre-encoded data (e.g. coming directly from AJAX $_POST) use
	 * save_feed_raw() instead to avoid double-encoding.
	 *
	 * @param array<string, mixed> $data Feed data.
	 *
	 * @return int|WP_Error New / updated feed ID, or WP_Error on failure.
	 */
	public static function save_feed( $data ) {
		foreach ( self::$feed_json_columns as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ] = wp_json_encode( $data[ $key ] );
			}
		}

		return self::save_feed_raw( $data );
	}

	/**
	 * Insert or update a feed with pre-encoded JSON strings for array columns.
	 *
	 * Cache invalidated: gfgs_feeds_form_{form_id}, gfgs_feed_{id}
	 *
	 * @param array<string, mixed> $data Feed data with JSON strings for array columns.
	 *
	 * @return int|WP_Error New / updated feed ID, or WP_Error on failure.
	 */
	public static function save_feed_raw( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . self::FEEDS_TABLE;

		if ( ! empty( $data['id'] ) ) {
			$id      = (int) $data['id'];
			$form_id = isset( $data['form_id'] ) ? (int) $data['form_id'] : 0;
			unset( $data['id'] );

			$result = $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			if ( false === $result ) {
				return new WP_Error( 'db_update_error', $wpdb->last_error );
			}

			wp_cache_delete( 'gfgs_feed_' . $id, self::CACHE_GROUP );

			if ( $form_id ) {
				wp_cache_delete( 'gfgs_feeds_form_' . $form_id, self::CACHE_GROUP );
			} else {
				// form_id not in $data on partial updates — resolve it to bust the right cache.
				self::bust_feed_form_cache( $id );
			}

			return $id;
		}

		$result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $result ) {
			return new WP_Error( 'db_insert_error', $wpdb->last_error );
		}

		$new_id  = (int) $wpdb->insert_id;
		$form_id = isset( $data['form_id'] ) ? (int) $data['form_id'] : 0;

		if ( $form_id ) {
			wp_cache_delete( 'gfgs_feeds_form_' . $form_id, self::CACHE_GROUP );
		}

		return $new_id;
	}

	/**
	 * Retrieve all feeds for a given form, decoded and normalised.
	 *
	 * Result is cached under the key 'gfgs_feeds_form_{form_id}'.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 *
	 * @return object[] Array of decoded feed row objects.
	 */
	public static function get_feeds_by_form( $form_id ) {
		$form_id   = (int) $form_id;
		$cache_key = 'gfgs_feeds_form_' . $form_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::FEEDS_TABLE;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $table ) . ' WHERE form_id = %d ORDER BY id ASC',
				$form_id
			)
		);

		$decoded = array_map( array( __CLASS__, 'decode_feed' ), $rows );

		wp_cache_set( $cache_key, $decoded, self::CACHE_GROUP, self::CACHE_TTL );

		return $decoded;
	}

	/**
	 * Retrieve a single feed by ID, decoded and normalised.
	 *
	 * Result is cached under the key 'gfgs_feed_{id}'.
	 *
	 * @param int $id Feed ID.
	 *
	 * @return object|null Decoded feed row object or null if not found.
	 */
	public static function get_feed( $id ) {
		$id        = (int) $id;
		$cache_key = 'gfgs_feed_' . $id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return '##null##' === $cached ? null : $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::FEEDS_TABLE;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $table ) . ' WHERE id = %d',
				$id
			)
		);

		$value = $row ? self::decode_feed( $row ) : '##null##';

		wp_cache_set( $cache_key, $value, self::CACHE_GROUP, self::CACHE_TTL );

		return $row ? $value : null;
	}

	/**
	 * Delete a feed record.
	 *
	 * Cache invalidated: gfgs_feed_{id}, gfgs_feeds_form_{form_id}
	 *
	 * @param int $id Feed ID.
	 *
	 * @return void
	 */
	public static function delete_feed( $id ) {
		global $wpdb;

		$id = (int) $id;

		// Fetch the feed first so we know which form cache to bust.
		$feed = self::get_feed( $id );

		$wpdb->delete( $wpdb->prefix . self::FEEDS_TABLE, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		wp_cache_delete( 'gfgs_feed_' . $id, self::CACHE_GROUP );

		if ( $feed && ! empty( $feed->form_id ) ) {
			wp_cache_delete( 'gfgs_feeds_form_' . (int) $feed->form_id, self::CACHE_GROUP );
		}
	}

	/**
	 * Toggle a feed's active state.
	 *
	 * Cache invalidated: gfgs_feed_{id}, gfgs_feeds_form_{form_id}
	 *
	 * @param int $id     Feed ID.
	 * @param int $active 1 = active, 0 = inactive.
	 *
	 * @return void
	 */
	public static function toggle_feed( $id, $active ) {
		global $wpdb;

		$id     = (int) $id;
		$active = (int) $active;

		// Read before write so we can bust the form-level cache.
		$feed = self::get_feed( $id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . self::FEEDS_TABLE,
			array( 'is_active' => $active ),
			array( 'id'        => $id )
		);

		wp_cache_delete( 'gfgs_feed_' . $id, self::CACHE_GROUP );

		if ( $feed && ! empty( $feed->form_id ) ) {
			wp_cache_delete( 'gfgs_feeds_form_' . (int) $feed->form_id, self::CACHE_GROUP );
		}
	}

	// ── Private helpers ─────────────────────────────────────────────────────────

	/**
	 * Bust the gfgs_feeds_form_{form_id} cache for the form that owns a feed.
	 *
	 * Used when a partial save_feed_raw() update omits form_id from $data and we
	 * cannot derive the correct form cache key without a lightweight DB lookup.
	 *
	 * Note: the NoCaching suppression below is intentional — this helper exists
	 * specifically to invalidate a stale cache, so caching the result here would
	 * be counterproductive.
	 *
	 * @param int $feed_id Feed ID.
	 *
	 * @return void
	 */
	private static function bust_feed_form_cache( $feed_id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::FEEDS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$form_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT form_id FROM ' . esc_sql( $table ) . ' WHERE id = %d LIMIT 1',
				(int) $feed_id
			)
		);

		if ( $form_id ) {
			wp_cache_delete( 'gfgs_feeds_form_' . $form_id, self::CACHE_GROUP );
		}
	}

	/**
	 * Decode JSON columns and normalise field_map key names for a raw feed row.
	 *
	 * This is the single authoritative place for:
	 *   - Decoding JSON strings to arrays.
	 *   - Migrating old field_map key names ('column'/'gf_field') to the
	 *     current schema ('sheet_column'/'field_id').
	 *   - Providing default structures for missing keys.
	 *
	 * @param object $row Raw database row object.
	 *
	 * @return object Normalised row object.
	 */
	private static function decode_feed( $row ) {
		// Decode JSON columns.
		foreach ( self::$feed_json_columns as $key ) {
			if ( isset( $row->$key ) && is_string( $row->$key ) ) {
				$decoded   = json_decode( $row->$key, true );
				$row->$key = $decoded ? $decoded : array();
			}

			if ( ! isset( $row->$key ) ) {
				$row->$key = array();
			}
		}

		// Apply default structure to the conditions column.
		if ( is_array( $row->conditions ) ) {
			$row->conditions = wp_parse_args(
				$row->conditions,
				array(
					'enabled' => false,
					'action'  => 'send',
					'logic'   => 'all',
					'rules'   => array(),
				)
			);
		}

		// Normalise field_map: migrate old key names ('column'/'gf_field')
		// to the current schema ('sheet_column'/'field_id').
		if ( is_array( $row->field_map ) ) {
			$row->field_map = array_map(
				static function ( $m ) {
					return array(
						'sheet_column' => isset( $m['sheet_column'] ) ? $m['sheet_column'] : ( isset( $m['column'] ) ? $m['column'] : '' ),
						'field_id'     => isset( $m['field_id'] ) ? $m['field_id'] : ( isset( $m['gf_field'] ) ? $m['gf_field'] : '' ),
						'field_type'   => isset( $m['field_type'] ) ? $m['field_type'] : 'standard',
					);
				},
				$row->field_map
			);
		}

		// Cast is_active to int so JS receives 0 or 1, not the string "0"
		// which is truthy in JavaScript.
		$row->is_active = (int) ( $row->is_active ? $row->is_active : 1 );

		return $row;
	}
}