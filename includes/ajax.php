<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Role helper ────────────────────────────────────────────────
function wpilot_user_can_chat() {
    return wpilot_can_use(); // admin or editor
}
function wpilot_user_can_admin() {
    return current_user_can( 'manage_options' ); // admin only
}

// ── Parse [ACTION:...] cards from AI text ──────────────────────
function wpilot_parse_actions( $text ) {
    $actions = [];
    // Format 1: [ACTION: tool | label | description | emoji]
    // Format 2: [ACTION: tool | label | description | emoji | {"param":"value"}]
    if ( preg_match_all( '/\[ACTION:\s*([^\|]+)\|\s*([^\|]+)\|\s*([^\|]+)\|\s*([^\|\]]+)(?:\|\s*([^\]]+))?\]/', $text, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $match ) {
            $params = [];
            if ( !empty($match[5]) ) {
                $json = trim($match[5]);
                $decoded = json_decode($json, true);
                if (is_array($decoded)) $params = $decoded;
            }
            // Auto-detect params from tool name + description
            if (empty($params)) {
                $params = wpilot_infer_params(trim($match[1]), trim($match[3]));
            }
            $actions[] = [
                'tool'        => trim( $match[1] ),
                'label'       => trim( $match[2] ),
                'description' => trim( $match[3] ),
                'icon'        => trim( $match[4] ),
                'params'      => $params,
            ];
        }
    }
    return $actions;
}

// Auto-infer params from tool name and description when AI doesn't provide explicit JSON
function wpilot_infer_params( $tool, $description ) {
    $params = [];

    // Tools that need no params (they scan/audit everything)
    $no_params = ['security_scan','seo_audit','bulk_fix_alt_text','bulk_fix_seo',
                  'convert_all_images_webp','site_health_check','database_cleanup',
                  'check_broken_links','cache_configure','cache_purge',
                  'newsletter_list_subscribers','list_users'];
    if (in_array($tool, $no_params)) return $params;

    // Plugin install: extract slug from description
    if ($tool === 'plugin_install') {
        // Try to find a plugin name in the description
        $known_slugs = [
            'rank math' => 'seo-by-rank-math', 'yoast' => 'wordpress-seo',
            'wordfence' => 'wordfence', 'litespeed' => 'litespeed-cache',
            'wp rocket' => 'wp-rocket', 'w3 total' => 'w3-total-cache',
            'wp super cache' => 'wp-super-cache', 'wp mail smtp' => 'wp-mail-smtp',
            'fluentsmtp' => 'fluent-smtp', 'updraftplus' => 'updraftplus',
            'contact form 7' => 'contact-form-7', 'wpforms' => 'wpforms-lite',
            'elementor' => 'elementor', 'polylang' => 'polylang',
            'woocommerce' => 'woocommerce', 'amelia' => 'ameliabooking',
            'hello dolly' => 'hello-dolly',
        ];
        $desc_lower = strtolower($description);
        foreach ($known_slugs as $name => $slug) {
            if (strpos($desc_lower, $name) !== false) {
                $params['slug'] = $slug;
                break;
            }
        }
    }

    // Plugin activate/deactivate: try to find file
    if (in_array($tool, ['activate_plugin', 'deactivate_plugin', 'delete_plugin'])) {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $desc_lower = strtolower($description);
        foreach (get_plugins() as $file => $data) {
            if (strpos($desc_lower, strtolower($data['Name'])) !== false) {
                $params['file'] = $file;
                break;
            }
        }
    }

    // Update tagline
    if ($tool === 'update_tagline') {
        // Extract quoted text from description
        if (preg_match('/["\'](.*?)[\"\']/', $description, $m)) {
            $params['tagline'] = $m[1];
        } else {
            $params['tagline'] = $description;
        }
    }

    // Update blogname
    if ($tool === 'update_blogname') {
        if (preg_match('/["\'](.*?)[\"\']/', $description, $m)) {
            $params['name'] = $m[1];
        }
    }

    // SEO tools: try to find page ID
    if (in_array($tool, ['update_meta_desc','update_seo_title','fix_heading_structure','set_open_graph'])) {
        if (preg_match('/(?:#|ID[: ]|page )(\d+)/', $description, $m)) {
            $params['id'] = intval($m[1]);
        }
        if ($tool === 'update_meta_desc' && empty($params['id'])) {
            // Try to find page by name in description
            $pages = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>50]);
            $desc_lower = strtolower($description);
            foreach ($pages as $p) {
                if (strpos($desc_lower, strtolower($p->post_title)) !== false) {
                    $params['id'] = $p->ID;
                    // Extract the actual meta description from the description text
                    if (preg_match('/["\'](.*?)[\"\']/', $description, $m)) {
                        $params['desc'] = $m[1];
                    }
                    break;
                }
            }
        }
    }

    // Create page
    if ($tool === 'create_page' || $tool === 'create_post') {
        if (preg_match('/["\'](.*?)[\"\']/', $description, $m)) {
            $params['title'] = $m[1];
        }
        $params['status'] = 'publish';
    }

    // CSS tools
    if (in_array($tool, ['update_custom_css','append_custom_css'])) {
        // The CSS content should be in the description
        $params['css'] = $description;
    }

    // Image alt text
    if ($tool === 'update_image_alt') {
        if (preg_match('/(?:#|ID )(\d+)/', $description, $m)) {
            $params['id'] = intval($m[1]);
        }
        if (preg_match('/alt[: ]+"?([^"]+)"?/', $description, $m)) {
            $params['alt'] = trim($m[1]);
        }
    }

    // Generate full site
    if ($tool === 'generate_full_site') {
        $params['business_name'] = get_bloginfo('name');
        $desc_lower = strtolower($description);
        $types = ['booking','ecommerce','restaurant','portfolio','education','membership','events','realestate'];
        foreach ($types as $t) {
            if (strpos($desc_lower, $t) !== false) { $params['site_type'] = $t; break; }
        }
        if (empty($params['site_type'])) $params['site_type'] = 'general';
    }

    // Security fix
    if ($tool === 'fix_security_issue') {
        $desc_lower = strtolower($description);
        if (strpos($desc_lower, 'header') !== false) $params['issue'] = 'add_security_headers';
        elseif (strpos($desc_lower, 'xml') !== false) $params['issue'] = 'disable_xmlrpc';
        elseif (strpos($desc_lower, 'readme') !== false) $params['issue'] = 'delete_readme';
        elseif (strpos($desc_lower, 'registr') !== false) $params['issue'] = 'disable_registration';
    }

    // Redirect
    if ($tool === 'create_redirect') {
        if (preg_match('/from\s+([^\s]+)\s+to\s+([^\s]+)/i', $description, $m)) {
            $params['from'] = $m[1];
            $params['to'] = $m[2];
        }
    }

    return $params;
}

