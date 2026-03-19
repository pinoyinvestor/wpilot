<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  MULTI-KEY MCP — Multiple Claude connections with role-based access
//
//  Admin creates keys with different roles:
//  - "admin" key  → all 45 tools, full access
//  - "client" key → limited tools, safe operations only
//  - "viewer" key → read-only tools (site_info, pages list, etc)
//
//  Each key = one Claude Code connection with its own permissions.
// ═══════════════════════════════════════════════════════════════

// ── Key roles and their allowed tools ──────────────────────────
function wpilot_mcp_key_roles() {
    return [
        'admin' => [
            'label'       => 'Admin — Full Access',
            'description' => 'All tools. For the site developer/agency.',
            'tools'       => '*', // all tools
            'color'       => '#10B981',
        ],
        'client' => [
            'label'       => 'Client — Safe Tools',
            'description' => 'Content, pages, media, SEO. No plugin install, no code, no security.',
            'tools'       => [
                'site_info', 'pages', 'posts', 'media', 'css', 'comments',
                'seo', 'check_frontend', 'design', 'images', 'forms',
                'ai_content', 'translations', 'search_replace',
                'woo_products', 'woo_orders', 'woo_customers', 'woo_reports',
                'analytics', 'export', 'menus', 'widgets',
                'backup', 'undo',
            ],
            'color'       => '#6366F1',
        ],
        'viewer' => [
            'label'       => 'Viewer — Read Only',
            'description' => 'Can only view site info, pages, posts. Cannot modify anything.',
            'tools'       => [
                'site_info', 'check_frontend',
            ],
            'read_only_actions' => [
                'pages'    => ['list', 'read', 'search'],
                'posts'    => ['list', 'read'],
                'media'    => ['list'],
                'css'      => ['get'],
                'plugins'  => ['list'],
                'themes'   => ['list'],
                'users'    => ['list'],
                'comments' => ['list'],
                'seo'      => ['audit'],
                'security' => ['scan'],
                'backup'   => ['list'],
                'undo'     => ['list'],
            ],
            'color'       => '#F59E0B',
        ],
    ];
}

// Built by Weblease

// ── Store for multiple keys ────────────────────────────────────
// Stored as: wpilot_mcp_keys = [
//   { hash: "sha256...", label: "My admin key", role: "admin", created: "2026-...", last_used: null },
//   { hash: "sha256...", label: "Client key", role: "client", created: "2026-...", last_used: null },
// ]

function wpilot_mcp_keys_get() {
    return get_option( 'wpilot_mcp_keys', [] );
}

function wpilot_mcp_keys_save( $keys ) {
    update_option( 'wpilot_mcp_keys', $keys, false );
}

// ── Generate a new key with role ───────────────────────────────
function wpilot_mcp_generate_key_v2( $label, $role = 'admin', $custom_tools = null ) {
    $roles = wpilot_mcp_key_roles();
    if ( ! isset( $roles[ $role ] ) ) return false;

    $bytes = random_bytes( 36 );
    $key   = 'wpi_' . $role[0] . '_' . bin2hex( $bytes );  // wpi_a_ for admin, wpi_c_ for client, wpi_v_ for viewer
    $hash  = hash( 'sha256', $key );

    $keys = wpilot_mcp_keys_get();
    $keys[] = [
        'hash'         => $hash,
        'label'        => sanitize_text_field( $label ),
        'role'         => $role,
        'custom_tools' => $custom_tools, // null = use role defaults, array = override
        'created'      => date( 'Y-m-d H:i:s' ),
        'last_used'    => null,
        'requests'     => 0,
    ];
    wpilot_mcp_keys_save( $keys );

    // Also maintain backward compat with single-key system
    update_option( 'wpilot_mcp_api_key_hash', $hash );
    update_option( 'wpilot_mcp_key_created', date( 'Y-m-d H:i:s' ) );

    return $key;
}

