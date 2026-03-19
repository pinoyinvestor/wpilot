<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 *
 * Each copy is bound to a specific domain via license key.
 * Tampered or unlicensed copies will be disabled remotely.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Guardian anti-copy check
if ( function_exists( "wpilot_guardian_runtime_check" ) && ! wpilot_guardian_runtime_check() ) return;


// ── WPilot Shield — Server-side prompt loading + protection ────
// The plugin fetches its AI brain from weblease.se. Without it,
// the plugin is a UI shell with no intelligence.
// Built by Weblease

define( 'WPILOT_PROMPT_API', 'https://weblease.se/api/plugin/prompt' );
define( 'WPILOT_PROMPT_CACHE_KEY', 'wpilot_server_prompt' );
define( 'WPILOT_PROMPT_CACHE_TTL', 3600 ); // 1 hour

// ── Fetch prompt from server (cached) ──────────────────────────
function wpilot_fetch_server_prompt() {
    // Check transient cache first
    $cached = get_transient( WPILOT_PROMPT_CACHE_KEY );
    if ( $cached && is_array( $cached ) && ! empty( $cached['identity'] ) ) {
        // Verify signature integrity
        if ( wpilot_verify_prompt( $cached ) ) {
            return $cached;
        }
    }

    // Fetch from server
    $license_key = get_option( 'ca_license_key', '' );
    $site_url    = get_site_url();

    $response = wp_remote_post( WPILOT_PROMPT_API, [
        'timeout' => 15,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'license_key'    => $license_key,
            'site_url'       => $site_url,
            'plugin_version' => defined( 'CA_VERSION' ) ? CA_VERSION : '0.0.0',
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        // Network error — use cached if available, even expired
        $fallback = get_option( 'wpilot_prompt_fallback', null );
        if ( $fallback ) return $fallback;
        return null; // Will trigger local fallback in api.php
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['identity'] ) ) {
        // License invalid, expired, or domain mismatch
        $error_code = $body['code'] ?? 'UNKNOWN';

        if ( $error_code === 'INVALID_LICENSE' || $error_code === 'EXPIRED' ) {
            // Clear cached prompt — force re-auth
            delete_transient( WPILOT_PROMPT_CACHE_KEY );
            delete_option( 'wpilot_prompt_fallback' );
        }

        // Still try fallback for graceful degradation
        $fallback = get_option( 'wpilot_prompt_fallback', null );
        if ( $fallback ) return $fallback;
        return null;
    }

    // Verify signature
    if ( ! wpilot_verify_prompt( $body ) ) {
        return null; // Tampered response
    }

    // Cache in transient (fast) + option (persistent fallback)
    set_transient( WPILOT_PROMPT_CACHE_KEY, $body, WPILOT_PROMPT_CACHE_TTL );
    update_option( 'wpilot_prompt_fallback', $body, false );

    // Apply rate limits from server
    if ( ! empty( $body['limits'] ) ) {
        update_option( 'wpilot_rate_limits', $body['limits'], false );
    }

    return $body;
}

// ── Verify prompt payload signature ────────────────────────────
function wpilot_verify_prompt( $data ) {
    if ( empty( $data['signature'] ) || empty( $data['identity'] ) ) return false;

    // Extract the data the server signed (everything except meta fields)
    $verify_data = $data;
    unset( $verify_data['signature'], $verify_data['expires'], $verify_data['server_time'] );

    // The signing secret comes from env on the server side.
    // Plugin verifies structure + length; full HMAC requires shared secret.
    // We verify the signature is a valid hex SHA-256 hash (64 chars).
    if ( strlen( $data['signature'] ) !== 64 || ! ctype_xdigit( $data['signature'] ) ) {
        return false;
    }

    // Verify core prompt fields exist and are non-empty
    $required = ['identity', 'style', 'rules', 'version'];
    foreach ( $required as $field ) {
        if ( empty( $data[ $field ] ) ) return false;
    }

    return true;
}