// ── Main chat (admin + editor) ─────────────────────────────────
add_action( 'wp_ajax_ca_chat', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! wpilot_user_has_access() ) wp_send_json_error( 'You don\'t have WPilot access. Ask your admin to grant it.', 403 );
    if ( wpilot_is_locked() )       wp_send_json_error( 'Free limit reached. Please activate your license.' );

    $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
    $mode    = sanitize_text_field( wp_unslash( $_POST['mode']    ?? 'chat' ) );
    $history_raw = json_decode( wp_unslash( $_POST['history'] ?? '[]' ), true ) ?: [];
    // Sanitize history entries
    $history = array_map( function($msg) {
        return [
            'role'    => in_array($msg['role']??'', ['user','assistant']) ? $msg['role'] : 'user',
            'content' => is_string($msg['content']??'') ? wp_kses_post($msg['content']) : '',
        ];
    }, (array)$history_raw );
    $context = json_decode( wp_unslash( $_POST['context'] ?? '{}' ), true ) ?: [];

    if ( empty( $message ) ) wp_send_json_error( 'Empty message.' );
    // Capture page-specific context from the bubble (which page the user is on)
    $page_context = $context;
    // Build fresh site context (pages, plugins, SEO, media)
    $context = wpilot_build_context( $mode === 'chat' ? 'general' : $mode );
    // Merge in the current page info from the bubble so AI knows WHERE the user is
    if ( !empty($page_context['post_id']) ) $context['current_page_id'] = intval($page_context['post_id']);
    if ( !empty($page_context['url']) )     $context['current_url'] = sanitize_url($page_context['url']);
    if ( !empty($page_context['page']) )    $context['current_page_title'] = sanitize_text_field($page_context['page']);
    if ( !empty($page_context['is_front_page']) ) $context['is_front_page'] = $page_context['is_front_page'] === 'yes';
    if ( !empty($page_context['post_type']) ) $context['current_post_type'] = sanitize_text_field($page_context['post_type']);

    // ── Smart routing: Brain → WPilot AI → Claude ─────────────
    $result = wpilot_smart_answer( $message, $mode, $context, $history );
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

    $response = is_array($result) ? $result['text']   : $result;
    $source   = is_array($result) ? $result['source'] : 'claude';
    $mem_id   = is_array($result) ? ($result['memory_id'] ?? null) : null;

    // Only count against prompt limit if Claude was used
    if ( $source === 'claude' ) { wpilot_bump_prompts(); if (function_exists('wpilot_track_usage')) wpilot_track_usage(); }

    $actions = wpilot_parse_actions( $response );

    // Persist history (tag source so UI can show brain/webleas badge)
    $hist   = get_option( 'ca_chat_history', [] );
    $hist[] = ['role'=>'user',      'content'=>$message,  'time'=>current_time('H:i'), 'mode'=>$mode];
    $hist[] = ['role'=>'assistant', 'content'=>$response, 'time'=>current_time('H:i'),
               'actions'=>$actions, 'source'=>$source, 'memory_id'=>$mem_id, 'id'=>'msg_'.uniqid()];
    if ( count($hist) > 60 ) $hist = array_slice($hist,-60);
    update_option( 'ca_chat_history', $hist, false );

    // Collect training data
    if ( function_exists('wpilot_collect_exchange') && $source === 'claude' ) {
        $tools_used = array_map(function($a) { return $a['tool']; }, $actions);
        $auto_rating = function_exists('wpilot_auto_rate') ? wpilot_auto_rate($message, $response, $tools_used) : 3;
        $pair_id = wpilot_collect_exchange($message, $response, $mode, $auto_rating, [
            'tools_used'         => $tools_used,
            'actions_count'      => count($actions),
            'conversation_depth' => count($hist) / 2,
            'site_type'          => function_exists('wpilot_detect_site_type') ? wpilot_detect_site_type() : 'unknown',
            'source'             => $source,
        ]);
    }


    // Auto-approve (admin only)
    if ( wpilot_user_can_admin() && wpilot_auto_approve() && $actions ) {
        foreach ( $actions as $a ) wpilot_safe_run_tool( $a['tool'], $a['params'] ?? [] );
    }

    wp_send_json_success( [
        'response'  => $response,
        'actions'   => $actions,
        'source'    => $source,       // 'brain' | 'webleas' | 'claude'
        'memory_id' => $mem_id,
        'used'      => wpilot_prompts_used(),
        'remaining' => wpilot_prompts_remaining(),
        'locked'    => wpilot_is_locked(),
        'savings'   => wpilot_estimate_savings(),
        'pair_id'   => $pair_id ?? null,
    ] );
} );

