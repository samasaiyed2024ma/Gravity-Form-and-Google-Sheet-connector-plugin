<?php

if(!defined('ABSPATH')) exit;

class GFGS_Google_API{
    const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const OAUTH_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3/files';
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    private $client_id;
    private $client_secret;
    private $redirect_uri;

    public function __construct()
    {
        $settings = get_option('gfgs_settings', []);
        $this->client_id = $settings['client_id'] ?? '';
        $this->client_secret = $settings['client_secret'] ?? '';
        $this->redirect_uri = admin_url('admin.php?page=gf_settings&subview=gf-google-sheets&gfgs_oauth=callback');
    }


    // ── OAuth ────────────────────────────────────────────────────────────────
    public function get_auth_url($state = ''){
        $params = [
            'client_id' => $this->client_id,
            // 'client_secret' => $this->client_secret,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent', 
            'state' => $state ?: wp_create_nonce('gfgs_oauth'),
        ];

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    public function exchange_code($code){
        return $this->exchage_code_with_creds($code, $this->client_id, $this->client_secret, $this->redirect_uri);
    }

    public function exchage_code_with_creds($code, $client_id, $client_secret, $redirect_uri){
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        return $this->parse_response($response);
    }
    public function refresh_token($refresh_token){
        return $this->refresh_token_with_creds($refresh_token, $this->client_id, $this->client_secret);
    }

    public function refresh_token_with_creds($refresh_token, $client_id, $client_secret){
        $reponse = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'grant_type' => 'refresh_token',
            ],
        ]);
        return $this->parse_response($reponse);
    }

    public function get_userinfo($access_token){
        return $this->get_userinfo_with_token($access_token);
    }

    public function get_userinfo_with_token($access_token){
        $response = wp_remote_get(self::USERINFO_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ],
        ]);
        return $this->parse_response($response);
    }

    // ── Token Management ────────────────────────────────────────────────────────────────
    public function get_valid_token( $account_id ) {
        $account = GFGS_Database::get_account( $account_id );
        if ( ! $account ) return new WP_Error( 'no_account', __( 'Account not found.', 'GFGS' ) );

        $tokens = json_decode( $account->access_token, true );
        if ( empty( $tokens['access_token'] ) ) {
            return new WP_Error( 'no_token', __( 'No access token stored. Please re-authorize this account.', 'GFGS' ) );
        }

        // Refresh if expires in < 60 seconds
        if ( (int) $account->token_expires - 60 < time() ) {
            // Use per-account credentials stored alongside the access token
            $client_id     = $tokens['client_id']     ?? $this->client_id;
            $client_secret = $tokens['client_secret'] ?? $this->client_secret;

            $refreshed = $this->refresh_token_with_creds( $account->refresh_token, $client_id, $client_secret );
            if ( is_wp_error( $refreshed ) ) return $refreshed;

            $tokens['access_token'] = $refreshed['access_token'];
            $expires_in             = (int) ( $refreshed['expires_in'] ?? 3600 );

            GFGS_Database::save_account( [
                'id'           => $account_id,
                'access_token' => wp_json_encode( $tokens ),
                'token_expires'=> time() + $expires_in,
            ] );
        }
        return $tokens['access_token'];
    }

    // ── Drive / Sheets discovery ────────────────────────────────────────────────────────────────
    public function list_spreadsheets( $account_id ) {
        $token = $this->get_valid_token( $account_id );
        if ( is_wp_error( $token ) ) return $token;

        $url      = add_query_arg( [
            'q'      => "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false",
            'fields' => 'files(id,name)',
            'pageSize' => 100,
        ], self::DRIVE_API_BASE );
        $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ] );
        $data     = $this->parse_response( $response );
        return is_wp_error( $data ) ? $data : ( $data['files'] ?? [] );
    }

    public function get_spreadsheet_sheets( $account_id, $spreadsheet_id ) {
        $token = $this->get_valid_token( $account_id );
        if ( is_wp_error( $token ) ) return $token;

        $url      = self::SHEETS_API_BASE . '/' . urlencode( $spreadsheet_id ) . '?fields=sheets.properties';
        $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ] );
        $data     = $this->parse_response( $response );
        if ( is_wp_error( $data ) ) return $data;

        return array_map( fn( $s ) => [
            'id'    => $s['properties']['sheetId'],
            'title' => $s['properties']['title'],
        ], $data['sheets'] ?? [] );
    }

    public function get_sheet_headers( $account_id, $spreadsheet_id, $sheet_name ) {
        $token = $this->get_valid_token( $account_id );
        if ( is_wp_error( $token ) ) return $token;

        $range    = urlencode( $sheet_name . '!1:1' );
        $url      = self::SHEETS_API_BASE . '/' . urlencode( $spreadsheet_id ) . '/values/' . $range;
        $response = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ] );
        $data     = $this->parse_response( $response );
        if ( is_wp_error( $data ) ) return $data;

        return $data['values'][0] ?? [];
    }

    // ── Writing data ─────────────────────────────────────────────────────────

    /**
     * Append a single row to a Google Sheet.
     *
     * @param int    $account_id
     * @param string $spreadsheet_id
     * @param string $sheet_name
     * @param array  $row            Flat array of cell values.
     */
    public function append_row( $account_id, $spreadsheet_id, $sheet_name, array $row ) {
        $token = $this->get_valid_token( $account_id );
        if ( is_wp_error( $token ) ) return $token;

        $range = urlencode( $sheet_name );
        $url   = add_query_arg( [ 'valueInputOption' => 'USER_ENTERED' ],
            self::SHEETS_API_BASE . '/' . urlencode( $spreadsheet_id ) . '/values/' . $range . ':append'
        );

        $response = wp_remote_post( $url, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 'values' => [ array_values( $row ) ] ] ),
        ] );

        return $this->parse_response( $response );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    private function parse_response($response){
        if(is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if($code >= 400){
            $msg = $body['error']['message'] ?? wp_remote_retrieve_response_message( $response );
            return new WP_Error( 'google_api_' . $code, $msg );
        }

        return $body;
    }
}