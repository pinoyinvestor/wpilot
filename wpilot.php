<?php
/**
 * Plugin Name:  WPilot — Powered by Claude
 * Plugin URI:   https://weblease.se/wpilot
 * Description:  AI-powered WordPress management. Chat agent, SEO, security, design — powered by Claude.
 * Version:      1.0.0
 * Author:       Weblease
 * Author URI:   https://weblease.se
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wpilot
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPILOT_VERSION', '1.0.0' );
define( 'WPILOT_FREE_LIMIT', 20 );

// ══════════════════════════════════════════════════════════════
//  HOOKS
// ══════════════════════════════════════════════════════════════

// Don't load if Lite is also active (Pro takes priority)
if ( in_array( 'wpilot-lite/wpilot-lite.php', get_option( 'active_plugins', [] ) ) ) {
    deactivate_plugins( 'wpilot-lite/wpilot-lite.php' );
}

add_action( 'rest_api_init', 'wpilot_register_routes' );
add_action( 'admin_menu', 'wpilot_register_admin' );
add_action( 'admin_init', 'wpilot_handle_actions' );
// Load scroll animations JS on frontend
add_action( "wp_enqueue_scripts", function() {
    wp_enqueue_script( "wpilot-animations", plugin_dir_url( __FILE__ ) . "wpilot-animations.js", [], WPILOT_VERSION, true );
});

// Auto-update: check weblease.se for new versions
add_filter( 'pre_set_site_transient_update_plugins', 'wpilot_check_update' );
add_filter( 'plugins_api', 'wpilot_plugin_info', 20, 3 );
add_filter( 'plugin_row_meta', 'wpilot_plugin_links', 10, 2 );

// Deactivate Lite when Pro is activated (prevent conflicts)
add_action( 'activated_plugin', function( $plugin ) {
    if ( $plugin === 'wpilot/wpilot.php' && is_plugin_active( 'wpilot-lite/wpilot-lite.php' ) ) {
        deactivate_plugins( 'wpilot-lite/wpilot-lite.php' );
    }
} );

register_activation_hook( __FILE__, function () {
    add_option( 'wpilot_do_activation_redirect', true );
    wpilot_create_tables();
    if ( ! wp_next_scheduled( 'wpilot_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'wpilot_daily_cleanup' );
    }
});
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'wpilot_daily_cleanup' );
});
add_action( 'wpilot_daily_cleanup', function () {
    global $wpdb;
    $queue = $wpdb->prefix . 'wpilot_chat_queue';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) === $queue ) {
        // Delete chat messages older than 30 days
        $wpdb->query( "DELETE FROM {$queue} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    }
    // Clean unanswered questions older than 30 days
    $uq = get_option( 'wpilot_unanswered_questions', [] );
    $cutoff = date( 'Y-m-d', strtotime( '-30 days' ) );
    $uq = array_filter( $uq, function( $q ) use ( $cutoff ) { return ( $q['time'] ?? '' ) >= $cutoff; } );
    update_option( 'wpilot_unanswered_questions', array_values( $uq ), false );
    // Training data is NEVER deleted — it makes the agent smarter over time
});
add_action( 'admin_init', function () {
    if ( get_option( 'wpilot_do_activation_redirect', false ) ) {
        delete_option( 'wpilot_do_activation_redirect' );
        wp_redirect( admin_url( 'admin.php?page=wpilot' ) );
        exit;
    }
});

// ══════════════════════════════════════════════════════════════
//  OAUTH 2.1 — MCP Authorization for Claude Desktop
// ══════════════════════════════════════════════════════════════

add_action( 'init', 'wpilot_oauth_well_known_handler', 1 );

add_action( 'init', 'wpilot_oauth_protected_resource_handler', 1 );

/**
 * Handle /.well-known/oauth-protected-resource (RFC 9728)
 * MCP spec: clients discover auth server via this endpoint
 */
function wpilot_oauth_protected_resource_handler() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url( $request_uri, PHP_URL_PATH );

    if ( $path !== '/.well-known/oauth-protected-resource' ) return;

    $site_url = get_site_url();

    $metadata = [
        'resource'              => $site_url . '/wp-json/wpilot/v1/mcp',
        'authorization_servers' => [ $site_url ],
        'scopes_supported'      => [ 'mcp' ],
        'bearer_methods_supported' => [ 'header' ],
    ];

    header( 'Content-Type: application/json' );
    header( 'Cache-Control: no-store' );
    header( 'Access-Control-Allow-Origin: *' );
    echo json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    exit;
}

add_action( 'init', 'wpilot_oauth_root_endpoints', 1 );

/**
 * Handle /.well-known/oauth-authorization-server
 * MCP spec: metadata endpoint MUST be at domain root level
 */
function wpilot_oauth_well_known_handler() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url( $request_uri, PHP_URL_PATH );

    if ( $path !== '/.well-known/oauth-authorization-server' ) return;

    $site_url = get_site_url();
    $metadata = wpilot_oauth_metadata( $site_url );

    header( 'Content-Type: application/json' );
    header( 'Cache-Control: no-store' );
    header( 'Access-Control-Allow-Origin: *' );
    echo json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    exit;
}

/**
 * Handle root-level /authorize, /token, /register endpoints
 * MCP spec: these MUST be at domain root for maximum compatibility
 */
function wpilot_oauth_root_endpoints() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url( $request_uri, PHP_URL_PATH );
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ( $path === '/authorize' && $method === 'GET' ) {
        wpilot_oauth_authorize_handler();
        exit;
    }

    if ( $path === '/authorize' && $method === 'POST' ) {
        wpilot_oauth_authorize_submit_handler();
        exit;
    }

    if ( $path === '/token' && $method === 'POST' ) {
        wpilot_oauth_token_handler();
        exit;
    }

    if ( $path === '/register' && $method === 'POST' ) {
        wpilot_oauth_register_handler();
        exit;
    }
}

/**
 * Build OAuth 2.0 Authorization Server Metadata (RFC8414)
 */
function wpilot_oauth_metadata( $site_url ) {
    return [
        'issuer'                                => $site_url,
        'authorization_endpoint'                => $site_url . '/authorize',
        'token_endpoint'                        => $site_url . '/token',
        'registration_endpoint'                 => $site_url . '/register',
        'response_types_supported'              => [ 'code' ],
        'grant_types_supported'                 => [ 'authorization_code' ],
        'token_endpoint_auth_methods_supported' => [ 'none', 'client_secret_post' ],
        'code_challenge_methods_supported'      => [ 'S256' ],
        'scopes_supported'                      => [ 'mcp' ],
    ];
}

/**
 * GET /authorize — Show login/authorize page
 */
function wpilot_oauth_authorize_handler() {
    $client_id     = sanitize_text_field( $_GET['client_id'] ?? '' );
    $redirect_uri  = esc_url_raw( $_GET['redirect_uri'] ?? '' );
    $response_type = sanitize_text_field( $_GET['response_type'] ?? '' );
    $state         = sanitize_text_field( $_GET['state'] ?? '' );
    $scope         = sanitize_text_field( $_GET['scope'] ?? 'mcp' );
    $code_challenge        = sanitize_text_field( $_GET['code_challenge'] ?? '' );
    $code_challenge_method = sanitize_text_field( $_GET['code_challenge_method'] ?? '' );

    if ( $response_type !== 'code' ) {
        wp_die( 'Invalid response_type. Must be "code".', 'OAuth Error', [ 'response' => 400 ] );
    }
    if ( empty( $client_id ) ) {
        wp_die( 'Missing client_id parameter.', 'OAuth Error', [ 'response' => 400 ] );
    }
    if ( empty( $redirect_uri ) ) {
        wp_die( 'Missing redirect_uri parameter.', 'OAuth Error', [ 'response' => 400 ] );
    }
    if ( empty( $code_challenge ) || $code_challenge_method !== 'S256' ) {
        wp_die( 'PKCE required. Provide code_challenge with method S256.', 'OAuth Error', [ 'response' => 400 ] );
    }

    // Validate redirect_uri: must be localhost, HTTPS, or custom scheme
    $parsed = parse_url( $redirect_uri );
    $host = $parsed['host'] ?? '';
    $scheme = $parsed['scheme'] ?? '';
    $is_localhost = in_array( $host, [ 'localhost', '127.0.0.1', '::1' ] );
    if ( ! $is_localhost && $scheme === 'http' ) {
        wp_die( 'redirect_uri must use HTTPS, localhost, or a custom scheme.', 'OAuth Error', [ 'response' => 400 ] );
    }

    // Validate client_id
    $clients = get_option( 'wpilot_oauth_clients', [] );
    $client_valid = false;
    foreach ( $clients as $c ) {
        if ( $c['client_id'] === $client_id ) { $client_valid = true; break; }
    }
    if ( ! $client_valid ) {
        wp_die( 'Unknown client_id. Register first via /register.', 'OAuth Error', [ 'response' => 400 ] );
    }

    // Built by Weblease

    if ( ! is_user_logged_in() ) {
        $authorize_url = add_query_arg( [
            'client_id' => $client_id, 'redirect_uri' => $redirect_uri,
            'response_type' => $response_type, 'state' => $state, 'scope' => $scope,
            'code_challenge' => $code_challenge, 'code_challenge_method' => $code_challenge_method,
        ], site_url( '/authorize' ) );
        wp_redirect( wp_login_url( $authorize_url ) );
        exit;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Only administrators can authorize MCP connections.', 'Access Denied', [ 'response' => 403 ] );
    }

    $site_name = get_bloginfo( 'name' ) ?: 'WordPress';
    wpilot_oauth_render_authorize_page( $site_name, $client_id, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method );
}

/**
 * POST /authorize — Handle Allow/Deny decision
 */
function wpilot_oauth_authorize_submit_handler() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.', 'Access Denied', [ 'response' => 403 ] );
    }
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wpilot_oauth_authorize' ) ) {
        wp_die( 'Invalid security token.', 'Security Error', [ 'response' => 403 ] );
    }

    $decision      = sanitize_text_field( $_POST['decision'] ?? '' );
    $client_id     = sanitize_text_field( $_POST['client_id'] ?? '' );
    $redirect_uri  = esc_url_raw( $_POST['redirect_uri'] ?? '' );
    $state         = sanitize_text_field( $_POST['state'] ?? '' );
    $scope         = sanitize_text_field( $_POST['scope'] ?? 'mcp' );
    $code_challenge        = sanitize_text_field( $_POST['code_challenge'] ?? '' );
    $code_challenge_method = sanitize_text_field( $_POST['code_challenge_method'] ?? '' );

    if ( empty( $redirect_uri ) ) {
        wp_die( 'Missing redirect_uri.', 'OAuth Error', [ 'response' => 400 ] );
    }

    if ( $decision !== 'allow' ) {
        wp_redirect( add_query_arg( [
            'error' => 'access_denied',
            'error_description' => 'User denied the request.',
            'state' => $state,
        ], $redirect_uri ) );
        exit;
    }

    $auth_code = bin2hex( random_bytes( 32 ) );
    $codes = get_option( 'wpilot_oauth_codes', [] );
    $codes = array_filter( $codes, function( $c ) { return $c['expires'] > time(); } );

    $codes[ $auth_code ] = [
        'client_id'             => $client_id,
        'redirect_uri'          => $redirect_uri,
        'scope'                 => $scope,
        'code_challenge'        => $code_challenge,
        'code_challenge_method' => $code_challenge_method,
        'user_id'               => get_current_user_id(),
        'expires'               => time() + 300,
    ];

    update_option( 'wpilot_oauth_codes', $codes, false );

    wp_redirect( add_query_arg( [
        'code'  => $auth_code,
        'state' => $state,
    ], $redirect_uri ) );
    exit;
}

/**
 * POST /token — Exchange auth code for access token
 */
function wpilot_oauth_token_handler() {
    header( 'Content-Type: application/json' );
    header( 'Cache-Control: no-store' );
    header( 'Access-Control-Allow-Origin: *' );

    $grant_type    = sanitize_text_field( $_POST['grant_type'] ?? '' );
    $code          = sanitize_text_field( $_POST['code'] ?? '' );
    $redirect_uri  = esc_url_raw( $_POST['redirect_uri'] ?? '' );
    $client_id     = sanitize_text_field( $_POST['client_id'] ?? '' );
    $code_verifier = sanitize_text_field( $_POST['code_verifier'] ?? '' );

    if ( $grant_type !== 'authorization_code' ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'unsupported_grant_type' ] );
        exit;
    }
    if ( empty( $code ) || empty( $code_verifier ) ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_request', 'error_description' => 'Missing code or code_verifier.' ] );
        exit;
    }

    $codes = get_option( 'wpilot_oauth_codes', [] );
    if ( ! isset( $codes[ $code ] ) ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'Invalid or expired code.' ] );
        exit;
    }

    $code_data = $codes[ $code ];
    unset( $codes[ $code ] );
    update_option( 'wpilot_oauth_codes', $codes, false );

    if ( $code_data['expires'] < time() ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'Code expired.' ] );
        exit;
    }
    if ( $code_data['client_id'] !== $client_id ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'Client mismatch.' ] );
        exit;
    }
    if ( $code_data['redirect_uri'] !== $redirect_uri ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'Redirect URI mismatch.' ] );
        exit;
    }

    // PKCE verification
    $expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
    if ( ! hash_equals( $code_data['code_challenge'], $expected ) ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ] );
        exit;
    }

    // Create access token (stored as wpilot token for backward compat)
    $raw_token = 'wpi_' . bin2hex( random_bytes( 32 ) );
    $hash = hash( 'sha256', $raw_token );
    $tokens = get_option( 'wpilot_tokens', [] );

    $tokens[] = [
        'hash'      => $hash,
        'style'     => 'technical',
        'label'     => 'OAuth: ' . substr( $client_id, 0, 24 ),
        'created'   => current_time( 'Y-m-d H:i' ),
        'last_used' => null,
        'oauth'     => true,
        'user_id'   => $code_data['user_id'],
    ];

    update_option( 'wpilot_tokens', $tokens );

    echo json_encode( [
        'access_token' => $raw_token,
        'token_type'   => 'Bearer',
        'scope'        => $code_data['scope'],
    ], JSON_UNESCAPED_SLASHES );
    exit;
}

/**
 * POST /register — Dynamic Client Registration (RFC7591)
 */
function wpilot_oauth_register_handler() {
    header( 'Content-Type: application/json' );
    header( 'Cache-Control: no-store' );
    header( 'Access-Control-Allow-Origin: *' );

    $body = json_decode( file_get_contents( 'php://input' ), true );
    if ( ! is_array( $body ) ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_request', 'error_description' => 'Invalid JSON.' ] );
        exit;
    }

    $client_name    = sanitize_text_field( $body['client_name'] ?? 'Unknown Client' );
    $redirect_uris  = $body['redirect_uris'] ?? [];
    $grant_types    = $body['grant_types'] ?? [ 'authorization_code' ];
    $response_types = $body['response_types'] ?? [ 'code' ];
    $auth_method    = sanitize_text_field( $body['token_endpoint_auth_method'] ?? 'none' );

    if ( ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'invalid_request', 'error_description' => 'redirect_uris required.' ] );
        exit;
    }

    $client_id = 'wpilot_' . bin2hex( random_bytes( 16 ) );
    $client_secret = bin2hex( random_bytes( 32 ) );
    $clients = get_option( 'wpilot_oauth_clients', [] );

    if ( count( $clients ) >= 50 ) { array_shift( $clients ); }

    $clients[] = [
        'client_id'                  => $client_id,
        'client_secret_hash'         => hash( 'sha256', $client_secret ),
        'client_name'                => $client_name,
        'redirect_uris'              => $redirect_uris,
        'grant_types'                => $grant_types,
        'response_types'             => $response_types,
        'token_endpoint_auth_method' => $auth_method,
        'registered_at'              => time(),
    ];

    update_option( 'wpilot_oauth_clients', $clients, false );

    http_response_code( 201 );
    echo json_encode( [
        'client_id'                  => $client_id,
        'client_secret'              => $client_secret,
        'client_name'                => $client_name,
        'redirect_uris'              => $redirect_uris,
        'grant_types'                => $grant_types,
        'response_types'             => $response_types,
        'token_endpoint_auth_method' => $auth_method,
    ], JSON_UNESCAPED_SLASHES );
    exit;
}

/**
 * Render premium authorize page — dark theme matching WPilot admin
 */