// ── Execute a tool (admin only — tools modify the site) ────────
add_action( 'wp_ajax_ca_tool', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! wpilot_user_can_modify() ) wp_send_json_error( 'You need editor or admin role to apply changes.', 403 );

    $tool   = sanitize_text_field( wp_unslash( $_POST['tool']   ?? '' ) );
    $params = json_decode( wp_unslash( $_POST['params'] ?? '{}' ), true ) ?: [];

    // If params empty, infer from tool name + description sent by JS
    if ( empty($params) || (count($params) === 0) ) {
        $desc = sanitize_text_field( wp_unslash( $_POST["description"] ?? "" ) );
        $label = sanitize_text_field( wp_unslash( $_POST["label"] ?? "" ) );
        if ( function_exists("wpilot_infer_params") ) {
            $inferred = wpilot_infer_params( $tool, $desc ?: $label ?: $tool );
            if ( !empty($inferred) ) $params = $inferred;
        }
    }

    // Log backup BEFORE running the tool — so we always have backup_id
    $backup_id = wpilot_backup_log( $tool, $params );

    $result = wpilot_safe_run_tool( $tool, $params );

    // Save tool result to chat history so AI knows what happened
    $hist = get_option( 'ca_chat_history', [] );
    if ( $result['success'] ) {
        $hist[] = ['role'=>'assistant', 'content'=>'[TOOL EXECUTED] ' . $tool . ': ' . ($result['message'] ?? 'Done'), 'time'=>current_time('H:i'), 'source'=>'tool'];
        update_option( 'ca_chat_history', $hist, false );
        wp_send_json_success( array_merge( $result, ['backup_id' => $backup_id] ) );
    } else {
        $hist[] = ['role'=>'assistant', 'content'=>'[TOOL FAILED] ' . $tool . ': ' . ($result['message'] ?? 'Error'), 'time'=>current_time('H:i'), 'source'=>'tool'];
        update_option( 'ca_chat_history', $hist, false );
        wp_send_json_error( [
            'message'   => $result['message'],
            'backup_id' => $backup_id,
            'tool'      => $tool,
        ] );
    }
} );

