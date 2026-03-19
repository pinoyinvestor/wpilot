<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPilot MCP Server — Undo System
 * Saves snapshots before destructive operations so they can be reversed.
 */

/**
 * Tools that need a snapshot before execution.
 */
function wpilot_mcp_destructive_tools() {
    return [
        'update_page_content', 'replace_in_page', 'delete',
        'bulk_delete_posts', 'append_page_content',
        'update_custom_css', 'append_custom_css', 'css_reset', 'remove_css',
        'deactivate_plugin', 'delete_plugin',
        'activate_theme', 'switch_theme',
        'woo_update_product', 'delete_coupon', 'woo_bulk_update_prices',
        'woo_remove_sale', 'refund_order', 'woo_refund_order',
        'delete_user', 'change_user_role',
        'delete_menu', 'remove_menu_item',
        'delete_media',
        'update_option',
        'apply_blueprint', 'apply_design', 'reset_design',
    ];
}

// Built by Weblease

/**
 * Save snapshot before a destructive operation.
 */
function wpilot_mcp_save_snapshot( $tool, $args ) {
    $snapshot = [
        'tool'      => $tool,
        'args'      => $args,
        'timestamp' => current_time( 'mysql' ),
        'data'      => [],
    ];

    if ( in_array( $tool, ['update_page_content', 'replace_in_page', 'append_page_content', 'delete'] ) ) {
        $id = $args['id'] ?? $args['page_id'] ?? $args['post_id'] ?? 0;
        if ( $id ) {
            $post = get_post( $id );
            if ( $post ) {
                $snapshot['data'] = [
                    'type'    => 'post',
                    'post_id' => $id,
                    'title'   => $post->post_title,
                    'content' => $post->post_content,
                    'status'  => $post->post_status,
                ];
            }
        }
    } elseif ( in_array( $tool, ['update_custom_css', 'append_custom_css', 'css_reset', 'remove_css'] ) ) {
        $snapshot['data'] = [
            'type' => 'css',
            'css'  => wp_get_custom_css(),
        ];
    } elseif ( in_array( $tool, ['deactivate_plugin', 'delete_plugin'] ) ) {
        $snapshot['data'] = [
            'type'    => 'plugin',
            'active'  => get_option( 'active_plugins', [] ),
            'slug'    => $args['slug'] ?? $args['plugin'] ?? '',
        ];
    } elseif ( $tool === 'update_option' ) {
        $key = $args['option'] ?? $args['key'] ?? '';
        if ( $key ) {
            $snapshot['data'] = [
                'type'  => 'option',
                'key'   => $key,
                'value' => get_option( $key ),
            ];
        }
    } elseif ( in_array( $tool, ['activate_theme', 'switch_theme'] ) ) {
        $snapshot['data'] = [
            'type'  => 'theme',
            'theme' => get_stylesheet(),
            'mods'  => get_theme_mods(),
        ];
    } elseif ( in_array( $tool, ['delete_menu', 'remove_menu_item'] ) ) {
        $menu_id = $args['menu_id'] ?? $args['id'] ?? 0;
        $snapshot['data'] = [
            'type'    => 'menu',
            'menu_id' => $menu_id,
        ];
    } elseif ( in_array( $tool, ['woo_update_product', 'delete_coupon', 'woo_bulk_update_prices', 'woo_remove_sale'] ) ) {
        $id = $args['id'] ?? $args['product_id'] ?? $args['coupon_id'] ?? 0;
        if ( $id ) {
            $post = get_post( $id );
            if ( $post ) {
                $snapshot['data'] = [
                    'type'    => 'post',
                    'post_id' => $id,
                    'title'   => $post->post_title,
                    'content' => $post->post_content,
                    'status'  => $post->post_status,
                ];
            }
        }
    }

    $snapshots = get_option( 'wpilot_mcp_snapshots', [] );
    $snapshot['id'] = time() . '_' . wp_rand( 1000, 9999 );
    $snapshots[] = $snapshot;

    if ( count( $snapshots ) > 50 ) {
        $snapshots = array_slice( $snapshots, -50 );
    }

    update_option( 'wpilot_mcp_snapshots', $snapshots );
    return $snapshot['id'];
}

/**
 * Undo the last operation or a specific snapshot.
 */
function wpilot_mcp_undo( $snapshot_id = null ) {
    $snapshots = get_option( 'wpilot_mcp_snapshots', [] );
    if ( empty( $snapshots ) ) return [ 'success' => false, 'message' => 'Nothing to undo.' ];

    if ( $snapshot_id ) {
        $snapshot = null;
        foreach ( $snapshots as $i => $s ) {
            if ( $s['id'] == $snapshot_id ) {
                $snapshot = $s;
                array_splice( $snapshots, $i, 1 );
                break;
            }
        }
        if ( ! $snapshot ) return [ 'success' => false, 'message' => 'Snapshot not found.' ];
    } else {
        $snapshot = array_pop( $snapshots );
    }

    update_option( 'wpilot_mcp_snapshots', $snapshots );

    $data = $snapshot['data'] ?? [];
    $type = $data['type'] ?? '';

    switch ( $type ) {
        case 'post':
            $post_id = $data['post_id'];
            $existing = get_post( $post_id );
            if ( ! $existing ) {
                wp_insert_post( [
                    'import_id'    => $post_id,
                    'post_title'   => $data['title'],
                    'post_content' => $data['content'],
                    'post_status'  => $data['status'],
                    'post_type'    => 'page',
                ] );
            } else {
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_title'   => $data['title'],
                    'post_content' => $data['content'],
                    'post_status'  => $data['status'],
                ] );
            }
            return [ 'success' => true, 'message' => "Restored \"{$data['title']}\" (ID: {$post_id})." ];

        case 'css':
            wp_update_custom_css_post( $data['css'] );
            return [ 'success' => true, 'message' => 'CSS restored.' ];

        case 'plugin':
            update_option( 'active_plugins', $data['active'] );
            return [ 'success' => true, 'message' => "Plugin state restored." ];

        case 'option':
            update_option( $data['key'], $data['value'] );
            return [ 'success' => true, 'message' => "Option '{$data['key']}' restored." ];

        case 'theme':
            switch_theme( $data['theme'] );
            if ( ! empty( $data['mods'] ) ) {
                foreach ( $data['mods'] as $k => $v ) set_theme_mod( $k, $v );
            }
            return [ 'success' => true, 'message' => "Theme restored to '{$data['theme']}'." ];

        default:
            return [ 'success' => false, 'message' => 'Unknown snapshot type.' ];
    }
}

/**
 * List available undo snapshots.
 */
function wpilot_mcp_undo_list() {
    $snapshots = get_option( 'wpilot_mcp_snapshots', [] );
    return array_reverse( array_map( function( $s ) {
        $data = $s['data'] ?? [];
        $type = $data['type'] ?? 'unknown';
        $summary = match( $type ) {
            'post'   => "Page: \"{$data['title']}\" (ID: {$data['post_id']})",
            'css'    => 'Custom CSS (' . strlen( $data['css'] ?? '' ) . ' chars)',
            'plugin' => "Plugin: {$data['slug']}",
            'option' => "Option: {$data['key']}",
            'theme'  => "Theme: {$data['theme']}",
            default  => $s['tool'],
        };
        return [
            'id'        => $s['id'],
            'tool'      => $s['tool'],
            'type'      => $type,
            'timestamp' => $s['timestamp'],
            'summary'   => $summary,
        ];
    }, $snapshots ) );
}
