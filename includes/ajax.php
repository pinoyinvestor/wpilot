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
    // Flexible: matches [ACTION: tool], [ACTION: tool | label], [ACTION: tool | label | desc | icon]
    if ( preg_match_all( '/\[ACTION:\s*([^\]\|]+?)(?:\|([^\]\|]*?))?(?:\|([^\]\|]*?))?(?:\|([^\]]*?))?\]/', $text, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $match ) {
            $label = trim( $match[2] ?? '' );
            // Try to extract inline JSON from label (e.g. "Installing plugin slug: wordfence")
            $inline_params = [];
            if ( preg_match('/slug[:\s]+["\']?([a-z0-9\-_]+)["\']?/i', $label, $sp) ) {
                $inline_params['slug'] = $sp[1];
            }
            // Also extract common param patterns from label
            if ( preg_match('/(?:post|page|product)[_\s]*(?:id|ID)[:\s]+(\d+)/i', $label, $ip) ) {
                $inline_params['post_id'] = intval($ip[1]);
            }
            if ( preg_match('/(?:price|pris)[:\s]+\$?(\d+(?:\.\d+)?)/i', $label, $pp) ) {
                $inline_params['price'] = $pp[1];
            }
            $actions[] = [
                'tool'        => trim( $match[1] ),
                'label'       => $label,
                'description' => trim( $match[3] ?? "" ),
                'icon'        => trim( $match[4] ?? "" ),
                'params'      => $inline_params,
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
    // Context: system prompt now includes pages, design, WooCommerce, plugins, CSS
    // Only build heavy context for analyze/build modes — chat uses system prompt data
    $context = ($mode !== 'chat') ? wpilot_build_context($mode) : [];

    // ── Smart routing: Brain → WPilot AI → Claude ─────────────
    $result = wpilot_smart_answer( $message, $mode, $context, $history );
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

    $response = is_array($result) ? $result['text']   : $result;
    $source   = is_array($result) ? $result['source'] : 'claude';
    $mem_id   = is_array($result) ? ($result['memory_id'] ?? null) : null;

    // Only count against prompt limit if Claude was used
    if ( $source === 'claude' ) wpilot_bump_prompts();

    $actions = wpilot_parse_actions( $response );
    if (empty($actions) && function_exists("wpilot_parse_compact_actions")) $actions = wpilot_parse_compact_actions($response);
    if (function_exists("wpilot_enhance_action_params")) wpilot_enhance_action_params($actions, $response);

    // Collect training data (before history — uses $actions early)
    $pair_id = null;
    if ( function_exists('wpilot_collect_exchange') && $source === 'claude' ) {
        $tools_used  = array_map(function($a) { return $a['tool']; }, $actions);
        $auto_rating = function_exists('wpilot_auto_rate') ? wpilot_auto_rate($message, $response, $tools_used) : 3;
        $pair_id     = wpilot_collect_exchange($message, $response, $mode, $auto_rating, [
            'tools_used'         => $tools_used,
            'actions_count'      => count($actions),
            'site_type'          => function_exists('wpilot_detect_site_type') ? wpilot_detect_site_type() : 'unknown',
            'source'             => $source,
        ]);
    }

    // ── Auto-approve: execute all actions, collect results, append summary ──
    $auto_summary = '';
    if ( wpilot_user_can_admin() && wpilot_auto_approve() && ! empty( $actions ) ) {
        $total      = count( $actions );
        $ok_count   = 0;
        $result_lines = [];

        foreach ( $actions as &$a ) {
            $tool   = $a['tool'];
            $params = $a['params'] ?? [];
            $label  = $a['label'] ?? $tool;

            // Log backup before execution
            $backup_id = function_exists('wpilot_backup_log') ? wpilot_backup_log( $tool, $params ) : null;

            // Execute
            $r = wpilot_safe_run_tool( $tool, $params );

            if ( ! empty( $r['success'] ) ) {
                $ok_count++;
                $result_lines[] = $label . ': OK';
                $a['auto_status']    = 'done';
                $a['auto_backup_id'] = $backup_id;

                // Activity log
                if ( function_exists('wpilot_log_activity') ) {
                    wpilot_log_activity( $tool, '[Auto] ' . $label, $r['message'] ?? '', $backup_id, 'ok' );
                }
            } else {
                $err_msg        = $r['message'] ?? 'Failed';
                $result_lines[] = $label . ': ✕ ' . $err_msg;
                $a['auto_status'] = 'failed';
                $a['auto_error']  = $err_msg;

                if ( function_exists('wpilot_log_activity') ) {
                    wpilot_log_activity( $tool, '[Auto] ' . $label, $err_msg, $backup_id, 'error' );
                }
            }
        }
        unset( $a );

        // Build summary line appended to AI response
        $summary_emoji  = ( $ok_count === $total ) ? '✅' : '⚠️';
        $auto_summary   = "\n\n" . $summary_emoji . ' Auto-approved: ' . $ok_count . '/' . $total . ' actions completed';
        if ( ! empty( $result_lines ) ) {
            $auto_summary .= ' — ' . implode( ', ', $result_lines );
        }
        $response .= $auto_summary;
    }

    // ── Auto-verify: after design changes, send a follow-up to check & fix ──
    $design_tools = ['create_html_page','update_page_content','append_page_content','add_head_code','add_footer_code','add_php_snippet'];
    $did_design = false;
    $failed_any = false;
    foreach ($actions as $a) {
        if (in_array($a['tool'], $design_tools) && ($a['auto_status'] ?? '') === 'done') $did_design = true;
        if (($a['auto_status'] ?? '') === 'failed') $failed_any = true;
    }
    // If design was done and something failed, auto-retry the failed action
    if ($failed_any && $source === 'claude' && wpilot_auto_approve()) {
        $failed_tools = [];
        foreach ($actions as $a) {
            if (($a['auto_status'] ?? '') === 'failed') {
                $failed_tools[] = $a['tool'] . ' (' . ($a['auto_error'] ?? 'unknown') . ')';
            }
        }
        // Build smart retry message that tells AI exactly what went wrong and how to fix
        $retry_parts = [];
        foreach ($actions as $a) {
            if (($a['auto_status'] ?? '') === 'failed') {
                $err = $a['auto_error'] ?? 'unknown';
                $tool = $a['tool'];
                // Suggest fix based on error pattern
                if (strpos($err, 'not found') !== false || strpos($err, 'not installed') !== false) {
                    $retry_parts[] = "{$tool} failed: {$err}. Try plugin_install first, then retry.";
                } elseif (strpos($err, 'required') !== false) {
                    $retry_parts[] = "{$tool} failed: {$err}. The params were empty — include all required params in your JSON.";
                } elseif (strpos($err, 'not allowed') !== false) {
                    $retry_parts[] = "{$tool} failed: {$err}. Use a different tool or approach.";
                } else {
                    $retry_parts[] = "{$tool} failed: {$err}. Fix and retry.";
                }
            }
        }
        $retry_msg = "FAILED ACTIONS — fix each one:\n" . implode("\n", $retry_parts) . "\n\nIMPORTANT: Only retry the failed actions. Don't repeat successful ones.";
        $retry_result = wpilot_call_claude($retry_msg, $mode, $context, array_merge($history, [
            ['role' => 'assistant', 'content' => $response],
            ['role' => 'user', 'content' => $retry_msg],
        ]));
        if (!is_wp_error($retry_result)) {
            $retry_text = is_array($retry_result) ? $retry_result['text'] : $retry_result;
            $retry_actions = wpilot_parse_actions($retry_text);
            if (empty($retry_actions) && function_exists("wpilot_parse_compact_actions")) $retry_actions = wpilot_parse_compact_actions($retry_text);
            if (function_exists("wpilot_enhance_action_params")) wpilot_enhance_action_params($retry_actions, $retry_text);
            foreach ($retry_actions as &$ra) {
                $rr = wpilot_safe_run_tool($ra['tool'], $ra['params'] ?? []);
                $ra['auto_status'] = !empty($rr['success']) ? 'done' : 'failed';
                if (!empty($rr['success'])) $ok_count++;
            }
            $actions = array_merge($actions, $retry_actions);
            $response .= "\n\n🔄 Auto-retry: " . count($retry_actions) . " actions attempted.";
        }
    }

    // ── Auto-continue: if the AI planned multiple phases, continue to next phase ──
    $has_phases = preg_match('/Phase \d|Step \d|Next:|Part \d/i', $response);
    $all_succeeded = !$failed_any && $ok_count > 0;
    if ($has_phases && $all_succeeded && $source === 'claude' && wpilot_auto_approve()) {
        $continue_count = intval(get_transient('wpilot_auto_continue_' . get_current_user_id()) ?: 0);
        if ($continue_count < 3) { // Max 3 auto-continues per conversation
            set_transient('wpilot_auto_continue_' . get_current_user_id(), $continue_count + 1, 300);
            $results_summary = implode(', ', $result_lines);
            $cont_msg = "Phase complete. Results: {$results_summary}. Continue to the NEXT phase. Don't repeat what's done.";
            $cont_result = wpilot_call_claude($cont_msg, $mode, [], array_merge($history, [
                ['role' => 'assistant', 'content' => $response],
                ['role' => 'user', 'content' => $cont_msg],
            ]));
            if (!is_wp_error($cont_result)) {
                $cont_text = is_array($cont_result) ? $cont_result['text'] : $cont_result;
                $cont_actions = wpilot_parse_actions($cont_text);
                if (empty($cont_actions) && function_exists("wpilot_parse_compact_actions")) $cont_actions = wpilot_parse_compact_actions($cont_text);
                if (function_exists("wpilot_enhance_action_params")) wpilot_enhance_action_params($cont_actions, $cont_text);
                foreach ($cont_actions as &$ca) {
                    $cr = wpilot_safe_run_tool($ca['tool'], $ca['params'] ?? []);
                    $ca['auto_status'] = !empty($cr['success']) ? 'done' : 'failed';
                }
                $actions = array_merge($actions, $cont_actions);
                $response .= "\n\n➡️ Auto-continued to next phase:\n" . $cont_text;
            }
        }
    }

    // ── Save action log — persistent memory of what's been done ──
    $action_log = get_option('wpilot_action_log', []);
    foreach ($actions as $a) {
        $action_log[] = [
            'tool' => $a['tool'],
            'status' => $a['auto_status'] ?? 'pending',
            'label' => substr($a['label'] ?? '', 0, 80),
            'time' => current_time('H:i'),
        ];
    }
    // Keep last 50 actions
    if (count($action_log) > 50) $action_log = array_slice($action_log, -50);
    update_option('wpilot_action_log', $action_log, false);

    // Persist history after auto-approve so response & action statuses are final
    $hist   = get_option( 'ca_chat_history', [] );
    $hist[] = ['role'=>'user',      'content'=>$message,  'time'=>current_time('H:i'), 'mode'=>$mode];
    $hist[] = ['role'=>'assistant', 'content'=>$response, 'time'=>current_time('H:i'),
               'actions'=>$actions, 'source'=>$source, 'memory_id'=>$mem_id, 'id'=>'msg_'.uniqid()];
    if ( count($hist) > 20 ) $hist = array_slice($hist,-20);
    update_option( 'ca_chat_history', $hist, false );

    wp_send_json_success( [
        'response'      => $response,
        'actions'       => $actions,
        'source'        => $source,       // 'brain' | 'webleas' | 'claude'
        'memory_id'     => $mem_id,
        'auto_approved' => ! empty( $auto_summary ),
        'used'          => wpilot_prompts_used(),
        'remaining'     => wpilot_prompts_remaining(),
        'locked'        => wpilot_is_locked(),
        'savings'       => wpilot_estimate_savings(),
        'pair_id'       => $pair_id ?? null,
    ] );
} );

// ── Execute a tool (admin only — tools modify the site) ────────
add_action( 'wp_ajax_ca_tool', function () {
    // Lazy-load only needed module for this tool
    if (function_exists('wpilot_ensure_module')) wpilot_ensure_module(sanitize_text_field($_POST['tool'] ?? ''));
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
    update_option( 'ca_api_key', function_exists('wpilot_encrypt') ? wpilot_encrypt($key) : $key );
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
    // Check for missing essential plugins during scan
    if (function_exists('wpilot_run_tool')) {
        $recs = wpilot_run_tool('recommend_plugins', []);
        if (!empty($recs['recommendations'])) {
            set_transient('wpilot_recommendations_' . get_current_user_id(), $recs['recommendations'], 300);
        }
    }
    if ( ! wpilot_user_has_access() ) wp_send_json_error('You don\'t have WPilot access. Ask your admin to grant it.', 403);
    if (!wpilot_is_connected()) wp_send_json_error('Not connected');
    if (wpilot_is_locked()) wp_send_json_error('Free limit reached. Please activate your license.');

    $ctx  = wpilot_build_context('plugins');
    $lang = function_exists('wpilot_get_lang') ? wpilot_get_lang() : 'en';

    if ( $lang === 'sv' ) {
        $prompt = "Jag har precis öppnat WPilot och vill ha en snabb genomgång av min WordPress-sajt.\n\nTitta på:\n1. Vilka plugins jag har installerade och aktiverade\n2. Vilka essentiella plugins som saknas (SEO, backup, säkerhet, prestanda, formulär)\n3. Plugins som jag har men kanske inte använder optimalt\n4. Om det finns plugins som överlappar varandra\n\nSvara strukturerat:\n- Börja med en kort sammanfattning (1-2 meningar) om sajten\n- Lista vad som fungerar bra ✅\n- Lista vad som saknas eller kan förbättras ⚠️\n- Avsluta med: \"Vad vill du att jag ska hjälpa dig med först?\"\n\nVar alltid ärlig om vad som är gratis och vad som kostar. Håll det kort och konkret — max 200 ord.";
    } else {
        $prompt = "I just opened WPilot and want a quick review of my WordPress site.\n\nLook at:\n1. Which plugins I have installed and active\n2. Which essential plugins are missing (SEO, backup, security, performance, forms)\n3. Plugins I have but may not be using optimally\n4. Plugins that overlap or conflict with each other\n\nRespond in a structured way:\n- Start with a brief 1-2 sentence summary of the site\n- List what is working well ✅\n- List what is missing or could be improved ⚠️\n- End with: \"What would you like me to help with first?\"\n\nAlways be honest about what is free vs paid. Keep it short and concrete — max 200 words.";
    }

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

// ── Feedback submission ────────────────────────────────────────
add_action('wp_ajax_wpi_send_feedback', function() {
    check_ajax_referer('ca_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized.');

    $type = sanitize_text_field($_POST['type'] ?? 'feedback');
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    $rating = intval($_POST['rating'] ?? 0);
    $email = sanitize_email($_POST['email'] ?? get_option('admin_email'));

    if (empty($message)) wp_send_json_error('Message required.');

    // Rate limit: 5 per hour
    $transient_key = 'wpi_feedback_' . get_current_user_id();
    $count = (int) get_transient($transient_key);
    if ($count >= 5) wp_send_json_error('Rate limit: max 5 feedback per hour.');
    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    // Save locally
    $feedbacks = get_option('wpilot_feedbacks', []);
    $fb_id = uniqid('fb_');
    $feedbacks[$fb_id] = [
        'type'           => $type,
        'message'        => $message,
        'rating'         => $rating,
        'email'          => $email,
        'date'           => date('Y-m-d H:i:s'),
        'site_url'       => get_site_url(),
        'wp_version'     => get_bloginfo('version'),
        'plugin_version' => CA_VERSION,
        'php_version'    => PHP_VERSION,
        'reply'          => '',
        'status'         => 'sent',
    ];
    update_option('wpilot_feedbacks', $feedbacks);

    // Send to weblease.se
    $response = wp_remote_post('https://weblease.se/plugin/feedback', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'id'             => $fb_id,
            'type'           => $type,
            'message'        => $message,
            'rating'         => $rating,
            'email'          => $email,
            'site_url'       => get_site_url(),
            'site_name'      => get_bloginfo('name'),
            'wp_version'     => get_bloginfo('version'),
            'plugin_version' => CA_VERSION,
            'php_version'    => PHP_VERSION,
            'builder'        => function_exists('wpilot_detect_builder') ? wpilot_detect_builder() : 'unknown',
            'tools_count'    => substr_count(file_get_contents(WP_PLUGIN_DIR . '/wpilot/includes/tools.php'), "case '"),
        ]),
    ]);

    $sent = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;

    wp_send_json_success([
        'message'        => $sent ? 'Feedback sent! We\'ll review it soon.' : 'Saved locally (server unreachable).',
        'id'             => $fb_id,
        'sent_to_server' => $sent,
    ]);
});

