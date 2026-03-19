<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPilot OAuth PKCE — Connect customer's Claude account
//
//  Flow:
//  1. Customer clicks "Connect Claude" in plugin settings
//  2. Plugin generates PKCE challenge + state
//  3. Redirects to claude.ai/oauth/authorize
//  4. Customer logs in, approves, sees code on platform.claude.com
//  5. Customer pastes code back in plugin
//  6. Plugin exchanges code for tokens via platform.claude.com API
//  7. Tokens stored encrypted in wp_options
//  8. Auto-refresh when access token expires
// ═══════════════════════════════════════════════════════════════

// Built by Weblease

// ── OAuth Configuration ──────────────────────────────────────
define( 'WPILOT_OAUTH_CLIENT_ID',    '9d1c250a-e61b-44d9-88ed-5944d1962f5e' );
define( 'WPILOT_OAUTH_AUTHORIZE_URL', 'https://claude.ai/oauth/authorize' );
define( 'WPILOT_OAUTH_TOKEN_URL',     'https://platform.claude.com/v1/oauth/token' );
define( 'WPILOT_OAUTH_REDIRECT_URI',  'https://platform.claude.com/oauth/code/callback' );
define( 'WPILOT_OAUTH_SCOPES',        'user:inference user:profile' );

// ── Generate PKCE code_verifier + code_challenge ─────────────
function wpilot_oauth_generate_pkce() {
    // Generate 32 random bytes → base64url encode → 43 chars
    $verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

    // SHA256 hash → base64url encode
    $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

    return [
        'verifier'  => $verifier,
        'challenge' => $challenge,
    ];
}

// ── Start OAuth flow — returns authorize URL ─────────────────
function wpilot_oauth_start() {
    $pkce  = wpilot_oauth_generate_pkce();
    $state = wp_generate_password( 32, false );

    // Store PKCE verifier + state temporarily (15 min TTL)
    set_transient( 'wpilot_oauth_verifier', $pkce['verifier'], 900 );
    set_transient( 'wpilot_oauth_state', $state, 900 );

    $params = [
        'response_type'         => 'code',
        'client_id'             => WPILOT_OAUTH_CLIENT_ID,
        'redirect_uri'          => WPILOT_OAUTH_REDIRECT_URI,
        'scope'                 => WPILOT_OAUTH_SCOPES,
        'state'                 => $state,
        'code_challenge'        => $pkce['challenge'],
        'code_challenge_method' => 'S256',
    ];

    return WPILOT_OAUTH_AUTHORIZE_URL . '?' . http_build_query( $params );
}

// ── Exchange authorization code for tokens ───────────────────
function wpilot_oauth_exchange( $code ) {
    $verifier = get_transient( 'wpilot_oauth_verifier' );
    if ( ! $verifier ) {
        return new WP_Error( 'expired', 'Inloggningen tog för lång tid. Försök igen.' );
    }

    $response = wp_remote_post( WPILOT_OAUTH_TOKEN_URL, [
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'grant_type'    => 'authorization_code',
            'client_id'     => WPILOT_OAUTH_CLIENT_ID,
            'code'          => $code,
            'redirect_uri'  => WPILOT_OAUTH_REDIRECT_URI,
            'code_verifier' => $verifier,
        ] ),
    ] );

    // Clear PKCE data — one-time use
    delete_transient( 'wpilot_oauth_verifier' );
    delete_transient( 'wpilot_oauth_state' );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'network', 'Kunde inte kontakta Claude. Kontrollera din internetanslutning.' );
    }

    $code_http = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code_http !== 200 || empty( $body['access_token'] ) ) {
        $err = $body['error_description'] ?? $body['error'] ?? "HTTP {$code_http}";
        return new WP_Error( 'token_error', 'Claude godkände inte koden: ' . $err );
    }

    // Store tokens encrypted
    wpilot_oauth_save_tokens( $body );

    return [
        'success' => true,
        'expires_in' => $body['expires_in'] ?? 3600,
    ];
}

// ── Save tokens (encrypted) ─────────────────────────────────
function wpilot_oauth_save_tokens( $token_data ) {
    $tokens = [
        'access_token'  => $token_data['access_token'],
        'refresh_token' => $token_data['refresh_token'] ?? '',
        'expires_at'    => time() + ( $token_data['expires_in'] ?? 3600 ),
        'token_type'    => $token_data['token_type'] ?? 'Bearer',
        'scope'         => $token_data['scope'] ?? WPILOT_OAUTH_SCOPES,
        'connected_at'  => time(),
    ];

    update_option( 'wpilot_oauth_tokens', wpilot_encrypt( wp_json_encode( $tokens ) ), false );
    update_option( 'wpilot_oauth_connected', 'yes' );
}

