<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Chat session management ────────────────────────────────────
// Sessions auto-expire after inactivity. Each user gets one active
// session at a time. Messages are stored in DB so they survive reloads.

define( 'WPILOT_SESSION_TIMEOUT', 30 * MINUTE_IN_SECONDS ); // 30 min inactivity

// ── Create sessions table on activation ────────────────────────
function wpilot_create_sessions_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_sessions';
    $charset = $wpdb->get_charset_collate();
    // Built by Weblease
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        session_key VARCHAR(64) NOT NULL,
        status ENUM('active','expired','cleared') DEFAULT 'active',
        messages LONGTEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_session_key (session_key),
        INDEX idx_expires (expires_at)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ── Get or create active session for current user ──────────────
function wpilot_get_session( $session_key = '' ) {
    global $wpdb;
    $table   = $wpdb->prefix . 'wpilot_sessions';
    $user_id = get_current_user_id();

    // Expire old sessions first
    wpilot_expire_sessions();

    // Try to find active session
    $where = $session_key
        ? $wpdb->prepare( "session_key = %s AND status = 'active'", $session_key )
        : $wpdb->prepare( "user_id = %d AND status = 'active'", $user_id );

    $session = $wpdb->get_row( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 1" );

    if ( $session ) {
        // Touch session — extend expiry
        $wpdb->update( $table, [
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + WPILOT_SESSION_TIMEOUT ),
        ], [ 'id' => $session->id ] );
        return $session;
    }

    return null;
}

// ── Start a new session ────────────────────────────────────────
function wpilot_start_session() {
    global $wpdb;
    $table   = $wpdb->prefix . 'wpilot_sessions';
    $user_id = get_current_user_id();

    // Expire any current active session for this user
    $wpdb->update( $table,
        [ 'status' => 'expired' ],
        [ 'user_id' => $user_id, 'status' => 'active' ]
    );

    $key = wp_generate_password( 32, false );
    $now = gmdate( 'Y-m-d H:i:s' );
    $exp = gmdate( 'Y-m-d H:i:s', time() + WPILOT_SESSION_TIMEOUT );

    $wpdb->insert( $table, [
        'user_id'     => $user_id,
        'session_key' => $key,
        'status'      => 'active',
        'messages'    => wp_json_encode( [] ),
        'created_at'  => $now,
        'updated_at'  => $now,
        'expires_at'  => $exp,
    ] );

    return [
        'id'          => $wpdb->insert_id,
        'session_key' => $key,
        'messages'    => [],
        'expires_at'  => $exp,
    ];
}

// ── Add message to session ─────────────────────────────────────
function wpilot_session_add_message( $session_key, $role, $content ) {
    global $wpdb;
    $table   = $wpdb->prefix . 'wpilot_sessions';
    $session = wpilot_get_session( $session_key );
    if ( ! $session ) return false;

    $messages   = json_decode( $session->messages, true ) ?: [];
    $messages[] = [
        'role'    => $role,
        'content' => $content,
        'time'    => time(),
    ];

    // Keep max 50 messages per session
    if ( count( $messages ) > 50 ) {
        $messages = array_slice( $messages, -50 );
    }

    $wpdb->update( $table, [
        'messages'   => wp_json_encode( $messages ),
        'expires_at' => gmdate( 'Y-m-d H:i:s', time() + WPILOT_SESSION_TIMEOUT ),
    ], [ 'id' => $session->id ] );

    return true;
}

// ── Get session messages ───────────────────────────────────────
function wpilot_session_messages( $session_key ) {
    $session = wpilot_get_session( $session_key );
    if ( ! $session ) return [];
    return json_decode( $session->messages, true ) ?: [];
}

// ── End session (user clicks "new chat") ───────────────────────
function wpilot_end_session( $session_key ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_sessions';
    return $wpdb->update( $table,
        [ 'status' => 'cleared' ],
        [ 'session_key' => $session_key ]
    );
}

// ── Expire stale sessions ──────────────────────────────────────
function wpilot_expire_sessions() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_sessions';
    $now   = gmdate( 'Y-m-d H:i:s' );
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'expired' WHERE status = 'active' AND expires_at < %s",
        $now
    ) );
}

// ── Check if session is still alive ────────────────────────────
function wpilot_session_alive( $session_key ) {
    $session = wpilot_get_session( $session_key );
    return $session && $session->status === 'active';
}

// ── Get session time remaining (seconds) ───────────────────────
function wpilot_session_remaining( $session_key ) {
    $session = wpilot_get_session( $session_key );
    if ( ! $session ) return 0;
    $exp = strtotime( $session->expires_at );
    return max( 0, $exp - time() );
}

// ── Cleanup: delete sessions older than 7 days ─────────────────
function wpilot_cleanup_old_sessions() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpilot_sessions';
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE status != 'active' AND updated_at < %s",
        $cutoff
    ) );
}

// Schedule cleanup
if ( ! wp_next_scheduled( 'wpilot_session_cleanup' ) ) {
    wp_schedule_event( time(), 'daily', 'wpilot_session_cleanup' );
}
add_action( 'wpilot_session_cleanup', 'wpilot_cleanup_old_sessions' );
