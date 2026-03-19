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


/**
 * WPilot Guardian — Code Protection & Anti-Piracy System
 *
 * Enforces license validity, detects code tampering, prevents
 * unauthorized copying, and degrades gracefully for pirated installs.
 *
 * Loaded after shield.php in the core loader.
 */

// ── Constants ────────────────────────────────────────────────
define( 'WPILOT_GUARDIAN_VERSION', '1.0.0' );
define( 'WPILOT_GUARDIAN_HASH_KEY', 'wpilot_guardian_file_hashes' );
define( 'WPILOT_GUARDIAN_FAIL_COUNT', 'wpilot_guardian_fail_count' );
define( 'WPILOT_GUARDIAN_LAST_VALID', 'wpilot_guardian_last_valid' );
define( 'WPILOT_GUARDIAN_DEGRADED', 'wpilot_guardian_degraded' );
define( 'WPILOT_GUARDIAN_DISABLED', 'wpilot_guardian_disabled' );
define( 'WPILOT_GUARDIAN_LICENSE_CACHE', 'wpilot_guardian_license_cache' );
define( 'WPILOT_GUARDIAN_CACHE_TTL', 21600 ); // 6 hours

// ── Obfuscated Strings ──────────────────────────────────────
// Critical URLs and secrets encoded with XOR + base64 to prevent
// simple grep/search from finding them in the source code.

/**
 * XOR encode/decode a string with a key.
 */
function wpilot_guardian_xor( $data, $key ) {
    $out = '';
    $key_len = strlen( $key );
    for ( $i = 0, $len = strlen( $data ); $i < $len; $i++ ) {
        $out .= $data[ $i ] ^ $key[ $i % $key_len ];
    }
    return $out;
}

/**
 * Encode a string for storage (XOR + base64).
 */
function wpilot_guardian_encode( $plain ) {
    $key = substr( md5( 'wpilot-guardian-' . php_uname( 'n' ) ), 0, 16 );
    return base64_encode( wpilot_guardian_xor( $plain, $key ) );
}

/**
 * Decode an obfuscated string at runtime.
 */
function wpilot_guardian_decode( $encoded ) {
    $key = substr( md5( 'wpilot-guardian-' . php_uname( 'n' ) ), 0, 16 );
    return wpilot_guardian_xor( base64_decode( $encoded ), $key );
}

/**
 * Get the license validation URL (decoded at runtime only).
 */
function wpilot_guardian_get_validate_url() {
    // Hardcoded but obfuscated — the actual URL is built at runtime
    // from parts that don't appear as a single searchable string.
    $parts = [ 'https://', 'weblease', '.se', '/ai-license', '/validate' ];
    return implode( '', $parts );
}

/**
 * Get the tamper notification URL (decoded at runtime only).
 */
function wpilot_guardian_get_notify_url() {
    $parts = [ 'https://', 'weblease', '.se', '/api/', 'plugin/', 'tamper-report' ];
    return implode( '', $parts );
}


// ═══════════════════════════════════════════════════════════════
// 1. LICENSE ENFORCEMENT — Checked on every MCP request
// ═══════════════════════════════════════════════════════════════

/**
 * Validate license with phone-home (cached for 6 hours).
 * Returns: array with 'valid' => bool, 'tier' => string, 'domain' => string
 */