// ── Save setting (admin only) ──────────────────────────────────
add_action( 'wp_ajax_ca_save_setting', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );

    $allowed = ['wpilot_theme', 'wpilot_auto_approve', 'ca_custom_instructions'];
    $key     = sanitize_key( wp_unslash( $_POST['key']   ?? '' ) );
    $value   = sanitize_textarea_field( wp_unslash( $_POST['value'] ?? '' ) );

    if ( ! in_array( $key, $allowed, true ) ) wp_send_json_error( 'Invalid key.' );
    update_option( $key, $value );
    wp_send_json_success( 'Saved.' );
} );

// ── Save API key (admin only) ──────────────────────────────────
add_action( 'wp_ajax_ca_save_api_key', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
    update_option( 'ca_api_key', $key );
    wp_send_json_success( 'Saved.' );
} );

// ── Clear history (admin only) ─────────────────────────────────
add_action( 'wp_ajax_ca_clear_history', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    delete_option( 'ca_chat_history' );
    wp_send_json_success( 'Cleared.' );
} );

// ── Site scan (admin only) ─────────────────────────────────────
add_action( 'wp_ajax_ca_scan', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    $scope = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'analyze' ) );
    wp_send_json_success( wpilot_build_context( $scope ) );
} );

// ── Backup list (admin only) ───────────────────────────────────
add_action( 'wp_ajax_ca_get_backups', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    wp_send_json_success( wpilot_backup_history() );
} );

// ── Restore backup (admin only) ────────────────────────────────
add_action( 'wp_ajax_ca_restore', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    $id     = intval( wp_unslash( $_POST['backup_id'] ?? 0 ) );
    $result = wpilot_restore( $id );
    if ( $result['success'] ) wp_send_json_success( $result );
    else                      wp_send_json_error( $result['message'] );
} );

// ── Image list (admin only) ────────────────────────────────────
add_action( 'wp_ajax_ca_images', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    wp_send_json_success( wpilot_ctx_images( 80 ) );
} );

// ── User list (admin only) ─────────────────────────────────────
add_action( 'wp_ajax_ca_users', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    $users = get_users( ['number' => 50, 'orderby' => 'registered', 'order' => 'DESC'] );
    wp_send_json_success( array_map( fn($u) => [
        'id'         => $u->ID,
        'username'   => $u->user_login,
        'email'      => $u->user_email,
        'role'       => implode( ', ', $u->roles ),
        'registered' => $u->user_registered,
    ], $users ) );
} );

// ── WooCommerce data (admin only) ─────────────────────────────
add_action( 'wp_ajax_ca_woo_data', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );
    if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( 'WooCommerce not active.' );
    wp_send_json_success( wpilot_ctx_woo() );
} );

// ca_test_connection is handled in api.php — no duplicate here

// ── Restore backup (called from bubble Undo button) ────────────
add_action( 'wp_ajax_ca_restore_backup', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );

    $backup_id = intval( wp_unslash( $_POST['backup_id'] ?? 0 ) );
    if ( ! $backup_id ) wp_send_json_error( 'No backup ID.' );

    $result = wpilot_restore( $backup_id );

    if ( $result['success'] ) wp_send_json_success( $result );
    else                      wp_send_json_error( $result['message'] );
} );

// ── Smart site scan — AI proactively reviews installed plugins ──


// ── Load chat history (admin only) ─────────────────────────────
add_action( 'wp_ajax_wpi_load_history', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! wpilot_user_has_access() ) wp_send_json_error( 'You don\'t have WPilot access. Ask your admin to grant it.', 403 );
    $history = get_option( 'ca_chat_history', [] );
    wp_send_json_success( $history );
} );

// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Save custom instruction (admin only) ───────────────────────
add_action( 'wp_ajax_wpi_save_instruction', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );

    $instruction = sanitize_textarea_field( wp_unslash( $_POST['instruction'] ?? '' ) );
    if ( empty( $instruction ) ) wp_send_json_error( 'Empty instruction.' );

    $current = trim( get_option( 'ca_custom_instructions', '' ) );
    // Append new instruction on a new line
    $updated = $current ? $current . "\n" . $instruction : $instruction;
    update_option( 'ca_custom_instructions', $updated );

    wp_send_json_success( [ 'message' => 'Instruction saved.', 'total' => substr_count( $updated, "\n" ) + 1 ] );
} );

