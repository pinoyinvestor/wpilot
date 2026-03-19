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
 * WPilot MCP Server — License Validation
 * Checks WPilot license tier for MCP access control.
 */

/**
 * Validate WPilot license for MCP access.
 * Returns: 'pro', 'team', 'lifetime', 'free', or false.
 * Caches result for 1 hour in transient.
 */
function wpilot_mcp_check_license() {
    $cached = get_transient( 'wpilot_mcp_license_tier' );
    if ( $cached !== false ) return $cached;

    $license_key = get_option( 'ca_license_key', '' );
    if ( empty( $license_key ) ) {
        set_transient( 'wpilot_mcp_license_tier', 'free', HOUR_IN_SECONDS );
        return 'free';
    }

    // Built by Weblease

    $response = wp_remote_post( 'https://weblease.se/ai-license/validate', [
        'timeout' => 10,
        'body'    => [
            'license_key' => $license_key,
            'domain'      => parse_url( get_site_url(), PHP_URL_HOST ),
            'product'     => 'wpilot',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        $prev = get_option( 'wpilot_mcp_last_tier', 'free' );
        set_transient( 'wpilot_mcp_license_tier', $prev, 900 );
        return $prev;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['valid'] ) ) {
        $tier = $body['plan'] ?? 'pro';
        set_transient( 'wpilot_mcp_license_tier', $tier, HOUR_IN_SECONDS );
        update_option( 'wpilot_mcp_last_tier', $tier );
        return $tier;
    }

    set_transient( 'wpilot_mcp_license_tier', 'free', HOUR_IN_SECONDS );
    update_option( 'wpilot_mcp_last_tier', 'free' );
    return 'free';
}

/**
 * Free tier tools — available without license.
 */
function wpilot_mcp_free_tools() {
    return [ 'site_info', 'pages', 'check_frontend', 'posts' ];
}

/**
 * Check if a grouped tool is available for the current license tier.
 */
function wpilot_mcp_tool_allowed_for_tier( $tool_name, $tier ) {
    if ( in_array( $tier, [ 'pro', 'team', 'lifetime' ], true ) ) return true;
    return in_array( $tool_name, wpilot_mcp_free_tools(), true );
}