// ── Validate a key and return its config ───────────────────────
function wpilot_mcp_validate_key_v2( $token ) {
    if ( empty( $token ) ) return false;

    $token_hash = hash( 'sha256', $token );
    $keys = wpilot_mcp_keys_get();

    foreach ( $keys as &$entry ) {
        if ( hash_equals( $entry['hash'], $token_hash ) ) {
            // Update last used
            $entry['last_used'] = date( 'Y-m-d H:i:s' );
            $entry['requests']  = ( $entry['requests'] ?? 0 ) + 1;
            wpilot_mcp_keys_save( $keys );

            return [
                'valid'        => true,
                'role'         => $entry['role'],
                'label'        => $entry['label'],
                'custom_tools' => $entry['custom_tools'] ?? null,
            ];
        }
    }

    return false;
}

// ── Revoke a key by hash ───────────────────────────────────────
function wpilot_mcp_revoke_key_v2( $hash ) {
    $keys = wpilot_mcp_keys_get();
    $keys = array_filter( $keys, fn( $k ) => $k['hash'] !== $hash );
    wpilot_mcp_keys_save( array_values( $keys ) );
}

// ── Check if a tool is allowed for a key's role ────────────────
function wpilot_mcp_tool_allowed_for_key( $tool_name, $action, $key_config ) {
    if ( ! $key_config || ! isset( $key_config['role'] ) ) return true; // no key info = allow (backward compat)

    $roles = wpilot_mcp_key_roles();
    $role  = $roles[ $key_config['role'] ] ?? null;
    if ( ! $role ) return false;

    // Admin = everything
    if ( $role['tools'] === '*' ) return true;

    // Custom tool override on the key itself
    $allowed_tools = $key_config['custom_tools'] ?? $role['tools'];

    // Check if tool is in the list
    if ( ! in_array( $tool_name, $allowed_tools, true ) ) return false;

    // Viewer role: also check action-level restrictions
    if ( $key_config['role'] === 'viewer' && isset( $role['read_only_actions'] ) ) {
        $allowed_actions = $role['read_only_actions'][ $tool_name ] ?? null;
        if ( $allowed_actions !== null && ! in_array( $action, $allowed_actions, true ) ) {
            return false;
        }
    }

    return true;
}

// ── Get custom instructions for a key's role ───────────────────
function wpilot_mcp_key_instructions( $key_config ) {
    $role = $key_config['role'] ?? 'admin';
    $base = get_option( 'ca_custom_instructions', '' );

    $role_instructions = [
        'client' => "\n\nIMPORTANT CLIENT RESTRICTIONS:\n- You are helping the site OWNER (not the developer)\n- Be extra careful with changes — explain what you'll do before doing it\n- Never install or remove plugins\n- Never modify theme files or PHP code\n- Never touch security settings\n- Focus on content, design, and products\n- Always ask before deleting anything",
        'viewer' => "\n\nIMPORTANT: READ-ONLY MODE\n- You can ONLY view and analyze — never modify anything\n- List pages, check SEO, review security — but do not change\n- If the user asks to change something, tell them they need a higher access level",
    ];

    return $base . ( $role_instructions[ $role ] ?? '' );
}

// ── Hook into MCP auth to use multi-key ────────────────────────
// This replaces the single-key validation in mcp_auth.php
add_filter( 'wpilot_mcp_auth_result', function( $result, $token ) {
    $key_config = wpilot_mcp_validate_key_v2( $token );
    if ( $key_config && $key_config['valid'] ) {
        // Store the key config in a global for tool filtering
        global $wpilot_current_key;
        $wpilot_current_key = $key_config;
        return true;
    }
    return $result;
}, 10, 2 );

// ── Hook into tools/list to filter by key role ─────────────────
add_filter( 'wpilot_mcp_tools_list', function( $tools ) {
    global $wpilot_current_key;
    if ( ! $wpilot_current_key ) return $tools;

    return array_filter( $tools, function( $tool ) {
        global $wpilot_current_key;
        return wpilot_mcp_tool_allowed_for_key( $tool['name'], '', $wpilot_current_key );
    } );
} );
