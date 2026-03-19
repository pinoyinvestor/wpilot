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
 * WPilot MCP Server — Audit Log
 * Logs every MCP tool call to a custom database table.
 */

/**
 * Create audit log table on plugin activation.
 */
function wpilot_mcp_create_audit_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_mcp_audit';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tool VARCHAR(100) NOT NULL,
        action VARCHAR(50) DEFAULT NULL,
        args TEXT DEFAULT NULL,
        result_summary VARCHAR(500) DEFAULT NULL,
        is_error TINYINT(1) DEFAULT 0,
        duration_ms INT UNSIGNED DEFAULT 0,
        ip VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tool (tool),
        INDEX idx_created (created_at)
    ) {$charset};";

    // Built by Weblease

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Log a tool call to the audit table.
 */
function wpilot_mcp_audit_log( $tool, $action, $args, $result_summary, $is_error, $duration_ms ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_mcp_audit';

    $safe_args = is_array( $args ) ? wp_json_encode( array_map( function( $v ) {
        if ( is_string( $v ) && strlen( $v ) > 200 ) return substr( $v, 0, 200 ) . '...';
        return $v;
    }, $args ) ) : '';

    $wpdb->insert( $table, [
        'tool'           => substr( $tool, 0, 100 ),
        'action'         => $action ? substr( $action, 0, 50 ) : null,
        'args'           => substr( $safe_args, 0, 65000 ),
        'result_summary' => substr( (string) $result_summary, 0, 500 ),
        'is_error'       => $is_error ? 1 : 0,
        'duration_ms'    => (int) $duration_ms,
        'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
        'created_at'     => current_time( 'mysql' ),
    ] );

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $count > 2000 ) {
        $cutoff = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET 2000" );
        if ( $cutoff ) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $cutoff ) );
    }
}

/**
 * Get recent audit entries for admin display.
 */
function wpilot_mcp_audit_recent( $limit = 50, $offset = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_mcp_audit';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
        $limit, $offset
    ) );
}

/**
 * Get audit stats for dashboard.
 */
function wpilot_mcp_audit_stats() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_mcp_audit';

    return [
        'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
        'today'      => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s",
            current_time( 'Y-m-d' )
        ) ),
        'errors'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_error = 1" ),
        'top_tools'  => $wpdb->get_results(
            "SELECT tool, COUNT(*) as cnt FROM {$table} GROUP BY tool ORDER BY cnt DESC LIMIT 10"
        ),
    ];
}
