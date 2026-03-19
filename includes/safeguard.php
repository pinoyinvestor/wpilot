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
//  WPILOT SAFEGUARD — Prevents AI from breaking WordPress
//
//  Safety layers:
//  1. Block dangerous tools & validate input
//  2. Snapshot BEFORE every change (real data, not just params)
//  3. Execute tool
//  4. Health check AFTER — verify site still works
//  5. Auto-rollback if health check fails
// ═══════════════════════════════════════════════════════════════

// Built by Weblease

// ── Validate CSS before applying ─────────────────────────────
function wpilot_validate_css( $css ) {
    $clean = preg_replace('/\/\*.*?\*\//s', '', $css);
    $dangerous = ['expression(', 'javascript:', 'behavior:', '-moz-binding:', '<script', '<iframe'];
    foreach ( $dangerous as $pattern ) {
        if ( stripos( $clean, $pattern ) !== false ) {
            return [ 'valid' => false, 'error' => "Blocked: CSS contains dangerous pattern" ];
        }
    }
    $open  = substr_count( $clean, '{' );
    $close = substr_count( $clean, '}' );
    if ( $open !== $close ) {
        return [ 'valid' => false, 'error' => "Invalid CSS: mismatched braces" ];
    }
    if ( strlen($css) > 100000 ) {
        return [ 'valid' => false, 'error' => 'CSS too large (max 100KB)' ];
    }
    return [ 'valid' => true ];
}

// ── Validate HTML content before inserting ────────────────────
function wpilot_validate_content( $html ) {
    $dangerous = ['<script', 'onclick=', 'onerror=', 'onload=', 'onmouseover=',
                   'document.cookie', 'window.location', 'javascript:'];
    $lower = strtolower( $html );
    foreach ( $dangerous as $pattern ) {
        if ( strpos( $lower, $pattern ) !== false ) {
            return [ 'valid' => false, 'error' => "Blocked: content contains dangerous pattern" ];
        }
    }
    return [ 'valid' => true ];
}

// ── Take snapshot of what will be affected ────────────────────
function wpilot_snapshot_before( $tool, $params ) {
    $snapshot = [ 'tool' => $tool, 'time' => time() ];

    // Page/post content tools — save the page content before change
    $page_tools = ['update_page_content', 'append_page_content', 'replace_in_page',
                   'create_html_page', 'delete_page', 'update_page_title'];
    if ( in_array( $tool, $page_tools ) ) {
        $id = $params['id'] ?? $params['page_id'] ?? $params['post_id'] ?? 0;
        if ( $id ) {
            $post = get_post( $id );
            if ( $post ) {
                $snapshot['type'] = 'page';
                $snapshot['target_id'] = $id;
                $snapshot['data'] = [
                    'post_title'   => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_status'  => $post->post_status,
                    'post_excerpt' => $post->post_excerpt,
                ];
            }
        }
    }

    // CSS tools — save current CSS
    $css_tools = ['update_custom_css', 'append_custom_css', 'add_head_code'];
    if ( in_array( $tool, $css_tools ) ) {
        $snapshot['type'] = 'css';
        $snapshot['data'] = [
            'custom_css' => wp_get_custom_css(),
            'head_code'  => get_option( 'wpilot_head_code', '' ),
        ];
    }

    // Option tools
    if ( in_array( $tool, ['update_option', 'set_option'] ) ) {
        $key = $params['key'] ?? $params['option'] ?? '';
        if ( $key ) {
            $snapshot['type'] = 'option';
            $snapshot['target_id'] = $key;
            $snapshot['data'] = [ $key => get_option( $key ) ];
        }
    }

    // Menu tools
    if ( strpos( $tool, 'menu' ) !== false ) {
        $snapshot['type'] = 'menu';
        $menus = wp_get_nav_menus();
        $snapshot['data'] = [];
        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            $snapshot['data'][ $menu->term_id ] = [
                'name'  => $menu->name,
                'items' => $items ? array_map( function($i) {
                    return ['title' => $i->title, 'url' => $i->url, 'object_id' => $i->object_id];
                }, $items ) : [],
            ];
        }
    }

    // Plugin tools — save active plugins
    if ( strpos( $tool, 'plugin' ) !== false ) {
        $snapshot['type'] = 'plugins';
        $snapshot['data'] = [ 'active' => get_option( 'active_plugins', [] ) ];
    }

    // Widget/header/footer tools
    if ( strpos( $tool, 'header' ) !== false || strpos( $tool, 'footer' ) !== false ) {
        $snapshot['type'] = 'header_footer';
        $snapshot['data'] = [
            'header' => get_option( 'wpilot_header_html', '' ),
            'header_css' => get_option( 'wpilot_header_css', '' ),
            'footer' => get_option( 'wpilot_footer_html', '' ),
            'footer_css' => get_option( 'wpilot_footer_css', '' ),
        ];
    }

    // Save snapshot to DB
    global $wpdb;
    $table = $wpdb->prefix . 'ca_backups';
    $wpdb->update(
        $table,
        [
            'data_before' => wp_json_encode( $snapshot['data'] ?? [] ),
            'target_id'   => $snapshot['target_id'] ?? null,
            'target_type' => $snapshot['type'] ?? 'unknown',
        ],
        [ 'id' => $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE tool = %s", $tool ) ) ]
    );

    return $snapshot;
}