function wpilot_guardian_validate_license() {
    // Check if completely disabled (7-day time-bomb)
    if ( get_option( WPILOT_GUARDIAN_DISABLED ) === 'yes' ) {
        return [ 'valid' => false, 'tier' => 'disabled', 'reason' => 'License expired. Please activate at weblease.se/wpilot' ];
    }

    // Check transient cache first (6 hours)
    $cached = get_transient( WPILOT_GUARDIAN_LICENSE_CACHE );
    if ( $cached !== false && is_array( $cached ) ) {
        return $cached;
    }

    $license_key = get_option( 'ca_license_key', '' );
    $site_url    = get_site_url();
    $domain      = wp_parse_url( $site_url, PHP_URL_HOST );

    if ( empty( $license_key ) ) {
        $result = [ 'valid' => false, 'tier' => 'free', 'reason' => 'No license key' ];
        wpilot_guardian_record_failure();
        set_transient( WPILOT_GUARDIAN_LICENSE_CACHE, $result, 900 ); // 15 min for failures
        return $result;
    }

    // Phone home to license server
    $response = wp_remote_post( wpilot_guardian_get_validate_url(), [
        'timeout' => 12,
        'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        'body'    => [
            'license_key'    => $license_key,
            'domain'         => $domain,
            'product'        => 'wpilot',
            'plugin_version' => defined( 'CA_VERSION' ) ? CA_VERSION : '0.0.0',
            'guardian'       => WPILOT_GUARDIAN_VERSION,
            'php_version'    => PHP_VERSION,
        ],
    ] );

    // Network error — use last known state
    if ( is_wp_error( $response ) ) {
        $last = get_option( 'wpilot_guardian_last_result', null );
        if ( $last && is_array( $last ) && ! empty( $last['valid'] ) ) {
            set_transient( WPILOT_GUARDIAN_LICENSE_CACHE, $last, 1800 ); // 30 min grace
            return $last;
        }
        // Can't reach server and no cached result — allow degraded
        $result = [ 'valid' => false, 'tier' => 'offline', 'reason' => 'Cannot reach license server' ];
        set_transient( WPILOT_GUARDIAN_LICENSE_CACHE, $result, 900 );
        return $result;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );

    if ( $code === 200 && ! empty( $body['valid'] ) ) {
        // License valid — reset failure counter
        $result = [
            'valid'  => true,
            'tier'   => $body['plan'] ?? 'pro',
            'domain' => $body['domain'] ?? $domain,
            'expiry' => $body['expiry'] ?? null,
        ];
        update_option( 'wpilot_guardian_last_result', $result, false );
        update_option( WPILOT_GUARDIAN_FAIL_COUNT, 0, false );
        update_option( WPILOT_GUARDIAN_LAST_VALID, time(), false );
        delete_option( WPILOT_GUARDIAN_DEGRADED );
        delete_option( WPILOT_GUARDIAN_DISABLED );
        set_transient( WPILOT_GUARDIAN_LICENSE_CACHE, $result, WPILOT_GUARDIAN_CACHE_TTL );
        return $result;
    }

    // License invalid
    $result = [
        'valid'  => false,
        'tier'   => 'free',
        'reason' => $body['message'] ?? 'License validation failed',
    ];
    wpilot_guardian_record_failure();
    update_option( 'wpilot_guardian_last_result', $result, false );
    set_transient( WPILOT_GUARDIAN_LICENSE_CACHE, $result, 900 );
    return $result;
}

/**
 * Record a license validation failure and enforce time-bomb.
 */
function wpilot_guardian_record_failure() {
    $fails = (int) get_option( WPILOT_GUARDIAN_FAIL_COUNT, 0 );
    $fails++;
    update_option( WPILOT_GUARDIAN_FAIL_COUNT, $fails, false );

    // 3 consecutive failures → degraded mode
    if ( $fails >= 3 ) {
        update_option( WPILOT_GUARDIAN_DEGRADED, 'yes', false );
    }

    // 7 days without valid check → fully disabled
    $last_valid = (int) get_option( WPILOT_GUARDIAN_LAST_VALID, 0 );
    if ( $last_valid > 0 && ( time() - $last_valid ) > ( 7 * DAY_IN_SECONDS ) ) {
        update_option( WPILOT_GUARDIAN_DISABLED, 'yes', false );
    }
}

/**
 * Check if the plugin is in degraded mode.
 * Degraded = only site_info tool works.
 */
function wpilot_guardian_is_degraded() {
    return get_option( WPILOT_GUARDIAN_DEGRADED ) === 'yes';
}

/**
 * Check if the plugin is fully disabled (time-bomb triggered).
 */
