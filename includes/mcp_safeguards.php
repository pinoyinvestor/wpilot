<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPilot MCP Server — Safeguards
 * Rate limits on destructive operations, bulk protection,
 * full site snapshots before major operations.
 */

// ── Destructive operation rate limiting ──────────────────────

/**
 * Check if a destructive operation is allowed.
 * Max 5 destructive ops per 10 minutes per session.
 * Returns true if allowed, error array if blocked.
 */
function wpilot_mcp_check_destructive_rate( $tool ) {
    if ( ! function_exists( 'wpilot_mcp_destructive_tools' ) ) return true;
    if ( ! in_array( $tool, wpilot_mcp_destructive_tools(), true ) ) return true;

    $key   = 'wpilot_mcp_destruct_count';
    $count = (int) get_transient( $key );

    if ( $count >= 5 ) {
        return [
            'success' => false,
            'message' => 'Safety limit: max 5 destructive operations per 10 minutes. Wait a few minutes or use the undo tool to review recent changes before continuing.',
        ];
    }

    set_transient( $key, $count + 1, 600 ); // 10 min window
    return true;
}

// Built by Weblease

// ── Bulk operation protection ────────────────────────────────

/**
 * Bulk delete protection.
 * If more than 3 items are being deleted at once, block it.
 */
function wpilot_mcp_check_bulk_delete( $tool, $args ) {
    $bulk_tools = [ 'bulk_delete_posts', 'bulk_delete_spam' ];
    if ( ! in_array( $tool, $bulk_tools, true ) ) return true;

    $count = 0;
    if ( isset( $args['ids'] ) && is_array( $args['ids'] ) ) {
        $count = count( $args['ids'] );
    } elseif ( isset( $args['count'] ) ) {
        $count = (int) $args['count'];
    }

    if ( $count > 3 ) {
        return [
            'success' => false,
            'message' => "Safety limit: cannot delete more than 3 items at once via MCP. You requested {$count}. Delete them one at a time for safety.",
        ];
    }

    return true;
}

// ── Full site snapshot before major operations ───────────────

/**
 * Major operations that trigger a full site snapshot.
 */
function wpilot_mcp_major_operations() {
    return [
        'apply_blueprint', 'apply_design', 'reset_design',
        'build_site', 'generate_site', 'generate_full_site',
        'css_reset', 'switch_theme', 'activate_theme',
    ];
}

/**
 * Save a full site snapshot (pages + CSS + active plugins + theme).
 * Stored in wp_options with timestamp. Max 3 full snapshots.
 */
function wpilot_mcp_save_full_snapshot( $trigger_tool ) {
    $pages = get_pages( [ 'post_status' => 'any', 'number' => 50 ] );
    $page_data = array_map( function( $p ) {
        return [
            'id'      => $p->ID,
            'title'   => $p->post_title,
            'content' => $p->post_content,
            'status'  => $p->post_status,
            'type'    => $p->post_type,
        ];
    }, $pages );

    $snapshot = [
        'id'        => 'full_' . time() . '_' . wp_rand( 1000, 9999 ),
        'trigger'   => $trigger_tool,
        'timestamp' => current_time( 'mysql' ),
        'data'      => [
            'pages'          => $page_data,
            'css'            => wp_get_custom_css(),
            'active_plugins' => get_option( 'active_plugins', [] ),
            'theme'          => get_stylesheet(),
            'theme_mods'     => get_theme_mods(),
            'blogname'       => get_option( 'blogname' ),
            'blogdescription'=> get_option( 'blogdescription' ),
        ],
    ];

    $snapshots = get_option( 'wpilot_mcp_full_snapshots', [] );
    $snapshots[] = $snapshot;

    // Keep max 3 full snapshots (they're large)
    if ( count( $snapshots ) > 3 ) {
        $snapshots = array_slice( $snapshots, -3 );
    }

    update_option( 'wpilot_mcp_full_snapshots', $snapshots, false );
    return $snapshot['id'];
}

/**
 * Restore a full site snapshot.
 * Restores all pages, CSS, plugins, theme.
 */
function wpilot_mcp_restore_full_snapshot( $snapshot_id = null ) {
    $snapshots = get_option( 'wpilot_mcp_full_snapshots', [] );
    if ( empty( $snapshots ) ) {
        return [ 'success' => false, 'message' => 'No full snapshots available.' ];
    }

    if ( $snapshot_id ) {
        $snapshot = null;
        foreach ( $snapshots as $s ) {
            if ( $s['id'] === $snapshot_id ) { $snapshot = $s; break; }
        }
        if ( ! $snapshot ) return [ 'success' => false, 'message' => 'Snapshot not found.' ];
    } else {
        $snapshot = end( $snapshots );
    }

    $data = $snapshot['data'];
    $restored = [];

    // Restore pages
    if ( ! empty( $data['pages'] ) ) {
        foreach ( $data['pages'] as $page ) {
            $existing = get_post( $page['id'] );
            if ( $existing ) {
                wp_update_post( [
                    'ID'           => $page['id'],
                    'post_title'   => $page['title'],
                    'post_content' => $page['content'],
                    'post_status'  => $page['status'],
                ] );
            }
        }
        $restored[] = count( $data['pages'] ) . ' pages';
    }

    // Restore CSS
    if ( isset( $data['css'] ) ) {
        wp_update_custom_css_post( $data['css'] );
        $restored[] = 'CSS';
    }

    // Restore theme
    if ( ! empty( $data['theme'] ) && $data['theme'] !== get_stylesheet() ) {
        switch_theme( $data['theme'] );
        $restored[] = 'theme (' . $data['theme'] . ')';
    }

    // Restore theme mods
    if ( ! empty( $data['theme_mods'] ) ) {
        foreach ( $data['theme_mods'] as $k => $v ) set_theme_mod( $k, $v );
    }

    // Restore plugins
    if ( ! empty( $data['active_plugins'] ) ) {
        update_option( 'active_plugins', $data['active_plugins'] );
        $restored[] = 'plugins';
    }

    // Restore site title/description
    if ( ! empty( $data['blogname'] ) ) update_option( 'blogname', $data['blogname'] );
    if ( ! empty( $data['blogdescription'] ) ) update_option( 'blogdescription', $data['blogdescription'] );

    $summary = implode( ', ', $restored );
    return [
        'success' => true,
        'message' => "Full site restored from snapshot {$snapshot['id']} ({$snapshot['timestamp']}). Restored: {$summary}.",
    ];
}

/**
 * List available full snapshots.
 */
function wpilot_mcp_list_full_snapshots() {
    $snapshots = get_option( 'wpilot_mcp_full_snapshots', [] );
    return array_reverse( array_map( function( $s ) {
        return [
            'id'        => $s['id'],
            'trigger'   => $s['trigger'],
            'timestamp' => $s['timestamp'],
            'pages'     => count( $s['data']['pages'] ?? [] ),
            'css_size'  => strlen( $s['data']['css'] ?? '' ),
            'theme'     => $s['data']['theme'] ?? '?',
        ];
    }, $snapshots ) );
}