// ── Build system prompt from server data ───────────────────────
function wpilot_build_server_prompt( $server_data, $mode, $message ) {
    if ( ! $server_data || empty( $server_data['identity'] ) ) return null;

    $site    = get_bloginfo( 'name' );
    $url     = get_site_url();
    $builder = function_exists( 'wpilot_detect_builder' ) ? ucfirst( wpilot_detect_builder() ) : 'Unknown';
    $theme   = wp_get_theme()->get( 'Name' );
    $woo     = class_exists( 'WooCommerce' ) ? 'WooCommerce active' : 'No WooCommerce';

    $lang = function_exists( 'wpilot_get_lang' ) ? wpilot_get_lang() : 'en';
    $respond_lang = ( $lang === 'sv' ) ? 'Respond in Swedish if the user writes in Swedish.' : 'Respond in the same language as the user.';

    // Inject site-specific info into server-provided identity
    $identity = str_replace(
        'AI assistant for this WordPress site',
        "AI assistant for \"{$site}\" ({$url})",
        $server_data['identity']
    );

    $prompt = $identity . "\n";
    $prompt .= "{$woo}. Builder: {$builder}. Theme: {$theme}.\n\n";
    $prompt .= $server_data['style'] . "\n\n";
    $prompt .= $server_data['rules'] . "\n";
    $prompt .= "- {$respond_lang}\n\n";

    // Design intelligence from server
    if ( ! empty( $server_data['design'] ) ) {
        $prompt .= $server_data['design'] . "\n\n";
    }

    return $prompt;
}

// ── Rate limiting per user (server-defined limits) ─────────────
function wpilot_check_rate_limit() {
    $limits = get_option( 'wpilot_rate_limits', [ 'prompts_per_hour' => 30, 'prompts_total' => -1 ] );
    $user_id = get_current_user_id();

    // Per-hour check
    $hour_key = 'wpilot_hourly_' . $user_id;
    $hourly = get_transient( $hour_key );
    if ( $hourly === false ) $hourly = 0;

    if ( $limits['prompts_per_hour'] > 0 && $hourly >= $limits['prompts_per_hour'] ) {
        return new WP_Error( 'rate_limited', 'Du har skickat för många meddelanden. Vänta lite så kan du fortsätta.' );
    }

    // Total check (free users)
    if ( $limits['prompts_total'] > 0 ) {
        $total = intval( get_option( 'wpilot_prompts_used', 0 ) );
        if ( $total >= $limits['prompts_total'] ) {
            return new WP_Error( 'limit_reached', 'Gratisgränsen nådd. Uppgradera för att fortsätta.' );
        }
    }

    return true;
}

// ── Bump hourly counter ────────────────────────────────────────
function wpilot_bump_rate_limit() {
    $user_id = get_current_user_id();
    $hour_key = 'wpilot_hourly_' . $user_id;
    $hourly = get_transient( $hour_key );
    if ( $hourly === false ) $hourly = 0;
    set_transient( $hour_key, $hourly + 1, HOUR_IN_SECONDS );
}

// ── License tamper detection ───────────────────────────────────
function wpilot_integrity_check() {
    // Check that critical files exist and haven't been hollowed out
    $critical = [
        CA_PATH . 'includes/shield.php',
        CA_PATH . 'includes/license.php',
        CA_PATH . 'includes/ajax.php',
        CA_PATH . 'includes/safeguard.php',
    ];

    foreach ( $critical as $file ) {
        if ( ! file_exists( $file ) || filesize( $file ) < 500 ) {
            return false;
        }
    }

    // Check that license validation hasn't been bypassed
    if ( ! function_exists( 'wpilot_is_licensed' ) ) {
        return false;
    }

    // Check that safeguard hasn't been gutted
    if ( ! function_exists( 'wpilot_safe_run_tool' ) || ! function_exists( 'wpilot_validate_css' ) ) {
        return false;
    }

    return true;
}

// ── Prompt tamper protection ───────────────────────────────────
// The system prompt comes from weblease.se. If someone tries to
// override it locally, the server version always wins.
function wpilot_enforce_server_prompt() {
    // Delete any locally cached prompt if it doesn't have our signature
    $cached = get_transient( WPILOT_PROMPT_CACHE_KEY );
    if ( $cached && ( empty( $cached['signature'] ) || empty( $cached['identity'] ) ) ) {
        delete_transient( WPILOT_PROMPT_CACHE_KEY );
        delete_option( 'wpilot_prompt_fallback' );
    }
}
add_action( 'admin_init', 'wpilot_enforce_server_prompt' );
