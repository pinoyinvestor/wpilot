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

// Built by Weblease

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

        case 'image':
            // Restore original image from WebP conversion
            $original_path = $data['original_path'] ?? '';
            $backup_path   = $data['backup_path']   ?? '';
            $original_mime = $data['original_mime']  ?? 'image/jpeg';
            $att_id        = $backup->target_id;

            if ( $att_id && $backup_path && file_exists( $backup_path ) ) {
                // Remove the WebP file
                $current_file = get_attached_file( $att_id );
                if ( $current_file && file_exists( $current_file ) && $current_file !== $original_path ) {
                    // Remove WebP thumbnails
                    $meta = wp_get_attachment_metadata( $att_id );
                    if ( ! empty( $meta['sizes'] ) ) {
                        $dir = dirname( $current_file );
                        foreach ( $meta['sizes'] as $size ) {
                            $thumb = $dir . '/' . $size['file'];
                            if ( file_exists( $thumb ) ) @unlink( $thumb );
                        }
                    }
                    @unlink( $current_file );
                }

                // Restore original file from backup
                copy( $backup_path, $original_path );
                @unlink( $backup_path );

                // Update WordPress attachment back to original
                update_attached_file( $att_id, $original_path );
                wp_update_post( [
                    'ID'             => $att_id,
                    'post_mime_type' => $original_mime,
                ] );

                // Regenerate thumbnails for original
                $metadata = wp_generate_attachment_metadata( $att_id, $original_path );
                wp_update_attachment_metadata( $att_id, $metadata );
            } else {
                return wpilot_err( 'Original image backup file not found. Cannot restore.' );
            }
            break;
    }

    $wpdb->update( $table, ['restored' => 1], ['id' => $backup_id] );
    return wpilot_ok( "Backup #{$backup_id} restored." );
}

// ── Bulk restore all WebP conversions ──────────────────────────
function wpilot_restore_all_webp() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ca_backups';
    $backups = $wpdb->get_results( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE tool = %s AND target_type = %s AND restored = 0 ORDER BY id DESC",
        'image_webp_convert', 'image'
    ) );

    if ( empty( $backups ) ) return wpilot_ok( 'No WebP conversions to restore.' );

    $restored = 0;
    $failed   = 0;
    foreach ( $backups as $b ) {
        $result = wpilot_restore( $b->id );
        if ( $result['success'] ) $restored++;
        else $failed++;
    }

    return wpilot_ok( "Restored {$restored} images back to original format." . ($failed ? " {$failed} failed." : '') );
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