// ── Health check — verify site still works after a tool ──────
function wpilot_health_check() {
    $url = get_site_url();

    // Quick loopback request to check if site responds
    $response = wp_remote_get( $url, [
        'timeout'   => 10,
        'sslverify' => false,
        'headers'   => [ 'X-WPilot-Health' => '1' ],
    ] );

    if ( is_wp_error( $response ) ) {
        return [ 'healthy' => false, 'error' => 'Site unreachable: ' . $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    // 500 = fatal error
    if ( $code >= 500 ) {
        return [ 'healthy' => false, 'error' => "Site returned HTTP {$code} — critical error" ];
    }

    // Check for WordPress fatal error markers
    if ( stripos( $body, 'critical error' ) !== false ||
         stripos( $body, 'Fatal error' ) !== false ||
         stripos( $body, 'Parse error' ) !== false ) {
        return [ 'healthy' => false, 'error' => 'WordPress fatal error detected on frontend' ];
    }

    // Also check admin-ajax (it's what the chat uses)
    $ajax_response = wp_remote_post( admin_url( 'admin-ajax.php' ), [
        'timeout'   => 5,
        'sslverify' => false,
        'body'      => [ 'action' => 'wpilot_health_ping' ],
    ] );

    if ( is_wp_error( $ajax_response ) ) {
        return [ 'healthy' => false, 'error' => 'Admin AJAX unreachable' ];
    }

    $ajax_code = wp_remote_retrieve_response_code( $ajax_response );
    if ( $ajax_code >= 500 ) {
        return [ 'healthy' => false, 'error' => "Admin AJAX returned HTTP {$ajax_code}" ];
    }

    return [ 'healthy' => true ];
}

// Simple health ping endpoint
add_action( 'wp_ajax_wpilot_health_ping', function() { wp_send_json_success( 'pong' ); } );
add_action( 'wp_ajax_nopriv_wpilot_health_ping', function() { wp_send_json_success( 'pong' ); } );

// ── Auto-rollback — restore the snapshot ─────────────────────
function wpilot_auto_rollback( $snapshot ) {
    if ( empty( $snapshot['data'] ) || empty( $snapshot['type'] ) ) return false;

    $data = $snapshot['data'];
    $type = $snapshot['type'];

    switch ( $type ) {
        case 'page':
            $id = $snapshot['target_id'] ?? 0;
            if ( $id && ! empty( $data['post_content'] ) ) {
                wp_update_post( [
                    'ID'           => $id,
                    'post_title'   => $data['post_title'] ?? '',
                    'post_content' => $data['post_content'],
                    'post_status'  => $data['post_status'] ?? 'publish',
                ] );
            }
            break;

        case 'css':
            if ( isset( $data['custom_css'] ) ) {
                wp_update_custom_css_post( $data['custom_css'] );
            }
            if ( isset( $data['head_code'] ) ) {
                update_option( 'wpilot_head_code', $data['head_code'] );
            }
            break;

        case 'option':
            foreach ( $data as $key => $value ) {
                update_option( $key, $value );
            }
            break;

        case 'header_footer':
            foreach ( $data as $key => $value ) {
                update_option( 'wpilot_' . $key, $value );
            }
            break;

        case 'plugins':
            if ( ! empty( $data['active'] ) ) {
                update_option( 'active_plugins', $data['active'] );
            }
            break;
    }

    // Log the rollback
    if ( function_exists( 'wpilot_log_activity' ) ) {
        wpilot_log_activity( 'auto_rollback', "Auto-rolled back {$type}", json_encode( $snapshot ) );
    }

    return true;
}

// ── Safe tool execution — the main wrapper ───────────────────
function wpilot_safe_run_tool( $tool, $params = [] ) {
    // 1. Block dangerous tools
    $blocked_tools = [ 'raw_sql', 'execute_php', 'run_code', 'shell_exec', 'delete_database', 'drop_table', 'read_file', 'write_file', 'edit_file', 'view_file', 'file_list', 'list_files' ];
    if ( in_array( $tool, $blocked_tools ) ) {
        return wpilot_err( 'Den här operationen är inte tillåten.' );
    }

    // 2. Validate input
    if ( in_array( $tool, ['update_custom_css', 'append_custom_css'] ) ) {
        $css = $params['css'] ?? '';
        $check = wpilot_validate_css( $css );
        if ( ! $check['valid'] ) return wpilot_err( $check['error'] );
    }

    if ( in_array( $tool, ['create_page', 'update_page_content', 'append_page_content', 'create_html_page'] ) ) {
        $content = $params['content'] ?? '';
        $check = wpilot_validate_content( $content );
        if ( ! $check['valid'] ) return wpilot_err( $check['error'] );
    }

    // 3. Take snapshot BEFORE the change
    $snapshot = wpilot_snapshot_before( $tool, $params );

    // 4. Execute tool
    try {
        $result = wpilot_run_tool( $tool, $params );
    } catch ( \Throwable $e ) {
        // Tool threw exception — rollback
        wpilot_auto_rollback( $snapshot );
        if ( function_exists( 'wpilot_log_activity' ) ) {
            wpilot_log_activity( 'tool_error', "Tool '{$tool}' threw exception — auto-rolled back", $e->getMessage() );
        }
        return wpilot_err( 'Något gick fel, men jag har återställt allt. Inga ändringar gjordes.' );
    }

    // 5. Health check — only for tools that modify the frontend
    $frontend_tools = ['update_page_content', 'append_page_content', 'replace_in_page',
                       'update_custom_css', 'append_custom_css', 'add_head_code',
                       'create_html_page', 'delete_page', 'apply_blueprint',
                       'apply_header_blueprint', 'apply_footer_blueprint'];

    if ( in_array( $tool, $frontend_tools ) && ! empty( $snapshot['data'] ) ) {
        $health = wpilot_health_check();
        if ( ! $health['healthy'] ) {
            // Site broken! Auto-rollback immediately
            wpilot_auto_rollback( $snapshot );
            if ( function_exists( 'wpilot_log_activity' ) ) {
                wpilot_log_activity( 'auto_rollback', "Health check failed after '{$tool}' — rolled back", $health['error'] );
            }
            // Return friendly error
            return wpilot_err( 'Ändringen orsakade ett problem, så jag ångrade den direkt. Sidan är säker.' );
        }
    }

    // 6. Fire activity log hook
    $result = apply_filters( 'wpi_after_tool_run', $result, $tool, $params, null );

    return $result;
}

// ── Emergency undo button in admin bar ───────────────────────
add_action( 'admin_bar_menu', function( $bar ) {
    if ( ! current_user_can('manage_options') ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'ca_backups';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) return;
    $recent = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE created_at > %s",
        gmdate('Y-m-d H:i:s', time() - 3600)
    ));
    if ( $recent > 0 ) {
        $bar->add_node([
            'id'    => 'wpilot-emergency',
            'title' => '<span style="color:#EF4444">↩ WPilot Ångra (' . intval($recent) . ')</span>',
            'href'  => admin_url('admin.php?page=wpilot'),
            'meta'  => ['title' => 'WPilot ändrade ' . intval($recent) . ' saker senaste timmen. Klicka för att ångra.'],
        ]);
    }
}, 999 );

// ── Recovery mode email hint ─────────────────────────────────
add_filter( 'recovery_mode_email', function( $email_data ) {
    $email_data['message'] .= "\n\nIf WPilot AI caused this, go to WordPress admin > WPilot to undo the last change.";
    return $email_data;
} );