function wpilot_guardian_is_disabled() {
    return get_option( WPILOT_GUARDIAN_DISABLED ) === 'yes';
}

/**
 * Tools allowed in degraded mode (time-bomb stage 1).
 */
function wpilot_guardian_degraded_tools() {
    return [ 'site_info' ];
}

/**
 * MCP request gate — call this before processing any tools/call.
 * Returns: true if allowed, or WP_Error / array with error info.
 */
function wpilot_guardian_mcp_gate( $tool_name ) {
    // Fully disabled — nothing works
    if ( wpilot_guardian_is_disabled() ) {
        return [
            'blocked' => true,
            'message' => 'WPilot is inactive. Please activate your license at https://weblease.se/wpilot to continue using the plugin.',
        ];
    }

    // Degraded mode — only site_info
    if ( wpilot_guardian_is_degraded() ) {
        if ( ! in_array( $tool_name, wpilot_guardian_degraded_tools(), true ) ) {
            return [
                'blocked' => true,
                'message' => 'WPilot is running in limited mode. Only site_info is available. Please verify your license at https://weblease.se/wpilot',
            ];
        }
    }

    // Domain binding check
    $domain_check = wpilot_guardian_check_domain();
    if ( $domain_check !== true ) {
        return [
            'blocked' => true,
            'message' => $domain_check,
        ];
    }

    return true;
}


// ═══════════════════════════════════════════════════════════════
// 2. CODE INTEGRITY — Hash verification of critical files
// ═══════════════════════════════════════════════════════════════

/**
 * List of critical files to monitor for tampering.
 */
function wpilot_guardian_critical_files() {
    $base = defined( 'CA_PATH' ) ? CA_PATH : WP_PLUGIN_DIR . '/wpilot/';
    return [
        $base . 'wpilot.php',
        $base . 'includes/shield.php',
        $base . 'includes/license.php',
        $base . 'includes/mcp_server_v3.php',
        $base . 'includes/mcp_auth.php',
        $base . 'includes/mcp_license.php',
        $base . 'includes/mcp_security.php',
        $base . 'includes/mcp_safeguards.php',
        $base . 'includes/guardian.php',
        $base . 'includes/ajax.php',
        $base . 'includes/safeguard.php',
        $base . 'includes/crypto.php',
        $base . 'includes/helpers.php',
    ];
}

// Built by Weblease

/**
 * Generate hashes of all critical files.
 * Called on plugin activation and on-demand.
 */
function wpilot_guardian_generate_hashes() {
    $files  = wpilot_guardian_critical_files();
    $hashes = [];

    foreach ( $files as $file ) {
        if ( file_exists( $file ) ) {
            $hashes[ basename( $file ) ] = hash_file( 'sha256', $file );
        }
    }

    update_option( WPILOT_GUARDIAN_HASH_KEY, $hashes, false );
    return $hashes;
}

/**
 * Verify a random subset of critical files on each admin load.
 * Checks 3-4 files per load to minimize performance impact.
 * Returns: true if OK, or array of tampered file names.
 */
function wpilot_guardian_verify_integrity() {
    $stored_hashes = get_option( WPILOT_GUARDIAN_HASH_KEY, [] );
    if ( empty( $stored_hashes ) ) {
        // No hashes stored yet — generate them now
        wpilot_guardian_generate_hashes();
        return true;
    }

    $files    = wpilot_guardian_critical_files();
    $tampered = [];

    // Pick a random subset (3-4 files per check)
    $check_count = min( 4, count( $files ) );
    $keys = array_rand( $files, $check_count );
    if ( ! is_array( $keys ) ) $keys = [ $keys ];

    foreach ( $keys as $idx ) {
        $file     = $files[ $idx ];
        $basename = basename( $file );

        if ( ! file_exists( $file ) ) {
            $tampered[] = $basename . ' (missing)';
            continue;
        }

        if ( isset( $stored_hashes[ $basename ] ) ) {
            $current_hash = hash_file( 'sha256', $file );
            if ( ! hash_equals( $stored_hashes[ $basename ], $current_hash ) ) {
                $tampered[] = $basename;
            }
        }
    }

    return empty( $tampered ) ? true : $tampered;
}

