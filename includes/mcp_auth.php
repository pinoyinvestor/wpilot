<?php
/**
 * WPilot MCP Server — Authentication & Rate Limiting
 *
 * Handles API key generation, validation, and per-IP rate limiting
 * for the MCP (Model Context Protocol) endpoint.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generate a new MCP API key.
 * Returns the plaintext key (shown once), stores SHA-256 hash in wp_options.
 */
function wpilot_mcp_generate_key() {
    $bytes = random_bytes(36);
    $key   = 'wpi_mcp_' . bin2hex($bytes);
    $hash  = hash('sha256', $key);

    update_option('wpilot_mcp_api_key_hash', $hash);
    update_option('wpilot_mcp_key_created', date('Y-m-d H:i:s'));
    delete_option('wpilot_mcp_key_revoked');

    // Reset stats on new key
    update_option('wpilot_mcp_stats', [
        'total_requests' => 0,
        'last_request'   => null,
        'created'        => date('Y-m-d H:i:s'),
    ]);

    return $key;
}

/**
 * Validate an incoming Bearer token against stored hash.
 * Uses timing-safe comparison to prevent timing attacks.
 */
function wpilot_mcp_validate_key($token) {
    if (empty($token)) return false;

    $stored_hash = get_option('wpilot_mcp_api_key_hash', '');
    if (empty($stored_hash)) return false;

    // Check if key was revoked
    if (get_option('wpilot_mcp_key_revoked', false)) return false;

    $token_hash = hash('sha256', $token);
    return hash_equals($stored_hash, $token_hash);
}

// Built by Weblease

/**
 * Revoke the current MCP API key.
 */
function wpilot_mcp_revoke_key() {
    delete_option('wpilot_mcp_api_key_hash');
    update_option('wpilot_mcp_key_revoked', date('Y-m-d H:i:s'));
}

/**
 * Check if an MCP key exists (is configured).
 */
function wpilot_mcp_has_key() {
    return !empty(get_option('wpilot_mcp_api_key_hash', ''));
}

/**
 * Rate limit: 120 requests per minute per IP.
 * Returns true if allowed, false if rate limited.
 */
function wpilot_mcp_rate_limit() {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'wpilot_mcp_rl_' . md5($ip);

    $count = intval(get_transient($key) ?: 0);

    if ($count >= 120) {
        return false;
    }

    set_transient($key, $count + 1, 60);
    return true;
}

/**
 * Extract Bearer token from Authorization header.
 */
function wpilot_mcp_get_bearer_token() {
    $header = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    return '';
}

/**
 * Increment request stats for the admin dashboard.
 */
function wpilot_mcp_track_request() {
    $stats = get_option('wpilot_mcp_stats', [
        'total_requests' => 0,
        'last_request'   => null,
        'created'        => null,
    ]);

    $stats['total_requests'] = ($stats['total_requests'] ?? 0) + 1;
    $stats['last_request']   = date('Y-m-d H:i:s');

    update_option('wpilot_mcp_stats', $stats);
}


// ═══════════════════════════════════════════════════════════════
//
//  Collects every tool call (MCP + chat bubble) for AI training:
//  • Tool name, arg keys (not values), success/fail, timing
//  • Tool chains — sequences of tools in one session
//  • All data anonymized — site hash, no URLs/emails/keys
//  • Consent required — same GDPR opt-in as Q&A collection
// ═══════════════════════════════════════════════════════════════

define('WPI_TOOL_TRAINING_ENDPOINT', 'https://weblease.se/ai-training/tools');

/**
 * Collect a single tool call for training.
 * Called from MCP server (tools/call) and chat bubble (ajax.php).
 */
function wpilot_collect_tool_usage($tool, $arguments, $is_error, $duration_ms, $source = 'mcp', $error_msg = '') {
    // Get or create session ID (groups related tool calls)
    $session_id = wpilot_tool_session_id();

    // Only collect arg keys — never values (privacy)
    $arg_keys = is_array($arguments) ? array_keys($arguments) : [];

    $entry = [
        'tool'       => $tool,
        'arg_keys'   => $arg_keys,
        'success'    => !$is_error,
        'duration_ms'=> $duration_ms,
        'error'      => $is_error ? substr($error_msg, 0, 200) : null,
        'source'     => $source,
        'session_id' => $session_id,
        'seq_num'    => wpilot_tool_seq_increment(),
        'builder'    => function_exists('wpilot_primary_builder') ? wpilot_primary_builder() : '',
        'wp_version' => get_bloginfo('version'),
        'ts'         => time(),
    ];

    // Queue locally
    $queue = get_option('wpi_tool_queue', []);
    $queue[] = $entry;

    // Keep max 1000 entries
    if (count($queue) > 1000) {
        $queue = array_slice($queue, -1000);
    }

    update_option('wpi_tool_queue', $queue, false);

    // Also track tool chain (session grouping)
    wpilot_update_tool_chain($session_id, $tool, $is_error, $duration_ms, $source);

    // Schedule flush every 2 hours
    if (!wp_next_scheduled('wpi_flush_tool_queue')) {
        wp_schedule_single_event(time() + 7200, 'wpi_flush_tool_queue');
    }
}

