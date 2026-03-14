<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  ACTIVITY LOG — Full audit trail of everything AI changed
// ═══════════════════════════════════════════════════════════════

function wpilot_log_activity( $tool, $label, $detail = '', $backup_id = null, $status = 'ok' ) {
    $log   = get_option('wpi_activity_log', []);
    $log[] = [
        'id'        => uniqid('act_'),
        'tool'      => $tool,
        'label'     => $label,
        'detail'    => $detail,
        'backup_id' => $backup_id,
        'status'    => $status, // ok | error | restored
        'user'      => wp_get_current_user()->display_name ?: 'Admin',
        'ts'        => time(),
    ];
    // Keep last 200 entries
    if ( count($log) > 200 ) $log = array_slice($log, -200);
    update_option('wpi_activity_log', $log);
}

// Hook into wpilot_run_tool to auto-log every action
add_filter('wpi_after_tool_run', function($result, $tool, $params, $backup_id) {
    $labels = [
        'create_page'          => 'Created page',
        'update_page_content'  => 'Updated page content',
        'update_post_title'    => 'Updated post title',
        'set_homepage'         => 'Set homepage',
        'create_menu'          => 'Created menu',
        'add_menu_item'        => 'Added menu item',
        'update_meta_desc'     => 'Updated meta description',
        'update_seo_title'     => 'Updated SEO title',
        'update_custom_css'    => 'Updated custom CSS',
        'append_custom_css'    => 'Appended custom CSS',
        'update_image_alt'     => 'Updated image alt text',
        'bulk_fix_alt_text'    => 'Bulk fixed alt text',
        'create_coupon'        => 'Created coupon',
        'update_product_price' => 'Updated product price',
        'create_user'          => 'Created user',
        'deactivate_plugin'    => 'Deactivated plugin',
        'update_blogname'      => 'Updated site name',
        'update_tagline'       => 'Updated tagline',
        'restore_backup'       => 'Restored backup',
    ];
    $label  = $labels[$tool] ?? ucfirst(str_replace('_',' ',$tool));
    $detail = '';
    if ( isset($params['title'])   ) $detail = $params['title'];
    if ( isset($params['name'])    ) $detail = $params['name'];
    if ( isset($params['post_id']) ) $detail = "ID: {$params['post_id']}";
    $status = ($result['success'] ?? false) ? 'ok' : 'error';
    wpilot_log_activity($tool, $label, $detail, $backup_id, $status);
    return $result;
}, 10, 4);

// Get activity log
function wpilot_get_activity_log( $limit = 50 ) {
    $log = get_option('wpi_activity_log', []);
    return array_slice(array_reverse($log), 0, $limit);
}

// Clear log
function wpilot_clear_activity_log() {
    delete_option('wpi_activity_log');
}

// AJAX: get log
add_action('wp_ajax_wpi_activity_log', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    wp_send_json_success(wpilot_get_activity_log(100));
});

// AJAX: clear log
add_action('wp_ajax_wpi_clear_activity_log', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    wpilot_clear_activity_log();
    wp_send_json_success();
});