// ── Check for feedback replies ─────────────────────────────────
add_action('wp_ajax_wpi_check_replies', function() {
    check_ajax_referer('ca_nonce', 'nonce');
    $feedbacks = get_option('wpilot_feedbacks', []);
    $with_replies = array_filter($feedbacks, fn($f) => !empty($f['reply']) && ($f['read'] ?? false) === false);
    wp_send_json_success(['unread_replies' => count($with_replies), 'feedbacks' => $feedbacks]);
});

// Newsletter subscribe AJAX (public — no login needed)
add_action('wp_ajax_wpilot_subscribe', 'wpilot_handle_subscribe');
add_action('wp_ajax_nopriv_wpilot_subscribe', 'wpilot_handle_subscribe');
function wpilot_handle_subscribe() {
    check_ajax_referer('ca_nonce', 'nonce');
    $email = sanitize_email($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        wp_send_json_error(['message' => 'Valid email required.']);
    }
    // Rate limit
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = 'wpilot_sub_' . md5($ip);
    $count = intval(get_transient($key));
    if ($count > 5) wp_send_json_error(['message' => 'Too many attempts. Try later.']);
    set_transient($key, $count + 1, 3600);
    // Save subscriber
    $subscribers = get_option('wpilot_subscribers', []);
    if (isset($subscribers[$email])) {
        wp_send_json_success(['message' => 'Already subscribed!']);
    }
    $subscribers[$email] = ['date' => date('Y-m-d H:i:s'), 'ip' => $ip, 'source' => 'popup'];
    update_option('wpilot_subscribers', $subscribers);
    wp_send_json_success(['message' => 'Subscribed! Check your email.', 'email' => $email]);
}