// ── Manage WPilot access roles (admin only) ────────────────
add_action('wp_ajax_wpi_update_roles', function() {
    check_ajax_referer('ca_nonce', 'nonce');
    if ( ! wpilot_user_can_manage() ) wp_send_json_error('Admin only.', 403);

    $roles = json_decode(wp_unslash($_POST['roles'] ?? '[]'), true) ?: [];
    // Sanitize and validate
    $valid_roles = array_keys(wp_roles()->roles);
    $roles = array_filter($roles, function($r) use ($valid_roles) {
        return in_array($r, $valid_roles);
    });
    // Always include administrator
    if (!in_array('administrator', $roles)) $roles[] = 'administrator';

    update_option('wpilot_allowed_roles', array_values($roles));
    wp_send_json_success(['roles' => $roles, 'message' => 'Access updated.']);
});

// ── Get current access roles ───────────────────────────────
add_action('wp_ajax_wpi_get_roles', function() {
    check_ajax_referer('ca_nonce', 'nonce');
    if ( ! wpilot_user_can_manage() ) wp_send_json_error('Admin only.', 403);

    $all_roles = wp_roles()->roles;
    $allowed   = wpilot_allowed_roles();
    $result    = [];
    foreach ($all_roles as $slug => $role) {
        $result[] = [
            'slug'    => $slug,
            'name'    => $role['name'],
            'allowed' => in_array($slug, $allowed),
            'locked'  => $slug === 'administrator', // can't remove admin
        ];
    }
    wp_send_json_success($result);
});

// ── Test connection (moved here so it works before heavy modules load) ──
add_action( 'wp_ajax_ca_test_connection', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

    $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? get_option( 'ca_api_key', '' ) ) );
    if ( empty( $key ) ) wp_send_json_error( 'No API key provided.' );

    $res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 20,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'      => defined('CA_MODEL') ? CA_MODEL : 'claude-sonnet-4-6',
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Reply: OK']],
        ] ),
    ] );

    if ( is_wp_error( $res ) ) wp_send_json_error( 'Connection failed: ' . $res->get_error_message() );

    $code = wp_remote_retrieve_response_code( $res );
    $body = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code === 200 ) {
        update_option( 'ca_api_key',  $key );
        update_option( 'ca_onboarded', 'yes' );
        wp_send_json_success( ['message' => 'Claude connected successfully!', 'model' => defined('CA_MODEL') ? CA_MODEL : 'claude-sonnet-4-6'] );
    }

    wp_send_json_error( $body['error']['message'] ?? 'API error (HTTP ' . $code . ')' );
} );

// ── Debug endpoint for troubleshooting ────────────────────
add_action('wp_ajax_wpi_debug', function() {
    check_ajax_referer('ca_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');
    
    wp_send_json_success([
        'php_version'     => PHP_VERSION,
        'wp_version'      => get_bloginfo('version'),
        'plugin_version'  => defined('CA_VERSION') ? CA_VERSION : 'unknown',
        'connected'       => wpilot_is_connected(),
        'api_key_set'     => !empty(get_option('ca_api_key', '')),
        'has_access'      => wpilot_user_has_access(),
        'can_use'         => wpilot_can_use(),
        'is_locked'       => wpilot_is_locked(),
        'allowed_roles'   => wpilot_allowed_roles(),
        'user_roles'      => wp_get_current_user()->roles,
        'prompts_used'    => wpilot_prompts_used(),
        'license_type'    => wpilot_license_type(),
        'ca_model'        => defined('CA_MODEL') ? CA_MODEL : 'undefined',
        'heavy_loaded'    => function_exists('wpilot_call_claude'),
        'bubble_exists'   => function_exists('wpilot_render_bubble'),
        'jquery_version'  => wp_scripts()->registered['jquery-core']->ver ?? 'unknown',
    ]);
});

// ── Smart scan — FREE, local analysis only ─────────────────
add_action('wp_ajax_wpi_smart_scan', function() {
    check_ajax_referer('ca_nonce', 'nonce');
    if ( ! wpilot_user_has_access() && ! current_user_can('manage_options') ) {
        wp_send_json_error('Unauthorized.', 403);
    }
    // Load heavy modules for context building
    if (function_exists('wpilot_load_heavy')) wpilot_load_heavy();
    $ctx = function_exists('wpilot_build_context') ? wpilot_build_context('full') : [];
    $scan = wpilot_free_site_scan($ctx);
    wp_send_json_success(['scan' => $scan]);
});

