<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPilot MCP Server — Security Blocklist
 * Prevents dangerous tools from being exposed via MCP.
 */

/**
 * Tools that are NEVER allowed via MCP.
 * These provide filesystem/shell/code access.
 */
function wpilot_mcp_blocked_tools() {
    return [
        // Filesystem access
        'read_file', 'write_file', 'edit_file', 'view_file', 'file_list', 'list_files',
        'edit_theme_file', 'delete_readme',
        // Code execution
        'run_command', 'wp_cli', 'add_php_snippet', 'add_javascript',
        'inject_html', 'add_htaccess_rule', 'update_htaccess',
        // Built by Weblease
        // Raw database danger
        'database_cleanup', 'optimize_database',
        // System internals
        'system_info', 'server_info', 'debug_log', 'view_debug_log',
        'error_log', 'read_log',
        // Scraping/external
        'scrape_url', 'scrape_design', 'fetch_url', 'http_request', 'api_call',
        'research_url', 'compare_website', 'compare_with_competitor',
        // Admin customization (confusing for MCP users)
        'admin_color_scheme', 'admin_theme', 'customize_admin',
        'customize_admin_bar', 'customize_dashboard', 'customize_login',
        'rebrand_admin', 'white_label_admin', 'simplify_admin',
        'simple_dashboard', 'hide_admin_menu',
        // Training/internal
        'export_training_data', 'training_stats', 'ai_self_test', 'test_ai',
        // Recovery/debug
        'recovery_status', 'reset_safe_mode', 'visual_debug',
        'safety_check',
        // Multi-action (could bypass security)
        'multi_action', 'action_chain', 'run_chain',
    ];
}

/**
 * Check if a tool is allowed via MCP.
 */
function wpilot_mcp_is_tool_allowed( $tool ) {
    return ! in_array( $tool, wpilot_mcp_blocked_tools(), true );
}

/**
 * Sanitize database query — only SELECT/SHOW/DESCRIBE allowed.
 */
function wpilot_mcp_sanitize_query( $query ) {
    $query = trim( $query );
    $upper = strtoupper( $query );

    $blocked_starts = ['DELETE ', 'DROP ', 'TRUNCATE ', 'ALTER ', 'CREATE ', 'INSERT ', 'UPDATE ', 'GRANT ', 'REVOKE '];
    foreach ( $blocked_starts as $keyword ) {
        if ( str_starts_with( $upper, $keyword ) ) {
            return false;
        }
    }

    $allowed_starts = ['SELECT ', 'SHOW ', 'DESCRIBE '];
    $is_allowed = false;
    foreach ( $allowed_starts as $keyword ) {
        if ( str_starts_with( $upper, $keyword ) ) {
            $is_allowed = true;
            break;
        }
    }

    return $is_allowed ? $query : false;
}