// ── Get current access token (auto-refresh if expired) ───────
function wpilot_oauth_get_token() {
    $encrypted = get_option( 'wpilot_oauth_tokens', '' );
    if ( empty( $encrypted ) ) return null;

    $tokens = json_decode( wpilot_decrypt( $encrypted ), true );
    if ( ! $tokens || empty( $tokens['access_token'] ) ) return null;

    // Check if expired (with 5 min buffer)
    if ( time() >= ( $tokens['expires_at'] - 300 ) ) {
        // Try to refresh
        $refreshed = wpilot_oauth_refresh( $tokens['refresh_token'] ?? '' );
        if ( is_wp_error( $refreshed ) ) {
            // Refresh failed — token is dead
            wpilot_oauth_disconnect();
            return null;
        }
        // Re-read tokens after refresh
        $encrypted = get_option( 'wpilot_oauth_tokens', '' );
        $tokens = json_decode( wpilot_decrypt( $encrypted ), true );
    }

    return $tokens['access_token'] ?? null;
}

// ── Refresh access token using refresh_token ─────────────────
function wpilot_oauth_refresh( $refresh_token ) {
    if ( empty( $refresh_token ) ) {
        return new WP_Error( 'no_refresh', 'No refresh token available.' );
    }

    $response = wp_remote_post( WPILOT_OAUTH_TOKEN_URL, [
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'grant_type'    => 'refresh_token',
            'client_id'     => WPILOT_OAUTH_CLIENT_ID,
            'refresh_token' => $refresh_token,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['access_token'] ) ) {
        return new WP_Error( 'refresh_failed', 'Token refresh failed.' );
    }

    // Save new tokens
    wpilot_oauth_save_tokens( $body );

    return true;
}

// ── Check if OAuth is connected ──────────────────────────────
function wpilot_oauth_is_connected() {
    return get_option( 'wpilot_oauth_connected', 'no' ) === 'yes';
}

// ── Disconnect — remove tokens ───────────────────────────────
function wpilot_oauth_disconnect() {
    delete_option( 'wpilot_oauth_tokens' );
    update_option( 'wpilot_oauth_connected', 'no' );
}

// ── Get connection status for UI ─────────────────────────────
function wpilot_oauth_status() {
    if ( ! wpilot_oauth_is_connected() ) {
        return [ 'connected' => false, 'status' => 'disconnected' ];
    }

    $encrypted = get_option( 'wpilot_oauth_tokens', '' );
    $tokens = json_decode( wpilot_decrypt( $encrypted ), true );

    if ( ! $tokens ) {
        return [ 'connected' => false, 'status' => 'corrupted' ];
    }

    $expired = time() >= ( $tokens['expires_at'] ?? 0 );
    $has_refresh = ! empty( $tokens['refresh_token'] );

    return [
        'connected'    => true,
        'status'       => $expired ? ( $has_refresh ? 'refreshable' : 'expired' ) : 'active',
        'expires_at'   => $tokens['expires_at'] ?? 0,
        'connected_at' => $tokens['connected_at'] ?? 0,
    ];
}

// ═══════════════════════════════════════════════════════════════
//  AJAX ENDPOINTS — called from bubble/settings UI
// ═══════════════════════════════════════════════════════════════

// ── Start OAuth flow ─────────────────────────────────────────
add_action( 'wp_ajax_wpilot_oauth_start', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Endast admins kan ansluta Claude.' );

    $url = wpilot_oauth_start();
    wp_send_json_success( [ 'url' => $url ] );
} );

// ── Exchange code for tokens ─────────────────────────────────
add_action( 'wp_ajax_wpilot_oauth_exchange', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Endast admins.' );

    $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
    if ( empty( $code ) ) wp_send_json_error( 'Ange koden du fick från Claude.' );

    $result = wpilot_oauth_exchange( $code );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( [
        'message' => 'Claude är nu ansluten! Du kan börja chatta.',
        'connected' => true,
    ] );
} );

// ── Check connection status ──────────────────────────────────
add_action( 'wp_ajax_wpilot_oauth_status', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    wp_send_json_success( wpilot_oauth_status() );
} );

// ── Disconnect ───────────────────────────────────────────────
add_action( 'wp_ajax_wpilot_oauth_disconnect', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Endast admins.' );
    wpilot_oauth_disconnect();
    wp_send_json_success( [ 'message' => 'Claude frånkopplad.' ] );
} );
