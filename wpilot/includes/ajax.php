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
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_chat() ) wp_send_json_error( 'Unauthorized.' );
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
    if ( empty( $context )  ) $context = wpilot_build_context( 'general' );

    // ── Smart routing: Brain → WPilot AI → Claude ─────────────
    $result = wpilot_smart_answer( $message, $mode, $context, $history );
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

    $response = is_array($result) ? $result['text']   : $result;
    $source   = is_array($result) ? $result['source'] : 'claude';
    $mem_id   = is_array($result) ? ($result['memory_id'] ?? null) : null;

    // Only count against prompt limit if Claude was used
    if ( $source === 'claude' ) wpilot_bump_prompts();

    $actions = wpilot_parse_actions( $response );

    // Persist history (tag source so UI can show brain/webleas badge)
    $hist   = get_option( 'ca_chat_history', [] );
    $hist[] = ['role'=>'user',      'content'=>$message,  'time'=>current_time('H:i'), 'mode'=>$mode];
    $hist[] = ['role'=>'assistant', 'content'=>$response, 'time'=>current_time('H:i'),
               'actions'=>$actions, 'source'=>$source, 'memory_id'=>$mem_id, 'id'=>'msg_'.uniqid()];
    if ( count($hist) > 60 ) $hist = array_slice($hist,-60);
    update_option( 'ca_chat_history', $hist );

    // Auto-approve (admin only)
    if ( wpilot_user_can_admin() && wpilot_auto_approve() && $actions ) {
        foreach ( $actions as $a ) wpilot_run_tool( $a['tool'], $a['params'] ?? [] );
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
    ] );
} );

// ── Execute a tool (admin only — tools modify the site) ────────
add_action( 'wp_ajax_ca_tool', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized. Admin only.' );

    $tool   = sanitize_text_field( wp_unslash( $_POST['tool']   ?? '' ) );
    $params = json_decode( wp_unslash( $_POST['params'] ?? '{}' ), true ) ?: [];

    // Log backup BEFORE running the tool — so we always have backup_id
    $backup_id = wpilot_backup_log( $tool, $params );

    $result = wpilot_run_tool( $tool, $params );

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

    $allowed = ['wpilot_theme', 'wpilot_auto_approve', 'ca_custom_instructions', 'ca_license_server'];
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

// ── Test connection (admin only) ───────────────────────────────
add_action( 'wp_ajax_ca_test_connection', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    if ( ! wpilot_user_can_admin() ) wp_send_json_error( 'Unauthorized.' );

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
            'model'      => CA_MODEL,
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Reply: OK']],
        ] ),
    ] );

    if ( is_wp_error( $res ) ) wp_send_json_error( 'Connection failed: ' . $res->get_error_message() );

    $code = wp_remote_retrieve_response_code( $res );
    $body = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code === 200 ) {
        update_option( 'ca_api_key',   $key );
        update_option( 'ca_onboarded', 'yes' );
        wp_send_json_success( ['message' => '✅ AI connected successfully!'] );
    }

    wp_send_json_error( $body['error']['message'] ?? "API error (HTTP {$code})" );
} );

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
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    check_ajax_referer('ca_nonce','nonce');
    if (!wpilot_is_connected()) wp_send_json_error('Not connected');

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

    $result = wpilot_call_claude($prompt, 'plugins', $ctx, []);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
    wpilot_increment_prompts();
    wp_send_json_success(['scan' => $result]);
});
