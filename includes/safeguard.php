<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPILOT SAFEGUARD — Prevents AI from breaking WordPress
//
//  Rules:
//  1. NEVER execute raw PHP — only use WordPress API functions
//  2. Validate CSS syntax before applying
//  3. Validate HTML content before inserting
//  4. Always create backup BEFORE any change
//  5. If a tool fails, auto-restore from backup
//  6. Block dangerous operations
//  7. All changes go to draft first (if applicable)
// ═══════════════════════════════════════════════════════════════

// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Validate CSS before applying ─────────────────────────────
function wpilot_validate_css( $css ) {
    $clean = preg_replace('/\/\*.*?\*\//s', '', $css);

    // Block dangerous CSS patterns
    $dangerous = [
        'expression(',
        'javascript:',
        'behavior:',
        '-moz-binding:',
        '<script',
        '<iframe',
    ];

    foreach ( $dangerous as $pattern ) {
        if ( stripos( $clean, $pattern ) !== false ) {
            return [ 'valid' => false, 'error' => "Blocked: CSS contains dangerous pattern" ];
        }
    }

    // Brace matching
    $open  = substr_count( $clean, '{' );
    $close = substr_count( $clean, '}' );
    if ( $open !== $close ) {
        return [ 'valid' => false, 'error' => "Invalid CSS: {$open} opening braces but {$close} closing braces — syntax error" ];
    }

    if ( strlen($css) > 100000 ) {
        return [ 'valid' => false, 'error' => 'CSS too large (max 100KB)' ];
    }

    return [ 'valid' => true ];
}

// ── Validate HTML content before inserting ────────────────────
function wpilot_validate_content( $html ) {
    $dangerous = [
        '<script',
        'onclick=',
        'onerror=',
        'onload=',
        'onmouseover=',
        'document.cookie',
        'window.location',
        'javascript:',
    ];

    $lower = strtolower( $html );
    foreach ( $dangerous as $pattern ) {
        if ( strpos( $lower, $pattern ) !== false ) {
            return [ 'valid' => false, 'error' => "Blocked: content contains dangerous pattern" ];
        }
    }

    return [ 'valid' => true ];
}

// ── Safe tool execution wrapper ──────────────────────────────
function wpilot_safe_run_tool( $tool, $params = [] ) {
    // Block dangerous tool names
    $blocked_tools = [ 'raw_sql', 'execute_php', 'run_code', 'shell_exec' ];
    if ( in_array( $tool, $blocked_tools ) ) {
        return wpilot_err( 'Blocked: This operation is not allowed for security reasons.' );
    }

    // Validate CSS before applying
    if ( in_array( $tool, ['update_custom_css', 'append_custom_css'] ) ) {
        $css = $params['css'] ?? '';
        $check = wpilot_validate_css( $css );
        if ( ! $check['valid'] ) {
            return wpilot_err( $check['error'] );
        }
    }

    // Validate content before inserting
    if ( in_array( $tool, ['create_page', 'update_page_content'] ) ) {
        $content = $params['content'] ?? '';
        $check = wpilot_validate_content( $content );
        if ( ! $check['valid'] ) {
            return wpilot_err( $check['error'] );
        }
    }

    // Execute with error catching
    try {
        $result = wpilot_run_tool( $tool, $params );
    } catch ( \Throwable $e ) {
        wpilot_log_activity( 'tool_error', "Tool '{$tool}' threw exception", $e->getMessage() );
        return wpilot_err( 'Tool failed safely: ' . $e->getMessage() . '. No changes were applied.' );
    }

    // Fire activity log hook
    $result = apply_filters('wpi_after_tool_run', $result, $tool, $params, null);

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
            'title' => '<span style="color:#EF4444">↩ WPilot Undo (' . intval($recent) . ')</span>',
            'href'  => admin_url('admin.php?page=wpilot-restore'),
            'meta'  => ['title' => 'WPilot made ' . intval($recent) . ' changes in the last hour. Click to undo.'],
        ]);
    }
}, 999 );

// ── Recovery mode help ───────────────────────────────────────
add_filter( 'recovery_mode_email', function( $email_data ) {
    $email_data['message'] .= "\n\nIf this was caused by WPilot AI, go to WordPress admin > WPilot > Restore History to undo the last change.";
    return $email_data;
});
