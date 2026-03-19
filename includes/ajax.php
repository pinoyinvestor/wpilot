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

// ── Clear history (admin only) ─────────────────────────────────

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

// Built by Weblease

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
// ── Session: start new chat ────────────────────────────────────

// ── Session: load messages ─────────────────────────────────────

// ── Session: check if alive (heartbeat) ────────────────────────

// ── Session: end current ───────────────────────────────────────