/**
 * Get or create a session ID for grouping tool calls.
 * New session every 30 minutes of inactivity.
 */
function wpilot_tool_session_id() {
    $session = get_transient('wpi_tool_session');
    if ($session) return $session;

    $session = 'ts_' . bin2hex(random_bytes(12));
    set_transient('wpi_tool_session', $session, 1800); // 30 min
    // Reset sequence counter
    set_transient('wpi_tool_seq', 0, 1800);
    return $session;
}

/**
 * Increment and return the sequence number within current session.
 */
function wpilot_tool_seq_increment() {
    $seq = intval(get_transient('wpi_tool_seq') ?: 0);
    $seq++;
    set_transient('wpi_tool_seq', $seq, 1800);
    return $seq;
}

/**
 * Track tool chains — ordered sequences of tools in a session.
 */
function wpilot_update_tool_chain($session_id, $tool, $is_error, $duration_ms, $source) {
    $chains = get_option('wpi_tool_chains', []);

    if (!isset($chains[$session_id])) {
        $chains[$session_id] = [
            'session_id'    => $session_id,
            'source'        => $source,
            'tools'         => [],
            'total_ms'      => 0,
            'success_count' => 0,
            'error_count'   => 0,
            'builder'       => function_exists('wpilot_primary_builder') ? wpilot_primary_builder() : '',
            'started'       => time(),
        ];
    }

    $chains[$session_id]['tools'][]       = $tool;
    $chains[$session_id]['total_ms']     += $duration_ms;
    $chains[$session_id]['success_count']+= $is_error ? 0 : 1;
    $chains[$session_id]['error_count']  += $is_error ? 1 : 0;

    // Keep max 50 chains
    if (count($chains) > 50) {
        // Remove oldest
        uasort($chains, fn($a, $b) => ($a['started'] ?? 0) <=> ($b['started'] ?? 0));
        $chains = array_slice($chains, -50, null, true);
    }

    update_option('wpi_tool_chains', $chains, false);
}

/**
 * Flush tool usage data
 * Runs every 2 hours via WP cron.
 */
add_action('wpi_flush_tool_queue', 'wpilot_flush_tool_queue');

function wpilot_flush_tool_queue() {
    // Consent required
    if (function_exists('wpilot_has_consent') && !wpilot_has_consent()) return;

    $queue  = get_option('wpi_tool_queue', []);
    $chains = get_option('wpi_tool_chains', []);

    if (empty($queue) && empty($chains)) return;

    // Anonymized site identifier
    $site_hash = md5(get_site_url());

    $payload = [
        'site_hash'      => $site_hash,
        'plugin_version' => defined('CA_VERSION') ? CA_VERSION : '?',
        'tools'          => array_slice($queue, 0, 200),
        'chains'         => array_values($chains),
    ];

    $response = wp_remote_post(WPI_TOOL_TRAINING_ENDPOINT, [
        'timeout'  => 15,
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode($payload),
    ]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        // Clear sent data
        $remaining = array_slice($queue, 200);
        update_option('wpi_tool_queue', $remaining, false);
        update_option('wpi_tool_chains', [], false);

        // Update stats
        $stats = get_option('wpi_tool_stats', ['total_sent' => 0, 'batches' => 0, 'last' => '']);
        $stats['total_sent'] += min(count($queue), 200);
        $stats['batches']++;
        $stats['last'] = date('Y-m-d H:i:s');
        update_option('wpi_tool_stats', $stats);
    }
}

/**
 * Get tool training stats.
 */
function wpilot_tool_training_stats() {
    $queue  = get_option('wpi_tool_queue', []);
    $chains = get_option('wpi_tool_chains', []);
    $stats  = get_option('wpi_tool_stats', ['total_sent' => 0, 'batches' => 0, 'last' => '—']);

    return [
        'queued'      => count($queue),
        'chains'      => count($chains),
        'total_sent'  => $stats['total_sent'] ?? 0,
        'batches'     => $stats['batches'] ?? 0,
        'last_flush'  => $stats['last'] ?? '—',
    ];
}