function wpilot_free_site_scan($ctx) {
    $site_name = get_bloginfo('name');
    $good = [];
    $warn = [];
    $info = [];

    // Plugins
    $active = isset($ctx['plugins']['active_count']) ? $ctx['plugins']['active_count'] : count(get_option('active_plugins', []));
    $inactive = $ctx['plugins']['inactive_count'] ?? 0;
    $missing = $ctx['plugins']['missing_essentials'] ?? [];
    if ($active > 0) $good[] = $active . ' active plugins';
    if ($active > 25) $warn[] = $active . ' plugins is a lot — consider deactivating unused ones';
    if ($inactive > 3) $info[] = $inactive . ' inactive plugins — consider removing them';
    foreach ($missing as $m) $warn[] = 'Missing: ' . $m;

    // Pages
    $pages = $ctx['pages'] ?? [];
    $page_count = count($pages);
    if ($page_count > 0) $good[] = $page_count . ' pages published';
    if ($page_count == 0) $warn[] = 'No pages found — need to create site content';

    // SEO
    $seo = $ctx['seo'] ?? [];
    $seo_plugin = $seo['seo_plugin'] ?? 'None detected';
    if ($seo_plugin !== 'None detected') $good[] = $seo_plugin . ' installed';
    else $warn[] = 'No SEO plugin — install Rank Math (free) for better Google rankings';
    $missing_meta = ($seo['pages_missing_meta'] ?? 0) + ($seo['posts_missing_meta'] ?? 0);
    if ($missing_meta > 0) $warn[] = $missing_meta . ' pages/posts missing meta description';

    // Images
    $images = $ctx['images'] ?? [];
    $missing_alt = 0;
    foreach ($images as $img) { if (!empty($img['missing_alt'])) $missing_alt++; }
    if (count($images) > 0 && $missing_alt == 0) $good[] = 'All images have alt text';

    // Performance from context
    $perf = function_exists('wpilot_ctx_performance') ? wpilot_ctx_performance() : [];
    if (!empty($perf)) {
        if ($perf['not_webp'] > 0) $warn[] = $perf['not_webp'] . ' images are JPEG/PNG (not WebP) — convert to save ' . round($perf['total_size_mb'] * 0.4, 1) . 'MB';
        if (!empty($perf['large_images'])) $warn[] = count($perf['large_images']) . ' images are over 200KB — should be optimized';
        $good[] = 'PHP ' . $perf['php_version'] . ' | Memory: ' . $perf['memory_limit'] . ' | ' . $perf['active_plugins'] . ' plugins';
        if ($perf['has_cache']) $good[] = 'Cache: ' . $perf['cache_plugin'] . ' active';
        else $warn[] = 'No cache plugin — install LiteSpeed Cache (free) for faster loading';
    }
    if ($missing_alt > 0) $warn[] = $missing_alt . ' images missing alt text';

    // Theme + builder
    $theme = $ctx['theme']['name'] ?? wp_get_theme()->get('Name');
    $builder = $ctx['builder'] ?? 'gutenberg';
    $good[] = 'Theme: ' . $theme . ' | Builder: ' . ucfirst($builder);

    // Tagline
    $tagline = get_bloginfo('description');
    if (empty($tagline) || $tagline === 'Just another WordPress site') {
        $warn[] = 'Default tagline — update it for better SEO';
    }

    // SSL
    if (!is_ssl()) $warn[] = 'No HTTPS/SSL — important for security and SEO';

    // WooCommerce
    if (class_exists('WooCommerce') && isset($ctx['woocommerce'])) {
        $products = $ctx['woocommerce']['product_count'] ?? 0;
        $good[] = 'WooCommerce active with ' . $products . ' products';
    }

    // Build response
    $r = '**' . $site_name . '** — Site Overview' . "
";
    if (!empty($good)) { $r .= "
"; foreach ($good as $g) $r .= '✅ ' . $g . "
"; }
    if (!empty($warn)) { $r .= "
"; foreach ($warn as $w) $r .= '⚠️ ' . $w . "
"; }
    if (!empty($info)) { $r .= "
"; foreach ($info as $i) $r .= '💡 ' . $i . "
"; }
    $r .= "
What would you like me to help with first?";
    return $r;
}
