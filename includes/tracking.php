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


//

define( 'WPI_TRACKING_ENDPOINT', 'https://weblease.se/plugin' );

// Built by Weblease

// ── Send activation data when plugin is activated ────────────
function wpilot_track_activation() {
    $email = get_option('admin_email', '');
    $license = get_option('ca_license_key', '');

    $data = [
        'email'          => $email,
        'site_url'       => get_site_url(),
        'license_key'    => $license,
        'plugin_version' => CA_VERSION,
        'wp_version'     => get_bloginfo('version'),
        'php_version'    => PHP_VERSION,
    ];

    wp_remote_post( WPI_TRACKING_ENDPOINT . '/activate', [
        'timeout'  => 10,
        'blocking' => false,
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'body'     => wp_json_encode( $data ),
    ]);
}

// ── Send deactivation data when plugin is deactivated ────────
function wpilot_track_deactivation() {
    $data = [
        'email'       => get_option('admin_email', ''),
        'site_url'    => get_site_url(),
        'license_key' => get_option('ca_license_key', ''),
    ];

    // Must be blocking on deactivation or it won't send
    wp_remote_post( WPI_TRACKING_ENDPOINT . '/deactivate', [
        'timeout'  => 5,
        'blocking' => true,
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'body'     => wp_json_encode( $data ),
    ]);
}

// ── Heartbeat — sync stats every 6 hours ─────────────────────
function wpilot_track_heartbeat() {
    $license = get_option('ca_license_key', '');
    if ( empty($license) ) return;

    $brain_count = 0;
    if ( function_exists('wpilot_brain_stats') ) {
        $stats = wpilot_brain_stats();
        $brain_count = $stats['total'] ?? 0;
    }

    $data = [
        'license_key'    => $license,
        'site_url'       => get_site_url(),
        'prompts_used'   => (int) get_option('wpilot_prompts_used', 0),
        'brain_memories' => $brain_count,
        'version'        => CA_VERSION,
    ];

    wp_remote_post( WPI_TRACKING_ENDPOINT . '/heartbeat', [
        'timeout'  => 10,
        'blocking' => false,
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'body'     => wp_json_encode( $data ),
    ]);
}

// ── Schedule heartbeat every 6 hours ─────────────────────────
add_action( 'init', function() {
    if ( ! wp_next_scheduled('wpi_tracking_heartbeat') ) {
        wp_schedule_event( time(), 'wpi_six_hours', 'wpi_tracking_heartbeat' );
    }
});

add_action( 'wpi_tracking_heartbeat', 'wpilot_track_heartbeat' );

// Register custom cron interval
add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['wpi_six_hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => 'Every 6 hours',
    ];
    return $schedules;
});

// ── Also heartbeat when connected (first connection) ──
add_action( 'update_option_ca_api_key', function( $old, $new ) {
    if ( $new && $new !== $old ) {
        // Slight delay to let license check finish first
        wp_schedule_single_event( time() + 5, 'wpi_tracking_heartbeat' );
    }
}, 10, 2 );

// ── Track on activation with email ───────────────────────────
add_action( 'update_option_ca_api_key', function( $old, $new ) {
    if ( $new && ! $old ) {
        wpilot_track_activation();
    }
}, 20, 2 );

// ── Clean up cron on plugin deactivation ─────────────────────
function wpilot_tracking_cleanup() {
    wp_clear_scheduled_hook('wpi_tracking_heartbeat');
}
