<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════════════════════
//  BRAIN AJAX ENDPOINTS
// ══════════════════════════════════════════════════════════════

// ── Main chat — Brain first, Claude as fallback ───────────────
add_action( 'wp_ajax_wpi_brain_chat', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    if ( ! wpilot_can_use() ) wp_send_json_error('Unauthorized.');

    $message = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
    $mode    = sanitize_text_field( $_POST['mode']    ?? 'chat' );
    $force   = (bool)( $_POST['force_claude'] ?? false );  // user explicitly wants Claude

    if ( empty($message) ) wp_send_json_error('Empty message.');

    // ── 1. Try Brain first (unless forced) ───────────────────
    if ( ! $force && get_option('wpi_brain_active', 'yes') === 'yes' ) {
        $memory = wpilot_brain_recall( $message );
        if ( $memory && $memory->match_score >= WPI_CONFIDENCE_MIN ) {
            wp_send_json_success([
                'reply'      => $memory->response,
                'source'     => 'brain',
                'memory_id'  => $memory->id,
                'confidence' => $memory->match_score,
                'message'    => $message,
            ]);
            return;
        }
    }

    // ── 2. Fall back to Claude ────────────────────────────────
    if ( wpilot_is_locked() ) wp_send_json_error('Prompt limit reached. Upgrade to Pro.');

    $context = wpilot_build_context('chat');
    $history = get_option('ca_chat_history', []);
    $reply   = wpilot_call_claude( $message, $mode, $context, $history );

    if ( is_wp_error($reply) ) wp_send_json_error( $reply->get_error_message() );

    // ── 3. Auto-store what Claude said so Brain can learn ─────
    $memory_id = wpilot_brain_learn_from_exchange( $message, $reply, $mode, false );

    // Save to chat history
    $history[] = ['role'=>'user',      'content'=>$message, 'time'=>current_time('H:i')];
    $history[] = ['role'=>'assistant', 'content'=>$reply,   'time'=>current_time('H:i'), 'memory_id'=>$memory_id];
    update_option('ca_chat_history', array_slice($history,-40));

    // Increment prompt counter
    wpilot_increment_prompts();

    wp_send_json_success([
        'reply'     => $reply,
        'source'    => 'claude',
        'memory_id' => $memory_id,
        'message'   => $message,
    ]);
});

// ── Approve a Brain memory (user clicked ✅ Apply) ────────────
add_action( 'wp_ajax_wpi_approve_memory', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');

    $id       = (int)( $_POST['memory_id'] ?? 0 );
    $question = sanitize_text_field( $_POST['question'] ?? '' );
    $answer   = wp_kses_post( $_POST['answer'] ?? '' );

    if ( !$id && !$question ) wp_send_json_error('No memory ID or question.');

    if ( $id ) {
        wpilot_brain_approve( $id );
    } else {
        // Store as new approved memory from direct approval
        wpilot_brain_learn_from_exchange( $question, $answer, 'chat', true );
    }

    wp_send_json_success(['message' => 'Memory approved and stored ✅']);
});

// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Teach Brain a preference ───────────────────────────────────
add_action( 'wp_ajax_wpi_learn_preference', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');

    $key   = sanitize_text_field( $_POST['pref_key']   ?? '' );
    $value = sanitize_text_field( $_POST['pref_value'] ?? '' );
    if ( !$key || !$value ) wp_send_json_error('Missing key or value.');

    wpilot_brain_learn_preference( $key, $value );
    wp_send_json_success(['message' => "Preference saved: {$key} = {$value}"]);
});

// ── Delete a memory ────────────────────────────────────────────
add_action( 'wp_ajax_wpi_forget_memory', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');
    wpilot_brain_forget( (int)( $_POST['id'] ?? 0 ) );
    wp_send_json_success();
});

// ── Reset Brain ────────────────────────────────────────────────
add_action( 'wp_ajax_wpi_brain_reset', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');
    wpilot_brain_reset();
    wp_send_json_success(['message' => 'Brain reset. All memories cleared.']);
});

// ── Toggle Brain on/off ────────────────────────────────────────
add_action( 'wp_ajax_wpi_toggle_brain', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');
    $active = $_POST['active'] === 'yes' ? 'yes' : 'no';
    update_option('wpi_brain_active', $active);
    wp_send_json_success(['active' => $active]);
});

// ── Get Brain stats ────────────────────────────────────────────
add_action( 'wp_ajax_wpi_brain_stats', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');
    wp_send_json_success( wpilot_brain_stats() );
});

// ── List memories (paginated) ──────────────────────────────────
add_action( 'wp_ajax_wpi_brain_list', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');
    $type   = sanitize_text_field( $_POST['type']   ?? '' );
    $offset = (int)( $_POST['offset'] ?? 0 );
    $rows   = wpilot_brain_get_all( 50, $offset, $type ?: null );
    wp_send_json_success( $rows );
});
