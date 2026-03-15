<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WEBLEAS AI — The routing brain
//
//  This is the main decision layer that decides who answers:
//
//  Priority order:
//  1. Local Brain (site-specific memory, instant, free)
//  2. WPilot AI  (trained WordPress AI on Weblease server, cheap)
//  3. Claude     (Anthropic API, kunden betalar, always available)
//
//  Over time WPilot AI handles more and more → Claude cost → 0
// ═══════════════════════════════════════════════════════════════

// WPI_WEBLEAS_ENDPOINT defined in wpilot.php
define( 'WPI_WEBLEAS_CONFIDENCE', 0.75 ); // min confidence to skip Claude

// ── Main routing function — replaces wpilot_call_claude() ─────────
function wpilot_smart_answer( $message, $mode = 'chat', $context = [], $history = [] ) {

    $route_log = []; // track which source answered for stats

    // ── Route 1: Local Brain (site-specific, instant) ─────────
    if ( get_option('wpi_brain_active','yes') === 'yes' ) {
        $memory = wpilot_brain_recall( $message );
        if ( $memory && $memory->match_score >= WPI_CONFIDENCE_MIN ) {
            wpilot_log_route( 'brain', $memory->match_score );
            return [
                'text'       => $memory->response,
                'source'     => 'brain',
                'memory_id'  => $memory->id,
                'confidence' => $memory->match_score,
                'cost'       => 0,
            ];
        }
    }

    // ── Route 2: WPilot AI (trained model, low cost) ──────────
    // Only routes to WPilot AI for topics it has proven it can handle
    $topic_check = wpilot_classify_topic( $message );
    if ( get_option('wpi_webleas_active','yes') === 'yes' && wpilot_wai_ready_for($topic_check) ) {
        $wai = wpilot_query_webleas_ai( $message, $mode, $context );
        if ( $wai && ! is_wp_error($wai) && ($wai['confidence'] ?? 0) >= WPI_WEBLEAS_CONFIDENCE ) {
            wpilot_log_route( 'webleas', $wai['confidence'] );

            // Store in local Brain too so it improves locally
            wpilot_brain_learn_from_exchange( $message, $wai['text'], $mode, false );

            return [
                'text'       => $wai['text'],
                'source'     => 'webleas',
                'confidence' => $wai['confidence'],
                'cost'       => $wai['cost'] ?? 0.001,
            ];
        }
    }

    // ── Route 3: Claude (full power, kunden betalar) ──────────
    if ( ! wpilot_is_connected() ) {
        return new WP_Error('no_connection', 'No AI source available. Connect a Claude API key in Settings.');
    }

    $reply = wpilot_call_claude( $message, $mode, $context, $history );
    if ( is_wp_error($reply) ) return $reply;

    wpilot_log_route( 'claude', 1.0 );

    // Classify topic for shadow testing
    $topic = wpilot_classify_topic( $message . ' ' . $reply );

    // Store in Brain for future use
    $memory_id = wpilot_brain_learn_from_exchange( $message, $reply, $mode, false );

    // Queue for WPilot AI training
    wpilot_collect_exchange( $message, $reply, $mode, 3 );

    // Fire shadow test — WPilot AI silently tries the same question
    // to measure if it could have answered this without Claude
    wpilot_shadow_fire( $message, $reply, $topic );

    return [
        'text'      => $reply,
        'source'    => 'claude',
        'memory_id' => $memory_id,
        'topic'     => $topic,
        'cost'      => 'api',
    ];
}

// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Query WPilot AI server ─────────────────────────────────────
function wpilot_query_webleas_ai( $message, $mode, $context = [] ) {
    $license = get_option('ca_license_key','');
    if ( empty($license) ) return null; // WPilot AI requires Pro license

    $response = wp_remote_post( WPI_WEBLEAS_ENDPOINT, [
        'timeout' => 12,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'q'       => $message,
            'mode'    => $mode,
            'lang'    => wpilot_get_lang(),
            'builder' => wpilot_detect_builder(),
            'woo'     => class_exists('WooCommerce'),
            'license' => md5( $license . 'wai1' ),
            'v'       => CA_VERSION,
        ] ),
    ] );

    if ( is_wp_error($response) )    return null;
    if ( wp_remote_retrieve_response_code($response) !== 200 ) return null;

    $body = json_decode( wp_remote_retrieve_body($response), true );
    if ( empty($body['answer']) )    return null;

    return [
        'text'       => $body['answer'],
        'confidence' => (float)($body['confidence'] ?? 0),
        'cost'       => (float)($body['cost']       ?? 0),
        'model'      => $body['model'] ?? 'webleas-1',
    ];
}

// ── Log which route handled the request ───────────────────────
function wpilot_log_route( $source, $confidence ) {
    $stats = get_option('wpilot_route_stats', [
        'brain'   => 0,
        'webleas' => 0,
        'claude'  => 0,
        'total'   => 0,
    ]);
    $stats[$source] = ($stats[$source] ?? 0) + 1;
    $stats['total'] = ($stats['total'] ?? 0) + 1;
    update_option('wpilot_route_stats', $stats);
}

// ── Get routing stats ──────────────────────────────────────────
function wpilot_route_stats() {
    $s = get_option('wpilot_route_stats', ['brain'=>0,'webleas'=>0,'claude'=>0,'total'=>0]);
    $total = max(1, $s['total']);
    return [
        'brain'        => $s['brain'],
        'webleas'      => $s['webleas'],
        'claude'       => $s['claude'],
        'total'        => $s['total'],
        'brain_pct'    => round(($s['brain']   / $total) * 100),
        'webleas_pct'  => round(($s['webleas'] / $total) * 100),
        'claude_pct'   => round(($s['claude']  / $total) * 100),
        'ai_handled'   => round((($s['brain'] + $s['webleas']) / $total) * 100),
        'savings_pct'  => round((($s['brain'] + $s['webleas']) / $total) * 100),
    ];
}

// ── Calculate estimated savings ───────────────────────────────
function wpilot_estimate_savings() {
    $s           = wpilot_route_stats();
    $per_claude  = 0.02;    // avg cost per Claude call in USD
    $per_webleas = 0.002;   // WPilot AI cost (10x cheaper)
    $per_brain   = 0.0;     // Brain is free

    $saved = ($s['brain']   * $per_claude)
           + ($s['webleas'] * ($per_claude - $per_webleas));

    return [
        'saved_usd' => round($saved, 4),
        'saved_sek' => round($saved * 10.5, 2),
        'pct'       => $s['savings_pct'],
    ];
}

// ── Toggle WPilot AI on/off ────────────────────────────────────
add_action( 'wp_ajax_wpi_toggle_webleas', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error();
    $active = sanitize_text_field($_POST['active'] ?? 'no');
    update_option('wpi_webleas_active', $active === 'yes' ? 'yes' : 'no');
    wp_send_json_success();
} );