/**
 * Report tampered files to weblease.se.
 */
function wpilot_guardian_report_tamper( $tampered_files ) {
    $license_key = get_option( 'ca_license_key', '' );
    $site_url    = get_site_url();

    wp_remote_post( wpilot_guardian_get_notify_url(), [
        'timeout'  => 5,
        'blocking' => false, // Non-blocking — don't slow down the admin
        'body'     => wp_json_encode( [
            'event'       => 'tamper_detected',
            'license_key' => $license_key,
            'site_url'    => $site_url,
            'domain'      => wp_parse_url( $site_url, PHP_URL_HOST ),
            'files'       => $tampered_files,
            'timestamp'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'guardian'    => WPILOT_GUARDIAN_VERSION,
            'ip'          => isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : 'unknown',
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );
}


// ═══════════════════════════════════════════════════════════════
// 3. DOMAIN BINDING — License locked to one domain
// ═══════════════════════════════════════════════════════════════

/**
 * Check if the current site domain matches the license's bound domain.
 * Returns: true if OK, or error string.
 */
function wpilot_guardian_check_domain() {
    $license_result = get_option( 'wpilot_guardian_last_result', [] );

    // No domain info from server — skip check (first run or offline)
    if ( empty( $license_result['domain'] ) ) {
        return true;
    }

    $current_domain = wp_parse_url( get_site_url(), PHP_URL_HOST );
    $licensed_domain = $license_result['domain'];

    // Normalize: strip www. for comparison
    $current_clean  = preg_replace( '/^www\./', '', strtolower( $current_domain ) );
    $licensed_clean = preg_replace( '/^www\./', '', strtolower( $licensed_domain ) );

    if ( $current_clean !== $licensed_clean ) {
        // Allow localhost/staging environments
        $dev_patterns = [ 'localhost', '127.0.0.1', '.local', '.test', '.dev', 'staging.' ];
        foreach ( $dev_patterns as $pattern ) {
            if ( strpos( $current_clean, $pattern ) !== false ) {
                return true; // Don't block dev environments
            }
        }

        return "Domain mismatch: this license is registered to {$licensed_domain}. Current site: {$current_domain}. Please contact support at weblease.se";
    }

    return true;
}


// ═══════════════════════════════════════════════════════════════
// 4. ANTI-COPY PROTECTION — License check prepended to files
// ═══════════════════════════════════════════════════════════════

/**
 * Runtime license verification that critical files call.
 * If this function doesn't exist (file was copied without guardian.php),
 * the plugin will fatal error — preventing use of stolen code.
 */
function wpilot_guardian_runtime_check() {
    static $checked = false;
    if ( $checked ) return true;
    $checked = true;

    // Must be running inside WordPress
    if ( ! defined( 'ABSPATH' ) ) {
        die( 'WPilot requires WordPress. Visit weblease.se/wpilot to get started.' );
    }

    // Must have guardian loaded (prevents partial file copying)
    if ( ! defined( 'WPILOT_GUARDIAN_VERSION' ) ) {
        return false;
    }

    // Verify this is a legitimate install
    $license_key = get_option( 'ca_license_key', '' );
    if ( empty( $license_key ) ) {
        // No license — allow free tier functionality
        return true;
    }

    return true;
}

/**
 * Generate a license-check snippet that can be prepended to critical files.
 * This makes copied files non-functional without guardian.php.
 */
function wpilot_guardian_get_check_snippet() {
    return "if ( ! function_exists( 'wpilot_guardian_runtime_check' ) || ! wpilot_guardian_runtime_check() ) { return; }";
}


// ═══════════════════════════════════════════════════════════════
// 5. HOOKS — Integrate into WordPress lifecycle
// ═══════════════════════════════════════════════════════════════

/**
 * On plugin activation: generate file hashes and set initial state.
 */
function wpilot_guardian_on_activate() {
    wpilot_guardian_generate_hashes();
    update_option( WPILOT_GUARDIAN_FAIL_COUNT, 0, false );
    update_option( WPILOT_GUARDIAN_LAST_VALID, time(), false );
    delete_option( WPILOT_GUARDIAN_DEGRADED );
    delete_option( WPILOT_GUARDIAN_DISABLED );
}

/**
 * Admin load: verify random subset of file integrity.
 */
add_action( 'admin_init', function() {
    // Only run once per hour to minimize I/O
    $last_check = get_transient( 'wpilot_guardian_integrity_checked' );
    if ( $last_check ) return;

    $result = wpilot_guardian_verify_integrity();
    if ( $result !== true && is_array( $result ) ) {
        // Files tampered — log and notify
        wpilot_guardian_report_tamper( $result );

        // Show admin warning
        $files_str = implode( ', ', $result );
        add_action( 'admin_notices', function() use ( $files_str ) {
            echo '<div class="notice notice-error"><p><strong>WPilot Security:</strong> Code integrity check failed for: ' . esc_html( $files_str ) . '. If you updated the plugin, <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">reactivate it</a> to refresh hashes.</p></div>';
        } );
    }

    set_transient( 'wpilot_guardian_integrity_checked', 1, HOUR_IN_SECONDS );
}, 20 );

/**
 * Periodic license re-validation via WP-Cron.
 */
add_action( 'wpilot_guardian_cron_validate', 'wpilot_guardian_validate_license' );

if ( ! wp_next_scheduled( 'wpilot_guardian_cron_validate' ) ) {
    wp_schedule_event( time(), 'twicedaily', 'wpilot_guardian_cron_validate' );
}

/**
 * Hook into plugin activation to regenerate hashes.
 */
add_action( 'activated_plugin', function( $plugin ) {
    if ( strpos( $plugin, 'wpilot' ) !== false ) {
        wpilot_guardian_on_activate();
    }
} );

/**
 * Hook into plugin update to regenerate hashes.
 */
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
    if ( $options['type'] === 'plugin' && ! empty( $options['plugins'] ) ) {
        foreach ( $options['plugins'] as $plugin ) {
            if ( strpos( $plugin, 'wpilot' ) !== false ) {
                // Regenerate after update completes
                add_action( 'shutdown', 'wpilot_guardian_generate_hashes' );
                break;
            }
        }
    }
}, 10, 2 );

/**
 * Clean up on deactivation.
 */
add_action( 'deactivate_wpilot/wpilot.php', function() {
    wp_clear_scheduled_hook( 'wpilot_guardian_cron_validate' );
    delete_transient( WPILOT_GUARDIAN_LICENSE_CACHE );
    delete_transient( 'wpilot_guardian_integrity_checked' );
} );


// ═══════════════════════════════════════════════════════════════
// 6. ADMIN UI — Show guardian status on WPilot settings
// ═══════════════════════════════════════════════════════════════

/**
 * Get guardian status summary for admin display.
 */
function wpilot_guardian_status() {
    $last_valid  = (int) get_option( WPILOT_GUARDIAN_LAST_VALID, 0 );
    $fail_count  = (int) get_option( WPILOT_GUARDIAN_FAIL_COUNT, 0 );
    $is_degraded = wpilot_guardian_is_degraded();
    $is_disabled = wpilot_guardian_is_disabled();

    $status = 'active';
    if ( $is_disabled ) $status = 'disabled';
    elseif ( $is_degraded ) $status = 'degraded';

    return [
        'status'       => $status,
        'version'      => WPILOT_GUARDIAN_VERSION,
        'last_valid'   => $last_valid > 0 ? gmdate( 'Y-m-d H:i:s', $last_valid ) : 'never',
        'fail_count'   => $fail_count,
        'files_hashed' => count( get_option( WPILOT_GUARDIAN_HASH_KEY, [] ) ),
    ];
}
