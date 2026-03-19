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
//  WPILOT DEBUG TOOLS — Safe diagnostics for WordPress sites
//
//  Claude can use these to diagnose and fix site issues without
//  ever touching sensitive files or security configurations.
//  Read-only unless explicitly fixing a known issue.
// ═══════════════════════════════════════════════════════════════

// Built by Weblease

function wpilot_tool_debug( $params ) {
    $action = $params['action'] ?? '';

    switch ( $action ) {

        // ── Full site health check ─────────────────────────────
        case 'health':
            $health = [];

            // WordPress
            $health['wordpress'] = [
                'version'     => get_bloginfo( 'version' ),
                'multisite'   => is_multisite(),
                'debug_mode'  => defined( 'WP_DEBUG' ) && WP_DEBUG,
                'debug_log'   => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
                'memory_limit' => WP_MEMORY_LIMIT,
                'max_memory'   => WP_MAX_MEMORY_LIMIT,
                'cron_active'  => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            ];

            // PHP
            $health['php'] = [
                'version'        => PHP_VERSION,
                'memory_limit'   => ini_get( 'memory_limit' ),
                'max_execution'  => ini_get( 'max_execution_time' ),
                'upload_max'     => ini_get( 'upload_max_filesize' ),
                'post_max'       => ini_get( 'post_max_size' ),
                'extensions'     => array_values( array_intersect(
                    get_loaded_extensions(),
                    [ 'curl', 'gd', 'imagick', 'mbstring', 'xml', 'zip', 'intl', 'openssl', 'mysqli' ]
                )),
            ];

            // Database
            global $wpdb;
            $health['database'] = [
                'server'  => $wpdb->db_version(),
                'prefix'  => $wpdb->prefix,
                'charset' => $wpdb->charset,
                'tables'  => count( $wpdb->get_results( "SHOW TABLES", ARRAY_N ) ),
            ];

            // Disk
            $wp_dir = ABSPATH;
            $health['disk'] = [
                'wp_size'      => size_format( wpilot_dir_size( $wp_dir ), 1 ),
                'uploads_size' => size_format( wpilot_dir_size( wp_upload_dir()['basedir'] ), 1 ),
                'free_space'   => function_exists( 'disk_free_space' ) ? size_format( @disk_free_space( $wp_dir ), 1 ) : 'unknown',
            ];

            // Theme
            $theme = wp_get_theme();
            $health['theme'] = [
                'name'      => $theme->get( 'Name' ),
                'version'   => $theme->get( 'Version' ),
                'parent'    => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
                'is_block'  => $theme->is_block_theme(),
            ];

            // Active plugins
            $active = get_option( 'active_plugins', [] );
            $health['plugins'] = [
                'active_count' => count( $active ),
                'list'         => array_map( function( $p ) {
                    $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false );
                    return $data['Name'] . ' ' . $data['Version'];
                }, $active ),
            ];

            // SSL
            $health['ssl'] = is_ssl();

            // Permalink
            $health['permalink'] = get_option( 'permalink_structure' ) ?: 'plain';

            $score = 100;
            $issues = [];
            if ( ! $health['ssl'] ) { $score -= 15; $issues[] = 'No SSL — site not secure'; }
            if ( $health['php']['version'] < '8.0' ) { $score -= 10; $issues[] = 'PHP ' . $health['php']['version'] . ' is outdated'; }
            if ( $health['wordpress']['debug_mode'] && ! $health['wordpress']['debug_log'] ) { $score -= 5; $issues[] = 'WP_DEBUG on but WP_DEBUG_LOG off — errors shown to visitors'; }
            if ( $health['permalink'] === 'plain' ) { $score -= 5; $issues[] = 'Plain permalinks — bad for SEO'; }

            return wpilot_ok( "Site health: {$score}/100. " . ( empty( $issues ) ? 'All good!' : count( $issues ) . ' issues found.' ), [
                'score'  => $score,
                'issues' => $issues,
                'health' => $health,
            ] );

        // ── PHP error log ──────────────────────────────────────
        case 'errors':
            $lines = intval( $params['lines'] ?? 30 );
            $lines = min( $lines, 100 ); // max 100 lines

            $log_file = WP_CONTENT_DIR . '/debug.log';
            if ( ! file_exists( $log_file ) ) {
                $log_file = ini_get( 'error_log' );
            }
            if ( ! $log_file || ! file_exists( $log_file ) ) {
                return wpilot_ok( 'No error log found. WP_DEBUG may be disabled.', [ 'debug_mode' => defined( 'WP_DEBUG' ) && WP_DEBUG ] );
            }

            $size = filesize( $log_file );
            $content = '';
            if ( $size > 0 ) {
                $fp = fopen( $log_file, 'r' );
                // Read last N lines efficiently
                $offset = max( 0, $size - ( $lines * 200 ) );
                fseek( $fp, $offset );
                $content = fread( $fp, $size - $offset );
                fclose( $fp );
                $all_lines = explode( "\n", trim( $content ) );
                $content = implode( "\n", array_slice( $all_lines, -$lines ) );
            }

            // Parse errors into structured format
            $parsed = [];
            $fatal_count = 0;
            $warning_count = 0;
            foreach ( explode( "\n", $content ) as $line ) {
                if ( stripos( $line, 'Fatal' ) !== false ) $fatal_count++;
                if ( stripos( $line, 'Warning' ) !== false ) $warning_count++;
            }

            return wpilot_ok( "Error log: " . size_format( $size ) . ". {$fatal_count} fatal, {$warning_count} warnings in last {$lines} lines.", [
                'log_size'   => size_format( $size ),
                'fatals'     => $fatal_count,
                'warnings'   => $warning_count,
                'last_lines' => $content,
            ] );

        // ── Clear error log ────────────────────────────────────
        case 'clear_errors':
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if ( file_exists( $log_file ) ) {
                file_put_contents( $log_file, '' );
                return wpilot_ok( 'Error log cleared.' );
            }
            return wpilot_ok( 'No error log to clear.' );

        // ── Check specific page for issues ─────────────────────
        case 'check_page':
            $url = $params['url'] ?? get_site_url();
            $response = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => false ] );
            if ( is_wp_error( $response ) ) {
                return wpilot_err( 'Cannot reach ' . $url . ': ' . $response->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $headers = wp_remote_retrieve_headers( $response );

            $issues = [];
            if ( $code !== 200 ) $issues[] = "HTTP {$code} — page not loading correctly";
            if ( stripos( $body, 'Fatal error' ) !== false ) $issues[] = 'PHP Fatal Error on page';
            if ( stripos( $body, 'Parse error' ) !== false ) $issues[] = 'PHP Parse Error on page';
            if ( stripos( $body, 'Warning:' ) !== false && stripos( $body, 'wp-includes' ) !== false ) $issues[] = 'PHP Warning visible on page';
            if ( stripos( $body, 'critical error' ) !== false ) $issues[] = 'WordPress critical error detected';
            if ( stripos( $body, 'database error' ) !== false ) $issues[] = 'Database error on page';
            if ( empty( $body ) ) $issues[] = 'Page is completely blank (white screen of death)';
            if ( strlen( $body ) < 500 ) $issues[] = 'Page content suspiciously small (' . strlen( $body ) . ' bytes)';

            // Check response time
            $time = wp_remote_retrieve_header( $response, 'x-runtime' );

            return wpilot_ok( "Page check: HTTP {$code}, " . strlen( $body ) . " bytes, " . count( $issues ) . " issues.", [
                'url'        => $url,
                'status'     => $code,
                'size'       => strlen( $body ),
                'issues'     => $issues,
                'has_errors' => ! empty( $issues ),
            ] );

        // ── WordPress constants & config (safe info only) ──────
        case 'config':
            return wpilot_ok( 'WordPress configuration (safe values only).', [
                'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
                'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
                'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
                'WP_MEMORY_LIMIT'  => WP_MEMORY_LIMIT,
                'WP_MAX_MEMORY_LIMIT' => WP_MAX_MEMORY_LIMIT,
                'ABSPATH'          => ABSPATH,
                'WP_CONTENT_DIR'   => WP_CONTENT_DIR,
                'UPLOADS'          => defined( 'UPLOADS' ) ? UPLOADS : 'wp-content/uploads',
                'DISABLE_WP_CRON'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                'FORCE_SSL_ADMIN'  => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
                'WP_POST_REVISIONS' => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true,
                'AUTOSAVE_INTERVAL' => defined( 'AUTOSAVE_INTERVAL' ) ? AUTOSAVE_INTERVAL : 60,
                'php_version'      => PHP_VERSION,
                'server_software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                'https'            => is_ssl(),
            ] );

        // ── Transient/cache check ──────────────────────────────
        case 'cache':
            global $wpdb;
            $transients = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
            $autoload = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'" );
            $object_cache = wp_using_ext_object_cache();

            return wpilot_ok( 'Cache status.', [
                'transients'      => intval( $transients ),
                'autoload_size'   => size_format( intval( $autoload ) ),
                'object_cache'    => $object_cache ? 'external (Redis/Memcached)' : 'database',
                'page_cache'      => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'active' : 'none',
            ] );

        // ── Fix common issues ──────────────────────────────────
        case 'fix':
            $what = $params['issue'] ?? '';
            $fixed = [];

            if ( $what === 'permalinks' || $what === 'all' ) {
                flush_rewrite_rules();
                $fixed[] = 'Permalinks flushed';
            }
            if ( $what === 'transients' || $what === 'all' ) {
                global $wpdb;
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
                $fixed[] = 'Expired transients cleared';
            }
            if ( $what === 'cache' || $what === 'all' ) {
                wp_cache_flush();
                $fixed[] = 'Object cache flushed';
            }

            if ( empty( $fixed ) ) {
                return wpilot_err( "Unknown issue '{$what}'. Available: permalinks, transients, cache, all" );
            }
            return wpilot_ok( 'Fixed: ' . implode( ', ', $fixed ) . '.', [ 'fixed' => $fixed ] );

        default:
            return wpilot_err( "Unknown debug action '{$action}'. Available: health, errors, clear_errors, check_page, config, cache, fix" );
    }
}

// ── Helper: directory size ─────────────────────────────────────
function wpilot_dir_size( $dir ) {
    $size = 0;
    if ( ! is_dir( $dir ) ) return 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ( $files as $file ) {
        if ( $file->isFile() ) $size += $file->getSize();
    }
    return $size;
}
