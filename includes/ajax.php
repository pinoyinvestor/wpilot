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
    if ( preg_match_all( '/\[ACTION:\s*([^\|]+)\|\s*([^\|]+)\|\s*([^\|]+)\|\s*([^\]]+)\]/', $text, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $match ) {
            $actions[] = [
                'tool'        => trim( $match[1] ),
                'label'       => trim( $match[2] ),
                'description' => trim( $match[3] ),
                'icon'        => trim( $match[4] ),
                'params'      => [],
            ];
        }
    }
    return $actions;
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

    // Log backup BEFORE running the tool — so we always have backup_id
    $backup_id = wpilot_backup_log( $tool, $params );

    $result = wpilot_safe_run_tool( $tool, $params );

    if ( $result['success'] ) {
        wp_send_json_success( array_merge( $result, ['backup_id' => $backup_id] ) );
    } else {
        // On error: always return backup_id so UI can show Restore button
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
add_action('wp_ajax_wpi_smart_scan', function() {
    check_ajax_referer('ca_nonce','nonce');
    if ( ! wpilot_user_has_access() ) wp_send_json_error('You don\'t have WPilot access. Ask your admin to grant it.', 403);
    if (!wpilot_is_connected()) wp_send_json_error('Not connected');
    if (wpilot_is_locked()) wp_send_json_error('Free limit reached. Please activate your license.');

    $ctx = wpilot_build_context('plugins');

    $prompt = <<<PROMPT
Jag har precis öppnat WPilot och vill ha en snabb genomgång av min WordPress-sajt.

Titta på:
1. Vilka plugins jag har installerade och aktiverade
2. Vilka essentiella plugins som saknas (SEO, backup, säkerhet, prestanda, formulär)
3. Plugins som jag har men kanske inte använder optimalt
4. Om det finns plugins som överlappar varandra

Svara strukturerat:
- Börja med en kort sammanfattning (1-2 meningar) om sajten
- Lista vad som fungerar bra ✅
- Lista vad som saknas eller kan förbättras ⚠️
- Avsluta med: "Vad vill du att jag ska hjälpa dig med först?"

Var alltid ärlig om vad som är gratis och vad som kostar. Håll det kort och konkret — max 200 ord.
PROMPT;

    $result = wpilot_smart_answer($prompt, 'plugins', $ctx, []);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
    $response = is_array($result) ? $result['text'] : $result;
    $source   = is_array($result) ? $result['source'] : 'claude';
    if ($source === 'claude') wpilot_bump_prompts();
    wp_send_json_success(['scan' => $response]);
});

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
