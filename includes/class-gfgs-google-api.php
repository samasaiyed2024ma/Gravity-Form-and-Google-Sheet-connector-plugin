<?php
/**
 * Google API client for the GFGS plugin.
 *
 * Encapsulates all communication with Google's OAuth 2.0 and REST APIs:
 *   - OAuth authorization URL generation.
 *   - Authorization-code exchange.
 *   - Token refresh.
 *   - User-info retrieval.
 *   - Google Drive file listing.
 *   - Google Sheets read (headers) and write (append row).
 *
 * Token management (auto-refresh) is handled by get_valid_token(), which
 * is called internally by every API method that requires authentication.
 *
 * To add a new Google API endpoint in the future:
 *   1. Add a public method following the same pattern as existing methods.
 *   2. Call $this->get_valid_token( $account_id ) first.
 *   3. Make the HTTP request using wp_remote_get() or wp_remote_post().
 *   4. Return $this->parse_response( $response ).
 *
 * @package GFGS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Google_API{

	// ── Google API endpoint constants ─────────────────────────────────────────

	/** @var string Google OAuth 2.0 authorisation endpoint. */
    const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** @var string Google OAuth 2.0 token endpoint. */
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var string Google OAuth 2.0 token revocation endpoint. */
    const OAUTH_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** @var string Google Sheets REST API v4 base URL. */
    const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** @var string Google Drive REST API v3 files endpoint. */
    const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3/files';

    /** @var string Google user-info endpoint (OpenID Connect). */
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
	 * OAuth scopes required by this plugin.
	 * Stored as a space-separated string, ready for use in the OAuth URL.
	 * @var string
	 */
	public const OAUTH_SCOPES = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email';
    

	// ── OAuth: URL generation ─────────────────────────────────────────────────

    /**
	 * Build a Google OAuth 2.0 authorisation URL.
	 *
	 * Delegates to the static helper so callers that only have a client_id
	 * and secret (before any account row exists) can still build the URL
	 * without an GFGS_Google_API instance.
	 *
	 * @param  string $client_id     OAuth client ID.
	 * @param  string $redirect_uri  Callback URL registered in Google Console.
	 * @param  int|string $state     Value passed through OAuth to identify the pending account.
	 * @return string  Full authorisation URL.
	 */
    public static function build_auth_url( $client_id, $redirect_uri, $state = '' ) {
        $params = [
            'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => self::OAUTH_SCOPES,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => (string) $state,
        ];

        return self::OAUTH_AUTH_URL . '?' . http_build_query( $params );
	}

    // ── OAuth: Code exchange ──────────────────────────────────────────────────
 
	/**
	 * Exchange an authorisation code for access and refresh tokens.
	 *
	 * @param  string $code          The `code` query-param returned by Google.
	 * @param  string $client_id     OAuth client ID.
	 * @param  string $client_secret OAuth client secret.
	 * @param  string $redirect_uri  Must match the URI used to generate the auth URL.
	 * @return array|WP_Error Token payload on success, WP_Error on failure.
	 */
    public function exchange_code( $code, $client_id, $client_secret, $redirect_uri) {
		$response = wp_remote_post(
			self::OAUTH_TOKEN_URL,
			[
				'body' => [
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				],
			]
		);
 
		return $this->parse_response( $response );
	}

    // ── OAuth: Token refresh ──────────────────────────────────────────────────
 
	/**
	 * Refresh an expired access token using the stored refresh token.
	 *
	 * @param  string $refresh_token Stored refresh token.
	 * @param  string $client_id     OAuth client ID.
	 * @param  string $client_secret OAuth client secret.
	 * @return array|WP_Error New token payload on success, WP_Error on failure.
	 */
   	public function refresh_token( $refresh_token, $client_id, $client_secret) {
		$response = wp_remote_post(
			self::OAUTH_TOKEN_URL,
			[
				'body' => [
					'refresh_token' => $refresh_token,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'refresh_token',
				],
			]
		);
 
		return $this->parse_response( $response );
	}

    // ── OAuth: User info ──────────────────────────────────────────────────────
 
	/**
	 * Retrieve the authenticated user's profile information.
	 *
	 * @param  string       $access_token A valid access token.
	 * @return array|WP_Error User-info payload on success, WP_Error on failure.
	 */
	public function get_user_info( $access_token ) {
		$response = wp_remote_get(
			self::USERINFO_URL,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
				],
			]
		);
 
		return $this->parse_response( $response );
	}

	// ── Token management ──────────────────────────────────────────────────────
 
	/**
	 * Return a valid (non-expired) access token for an account.
	 *
	 * Automatically refreshes the token if it expires within 60 seconds
	 * and saves the new token back to the database.
	 *
	 * @param  int          $account_id Account ID from the gfgs_accounts table.
	 * @return string|WP_Error  Valid access token string, or WP_Error on failure.
	 */
	public function get_valid_token( $account_id ) {
		$account = GFGS_Database::get_account( $account_id );
 
		if ( ! $account ) {
			return new WP_Error( 'no_account', __( 'Account not found.', 'spreadsheet-sync-for-gravity-forms' ) );
		}
 
		$token_data = json_decode( $account->access_token, true );
 
		if ( empty( $token_data['access_token'] ) ) {
			return new WP_Error(
				'no_token',
				__( 'No access token stored. Please re-authorize this account.', 'spreadsheet-sync-for-gravity-forms' )
			);
		}
 
		// Refresh the token if it expires within 60 seconds.
		if ( (int) $account->token_expires - 60 < time() ) {
			$client_id     = $token_data['client_id']     ?? '';
			$client_secret = $token_data['client_secret'] ?? '';
 
			$refreshed = $this->refresh_token( $account->refresh_token, $client_id, $client_secret );
 
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
 
			$token_data['access_token'] = $refreshed['access_token'];
			$expires_in                 = (int) ( $refreshed['expires_in'] ?? 3600 );
 
			GFGS_Database::save_account( [
				'id'            => $account_id,
				'access_token'  => wp_json_encode( $token_data ),
				'token_expires' => time() + $expires_in,
			] );
		}
 
		return $token_data['access_token'];
	}

 
	// ── Google Drive ──────────────────────────────────────────────────────────
 
	/**
	 * List all Google Sheets spreadsheets accessible by an account.
	 *
	 * @param  int          $account_id Account ID.
	 * @return array|WP_Error Array of {id, name} objects, or WP_Error.
	 */
	public function list_spreadsheets( $account_id ) {
		$token = $this->get_valid_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
 
		$url = add_query_arg(
			[
				'q'        => "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false",
				'fields'   => 'files(id,name)',
				'pageSize' => 100,
			],
			self::DRIVE_API_BASE
		);
 
		$response = wp_remote_get( $url, [ 'headers' => $this->auth_headers( $token ) ] );
		$data     = $this->parse_response( $response );
 
		return is_wp_error( $data ) ? $data : ( $data['files'] ?? [] );
	}

    // ── Google Sheets ─────────────────────────────────────────────────────────
 
	/**
	 * Retrieve all sheet (tab) names and IDs from a spreadsheet.
	 *
	 * @param  int          $account_id     Account ID.
	 * @param  string       $spreadsheet_id Google Sheets file ID.
	 * @return array|WP_Error  Array of {id, title} maps, or WP_Error.
	 */
    public function get_spreadsheet_sheets( $account_id, $spreadsheet_id ) {
		$token = $this->get_valid_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
 
		$url      = self::SHEETS_API_BASE . '/' . rawurlencode( $spreadsheet_id ) . '?fields=sheets.properties';
		$response = wp_remote_get( $url, [ 'headers' => $this->auth_headers( $token ) ] );
		$data     = $this->parse_response( $response );
 
		if ( is_wp_error( $data ) ) {
			return $data;
		}
 
		return array_map(
			static function ( array $sheet ): array {
				return [
					'id'    => $sheet['properties']['sheetId'],
					'title' => $sheet['properties']['title'],
				];
			},
			$data['sheets'] ?? []
		);
	}

    /**
	 * Retrieve the header row (first row) of a specific sheet.
	 *
	 * @param  int          $account_id     Account ID.
	 * @param  string       $spreadsheet_id Google Sheets file ID.
	 * @param  string       $sheet_name     Name of the sheet (tab).
	 * @return array|WP_Error  Flat array of header strings, or WP_Error.
	 */
	public function get_sheet_headers( $account_id, $spreadsheet_id, $sheet_name ) {
		$token = $this->get_valid_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
 
		$range    = rawurlencode( $sheet_name . '!1:1' );
		$url      = self::SHEETS_API_BASE . '/' . rawurlencode( $spreadsheet_id ) . '/values/' . $range;
		$response = wp_remote_get( $url, [ 'headers' => $this->auth_headers( $token ) ] );
		$data     = $this->parse_response( $response );
 
		if ( is_wp_error( $data ) ) {
			return $data;
		}
 
		return $data['values'][0] ?? [];
	}

  	/**
	 * Append a single data row to a Google Sheet.
	 *
	 * Values are sent with valueInputOption=USER_ENTERED so Google interprets
	 * dates, numbers, and formulas automatically.
	 *
	 * @param  int    $account_id     Account ID.
	 * @param  string $spreadsheet_id Google Sheets file ID.
	 * @param  string $sheet_name     Name of the sheet (tab).
	 * @param  array  $row            Flat array of cell values (column-ordered).
	 * @return array|WP_Error  API response payload, or WP_Error on failure.
	 */
	public function append_row( $account_id, $spreadsheet_id, $sheet_name, $row ) {
		$token = $this->get_valid_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
 
		$url = add_query_arg(
			[ 'valueInputOption' => 'USER_ENTERED' ],
			self::SHEETS_API_BASE . '/' . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $sheet_name ) . ':append'
		);
 
		$response = wp_remote_post(
			$url,
			[
				'method'  => 'POST',
				'headers' => array_merge( $this->auth_headers( $token ), [ 'Content-Type' => 'application/json' ] ),
				'body'    => wp_json_encode( [ 'values' => [ array_values( $row ) ] ] ),
			]
		);
 
		return $this->parse_response( $response );
	}

	// ── Internal helpers ──────────────────────────────────────────────────────
 
	/**
	 * Build the Authorization header array for an API request.
	 *
	 * @param  string $access_token Valid Google access token.
	 * @return array<string, string>
	 */
    private function auth_headers( $access_token ) {
		return [ 'Authorization' => 'Bearer ' . $access_token ];
	}

    /**
	 * Parse a wp_remote_*() response into a data array or WP_Error.
     *
	 * @param  array|WP_Error $response Response from wp_remote_get/post.
	 * @return array|WP_Error Decoded JSON body on success (2xx), WP_Error on HTTP or API error.
	 */
    private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
 
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
 
		if ( $code >= 400 ) {
			$message = $body['error']['message'] ?? wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'google_api_' . $code, $message );
		}
 
		return (array) $body;
	}
}