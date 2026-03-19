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


// ═══════════════════════════════════════════════════════════════
//  LICENSE + TIER SYSTEM
//
//  Tier 1 — LIFETIME (first 20 API connections)
//    • Connect Claude
//    • Server checks: are slots remaining?
//    • If yes → mark as Lifetime, no monthly fee ever

//
//  Tier 2 — FREE
//    • 20 free prompts
//    • After 20 → must upgrade to Pro
//
//  Tier 3 — PRO ($9/month)
//    • Unlimited prompts
//    • All features unlocked
//
//  License types stored in wpilot_license_type:
//    'lifetime' | 'pro' | '' (free)
// ═══════════════════════════════════════════════════════════════

// ── Helpers ────────────────────────────────────────────────────
function wpilot_license_type()       { return get_option('wpilot_license_type', ''); }
function wpilot_get_license_tier()  { 
    $t = wpilot_license_type();
    return $t ?: 'free';
}
function wpilot_is_lifetime()    { return wpilot_license_type() === 'lifetime'; }
function wpilot_is_pro()         { return in_array(wpilot_license_type(), ['lifetime','pro','team']); }
function wpilot_is_licensed()    { return wpilot_is_pro(); }  // backwards compat

function wpilot_prompts_used()   { return (int) get_option('wpilot_prompts_used', 0); }
function wpilot_is_locked()      { return !wpilot_can_use(); }
function wpilot_prompts_remaining() {
    if ( wpilot_is_pro() ) return '∞';
    return max(0, CA_FREE_LIMIT - wpilot_prompts_used());
}
function wpilot_bump_prompts()   { update_option('wpilot_prompts_used', wpilot_prompts_used() + 1); }
function wpilot_increment_prompts() { wpilot_bump_prompts(); } // alias

// ── Check lifetime slot availability (from server) ────────────
function wpilot_lifetime_slots_remaining() {
    $cached = get_transient('wpi_lifetime_slots');
    if ( $cached !== false ) return (int)$cached;

    $res = wp_remote_get( WPI_LICENSE_SERVER . '/lifetime-slots', ['timeout' => 8] );
    if ( is_wp_error($res) ) return -1; // unknown, assume slots exist

    $data = json_decode(wp_remote_retrieve_body($res), true);
    $remaining = (int)($data['remaining'] ?? 0);
    set_transient('wpi_lifetime_slots', $remaining, 300); // cache 5 min
    return $remaining;
}

// ── When Claude is first connected → check for lifetime slot ──
function wpilot_check_lifetime_on_connect( $api_key ) {
    if ( wpilot_is_pro() ) return; // already licensed

    $slots = wpilot_lifetime_slots_remaining();

    if ( $slots === 0 ) return; // slots full, stays on free tier

    // Claim a lifetime slot
    $res = wp_remote_post( WPI_LICENSE_SERVER . '/lifetime-claim', [
        'timeout' => 12,
        'body'    => [
            'site_url'   => get_site_url(),
            'api_key_hash' => md5($api_key),
            'version'    => CA_VERSION,
        ],
    ]);

    if ( is_wp_error($res) ) return;

    $data = json_decode(wp_remote_retrieve_body($res), true);

    if ( !empty($data['granted']) ) {
        update_option('wpilot_license_type',   'lifetime');
        update_option('ca_license_key',    $data['license_key'] ?? 'LIFETIME-' . substr(md5($api_key),0,8));
        update_option('ca_license_status', 'active');
        update_option('ca_lifetime_slot',  $data['slot_number'] ?? '?');
        delete_transient('wpi_lifetime_slots');

        // Log it
        wpilot_log_activity('lifetime_granted',
// Built by Weblease
            '🎉 Lifetime access granted',
            'Slot #' . ($data['slot_number'] ?? '?') . ' of ' . CA_LIFETIME_SLOTS
        );
    }
}

// ── Hook: whenever Claude is connected → check lifetime ───────────
add_action('update_option_ca_api_key', function($old, $new) {
    if ( $new && $new !== $old ) {
        wpilot_check_lifetime_on_connect($new);
    }
}, 10, 2);

// Also check on first save (add_option)
add_action('added_option', function($name, $value) {
    if ( $name === 'ca_api_key' && $value ) {
        wpilot_check_lifetime_on_connect($value);
    }
}, 10, 2);

// ── Activate Pro license key ───────────────────────────────────
add_action('wp_ajax_ca_activate_license', function() {
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');

    $key    = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
    $server = WPI_LICENSE_SERVER;

    $res = wp_remote_post(trailingslashit($server) . 'validate', [
        'timeout' => 15,
        'body'    => ['key' => $key, 'site_url' => get_site_url(), 'version' => CA_VERSION],
    ]);

    // Offline fallback
    if (is_wp_error($res)) {
        if (preg_match('/^CA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key)) {
            update_option('ca_license_key',    $key);
            update_option('ca_license_status', 'active');
            update_option('wpilot_license_type',   'pro');
            wp_send_json_success(['message' => '✅ Pro license activated (offline mode).', 'type' => 'pro']);
        }
        wp_send_json_error('Could not reach license server: ' . $res->get_error_message());
    }

    $data = json_decode(wp_remote_retrieve_body($res), true);

    if (!empty($data['valid'])) {
        $type = $data['type'] ?? 'pro'; // 'lifetime' or 'pro'
        update_option('ca_license_key',    $key);
        update_option('ca_license_status', 'active');
        update_option('wpilot_license_type',   $type);
        update_option('ca_license_email',  $data['email'] ?? '');

        $msg = $type === 'lifetime'
            ? '🎉 Lifetime access activated! You\'re one of the first ' . CA_LIFETIME_SLOTS . '. No monthly fees ever.'
            : '✅ Pro license activated! Unlimited access unlocked.';

        wp_send_json_success(['message' => $msg, 'type' => $type]);
    }

    update_option('ca_license_status', 'invalid');
    wp_send_json_error($data['message'] ?? 'Invalid license key.');
});

// ── Deactivate ─────────────────────────────────────────────────
add_action('wp_ajax_ca_deactivate_license', function() {
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');
    delete_option('ca_license_key');
    delete_option('ca_license_status');
    delete_option('wpilot_license_type');
    delete_option('ca_license_email');
    wp_send_json_success('License deactivated.');
});

// ── AJAX: get current slot count (for settings page) ──────────
add_action('wp_ajax_wpi_get_lifetime_slots', function() {
    check_ajax_referer('ca_nonce','nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    $slots = wpilot_lifetime_slots_remaining();
    $taken = CA_LIFETIME_SLOTS - max(0, $slots);
    wp_send_json_success([
        'remaining' => $slots,
        'taken'     => $taken,
        'total'     => CA_LIFETIME_SLOTS,
        'full'      => $slots <= 0,
    ]);
});