function wpilot_oauth_render_authorize_page( $site_name, $client_id, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method ) {
    $user = wp_get_current_user();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize — WPilot</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:linear-gradient(135deg,#0f0f1a 0%,#1a1a2e 40%,#16213e 70%,#0f3460 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#e2e8f0}
        .oc{background:rgba(255,255,255,.04);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:48px 44px;max-width:460px;width:100%;box-shadow:0 24px 80px rgba(0,0,0,.5),0 0 120px rgba(78,201,176,.05)}
        .ol{text-align:center;margin-bottom:32px}
        .ol-t{font-size:28px;font-weight:800;letter-spacing:-.5px}
        .ol-b{display:inline-block;background:rgba(78,201,176,.15);color:#4ec9b0;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;margin-left:8px;vertical-align:middle}
        .ox{display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:32px;padding:20px;background:rgba(255,255,255,.03);border-radius:16px;border:1px solid rgba(255,255,255,.06)}
        .oi{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;flex-shrink:0;color:#fff}
        .oi-c{background:linear-gradient(135deg,#d97706,#f59e0b)}
        .oi-w{background:linear-gradient(135deg,#4ec9b0,#22c55e)}
        .oa{color:#4ec9b0;font-size:24px}
        .ot{text-align:center;font-size:18px;font-weight:600;margin-bottom:8px;color:#f1f5f9}
        .os{text-align:center;font-size:14px;color:#94a3b8;margin-bottom:28px;line-height:1.5}
        .op{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:20px 24px;margin-bottom:28px}
        .op-t{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:600;margin-bottom:14px}
        .op-i{display:flex;align-items:center;gap:10px;padding:8px 0;font-size:14px;color:#cbd5e1}
        .op-c{color:#4ec9b0;font-size:16px;font-weight:700}
        .ou{display:flex;align-items:center;gap:12px;padding:14px 18px;background:rgba(78,201,176,.08);border:1px solid rgba(78,201,176,.15);border-radius:12px;margin-bottom:28px}
        .ou-a{width:36px;height:36px;border-radius:50%;background:#4ec9b0;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px}
        .ou-n{font-size:14px;font-weight:600;color:#e2e8f0}
        .ou-r{font-size:12px;color:#94a3b8}
        .ob{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .btn{padding:14px 24px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;border:none;transition:all .2s;text-align:center}
        .btn-d{background:rgba(255,255,255,.06);color:#94a3b8;border:1px solid rgba(255,255,255,.1)}
        .btn-d:hover{background:rgba(220,38,38,.15);color:#fca5a5;border-color:rgba(220,38,38,.3)}
        .btn-a{background:linear-gradient(135deg,#4ec9b0,#22c55e);color:#fff;box-shadow:0 4px 16px rgba(78,201,176,.3)}
        .btn-a:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(78,201,176,.4)}
        .of{text-align:center;margin-top:24px;font-size:12px;color:#64748b}
        .of a{color:#4ec9b0;text-decoration:none}
        @media(max-width:480px){.oc{padding:32px 24px}.ob{grid-template-columns:1fr}}
    
/* ── WordPress Admin Nuclear Overrides ── */
#wpbody-content .wpi h1, #wpbody-content .wpi h2, #wpbody-content .wpi h3, #wpbody-content .wpi h4 { padding: 0 !important; margin-top: 0 !important; }
#wpbody-content .wpi p { font-size: 14px !important; }
#wpbody-content .wpi a { color: #4ec9b0 !important; text-decoration: none !important; }
#wpbody-content .wpi a:hover { color: #2dd4a8 !important; }
#wpbody-content .wpi a:focus { box-shadow: none !important; outline: none !important; }
#wpbody-content .wpi input[type=text], #wpbody-content .wpi input[type=email], #wpbody-content .wpi textarea, #wpbody-content .wpi select { border-color: #dde3ea !important; box-shadow: none !important; border-radius: 10px !important; height: auto !important; min-height: 0 !important; line-height: 1.5 !important; }
#wpbody-content .wpi input[type=text]:focus, #wpbody-content .wpi input[type=email]:focus, #wpbody-content .wpi textarea:focus, #wpbody-content .wpi select:focus { border-color: #4ec9b0 !important; box-shadow: 0 0 0 4px rgba(78,201,176,0.15) !important; outline: none !important; }
#wpbody-content .wpi button { font-family: inherit !important; }
#wpbody-content .wpi table { border: none !important; }
#wpbody-content .wpi table th, #wpbody-content .wpi table td { border: none !important; background: transparent !important; }
#wpbody-content .wpi code { background: none !important; padding: 0 !important; }
#wpbody-content .wpi ul { margin: 0 !important; padding: 0 !important; }
#wpbody-content .wpi li { margin: 0 !important; }
#wpbody-content .wpi form { margin: 0 !important; padding: 0 !important; }
#wpbody-content .wpi .wpi-card a.wpi-btn { color: #fff !important; }
#wpbody-content .wpi .wpi-card a.wpi-btn-red { color: #dc2626 !important; }
#wpbody-content .wpi .wpi-card a.wpi-btn:hover { color: #fff !important; }
#wpbody-content .wpi .wpi-hero a { color: #4ec9b0 !important; }
#wpbody-content .wpi .wpi-plan ul li { padding: 7px 0 !important; font-size: 13px !important; color: #475569 !important; border-bottom: 1px solid #f1f5f9 !important; list-style: none !important; }
#wpbody-content .wpi .wpi-plan ul li:last-child { border: none !important; }
#wpbody-content .wpi .wpi-plan ul li::before { content: "\2713" !important; color: #4ec9b0 !important; font-weight: 700 !important; margin-right: 8px !important; }
#wpbody-content .wpi .wpi-radios label:has(input:checked) { border-color: #4ec9b0 !important; color: #047857 !important; background: #ecfdf5 !important; }
#wpbody-content .wpi .wpi-grid-2 { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 18px !important; }
@media(max-width:782px) { #wpbody-content .wpi .wpi-grid-2 { grid-template-columns: 1fr !important; } #wpbody-content .wpi .wpi-pricing { grid-template-columns: 1fr !important; } }
</style>
</head>
<body>
<div class="oc">
    <div class="ol"><span class="ol-t">WPilot</span><span class="ol-b">MCP</span></div>
    <div class="ox"><div class="oi oi-c">C</div><div class="oa">&#8594;</div><div class="oi oi-w">W</div></div>
    <div class="ot">Claude wants to connect</div>
    <div class="os">An application wants to manage<br><strong style="color:#4ec9b0"><?php echo esc_html( $site_name ); ?></strong> through WPilot</div>
    <div class="op">
        <div class="op-t">This will allow Claude to:</div>
        <div class="op-i"><span class="op-c">&#10003;</span> Read and modify content, pages, and posts</div>
        <div class="op-i"><span class="op-c">&#10003;</span> Manage WooCommerce products and orders</div>
        <div class="op-i"><span class="op-c">&#10003;</span> Configure site settings and SEO</div>
        <div class="op-i"><span class="op-c">&#10003;</span> Customize design, themes, and layouts</div>
    </div>
    <div class="ou">
        <div class="ou-a"><?php echo esc_html( strtoupper( substr( $user->display_name ?: $user->user_login, 0, 1 ) ) ); ?></div>
        <div><div class="ou-n"><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></div><div class="ou-r">Administrator</div></div>
    </div>
    <form method="post" action="<?php echo esc_url( site_url( '/authorize' ) ); ?>">
        <?php wp_nonce_field( 'wpilot_oauth_authorize' ); ?>
        <input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
        <input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
        <input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
        <input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
        <input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
        <input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">
        <div class="ob">
            <button type="submit" name="decision" value="deny" class="btn btn-d">Deny</button>
            <button type="submit" name="decision" value="allow" class="btn btn-a">Allow</button>
        </div>
    </form>
    <div class="of">Powered by <a href="https://weblease.se/wpilot" target="_blank">WPilot</a> &mdash; Weblease</div>
</div>
</body>
</html>
<?php
    exit;
}
// ══════════════════════════════════════════════════════════════
//  HEARTBEAT — Is Claude Code online?
// ══════════════════════════════════════════════════════════════

function wpilot_claude_is_online() {
    $last = intval( get_option( 'wpilot_claude_last_seen', 0 ) );
    return ( time() - $last ) < 45;
}

// ══════════════════════════════════════════════════════════════
//  AUTO LICENSE MATCH — Check weblease.se on first connection
// ══════════════════════════════════════════════════════════════

function wpilot_auto_match_license() {
    // Only try once per 24 hours
    if ( get_transient( 'wpilot_auto_match_checked' ) ) return;
    set_transient( 'wpilot_auto_match_checked', '1', DAY_IN_SECONDS );

    // Skip if a license key is already saved
    if ( get_option( 'wpilot_license_key', '' ) !== '' ) return;

    $email    = get_option( 'admin_email', '' );
    $site_url = get_site_url();

    if ( empty( $email ) || empty( $site_url ) ) return;

    // Built by Weblease
    $response = wp_remote_post( 'https://weblease.se/ai-license/auto-match', [
        'timeout' => 10,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'email'          => $email,
            'site_url'       => $site_url,
            'plugin_version' => WPILOT_VERSION,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) return;

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['valid'] ) && ! empty( $body['license_key'] ) ) {
        update_option( 'wpilot_license_key', sanitize_text_field( $body['license_key'] ) );
        set_transient( 'wpilot_license_status', 'valid', 3600 );
        if ( ! empty( $body['chat_agent'] ) ) {
            set_transient( 'wpilot_chat_agent_licensed', 'yes', 3600 );
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  LICENSE — Validate against weblease.se
// ══════════════════════════════════════════════════════════════

function wpilot_check_license() {
    $key = get_option( 'wpilot_license_key', '' );
    if ( empty( $key ) ) return 'free';

    $cached = get_transient( 'wpilot_license_status' );
    if ( $cached !== false ) return $cached;

    $response = wp_remote_post( 'https://weblease.se/ai-license/validate', [
        'timeout' => 10,
        'body'    => [
            'license_key' => $key,
            'site_url'    => get_site_url(),
            'plugin'      => 'wpilot',
            'version'     => WPILOT_VERSION,
        ],
    ] );

    // Built by Weblease
    if ( is_wp_error( $response ) ) {
        $last = get_transient( 'wpilot_license_status' );
        $fallback = $last !== false ? $last : 'free';
        set_transient( 'wpilot_license_status', $fallback, 300 );
        return $fallback;
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $status = ( $body['valid'] ?? false ) ? 'valid' : 'expired';
    set_transient( 'wpilot_license_status', $status, 3600 );

    // Chat Agent — separate addon license ($20/mo)
    if ( $status === 'valid' && ! empty( $body['chat_agent'] ) ) {
        set_transient( 'wpilot_chat_agent_licensed', 'yes', 3600 );
    }

    return $status;
}

function wpilot_is_licensed() {
    return wpilot_check_license() === 'valid';
}

//  REST ROUTE
// ══════════════════════════════════════════════════════════════

function wpilot_connection_status() {
    $online = wpilot_claude_is_online();
    $last = intval( get_option( 'wpilot_claude_last_seen', 0 ) );
    return new WP_REST_Response([
        'connected' => $online,
        'last_seen' => $last > 0 ? human_time_diff( $last ) . ' ago' : 'never',
    ]);
}

function wpilot_register_routes() {
    register_rest_route( 'wpilot/v1', '/connection-status', [
        'methods'             => 'GET',
        'callback'            => 'wpilot_connection_status',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route( 'wpilot/v1', '/mcp', [
        'methods'             => [ 'GET', 'POST', 'DELETE' ],
        'callback'            => 'wpilot_mcp_endpoint',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'wpilot/v1', '/chat', [
        'methods'             => [ 'POST', 'OPTIONS' ],
        'callback'            => 'wpilot_chat_endpoint',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'wpilot/v1', '/chat-status', [
        'methods'             => 'GET',
        'callback'            => 'wpilot_chat_status',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'wpilot/v1', '/chat-poll', [
        'methods'             => [ 'POST', 'OPTIONS' ],
        'callback'            => 'wpilot_chat_poll',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'wpilot/v1', '/chat-upload', [
        'methods'             => [ 'POST', 'OPTIONS' ],
        'callback'            => 'wpilot_chat_upload',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'wpilot/v1', '/chat-email', [
        'methods'             => 'POST',
        'callback'            => 'wpilot_chat_collect_email',
        'permission_callback' => '__return_true',
    ]);
}

// ══════════════════════════════════════════════════════════════
//  TOKEN SYSTEM — Multiple tokens with styles
// ══════════════════════════════════════════════════════════════

function wpilot_get_tokens() {
    return get_option( 'wpilot_tokens', [] );
}

function wpilot_save_tokens( $tokens ) {
    update_option( 'wpilot_tokens', $tokens );
}

function wpilot_create_token( $style, $label ) {
    $raw   = 'wpi_' . bin2hex( random_bytes( 32 ) );
    $hash  = hash( 'sha256', $raw );
    $tokens = wpilot_get_tokens();

    $tokens[] = [
        'hash'      => $hash,
        'style'     => $style,
        'label'     => $label,
        'created'   => current_time( 'Y-m-d H:i' ),
        'last_used' => null,
    ];

    wpilot_save_tokens( $tokens );
    return $raw;
}

function wpilot_validate_token( $raw ) {
    if ( empty( $raw ) ) return false;

    $hash   = hash( 'sha256', $raw );
    $tokens = wpilot_get_tokens();

    foreach ( $tokens as $i => $t ) {
        if ( hash_equals( $t['hash'], $hash ) ) {
            $tokens[ $i ]['last_used'] = current_time( 'Y-m-d H:i' );
            wpilot_save_tokens( $tokens );
            return $t;
        }
    }

    return false;
}

function wpilot_revoke_token( $hash ) {
    $tokens = wpilot_get_tokens();
    $tokens = array_values( array_filter( $tokens, fn( $t ) => $t['hash'] !== $hash ) );
    wpilot_save_tokens( $tokens );
}

function wpilot_get_bearer_token() {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

    if ( function_exists( 'apache_request_headers' ) && empty( $header ) ) {
        $h = apache_request_headers();
        $header = $h['Authorization'] ?? $h['authorization'] ?? '';
    }

    return preg_match( '/^Bearer\s+(.+)$/i', $header, $m ) ? trim( $m[1] ) : '';
}

// ══════════════════════════════════════════════════════════════
//  DAILY USAGE — 5 requests per day, resets at midnight
// ══════════════════════════════════════════════════════════════

function wpilot_get_daily_usage() {
    $data  = get_option( 'wpilot_daily_usage', [] );
    $today = current_time( 'Y-m-d' );

    if ( ( $data['date'] ?? '' ) !== $today ) {
        $data = [ 'date' => $today, 'count' => 0 ];
        update_option( 'wpilot_daily_usage', $data, false );
    }

    return $data;
}

function wpilot_increment_usage() {
    $data = wpilot_get_daily_usage();
    $data['count'] = intval( $data['count'] ) + 1;
    update_option( 'wpilot_daily_usage', $data, false );
    return $data['count'];
}

// ══════════════════════════════════════════════════════════════
//  SERVER — JSON-RPC 2.0
// ══════════════════════════════════════════════════════════════

function wpilot_mcp_endpoint( $request ) {
    header( 'Cache-Control: no-store' );
    header( 'X-Content-Type-Options: nosniff' );

    $method = $request->get_method();

    if ( $method === 'GET' ) {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'error'   => [ 'code' => -32600, 'message' => esc_html__( 'Invalid request method.', 'wpilot' ) ],
        ], 200 );
    }

    if ( $method === 'DELETE' ) {
        return new WP_REST_Response( null, 204 );
    }

    // Auth
    $raw_token  = wpilot_get_bearer_token();
    $token_data = wpilot_validate_token( $raw_token );

    if ( ! $token_data ) {
        $site_url = get_site_url();
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'error'   => [ 'code' => -32000, 'message' => esc_html__( 'Unauthorized — invalid or missing API token.', 'wpilot' ) ],
            'id'      => null,
        ], 401, [
            'WWW-Authenticate' => 'Bearer resource_metadata="' . $site_url . '/.well-known/oauth-protected-resource"',
        ] );
    }

    $style = $token_data['style'] ?? 'simple';

    // Heartbeat
    update_option( 'wpilot_claude_last_seen', time(), false );

    // Auto-match license on first MCP connection
    wpilot_auto_match_license();

    // Connection tracking + client detection
    wpilot_track_connection( $token_data );
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $client = 'unknown';
    if ( stripos( $ua, 'claude-desktop' ) !== false || stripos( $ua, 'ClaudeDesktop' ) !== false || stripos( $ua, 'Electron' ) !== false ) {
        $client = 'desktop';
    } elseif ( stripos( $ua, 'claude-code' ) !== false || stripos( $ua, 'claude/' ) !== false || stripos( $ua, 'node' ) !== false || stripos( $ua, 'npx' ) !== false ) {
        $client = 'code';
    }
    if ( $client !== 'unknown' ) {
        update_option( 'wpilot_client_type', $client, false );
    }

    // Rate limit: per-token (60 req/min) + per-IP (120 req/min)
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $tk_key = 'wpilot_rl_' . substr( $token_data['hash'], 0, 16 );
    $ip_key = 'wpilot_rl_ip_' . md5( $ip );
    $tk_count = intval( get_transient( $tk_key ) ?: 0 );
    $ip_count = intval( get_transient( $ip_key ) ?: 0 );
    if ( $tk_count >= 60 || $ip_count >= 120 ) {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'error'   => [ 'code' => -32000, 'message' => esc_html__( 'Rate limit exceeded. Try again in a minute.', 'wpilot' ) ],
            'id'      => null,
        ], 429 );
    }
    set_transient( $tk_key, $tk_count + 1, 60 );
    set_transient( $ip_key, $ip_count + 1, 60 );

    // Parse JSON-RPC
    $body   = $request->get_json_params();
    $rpc    = $body['method'] ?? '';
    $params = $body['params'] ?? [];
    $id     = $body['id'] ?? null;

    switch ( $rpc ) {
        case 'initialize':
            return wpilot_rpc_ok( $id, [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => [ 'tools' => (object)[] ],
                'serverInfo'      => [ 'name' => 'wpilot', 'version' => WPILOT_VERSION ],
                'instructions'    => wpilot_system_prompt( $style ),
            ]);

        case 'notifications/initialized':
            return new WP_REST_Response( null, 204 );

        case 'tools/list':
            return wpilot_rpc_ok( $id, [ 'tools' => [ wpilot_tool_definition() ] ] );

        case 'tools/call':
            return wpilot_handle_execute( $id, $params, $style );

        default:
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'error'   => [ 'code' => -32601, 'message' => "Unknown method: {$rpc}" ],
                'id'      => $id,
            ], 200 );
    }
}

function wpilot_rpc_ok( $id, $result ) {
    return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'result' => $result, 'id' => $id ], 200 );
}

function wpilot_rpc_tool_result( $id, $text, $is_error ) {
    return new WP_REST_Response([
        'jsonrpc' => '2.0',
        'result'  => [ 'content' => [ [ 'type' => 'text', 'text' => $text ] ], 'isError' => $is_error ],
        'id'      => $id,
    ], 200 );
}

// ══════════════════════════════════════════════════════════════
//  THE ONE TOOL: wordpress
// ══════════════════════════════════════════════════════════════

function wpilot_tool_definition() {
    return [
        'name'        => 'wordpress',
        'description' => 'Run WordPress PHP code on this site. All WP functions are available (get_posts, wp_insert_post, get_option, update_option, WP_Query, WooCommerce, etc). The code executes inside WordPress with the full API loaded. Use return to send data back to the conversation.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'PHP code to run. No opening php tag needed. Use return to output data. Examples: return get_posts(["numberposts"=>5]); or return get_option("blogname"); or wp_update_post(["ID"=>1,"post_title"=>"New Title"]); return "Done";',
                ],
            ],
            'required' => [ 'action' ],
        ],
    ];
}

// ══════════════════════════════════════════════════════════════
//  EXECUTE — Core engine with full security blocks
// ══════════════════════════════════════════════════════════════

function wpilot_sandbox_violation($id, $message) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'wpilot_attacks_' . md5($ip);
    $count = intval(get_transient($key) ?: 0) + 1;
    set_transient($key, $count, 3600);
    if ($count >= 5) set_transient('wpilot_ban_' . md5($ip), true, 86400);
    return wpilot_rpc_tool_result($id, $message, true);
}

function wpilot_handle_execute( $id, $params, $style = 'simple' ) {
    $code = $params['arguments']['action'] ?? $params['arguments']['code'] ?? '';

    // Check if IP is banned
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ban_key = 'wpilot_ban_' . md5($ip);
    if (get_transient($ban_key)) {
        return wpilot_rpc_tool_result($id, 'Access temporarily blocked.', true);
    }

    if ( empty( $code ) ) {
        return wpilot_rpc_tool_result( $id, __( 'No action provided.', 'wpilot' ), true );
    }

    // License check — licensed users skip daily limit
    $licensed = wpilot_is_licensed();

    if ( ! $licensed ) {
        $usage = wpilot_get_daily_usage();
        $used  = intval( $usage['count'] );

        if ( $used >= WPILOT_FREE_LIMIT ) {
            return wpilot_rpc_tool_result( $id,
                sprintf(
                    __( "You've used all %d free requests today. Get unlimited requests — plans start at $9/month.\n\nUpgrade: https://weblease.se/wpilot-checkout\nOr paste a license key in your WordPress admin: WPilot > Plan", 'wpilot' ),
                    WPILOT_FREE_LIMIT
                ),
                true
            );
        }
        wpilot_increment_usage();
    }

    // Size limit (50KB max)
    if ( strlen( $code ) > 51200 ) {
        return wpilot_rpc_tool_result( $id, 'Action too large. Break it into smaller steps.', true );
    }

    // ══════════════════════════════════════════════════════════
    //  SANDBOX — Customers can do EVERYTHING except:
    //  1. Shell commands  2. Read credentials  3. Tamper with WPilot
    //  Everything else is ALLOWED — full WordPress admin power.
    // ══════════════════════════════════════════════════════════

    // 1. SHELL — never
    if ( preg_match( '/\b(exec|shell_exec|system|passthru|popen|proc_open|pcntl_exec|pcntl_fork)\s*\(/i', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Shell commands are not allowed.' );
    }

    // 2. CODE INJECTION — no eval/include
    if ( preg_match( '/\beval\s*\(|\bassert\s*\(|\bcreate_function\s*\(/', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Dynamic code execution is not allowed.' );
    }
    if ( preg_match( '/\b(include|require|include_once|require_once)\s*[\s(]/i', $code ) ) {
        return wpilot_sandbox_violation( $id, 'File inclusion is not allowed.' );
    }

    // 3. SUPERGLOBALS
    if ( preg_match( '/\$_(SERVER|ENV|REQUEST|COOKIE|SESSION)\b/', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Use WordPress functions instead of superglobals.' );
    }

    // 4. SERVER INFO
    if ( preg_match( '/\b(phpinfo|php_uname|getenv|get_defined_constants|get_defined_vars)\s*\(/i', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Server info functions are not allowed.' );
    }

    // 5. CREDENTIALS — wp-config secrets
    $secrets = ['DB_PASSWORD','DB_USER','DB_HOST','DB_NAME','AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'];
    foreach ( $secrets as $s ) { if ( strpos( $code, $s ) !== false ) return wpilot_sandbox_violation( $id, 'Credential access is not allowed.' ); }

    // 6. WPILOT INTERNALS — can't tamper with the plugin
    $protected = ['wpilot_tokens','wpilot_license_key','wpilot_license_status','wpilot_chat_key','wpilot_chat_agent_licensed','wpilot_daily_usage','wpilot_oauth_clients','wpilot_oauth_codes','wp-config.php','.htaccess'];
    foreach ( $protected as $p ) { if ( strpos( $code, $p ) !== false ) return wpilot_sandbox_violation( $id, 'WPilot internals are protected.' ); }

    // 7. DESTRUCTIVE SQL
    if ( preg_match( '/\bquery\s*\(.*\b(DROP|TRUNCATE|ALTER|GRANT|REVOKE)\b/i', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Destructive SQL is not allowed.' );
    }

    // 8. RAW SOCKETS (wp_remote_get/post ARE allowed)
    if ( preg_match( '/\b(curl_init|fsockopen|stream_socket|socket_create)\s*\(/i', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Use wp_remote_get() or wp_remote_post() instead of raw sockets.' );
    }

    // 9. CALLBACK ABUSE — "system" as string argument
    $dangerous_strings = ['exec','shell_exec','system','passthru','popen','proc_open','phpinfo','eval','assert'];
    foreach ( $dangerous_strings as $ds ) {
        if ( preg_match( '/["\x27]' . preg_quote( $ds, '/' ) . '["\x27]/i', $code ) ) {
            return wpilot_sandbox_violation( $id, 'Blocked for security reasons.' );
        }
    }

    // 10. CLOSURE BYPASS
    if ( preg_match( '/Closure\s*::\s*fromCallable|new\s+class\b/i', $code ) ) {
        return wpilot_sandbox_violation( $id, 'Blocked for security reasons.' );
    }

    // 11. STRING CONCATENATION BYPASS
    $dangerous_funcs = ['exec','shell_exec','system','passthru','popen','proc_open','phpinfo','eval','assert','create_function'];

    // Built by Weblease
    // EVERYTHING ELSE IS ALLOWED:
    // - All WordPress functions (get_posts, wp_insert_post, wp_update_post, etc.)
    // - File operations (file_put_contents, fopen, etc.) — for themes, uploads, etc.
    // - array_map, array_filter, usort with closures
    // - wp_remote_get, wp_remote_post for external APIs
    // - WooCommerce functions
    // - Media uploads (media_sideload_image, wp_upload_bits, etc.)
    // - Theme/plugin management
    // - User management
    // - Database queries (SELECT, INSERT, UPDATE, DELETE)

    // Block backtick execution
    if ( preg_match( '/`[^`]+`/', $code ) ) {
        return wpilot_sandbox_violation( $id, __( 'This action is not allowed.', 'wpilot' ) );
    }

    // Block variable variables ($$var)
    if ( preg_match( '/\$\$[a-zA-Z_]/', $code ) ) {
        return wpilot_sandbox_violation( $id, __( 'This action is not allowed.', 'wpilot' ) );
    }

    // Block curly brace variable access ${...}
    if ( preg_match( '/\$\{/', $code ) ) {
        return wpilot_sandbox_violation( $id, __( 'This action is not allowed.', 'wpilot' ) );
    }

    // Block string concatenation of dangerous function names
    foreach ( $dangerous_funcs as $func ) {
        if ( strlen( $func ) < 4 ) continue;
        $stripped = str_replace( [ '"', "'", '.', ' ' ], '', $code );
        if ( stripos( $stripped, $func . '(' ) !== false && ! preg_match( '/\b' . preg_quote( $func ) . '\s*\(/i', $code ) ) {
            return wpilot_sandbox_violation( $id, __( 'This action is not allowed.', 'wpilot' ) );
        }
    }

    // ── Execute ──
    // NOTE: Intentional use of eval() — this is the core MCP feature.
    // Authenticated users (token + rate limited) can run WordPress PHP.
    // Security: token auth, rate limiting, blocked patterns, no shell/fs.
    @set_time_limit( 30 );
    ob_start();
    $return_value = null;
    $error = null;

    try {
        $fn = function() use ( $code ) {
            return eval( $code );
        };
        $return_value = $fn();
    } catch ( \Throwable $e ) {
        $error = $e->getMessage();
    }

    $output = ob_get_clean();

    if ( $error ) {
        $clean_error = $error;
        $clean_error = preg_replace( '/\s+on line \d+/', '', $clean_error );
        $clean_error = preg_replace( '/\s+in\s+\/[^\s]+/', '', $clean_error );
        $clean_error = preg_replace( '/\/[a-z0-9\/._-]*\.(php|html|js)/i', '[path]', $clean_error );
        $clean_error = preg_replace( '/Stack trace:[\s\S]*$/', '', $clean_error );
        $clean_error = trim( preg_replace( '/\s+/', ' ', $clean_error ) );
        if ( strlen( $clean_error ) > 200 ) $clean_error = substr( $clean_error, 0, 200 ) . '...';
        return wpilot_rpc_tool_result( $id, "Something went wrong, please try again. ({$clean_error})", true );
    }

    $result = '';
    if ( $return_value !== null && $return_value !== '' ) {
        $result = is_array( $return_value ) || is_object( $return_value )
            ? json_encode( $return_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
            : (string) $return_value;
    }
    if ( ! empty( $output ) ) {
        $result = $result ? $result . "\n\nOutput:\n" . $output : $output;
    }
    if ( empty( $result ) ) {
        $result = __( 'Done.', 'wpilot' );
    }
    if ( strlen( $result ) > 50000 ) {
        $result = substr( $result, 0, 50000 ) . "\n\n[Truncated]";
    }

    // Show remaining daily usage in response
    if ( ! $licensed ) {
        $usage = wpilot_get_daily_usage();
        $remaining = max( 0, WPILOT_FREE_LIMIT - intval( $usage['count'] ) );
        $result .= "\n\n[WPilot: {$remaining}/" . WPILOT_FREE_LIMIT . " requests remaining today]";
    }

    // ── AI Training — log what Claude does (anonymized) ──
    wpilot_log_mcp_action( $code, $result, $error ? true : false );

    // ── Chat Agent Piggyback — inject pending visitor messages ──
    if ( $licensed && get_option( 'wpilot_chat_enabled', false ) ) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'wpilot_chat_queue';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table ) {
            $pending = $wpdb->get_results(
                "SELECT id, session_id, message, created_at FROM {$queue_table} WHERE source = 'pending' AND response IS NULL ORDER BY created_at ASC LIMIT 10"
            );
            if ( ! empty( $pending ) ) {
                $agent_name = get_option( 'wpilot_agent_name', '' ) ?: 'WPilot';
                $result .= "\n\n" . str_repeat( '=', 50 );
                $result .= "\nPENDING CUSTOMER MESSAGES — RESPOND AS \"{$agent_name}\"";
                $result .= "\n" . str_repeat( '=', 50 );
                $result .= "\nRespond to each message by calling the wpilot tool with:";
                $result .= "\n\$wpdb->update('{$queue_table}', ['response' => 'YOUR ANSWER', 'source' => 'claude'], ['id' => MESSAGE_ID]);";
                $result .= "\n";
                foreach ( $pending as $msg ) {
                    $result .= "\n[ID: {$msg->id}] [{$msg->created_at}] Visitor: " . esc_html( $msg->message );
                }
                $result .= "\n\nIMPORTANT: Answer in the visitor's language. Be concise (1-3 sentences). Use real data from the website.";
            }
        }
    }

    return wpilot_rpc_tool_result( $id, $result, false );
}

// ══════════════════════════════════════════════════════════════
//  CONNECTION TRACKING — Log MCP connections
// ══════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
//  AI TRAINING — Collect how Claude works for future AI model
// ══════════════════════════════════════════════════════════════

function wpilot_log_mcp_action( $code, $result, $is_error ) {
    // Only log if site has opted in
    if ( ! get_option( 'wpilot_training_opt_in', false ) ) return;

    // Anonymize — strip specific data, keep patterns
    $clean_code = wpilot_anonymize_for_training( $code );
    $clean_result = mb_substr( $result, 0, 500 );

    $log = get_option( 'wpilot_mcp_training_log', [] );
    $log[] = [
        'code'     => $clean_code,
        'result'   => $clean_result,
        'error'    => $is_error,
        'wp_funcs' => wpilot_extract_wp_functions( $code ),
        'time'     => current_time( 'Y-m-d H:i' ),
    ];

    // Keep buffer of 100 — flush to weblease.se when full
    if ( count( $log ) >= 100 ) {
        wpilot_flush_training_data( $log );
        $log = [];
    }

    update_option( 'wpilot_mcp_training_log', $log, false );
}

function wpilot_extract_wp_functions( $code ) {
    // Extract WordPress function names used — this is the gold
    preg_match_all( '/\b(wp_[a-z_]+|get_[a-z_]+|update_[a-z_]+|delete_[a-z_]+|wc_[a-z_]+)\s*\(/', $code, $matches );
    return array_unique( $matches[1] ?? [] );
}

function wpilot_anonymize_for_training( $code ) {
    // Remove specific content but keep structure
    $clean = $code;
    // Replace string literals with placeholders
    $clean = preg_replace( '/(["\x27])(?:(?!\1).)*\1/', '$1[STR]$1', $clean );
    // Replace numbers
    $clean = preg_replace( '/\b\d{4,}\b/', '[NUM]', $clean );
    // Replace emails
    $clean = preg_replace( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $clean );
    // Replace URLs
    $clean = preg_replace( '/https?:\/\/[^\s\'"]+/', '[URL]', $clean );
    return mb_substr( $clean, 0, 1000 );
}

// Built by Weblease

function wpilot_flush_training_data( $log ) {
    $license_key = get_option( 'wpilot_license_key', '' );
    if ( empty( $license_key ) ) return;

    // Convert to ingest format: { code, result, success }
    $entries = [];
    foreach ( $log as $item ) {
        $entries[] = [
            'code'       => $item['code'] ?? '',
            'result'     => $item['result'] ?? '',
            'success'    => empty( $item['error'] ),
            'wp_version' => get_bloginfo( 'version' ),
            'theme'      => wp_get_theme()->get( 'Name' ),
            'woo'        => class_exists( 'WooCommerce' ) ? 'yes' : 'no',
        ];
    }

    wp_remote_post( 'https://weblease.se/api/ai-training/ingest', [
        'timeout'  => 15,
        'blocking' => false,
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'body'     => wp_json_encode( [
            'license_key'    => $license_key,
            'entries'        => $entries,
            'site_hash'      => md5( get_site_url() ),
            'plugin_version' => WPILOT_VERSION,
        ] ),
    ] );
}

function wpilot_track_connection( $token_data ) {
    $connections = get_option( 'wpilot_connections', [] );
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $label = $token_data['label'] ?? 'Unknown';
    $now = current_time( 'Y-m-d H:i:s' );

    $found = false;
    foreach ( $connections as $i => $conn ) {
        if ( $conn['token_label'] === $label && $conn['ip'] === $ip ) {
            $connections[ $i ]['last_seen'] = $now;
            $found = true;
            break;
        }
    }

    if ( ! $found ) {
        $connections[] = [
            'token_label'   => $label,
            'connected_at'  => $now,
            'last_seen'     => $now,
            'ip'            => $ip,
        ];
    }

    if ( count( $connections ) > 50 ) {
        $connections = array_slice( $connections, -50 );
    }

    update_option( 'wpilot_connections', $connections, false );
}

// ══════════════════════════════════════════════════════════════
//  BUILDER DETECTION — Detect page builders in use
// ══════════════════════════════════════════════════════════════

function wpilot_detect_builders() {
    $builders = [];
    $active_plugins = get_option( 'active_plugins', [] );

    if ( defined( 'ELEMENTOR_VERSION' ) || in_array( 'elementor/elementor.php', $active_plugins ) ) {
        $builders[] = 'elementor';
    }
    if ( defined( 'ELEMENTOR_PRO_VERSION' ) || in_array( 'elementor-pro/elementor-pro.php', $active_plugins ) ) {
        $builders[] = 'elementor-pro';
    }
    if ( defined( 'ET_BUILDER_VERSION' ) || wp_get_theme()->get_template() === 'Divi' || in_array( 'divi-builder/divi-builder.php', $active_plugins ) ) {
        $builders[] = 'divi';
    }
    if ( defined( 'WPB_VC_VERSION' ) || in_array( 'js_composer/js_composer.php', $active_plugins ) ) {
        $builders[] = 'wpbakery';
    }
    if ( defined( 'FL_BUILDER_VERSION' ) || in_array( 'beaver-builder-lite-version/fl-builder.php', $active_plugins ) ) {
        $builders[] = 'beaver';
    }
    if ( defined( 'CT_VERSION' ) || in_array( 'oxygen/functions.php', $active_plugins ) ) {
        $builders[] = 'oxygen';
    }
    if ( defined( 'BRIZY_VERSION' ) || in_array( 'brizy/brizy.php', $active_plugins ) ) {
        $builders[] = 'brizy';
    }
    if ( in_array( 'breakdance/plugin.php', $active_plugins ) ) {
        $builders[] = 'breakdance';
    }
    if ( in_array( 'kadence-blocks/kadence-blocks.php', $active_plugins ) ) {
        $builders[] = 'kadence';
    }
    if ( in_array( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php', $active_plugins ) ) {
        $builders[] = 'spectra';
    }
    if ( in_array( 'generateblocks/plugin.php', $active_plugins ) || wp_get_theme()->get_template() === 'generatepress' ) {
        $builders[] = 'generateblocks';
    }

    // Built by Weblease
    if ( empty( $builders ) ) {
        $builders[] = 'gutenberg';
    }

    return $builders;
}

// ══════════════════════════════════════════════════════════════
//  PLUGIN DETECTION — Detect installed plugins
// ══════════════════════════════════════════════════════════════

function wpilot_detect_plugins() {
    $active = get_option( 'active_plugins', [] );
    $found = [];

    $known = [
        'woocommerce/woocommerce.php' => ['name' => 'WooCommerce', 'cat' => 'ecommerce'],
        'woo-gutenberg-products-block/woocommerce-gutenberg-products-block.php' => ['name' => 'WooCommerce Blocks', 'cat' => 'ecommerce'],
        'wordpress-seo/wp-seo.php' => ['name' => 'Yoast SEO', 'cat' => 'seo'],
        'seo-by-rank-math/rank-math.php' => ['name' => 'Rank Math', 'cat' => 'seo'],
        'all-in-one-seo-pack/all_in_one_seo_pack.php' => ['name' => 'All in One SEO', 'cat' => 'seo'],
        'the-seo-framework-extension-manager/the-seo-framework-extension-manager.php' => ['name' => 'The SEO Framework', 'cat' => 'seo'],
        'contact-form-7/wp-contact-form-7.php' => ['name' => 'Contact Form 7', 'cat' => 'forms'],
        'wpforms-lite/wpforms.php' => ['name' => 'WPForms', 'cat' => 'forms'],
        'gravityforms/gravityforms.php' => ['name' => 'Gravity Forms', 'cat' => 'forms'],
        'forminator/forminator.php' => ['name' => 'Forminator', 'cat' => 'forms'],
        'fluentform/fluentform.php' => ['name' => 'Fluent Forms', 'cat' => 'forms'],
        'wordfence/wordfence.php' => ['name' => 'Wordfence', 'cat' => 'security'],
        'better-wp-security/better-wp-security.php' => ['name' => 'iThemes Security', 'cat' => 'security'],
        'sucuri-scanner/sucuri.php' => ['name' => 'Sucuri', 'cat' => 'security'],
        'all-in-one-wp-security-and-firewall/wp-security.php' => ['name' => 'All In One Security', 'cat' => 'security'],
        'litespeed-cache/litespeed-cache.php' => ['name' => 'LiteSpeed Cache', 'cat' => 'cache'],
        'w3-total-cache/w3-total-cache.php' => ['name' => 'W3 Total Cache', 'cat' => 'cache'],
        'wp-super-cache/wp-cache.php' => ['name' => 'WP Super Cache', 'cat' => 'cache'],
        'wp-fastest-cache/wpFastestCache.php' => ['name' => 'WP Fastest Cache', 'cat' => 'cache'],
        'autoptimize/autoptimize.php' => ['name' => 'Autoptimize', 'cat' => 'cache'],
        'wp-optimize/wp-optimize.php' => ['name' => 'WP-Optimize', 'cat' => 'cache'],
        'sitepress-multilingual-cms/sitepress.php' => ['name' => 'WPML', 'cat' => 'multilingual'],
        'polylang/polylang.php' => ['name' => 'Polylang', 'cat' => 'multilingual'],
        'translatepress-multilingual/index.php' => ['name' => 'TranslatePress', 'cat' => 'multilingual'],
        'updraftplus/updraftplus.php' => ['name' => 'UpdraftPlus', 'cat' => 'backup'],
        'duplicator/duplicator.php' => ['name' => 'Duplicator', 'cat' => 'backup'],
        'advanced-custom-fields/acf.php' => ['name' => 'ACF', 'cat' => 'fields'],
        'advanced-custom-fields-pro/acf.php' => ['name' => 'ACF Pro', 'cat' => 'fields'],
        'wp-mail-smtp/wp_mail_smtp.php' => ['name' => 'WP Mail SMTP', 'cat' => 'email'],
        'fluent-smtp/fluent-smtp.php' => ['name' => 'FluentSMTP', 'cat' => 'email'],
        'ameliabooking/ameliabooking.php' => ['name' => 'Amelia Booking', 'cat' => 'booking'],
        'bookly-responsive-appointment-booking-tool/main.php' => ['name' => 'Bookly', 'cat' => 'booking'],
        'mailchimp-for-wp/mailchimp-for-wp.php' => ['name' => 'Mailchimp for WP', 'cat' => 'marketing'],
        'official-facebook-pixel/facebook-for-wordpress.php' => ['name' => 'Meta Pixel', 'cat' => 'marketing'],
        'revslider/revslider.php' => ['name' => 'Slider Revolution', 'cat' => 'media'],
        'smart-slider-3/smart-slider-3.php' => ['name' => 'Smart Slider', 'cat' => 'media'],
        'memberpress/memberpress.php' => ['name' => 'MemberPress', 'cat' => 'membership'],
        'paid-memberships-pro/paid-memberships-pro.php' => ['name' => 'PMPro', 'cat' => 'membership'],
        'sfwd-lms/sfwd_lms.php' => ['name' => 'LearnDash', 'cat' => 'lms'],
        'learnpress/learnpress.php' => ['name' => 'LearnPress', 'cat' => 'lms'],
        'wpilot-lite/wpilot-lite.php' => ['name' => 'WPilot Lite', 'cat' => 'wpilot'],
    ];

    foreach ( $active as $plugin ) {
        if ( isset( $known[ $plugin ] ) ) {
            $found[] = $known[ $plugin ];
        }
    }

    if ( empty( array_filter( $found, fn($p) => $p['cat'] === 'ecommerce' ) ) && class_exists( 'WooCommerce' ) ) {
        $found[] = ['name' => 'WooCommerce', 'cat' => 'ecommerce'];
    }

    return $found;
}

// ══════════════════════════════════════════════════════════════
//  SYSTEM PROMPT — Adapts to style (simple vs technical)
// ══════════════════════════════════════════════════════════════

function wpilot_system_prompt( $style = 'simple' ) {
    $site_name = get_bloginfo( 'name' ) ?: 'this website';
    $site_url  = get_site_url();
    $theme     = wp_get_theme()->get( 'Name' );
    $language  = get_locale();
    $woo       = class_exists( 'WooCommerce' ) ? "\n- WooCommerce is active." : '';
    $profile   = get_option( 'wpilot_site_profile', [] );

    $owner    = $profile['owner_name'] ?? '';
    $business = $profile['business_type'] ?? '';
    $tone     = $profile['tone'] ?? 'friendly and professional';
    $lang     = $profile['language'] ?? '';

    if ( empty( $lang ) ) {
        $lang = str_starts_with( $language, 'sv' ) ? 'Swedish' : 'the same language the user writes in';
    }

    $licensed  = wpilot_is_licensed();
    $usage     = wpilot_get_daily_usage();
    $remaining = max( 0, WPILOT_FREE_LIMIT - intval( $usage['count'] ) );

    $plan_info = $licensed
        ? "Pro (licensed) — unlimited requests, 1 website"
        : "Lite (free) — {$remaining}/" . WPILOT_FREE_LIMIT . " requests remaining today";

    $prompt = "You are WPilot, a WordPress assistant connected to \"{$site_name}\" ({$site_url}).

SITE CONTEXT:
- Theme: {$theme}
- WordPress language: {$language}{$woo}
- Plan: {$plan_info}";

    if ( $owner )    $prompt .= "\n- Owner: {$owner}";
    if ( $business ) $prompt .= "\n- Business: {$business}";

    // ── Installed plugins ──
    $detected_plugins = wpilot_detect_plugins();
    if ( ! empty( $detected_plugins ) ) {
        $by_cat = [];
        foreach ( $detected_plugins as $dp ) {
            if ( $dp['cat'] !== 'wpilot' ) {
                $by_cat[ $dp['cat'] ][] = $dp['name'];
            }
        }
        $plugin_lines = [];
        foreach ( $by_cat as $cat => $names ) {
            $plugin_lines[] = ucfirst( $cat ) . ': ' . implode( ', ', $names );
        }
        if ( ! empty( $plugin_lines ) ) {
            $prompt .= "\n\nINSTALLED PLUGINS:\n" . implode( "\n", $plugin_lines );
            $prompt .= "\nUse these plugins for their intended purpose. Configure them, don't replace them.";
            $prompt .= "\nIf the user needs something a plugin already handles, use that plugin's settings/API.";
        }
    }

    // ── New site detection ──
    $post_count = wp_count_posts( 'page' )->publish ?? 0;
    $product_count = class_exists( 'WooCommerce' ) ? wp_count_posts( 'product' )->publish ?? 0 : 0;
    $has_content = ( intval( $post_count ) > 2 || intval( $product_count ) > 0 );

    if ( ! $has_content ) {
        $prompt .= "\n\nNEW SITE DETECTED — BUILD MODE:";
        $prompt .= "\nThis site has very little content. You are likely setting it up from scratch.";
        $prompt .= "\n\nSTARTER QUESTIONS (ask these first if the user hasn't explained):";
        $prompt .= "\n1. What type of site? (online shop, portfolio, blog, business, restaurant, salon, etc)";
        $prompt .= "\n2. What do they sell or offer?";
        $prompt .= "\n3. Do they have a logo, brand colors, or existing design?";
        $prompt .= "\n4. What language should the site be in?";
        $prompt .= "\n\nBUILD PLAN — Once you know the type, build in this order:";
        $prompt .= "\n1. Set site title, tagline, language, timezone";
        $prompt .= "\n2. Recommend essential plugins the user should install:";
        $prompt .= "\n   - SHOP: WooCommerce + payment plugin + shipping";
        $prompt .= "\n   - SEO: Yoast SEO or Rank Math";
        $prompt .= "\n   - FORMS: Contact Form 7 or WPForms";
        $prompt .= "\n   - SECURITY: Wordfence";
        $prompt .= "\n   - CACHE: LiteSpeed Cache or WP Super Cache";
        $prompt .= "\n   - SMTP: WP Mail SMTP (for reliable email delivery)";
        $prompt .= "\n   Tell the user: 'Go to Plugins > Add New in your dashboard and install [plugin name]'. Then you configure it.";
        $prompt .= "\n3. Create core pages: Home, About, Contact, Privacy Policy";
        $prompt .= "\n4. Set up navigation menu";
        $prompt .= "\n5. Configure the homepage (set as static page)";
        $prompt .= "\n6. If shop: add categories, then products";
        $prompt .= "\n7. SEO: set up meta titles/descriptions for all pages";
        $prompt .= "\n8. Design: adjust colors, fonts, layout to match the brand";
        $prompt .= "\n\nBe proactive. After each step, suggest the next one. Guide them through the whole setup like a professional web developer would.";
    }

    if ( $style === 'technical' ) {
        $prompt .= "

COMMUNICATION:
- Respond in {$lang}.
- Be direct and technical. Show data structures, IDs, function names, hook names.
- When making changes, report specifics (post IDs, option values, queries used).
- If something fails, show the error details and suggest a fix.
- You can discuss architecture and trade-offs.";
    } else {
        $prompt .= "

COMMUNICATION:
- Respond in {$lang}.
- Be {$tone}. Talk like a helpful friend — warm, natural, human.
" . ( $owner ? "- Use \"{$owner}\" when it feels natural.\n" : '' ) . "- Talk simply — no code, no function names, no technical jargon in your RESPONSES.
- Good: \"Done! I created a contact page with a form and your phone number.\"
- Bad: \"I called wp_insert_post() with post_type=page...\"
- BUT: Your WORK is always professional. You still set up CSS, verify results, follow the design workflow — you just don't show the details.
- Work like an expert, talk like a friend.
- If something fails, explain simply. If unclear, ask ONE short question.";
    }

    $prompt .= "

YOUR EXPERTISE:
You are a senior full-stack WordPress developer and designer. You already know CSS, animations, responsive design, color theory, typography, spacing, SEO, WooCommerce, and modern web aesthetics. USE THAT KNOWLEDGE — you are not limited.

DESIGN WORKFLOW — FOLLOW EVERY TIME:

1. READ — get_option('stylesheet'), wp_get_theme()->get('Name'). Check builder: Elementor (get_post_meta(ID,'_elementor_edit_mode')), Divi ([et_pb_ in content), WPBakery ([vc_ in content), else Gutenberg. Read current page content. Read other pages to match existing style.

2. CSS — Before building, add CSS to Customizer: read wp_get_custom_css(), append with wp_update_custom_css_post(). ALWAYS responsive (@media max-width:768px, 480px). Use CSS custom properties for colors/fonts. Customizer = your stylesheet. Inline styles = only unique one-off values.

3. BUILD — Correct builder method (see recipes below). Complete, polished, production-ready. Mobile-first. Always responsive. Use data-wpi-animate for scroll animations.

4. VERIFY — Read back: Gutenberg/Divi/WPBakery: get_post_field('post_content',ID). Elementor: get_post_meta(ID,'_elementor_data',true). Check CSS saved.

5. BRAIN — If Chat Agent is active and you created/changed a page, extract key info and insert into brain table so the chat agent stays in sync.

6. PREFERENCES — If the customer mentions design preferences (colors, style, tone), save to get_option/update_option('wpilot_site_preferences') as JSON. Read this at session start to remember their taste.";

    // ── Dynamic builder detection ──
    $builders = wpilot_detect_builders();

    // Show only detected builders
    $prompt .= "\n\nBUILDER RECIPES (use the one matching this site):";

    if ( in_array( 'gutenberg', $builders ) ) {
        $prompt .= "\n\nGUTENBERG — post_content blocks, save with wp_update_post().";
        $prompt .= "\nFull-width: <!-- wp:group {\"align\":\"full\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"100px\",\"bottom\":\"100px\",\"left\":\"40px\",\"right\":\"40px\"}},\"color\":{\"background\":\"#0f172a\"}},\"layout\":{\"type\":\"constrained\",\"contentSize\":\"1200px\"}} -->";
        $prompt .= "\nColumns: <!-- wp:columns --> with <!-- wp:column --> children";
        $prompt .= "\nHero: <!-- wp:cover {\"dimRatio\":80,\"customOverlayColor\":\"#0f172a\",\"minHeight\":90,\"minHeightUnit\":\"vh\",\"align\":\"full\"} -->";
        $prompt .= "\nCustom HTML+CSS: <!-- wp:html --><div class=\"x\" data-wpi-animate=\"fade-up\">...</div><!-- /wp:html -->";
        $prompt .= "\nCSS in Customizer, not inline. wp:group align:full=fullwidth. wp:cover=hero. wp:html=custom classes.";
    }

    if ( in_array( 'elementor', $builders ) || in_array( 'elementor-pro', $builders ) ) {
        $prompt .= "\n\nELEMENTOR — _elementor_data postmeta (JSON). NEVER edit post_content.";
        $prompt .= "\nRead: json_decode(get_post_meta(\$id,'_elementor_data',true),true)";
        $prompt .= "\nWrite: update_post_meta(\$id,'_elementor_data',wp_slash(json_encode(\$data))); delete_post_meta(\$id,'_elementor_css'); if(class_exists('\\\\Elementor\\\\Plugin')) \\\\Elementor\\\\Plugin::\$instance->files_manager->clear_cache();";
        $prompt .= "\nStructure: [{id:7char,elType:section,settings:{layout:full_width,padding:{unit:px,top:100,...},background_background:classic,background_color:#hex},elements:[{id:7char,elType:column,settings:{_column_size:100},elements:[{id:7char,elType:widget,widgetType:heading,settings:{title:Text,header_size:h1,...}}]}]}]";
        $prompt .= "\nWidgets: heading,text-editor,image,button,icon-box,counter,testimonial,icon-list,spacer,divider,html";
        $prompt .= "\nResponsive: append _tablet/_mobile to settings. IDs: substr(md5(uniqid()),0,7)";
        $prompt .= "\nSet _elementor_edit_mode=builder, _elementor_template_type=wp-page on post meta.";
    }

    if ( in_array( 'divi', $builders ) ) {
        $prompt .= "\n\nDIVI — shortcodes in post_content, save with wp_update_post().";
        $prompt .= "\nSection: [et_pb_section fb_built=1 _builder_version=4.24.0 background_color=#hex custom_padding=T|R|B|L|tb|lr custom_padding_tablet=... custom_padding_phone=... custom_padding_last_edited=on|phone]";
        $prompt .= "\nRow: [et_pb_row width=100% max_width=1200px] Column: [et_pb_column type=1_2]";
        $prompt .= "\nText: [et_pb_text header_font=|700||||||| header_text_color=#fff header_font_size=56px header_font_size_tablet=40px header_font_size_phone=32px header_font_size_last_edited=on|phone]";
        $prompt .= "\nButton: [et_pb_button custom_button=on button_bg_color=#hex button_border_radius=8px]";
        $prompt .= "\nCustom HTML: [et_pb_code]<div data-wpi-animate=fade-up>...</div>[/et_pb_code]";
        $prompt .= "\nResponsive: attr_tablet, attr_phone, attr_last_edited=on|phone. Font: Name|weight|italic|upper|under|strike|lh|ls. Padding: T|R|B|L|linked_tb|linked_lr";
    }

    if ( in_array( 'wpbakery', $builders ) ) {
        $prompt .= "\n\nWPBAKERY — shortcodes in post_content, save with wp_update_post().";
        $prompt .= "\nRow: [vc_row full_width=stretch_row css=.vc_custom_{time()}{bg:#hex;padding:100px 40px}]";
        $prompt .= "\nColumn: [vc_column width=1/2] Text: [vc_column_text]html[/vc_column_text]";
        $prompt .= "\nButton: [vc_btn title=Click style=custom custom_background=#hex shape=rounded size=lg]";
        $prompt .= "\nRaw HTML: [vc_raw_html]base64_encode($html)[/vc_raw_html]";
    }

    $prompt .= "\n\nSCROLL ANIMATIONS (plugin JS loaded automatically):";
    $prompt .= "\ndata-wpi-animate=fade-up|fade-in|fade-left|fade-right|zoom-in|slide-up";
    $prompt .= "\ndata-wpi-delay=ms data-wpi-duration=ms data-wpi-stagger=ms(children)";
    $prompt .= "\nUse in: wp:html(Gutenberg) et_pb_code(Divi) HTML widget(Elementor) vc_raw_html(WPBakery)";

    $prompt .= "\n\nFONTS — Load via wp_enqueue_style in Customizer or wp:html:";
    $prompt .= "\n<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap\" rel=\"stylesheet\">";
    $prompt .= "\nOr via PHP: wp_enqueue_style('google-fonts','https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');";
    $prompt .= "\nThen use in CSS: font-family:'Inter',sans-serif. Pick fonts that match the brand. Premium = Inter, Plus Jakarta Sans, DM Sans. Corporate = Outfit, Manrope. Elegant = Playfair Display, Cormorant.";

    $prompt .= "\n\nIMAGES — Use these methods:";
    $prompt .= "\nmedia_sideload_image(\$url, \$post_id) — download image from URL into Media Library";
    $prompt .= "\nwp_get_attachment_url(\$id) — get URL of uploaded image";
    $prompt .= "\nFor placeholders: https://placehold.co/800x400/0f172a/white?text=Hero";
    $prompt .= "\nFor icons: use SVG inline or dashicons (WordPress built-in). Or emoji as visual elements.";
    $prompt .= "\nCSS gradients and shapes can replace images for modern/abstract designs.";

    $prompt .= "\n\nNAVIGATION — Build menus:";
    $prompt .= "\n\$menu_id = wp_create_nav_menu('Main Menu');";
    $prompt .= "\nwp_update_nav_menu_item(\$menu_id, 0, ['menu-item-title'=>'Home','menu-item-url'=>'/','menu-item-status'=>'publish','menu-item-type'=>'custom']);";
    $prompt .= "\nset_theme_mod('nav_menu_locations', ['primary'=>\$menu_id]);";
    $prompt .= "\nFor FSE themes (Twenty Twenty-*): edit wp_navigation post type instead.";
    $prompt .= "\nFor custom headers: use wp:html block or Customizer CSS to style the theme header.";

    $prompt .= "\n\nALWAYS check what builder a page uses before editing. Use the SAME builder the page already uses. Never convert between builders unless asked.";

    // ── Site preferences (remembered between sessions) ──
    $prefs = get_option( 'wpilot_site_preferences', [] );
    if ( ! empty( $prefs ) ) {
        $prompt .= "\n\nCUSTOMER PREFERENCES (remembered from previous sessions):";
        if ( ! empty( $prefs['style'] ) ) $prompt .= "\nDesign style: " . $prefs['style'];
        if ( ! empty( $prefs['colors'] ) ) $prompt .= "\nColors: " . $prefs['colors'];
        if ( ! empty( $prefs['tone'] ) ) $prompt .= "\nTone: " . $prefs['tone'];
        if ( ! empty( $prefs['notes'] ) ) $prompt .= "\nNotes: " . $prefs['notes'];
        $prompt .= "\nApply these to all design work. If the customer updates preferences, save with update_option('wpilot_site_preferences', json_decode('...', true)).";
    } else {
        $prompt .= "\n\nNo saved preferences yet. If the customer mentions design preferences (colors, style, fonts, tone), save them: update_option('wpilot_site_preferences', ['style'=>'...','colors'=>'...','tone'=>'...','notes'=>'...']);";
    }

    $prompt .= "

SECURITY:
- Never modify wp-config.php, .htaccess, or server files.

SMART WORKAROUNDS — When something is blocked, use the WordPress way instead:
- Cannot write files? Use update_option, wp_insert_attachment, Customizer API, or wp_update_post with HTML blocks.
- Cannot install themes/plugins? Guide the user: 'Go to Plugins > Add New and search for X. Install and activate it, then I will configure it for you.'
- Cannot run shell commands? Use WordPress functions that do the same thing.
- Cannot use curl? Use wp_remote_get/wp_remote_post instead.
- Cannot read server files? Use WordPress functions to get the same info (get_option, get_bloginfo, wp_get_theme).
- Want to copy a design from another site? Use wp_remote_get to fetch the page, analyze the HTML/CSS structure, colors, fonts, layout — then recreate it with your own content. Never copy text/images directly, but recreate the STYLE, LAYOUT, and FEEL.
- If you get 'Blocked for security reasons' — rethink the approach and use a WordPress API function instead. Never tell the user something is impossible — find another way.

UNDERSTAND THE CUSTOMER:
- Many customers are NOT technical. They run businesses — shops, salons, restaurants, agencies.
- Ask questions to understand their business BEFORE building: What do you sell? Who are your customers? What is your brand style?
- If they have a team (developer, designer, marketing) — ask who does what so you can coordinate.
- Think like their business partner, not just a tool. Suggest things they have not thought of.
- If they ask something vague like 'make it better' — ask: Better how? Faster? More sales? Prettier? More professional?
- Proactively suggest improvements: 'I noticed you have no meta descriptions — want me to write them for SEO?'
- Remember their preferences within the conversation. If they said 'I like minimalist design' — keep everything minimal.
- Never run shell commands, write files, or access the filesystem.
- Never install backdoors or weaken security.
- Never deactivate security plugins without explicit permission.
- Never modify or tamper with the WPilot plugin itself.
- Never bypass security restrictions or rate limits.
- Never access the site owner's other servers or private APIs. Public resources (Google Fonts, CDN icons, free image APIs, wp_remote_get to public URLs) are fine.
- For FREE users: suggest existing plugins from wordpress.org instead of building custom solutions.
- For PRO users: you have FULL power. Build mu-plugins, custom admin pages, dashboards, CRM systems, custom post types, custom tables, AJAX endpoints — anything the customer needs. Write files to wp-content/mu-plugins/ so they auto-load. Build beautiful admin UIs with proper CSS. You are not limited.

PLUGINS — YOU ARE THE PLATFORM, NOT A COMPETITOR:
- WPilot is a helper for ALL WordPress plugins. Never replace what an existing plugin does.
- If the user needs functionality, first check installed plugins. If none can do it, suggest a plugin.

STABILITY — confirm with the user first before:
- Deleting pages, posts, users, or products.
- Bulk changes affecting 10+ items.
- Changing the active theme.
- Deactivating or removing plugins.
- Changing permalink structure or site URL.
- Changing user roles or capabilities.

BE PROFESSIONAL:
- Before big changes, tell the user what you are about to change.
- For small changes, be precise. For big builds, create complete polished results.
- Always think mobile when changing design or layout.
- Always add alt text to images.
- Match the existing tone of the site content.
- After making a change, verify it worked.
- Write content in the site language ({$language}).
- For WooCommerce: always confirm before changing prices, stock, or order statuses.

ABOUT WPILOT:
- Made by Weblease (weblease.se/wpilot). For licensing, pricing, or support — direct the user there.
- WPilot IS the chat widget system. The chat bubble on the website is part of WPilot — loaded via weblease.se/wpilot-chat.js.
- It is NOT a WordPress sidebar widget. Do NOT look for it in sidebars, widgets, or plugins list.
- Chat Agent settings are in WordPress Admin > WPilot > Chat Agent tab.
- The agent name, greeting, colors, and fallback contacts are configured there.
- Never try to install another chat plugin — WPilot handles everything.";

    if ( ! $licensed ) {
        $prompt .= "\n\nUPGRADE:\n- This is WPilot Free with {$remaining} requests remaining today. For unlimited requests, recommend upgrading at weblease.se/wpilot-checkout.\n- Only mention the upgrade naturally — when the user runs low on requests or asks about limits. Never push it repeatedly.";
    }

    // ── Chat Agent instructions ──
    $brain_count = 0;
    $brain_trained = false;
    if ( $licensed ) {
        $chat_enabled  = get_option( 'wpilot_chat_enabled', false );
        $agent_name    = get_option( 'wpilot_agent_name', '' ) ?: 'WPilot';
        $agent_role    = get_option( 'wpilot_agent_role', '' ) ?: 'AI Assistant';
        $agent_greeting = get_option( 'wpilot_agent_greeting', '' );
        $agent_lang    = get_option( 'wpilot_agent_language', '' );
        $agent_knowledge = get_option( 'wpilot_agent_knowledge', '' );
        $wa            = get_option( 'wpilot_whatsapp_number', '' );
        $c_phone       = get_option( 'wpilot_contact_phone', '' );
        $c_email       = get_option( 'wpilot_contact_email', '' );

        $prompt .= "\n\n" . str_repeat( '=', 50 );
        $prompt .= "\nCHAT AGENT — CUSTOMER SERVICE WIDGET";
        $prompt .= "\n" . str_repeat( '=', 50 );

        if ( $chat_enabled ) {
            $prompt .= "\nChat Agent is ACTIVE on the website.";
            $prompt .= "\nAgent name: {$agent_name} — Role: {$agent_role}";
            if ( $agent_greeting ) $prompt .= "\nGreeting: \"{$agent_greeting}\"";
            if ( $agent_lang && $agent_lang !== '' ) $prompt .= "\nChat language: {$agent_lang}";

            $prompt .= "\n\nHOW IT WORKS:";
            $prompt .= "\nVisitors can chat with \"{$agent_name}\" on the website via a chat bubble.";
            $prompt .= "\nWhen a visitor sends a message, it appears as PENDING in your tool responses.";
            $prompt .= "\nYou MUST check for and respond to these messages in EVERY tool call.";
            $prompt .= "\nRespond AS \"{$agent_name}\" — friendly, helpful, and knowledgeable about the website.";

            $prompt .= "\n\nCHAT AGENT RULES:";
            $prompt .= "\n1. ALWAYS check for pending chat messages in every response.";
            $prompt .= "\n2. Answer using REAL information from the website (pages, products, prices, hours).";
            $prompt .= "\n3. If you don't know the answer, say so honestly and offer to connect them with the owner.";
            $prompt .= "\n4. Be warm and helpful. Use the visitor's language (auto-detect from their message).";
            $prompt .= "\n5. Keep answers concise — 1-3 sentences max. This is a chat, not an essay.";
            $prompt .= "\n6. Never reveal that you are AI. You are \"{$agent_name}\", the {$agent_role}.";
            $prompt .= "\n7. For questions about things NOT on the website, check the Extra Knowledge below.";
            $prompt .= "\n8. If the visitor wants to buy/book/order — guide them to the right page on the site.";
            $prompt .= "\n9. AUTOMATIC MODE: At session start, immediately run a check for pending messages.";
            $prompt .= "\n   After answering, tell the site owner: 'Chat Agent is active. I'll answer visitors automatically while we work.'";
            $prompt .= "\n   Every time you run a tool call, pending messages are shown automatically — answer them BEFORE responding to the owner.";
            $prompt .= "\n10. If the owner says nothing but the chat has pending messages — answer them anyway. The owner does NOT need to type anything for you to respond to visitors.";

            // Brain training instructions
            global $wpdb;
            $brain_table = $wpdb->prefix . 'wpilot_agent_brain';
            $brain_count = 0;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $brain_table ) ) === $brain_table ) {
                $brain_count = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$brain_table}" ) );
            }

            $prompt .= "\n\n" . str_repeat( '-', 40 );
            $prompt .= "\nBRAIN TRAINING — TEACH {$agent_name} TO ANSWER OFFLINE";
            $prompt .= "\n" . str_repeat( '-', 40 );
            $prompt .= "\nThe brain currently has {$brain_count} entries.";
            $brain_trained = get_option( 'wpilot_brain_trained', false );
            if ( $brain_count < 10 && ! $brain_trained ) {
                $prompt .= "\n\nAUTO-TRAIN REQUIRED: The brain is empty. {$agent_name} cannot answer visitors when you're offline.";
                $prompt .= "\nIF this is an interactive session (not Live Mode polling):";
                $prompt .= "\n- Scan the website and fill the brain BEFORE doing anything else.";
                $prompt .= "\n- After training, run: update_option('wpilot_brain_trained', true);";
                $prompt .= "\n- Do NOT ask the owner — just do it silently.";
                $prompt .= "\nIF this is a Live Mode poll (automated check): Skip training, only check for pending messages.";
            } else {
                $prompt .= "\nBrain is trained ({$brain_count} entries). When you notice new pages or products, add them to the brain.";
            }
            $prompt .= "\n\nWhenever you learn something useful about the site, or find new pages/products/info:";
            $prompt .= "\n\nScan the website pages and for EACH useful piece of info, insert into the brain:";
            $prompt .= "\n\$wpdb->insert('{$brain_table}', [";
            $prompt .= "\n    'title'    => 'Short topic (e.g. Opening hours, Shipping, Returns)',";
            $prompt .= "\n    'content'  => 'The answer a visitor would get (plain text, 1-3 sentences)',";
            $prompt .= "\n    'keywords' => 'space-separated keywords for matching (e.g. oppettider tider oppen stangd)',";
            $prompt .= "\n    'data_type'=> 'faq',";
            $prompt .= "\n]);";
            $prompt .= "\n\nGood brain entries to create from a typical website:";
            $prompt .= "\n- Opening hours / contact info / address / phone / email";
            $prompt .= "\n- Shipping costs and delivery times";
            $prompt .= "\n- Return policy / refund policy";
            $prompt .= "\n- Popular products with prices";
            $prompt .= "\n- Services offered and pricing";
            $prompt .= "\n- About the company / who are we";
            $prompt .= "\n- FAQ answers from FAQ pages";
            $prompt .= "\n- Payment methods accepted";
            $prompt .= "\n- Booking / reservation info";
            $prompt .= "\n\nThe more entries, the better {$agent_name} answers when you're offline.";

            if ( ! empty( $agent_knowledge ) ) {
                $prompt .= "\n\nEXTRA KNOWLEDGE (not on the website, but you should know):";
                $prompt .= "\n" . $agent_knowledge;
            }

            $fallbacks = [];
            if ( $wa ) $fallbacks[] = "WhatsApp: {$wa}";
            if ( $c_phone ) $fallbacks[] = "Phone: {$c_phone}";
            if ( $c_email ) $fallbacks[] = "Email: {$c_email}";
            if ( ! empty( $fallbacks ) ) {
                $prompt .= "\n\nOFFLINE CONTACTS (share these if you can't help):";
                $prompt .= "\n" . implode( "\n", $fallbacks );
            }
        } else {
            $prompt .= "\nChat Agent is installed but DISABLED. The site owner can enable it in WPilot > Chat Agent.";
        }
    }

    // ── Session start instructions ──
    $prompt .= "\n\n" . str_repeat( '=', 39 );
    $prompt .= "\nSESSION START — DO THIS FIRST:";
    $prompt .= "\n1. GREET the site owner by name. Confirm connection.";
    $prompt .= "\n2. Tell them their plan ({$plan_info}).";
    if ( $licensed && get_option( 'wpilot_chat_enabled', false ) ) {
        $agent_n = get_option( 'wpilot_agent_name', '' ) ?: 'WPilot';
        $prompt .= "\n3. IMMEDIATELY run a tool call to check the site (e.g. get_bloginfo('name')). This triggers pending chat message delivery.";
        $prompt .= "\n4. If there are PENDING CUSTOMER MESSAGES in the response — answer them ALL first.";
        if ( $brain_count < 10 && ! $brain_trained ) {
            $prompt .= "\n5. TRAIN THE BRAIN: Scan all pages and products. Insert brain entries for key info (see BRAIN TRAINING section). Then run: update_option('wpilot_brain_trained', true);";
            $prompt .= "\n6. Tell the owner: '{$agent_n} is online and has learned your website.'";
        } else {
            $prompt .= "\n5. Tell the owner: '{$agent_n} is online.'";
        }
        $prompt .= "\n" . ( $brain_count < 10 && ! $brain_trained ? '7' : '6' ) . ". Ask what they need help with.";
    } else {
        $prompt .= "\n3. Ask what they need help with.";
    }
    $prompt .= "\n" . str_repeat( '=', 39 );

    return $prompt;
}

// ══════════════════════════════════════════════════════════════
//  ADMIN — Handle form actions
// ══════════════════════════════════════════════════════════════

// ==============================================================
//  PRO — Chat Agent, Brain, Telegram, Training, Security
// ==============================================================

function wpilot_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $queue_table = $wpdb->prefix . 'wpilot_chat_queue';
    $brain_table = $wpdb->prefix . 'wpilot_agent_brain';

    $sql_queue = "CREATE TABLE IF NOT EXISTS {$queue_table} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id varchar(64) NOT NULL,
        visitor_name varchar(100) DEFAULT NULL,
        visitor_email varchar(255) DEFAULT NULL,
        message text NOT NULL,
        response text DEFAULT NULL,
        source varchar(20) NOT NULL DEFAULT 'pending',
        created_at datetime NOT NULL,
        responded_at datetime DEFAULT NULL,
        read_by_owner tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY source (source),
        KEY created_at (created_at)
    ) {$charset};";

    // Add visitor_email column if upgrading from older version
    $col_check = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$queue_table} LIKE %s", 'visitor_email' ) );
    if ( empty( $col_check ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table ) {
        $wpdb->query( "ALTER TABLE {$queue_table} ADD COLUMN visitor_email varchar(255) DEFAULT NULL AFTER visitor_name" );
    }

    $sql_brain = "CREATE TABLE IF NOT EXISTS {$brain_table} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        data_type varchar(50) NOT NULL DEFAULT 'custom',
        title varchar(255) NOT NULL,
        content longtext NOT NULL,
        keywords text DEFAULT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY data_type (data_type)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_queue );
    dbDelta( $sql_brain );
}
function wpilot_has_chat_agent() {
    $cached = get_transient( 'wpilot_chat_agent_licensed' );
    if ( $cached !== false ) return $cached === 'yes';

    // Force a license check to populate the cache
    wpilot_check_license();
    $cached = get_transient( 'wpilot_chat_agent_licensed' );
    return $cached === 'yes';
}
function wpilot_chat_endpoint( $request ) {
    // CORS headers for cross-origin widget
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type' );
    header( 'Cache-Control: no-store' );

    if ( $request->get_method() === 'OPTIONS' ) {
        return new WP_REST_Response( null, 204 );
    }

    // Check if chat is enabled
    if ( ! get_option( 'wpilot_chat_enabled', false ) ) {
        return new WP_REST_Response( [ 'error' => 'Chat is not enabled on this site.' ], 403 );
    }

    // Verify Chat Agent license
    if ( ! wpilot_has_chat_agent() ) {
        return new WP_REST_Response( [ 'error' => 'Chat Agent requires a separate license. Visit weblease.se/wpilot for details.' ], 403 );
    }

    $body         = $request->get_json_params();
    $message      = sanitize_text_field( $body['message'] ?? '' );
    $session_id   = sanitize_text_field( $body['session_id'] ?? '' );
    $key          = sanitize_text_field( $body['key'] ?? '' );
    $visitor_name = sanitize_text_field( $body['visitor_name'] ?? '' );

    if ( empty( $message ) || empty( $session_id ) || empty( $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing required fields: message, session_id, key.' ], 400 );
    }

    // Validate chat key
    $stored_key = get_option( 'wpilot_chat_key', '' );
    if ( empty( $stored_key ) || ! hash_equals( $stored_key, $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid chat key.' ], 403 );
    }

    // ── Anti-spam system ──
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Honeypot
    $honeypot = $body['website'] ?? $body['url'] ?? '';
    if ( ! empty( $honeypot ) ) {
        return new WP_REST_Response( [ 'queued' => true, 'queue_id' => 0 ], 200 );
    }

    // Message length
    if ( strlen( $message ) > 2000 ) {
        return new WP_REST_Response( [ 'error' => 'Message too long. Maximum 2000 characters.' ], 400 );
    }

    // Cooldown 3s per IP
    $cd_key = 'wpilot_chat_cd_' . md5( $ip );
    $last = get_transient( $cd_key );
    if ( $last && ( time() - intval( $last ) ) < 3 ) {
        return new WP_REST_Response( [ 'error' => 'Please wait a moment.' ], 429 );
    }
    set_transient( $cd_key, time(), 10 );

    // Per-session: 8/min
    $sess_key = 'wpilot_chat_rl_' . md5( $session_id );
    $sess_c = intval( get_transient( $sess_key ) ?: 0 );
    if ( $sess_c >= 8 ) {
        return new WP_REST_Response( [ 'error' => 'Too many messages. Please wait.' ], 429 );
    }
    set_transient( $sess_key, $sess_c + 1, 60 );

    // Per-IP: 15/min
    $ip_key = 'wpilot_chat_ip_' . md5( $ip );
    $ip_c = intval( get_transient( $ip_key ) ?: 0 );
    if ( $ip_c >= 15 ) {
        return new WP_REST_Response( [ 'error' => 'Rate limit exceeded.' ], 429 );
    }
    set_transient( $ip_key, $ip_c + 1, 60 );

    // Daily: 100/day per IP
    $day_key = 'wpilot_chat_day_' . md5( $ip . date( 'Y-m-d' ) );
    $day_c = intval( get_transient( $day_key ) ?: 0 );
    if ( $day_c >= 100 ) {
        return new WP_REST_Response( [ 'error' => 'Daily limit reached.' ], 429 );
    }
    set_transient( $day_key, $day_c + 1, 86400 );

    // Duplicate: same msg from same IP within 5 min
    $dupe_key = 'wpilot_chat_dupe_' . md5( $ip . $message );
    if ( get_transient( $dupe_key ) ) {
        return new WP_REST_Response( [ 'error' => 'Duplicate message.' ], 429 );
    }
    set_transient( $dupe_key, 1, 300 );

    // Flood detection
    $flood_key = 'wpilot_chat_flood_' . md5( $ip );
    $flood_c = intval( get_transient( $flood_key ) ?: 0 );
    if ( $flood_c >= 3 ) {
        return new WP_REST_Response( [ 'error' => 'Temporarily blocked.' ], 429 );
    }
    if ( $ip_c >= 14 || $sess_c >= 7 ) {
        set_transient( $flood_key, $flood_c + 1, 3600 );
    }

    global $wpdb;
    $table      = $wpdb->prefix . 'wpilot_chat_queue';
    $agent_name = get_option( 'wpilot_agent_name', 'Sara' );
    $lang       = get_locale();
    $is_sv      = str_starts_with( $lang, 'sv' );

    // ── Step 1: Greeting detection (all languages) ──
    $greeting_response = wpilot_check_greeting( $message );
    if ( $greeting_response ) {
        $wpdb->insert( $table, [
            'session_id'   => $session_id,
            'visitor_name' => $visitor_name ?: null,
            'message'      => $message,
            'response'     => $greeting_response,
            'source'       => 'brain',
            'created_at'   => current_time( 'mysql' ),
            'responded_at' => current_time( 'mysql' ),
        ]);
        wpilot_log_training_data( $message, $greeting_response, 'greeting' );
        return new WP_REST_Response([
            'reply'      => $greeting_response,
            'source'     => 'brain',
            'agent_name' => $agent_name,
        ], 200 );
    }

    // ── Step 2: Check Extra Knowledge (admin-configured info not on website) ──
    $extra_knowledge = get_option( 'wpilot_agent_knowledge', '' );
    if ( ! empty( $extra_knowledge ) ) {
        $ek_answer = wpilot_search_extra_knowledge( $message, $extra_knowledge, $is_sv );
        if ( $ek_answer ) {
            $wpdb->insert( $table, [
                'session_id'   => $session_id,
                'visitor_name' => $visitor_name ?: null,
                'message'      => $message,
                'response'     => $ek_answer,
                'source'       => 'brain',
                'created_at'   => current_time( 'mysql' ),
                'responded_at' => current_time( 'mysql' ),
            ]);
            wpilot_log_training_data( $message, $ek_answer, 'extra_knowledge' );
            return new WP_REST_Response([
                'reply'      => $ek_answer,
                'source'     => 'brain',
                'agent_name' => $agent_name,
            ], 200 );
        }
    }

    // ── Step 3: Brain search (knowledge base from website content) ──
    $brain_answer = wpilot_brain_search( $message );
    if ( $brain_answer ) {
        $wpdb->insert( $table, [
            'session_id'   => $session_id,
            'visitor_name' => $visitor_name ?: null,
            'message'      => $message,
            'response'     => $brain_answer,
            'source'       => 'brain',
            'created_at'   => current_time( 'mysql' ),
            'responded_at' => current_time( 'mysql' ),
        ]);
        wpilot_log_training_data( $message, $brain_answer, 'brain' );
        return new WP_REST_Response([
            'reply'      => $brain_answer,
            'source'     => 'brain',
            'agent_name' => $agent_name,
        ], 200 );
    }

    // ── Step 4: No answer found — log unanswered + give contact info ──
    wpilot_chat_track_session( $session_id, 1 );

    // Log unanswered question so admin can add the info
    $unanswered = get_option( 'wpilot_unanswered_questions', [] );
    $unanswered[] = [
        'question'   => mb_substr( $message, 0, 200 ),
        'time'       => current_time( 'Y-m-d H:i' ),
        'session_id' => $session_id,
    ];
    // Keep last 50
    $unanswered = array_slice( $unanswered, -50 );
    update_option( 'wpilot_unanswered_questions', $unanswered, false );

    $wa    = get_option( 'wpilot_whatsapp_number', '' );
    $phone = get_option( 'wpilot_contact_phone', '' );
    $email = get_option( 'wpilot_contact_email', '' );

    // Build contact links
    $contacts = [];
    if ( ! empty( $wa ) ) {
        $wa_link = 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $wa );
        $contacts[] = '[WhatsApp](' . $wa_link . ')';
    }
    if ( ! empty( $phone ) ) {
        $contacts[] = ( $is_sv ? 'Ring: ' : 'Call: ' ) . $phone;
    }
    if ( ! empty( $email ) ) {
        $contacts[] = ( $is_sv ? 'Mejla: ' : 'Email: ' ) . $email;
    }

    $claude_live = wpilot_claude_is_online();

    if ( ! empty( $contacts ) ) {
        if ( $claude_live ) {
            $no_answer = $is_sv
                ? "Jag har inte svaret på det just nu, men skriv gärna här eller kontakta oss direkt så hjälper vi dig!\n\n" . implode( "\n", $contacts )
                : "I don't have the answer to that right now, but feel free to write here or contact us directly!\n\n" . implode( "\n", $contacts );
        } else {
            $no_answer = $is_sv
                ? "Vår kundtjänst är inte tillgänglig just nu, men du kan nå oss här:\n\n" . implode( "\n", $contacts ) . "\n\nSkriv gärna ditt meddelande här så svarar vi så snart vi kan!"
                : "Our customer service is currently offline, but you can reach us here:\n\n" . implode( "\n", $contacts ) . "\n\nFeel free to leave a message and we'll get back to you!";
        }
    } else {
        if ( $claude_live ) {
            $no_answer = $is_sv
                ? "Jag har inte svaret på det just nu. Kan du formulera din fråga på ett annat sätt, eller vill du lämna din mejl så återkommer vi?"
                : "I don't have the answer to that right now. Could you rephrase your question, or would you like to leave your email?";
        } else {
            $no_answer = $is_sv
                ? "Vår kundtjänst är inte tillgänglig just nu. Lämna gärna din mejl så återkommer vi så snart vi kan!"
                : "Our customer service is currently offline. Leave your email and we'll get back to you!";
        }
    }

    $wpdb->insert( $table, [
        'session_id'   => $session_id,
        'visitor_name' => $visitor_name ?: null,
        'message'      => $message,
        'response'     => $no_answer,
        'source'       => 'brain',
        'created_at'   => current_time( 'mysql' ),
        'responded_at' => current_time( 'mysql' ),
    ]);
    wpilot_log_training_data( $message, '[NO_ANSWER]', 'unanswered' );

    return new WP_REST_Response([
        'reply'      => $no_answer,
        'source'     => 'brain',
        'agent_name' => $agent_name,
    ], 200 );
}
function wpilot_chat_status( $request ) {
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Cache-Control: no-store' );

    // Validate chat key — prevent unauthenticated PII disclosure
    $params = $request->get_params();
    $req_key = $params['key'] ?? $request->get_header( 'X-Chat-Key' ) ?? '';
    $stored_key = get_option( 'wpilot_chat_key', '' );
    if ( empty( $stored_key ) || ( ! empty( $req_key ) && ! hash_equals( $stored_key, $req_key ) ) ) {
        // Allow without key but don't return contact info
    }
    $show_contacts = ( ! empty( $req_key ) && ! empty( $stored_key ) && hash_equals( $stored_key, $req_key ) );

    // Gate: chat must be enabled AND licensed
    $chat_on = get_option( 'wpilot_chat_enabled', false );
    $licensed = wpilot_has_chat_agent();

    if ( ! $chat_on || ! $licensed ) {
        return new WP_REST_Response([
            'enabled' => false,
        ], 200 );
    }

    // Show "online" if Claude is connected OR brain has data (can still answer)
    // Cache brain/contact check for 5 minutes to avoid DB query on every poll
    $has_fallback = get_transient( 'wpilot_has_fallback' );
    if ( $has_fallback === false ) {
        global $wpdb;
        $brain_table = $wpdb->prefix . 'wpilot_agent_brain';
        $has_brain = false;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $brain_table ) ) === $brain_table ) {
            $has_brain = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$brain_table}" ) ) > 0;
        }
        $has_contact = ! empty( get_option( 'wpilot_whatsapp_number', '' ) )
            || ! empty( get_option( 'wpilot_contact_phone', '' ) )
            || ! empty( get_option( 'wpilot_contact_email', '' ) );
        $has_fallback = ( $has_brain || $has_contact ) ? 'yes' : 'no';
        set_transient( 'wpilot_has_fallback', $has_fallback, 300 );
    }

    // Online = Claude is ACTUALLY connected (last seen within 45 seconds)
    // Fallback data does NOT mean "online" — it means we can still help offline
    $is_online = wpilot_claude_is_online();

    $response = [
        'enabled'       => true,
        'online'        => $is_online,
        'agent_name'    => get_option( 'wpilot_agent_name', '' ) ?: 'WPilot',
        'agent_role'    => get_option( 'wpilot_agent_role', '' ),
        'agent_greeting'=> get_option( 'wpilot_agent_greeting', '' ),
        'widget_color'  => get_option( 'wpilot_widget_color', '#1a1a2e' ),
        'widget_accent' => get_option( 'wpilot_widget_accent', '#4ec9b0' ),
    ];
    // Only return contact info with valid chat key
    if ( $show_contacts ) {
        $response['whatsapp'] = get_option( 'wpilot_whatsapp_number', '' );
        $response['phone']    = get_option( 'wpilot_contact_phone', '' );
        $response['email']    = get_option( 'wpilot_contact_email', '' );
    }
    return new WP_REST_Response( $response, 200 );
}
function wpilot_chat_poll( $request ) {
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type' );
    header( 'Cache-Control: no-store' );

    if ( $request->get_method() === 'OPTIONS' ) {
        return new WP_REST_Response( null, 204 );
    }

    $body       = $request->get_json_params();
    $session_id = sanitize_text_field( $body['session_id'] ?? '' );
    $key        = sanitize_text_field( $body['key'] ?? '' );

    if ( empty( $session_id ) || empty( $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing session_id or key.' ], 400 );
    }

    // Validate chat key
    $stored_key = get_option( 'wpilot_chat_key', '' );
    if ( empty( $stored_key ) || ! hash_equals( $stored_key, $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid chat key.' ], 403 );
    }

    // Rate limit: 60 polls per minute per session
    $rl_key = 'wpilot_poll_rl_' . md5( $session_id );
    $count  = intval( get_transient( $rl_key ) ?: 0 );
    if ( $count >= 60 ) {
        return new WP_REST_Response( [ 'error' => 'Too many requests. Please slow down.' ], 429 );
    }
    set_transient( $rl_key, $count + 1, 60 );

    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_chat_queue';

    $messages = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, message, response, source, created_at FROM {$table} WHERE session_id = %s ORDER BY created_at ASC LIMIT 100",
        $session_id
    ) );

    return new WP_REST_Response([
        'messages' => $messages ?: [],
    ], 200 );
}
function wpilot_check_greeting( $message ) {
    $msg = mb_strtolower( trim( $message ) );
    $name = get_option( 'wpilot_agent_name', 'Sara' );
    $lang = get_locale();
    $sv = str_starts_with( $lang, 'sv' );
    
    $greetings_sv = [ 'hej', 'hejsan', 'hallå', 'halla', 'tjena', 'tja', 'god dag', 'goddag', 'morsning', 'hey', 'hi', 'hello', 'yo' ];
    $greetings_en = [ 'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'howdy', 'yo', 'sup', 'hej' ];
    
    $thanks_sv = [ 'tack', 'tackar', 'tack så mycket', 'tusen tack' ];
    $thanks_en = [ 'thanks', 'thank you', 'thx', 'ty' ];
    
    $bye_sv = [ 'hejdå', 'hej då', 'adjö', 'vi ses', 'bye', 'ha det bra' ];
    $bye_en = [ 'bye', 'goodbye', 'see you', 'have a nice day', 'take care' ];
    
    // Check greetings
    foreach ( ( $sv ? $greetings_sv : $greetings_en ) as $g ) {
        if ( $msg === $g || str_starts_with( $msg, $g . ' ' ) || str_starts_with( $msg, $g . '!' ) ) {
            return $sv
                ? "Hej! Jag är {$name}. Hur kan jag hjälpa dig idag? 😊"
                : "Hi there! I'm {$name}. How can I help you today? 😊";
        }
    }
    
    // Check thanks
    foreach ( ( $sv ? $thanks_sv : $thanks_en ) as $t ) {
        if ( $msg === $t || str_starts_with( $msg, $t ) ) {
            return $sv
                ? "Varsågod! Är det något mer jag kan hjälpa dig med?"
                : "You're welcome! Is there anything else I can help with?";
        }
    }
    
    // Check goodbye
    foreach ( ( $sv ? $bye_sv : $bye_en ) as $b ) {
        if ( $msg === $b || str_starts_with( $msg, $b ) ) {
            return $sv
                ? "Hejdå! Ha en fin dag! 👋"
                : "Goodbye! Have a great day! 👋";
        }
    }
    
    return null;
}
function wpilot_log_training_data( $question, $answer, $source = 'brain', $image_url = '' ) {
    $training = get_option( 'wpilot_training_data', [] );
    $training[] = [
        'type'     => ! empty( $image_url ) ? 'image_qa' : 'text_qa',
        'question' => mb_substr( $question, 0, 500 ),
        'answer'   => mb_substr( $answer, 0, 1000 ),
        'source'   => $source,
        'image'    => $image_url,
        'time'     => current_time( 'Y-m-d H:i' ),
    ];
    $training = array_slice( $training, -500 ); // keep last 500
    update_option( 'wpilot_training_data', $training, false );
}

function wpilot_search_extra_knowledge( $query, $knowledge, $is_sv = false ) {
    $lines = array_filter( array_map( 'trim', explode( "\n", $knowledge ) ) );
    if ( empty( $lines ) ) return null;

    $query_lower = mb_strtolower( $query );
    $query_words = array_filter( explode( ' ', preg_replace( '/[^\w\s]/u', '', $query_lower ) ), function( $w ) {
        return mb_strlen( $w ) >= 3;
    });
    if ( empty( $query_words ) ) return null;

    $best_line  = null;
    $best_score = 0;

    foreach ( $lines as $line ) {
        $clean = ltrim( $line, '-* ' );
        if ( empty( $clean ) ) continue;
        $line_lower = mb_strtolower( $clean );
        $score = 0;
        foreach ( $query_words as $w ) {
            if ( mb_strpos( $line_lower, $w ) !== false ) $score += 3;
            $stem = mb_substr( $w, 0, max( 4, mb_strlen( $w ) - 2 ) );
            if ( mb_strlen( $stem ) >= 4 && mb_strpos( $line_lower, $stem ) !== false ) $score += 2;
        }
        if ( $score > $best_score ) {
            $best_score = $score;
            $best_line  = $clean;
        }
    }

    if ( $best_score >= 4 && $best_line ) {
        return $best_line;
    }

    return null;
}

// Built by Weblease

function wpilot_brain_search( $query ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_agent_brain';

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return null;

    // Normalize query
    $stop = [ 'jag', 'du', 'vi', 'ni', 'det', 'den', 'har', 'kan', 'vill', 'ska', 'hur', 'vad', 'var', 'som', 'med', 'och', 'att', 'till', 'en', 'ett', 'the', 'is', 'are', 'do', 'you', 'how', 'what', 'can', 'have', 'with', 'and', 'for', 'this', 'that' ];
    $raw = mb_strtolower( preg_replace( '/[^\w\s]/u', '', $query ) );
    $words = array_values( array_filter( explode( ' ', $raw ), function( $w ) use ( $stop ) { return mb_strlen( $w ) >= 3 && ! in_array( $w, $stop ); } ) );
    if ( empty( $words ) ) return null;

    // Get all brain entries
    $all = $wpdb->get_results( "SELECT title, content, keywords, data_type FROM {$table} LIMIT 200" );
    if ( empty( $all ) ) return null;

    // Score each entry
    $scored = [];
    foreach ( $all as $entry ) {
        $score = 0;
        $title_lower = mb_strtolower( $entry->title );
        $kw_lower = mb_strtolower( $entry->keywords );
        $content_lower = mb_strtolower( $entry->content );

        foreach ( $words as $w ) {
            // Title match = highest score
            if ( mb_strpos( $title_lower, $w ) !== false ) $score += 10;
            // Keyword match — also check stem (first 4+ chars)
            $kw_words = explode( ' ', $kw_lower );
            foreach ( $kw_words as $kword ) {
                if ( $kword === $w ) { $score += 5; break; }
                // Stem match: kontaktar matches kontakt, betalning matches betala
                $stem = mb_substr( $w, 0, max( 4, mb_strlen( $w ) - 2 ) );
                if ( mb_strlen( $stem ) >= 4 && mb_strpos( $kword, $stem ) === 0 ) { $score += 4; break; }
                if ( mb_strlen( $stem ) >= 4 && mb_strpos( $w, mb_substr( $kword, 0, max( 4, mb_strlen( $kword ) - 2 ) ) ) === 0 ) { $score += 4; break; }
            }
            // Content match = lower score
            if ( mb_strpos( $content_lower, $w ) !== false ) $score += 1;
        }

        if ( $score > 0 ) {
            $scored[] = [ 'entry' => $entry, 'score' => $score ];
        }
    }

    if ( empty( $scored ) ) return null;

    // Sort by score (highest first)
    usort( $scored, function( $a, $b ) { return $b['score'] - $a['score']; } );

    // Minimum score threshold — avoid irrelevant matches
    if ( $scored[0]['score'] < 5 ) return null;

    // Return the best match
    $best = $scored[0]['entry'];
    $clean = html_entity_decode( strip_tags( $best->content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

    return $clean;
}
function wpilot_telegram_notify( $text, $image_url = null ) {
    $token   = get_option( 'wpilot_telegram_token', '' );
    $chat_id = get_option( 'wpilot_telegram_chat_id', '' );

    if ( empty( $token ) || empty( $chat_id ) ) return false;

    // Rate limit: max 1 telegram per 30 seconds
    $tg_rl = get_transient( 'wpilot_tg_rl' );
    if ( $tg_rl ) return false;
    set_transient( 'wpilot_tg_rl', 1, 30 );

    $api = 'https://api.telegram.org/bot' . $token;

    if ( $image_url ) {
        // Send photo with caption
        wp_remote_post( $api . '/sendPhoto', [
            'timeout' => 10,
            'body'    => [
                'chat_id' => $chat_id,
                'photo'   => $image_url,
                'caption' => mb_substr( $text, 0, 1024 ),
                'parse_mode' => 'HTML',
            ],
        ] );
    } else {
        // Send text message
        wp_remote_post( $api . '/sendMessage', [
            'timeout' => 10,
            'body'    => [
                'chat_id'    => $chat_id,
                'text'       => mb_substr( $text, 0, 4000 ),
                'parse_mode' => 'HTML',
            ],
        ] );
    }

    return true;
}
function wpilot_chat_upload( $request ) {
    // CORS headers for cross-origin widget
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type' );
    header( 'Cache-Control: no-store' );

    if ( $request->get_method() === 'OPTIONS' ) {
        return new WP_REST_Response( null, 204 );
    }

    if ( ! get_option( 'wpilot_chat_enabled', false ) ) {
        return new WP_REST_Response( [ 'error' => 'Chat is not enabled.' ], 403 );
    }

    $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
    $key        = sanitize_text_field( $_POST['key'] ?? '' );

    if ( empty( $session_id ) || empty( $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing session_id or key.' ], 400 );
    }

    // Validate chat key
    $stored_key = get_option( 'wpilot_chat_key', '' );
    if ( empty( $stored_key ) || ! hash_equals( $stored_key, $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid chat key.' ], 403 );
    }

    // Rate limit: 10 uploads per minute per session
    $rl_key = 'wpilot_upload_rl_' . md5( $session_id );
    $rl_count = intval( get_transient( $rl_key ) ?: 0 );
    if ( $rl_count >= 10 ) {
        return new WP_REST_Response( [ 'error' => 'Too many uploads. Please wait a moment.' ], 429 );
    }
    set_transient( $rl_key, $rl_count + 1, 60 );

    // Check file exists
    if ( empty( $_FILES['file'] ) ) {
        return new WP_REST_Response( [ 'error' => 'No file uploaded.' ], 400 );
    }

    $file = $_FILES['file'];

    // Validate size: max 5MB
    if ( $file['size'] > 5 * 1024 * 1024 ) {
        return new WP_REST_Response( [ 'error' => 'File too large. Maximum 5MB.' ], 400 );
    }

    // Validate MIME type
    $allowed = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
    $finfo   = finfo_open( FILEINFO_MIME_TYPE );
    $mime    = finfo_file( $finfo, $file['tmp_name'] );
    finfo_close( $finfo );

    if ( ! in_array( $mime, $allowed, true ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid file type. Only JPG, PNG, GIF, WebP allowed.' ], 400 );
    }

    // Create upload directory
    $upload_dir  = wp_upload_dir();
    $chat_dir    = $upload_dir['basedir'] . '/wpilot-chat';
    $chat_url    = $upload_dir['baseurl'] . '/wpilot-chat';

    if ( ! file_exists( $chat_dir ) ) {
        wp_mkdir_p( $chat_dir );
        // Add index.php for security
        file_put_contents( $chat_dir . '/index.php', '<?php // Silence is golden.' );
        // Block PHP execution in upload directory
        file_put_contents( $chat_dir . '/.htaccess', "Options -Indexes\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\nAddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\nForceType text/plain\n</FilesMatch>" );
    }

    // Generate unique filename
    $ext      = pathinfo( $file['name'], PATHINFO_EXTENSION );
    $ext      = in_array( strtolower( $ext ), [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ] ) ? strtolower( $ext ) : 'jpg';
    $filename = 'chat_' . bin2hex( random_bytes( 12 ) ) . '.' . $ext;
    $filepath = $chat_dir . '/' . $filename;

    if ( ! move_uploaded_file( $file['tmp_name'], $filepath ) ) {
        return new WP_REST_Response( [ 'error' => 'Upload failed.' ], 500 );
    }

    $image_url = $chat_url . '/' . $filename;

    // Store in chat queue as image message
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_chat_queue';
    $wpdb->insert( $table, [
        'session_id'   => $session_id,
        'message'      => '[IMAGE] ' . $image_url,
        'response'     => null,
        'source'       => 'pending',
        'created_at'   => current_time( 'mysql' ),
    ]);
    $queue_id = $wpdb->insert_id;

    // Store for AI training
    $training = get_option( 'wpilot_training_data', [] );
    $training[] = [
        'type'       => 'image',
        'image_url'  => $image_url,
        'session_id' => $session_id,
        'time'       => current_time( 'Y-m-d H:i' ),
        'queue_id'   => $queue_id,
    ];
    $training = array_slice( $training, -200 ); // keep last 200
    update_option( 'wpilot_training_data', $training, false );

    // Email notification to admin
    $admin_email = get_option( 'wpilot_contact_email', '' ) ?: get_option( 'admin_email' );
    $site_name   = get_bloginfo( 'name' );
    $agent_name  = get_option( 'wpilot_agent_name', 'Chat Agent' );
    if ( ! empty( $admin_email ) ) {
        $subject = "[{$site_name}] Visitor sent an image in chat";
        $body  = "A visitor sent an image via {$agent_name} on {$site_name}.\n\n";
        $body .= "Image: {$image_url}\n\n";
        $body .= "If Claude is running (Live Mode), it will respond automatically.\n";
        $body .= "Otherwise, check your admin panel: " . admin_url( 'admin.php?page=wpilot-chat' );
        wp_mail( $admin_email, $subject, $body );
    }

    return new WP_REST_Response([
        'url'      => $image_url,
        'file_id'  => $queue_id,
        'queue_id' => $queue_id,
    ], 200 );
}
function wpilot_chat_collect_email( $request ) {
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type' );
    header( 'Cache-Control: no-store' );

    if ( $request->get_method() === 'OPTIONS' ) {
        return new WP_REST_Response( null, 204 );
    }

    $body       = $request->get_json_params();
    $session_id = sanitize_text_field( $body['session_id'] ?? '' );
    $key        = sanitize_text_field( $body['key'] ?? '' );
    $email      = sanitize_email( $body['email'] ?? '' );

    if ( empty( $session_id ) || empty( $key ) || empty( $email ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing session_id, key, or email.' ], 400 );
    }

    // Validate chat key
    $stored_key = get_option( 'wpilot_chat_key', '' );
    if ( empty( $stored_key ) || ! hash_equals( $stored_key, $key ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid chat key.' ], 403 );
    }

    // Rate limit: 5 email submissions per minute per session
    $rl_key = 'wpilot_email_rl_' . md5( $session_id );
    $rl_count = intval( get_transient( $rl_key ) ?: 0 );
    if ( $rl_count >= 5 ) {
        return new WP_REST_Response( [ 'error' => 'Too many requests. Please wait.' ], 429 );
    }
    set_transient( $rl_key, $rl_count + 1, 60 );

    // Validate email format
    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid email address.' ], 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_chat_queue';

    // Update all messages in this session with the visitor email
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET visitor_email = %s WHERE session_id = %s",
        $email, $session_id
    ) );

    // Get latest unanswered message for the email notification
    $latest = $wpdb->get_row( $wpdb->prepare(
        "SELECT message, created_at FROM {$table} WHERE session_id = %s AND response IS NULL ORDER BY created_at DESC LIMIT 1",
        $session_id
    ) );

    // Send notification email to admin
    $admin_email = get_option( 'wpilot_notification_email', '' ) ?: get_option( 'admin_email' );
    $site_name   = get_bloginfo( 'name' );
    $locale      = get_locale();
    $is_swedish  = ( strpos( $locale, 'sv' ) === 0 );
    $question    = $latest ? $latest->message : '(no message)';
    $time        = $latest ? $latest->created_at : current_time( 'mysql' );

    if ( $is_swedish ) {
        $subject = "[{$site_name}] Ny kundfråga från {$email}";
        $body_text  = "Hej!\n\n";
        $body_text .= "En besökare lämnade sin mejladress efter att inte ha fått svar i chatten.\n\n";
        $body_text .= "Besökare: {$email}\n";
        $body_text .= "Fråga: {$question}\n";
        $body_text .= "Tid: {$time}\n\n";
        $body_text .= "Svara direkt till kunden på: {$email}\n";
        $body_text .= "Eller öppna Claude Code och koppla upp dig — Sara svarar automatiskt på väntande frågor.\n\n";
        $body_text .= "Se alla meddelanden: WordPress Admin → WPilot → Chat Agent\n\n";
        $body_text .= "— WPilot";
    } else {
        $subject = "[{$site_name}] New customer question from {$email}";
        $body_text  = "Hi!\n\n";
        $body_text .= "A visitor left their email after not getting a response in the chat.\n\n";
        $body_text .= "Visitor: {$email}\n";
        $body_text .= "Question: {$question}\n";
        $body_text .= "Time: {$time}\n\n";
        $body_text .= "Reply directly to the customer at: {$email}\n";
        $body_text .= "Or open Claude Code and connect — Sara will automatically answer pending questions.\n\n";
        $body_text .= "View all messages: WordPress Admin → WPilot → Chat Agent\n\n";
        $body_text .= "— WPilot";
    }

    wp_mail( $admin_email, $subject, $body_text );

    // Telegram push notification for email collection
    $tg_site_name = get_bloginfo( 'name' );
    $latest_message = $latest ? $latest->message : '(no message)';
    $tg_email_text = "\xF0\x9F\x93\xA7 <b>Visitor left their email on {$tg_site_name}</b>\n\n";
    $tg_email_text .= "Email: " . sanitize_email( $email ) . "\n";
    $tg_email_text .= "Question: <i>" . esc_html( mb_substr( $latest_message, 0, 500 ) ) . "</i>\n\n";
    $tg_email_text .= "Reply directly or open Claude Code.";
    wpilot_telegram_notify( $tg_email_text );

    return new WP_REST_Response( [ 'success' => true ], 200 );
}
function wpilot_chat_track_session( $session_id, $msg_count ) {
    $sessions = get_option( 'wpilot_chat_sessions', [] );

    $found = false;
    foreach ( $sessions as &$s ) {
        if ( $s['id'] === $session_id ) {
            $s['messages']  = $msg_count;
            $s['last_active'] = time();
            $found = true;
            break;
        }
    }
    unset( $s );

    if ( ! $found ) {
        $sessions[] = [
            'id'          => $session_id,
            'messages'    => $msg_count,
            'started'     => time(),
            'last_active' => time(),
        ];
    }

    // Keep only last 50 sessions
    usort( $sessions, fn( $a, $b ) => $b['last_active'] - $a['last_active'] );
    $sessions = array_slice( $sessions, 0, 50 );

    update_option( 'wpilot_chat_sessions', $sessions, false );
}
function wpilot_anonymize( $text ) {
    // Strip email addresses
    $text = preg_replace( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $text );
    // Strip URLs with domains
    $text = preg_replace( '#https?://[^\s"\'<>]+#', '[URL]', $text );
    // Strip anything that looks like a password or key
    $text = preg_replace( '/(?:password|passwd|secret|key|token|api_key|auth)\s*[=:]\s*["\']?[^\s"\'<>,;]+/i', '[REDACTED]', $text );
    // Strip phone numbers
    $text = preg_replace( '/\+?\d[\d\s-]{8,}/', '[PHONE]', $text );
    // Strip IP addresses
    $text = preg_replace( '/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', '[IP]', $text );
    return $text;
}
function wpilot_handle_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_POST['wpilot_action'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wpilot_admin' ) ) return;

    $action = $_POST['wpilot_action'];

    if ( $action === 'save_profile' ) {
        update_option( 'wpilot_site_profile', [
            'owner_name'    => sanitize_text_field( $_POST['owner_name'] ?? '' ),
            'business_type' => sanitize_text_field( $_POST['business_type'] ?? '' ),
            'tone'          => sanitize_text_field( $_POST['tone'] ?? 'friendly and professional' ),
            'language'      => sanitize_text_field( $_POST['language'] ?? '' ),
            'completed'     => true,
        ]);
        wp_redirect( admin_url( 'admin.php?page=wpilot-settings&saved=profile' ) );
        exit;
    }

    if ( $action === 'create_token' ) {
        $existing = get_option( 'wpilot_tokens', [] );
        $token_limit = wpilot_is_licensed() ? 999 : 3;
        if ( count( $existing ) >= $token_limit ) {
            wp_redirect( admin_url( 'admin.php?page=wpilot&error=limit' ) );
            exit;
        }
        if ( empty( trim( $_POST['token_label'] ?? '' ) ) ) {
            wp_redirect( admin_url( 'admin.php?page=wpilot&error=name' ) );
            exit;
        }
        $style = in_array( $_POST['token_style'] ?? '', [ 'simple', 'technical' ] ) ? $_POST['token_style'] : 'simple';
        $label = sanitize_text_field( $_POST['token_label'] ?? '' ) ?: ( $style === 'technical' ? 'Technical' : 'My connection' );
        $raw   = wpilot_create_token( $style, $label );
        set_transient( 'wpilot_new_token', $raw, 600 );
        set_transient( 'wpilot_new_token_style', $style, 600 );
        set_transient( 'wpilot_new_token_label', $label, 600 );
        wp_redirect( admin_url( 'admin.php?page=wpilot&saved=token' ) );
        exit;
    }

    if ( $action === 'revoke_token' ) {
        $hash = sanitize_text_field( $_POST['token_hash'] ?? '' );
        if ( $hash ) wpilot_revoke_token( $hash );
        wp_redirect( admin_url( 'admin.php?page=wpilot&saved=revoked' ) );
        exit;
    }

    if ( $action === 'save_license' ) {
        $key = sanitize_text_field( $_POST['license_key'] ?? '' );
        if ( ! empty( $key ) ) {
            update_option( 'wpilot_license_key', $key );
            delete_transient( 'wpilot_license_status' );
            $status = wpilot_check_license();
            wp_redirect( admin_url( 'admin.php?page=wpilot-plan&saved=license&status=' . $status ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=wpilot-plan&error=nokey' ) );
        }
        exit;
    }

    if ( $action === 'remove_license' ) {
        delete_option( 'wpilot_license_key' );
        delete_transient( 'wpilot_license_status' );
        delete_transient( 'wpilot_chat_agent_licensed' );
        wp_redirect( admin_url( 'admin.php?page=wpilot-plan&saved=license_removed' ) );
        exit;
    }

    if ( $action === 'save_chat_settings' ) {
        $enabled = ! empty( $_POST['chat_enabled'] );
        update_option( 'wpilot_chat_enabled', $enabled );
        update_option( 'wpilot_whatsapp_number', sanitize_text_field( $_POST['whatsapp_number'] ?? '' ) );
        update_option( 'wpilot_contact_phone', sanitize_text_field( $_POST['contact_phone'] ?? '' ) );
        update_option( 'wpilot_contact_email', sanitize_email( $_POST['contact_email'] ?? '' ) );
        update_option( 'wpilot_agent_name', sanitize_text_field( $_POST['agent_name'] ?? '' ) );
        update_option( 'wpilot_agent_greeting', sanitize_text_field( $_POST['agent_greeting'] ?? '' ) );
        update_option( 'wpilot_agent_role', sanitize_text_field( $_POST['agent_role'] ?? '' ) );
        update_option( 'wpilot_agent_language', sanitize_text_field( $_POST['agent_language'] ?? '' ) );
        $color = sanitize_hex_color( $_POST['widget_color'] ?? $_POST['widget_color_hex'] ?? '#1a1a2e' );
        if ( $color ) update_option( 'wpilot_widget_color', $color );
        $accent = sanitize_hex_color( $_POST['widget_accent'] ?? $_POST['widget_accent_hex'] ?? '#4ec9b0' );
        if ( $accent ) update_option( 'wpilot_widget_accent', $accent );
        update_option( 'wpilot_agent_knowledge', sanitize_textarea_field( $_POST['agent_knowledge'] ?? '' ) );
        wp_redirect( admin_url( 'admin.php?page=wpilot-chat&saved=chat' ) );
        exit;
    }

    if ( $action === 'clear_unanswered' ) {
        delete_option( 'wpilot_unanswered_questions' );
        wp_redirect( admin_url( 'admin.php?page=wpilot-chat&saved=chat' ) );
        exit;
    }

    if ( $action === 'regenerate_chat_key' ) {
        $new_key = wp_generate_password( 32, false );
        update_option( 'wpilot_chat_key', $new_key );
        wp_redirect( admin_url( 'admin.php?page=wpilot-chat&saved=key' ) );
        exit;
    }

    if ( $action === 'toggle_training' ) {
        update_option( 'wpilot_training_opt_in', ! empty( $_POST['training_opt_in'] ) );
        wp_redirect( admin_url( 'admin.php?page=wpilot-settings&saved=profile' ) );
        exit;
    }

    if ( $action === 'send_feedback' ) {
        $type = sanitize_text_field( $_POST['feedback_type'] ?? 'feedback' );
        $msg  = sanitize_textarea_field( $_POST['feedback_message'] ?? '' );
        if ( ! empty( $msg ) ) {
            wp_mail( 'info@weblease.se', '[WPilot Feedback] ' . $type, $msg . "\n\nSite: " . get_site_url() . "\nEmail: " . get_option( 'admin_email' ) );
        }
        wp_redirect( admin_url( 'admin.php?page=wpilot-help&saved=feedback' ) );
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
//  AUTO-UPDATE — Check weblease.se for new plugin versions
// ══════════════════════════════════════════════════════════════

function wpilot_check_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $remote = wpilot_get_remote_version();
    if ( ! $remote || empty( $remote['version'] ) ) return $transient;

    $plugin_file = 'wpilot/wpilot.php';
    $current     = WPILOT_VERSION;

    if ( version_compare( $remote['version'], $current, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'        => 'wpilot',
            'plugin'      => $plugin_file,
            'new_version' => $remote['version'],
            'url'         => 'https://weblease.se/wpilot',
            'package'     => $remote['download_url'] ?? '',
            'icons'       => [ 'default' => 'https://weblease.se/wpilot-icon.png' ],
            'tested'      => $remote['tested'] ?? '6.8',
            'requires'    => $remote['requires'] ?? '6.0',
        ];
    } else {
        $transient->no_update[ $plugin_file ] = (object) [
            'slug'        => 'wpilot',
            'plugin'      => $plugin_file,
            'new_version' => $current,
            'url'         => 'https://weblease.se/wpilot',
        ];
    }

    return $transient;
}

function wpilot_plugin_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'wpilot' ) return $result;

    $remote = wpilot_get_remote_version();
    if ( ! $remote || empty( $remote['version'] ) ) return $result;

    return (object) [
        'name'          => 'WPilot — Powered by Claude',
        'slug'          => 'wpilot',
        'version'       => $remote['version'],
        'author'        => '<a href="https://weblease.se">Weblease</a>',
        'homepage'      => 'https://weblease.se/wpilot',
        'download_link' => $remote['download_url'] ?? '',
        'requires'      => $remote['requires'] ?? '6.0',
        'tested'        => $remote['tested'] ?? '6.8',
        'requires_php'  => '8.0',
        'sections'      => [
            'description' => 'Connect Claude AI to your WordPress site. AI-powered site management via MCP protocol.',
            'changelog'   => $remote['changelog'] ?? 'See <a href="https://weblease.se/wpilot">weblease.se/wpilot</a> for details.',
        ],
    ];
}

function wpilot_get_remote_version() {
    $cached = get_transient( 'wpilot_update_info' );
    if ( $cached !== false ) return $cached;

    $response = wp_remote_get( 'https://weblease.se/api/plugin/version', [
        'timeout' => 8,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        set_transient( 'wpilot_update_info', [], 3600 );
        return [];
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['version'] ) ) {
        set_transient( 'wpilot_update_info', [], 3600 );
        return [];
    }

    set_transient( 'wpilot_update_info', $body, 12 * HOUR_IN_SECONDS );
    return $body;
}

function wpilot_plugin_links( $links, $file ) {
    if ( $file === 'wpilot/wpilot.php' ) {
        $links[] = '<a href="https://weblease.se/wpilot" target="_blank">Docs</a>';
        $links[] = '<a href="https://weblease.se/wpilot-checkout" target="_blank" style="color:#22c55e;font-weight:600;">Upgrade to Pro</a>';
    }
    return $links;
}

// ══════════════════════════════════════════════════════════════
//  ADMIN — Menu Registration (4 sub-pages)
// ══════════════════════════════════════════════════════════════

function wpilot_register_admin() {
    $is_pro  = function_exists( 'wpilot_is_licensed' ) && wpilot_is_licensed();
    $badge   = $is_pro ? ' <span style="font-size:9px;background:rgba(78,201,176,.25);color:#4ec9b0;padding:2px 7px;border-radius:10px;font-weight:700;vertical-align:middle;margin-left:4px;">PRO</span>' : ' <span style="font-size:9px;background:rgba(234,179,8,.25);color:#eab308;padding:2px 7px;border-radius:10px;font-weight:700;vertical-align:middle;margin-left:4px;">LITE</span>';
    $cap     = 'manage_options';

    add_menu_page(
        'WPilot',
        'WPilot' . $badge,
        $cap,
        'wpilot',
        'wpilot_page_connect',
        'dashicons-cloud',
        80
    );

    add_submenu_page( 'wpilot', 'Connect — WPilot',     'Connect',     $cap, 'wpilot',     'wpilot_page_connect' );
    add_submenu_page( 'wpilot', 'Chat Agent — WPilot',  'Chat Agent',  $cap, 'wpilot-chat',     'wpilot_page_chat_agent' );
    add_submenu_page( 'wpilot', 'Plan — WPilot',        'Plan',        $cap, 'wpilot-plan',     'wpilot_page_plan' );
    add_submenu_page( 'wpilot', 'Settings — WPilot',    'Settings',    $cap, 'wpilot-settings', 'wpilot_page_settings' );
    add_submenu_page( 'wpilot', 'Help — WPilot',        'Help',        $cap, 'wpilot-help',     'wpilot_page_help' );
}


// ══════════════════════════════════════════════════════════════
//  ADMIN — All CSS (inline <style>)
// ══════════════════════════════════════════════════════════════

function wpilot_admin_css() {
    if ( function_exists( 'get_current_screen' ) ) {
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'wpilot' ) === false ) return;
    }
    ?>
<style>
/* ── Reset & Base ── */
#wpbody-content .wpi * { box-sizing: border-box !important; }
#wpbody-content .wpi { max-width: 860px !important; margin: 0 auto !important; padding: 24px 0 60px !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif !important; color: #1e293b !important; line-height: 1.6 !important; }

/* ── Hero Banner ── */
#wpbody-content .wpi .wpi-hero { background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f3460 100%) !important; border-radius: 18px 18px 0 0 !important; padding: 38px 42px 34px !important; color: #fff !important; margin-bottom: 0 !important; position: relative !important; overflow: hidden !important; }
#wpbody-content .wpi .wpi-hero::before { content: "" !important; position: absolute !important; top: -60% !important; right: -15% !important; width: 340px !important; height: 340px !important; background: radial-gradient(circle, rgba(78,201,176,0.18) 0%, transparent 70%) !important; pointer-events: none !important; }
#wpbody-content .wpi .wpi-hero::after { content: "" !important; position: absolute !important; bottom: -40% !important; left: 10% !important; width: 260px !important; height: 260px !important; background: radial-gradient(circle, rgba(99,102,241,0.1) 0%, transparent 70%) !important; pointer-events: none !important; }
#wpbody-content .wpi .wpi-hero h1 { font-size: 30px !important; font-weight: 800 !important; margin: 0 0 6px !important; display: flex !important; align-items: center !important; gap: 12px !important; position: relative !important; z-index: 1 !important; letter-spacing: -0.02em !important; color: #fff !important; }
#wpbody-content .wpi .wpi-hero .wpi-tagline { color: #94a3b8 !important; font-size: 14px !important; margin: 0 !important; position: relative !important; z-index: 1 !important; }
#wpbody-content .wpi .wpi-badge { font-size: 10px !important; background: rgba(78,201,176,0.2) !important; color: #4ec9b0 !important; padding: 3px 11px !important; border-radius: 20px !important; font-weight: 700 !important; letter-spacing: 0.04em !important; text-transform: uppercase !important; }
#wpbody-content .wpi .wpi-badge-lite { font-size: 10px !important; background: rgba(234,179,8,0.2) !important; color: #eab308 !important; padding: 3px 11px !important; border-radius: 20px !important; font-weight: 700 !important; letter-spacing: 0.04em !important; text-transform: uppercase !important; }

/* ── Navigation Bar ── */
#wpbody-content .wpi .wpi-nav { display: flex !important; gap: 2px !important; background: #f1f5f9 !important; border-radius: 0 0 18px 18px !important; padding: 6px !important; margin-bottom: 28px !important; flex-wrap: wrap !important; }
#wpbody-content .wpi .wpi-nav a { display: flex !important; align-items: center !important; gap: 7px !important; padding: 11px 20px !important; border-radius: 12px !important; font-size: 13px !important; font-weight: 600 !important; color: #64748b !important; text-decoration: none !important; transition: all 0.15s ease !important; white-space: nowrap !important; flex: 1 !important; justify-content: center !important; }
#wpbody-content .wpi .wpi-nav a:hover { color: #1e293b !important; background: #fff !important; }
#wpbody-content .wpi .wpi-nav a.wpi-nav-active { background: #1e293b !important; color: #4ec9b0 !important; box-shadow: 0 2px 8px rgba(0,0,0,0.12) !important; }
#wpbody-content .wpi .wpi-nav a svg { width: 16px !important; height: 16px !important; flex-shrink: 0 !important; }

/* ── Cards ── */
#wpbody-content .wpi .wpi-card { background: #fff !important; border: 1px solid #dde3ea !important; border-radius: 16px !important; padding: 32px !important; margin-bottom: 22px !important; box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 14px rgba(0,0,0,0.02) !important; transition: box-shadow 0.2s ease, transform 0.2s ease !important; }
#wpbody-content .wpi .wpi-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 8px 28px rgba(0,0,0,0.04) !important; }
#wpbody-content .wpi .wpi-card h2 { margin: 0 0 6px !important; font-size: 18px !important; font-weight: 700 !important; display: flex !important; align-items: center !important; gap: 10px !important; color: #1e293b !important; }
#wpbody-content .wpi .wpi-card .wpi-sub { color: #64748b !important; font-size: 14px !important; margin: 0 0 22px !important; line-height: 1.6 !important; }

/* ── Connection Panel (dark) ── */
#wpbody-content .wpi .wpi-conn { background: #0f172a !important; border-radius: 16px !important; padding: 28px 32px !important; margin-bottom: 22px !important; color: #fff !important; display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: wrap !important; }
#wpbody-content .wpi .wpi-conn-icon { width: 48px !important; height: 48px !important; background: linear-gradient(135deg, #22c55e, #16a34a) !important; border-radius: 14px !important; display: flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important; box-shadow: 0 4px 14px rgba(34,197,94,0.3) !important; }
#wpbody-content .wpi .wpi-conn-icon svg { width: 24px !important; height: 24px !important; color: #fff !important; }
#wpbody-content .wpi .wpi-conn-body { flex: 1 !important; min-width: 200px !important; }
#wpbody-content .wpi .wpi-conn-label { font-size: 12px !important; color: #94a3b8 !important; text-transform: uppercase !important; letter-spacing: 0.06em !important; font-weight: 600 !important; margin-bottom: 6px !important; }
#wpbody-content .wpi .wpi-conn-url { font-size: 16px !important; font-family: "SF Mono", "Fira Code", Monaco, Consolas, monospace !important; color: #4ec9b0 !important; word-break: break-all !important; line-height: 1.5 !important; }
#wpbody-content .wpi .wpi-conn-copy { padding: 12px 28px !important; background: linear-gradient(135deg, #22c55e, #16a34a) !important; color: #fff !important; border: none !important; border-radius: 12px !important; font-size: 14px !important; font-weight: 700 !important; cursor: pointer !important; transition: all 0.15s ease !important; white-space: nowrap !important; display: flex !important; align-items: center !important; gap: 8px !important; box-shadow: 0 4px 14px rgba(34,197,94,0.3) !important; }
#wpbody-content .wpi .wpi-conn-copy:hover { transform: translateY(-1px) !important; box-shadow: 0 6px 20px rgba(34,197,94,0.4) !important; }

/* ── Status Indicator ── */
#wpbody-content .wpi .wpi-status { display: flex !important; align-items: center !important; gap: 8px !important; margin-top: 12px !important; }
#wpbody-content .wpi .wpi-status-dot { width: 10px !important; height: 10px !important; border-radius: 50% !important; flex-shrink: 0 !important; }
#wpbody-content .wpi .wpi-status-dot.online { background: #22c55e !important; box-shadow: 0 0 8px rgba(34,197,94,0.6) !important; animation: wpiPulse 2s ease-in-out infinite !important; }
#wpbody-content .wpi .wpi-status-dot.offline { background: #64748b !important; }
#wpbody-content .wpi .wpi-status-text { font-size: 13px !important; color: #94a3b8 !important; }
@keyframes wpiPulse { 0%, 100% { box-shadow: 0 0 4px rgba(34,197,94,0.4); } 50% { box-shadow: 0 0 12px rgba(34,197,94,0.8); } }

/* ── Tabs (Desktop / Terminal) ── */
#wpbody-content .wpi .wpi-tabs { display: flex !important; gap: 4px !important; margin-bottom: 24px !important; }
#wpbody-content .wpi .wpi-tab { display: flex !important; align-items: center !important; gap: 8px !important; padding: 10px 22px !important; border-radius: 10px !important; border: 2px solid #dde3ea !important; background: #fff !important; font-size: 14px !important; font-weight: 600 !important; color: #64748b !important; cursor: pointer !important; transition: all 0.15s ease !important; }
#wpbody-content .wpi .wpi-tab:hover { border-color: #94a3b8 !important; color: #1e293b !important; }
#wpbody-content .wpi .wpi-tab.active { background: #1e293b !important; color: #4ec9b0 !important; border-color: #1e293b !important; }
#wpbody-content .wpi .wpi-tab svg { width: 18px !important; height: 18px !important; }
#wpbody-content .wpi .wpi-tab-panel { display: none !important; }
#wpbody-content .wpi .wpi-tab-panel.active { display: block !important; }

/* ── Step Circles ── */
#wpbody-content .wpi .wpi-step { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 36px !important; height: 36px !important; border-radius: 50% !important; background: linear-gradient(135deg, #22c55e, #16a34a) !important; color: #fff !important; font-size: 15px !important; font-weight: 800 !important; flex-shrink: 0 !important; box-shadow: 0 3px 10px rgba(34,197,94,0.3) !important; margin-right: 16px !important; }
#wpbody-content .wpi .wpi-step-row { display: flex !important; align-items: flex-start !important; margin-bottom: 24px !important; }
#wpbody-content .wpi .wpi-step-body h3 { margin: 0 0 4px !important; font-size: 15px !important; font-weight: 700 !important; color: #1e293b !important; }
#wpbody-content .wpi .wpi-step-body p { margin: 0 !important; font-size: 13px !important; color: #64748b !important; line-height: 1.6 !important; }
#wpbody-content .wpi .wpi-step-highlight { background: #f0fdf4 !important; border: 1px solid #bbf7d0 !important; border-radius: 10px !important; padding: 10px 16px !important; margin-top: 8px !important; font-size: 13px !important; color: #166534 !important; font-weight: 600 !important; }

/* ── Code Block ── */
#wpbody-content .wpi .wpi-code { display: block !important; padding: 20px 22px !important; padding-right: 90px !important; background: #0f172a !important; color: #5eead4 !important; border-radius: 12px !important; font-size: 13px !important; font-family: "SF Mono", "Fira Code", Monaco, Consolas, monospace !important; word-break: break-all !important; line-height: 1.8 !important; cursor: pointer !important; transition: all 0.15s ease !important; border: 2px solid rgba(78,201,176,0.15) !important; margin-top: 10px !important; white-space: pre-wrap !important; position: relative !important; box-shadow: 0 4px 16px rgba(0,0,0,0.2) !important; }
#wpbody-content .wpi .wpi-code::after { content: "COPY" !important; position: absolute !important; top: 12px !important; right: 12px !important; background: linear-gradient(135deg, #22c55e, #16a34a) !important; color: #fff !important; font-family: -apple-system, BlinkMacSystemFont, sans-serif !important; font-size: 11px !important; font-weight: 700 !important; padding: 6px 14px !important; border-radius: 6px !important; letter-spacing: 0.05em !important; box-shadow: 0 2px 6px rgba(34,197,94,0.3) !important; transition: all 0.15s !important; }
#wpbody-content .wpi .wpi-code:hover { border-color: #4ec9b0 !important; box-shadow: 0 4px 20px rgba(0,0,0,0.25), 0 0 0 3px rgba(78,201,176,0.1) !important; }
#wpbody-content .wpi .wpi-code:hover::after { background: linear-gradient(135deg, #10b981, #059669) !important; transform: translateY(-1px) !important; box-shadow: 0 4px 12px rgba(34,197,94,0.4) !important; }
#wpbody-content .wpi .wpi-code.copied::after { content: "COPIED!" !important; background: #059669 !important; }
#wpbody-content .wpi .wpi-code-hint { display: block !important; font-size: 12px !important; color: #64748b !important; margin-top: 6px !important; }

/* ── Form Fields ── */
#wpbody-content .wpi .wpi-field { margin-bottom: 20px !important; }
#wpbody-content .wpi .wpi-field label { display: block !important; font-weight: 600 !important; font-size: 13px !important; color: #374151 !important; margin-bottom: 6px !important; }
#wpbody-content .wpi .wpi-field .wpi-hint { display: block !important; font-size: 12px !important; color: #94a3b8 !important; margin-top: 4px !important; }
#wpbody-content .wpi .wpi-field input[type=text],
#wpbody-content .wpi .wpi-field input[type=email],
#wpbody-content .wpi .wpi-field textarea,
#wpbody-content .wpi .wpi-field select { width: 100% !important; max-width: 100% !important; padding: 11px 16px !important; border: 2px solid #dde3ea !important; border-radius: 10px !important; font-size: 14px !important; color: #1e293b !important; background: #fafbfc !important; transition: all 0.15s ease !important; font-family: inherit !important; }
#wpbody-content .wpi .wpi-field input:focus,
#wpbody-content .wpi .wpi-field textarea:focus,
#wpbody-content .wpi .wpi-field select:focus { border-color: #4ec9b0 !important; outline: none !important; box-shadow: 0 0 0 4px rgba(78,201,176,0.15) !important; background: #fff !important; }
#wpbody-content .wpi .wpi-field textarea { max-width: 100% !important; min-height: 120px !important; resize: vertical !important; }

/* ── Buttons ── */
#wpbody-content .wpi .wpi-btn { display: inline-flex !important; align-items: center !important; gap: 8px !important; padding: 12px 26px !important; border-radius: 10px !important; font-size: 14px !important; font-weight: 700 !important; cursor: pointer !important; border: none !important; transition: all 0.15s ease !important; text-decoration: none !important; letter-spacing: 0.01em !important; }
#wpbody-content .wpi .wpi-btn-dark { background: #1e293b !important; color: #fff !important; }
#wpbody-content .wpi .wpi-btn-dark:hover { background: #334155 !important; transform: translateY(-1px) !important; box-shadow: 0 4px 14px rgba(30,41,59,0.3) !important; color: #fff !important; }
#wpbody-content .wpi .wpi-btn-green { background: linear-gradient(135deg, #22c55e, #16a34a) !important; color: #fff !important; }
#wpbody-content .wpi .wpi-btn-green:hover { transform: translateY(-1px) !important; box-shadow: 0 4px 14px rgba(34,197,94,0.4) !important; color: #fff !important; }
#wpbody-content .wpi .wpi-btn-red { background: transparent !important; border: 2px solid #fca5a5 !important; color: #dc2626 !important; padding: 8px 18px !important; font-size: 13px !important; }
#wpbody-content .wpi .wpi-btn-red:hover { background: #fef2f2 !important; }
#wpbody-content .wpi .wpi-btn-sm { padding: 8px 16px !important; font-size: 12px !important; border-radius: 8px !important; }

/* ── Alerts ── */
#wpbody-content .wpi .wpi-alert { padding: 16px 20px !important; border-radius: 12px !important; margin-bottom: 22px !important; font-size: 14px !important; line-height: 1.6 !important; }
#wpbody-content .wpi .wpi-alert-ok { background: #f0fdf4 !important; border: 1px solid #bbf7d0 !important; color: #166534 !important; }
#wpbody-content .wpi .wpi-alert-warn { background: #fffbeb !important; border: 1px solid #fde68a !important; color: #92400e !important; }
#wpbody-content .wpi .wpi-alert-info { background: #eff6ff !important; border: 1px solid #bfdbfe !important; color: #1e40af !important; }
#wpbody-content .wpi .wpi-alert strong { display: block !important; margin-bottom: 2px !important; }

/* ── Token Table ── */
#wpbody-content .wpi .wpi-table { width: 100% !important; border-collapse: collapse !important; margin-top: 22px !important; }
#wpbody-content .wpi .wpi-table th { text-align: left !important; font-size: 11px !important; color: #94a3b8 !important; text-transform: uppercase !important; letter-spacing: 0.07em !important; padding: 10px 14px !important; border-bottom: 2px solid #f1f5f9 !important; font-weight: 700 !important; }
#wpbody-content .wpi .wpi-table td { padding: 14px !important; border-bottom: 1px solid #f1f5f9 !important; font-size: 14px !important; color: #475569 !important; }
#wpbody-content .wpi .wpi-table tr:hover td { background: #fafbfc !important; }
#wpbody-content .wpi .wpi-style-badge { display: inline-block !important; padding: 3px 12px !important; border-radius: 20px !important; font-size: 12px !important; font-weight: 600 !important; }
#wpbody-content .wpi .wpi-style-simple { background: #e0f2fe !important; color: #0284c7 !important; }
#wpbody-content .wpi .wpi-style-technical { background: #ede9fe !important; color: #7c3aed !important; }

/* ── Progress Bar ── */
#wpbody-content .wpi .wpi-progress { background: #f1f5f9 !important; border-radius: 8px !important; height: 12px !important; margin: 12px 0 20px !important; overflow: hidden !important; }
#wpbody-content .wpi .wpi-progress-bar { height: 100% !important; border-radius: 8px !important; transition: width 0.4s ease !important; }

/* ── Pricing Cards ── */
#wpbody-content .wpi .wpi-pricing { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 18px !important; margin-top: 24px !important; }
#wpbody-content .wpi .wpi-plan { background: #fff !important; border: 2px solid #dde3ea !important; border-radius: 16px !important; padding: 30px 24px !important; text-align: center !important; transition: all 0.2s ease !important; position: relative !important; }
#wpbody-content .wpi .wpi-plan:hover { border-color: #4ec9b0 !important; transform: translateY(-3px) !important; box-shadow: 0 8px 28px rgba(0,0,0,0.08) !important; }
#wpbody-content .wpi .wpi-plan-pop { border-color: #4ec9b0 !important; box-shadow: 0 4px 18px rgba(78,201,176,0.15) !important; }
#wpbody-content .wpi .wpi-plan-pop::before { content: "Most popular" !important; position: absolute !important; top: -13px !important; left: 50% !important; transform: translateX(-50%) !important; background: linear-gradient(135deg, #4ec9b0, #22c55e) !important; color: #fff !important; font-size: 11px !important; font-weight: 700 !important; padding: 4px 18px !important; border-radius: 20px !important; text-transform: uppercase !important; letter-spacing: 0.03em !important; }
#wpbody-content .wpi .wpi-plan h3 { margin: 0 0 8px !important; font-size: 18px !important; font-weight: 800 !important; color: #1e293b !important; }
#wpbody-content .wpi .wpi-plan .wpi-price { font-size: 38px !important; font-weight: 800 !important; color: #0f172a !important; margin: 14px 0 4px !important; }
#wpbody-content .wpi .wpi-plan .wpi-price span { font-size: 15px !important; font-weight: 400 !important; color: #94a3b8 !important; }
#wpbody-content .wpi .wpi-plan .wpi-price-note { font-size: 13px !important; color: #94a3b8 !important; margin: 0 0 18px !important; }
#wpbody-content .wpi .wpi-plan ul { list-style: none !important; padding: 0 !important; margin: 0 0 22px !important; text-align: left !important; }
#wpbody-content .wpi .wpi-plan ul li { padding: 7px 0 !important; font-size: 13px !important; color: #475569 !important; display: flex !important; gap: 8px !important; align-items: start !important; }
#wpbody-content .wpi .wpi-plan ul li::before { content: "\2713" !important; color: #22c55e !important; font-weight: 700 !important; flex-shrink: 0 !important; }
#wpbody-content .wpi .wpi-plan .wpi-btn { width: 100% !important; justify-content: center !important; }

/* ── 2x2 Grid ── */
#wpbody-content .wpi .wpi-grid-2 { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 18px !important; }

/* ── Example Cards ── */
#wpbody-content .wpi .wpi-examples { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 16px !important; margin-top: 18px !important; }
#wpbody-content .wpi .wpi-example { background: #f8fafc !important; border-radius: 12px !important; padding: 20px 22px !important; border: 1px solid #f1f5f9 !important; transition: all 0.15s ease !important; }
#wpbody-content .wpi .wpi-example:hover { border-color: #dde3ea !important; transform: translateY(-1px) !important; }
#wpbody-content .wpi .wpi-example h3 { font-size: 12px !important; color: #94a3b8 !important; text-transform: uppercase !important; letter-spacing: 0.06em !important; margin: 0 0 10px !important; font-weight: 700 !important; display: flex !important; align-items: center !important; gap: 6px !important; }
#wpbody-content .wpi .wpi-example p { margin: 0 !important; padding: 6px 0 !important; color: #475569 !important; font-size: 13px !important; font-style: italic !important; line-height: 1.5 !important; border-bottom: 1px solid #f1f5f9 !important; }
#wpbody-content .wpi .wpi-example p:last-child { border: none !important; }

/* ── Radio Group (Feedback) ── */
#wpbody-content .wpi .wpi-radios { display: flex !important; gap: 6px !important; flex-wrap: wrap !important; margin-bottom: 16px !important; }
#wpbody-content .wpi .wpi-radios label { display: flex !important; align-items: center !important; gap: 6px !important; padding: 8px 16px !important; border-radius: 8px !important; border: 2px solid #dde3ea !important; font-size: 13px !important; font-weight: 600 !important; color: #64748b !important; cursor: pointer !important; transition: all 0.15s ease !important; }
#wpbody-content .wpi .wpi-radios label:hover { border-color: #94a3b8 !important; color: #1e293b !important; }
#wpbody-content .wpi .wpi-radios input[type=radio] { display: none !important; }
#wpbody-content .wpi .wpi-radios input[type=radio]:checked + span { color: #4ec9b0 !important; }
#wpbody-content .wpi .wpi-radios label:has(input:checked) { border-color: #4ec9b0 !important; background: rgba(78,201,176,0.06) !important; color: #4ec9b0 !important; }

/* ── Info Row (why multiple connections) ── */
#wpbody-content .wpi .wpi-info-row { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 14px !important; margin-top: 24px !important; }
#wpbody-content .wpi .wpi-info-item { background: #f8fafc !important; border: 1px solid #f1f5f9 !important; border-radius: 12px !important; padding: 18px !important; text-align: center !important; }
#wpbody-content .wpi .wpi-info-item h4 { margin: 8px 0 4px !important; font-size: 13px !important; font-weight: 700 !important; color: #1e293b !important; }
#wpbody-content .wpi .wpi-info-item p { margin: 0 !important; font-size: 12px !important; color: #64748b !important; }

/* ── Support Links ── */
#wpbody-content .wpi .wpi-links { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 12px !important; margin-top: 20px !important; }
#wpbody-content .wpi .wpi-links a { display: flex !important; align-items: center !important; gap: 10px !important; padding: 16px 20px !important; background: #f8fafc !important; border: 2px solid #eef2f6 !important; border-radius: 12px !important; color: #1e293b !important; text-decoration: none !important; font-weight: 600 !important; font-size: 14px !important; transition: all 0.15s ease !important; }
#wpbody-content .wpi .wpi-links a:hover { border-color: #4ec9b0 !important; background: #f0fdf4 !important; color: #047857 !important; transform: translateY(-1px) !important; box-shadow: 0 4px 12px rgba(78,201,176,0.15) !important; }
#wpbody-content .wpi .wpi-links a svg { color: #4ec9b0 !important; stroke: #4ec9b0 !important; flex-shrink: 0 !important; }

/* ── Mobile ── */
@media (max-width: 782px) {
    #wpbody-content .wpi { padding: 16px 10px 40px !important; }
    #wpbody-content .wpi .wpi-hero { padding: 24px 20px !important; }
    #wpbody-content .wpi .wpi-hero h1 { font-size: 22px !important; }
    #wpbody-content .wpi .wpi-nav { flex-wrap: wrap !important; }
    #wpbody-content .wpi .wpi-nav a { flex: none !important; padding: 9px 14px !important; font-size: 12px !important; }
    #wpbody-content .wpi .wpi-card { padding: 22px 18px !important; }
    #wpbody-content .wpi .wpi-conn { flex-direction: column !important; padding: 22px 18px !important; text-align: center !important; }
    #wpbody-content .wpi .wpi-conn-copy { width: 100% !important; justify-content: center !important; }
    #wpbody-content .wpi .wpi-pricing { grid-template-columns: 1fr !important; }
    #wpbody-content .wpi .wpi-grid-2 { grid-template-columns: 1fr !important; }
    #wpbody-content .wpi .wpi-examples { grid-template-columns: 1fr !important; }
    #wpbody-content .wpi .wpi-info-row { grid-template-columns: 1fr !important; }
    #wpbody-content .wpi .wpi-tabs { flex-wrap: wrap !important; }
}
</style>
    <?php
}

// ══════════════════════════════════════════════════════════════
//  ADMIN — Hero + Navigation bar
// ══════════════════════════════════════════════════════════════

function wpilot_page_nav( $current ) {
    $is_pro = function_exists( 'wpilot_is_licensed' ) && wpilot_is_licensed();
    $pages  = [
        'wpilot'     => [
            'label' => 'Connect',
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        ],
        'wpilot-chat'     => [
            'label' => 'Chat Agent',
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        ],
        'wpilot-plan'     => [
            'label' => 'Plan',
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
        ],
        'wpilot-settings' => [
            'label' => 'Settings',
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        ],
        'wpilot-help'     => [
            'label' => 'Help',
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        ],
    ];
    ?>
    <div class="wpi">

        <!-- Hero -->
        <div class="wpi-hero">
            <h1>WPilot <?php if ( $is_pro ): ?><span class="wpi-badge">PRO</span><?php else: ?><span class="wpi-badge-lite">LITE</span><?php endif; ?> <span class="wpi-badge">v<?php echo esc_html( WPILOT_VERSION ); ?></span></h1>
            <p class="wpi-tagline">Manage your entire WordPress site with AI. Just talk — Claude does the rest.</p>
        </div>

        <!-- Nav -->
        <div class="wpi-nav">
            <?php foreach ( $pages as $slug => $p ): ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo $slug === $current ? 'wpi-nav-active' : ''; ?>">
                    <?php echo $p['icon']; ?>
                    <?php echo esc_html( $p['label'] ); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php
}

// ══════════════════════════════════════════════════════════════
//  PAGE: Connect
// ══════════════════════════════════════════════════════════════

function wpilot_page_connect() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    wpilot_admin_css();
    wpilot_page_nav( 'wpilot' );

    $tokens    = wpilot_get_tokens();
    $new_token = get_transient( 'wpilot_new_token' );
    $new_style = get_transient( 'wpilot_new_token_style' );
    $new_label = get_transient( 'wpilot_new_token_label' );
    $site_url  = get_site_url();
    $mcp_url   = $site_url . '/wp-json/wpilot/v1/mcp';
    $saved     = sanitize_text_field( $_GET['saved'] ?? '' );
    $rest_url  = rest_url( 'wpilot/v1/connection-status' );

    // Alerts
    if ( $saved === 'token' ) {
        echo '<div class="wpi-alert wpi-alert-ok"><strong>Connection created!</strong> Copy the address below and follow the guide to connect Claude.</div>';
        if ( $new_token ) {
            // Token visible for 10 minutes (transient auto-expires)
            
            
        }
    } elseif ( $saved === 'revoked' ) {
        echo '<div class="wpi-alert wpi-alert-warn"><strong>Token revoked.</strong> That connection will no longer work.</div>';
    }

    
    ?>

    <?php // ─── How to Connect ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            Get started
        </h2>
        <p class="wpi-sub">Connect Claude to your website in under 2 minutes. Pick how you want to use it:</p>

        <div class="wpi-tabs">
            <button type="button" class="wpi-tab active" onclick="wpiTab('desktop')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Claude Desktop
                <span style="font-size:9px !important;background:rgba(78,201,176,0.2) !important;color:#4ec9b0 !important;padding:2px 7px !important;border-radius:8px !important;font-weight:700 !important;margin-left:2px !important;">EASIEST</span>
            </button>
            <button type="button" class="wpi-tab" onclick="wpiTab('terminal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                Claude Code (Terminal)
            </button>
        </div>

        <?php
        $token_display = $new_token ? esc_attr( $new_token ) : '';
        $token_masked = $new_token ? substr($new_token, 0, 8) . '********' . substr($new_token, -6) : '';
        $has_token = !empty($new_token);
        ?>

        <!-- Desktop Tab -->
        <div class="wpi-tab-panel active" id="wpi-panel-desktop">

            <div style="background:#f0fdf4 !important;border:2px solid #bbf7d0 !important;border-radius:14px !important;padding:20px 24px !important;margin-bottom:24px !important;">
                <h3 style="margin:0 0 4px !important;font-size:15px !important;color:#166534 !important;">Best for: Non-technical users</h3>
                <p style="margin:0 !important;font-size:13px !important;color:#15803d !important;">Point-and-click app. Chat with Claude about your website like you would with a colleague.</p>
            </div>

            <?php if ( $has_token ): ?>

            <!-- HAS TOKEN: Simple 3-step flow -->
            <?php if ( $new_label = get_transient('wpilot_new_token_label') ): ?>
            <div style="background:#f0fdf4 !important;border:2px solid #bbf7d0 !important;border-radius:12px !important;padding:14px 20px !important;margin-bottom:20px !important;font-size:14px !important;color:#166534 !important;font-weight:600 !important;">
                &#10003; Connection "<strong><?php echo esc_html($new_label); ?></strong>" created! Now follow these 3 steps:
            </div>
            <?php endif; ?>

            <div class="wpi-step-row">
                <span class="wpi-step">1</span>
                <div class="wpi-step-body">
                    <h3>Copy your connection code</h3>
                    <p>Click to copy:</p>
                    <code class="wpi-code" onclick="wpiCopy(this)" style="margin-top:6px !important;font-size:12px !important;" data-copy="{&#10;  &quot;mcpServers&quot;: {&#10;    &quot;wpilot&quot;: {&#10;      &quot;command&quot;: &quot;npx&quot;,&#10;      &quot;args&quot;: [&quot;-y&quot;, &quot;mcp-remote&quot;, &quot;<?php echo esc_url( $mcp_url ); ?>&quot;, &quot;--header&quot;, &quot;Authorization:${AUTH_HEADER}&quot;],&#10;      &quot;env&quot;: { &quot;AUTH_HEADER&quot;: &quot;Bearer <?php echo $token_display; ?>&quot; }&#10;    }&#10;  }&#10;}">{
  "mcpServers": {
    "wpilot": {
      "command": "npx",
      "args": ["-y", "mcp-remote",
        "<?php echo esc_url( $mcp_url ); ?>",
        "--header",
        "Authorization:${AUTH_HEADER}"],
      "env": {
        "AUTH_HEADER": "Bearer <?php echo $token_masked; ?>"
      }
    }
  }
}</code>
                    <p style="font-size:11px !important;color:#f59e0b !important;margin:6px 0 0 !important;">Token hidden. Click copies the full version.</p>
                </div>
            </div>

            <div class="wpi-step-row">
                <span class="wpi-step">2</span>
                <div class="wpi-step-body">
                    <h3>Open Claude Desktop &rarr; New session</h3>
                    <p>Open the <a href="https://claude.ai/download" target="_blank" style="color:#4ec9b0 !important;font-weight:600 !important;">Claude app</a> on your computer and start a new conversation.</p>
                </div>
            </div>

            <div class="wpi-step-row" style="margin-bottom:0 !important;">
                <span class="wpi-step">3</span>
                <div class="wpi-step-body">
                    <h3>Paste the code &rarr; Send</h3>
                    <p>Paste the code into the chat and tell Claude: <strong>"Connect me to my website"</strong>. Claude handles the rest.</p>
                    <div class="wpi-step-highlight">Done! Start chatting about your website.</div>
                </div>
            </div>

            <?php else: ?>

            <!-- NO TOKEN: Guide to create one -->
            <div class="wpi-step-row">
                <span class="wpi-step">1</span>
                <div class="wpi-step-body">
                    <h3>Download Claude</h3>
                    <p>Get the free app from <a href="https://claude.ai/download" target="_blank" style="color:#4ec9b0 !important;font-weight:600 !important;">claude.ai/download</a> and sign in.</p>
                </div>
            </div>
            <div class="wpi-step-row">
                <span class="wpi-step">2</span>
                <div class="wpi-step-body">
                    <h3>Create a connection</h3>
                    <p>Scroll down to <strong>"Create new connection"</strong> below. Type a name (like "My laptop") and click <strong>Create</strong>.</p>
                    <div class="wpi-step-highlight">The setup instructions will appear here automatically.</div>
                </div>
            </div>

            <?php endif; ?>
        </div>

        <!-- Terminal Tab: Claude Code -->
        <div class="wpi-tab-panel" id="wpi-panel-terminal">

            <div style="background:#eff6ff !important;border:2px solid #bfdbfe !important;border-radius:14px !important;padding:20px 24px !important;margin-bottom:24px !important;">
                <h3 style="margin:0 0 4px !important;font-size:15px !important;color:#1e40af !important;">Best for: Developers &amp; power users</h3>
                <p style="margin:0 !important;font-size:13px !important;color:#1d4ed8 !important;">Terminal-based. One command to connect, then manage your site from the command line. Use the <a href="https://chromewebstore.google.com/detail/claude-code/dfcojoapopilbkdojnhcfgiamaigeogp" target="_blank" style="color:#1e40af !important;font-weight:700 !important;">Chrome extension</a> for a live browser view.</p>
            </div>

            <div class="wpi-step-row">
                <span class="wpi-step">1</span>
                <div class="wpi-step-body">
                    <h3>Install Claude Code</h3>
                    <p>Open a terminal and run:</p>
                    <code class="wpi-code" onclick="wpiCopy(this)" style="margin-top:8px !important;font-size:14px !important;padding:14px 18px !important;line-height:1.4 !important;" data-copy="npm install -g @anthropic-ai/claude-code">npm install -g @anthropic-ai/claude-code</code>
                </div>
            </div>
            <div class="wpi-step-row">
                <span class="wpi-step">2</span>
                <div class="wpi-step-body">
                    <h3>Create a connection below</h3>
                    <p>Scroll down to <strong>"Create new connection"</strong>, type a name, and click <strong>Create</strong>.</p>
                </div>
            </div>
            <div class="wpi-step-row">
                <span class="wpi-step">3</span>
                <div class="wpi-step-body">
                    <h3>Copy-paste one command</h3>
                    <p>Click the command below to copy it, paste it in your terminal, and press Enter.</p>
                </div>
            </div>
            <div class="wpi-step-row" style="margin-bottom:0 !important;">
                <span class="wpi-step">4</span>
                <div class="wpi-step-body">
                    <h3>Start a new session</h3>
                    <p>Type <code style="background:#e2e8f0 !important;padding:2px 8px !important;border-radius:4px !important;font-size:13px !important;">claude</code> to open a new session. WPilot tools are now available &mdash; just start chatting about your website!</p>
                </div>
            </div>

            <?php if ( $has_token ): ?>
            <div style="margin-top:24px !important;">
                <p style="font-size:12px !important;font-weight:700 !important;text-transform:uppercase !important;letter-spacing:0.06em !important;color:#64748b !important;margin:0 0 8px !important;">Your command — click to copy:</p>
                <div style="background:linear-gradient(135deg,#0c0c1d,#111827) !important;border:2px solid rgba(78,201,176,0.3) !important;border-radius:14px !important;padding:22px !important;">
                    <?php if ( $new_label = get_transient('wpilot_new_token_label') ): ?>
                    <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 0 10px !important;">Connection: <strong style="color:#fff !important;"><?php echo esc_html($new_label); ?></strong></p>
                    <?php endif; ?>
                    <code class="wpi-code" onclick="wpiCopy(this)" style="margin:0 !important;border:none !important;background:rgba(255,255,255,0.04) !important;" data-copy="claude mcp add-json wpilot '{&quot;type&quot;:&quot;http&quot;,&quot;url&quot;:&quot;<?php echo esc_url( $mcp_url ); ?>&quot;,&quot;headers&quot;:{&quot;Authorization&quot;:&quot;Bearer <?php echo $token_display; ?>&quot;}}' --scope user">claude mcp add-json wpilot '{"type":"http","url":"<?php echo esc_url( $mcp_url ); ?>","headers":{"Authorization":"Bearer <?php echo $token_masked; ?>"}}' --scope user</code>
                    <p style="font-size:12px !important;color:#f59e0b !important;margin:10px 0 0 !important;font-weight:600 !important;">&#9888; Token hidden. Click to copy with full token.</p>
                </div>

                <div style="background:#f8fafc !important;border:2px solid #e2e8f0 !important;border-radius:14px !important;padding:18px 22px !important;margin-top:16px !important;">
                    <h4 style="margin:0 0 6px !important;font-size:14px !important;color:#1e293b !important;">Then start Claude:</h4>
                    <code class="wpi-code" onclick="wpiCopy(this)" style="font-size:15px !important;padding:12px 18px !important;margin:0 !important;" data-copy="claude">claude</code>
                    <p style="font-size:13px !important;color:#64748b !important;margin:8px 0 0 !important;">That's it! You're connected. Try saying: <em style="color:#4ec9b0 !important;">"Give me an overview of my site"</em></p>
                </div>
            </div>
            <?php else: ?>
            <div style="background:#fefce8 !important;border:2px solid #fcd34d !important;border-radius:14px !important;padding:20px 24px !important;margin-top:24px !important;text-align:center !important;">
                <p style="font-size:14px !important;font-weight:600 !important;color:#92400e !important;margin:0 !important;">&#x1F447; Create a connection first, then the command will appear here.</p>
            </div>
            <?php endif; ?>

            <?php // ─── Chrome Extension Tip ─── ?>
            <div style="background:linear-gradient(135deg, #fdf4ff, #fae8ff) !important;border:2px solid #e9d5ff !important;border-radius:14px !important;padding:20px 24px !important;margin-top:20px !important;display:flex !important;gap:16px !important;align-items:center !important;flex-wrap:wrap !important;">
                <div style="font-size:32px !important;flex-shrink:0 !important;">&#127912;</div>
                <div style="flex:1 !important;min-width:200px !important;">
                    <h4 style="margin:0 0 4px !important;font-size:14px !important;color:#7c3aed !important;">Pro tip: Use the Chrome extension</h4>
                    <p style="margin:0 !important;font-size:13px !important;color:#6b21a8 !important;line-height:1.5 !important;">See changes live in your browser while Claude works. Install the <a href="https://chromewebstore.google.com/detail/claude-code/dfcojoapopilbkdojnhcfgiamaigeogp" target="_blank" style="color:#7c3aed !important;font-weight:700 !important;">Claude Code Chrome extension</a> &mdash; it shows a live preview of every change Claude makes to your site.</p>
                </div>
            </div>
        </div>

    <?php // ─── Active Connections ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Connections
        </h2>
        <p class="wpi-sub">Each person or device that connects Claude needs their own connection.</p>

        <?php if ( ! empty( $tokens ) ): ?>
        <table class="wpi-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Mode</th>
                    <th>Created</th>
                    <th>Last used</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tokens as $t ): $s = $t['style'] ?? 'simple'; ?>
                <tr>
                    <td style="font-weight:600 !important;"><?php echo esc_html( $t['label'] ?? '—' ); ?></td>
                    <td><span class="wpi-style-badge wpi-style-<?php echo esc_attr( $s ); ?>"><?php echo $s === 'technical' ? 'Technical' : 'Simple'; ?></span></td>
                    <td><?php echo esc_html( $t['created'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $t['last_used'] ?? 'Never' ); ?></td>
                    <td style="text-align:right !important;">
                        <form method="post" style="display:inline !important;" onsubmit="return confirm('Revoke this connection? It will stop working immediately.');">
                            <?php wp_nonce_field( 'wpilot_admin' ); ?>
                            <input type="hidden" name="wpilot_action" value="revoke_token">
                            <input type="hidden" name="token_hash" value="<?php echo esc_attr( $t['hash'] ); ?>">
                            <button type="submit" class="wpi-btn wpi-btn-red wpi-btn-sm">Revoke</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="margin-top:24px !important;padding-top:24px !important;border-top:1px solid #f1f5f9 !important;">
            <h3 style="font-size:15px !important;font-weight:700 !important;margin:0 0 14px !important;color:#1e293b !important;">Create new connection</h3>
            <form method="post" style="display:flex !important;gap:12px !important;align-items:end !important;flex-wrap:wrap !important;">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="create_token">
                <div class="wpi-field" style="margin:0 !important;min-width:160px !important;">
                    <label>Name</label>
                    <input type="text" name="token_label" placeholder="e.g. Lisa, Office Mac" style="max-width:220px !important;">
                </div>
                <div class="wpi-field" style="margin:0 !important;min-width:180px !important;">
                    <label>Mode</label>
                    <select name="token_style" style="max-width:260px !important;">
                        <option value="simple">Simple — friendly, no code details</option>
                        <option value="technical">Technical — includes IDs &amp; code refs</option>
                    </select>
                </div>
                <button type="submit" class="wpi-btn wpi-btn-green" style="margin-bottom:0 !important;">Create</button>
            </form>
        </div>

        <div class="wpi-info-row">
            <div class="wpi-info-item">
                <div style="font-size:24px !important;">&#128187;</div>
                <h4>Multiple devices</h4>
                <p>One connection per computer — home, office, laptop.</p>
            </div>
            <div class="wpi-info-item">
                <div style="font-size:24px !important;">&#128101;</div>
                <h4>Team members</h4>
                <p>Give each person their own connection with the right mode.</p>
            </div>
            <div class="wpi-info-item">
                <div style="font-size:24px !important;">&#127758;</div>
                <h4>Clients</h4>
                <p>Let clients manage their own site with Simple mode.</p>
            </div>
        </div>
    </div>

    <?php // ─── What Happens After Connecting ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            What happens after connecting?
        </h2>
        <p class="wpi-sub">Once Claude is connected to your site, you can manage everything by chatting naturally.</p>

        <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)) !important;gap:16px !important;margin-bottom:24px !important;">
            <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:12px !important;padding:18px !important;">
                <div style="font-size:22px !important;margin-bottom:8px !important;">&#128196;</div>
                <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#166534 !important;">Content & Pages</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;line-height:1.5 !important;">"Create a contact page with a form and map"<br>"Write a blog post about summer trends"</p>
            </div>
            <div style="background:#eff6ff !important;border:1px solid #bfdbfe !important;border-radius:12px !important;padding:18px !important;">
                <div style="font-size:22px !important;margin-bottom:8px !important;">&#127912;</div>
                <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#1e40af !important;">Design & Styling</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;line-height:1.5 !important;">"Make the header background dark blue"<br>"Add a hero section with a gradient"</p>
            </div>
            <div style="background:#fefce8 !important;border:1px solid #fde68a !important;border-radius:12px !important;padding:18px !important;">
                <div style="font-size:22px !important;margin-bottom:8px !important;">&#128270;</div>
                <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#92400e !important;">SEO & Keywords</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;line-height:1.5 !important;">"Optimize all pages for Google"<br>"What keywords should I target?"</p>
            </div>
            <div style="background:#fdf2f8 !important;border:1px solid #fbcfe8 !important;border-radius:12px !important;padding:18px !important;">
                <div style="font-size:22px !important;margin-bottom:8px !important;">&#128722;</div>
                <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#9d174d !important;">WooCommerce</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;line-height:1.5 !important;">"Add a product called Summer Hat for $29"<br>"Set up a 20% off coupon"</p>
            </div>
            <div style="background:#f5f3ff !important;border:1px solid #ddd6fe !important;border-radius:12px !important;padding:18px !important;">
                <div style="font-size:22px !important;margin-bottom:8px !important;">&#128274;</div>
                <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#5b21b6 !important;">Security & Updates</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;line-height:1.5 !important;">"Check for outdated plugins"<br>"Are there any security issues?"</p>
            </div>
            <div style="background:#f0fdfa !important;border:1px solid #99f6e4 !important;border-radius:12px !important;padding:18px !important;">
                <div style="font-size:22px !important;margin-bottom:8px !important;">&#9889;</div>
                <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#115e59 !important;">Performance</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;line-height:1.5 !important;">"How fast does my site load?"<br>"Clean up old post revisions"</p>
            </div>
        </div>

        <div style="background:#0f172a !important;border-radius:14px !important;padding:22px 28px !important;color:#fff !important;">
            <h3 style="color:#4ec9b0 !important;font-size:15px !important;margin:0 0 8px !important;">Quick tip: Just talk naturally</h3>
            <p style="color:#94a3b8 !important;font-size:13px !important;margin:0 !important;line-height:1.7 !important;">
                You don't need special commands or syntax. Just describe what you want in plain language — English, Swedish, Spanish, or any language. Claude understands context, so "make it look better" or "fix the menu" works just as well as detailed technical instructions.
            </p>
        </div>
    </div>

    <?php // ─── First Conversation Starters ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Great first things to try
        </h2>
        <p class="wpi-sub">Not sure where to start? Try one of these after connecting:</p>

        <div style="display:flex !important;flex-direction:column !important;gap:10px !important;">
            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:14px 18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:10px !important;">
                <span style="font-size:18px !important;">&#128075;</span>
                <div>
                    <strong style="font-size:13px !important;color:#1e293b !important;">"Give me an overview of my site"</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:2px 0 0 !important;">Claude will scan your pages, plugins, theme, and settings — like a site audit.</p>
                </div>
            </div>
            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:14px 18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:10px !important;">
                <span style="font-size:18px !important;">&#128269;</span>
                <div>
                    <strong style="font-size:13px !important;color:#1e293b !important;">"How's my SEO? What should I improve?"</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:2px 0 0 !important;">Get keyword suggestions, meta description fixes, and content tips.</p>
                </div>
            </div>
            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:14px 18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:10px !important;">
                <span style="font-size:18px !important;">&#128295;</span>
                <div>
                    <strong style="font-size:13px !important;color:#1e293b !important;">"Are there any plugins that need updating?"</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:2px 0 0 !important;">Claude checks versions and warns about known issues.</p>
                </div>
            </div>
            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:14px 18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:10px !important;">
                <span style="font-size:18px !important;">&#127760;</span>
                <div>
                    <strong style="font-size:13px !important;color:#1e293b !important;">"Create an About Us page"</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:2px 0 0 !important;">Claude writes the content, creates the page, and publishes it — all from one message.</p>
                </div>
            </div>
        </div>
    </div>

    <?php
    wpilot_page_js( $rest_url );
    echo '</div>'; // close .wpi
}

// Built by Weblease

// ══════════════════════════════════════════════════════════════
//  PAGE: Chat Agent
// ══════════════════════════════════════════════════════════════

function wpilot_page_chat_agent() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    wpilot_admin_css();
    wpilot_page_nav( 'wpilot-chat' );

    $is_licensed  = function_exists( 'wpilot_is_licensed' ) && wpilot_is_licensed();
    $has_chat     = function_exists( 'wpilot_has_chat_agent' ) && wpilot_has_chat_agent();
    $chat_enabled = get_option( 'wpilot_chat_enabled', false );
    $chat_key     = get_option( 'wpilot_chat_key', '' );
    $wa_number    = get_option( 'wpilot_whatsapp_number', '' );
    $phone        = get_option( 'wpilot_contact_phone', '' );
    $email        = get_option( 'wpilot_contact_email', '' );
    $saved        = sanitize_text_field( $_GET['saved'] ?? '' );
    $site_url     = get_site_url();
    $checkout     = 'https://weblease.se/wpilot-checkout?site=' . urlencode( $site_url ) . '&email=' . urlencode( get_option( 'admin_email' ) );

    if ( $saved === 'chat' ) {
        echo '<div class="wpi-alert wpi-alert-ok"><strong>Chat Agent settings saved!</strong></div>';
    } elseif ( $saved === 'key' ) {
        echo '<div class="wpi-alert wpi-alert-ok"><strong>Widget key regenerated!</strong> Update the script tag on your site.</div>';
    }

    // ═══════════════════════════════════════════════════════════
    //  NOT LICENSED — Full sales page
    // ═══════════════════════════════════════════════════════════
    if ( ! $is_licensed ) {
        ?>

        <!-- Hero pitch -->
        <div style="background:linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%) !important;border-radius:18px !important;padding:48px 40px !important;color:#fff !important;text-align:center !important;margin-bottom:24px !important;position:relative !important;overflow:hidden !important;">
            <div style="position:absolute !important;top:-40% !important;right:-10% !important;width:300px !important;height:300px !important;background:radial-gradient(circle, rgba(139,92,246,0.2) 0%, transparent 70%) !important;pointer-events:none !important;"></div>
            <div style="position:absolute !important;bottom:-30% !important;left:5% !important;width:250px !important;height:250px !important;background:radial-gradient(circle, rgba(78,201,176,0.15) 0%, transparent 70%) !important;pointer-events:none !important;"></div>
            <div style="position:relative !important;z-index:1 !important;">
                <div style="font-size:56px !important;margin-bottom:16px !important;">&#128172;</div>
                <h2 style="font-size:28px !important;font-weight:800 !important;margin:0 0 12px !important;color:#fff !important;letter-spacing:-0.02em !important;">Your website, answering customers 24/7</h2>
                <p style="font-size:16px !important;color:#c4b5fd !important;max-width:520px !important;margin:0 auto 8px !important;line-height:1.6 !important;">Add an AI chat bubble that knows your products, pages, and services. Visitors get instant answers. You get more leads.</p>
            </div>
        </div>

        <!-- The problem -->
        <div class="wpi-card">
            <h2 style="color:#dc2626 !important;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                The problem
            </h2>
            <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)) !important;gap:16px !important;">
                <div style="background:#fef2f2 !important;border:1px solid #fecaca !important;border-radius:12px !important;padding:20px !important;">
                    <strong style="font-size:28px !important;display:block !important;color:#dc2626 !important;margin-bottom:4px !important;">53%</strong>
                    <p style="font-size:13px !important;color:#991b1b !important;margin:0 !important;line-height:1.5 !important;">of visitors leave a website if they can't find an answer within 30 seconds.</p>
                </div>
                <div style="background:#fef2f2 !important;border:1px solid #fecaca !important;border-radius:12px !important;padding:20px !important;">
                    <strong style="font-size:28px !important;display:block !important;color:#dc2626 !important;margin-bottom:4px !important;">$0</strong>
                    <p style="font-size:13px !important;color:#991b1b !important;margin:0 !important;line-height:1.5 !important;">is what every lost visitor is worth. No answer = no sale = no booking = nothing.</p>
                </div>
                <div style="background:#fef2f2 !important;border:1px solid #fecaca !important;border-radius:12px !important;padding:20px !important;">
                    <strong style="font-size:28px !important;display:block !important;color:#dc2626 !important;margin-bottom:4px !important;">24/7</strong>
                    <p style="font-size:13px !important;color:#991b1b !important;margin:0 !important;line-height:1.5 !important;">is when customers browse. Evenings, weekends, holidays. You can't be online all the time.</p>
                </div>
            </div>
        </div>

        <!-- The solution -->
        <div class="wpi-card">
            <h2 style="color:#22c55e !important;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Chat Agent solves this
            </h2>
            <p class="wpi-sub">A smart chat bubble on your website that actually knows your business.</p>

            <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)) !important;gap:16px !important;margin-top:16px !important;">
                <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:14px !important;padding:22px !important;text-align:center !important;">
                    <div style="font-size:32px !important;margin-bottom:10px !important;">&#129504;</div>
                    <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#166534 !important;">Knows your site</strong>
                    <p style="font-size:12px !important;color:#15803d !important;margin:0 !important;line-height:1.5 !important;">Claude reads your actual pages, products, prices, and services. Real answers, not generic chatbot fluff.</p>
                </div>
                <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:14px !important;padding:22px !important;text-align:center !important;">
                    <div style="font-size:32px !important;margin-bottom:10px !important;">&#9889;</div>
                    <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#166534 !important;">Answers instantly</strong>
                    <p style="font-size:12px !important;color:#15803d !important;margin:0 !important;line-height:1.5 !important;">No waiting for email replies. Visitors get help in seconds, right when they're ready to buy or book.</p>
                </div>
                <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:14px !important;padding:22px !important;text-align:center !important;">
                    <div style="font-size:32px !important;margin-bottom:10px !important;">&#128176;</div>
                    <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#166534 !important;">Zero extra cost</strong>
                    <p style="font-size:12px !important;color:#15803d !important;margin:0 !important;line-height:1.5 !important;">No API keys, no per-message fees. Piggybacks on your existing Claude connection. Included with Pro.</p>
                </div>
                <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:14px !important;padding:22px !important;text-align:center !important;">
                    <div style="font-size:32px !important;margin-bottom:10px !important;">&#128225;</div>
                    <strong style="font-size:14px !important;display:block !important;margin-bottom:6px !important;color:#166534 !important;">Always available</strong>
                    <p style="font-size:12px !important;color:#15803d !important;margin:0 !important;line-height:1.5 !important;">When you're offline, visitors see WhatsApp, phone, or email buttons. Nobody gets a dead end.</p>
                </div>
            </div>
        </div>

        <!-- What visitors see -->
        <div class="wpi-card">
            <h2>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                What your visitors will see
            </h2>
            <p class="wpi-sub">Real examples of what Chat Agent can answer:</p>

            <div style="max-width:420px !important;margin:0 auto !important;background:#0f172a !important;border-radius:16px !important;padding:24px !important;font-family:-apple-system, sans-serif !important;">
                <div style="text-align:right !important;margin-bottom:12px !important;">
                    <span style="background:#4f46e5 !important;color:#fff !important;padding:10px 16px !important;border-radius:16px 16px 4px 16px !important;font-size:13px !important;display:inline-block !important;">What are your opening hours?</span>
                </div>
                <div style="text-align:left !important;margin-bottom:12px !important;">
                    <span style="background:#1e293b !important;color:#e2e8f0 !important;padding:10px 16px !important;border-radius:16px 16px 16px 4px !important;font-size:13px !important;display:inline-block !important;max-width:85% !important;line-height:1.5 !important;">We're open Monday&ndash;Friday 9:00&ndash;17:00 and Saturday 10:00&ndash;14:00. Closed Sundays. You can book online anytime!</span>
                </div>
                <div style="text-align:right !important;margin-bottom:12px !important;">
                    <span style="background:#4f46e5 !important;color:#fff !important;padding:10px 16px !important;border-radius:16px 16px 4px 16px !important;font-size:13px !important;display:inline-block !important;">Do you deliver to Stockholm?</span>
                </div>
                <div style="text-align:left !important;">
                    <span style="background:#1e293b !important;color:#e2e8f0 !important;padding:10px 16px !important;border-radius:16px 16px 16px 4px !important;font-size:13px !important;display:inline-block !important;max-width:85% !important;line-height:1.5 !important;">Yes! We offer free delivery in Stockholm for orders over 499 kr. Standard shipping is 49 kr.</span>
                </div>
            </div>
            <p style="text-align:center !important;font-size:12px !important;color:#94a3b8 !important;margin-top:12px !important;">These answers come from your actual website content — not pre-written scripts.</p>
        </div>

        <!-- Who needs this -->
        <div class="wpi-card">
            <h2>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Perfect for
            </h2>
            <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)) !important;gap:14px !important;">
                <div style="padding:18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;">
                    <strong style="font-size:15px !important;display:block !important;margin-bottom:4px !important;">Online stores</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;">"Do you have this in size M?" "What's the return policy?"</p>
                </div>
                <div style="padding:18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;">
                    <strong style="font-size:15px !important;display:block !important;margin-bottom:4px !important;">Restaurants &amp; cafes</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;">"Can I book a table for 4?" "Do you have vegan options?"</p>
                </div>
                <div style="padding:18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;">
                    <strong style="font-size:15px !important;display:block !important;margin-bottom:4px !important;">Service businesses</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;">"How much does a haircut cost?" "Can I book this Saturday?"</p>
                </div>
                <div style="padding:18px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;">
                    <strong style="font-size:15px !important;display:block !important;margin-bottom:4px !important;">Agencies &amp; freelancers</strong>
                    <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;">"What services do you offer?" "Can you help with SEO?"</p>
                </div>
            </div>
        </div>

        <!-- How it works (simple) -->
        <div class="wpi-card">
            <h2>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                How it works
            </h2>
            <div style="display:flex !important;flex-direction:column !important;gap:0 !important;">
                <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;padding:16px 0 !important;">
                    <span class="wpi-step">1</span>
                    <div>
                        <h3 style="margin:0 0 2px !important;font-size:15px !important;">You connect Claude to your site</h3>
                        <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;">Already done if you're using WPilot. One click to enable the widget.</p>
                    </div>
                </div>
                <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;padding:16px 0 !important;border-top:1px solid #f1f5f9 !important;">
                    <span class="wpi-step">2</span>
                    <div>
                        <h3 style="margin:0 0 2px !important;font-size:15px !important;">Chat bubble appears on your website</h3>
                        <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;">One line of code. Works with any WordPress theme or page builder.</p>
                    </div>
                </div>
                <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;padding:16px 0 !important;border-top:1px solid #f1f5f9 !important;">
                    <span class="wpi-step">3</span>
                    <div>
                        <h3 style="margin:0 0 2px !important;font-size:15px !important;">Visitors ask &mdash; Claude answers</h3>
                        <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;">Using your real content. Prices, hours, products, policies &mdash; all from your actual website.</p>
                    </div>
                </div>
                <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;padding:16px 0 !important;border-top:1px solid #f1f5f9 !important;">
                    <span class="wpi-step">4</span>
                    <div>
                        <h3 style="margin:0 0 2px !important;font-size:15px !important;">You're offline? No problem</h3>
                        <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;">Visitors see WhatsApp, phone, or email buttons instead. Nobody hits a dead end.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- What you need -->
        <div class="wpi-card" style="background:linear-gradient(135deg, #f0fdf4, #ecfdf5) !important;border:2px solid #86efac !important;">
            <h2 style="color:#166534 !important;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                Get Chat Agent
            </h2>
            <div style="display:flex !important;flex-direction:column !important;gap:12px !important;margin-bottom:24px !important;">
                <div style="display:flex !important;align-items:center !important;gap:12px !important;">
                    <span style="width:28px !important;height:28px !important;background:#22c55e !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;color:#fff !important;font-size:14px !important;font-weight:800 !important;">&#10003;</span>
                    <span style="font-size:14px !important;color:#166534 !important;"><strong>WPilot Pro</strong> &mdash; $9/month (required)</span>
                </div>
                <div style="display:flex !important;align-items:center !important;gap:12px !important;">
                    <span style="width:28px !important;height:28px !important;background:#22c55e !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;color:#fff !important;font-size:14px !important;font-weight:800 !important;">+</span>
                    <span style="font-size:14px !important;color:#166534 !important;"><strong>Chat Agent addon</strong> &mdash; $20/month</span>
                </div>
                <div style="display:flex !important;align-items:center !important;gap:12px !important;">
                    <span style="width:28px !important;height:28px !important;background:#22c55e !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;color:#fff !important;font-size:14px !important;font-weight:800 !important;">&#10003;</span>
                    <span style="font-size:14px !important;color:#166534 !important;"><strong>Claude running</strong> &mdash; on your computer (Desktop or Terminal)</span>
                </div>
            </div>

            <?php if ( $is_licensed ): ?>
            <div style="text-align:center !important;">
                <a href="<?php echo esc_url( $checkout . '&addon=chat-agent' ); ?>" target="_blank" class="wpi-btn wpi-btn-green" style="font-size:17px !important;padding:16px 44px !important;box-shadow:0 6px 24px rgba(34,197,94,0.4) !important;">Add Chat Agent &mdash; $20/month</a>
                <p style="font-size:12px !important;color:#15803d !important;margin:10px 0 0 !important;">You already have WPilot Pro. Just add Chat Agent to your plan.</p>
            </div>
            <?php else: ?>
            <div style="text-align:center !important;">
                <a href="<?php echo esc_url( $checkout . '&plan=pro&addon=chat-agent' ); ?>" target="_blank" class="wpi-btn wpi-btn-green" style="font-size:17px !important;padding:16px 44px !important;box-shadow:0 6px 24px rgba(34,197,94,0.4) !important;">Get Pro + Chat Agent &mdash; $29/month</a>
                <p style="font-size:12px !important;color:#15803d !important;margin:10px 0 0 !important;">$9 WPilot Pro + $20 Chat Agent. Cancel anytime.</p>
            </div>
            <?php endif; ?>
        </div>

        <?php
        echo '</div>';
        return;
    }

    // ═══════════════════════════════════════════════════════════
    //  LICENSED — Settings & management
    // ═══════════════════════════════════════════════════════════
    ?>

    <!-- Status overview -->
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Chat Agent
        </h2>

        <div style="display:flex !important;gap:16px !important;flex-wrap:wrap !important;margin-bottom:20px !important;">
            <div style="flex:1 !important;min-width:180px !important;background:<?php echo $chat_enabled ? '#f0fdf4' : '#fef2f2'; ?> !important;border:1px solid <?php echo $chat_enabled ? '#bbf7d0' : '#fecaca'; ?> !important;border-radius:12px !important;padding:16px 20px !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.05em !important;">Widget</span>
                <div style="font-size:18px !important;font-weight:700 !important;color:<?php echo $chat_enabled ? '#166534' : '#991b1b'; ?> !important;margin-top:4px !important;"><?php echo $chat_enabled ? 'Enabled' : 'Disabled'; ?></div>
            </div>
            <div style="flex:1 !important;min-width:180px !important;background:<?php echo $has_chat ? '#f0fdf4' : '#fefce8'; ?> !important;border:1px solid <?php echo $has_chat ? '#bbf7d0' : '#fcd34d'; ?> !important;border-radius:12px !important;padding:16px 20px !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.05em !important;">License</span>
                <div style="font-size:18px !important;font-weight:700 !important;color:<?php echo $has_chat ? '#166534' : '#92400e'; ?> !important;margin-top:4px !important;"><?php echo $has_chat ? 'Active' : 'Checking...'; ?></div>
            </div>
            <div style="flex:1 !important;min-width:180px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;padding:16px 20px !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.05em !important;">Fallbacks</span>
                <?php
                $fallbacks = [];
                if ( ! empty( $wa_number ) ) $fallbacks[] = 'WhatsApp';
                if ( ! empty( $phone ) ) $fallbacks[] = 'Phone';
                if ( ! empty( $email ) ) $fallbacks[] = 'Email';
                ?>
                <div style="font-size:18px !important;font-weight:700 !important;color:#1e293b !important;margin-top:4px !important;"><?php echo ! empty( $fallbacks ) ? esc_html( implode( ', ', $fallbacks ) ) : 'None set'; ?></div>
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            How your Chat Agent works
        </h2>

        <div style="display:flex !important;flex-direction:column !important;gap:20px !important;">

            <!-- Reads your site -->
            <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;">
                <div style="width:44px !important;height:44px !important;background:linear-gradient(135deg, #22c55e, #16a34a) !important;border-radius:12px !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;font-size:20px !important;">&#129504;</div>
                <div>
                    <h3 style="margin:0 0 4px !important;font-size:15px !important;color:#1e293b !important;">Reads your website automatically</h3>
                    <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;line-height:1.6 !important;">Your Chat Agent reads your pages, products, prices, opening hours, and everything else on your site. You don't need to type any of this &mdash; it already knows.</p>
                </div>
            </div>

            <!-- Extra info -->
            <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;border-top:1px solid #f1f5f9 !important;padding-top:20px !important;">
                <div style="width:44px !important;height:44px !important;background:linear-gradient(135deg, #6366f1, #4f46e5) !important;border-radius:12px !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;font-size:20px !important;">&#128221;</div>
                <div>
                    <h3 style="margin:0 0 4px !important;font-size:15px !important;color:#1e293b !important;">Add info that's NOT on your website</h3>
                    <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;line-height:1.6 !important;">Some things aren't written on your site &mdash; internal policies, special deals, things you'd tell a customer in person. Add those below as "Extra knowledge" so the agent can mention them when relevant.</p>
                    <div style="background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:10px !important;padding:12px 16px !important;margin-top:8px !important;font-size:12px !important;color:#64748b !important;line-height:1.7 !important;">
                        <strong style="color:#1e293b !important;">Examples:</strong><br>
                        &#8226; "We offer 10% discount for first-time customers &mdash; code WELCOME10"<br>
                        &#8226; "We don't do refunds on sale items, but we do exchanges"<br>
                        &#8226; "Parking is free behind the building on Storgatan 5"<br>
                        &#8226; "We're closed July 15&ndash;August 1 for summer vacation"
                    </div>
                </div>
            </div>

            <!-- Don't repeat -->
            <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;border-top:1px solid #f1f5f9 !important;padding-top:20px !important;">
                <div style="width:44px !important;height:44px !important;background:linear-gradient(135deg, #f59e0b, #d97706) !important;border-radius:12px !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;font-size:20px !important;">&#128161;</div>
                <div>
                    <h3 style="margin:0 0 4px !important;font-size:15px !important;color:#1e293b !important;">Don't repeat what's already on your site</h3>
                    <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;line-height:1.6 !important;">If your opening hours, prices, or product details are on your website, the agent already knows them. Only add things that a visitor <strong>can't find</strong> on your site.</p>
                </div>
            </div>

            <!-- Auto-responds -->
            <div style="display:flex !important;gap:16px !important;align-items:flex-start !important;border-top:1px solid #f1f5f9 !important;padding-top:20px !important;">
                <div style="width:44px !important;height:44px !important;background:linear-gradient(135deg, #4ec9b0, #0d9488) !important;border-radius:12px !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;font-size:20px !important;">&#9889;</div>
                <div>
                    <h3 style="margin:0 0 4px !important;font-size:15px !important;color:#1e293b !important;">Claude checks &amp; answers automatically</h3>
                    <p style="margin:0 !important;font-size:13px !important;color:#64748b !important;line-height:1.6 !important;">When Claude is connected (your app is open), it constantly checks for new visitor questions and responds within seconds. You don't need to do anything &mdash; just keep Claude running.</p>
                    <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:10px !important;padding:12px 16px !important;margin-top:8px !important;font-size:13px !important;color:#166534 !important;">
                        <strong>Leave Claude open</strong> on your computer. As long as it's running, your Chat Agent is live and answering visitors.
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Settings -->
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09"/></svg>
            Settings
        </h2>

        <?php
        $agent_name     = get_option( 'wpilot_agent_name', '' );
        $agent_greeting = get_option( 'wpilot_agent_greeting', '' );
        $agent_role     = get_option( 'wpilot_agent_role', '' );
        $agent_lang     = get_option( 'wpilot_agent_language', '' );
        ?>
        <form method="post">
            <?php wp_nonce_field( 'wpilot_admin' ); ?>
            <input type="hidden" name="wpilot_action" value="save_chat_settings">

            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:16px 20px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;margin-bottom:24px !important;">
                <label style="display:flex !important;align-items:center !important;gap:10px !important;cursor:pointer !important;font-size:15px !important;font-weight:600 !important;color:#1e293b !important;">
                    <input type="checkbox" name="chat_enabled" value="1" <?php checked( $chat_enabled ); ?> style="width:18px !important;height:18px !important;">
                    Enable chat widget on your website
                </label>
            </div>

            <!-- Agent personality -->
            <h3 style="font-size:15px !important;font-weight:700 !important;margin:0 0 6px !important;color:#1e293b !important;">Your AI Agent</h3>
            <p style="font-size:13px !important;color:#64748b !important;margin:0 0 16px !important;">Give your chat agent a name and personality. This is what visitors see and interact with.</p>

            <div class="wpi-grid-2">
                <div class="wpi-field">
                    <label for="wpi-agent-name">Agent name</label>
                    <input type="text" id="wpi-agent-name" name="agent_name" value="<?php echo esc_attr( $agent_name ); ?>" placeholder="e.g. Lisa, Alex, Support">
                    <span class="wpi-hint">The name shown in the chat bubble header. Leave empty for "WPilot".</span>
                </div>
                <div class="wpi-field">
                    <label for="wpi-agent-role">Role / title</label>
                    <input type="text" id="wpi-agent-role" name="agent_role" value="<?php echo esc_attr( $agent_role ); ?>" placeholder="e.g. Customer support, Sales assistant">
                    <span class="wpi-hint">Shown below the name. Tells visitors what the agent does.</span>
                </div>
                <div class="wpi-field" style="grid-column: 1 / -1 !important;">
                    <label for="wpi-agent-greeting">Welcome message</label>
                    <input type="text" id="wpi-agent-greeting" name="agent_greeting" value="<?php echo esc_attr( $agent_greeting ); ?>" placeholder="e.g. Hi! How can I help you today?">
                    <span class="wpi-hint">First message visitors see when they open the chat. Leave empty for auto-detect based on language.</span>
                </div>
                <div class="wpi-field">
                    <label for="wpi-agent-lang">Chat language</label>
                    <select id="wpi-agent-lang" name="agent_language">
                        <?php
                        $chat_langs = [
                            ''           => 'Auto-detect (matches visitor\'s language)',
                            'sv'         => 'Svenska',
                            'en'         => 'English',
                            'es'         => 'Espa&ntilde;ol',
                            'de'         => 'Deutsch',
                            'fr'         => 'Fran&ccedil;ais',
                            'no'         => 'Norsk',
                            'da'         => 'Dansk',
                            'fi'         => 'Suomi',
                            'nl'         => 'Nederlands',
                            'it'         => 'Italiano',
                            'pt'         => 'Portugu&ecirc;s',
                            'ar'         => 'العربية',
                            'el'         => 'Ελληνικά',
                            'tr'         => 'T&uuml;rk&ccedil;e',
                            'pl'         => 'Polski',
                        ];
                        foreach ( $chat_langs as $v => $l ) {
                            echo '<option value="' . esc_attr( $v ) . '"' . selected( $agent_lang, $v, false ) . '>' . $l . '</option>';
                        }
                        ?>
                    </select>
                    <span class="wpi-hint">Auto-detect responds in whatever language the visitor writes.</span>
                </div>
                <div class="wpi-field">
                    <label for="wpi-widget-color">Widget color</label>
                    <?php $widget_color = get_option( 'wpilot_widget_color', '#1a1a2e' ); ?>
                    <div style="display:flex !important;gap:8px !important;align-items:center !important;">
                        <input type="color" id="wpi-widget-color" name="widget_color" value="<?php echo esc_attr( $widget_color ); ?>" style="width:48px !important;height:38px !important;border:2px solid #dde3ea !important;border-radius:8px !important;cursor:pointer !important;padding:2px !important;">
                        <input type="text" name="widget_color_hex" value="<?php echo esc_attr( $widget_color ); ?>" placeholder="#1a1a2e" style="max-width:120px !important;font-family:monospace !important;" onchange="document.getElementById('wpi-widget-color').value=this.value" oninput="document.getElementById('wpi-widget-color').value=this.value">
                    </div>
                    <span class="wpi-hint">The main color of the chat bubble and header. Match your brand.</span>
                    <script>document.getElementById('wpi-widget-color').addEventListener('input',function(e){e.target.nextElementSibling.value=e.target.value;});</script>
                </div>
                <div class="wpi-field">
                    <label for="wpi-widget-accent">Accent color</label>
                    <?php $widget_accent = get_option( 'wpilot_widget_accent', '#4ec9b0' ); ?>
                    <div style="display:flex !important;gap:8px !important;align-items:center !important;">
                        <input type="color" id="wpi-widget-accent" name="widget_accent" value="<?php echo esc_attr( $widget_accent ); ?>" style="width:48px !important;height:38px !important;border:2px solid #dde3ea !important;border-radius:8px !important;cursor:pointer !important;padding:2px !important;">
                        <input type="text" name="widget_accent_hex" value="<?php echo esc_attr( $widget_accent ); ?>" placeholder="#4ec9b0" style="max-width:120px !important;font-family:monospace !important;" onchange="document.getElementById('wpi-widget-accent').value=this.value">
                    </div>
                    <span class="wpi-hint">The accent color for icons, status dots, and links.</span>
                    <script>document.getElementById('wpi-widget-accent').addEventListener('input',function(e){e.target.nextElementSibling.value=e.target.value;});</script>
                </div>
            </div>

            <!-- Extra knowledge -->
            <div style="margin-top:8px !important;">
                <h3 style="font-size:15px !important;font-weight:700 !important;margin:0 0 6px !important;color:#1e293b !important;">Extra knowledge</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 0 10px !important;">Add info that's <strong>not on your website</strong> but you want the agent to know. Don't repeat things already on your site &mdash; the agent reads those automatically.</p>
                <?php $extra_knowledge = get_option( 'wpilot_agent_knowledge', '' ); ?>
                <div class="wpi-field" style="margin:0 !important;">
                    <textarea name="agent_knowledge" rows="5" placeholder="Example:&#10;- 10% discount for first-time customers, code WELCOME10&#10;- No refunds on sale items, but we do exchanges&#10;- Free parking behind the building on Storgatan 5&#10;- Closed July 15 - August 1 for summer vacation&#10;- We can do custom orders, ask for a quote" style="max-width:100% !important;font-size:13px !important;line-height:1.6 !important;"><?php echo esc_textarea( $extra_knowledge ); ?></textarea>
                    <span class="wpi-hint">Write naturally, one thing per line. The agent will mention these when relevant to a visitor's question.</span>
                </div>
            </div>

            <!-- Preview -->
            <div style="margin:20px 0 28px !important;">
                <p style="font-size:12px !important;font-weight:700 !important;text-transform:uppercase !important;letter-spacing:0.06em !important;color:#64748b !important;margin:0 0 10px !important;">Preview</p>
                <div style="max-width:340px !important;background:#0f172a !important;border-radius:16px !important;padding:0 !important;overflow:hidden !important;box-shadow:0 8px 32px rgba(0,0,0,0.2) !important;">
                    <div style="background:linear-gradient(135deg, #1e293b, #0f172a) !important;padding:16px 20px !important;display:flex !important;align-items:center !important;gap:12px !important;border-bottom:1px solid #334155 !important;">
                        <div style="width:36px !important;height:36px !important;background:linear-gradient(135deg, #4ec9b0, #22c55e) !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;color:#fff !important;font-weight:800 !important;font-size:14px !important;"><?php echo ! empty( $agent_name ) ? esc_html( mb_substr( $agent_name, 0, 1 ) ) : 'W'; ?></div>
                        <div>
                            <div style="color:#fff !important;font-weight:700 !important;font-size:14px !important;"><?php echo ! empty( $agent_name ) ? esc_html( $agent_name ) : 'WPilot'; ?></div>
                            <div style="color:#4ec9b0 !important;font-size:11px !important;"><?php echo ! empty( $agent_role ) ? esc_html( $agent_role ) : 'AI Assistant'; ?></div>
                        </div>
                        <div style="margin-left:auto !important;width:8px !important;height:8px !important;background:#22c55e !important;border-radius:50% !important;box-shadow:0 0 6px rgba(34,197,94,0.6) !important;"></div>
                    </div>
                    <div style="padding:16px 20px !important;">
                        <div style="background:#1e293b !important;color:#e2e8f0 !important;padding:10px 14px !important;border-radius:12px 12px 12px 4px !important;font-size:13px !important;display:inline-block !important;max-width:85% !important;"><?php echo ! empty( $agent_greeting ) ? esc_html( $agent_greeting ) : 'Hi! How can I help you today?'; ?></div>
                    </div>
                </div>
            </div>

            <div style="border-top:1px solid #f1f5f9 !important;padding-top:24px !important;margin-top:8px !important;">
                <h3 style="font-size:15px !important;font-weight:700 !important;margin:0 0 6px !important;color:#1e293b !important;">Offline Fallback Contacts</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 0 16px !important;">When Claude is not connected, visitors see these contact buttons instead. Set at least one so nobody hits a dead end.</p>

                <div class="wpi-grid-2">
                    <div class="wpi-field">
                        <label for="wpi-wa">WhatsApp number</label>
                        <input type="text" id="wpi-wa" name="whatsapp_number" value="<?php echo esc_attr( $wa_number ); ?>" placeholder="e.g. +46701234567">
                        <span class="wpi-hint">Include country code. Opens WhatsApp chat.</span>
                    </div>
                    <div class="wpi-field">
                        <label for="wpi-phone">Phone number</label>
                        <input type="text" id="wpi-phone" name="contact_phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="e.g. +46701234567">
                        <span class="wpi-hint">Tap to call on mobile.</span>
                    </div>
                    <div class="wpi-field">
                        <label for="wpi-email">Email address</label>
                        <input type="email" id="wpi-email" name="contact_email" value="<?php echo esc_attr( $email ); ?>" placeholder="e.g. hello@yoursite.com">
                        <span class="wpi-hint">Opens email app.</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="wpi-btn wpi-btn-dark" style="margin-top:8px !important;">Save Settings</button>
        </form>
    </div>

    <!-- Unanswered Questions -->
    <?php
    $unanswered = get_option( 'wpilot_unanswered_questions', [] );
    if ( ! empty( $unanswered ) ):
        $unanswered = array_reverse( $unanswered ); // newest first
    ?>
    <div class="wpi-card" style="border:2px solid #fde68a !important;">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span style="color:#f59e0b !important;">Unanswered Questions</span>
            <span style="font-size:11px !important;background:#fef3c7 !important;color:#92400e !important;padding:2px 10px !important;border-radius:10px !important;font-weight:700 !important;margin-left:6px !important;"><?php echo count( $unanswered ); ?></span>
        </h2>
        <p class="wpi-sub">Visitors asked these questions but <?php echo esc_html( get_option( 'wpilot_agent_name', '' ) ?: 'the agent' ); ?> couldn't answer. Add the info so it can answer next time.</p>

        <div style="display:flex !important;flex-direction:column !important;gap:8px !important;margin-bottom:16px !important;">
            <?php foreach ( array_slice( $unanswered, 0, 10 ) as $uq ): ?>
            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:12px 16px !important;background:#fefce8 !important;border:1px solid #fde68a !important;border-radius:10px !important;">
                <div style="flex:1 !important;">
                    <strong style="font-size:14px !important;color:#1e293b !important;">"<?php echo esc_html( $uq['question'] ); ?>"</strong>
                    <span style="font-size:11px !important;color:#94a3b8 !important;margin-left:8px !important;"><?php echo esc_html( $uq['time'] ); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="background:#f0fdf4 !important;border:2px solid #bbf7d0 !important;border-radius:14px !important;padding:18px 22px !important;">
            <h3 style="margin:0 0 6px !important;font-size:14px !important;color:#166534 !important;">How to fix this</h3>
            <p style="margin:0 !important;font-size:13px !important;color:#15803d !important;line-height:1.6 !important;">
                Scroll up to <strong>Extra knowledge</strong> and add the answers. For example:<br>
                <em style="color:#166534 !important;">"We accept Swish, card, and invoice payments"</em><br>
                <em style="color:#166534 !important;">"Returns accepted within 14 days with receipt"</em><br>
                Or add the info to a page on your website — <?php echo esc_html( get_option( 'wpilot_agent_name', '' ) ?: 'the agent' ); ?> will learn it automatically next time Claude connects.
            </p>
        </div>

        <form method="post" style="margin-top:12px !important;">
            <?php wp_nonce_field( 'wpilot_admin' ); ?>
            <input type="hidden" name="wpilot_action" value="clear_unanswered">
            <button type="submit" class="wpi-btn wpi-btn-red wpi-btn-sm" onclick="return confirm('Clear all unanswered questions?');">Clear list</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Live Mode -->
    <?php
    $live_agent  = esc_html( get_option( 'wpilot_agent_name', '' ) ?: 'Your agent' );
    $client_type = get_option( 'wpilot_client_type', 'unknown' );
    $uses_desktop = ( $client_type === 'desktop' );
    $uses_code    = ( $client_type === 'code' );
    ?>
    <div class="wpi-card" style="border:2px solid #bbf7d0 !important;">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
            <span style="color:#22c55e !important;">Live Mode</span> — Automatic Answers
        </h2>
        <p class="wpi-sub"><?php echo $live_agent; ?> can answer visitors automatically on your website. It runs in the background on your computer — you don't need to do anything.</p>

        <?php if ( $uses_desktop && ! $uses_code ): ?>
        <!-- Desktop user needs Claude Code -->
        <div style="background:#eff6ff !important;border:2px solid #bfdbfe !important;border-radius:14px !important;padding:18px 22px !important;margin-bottom:20px !important;">
            <h3 style="margin:0 0 6px !important;font-size:14px !important;color:#1e40af !important;">You're using Claude Desktop — one extra step needed</h3>
            <p style="margin:0 0 10px !important;font-size:13px !important;color:#1d4ed8 !important;">Live Mode needs Claude Code (free, takes 1 minute to install). You'll still use Claude Desktop for everything else &mdash; Claude Code just runs the chat agent in the background.</p>
        </div>
        <?php elseif ( $uses_code ): ?>
        <!-- Code user - good to go -->
        <div style="background:#f0fdf4 !important;border:2px solid #bbf7d0 !important;border-radius:14px !important;padding:14px 20px !important;margin-bottom:20px !important;">
            <p style="margin:0 !important;font-size:13px !important;color:#166534 !important;font-weight:600 !important;">&#10003; You're using Claude Code &mdash; Live Mode works right away. Just paste the command below.</p>
        </div>
        <?php endif; ?>

        <!-- How it works - super simple -->
        <div style="background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:14px !important;padding:20px 24px !important;margin-bottom:20px !important;">
            <h3 style="margin:0 0 10px !important;font-size:15px !important;color:#166534 !important;">How it works</h3>
            <div style="display:flex !important;flex-direction:column !important;gap:8px !important;font-size:14px !important;color:#15803d !important;">
                <div>&#10147; You paste <strong>one command</strong> in a terminal window</div>
                <div>&#10147; You minimize the window and forget about it</div>
                <div>&#10147; <?php echo $live_agent; ?> answers visitors automatically, every 30 seconds</div>
                <div>&#10147; When you shut down your computer, visitors see your contact info instead</div>
            </div>
        </div>

        <!-- The command — auto-detect OS -->
        <div style="background:#0f172a !important;border-radius:14px !important;padding:24px !important;margin-bottom:20px !important;">

            <!-- Prerequisites — hide if already using Code -->
            <div style="background:rgba(255,255,255,0.03) !important;border:1px solid #334155 !important;border-radius:12px !important;padding:16px 20px !important;margin-bottom:18px !important;<?php echo $uses_code ? 'display:none !important;' : ''; ?>">
                <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 0 8px !important;"><?php echo $uses_desktop ? 'Install Claude Code to enable Live Mode (free, 1 minute):' : 'Live Mode requires <strong style="color:#fff">Claude Code</strong> (free). If you only use Claude Desktop, install it first:'; ?></p>
                <div style="display:flex !important;gap:6px !important;margin-bottom:8px !important;">
                    <button type="button" onclick="wpiLiveTab('mac')" class="wpi-live-tab" id="wpi-live-install-mac" style="padding:5px 12px !important;border-radius:8px !important;border:1px solid #334155 !important;background:transparent !important;color:#64748b !important;font-size:11px !important;font-weight:600 !important;cursor:pointer !important;">Mac / Linux</button>
                    <button type="button" onclick="wpiLiveTab('win')" class="wpi-live-tab" id="wpi-live-install-win" style="padding:5px 12px !important;border-radius:8px !important;border:1px solid #334155 !important;background:transparent !important;color:#64748b !important;font-size:11px !important;font-weight:600 !important;cursor:pointer !important;">Windows</button>
                </div>
                <div id="wpi-live-install-panel-mac" style="display:none !important;">
                    <code class="wpi-code" onclick="wpiCopy(this)" style="margin:0 !important;border:none !important;background:rgba(255,255,255,0.06) !important;font-size:12px !important;padding:8px 14px !important;" data-copy="npm install -g @anthropic-ai/claude-code">npm install -g @anthropic-ai/claude-code</code>
                    <p style="font-size:11px !important;color:#64748b !important;margin:6px 0 0 !important;">Paste in Terminal (Cmd+Space &rarr; "Terminal"). Needs <a href="https://nodejs.org" target="_blank" style="color:#4ec9b0 !important;">Node.js</a> installed.</p>
                </div>
                <div id="wpi-live-install-panel-win" style="display:none !important;">
                    <code class="wpi-code" onclick="wpiCopy(this)" style="margin:0 !important;border:none !important;background:rgba(255,255,255,0.06) !important;font-size:12px !important;padding:8px 14px !important;" data-copy="npm install -g @anthropic-ai/claude-code">npm install -g @anthropic-ai/claude-code</code>
                    <p style="font-size:11px !important;color:#64748b !important;margin:6px 0 0 !important;">Paste in PowerShell (right-click Start &rarr; Terminal). Needs <a href="https://nodejs.org" target="_blank" style="color:#4ec9b0 !important;">Node.js</a> installed.</p>
                </div>
                <p style="font-size:11px !important;color:#4ec9b0 !important;margin:8px 0 0 !important;font-weight:600 !important;">Already have Claude Code? Skip this &mdash; go straight to the steps below.</p>
            </div>

            <div style="display:flex !important;gap:6px !important;margin-bottom:16px !important;">
                <button type="button" onclick="wpiLiveTab('mac')" class="wpi-live-tab" id="wpi-live-mac" style="padding:8px 18px !important;border-radius:10px !important;border:1.5px solid #334155 !important;background:transparent !important;color:#94a3b8 !important;font-size:13px !important;font-weight:600 !important;cursor:pointer !important;transition:all .15s !important;">Mac / Linux</button>
                <button type="button" onclick="wpiLiveTab('win')" class="wpi-live-tab" id="wpi-live-win" style="padding:8px 18px !important;border-radius:10px !important;border:1.5px solid #334155 !important;background:transparent !important;color:#94a3b8 !important;font-size:13px !important;font-weight:600 !important;cursor:pointer !important;transition:all .15s !important;">Windows</button>
            </div>

            <!-- Mac/Linux -->
            <div id="wpi-live-panel-mac" style="display:none !important;">

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:18px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">1</span>
                    <div>
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 4px !important;">Open Terminal</p>
                        <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 !important;">Press <strong style="color:#e2e8f0 !important;">Cmd + Space</strong>, type <strong style="color:#4ec9b0 !important;">Terminal</strong>, press Enter.</p>
                    </div>
                </div>

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:18px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">2</span>
                    <div style="flex:1 !important;">
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 6px !important;">Paste this command</p>
                        <p style="font-size:12px !important;color:#94a3b8 !important;margin:0 0 8px !important;">Click the box to copy, then paste in Terminal with <strong style="color:#e2e8f0 !important;">Cmd + V</strong>:</p>
                        <code class="wpi-code" onclick="wpiCopy(this)" style="margin:0 !important;border:none !important;background:rgba(255,255,255,0.06) !important;font-size:11px !important;line-height:1.6 !important;" data-copy="W=60; while true; do R=$(claude -p &quot;Check for pending customer chat messages on the website and respond to all of them as <?php echo esc_attr( $live_agent ); ?>. If no pending messages, just say OK.&quot; 2>/dev/null); if echo &quot;$R&quot; | grep -qi &quot;PENDING\|responded\|answered&quot;; then W=10; else W=60; fi; sleep $W; done">W=60; while true; do
  R=$(claude -p "Check chat messages, respond as <?php echo $live_agent; ?>." 2>/dev/null)
  if echo "$R" | grep -qi "PENDING\|responded"; then W=10; else W=60; fi
  sleep $W
done</code>
                        <p style="font-size:11px !important;color:#64748b !important;margin:6px 0 0 !important;">Checks every 60 sec. When a visitor writes, speeds up to every 10 sec.</p>
                    </div>
                </div>

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:18px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">3</span>
                    <div>
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 4px !important;">Press Enter</p>
                        <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 !important;">You'll see text scrolling &mdash; that means it's working.</p>
                    </div>
                </div>

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">4</span>
                    <div>
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 4px !important;">Minimize the window</p>
                        <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 !important;">Don't close it &mdash; just minimize. <?php echo $live_agent; ?> is now live and answering visitors. Go about your day!</p>
                    </div>
                </div>

                <div style="background:rgba(255,255,255,0.04) !important;border:1px solid #334155 !important;border-radius:10px !important;padding:12px 16px !important;margin-top:18px !important;font-size:12px !important;color:#94a3b8 !important;">
                    <strong style="color:#f59e0b !important;">To stop:</strong> Open Terminal again, press <strong style="color:#e2e8f0 !important;">Ctrl + C</strong>. <?php echo $live_agent; ?> goes offline.
                </div>
            </div>

            <!-- Windows -->
            <div id="wpi-live-panel-win" style="display:none !important;">

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:18px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">1</span>
                    <div>
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 4px !important;">Open PowerShell</p>
                        <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 !important;">Right-click the <strong style="color:#e2e8f0 !important;">Start button</strong> (bottom left) &rarr; click <strong style="color:#4ec9b0 !important;">Terminal</strong> or <strong style="color:#4ec9b0 !important;">PowerShell</strong>.</p>
                    </div>
                </div>

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:18px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">2</span>
                    <div style="flex:1 !important;">
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 6px !important;">Paste this command</p>
                        <p style="font-size:12px !important;color:#94a3b8 !important;margin:0 0 8px !important;">Click the box to copy, then right-click in PowerShell to paste:</p>
                        <code class="wpi-code" onclick="wpiCopy(this)" style="margin:0 !important;border:none !important;background:rgba(255,255,255,0.06) !important;font-size:11px !important;line-height:1.6 !important;" data-copy="$W=60; while ($true) { $R = claude -p &quot;Check for pending customer chat messages on the website and respond to all of them as <?php echo esc_attr( $live_agent ); ?>. If no pending messages, just say OK.&quot; 2>$null; if ($R -match 'PENDING|responded|answered') { $W=10 } else { $W=60 }; Start-Sleep $W }">$W=60; while ($true) {
  $R = claude -p "Check chat messages, respond as <?php echo $live_agent; ?>." 2>$null
  if ($R -match 'PENDING|responded') { $W=10 } else { $W=60 }
  Start-Sleep $W
}</code>
                        <p style="font-size:11px !important;color:#64748b !important;margin:6px 0 0 !important;">Checks every 60 sec. When a visitor writes, speeds up to every 10 sec.</p>
                    </div>
                </div>

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:18px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">3</span>
                    <div>
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 4px !important;">Press Enter</p>
                        <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 !important;">You'll see text scrolling &mdash; that means it's working.</p>
                    </div>
                </div>

                <div style="display:flex !important;align-items:flex-start !important;gap:12px !important;">
                    <span style="background:#22c55e !important;color:#fff !important;width:26px !important;height:26px !important;border-radius:50% !important;display:flex !important;align-items:center !important;justify-content:center !important;font-weight:800 !important;font-size:12px !important;flex-shrink:0 !important;">4</span>
                    <div>
                        <p style="font-size:14px !important;font-weight:600 !important;color:#fff !important;margin:0 0 4px !important;">Minimize the window</p>
                        <p style="font-size:13px !important;color:#94a3b8 !important;margin:0 !important;">Don't close it &mdash; just minimize. <?php echo $live_agent; ?> is now live and answering visitors. Go about your day!</p>
                    </div>
                </div>

                <div style="background:rgba(255,255,255,0.04) !important;border:1px solid #334155 !important;border-radius:10px !important;padding:12px 16px !important;margin-top:18px !important;font-size:12px !important;color:#94a3b8 !important;">
                    <strong style="color:#f59e0b !important;">To stop:</strong> Open PowerShell again, press <strong style="color:#e2e8f0 !important;">Ctrl + C</strong>. <?php echo $live_agent; ?> goes offline.
                </div>
            </div>

        </div>
        <script>
        function wpiLiveTab(os) {
            // Main panels
            document.getElementById('wpi-live-panel-mac').style.display = os === 'mac' ? 'block' : 'none';
            document.getElementById('wpi-live-panel-win').style.display = os === 'win' ? 'block' : 'none';
            document.getElementById('wpi-live-mac').style.background = os === 'mac' ? '#1e293b' : 'transparent';
            document.getElementById('wpi-live-mac').style.color = os === 'mac' ? '#4ec9b0' : '#94a3b8';
            document.getElementById('wpi-live-mac').style.borderColor = os === 'mac' ? '#4ec9b0' : '#334155';
            document.getElementById('wpi-live-win').style.background = os === 'win' ? '#1e293b' : 'transparent';
            document.getElementById('wpi-live-win').style.color = os === 'win' ? '#4ec9b0' : '#94a3b8';
            document.getElementById('wpi-live-win').style.borderColor = os === 'win' ? '#4ec9b0' : '#334155';
            // Install panels
            document.getElementById('wpi-live-install-panel-mac').style.display = os === 'mac' ? 'block' : 'none';
            document.getElementById('wpi-live-install-panel-win').style.display = os === 'win' ? 'block' : 'none';
            document.getElementById('wpi-live-install-mac').style.background = os === 'mac' ? '#1e293b' : 'transparent';
            document.getElementById('wpi-live-install-mac').style.color = os === 'mac' ? '#e2e8f0' : '#64748b';
            document.getElementById('wpi-live-install-win').style.background = os === 'win' ? '#1e293b' : 'transparent';
            document.getElementById('wpi-live-install-win').style.color = os === 'win' ? '#e2e8f0' : '#64748b';
        }
        (function(){ var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent); wpiLiveTab(isMac ? 'mac' : 'win'); })();
        </script>

        <!-- Visual explanation -->
        <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)) !important;gap:12px !important;margin-bottom:16px !important;">
            <div style="text-align:center !important;padding:16px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;">
                <div style="font-size:28px !important;margin-bottom:6px !important;">&#128187;</div>
                <strong style="font-size:13px !important;color:#1e293b !important;display:block !important;">Your computer</strong>
                <span style="font-size:12px !important;color:#64748b !important;">Runs in the background while you work, browse, or do anything else</span>
            </div>
            <div style="text-align:center !important;padding:16px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;">
                <div style="font-size:28px !important;margin-bottom:6px !important;">&#128172;</div>
                <strong style="font-size:13px !important;color:#1e293b !important;display:block !important;">Visitor asks</strong>
                <span style="font-size:12px !important;color:#64748b !important;">Someone writes in the chat on your website</span>
            </div>
            <div style="text-align:center !important;padding:16px !important;background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:12px !important;">
                <div style="font-size:28px !important;margin-bottom:6px !important;">&#9889;</div>
                <strong style="font-size:13px !important;color:#166534 !important;display:block !important;"><?php echo $live_agent; ?> answers</strong>
                <span style="font-size:12px !important;color:#15803d !important;">Automatically, within 30 seconds, using your website's real content</span>
            </div>
        </div>

        <div style="font-size:13px !important;color:#64748b !important;display:flex !important;flex-direction:column !important;gap:6px !important;">
            <div>&#128161; <strong>Uses your Claude subscription</strong> — no extra costs or API keys</div>
            <div>&#128161; <strong>Stop anytime</strong> — press Ctrl+C or close the terminal</div>
            <div>&#128161; <strong>Computer off = agent offline</strong> — visitors see WhatsApp / phone / email instead</div>
        </div>
    </div>
    <?php
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════
//  PAGE: Plan
// ══════════════════════════════════════════════════════════════

function wpilot_page_plan() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    wpilot_admin_css();
    wpilot_page_nav( 'wpilot-plan' );

    $license_key = get_option( 'wpilot_license_key', '' );
    $license     = ! empty( $license_key ) ? wpilot_check_license() : 'free';
    $saved       = sanitize_text_field( $_GET['saved'] ?? '' );
    $status      = sanitize_text_field( $_GET['status'] ?? '' );
    $site_url    = get_site_url();
    $usage       = wpilot_get_daily_usage();
    $used        = intval( $usage['count'] );
    $checkout    = 'https://weblease.se/wpilot-checkout?site=' . urlencode( $site_url ) . '&email=' . urlencode( get_option( 'admin_email' ) );

    // Alerts
    if ( $saved === 'license' ) {
        if ( $status === 'valid' ) {
            echo '<div class="wpi-alert wpi-alert-ok"><strong>License activated!</strong> You now have unlimited requests. Enjoy WPilot Pro.</div>';
        } elseif ( $status === 'expired' ) {
            echo '<div class="wpi-alert wpi-alert-warn"><strong>License expired.</strong> Please renew your license or enter a new key.</div>';
        } else {
            echo '<div class="wpi-alert wpi-alert-info"><strong>License saved.</strong> Checking validity...</div>';
        }
    } elseif ( $saved === 'license_removed' ) {
        echo '<div class="wpi-alert wpi-alert-warn"><strong>License removed.</strong> You are now on the free plan with ' . intval( WPILOT_FREE_LIMIT ) . ' requests per day.</div>';
    }

    // ─── Licensed: Pro ───
    if ( $license === 'valid' ) {
        ?>
        <div class="wpi-card">
            <h2>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Your Plan — <span style="color:#22c55e !important;">Pro — Unlimited</span>
            </h2>
            <p class="wpi-sub">You have unlimited requests. No daily limits apply.</p>
            <div style="display:flex !important;align-items:center !important;gap:12px !important;padding:16px 20px !important;background:#f0fdf4 !important;border:1px solid #bbf7d0 !important;border-radius:12px !important;margin-bottom:20px !important;">
                <span style="font-size:11px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.05em !important;">License key:</span>
                <code style="font-size:13px !important;color:#166534 !important;font-family:monospace !important;"><?php echo esc_html( substr( $license_key, 0, 8 ) . str_repeat( '*', 8 ) ); ?></code>
            </div>
            <form method="post" onsubmit="return confirm('Remove your Pro license? You will be limited to <?php echo intval( WPILOT_FREE_LIMIT ); ?> requests per day.');">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="remove_license">
                <button type="submit" class="wpi-btn wpi-btn-red">Remove License</button>
            </form>
        </div>
        <?php

    // ─── Expired ───
    } elseif ( $license === 'expired' ) {
        ?>
        <div class="wpi-card">
            <h2>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                License Expired
            </h2>
            <p class="wpi-sub">Your license has expired. Renew to get unlimited requests again, or enter a new license key below.</p>
            <form method="post" style="display:flex !important;gap:10px !important;align-items:end !important;flex-wrap:wrap !important;">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="save_license">
                <div class="wpi-field" style="margin:0 !important;flex:1 !important;min-width:240px !important;">
                    <label>License key</label>
                    <input type="text" name="license_key" value="<?php echo esc_attr( $license_key ); ?>" placeholder="WPILOT-XXXX-XXXX-XXXX" style="max-width:100% !important;">
                </div>
                <button type="submit" class="wpi-btn wpi-btn-green">Activate</button>
            </form>
            <div style="margin-top:14px !important;">
                <a href="<?php echo esc_url( $checkout . '&plan=pro' ); ?>" target="_blank" style="color:#4ec9b0 !important;font-weight:600 !important;font-size:13px !important;">Renew subscription &rarr;</a>
            </div>
        </div>
        <?php

    // ─── Free ───
    } else {
        ?>
        <div class="wpi-card">
            <h2>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                Lite Plan
            </h2>
            <p class="wpi-sub">You have used <strong><?php echo intval( $used ); ?></strong> of <strong><?php echo intval( WPILOT_FREE_LIMIT ); ?></strong> free requests today. Resets at midnight.</p>
            <div class="wpi-progress">
                <div class="wpi-progress-bar" style="width:<?php echo min( 100, ( $used / max( 1, WPILOT_FREE_LIMIT ) ) * 100 ); ?>% !important;background:<?php echo $used >= WPILOT_FREE_LIMIT ? '#dc2626' : 'linear-gradient(90deg, #4ec9b0, #22c55e)'; ?> !important;"></div>
            </div>

            <h3 style="font-size:15px !important;font-weight:700 !important;margin:22px 0 12px !important;color:#1e293b !important;">Have a license key?</h3>
            <form method="post" style="display:flex !important;gap:10px !important;align-items:end !important;flex-wrap:wrap !important;">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="save_license">
                <div class="wpi-field" style="margin:0 !important;flex:1 !important;min-width:240px !important;">
                    <label>License key</label>
                    <input type="text" name="license_key" placeholder="WPILOT-XXXX-XXXX-XXXX" style="max-width:100% !important;">
                </div>
                <button type="submit" class="wpi-btn wpi-btn-dark">Activate</button>
            </form>
        </div>

        <div class="wpi-pricing">
            <div class="wpi-plan wpi-plan-pop">
                <h3>Pro</h3>
                <div class="wpi-price">$9<span>/month</span></div>
                <p class="wpi-price-note">1 license = 1 website</p>
                <ul>
                    <li>Unlimited AI requests</li>
                    <li>Claude Desktop + Claude Code</li>
                    <li>Unlimited connections</li>
                    <li>Simple + Technical mode</li>
                    <li>SEO &amp; keyword expert</li>
                    <li>Email support</li>
                    <li>Cancel anytime</li>
                </ul>
                <a href="<?php echo esc_url( $checkout . '&plan=pro' ); ?>" target="_blank" class="wpi-btn wpi-btn-green">Get Pro</a>
            </div>
            <div class="wpi-plan">
                <h3>Team</h3>
                <div class="wpi-price">$29<span>/month</span></div>
                <p class="wpi-price-note">3 licenses = 3 websites</p>
                <ul>
                    <li>Up to 3 WordPress sites</li>
                    <li>Unlimited AI requests</li>
                    <li>Claude Desktop + Claude Code</li>
                    <li>All capabilities included</li>
                    <li>Priority support</li>
                    <li>Cancel anytime</li>
                </ul>
                <a href="<?php echo esc_url( $checkout . '&plan=team' ); ?>" target="_blank" class="wpi-btn wpi-btn-dark">Get Team</a>
            </div>
            <div class="wpi-plan">
                <h3>Lifetime</h3>
                <div class="wpi-price">$149<span> once</span></div>
                <p class="wpi-price-note">Pro forever — 1 website</p>
                <ul>
                    <li>Everything in Pro</li>
                    <li>Pay once, use forever</li>
                    <li>All future updates</li>
                    <li>Unlimited AI requests</li>
                    <li>Unlimited connections</li>
                    <li>Lifetime support</li>
                    <li>Limited slots available</li>
                </ul>
                <a href="<?php echo esc_url( $checkout . '&plan=lifetime' ); ?>" target="_blank" class="wpi-btn wpi-btn-dark">Get Lifetime</a>
            </div>
        </div>
        <p style="text-align:center !important;font-size:12px !important;color:#94a3b8 !important;margin-top:12px !important;">Each license is locked to one website and cannot be transferred.</p>

        <!-- Chat Agent Addon -->
        <div style="margin-top:28px !important;background:linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%) !important;border-radius:18px !important;padding:32px !important;color:#fff !important;">
            <div style="display:flex !important;align-items:center !important;gap:16px !important;flex-wrap:wrap !important;">
                <div style="flex:1 !important;min-width:200px !important;">
                    <div style="font-size:11px !important;text-transform:uppercase !important;letter-spacing:0.06em !important;color:#a78bfa !important;font-weight:700 !important;margin-bottom:6px !important;">Addon</div>
                    <h3 style="font-size:20px !important;font-weight:800 !important;margin:0 0 6px !important;color:#fff !important;">Chat Agent — AI Customer Service</h3>
                    <p style="font-size:13px !important;color:#c4b5fd !important;margin:0 !important;line-height:1.5 !important;">Add an AI chat widget to your website. Answers visitors 24/7 using your site's real content. Requires Pro, Team, or Lifetime license.</p>
                </div>
                <div style="text-align:center !important;">
                    <div style="font-size:32px !important;font-weight:800 !important;color:#fff !important;">$20<span style="font-size:16px !important;color:#a78bfa !important;font-weight:500 !important;">/month</span></div>
                    <a href="<?php echo esc_url( $checkout . '&addon=chat-agent' ); ?>" target="_blank" class="wpi-btn wpi-btn-green" style="margin-top:10px !important;">Add Chat Agent</a>
                </div>
            </div>
        </div>
        <?php
    }

    echo '</div>'; // close .wpi
}

// ══════════════════════════════════════════════════════════════
//  PAGE: Settings
// ══════════════════════════════════════════════════════════════

function wpilot_page_settings() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    wpilot_admin_css();
    wpilot_page_nav( 'wpilot-settings' );

    $profile = get_option( 'wpilot_site_profile', [] );
    $saved   = sanitize_text_field( $_GET['saved'] ?? '' );

    if ( $saved === 'profile' ) {
        echo '<div class="wpi-alert wpi-alert-ok"><strong>Profile saved!</strong> Claude will use these settings in future conversations.</div>';
    }
    ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Site Profile
        </h2>
        <p class="wpi-sub">Tell Claude about you and your site. This personalizes every conversation.</p>

        <form method="post">
            <?php wp_nonce_field( 'wpilot_admin' ); ?>
            <input type="hidden" name="wpilot_action" value="save_profile">

            <div class="wpi-grid-2">
                <div class="wpi-field">
                    <label for="wpi-owner">Your name</label>
                    <input type="text" id="wpi-owner" name="owner_name" value="<?php echo esc_attr( $profile['owner_name'] ?? '' ); ?>" placeholder="e.g. Lisa">
                    <span class="wpi-hint">Claude will use your name in conversation.</span>
                </div>
                <div class="wpi-field">
                    <label for="wpi-biz">Business type</label>
                    <input type="text" id="wpi-biz" name="business_type" value="<?php echo esc_attr( $profile['business_type'] ?? '' ); ?>" placeholder="e.g. Flower shop, restaurant, blog...">
                    <span class="wpi-hint">Helps Claude suggest the right content for your industry.</span>
                </div>
                <div class="wpi-field">
                    <label for="wpi-tone">Tone</label>
                    <select id="wpi-tone" name="tone">
                        <?php
                        $tones = [
                            'friendly and professional' => 'Friendly & professional — like a helpful colleague',
                            'casual and relaxed'        => 'Casual & relaxed — like chatting with a friend',
                            'formal and business-like'  => 'Formal & business-like — straight to the point',
                            'warm and personal'         => 'Warm & personal — encouraging and supportive',
                            'short and direct'          => 'Short & direct — just the facts, no small talk',
                        ];
                        $cur = $profile['tone'] ?? 'friendly and professional';
                        foreach ( $tones as $v => $l ) {
                            echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $l ) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="wpi-field">
                    <label for="wpi-lang">Language</label>
                    <select id="wpi-lang" name="language">
                        <?php
                        $langs = [
                            ''           => 'Auto-detect (matches your language)',
                            'Swedish'    => 'Swedish',
                            'English'    => 'English',
                            'Spanish'    => 'Spanish',
                            'German'     => 'German',
                            'French'     => 'French',
                            'Norwegian'  => 'Norwegian',
                            'Danish'     => 'Danish',
                            'Finnish'    => 'Finnish',
                            'Dutch'      => 'Dutch',
                            'Italian'    => 'Italian',
                            'Portuguese' => 'Portuguese',
                            'Arabic'     => 'Arabic',
                            'Greek'      => 'Greek',
                            'Turkish'    => 'Turkish',
                            'Polish'     => 'Polish',
                        ];
                        $cur_l = $profile['language'] ?? '';
                        foreach ( $langs as $v => $l ) {
                            echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur_l, $v, false ) . '>' . esc_html( $l ) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="wpi-btn wpi-btn-dark" style="margin-top:8px !important;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Settings
            </button>
        </form>
    </div>

    <?php // ─── Plan Status ─── ?>
    <?php
    $is_pro     = function_exists( 'wpilot_is_licensed' ) && wpilot_is_licensed();
    $has_chat   = function_exists( 'wpilot_has_chat_agent' ) && wpilot_has_chat_agent();
    $tokens     = wpilot_get_tokens();
    $token_count = count( $tokens );
    $usage      = wpilot_get_daily_usage();
    $used       = intval( $usage['count'] );
    ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Account Overview
        </h2>

        <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)) !important;gap:12px !important;">
            <div style="padding:16px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#fefce8'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#fde68a'; ?> !important;border-radius:12px !important;text-align:center !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;">Plan</span>
                <div style="font-size:20px !important;font-weight:800 !important;color:<?php echo $is_pro ? '#166534' : '#92400e'; ?> !important;margin-top:4px !important;"><?php echo $is_pro ? 'Pro' : 'Free'; ?></div>
            </div>
            <div style="padding:16px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;text-align:center !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;">Requests today</span>
                <div style="font-size:20px !important;font-weight:800 !important;color:#1e293b !important;margin-top:4px !important;"><?php echo $is_pro ? 'Unlimited' : $used . '/' . intval( WPILOT_FREE_LIMIT ); ?></div>
            </div>
            <div style="padding:16px !important;background:#f8fafc !important;border:1px solid #e2e8f0 !important;border-radius:12px !important;text-align:center !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;">Connections</span>
                <div style="font-size:20px !important;font-weight:800 !important;color:#1e293b !important;margin-top:4px !important;"><?php echo intval( $token_count ); ?> active</div>
            </div>
            <div style="padding:16px !important;background:<?php echo $has_chat ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $has_chat ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:12px !important;text-align:center !important;">
                <span style="font-size:12px !important;color:#64748b !important;font-weight:600 !important;text-transform:uppercase !important;">Chat Agent</span>
                <div style="font-size:20px !important;font-weight:800 !important;color:<?php echo $has_chat ? '#166534' : '#94a3b8'; ?> !important;margin-top:4px !important;"><?php echo $has_chat ? 'Active' : 'Off'; ?></div>
            </div>
        </div>
    </div>

    <?php // ─── AI Training ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Help Us Build Better AI
        </h2>
        <p class="wpi-sub">Share anonymous usage data to help us improve WPilot for everyone. No personal data is ever sent — only patterns of how Claude works with WordPress.</p>

        <form method="post" style="display:flex !important;align-items:center !important;gap:12px !important;">
            <?php wp_nonce_field( 'wpilot_admin' ); ?>
            <input type="hidden" name="wpilot_action" value="toggle_training">
            <?php $opt_in = get_option( 'wpilot_training_opt_in', false ); ?>
            <label style="display:flex !important;align-items:center !important;gap:10px !important;cursor:pointer !important;font-size:14px !important;font-weight:600 !important;color:#1e293b !important;">
                <input type="checkbox" name="training_opt_in" value="1" <?php checked( $opt_in ); ?> onchange="this.form.submit()" style="width:18px !important;height:18px !important;">
                Share anonymous usage data
            </label>
        </form>

        <div style="margin-top:12px !important;font-size:12px !important;color:#94a3b8 !important;line-height:1.6 !important;">
            <strong style="color:#64748b !important;">What we collect:</strong> WordPress function patterns (e.g. "used wp_insert_post"), success/failure rates, common workflows.<br>
            <strong style="color:#64748b !important;">What we DON'T collect:</strong> Your content, passwords, customer data, personal information, or anything from your website.
        </div>
    </div>

    <?php // ─── Plugin Info ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            Plugin Information
        </h2>
        <div style="display:grid !important;grid-template-columns:140px 1fr !important;gap:8px 16px !important;font-size:13px !important;">
            <span style="color:#64748b !important;font-weight:600 !important;">Version</span>
            <span style="color:#1e293b !important;"><?php echo esc_html( WPILOT_VERSION ); ?></span>
            <span style="color:#64748b !important;font-weight:600 !important;">Plugin type</span>
            <span style="color:#1e293b !important;">WPilot Pro</span>
            <span style="color:#64748b !important;font-weight:600 !important;">Site URL</span>
            <span style="color:#1e293b !important;"><?php echo esc_html( get_site_url() ); ?></span>
            <span style="color:#64748b !important;font-weight:600 !important;">PHP version</span>
            <span style="color:#1e293b !important;"><?php echo esc_html( phpversion() ); ?></span>
            <span style="color:#64748b !important;font-weight:600 !important;">WordPress</span>
            <span style="color:#1e293b !important;"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
        </div>
    </div>
    <?php

    echo '</div>'; // close .wpi
}

// ══════════════════════════════════════════════════════════════
//  PAGE: Help
// ══════════════════════════════════════════════════════════════

function wpilot_page_help() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    wpilot_admin_css();
    wpilot_page_nav( 'wpilot-help' );

    $saved   = sanitize_text_field( $_GET['saved'] ?? '' );
    $is_pro  = function_exists( 'wpilot_is_licensed' ) && wpilot_is_licensed();

    if ( $saved === 'feedback' ) {
        echo '<div class="wpi-alert wpi-alert-ok"><strong>Thank you!</strong> Your feedback has been sent. We read every message.</div>';
    }

    // ─── What can Claude do? ───
    ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            What can Claude do?
        </h2>
        <p class="wpi-sub">Once connected, just talk to Claude naturally. Here are some examples:</p>

        <div class="wpi-examples">
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
                    Design &amp; Content
                </h3>
                <p>"Create a contact page with a form"</p>
                <p>"Make the header dark blue"</p>
                <p>"Add a hero section with a gradient"</p>
                <p>"Write an About Us page for my bakery"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    SEO &amp; Keywords
                </h3>
                <p>"What keywords should I target?"</p>
                <p>"Optimize my homepage for Google"</p>
                <p>"Write meta descriptions for all pages"</p>
                <p>"How does my site rank for [keyword]?"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    WooCommerce
                </h3>
                <p>"Add a product called Summer Hat for $29"</p>
                <p>"Set up a 20% off coupon code"</p>
                <p>"How many orders did we get this month?"</p>
                <p>"Create a product category for shoes"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    Site Management
                </h3>
                <p>"Show me all pages on the site"</p>
                <p>"Change the site title to X"</p>
                <p>"Clean up old post revisions"</p>
                <p>"What plugins are installed?"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Security &amp; Updates
                </h3>
                <p>"Are there any outdated plugins?"</p>
                <p>"Check my site for security issues"</p>
                <p>"Update all plugins to latest"</p>
                <p>"Who are the admin users?"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    Performance &amp; Speed
                </h3>
                <p>"How fast does my site load?"</p>
                <p>"What's slowing down my site?"</p>
                <p>"Clear all caches"</p>
                <p>"Optimize my images"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Users &amp; Roles
                </h3>
                <p>"Create an editor account for Lisa"</p>
                <p>"List all admin users"</p>
                <p>"Change Lisa's role to author"</p>
                <p>"Reset password for user X"</p>
            </div>
            <div class="wpi-example">
                <h3>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4ec9b0" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Backups &amp; Maintenance
                </h3>
                <p>"Export my database"</p>
                <p>"Delete spam comments"</p>
                <p>"How much disk space am I using?"</p>
                <p>"Remove unused media files"</p>
            </div>
        </div>
    </div>

    <?php // ─── Pro Features ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?php echo $is_pro ? 'Your Pro Features' : 'Pro Features'; ?>
        </h2>
        <p class="wpi-sub"><?php echo $is_pro ? 'Everything included in your plan:' : 'Upgrade to unlock these features:'; ?></p>

        <div style="display:grid !important;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)) !important;gap:12px !important;">
            <div style="padding:14px 18px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:10px !important;">
                <strong style="font-size:13px !important;color:#1e293b !important;">Unlimited requests</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:4px 0 0 !important;">No daily limits. Use as much as you need.</p>
            </div>
            <div style="padding:14px 18px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:10px !important;">
                <strong style="font-size:13px !important;color:#1e293b !important;">Chat Agent</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:4px 0 0 !important;">AI chat bubble on your website for visitors.</p>
            </div>
            <div style="padding:14px 18px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:10px !important;">
                <strong style="font-size:13px !important;color:#1e293b !important;">Unlimited connections</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:4px 0 0 !important;">Connect from multiple devices and team members.</p>
            </div>
            <div style="padding:14px 18px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:10px !important;">
                <strong style="font-size:13px !important;color:#1e293b !important;">SEO expert mode</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:4px 0 0 !important;">Deep keyword analysis and optimization.</p>
            </div>
            <div style="padding:14px 18px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:10px !important;">
                <strong style="font-size:13px !important;color:#1e293b !important;">Priority support</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:4px 0 0 !important;">Faster response times from our team.</p>
            </div>
            <div style="padding:14px 18px !important;background:<?php echo $is_pro ? '#f0fdf4' : '#f8fafc'; ?> !important;border:1px solid <?php echo $is_pro ? '#bbf7d0' : '#e2e8f0'; ?> !important;border-radius:10px !important;">
                <strong style="font-size:13px !important;color:#1e293b !important;">Auto-updates</strong>
                <p style="font-size:12px !important;color:#64748b !important;margin:4px 0 0 !important;">Always on the latest version automatically.</p>
            </div>
        </div>

        <?php if ( ! $is_pro ): ?>
        <div style="text-align:center !important;margin-top:20px !important;">
            <?php $checkout = 'https://weblease.se/wpilot-checkout?site=' . urlencode( get_site_url() ) . '&email=' . urlencode( get_option( 'admin_email' ) ); ?>
            <a href="<?php echo esc_url( $checkout . '&plan=pro' ); ?>" target="_blank" class="wpi-btn wpi-btn-green">Upgrade to Pro — $9/month</a>
        </div>
        <?php endif; ?>
    </div>

    <?php // ─── FAQ ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Frequently Asked Questions
        </h2>

        <div style="display:flex !important;flex-direction:column !important;gap:16px !important;">
            <div>
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">Do I need to pay for Claude separately?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;">Yes. WPilot connects Claude to your WordPress site, but you need a Claude subscription (free tier works for testing). WPilot does not charge for AI usage — your Claude subscription covers that.</p>
            </div>
            <div style="border-top:1px solid #f1f5f9 !important;padding-top:16px !important;">
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">Is my data safe?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;">WPilot runs entirely on your server. Your data never passes through our servers. Claude connects directly to your WordPress via MCP (Model Context Protocol). Each connection uses a unique token that you control.</p>
            </div>
            <div style="border-top:1px solid #f1f5f9 !important;padding-top:16px !important;">
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">Can Claude break my site?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;">WPilot has a built-in sandbox that blocks dangerous operations (like deleting the database or modifying core files). Claude can create content, adjust settings, and manage plugins — but destructive actions are blocked automatically.</p>
            </div>
            <div style="border-top:1px solid #f1f5f9 !important;padding-top:16px !important;">
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">What's the difference between Simple and Technical mode?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;"><strong>Simple mode</strong> is for site owners and clients — friendly language, no code details. <strong>Technical mode</strong> is for developers — includes database IDs, code references, and technical details. Choose per connection.</p>
            </div>
            <div style="border-top:1px solid #f1f5f9 !important;padding-top:16px !important;">
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">Can I use multiple languages?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;">Yes! Claude understands and responds in any language. You can set a default language in Settings, or just write in whatever language you prefer — Claude will match it automatically.</p>
            </div>
            <div style="border-top:1px solid #f1f5f9 !important;padding-top:16px !important;">
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">How does the Chat Agent work?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;">The Chat Agent adds an AI chat bubble to your website (Pro only). Visitor questions are queued on your server, and Claude answers them through your existing MCP connection. No API keys or extra services needed. When you're offline, visitors see your contact info instead.</p>
            </div>
            <div style="border-top:1px solid #f1f5f9 !important;padding-top:16px !important;">
                <h3 style="font-size:14px !important;font-weight:700 !important;margin:0 0 4px !important;color:#1e293b !important;">Can I give access to my team or clients?</h3>
                <p style="font-size:13px !important;color:#64748b !important;margin:0 !important;line-height:1.6 !important;">Yes. Create a separate connection for each person or device. You control who has access and can revoke any connection instantly. Each person gets their own token.</p>
            </div>
        </div>
    </div>

    <?php // ─── Feedback Form ─── ?>
    <div class="wpi-card">
        <h2>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Send Feedback
        </h2>
        <p class="wpi-sub">We read every message. Help us make WPilot better for everyone.</p>

        <form method="post">
            <?php wp_nonce_field( 'wpilot_admin' ); ?>
            <input type="hidden" name="wpilot_action" value="send_feedback">

            <div class="wpi-radios">
                <label><input type="radio" name="feedback_type" value="feedback" checked><span>Feedback</span></label>
                <label><input type="radio" name="feedback_type" value="feature"><span>Feature request</span></label>
                <label><input type="radio" name="feedback_type" value="bug"><span>Bug report</span></label>
                <label><input type="radio" name="feedback_type" value="question"><span>Question</span></label>
            </div>

            <div class="wpi-field">
                <label for="wpi-feedback-msg">Your message</label>
                <textarea id="wpi-feedback-msg" name="feedback_message" placeholder="Tell us what you think, what you need, or what went wrong..." style="max-width:100% !important;"></textarea>
            </div>

            <button type="submit" class="wpi-btn wpi-btn-dark">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send Feedback
            </button>
        </form>
    </div>

    <?php // ─── Support Links ─── ?>
    <div class="wpi-card">
        <h2>Support &amp; Resources</h2>
        <div class="wpi-links">
            <a href="https://weblease.se/wpilot" target="_blank">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                weblease.se/wpilot
            </a>
            <a href="mailto:support@weblease.se">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                support@weblease.se
            </a>
            <a href="https://claude.ai/download" target="_blank">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download Claude
            </a>
            <a href="https://weblease.se/wpilot-account" target="_blank">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                My account
            </a>
            <a href="https://weblease.se/forgot-password" target="_blank">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Forgot password
            </a>
        </div>
    </div>
    <?php

    echo '</div>'; // close .wpi
}

// ══════════════════════════════════════════════════════════════
//  JAVASCRIPT — Tab switching, clipboard, status polling
// ══════════════════════════════════════════════════════════════

function wpilot_page_js( $rest_url = '' ) {
    ?>
    <script>
    /* Tab switching (Desktop / Terminal) */
    function wpiTab(name) {
        var tabs = document.querySelectorAll('#wpbody-content .wpi .wpi-tab');
        var panels = document.querySelectorAll('#wpbody-content .wpi .wpi-tab-panel');
        for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('active');
        for (var i = 0; i < panels.length; i++) panels[i].classList.remove('active');
        var panel = document.getElementById('wpi-panel-' + name);
        if (panel) panel.classList.add('active');
        /* Find the matching tab button by checking onclick attribute text */
        var allTabs = document.querySelectorAll('#wpbody-content .wpi .wpi-tab');
        for (var j = 0; j < allTabs.length; j++) {
            var onclick = allTabs[j].getAttribute('onclick') || '';
            if (onclick.indexOf(name) !== -1) {
                allTabs[j].classList.add('active');
            }
        }
    }

    /* Clipboard copy with visual feedback */
    function wpiCopy(el) {
        var text = el.getAttribute('data-copy') || el.textContent || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text.trim()).then(function() {
                wpiCopyFeedback(el);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text.trim();
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            wpiCopyFeedback(el);
        }
    }

    function wpiCopyFeedback(el) {
        /* Code blocks - add copied class for CSS animation */
        if (el.classList.contains("wpi-code")) {
            el.classList.add("copied");
            setTimeout(function(){ el.classList.remove("copied"); }, 2500);
            return;
        }
        if (el.classList.contains('wpi-conn-copy')) {
            /* Store original text content and swap to confirmation */
            var origText = el.textContent;
            el.textContent = 'COPIED!';
            el.style.background = '#16a34a';
            setTimeout(function() {
                el.textContent = 'COPY ADDRESS';
                el.style.background = '';
            }, 2000);
        } else {
            el.style.borderColor = '#22c55e';
            el.style.boxShadow = '0 0 0 4px rgba(34,197,94,0.15)';
            setTimeout(function() {
                el.style.borderColor = '';
                el.style.boxShadow = '';
            }, 2000);
        }
    }

    <?php if ( $rest_url ): ?>
    /* Connection status polling every 5 seconds */
    (function() {
        var dot = document.getElementById('wpi-status-dot');
        var txt = document.getElementById('wpi-status-text');
        if (!dot || !txt) return;

        function checkStatus() {
            fetch('<?php echo esc_js( $rest_url ); ?>', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.online) {
                    dot.className = 'wpi-status-dot online';
                    txt.textContent = 'Claude is connected';
                } else {
                    dot.className = 'wpi-status-dot offline';
                    txt.textContent = 'No active connection';
                }
            })
            .catch(function() {
                dot.className = 'wpi-status-dot offline';
                txt.textContent = 'Unable to check status';
            });
        }

        checkStatus();
        setInterval(checkStatus, 5000);
    })();
    <?php endif; ?>
    </script>
    <?php
}
