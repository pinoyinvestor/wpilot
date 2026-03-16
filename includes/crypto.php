<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPilot Encryption — AES-256-CBC using WordPress AUTH_KEY salt
//  Backwards-compatible: unencrypted values auto-detected and
//  returned as-is (migrated on next save).
// ═══════════════════════════════════════════════════════════════

/**
 * Encrypt a string using AES-256-CBC with WordPress AUTH_KEY
 */
function wpilot_encrypt( $data ) {
    if ( empty( $data ) ) return '';
    if ( ! function_exists( 'openssl_encrypt' ) ) return $data; // fallback if no openssl
    $key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wpilot-default-key-change-me';
    $key = hash( 'sha256', $key, true );
    $iv  = openssl_random_pseudo_bytes( 16 );
    $encrypted = openssl_encrypt( $data, 'aes-256-cbc', $key, 0, $iv );
    return base64_encode( $iv . '::' . $encrypted );
}

// Built by Christos Ferlachidis & Daniel Hedenberg

/**
 * Decrypt a string encrypted with wpilot_encrypt()
 * Returns plaintext on legacy (unencrypted) values for backwards compatibility
 */
function wpilot_decrypt( $data ) {
    if ( empty( $data ) ) return '';
    if ( ! function_exists( 'openssl_decrypt' ) ) return $data;
    $decoded = base64_decode( $data, true );
    // Not base64 or no :: separator → plaintext (legacy unencrypted value)
    if ( $decoded === false || strpos( $decoded, '::' ) === false ) return $data;
    list( $iv, $encrypted ) = explode( '::', $decoded, 2 );
    if ( strlen( $iv ) !== 16 ) return $data; // invalid IV length → plaintext
    $key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wpilot-default-key-change-me';
    $key = hash( 'sha256', $key, true );
    $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );
    return ( $decrypted !== false ) ? $decrypted : $data;
}

/**
 * Check if a value is already encrypted (base64 with :: separator)
 */
function wpilot_is_encrypted( $data ) {
    if ( empty( $data ) ) return false;
    $decoded = base64_decode( $data, true );
    if ( $decoded === false ) return false;
    if ( strpos( $decoded, '::' ) === false ) return false;
    list( $iv, ) = explode( '::', $decoded, 2 );
    return strlen( $iv ) === 16;
}

/**
 * Migrate existing plaintext API keys to encrypted storage
 * Called on admin_init — runs once and sets a flag
 */
function wpilot_migrate_encrypt_keys() {
    // Skip if already migrated
    if ( get_option( 'wpilot_keys_encrypted', false ) ) return;

    $migrated = false;

    // Migrate third-party API keys (Stripe, Mailchimp, etc.)
    $keys = get_option( 'wpilot_api_keys', [] );
    if ( ! empty( $keys ) ) {
        foreach ( $keys as $name => &$data ) {
            if ( ! empty( $data['key'] ) && ! wpilot_is_encrypted( $data['key'] ) ) {
                $data['key'] = wpilot_encrypt( $data['key'] );
                $migrated = true;
            }
            // Also encrypt secret field if present (e.g. reCAPTCHA)
            if ( ! empty( $data['secret'] ) && ! wpilot_is_encrypted( $data['secret'] ) ) {
                $data['secret'] = wpilot_encrypt( $data['secret'] );
                $migrated = true;
            }
        }
        unset( $data );
        if ( $migrated ) update_option( 'wpilot_api_keys', $keys );
    }

    // Migrate Claude API key
    $claude_key = get_option( 'ca_api_key', '' );
    if ( ! empty( $claude_key ) && ! wpilot_is_encrypted( $claude_key ) ) {
        update_option( 'ca_api_key', wpilot_encrypt( $claude_key ) );
        $migrated = true;
    }

    // Migrate reCAPTCHA keys stored separately
    $rc_site = get_option( 'wpilot_recaptcha_site_key', '' );
    if ( ! empty( $rc_site ) && ! wpilot_is_encrypted( $rc_site ) ) {
        update_option( 'wpilot_recaptcha_site_key', wpilot_encrypt( $rc_site ) );
    }
    $rc_secret = get_option( 'wpilot_recaptcha_secret_key', '' );
    if ( ! empty( $rc_secret ) && ! wpilot_is_encrypted( $rc_secret ) ) {
        update_option( 'wpilot_recaptcha_secret_key', wpilot_encrypt( $rc_secret ) );
    }

    update_option( 'wpilot_keys_encrypted', true );
}

/**
 * Helper: get a decrypted API key by name from wpilot_api_keys
 */
function wpilot_get_api_key( $name ) {
    $keys = get_option( 'wpilot_api_keys', [] );
    if ( ! isset( $keys[ $name ] ) ) return null;
    $data = $keys[ $name ];
    $data['key'] = wpilot_decrypt( $data['key'] ?? '' );
    if ( isset( $data['secret'] ) ) {
        $data['secret'] = wpilot_decrypt( $data['secret'] );
    }
    return $data;
}

/**
 * Helper: get decrypted Claude API key
 */
function wpilot_get_claude_key() {
    return wpilot_decrypt( get_option( 'ca_api_key', '' ) );
}
