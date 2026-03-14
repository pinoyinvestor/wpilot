<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Create DB table ────────────────────────────────────────────
function wpilot_backup_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ca_backups';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tool        VARCHAR(100)     NOT NULL,
        target_id   BIGINT UNSIGNED  DEFAULT NULL,
        target_type VARCHAR(60)      DEFAULT NULL,
        data_before LONGTEXT         DEFAULT NULL,
        params      LONGTEXT         DEFAULT NULL,
        created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        restored    TINYINT(1)       DEFAULT 0
    ) {$charset};" );
}

// ── Log every tool call ────────────────────────────────────────
function wpilot_backup_log( $tool, $params = [] ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'ca_backups', [
        'tool'       => $tool,
        'params'     => wp_json_encode( $params ),
        'created_at' => current_time( 'mysql' ),
    ] );
    return $wpdb->insert_id;
}

// ── Restore ────────────────────────────────────────────────────
function wpilot_restore( $backup_id ) {
    global $wpdb;
    $table  = $wpdb->prefix . 'ca_backups';
    $backup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $backup_id ) );

    if ( ! $backup )              return wpilot_err( 'Backup not found.' );
    if ( ! $backup->data_before ) return wpilot_err( 'No restorable data in this backup.' );

    $data = json_decode( $backup->data_before, true );

    switch ( $backup->target_type ) {
        case 'page':
        case 'post':
        case 'product':
            if ( ! empty( $data['post'] ) ) {
                wp_update_post( array_intersect_key( $data['post'],
                    array_flip( ['ID','post_title','post_content','post_excerpt','post_status'] ) ) );
            }
            if ( ! empty( $data['meta'] ) && $backup->target_id ) {
                foreach ( $data['meta'] as $key => $values ) {
                    delete_post_meta( $backup->target_id, $key );
                    foreach ( $values as $v ) {
                        add_post_meta( $backup->target_id, $key, maybe_unserialize( $v ) );
                    }
                }
            }
            break;

        case 'custom_css':
            wp_update_custom_css_post( $data['css'] ?? '' );
            break;

        case 'option':
            foreach ( $data as $k => $v ) update_option( $k, $v );
            break;
    }

    $wpdb->update( $table, ['restored' => 1], ['id' => $backup_id] );
    return wpilot_ok( "✅ Backup #{$backup_id} restored." );
}

// ── Get history ────────────────────────────────────────────────
function wpilot_backup_history( $limit = 40 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'ca_backups';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, tool, target_type, target_id, created_at, restored FROM {$table} ORDER BY created_at DESC LIMIT %d",
        $limit
    ) );
}
