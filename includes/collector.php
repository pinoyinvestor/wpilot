<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPILOT COLLECTOR v2.0 — Training Data Pipeline
//
//  Passive signal collection:
//  • Every Claude exchange is queued with WordPress context
//  • User actions (Apply/Undo/Follow-up) auto-rate quality
//  • Only high-rated pairs (4-5★) sent to VPS for training
//  • GDPR: consent required, fully anonymized, no personal data
//
//  Training pipeline on VPS:
//  1. Pairs accumulate in MySQL on weblease.se
//  2. At 50k pairs → fine-tune Llama 3.3 (run_training.py)
//  3. Deploy trained model → weblease.se/ai-query
//  4. WPilot routes to own model instead of Claude
// ═══════════════════════════════════════════════════════════════

define( 'WPI_COLLECTOR_ENDPOINT', 'https://weblease.se/ai-training/ingest' );
define( 'WPI_COLLECTOR_VERSION',  '2' );

// ── Consent ───────────────────────────────────────────────────
function wpilot_has_consent() {
    return get_option( 'wpi_data_consent', 'no' ) === 'yes';
}

// ── Anonymize — strip all PII ─────────────────────────────────
function wpilot_anonymize( $text ) {
    $text = preg_replace( '/https?:\/\/[^\s"\'<>]+/', '[URL]', $text );
    $text = preg_replace( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $text );
    $text = preg_replace( '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP]', $text );
    $site = get_bloginfo('name');
    if ( $site ) $text = str_ireplace( $site, '[SITE]', $text );
    $text = preg_replace( '/\b[a-z0-9\-]{3,}\.(se|com|net|org|io|dev|co\.uk)\b/i', '[DOMAIN]', $text );
    return trim( $text );
}

// ── Auto-rate exchange quality based on signals ───────────────
function wpilot_auto_rate( $question, $answer, $tools_used = [] ) {
    $rating = 3; // default
    // Boost if tools were actually used (actionable response)
    if ( !empty($tools_used) ) $rating = 4;
    // Boost if answer is detailed enough
    if ( strlen($answer) > 200 ) $rating = max($rating, 4);
    // Boost if answer contains action cards
    if ( preg_match_all('/\[ACTION:/', $answer) >= 2 ) $rating = 5;
    // Penalize very short answers
    if ( strlen($answer) < 50 ) $rating = min($rating, 2);
    // Penalize if answer is just asking questions
    if ( substr_count($answer, '?') >= 3 && strlen($answer) < 300 ) $rating = min($rating, 2);
    return $rating;
}

// ── Collect one exchange ──────────────────────────────────────
// Called from ajax.php after every Claude response
function wpilot_collect_exchange( $question, $answer, $mode = 'chat', $rating = 3, $extra = [] ) {
    if ( ! wpilot_has_consent() ) return null;

    $q = wpilot_anonymize( $question );
    $a = wpilot_anonymize( $answer );

    if ( strlen($q) < 10 || strlen($a) < 20 ) return null;

    $id = 'wpi_' . uniqid();

    $pair = [
        'id'      => $id,
        'q'       => $q,
        'a'       => $a,
        'rating'  => $rating,  // 1-5, updated by user signals
        'mode'    => $mode,
        'topic'   => wpilot_classify_topic( $q . ' ' . $a ),
        'builder' => function_exists('wpilot_primary_builder') ? wpilot_primary_builder() : 'unknown',
        'ctx'     => wpilot_collect_context(),
        'ts'      => time(),
        'v'       => WPI_COLLECTOR_VERSION,
        'sent'    => false,
        'response_length' => strlen($answer),
    ];

    // Merge allowed extra fields
    $pair = array_merge($pair, array_intersect_key($extra, array_flip([
        'tools_used', 'actions_count', 'conversation_depth', 'site_type', 'source'
    ])));

    // Store in queue — indexed by ID for easy rating updates
    $queue       = get_option( 'wpi_collect_queue', [] );
    $queue[$id]  = $pair;

    // Keep max 500 pairs locally
    if ( count($queue) > 500 ) {
        // Remove oldest sent pairs first, then oldest unsent
        uasort($queue, fn($a,$b) => $a['ts'] <=> $b['ts']);
        $queue = array_slice($queue, -500, null, true);
    }

    update_option( 'wpi_collect_queue', $queue, false );

    // Schedule flush if not already
    if ( ! wp_next_scheduled('wpi_flush_queue') ) {
        wp_schedule_single_event( time() + 3600, 'wpi_flush_queue' );
    }

    return $id; // Return ID so JS can update rating later
}

// ── Update rating from user signal ───────────────────────────
// Called when user clicks Apply, Undo, or sends follow-up
function wpilot_rate_exchange( $id, $rating ) {
    if ( ! $id ) return;
    $queue = get_option( 'wpi_collect_queue', [] );
    if ( isset($queue[$id]) ) {
        $queue[$id]['rating'] = max(1, min(5, (int)$rating));
        update_option( 'wpi_collect_queue', $queue, false );
    }
}

// ── Collect WordPress context (no PII) ───────────────────────
function wpilot_collect_context() {
    $plugins = get_option('active_plugins', []);
    // Only slugs, no paths
    $slugs = array_map(function($p) {
        return explode('/', $p)[0];
    }, $plugins);

    return [
        'wp'       => get_bloginfo('version'),
        'plugins'  => array_values(array_slice($slugs, 0, 20)), // max 20
        'theme'    => get_option('stylesheet'),
        'lang'     => wpilot_get_lang(),
        'type'     => function_exists('wpilot_detect_site_type') ? wpilot_detect_site_type() : 'unknown',
        'builder'  => function_exists('wpilot_detect_all_builders') ? wpilot_detect_all_builders() : [],
    ];
}

// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Classify WordPress topic ──────────────────────────────────
function wpilot_classify_topic( $text ) {
    $text = strtolower($text);
    $topics = [
        'css_design'  => '/css|style|design|color|font|layout|responsive|mobile|spacing|margin|padding/',
        'seo'         => '/seo|meta|title|description|ranking|keyword|sitemap|schema|og:/',
        'woocommerce' => '/woocommerce|product|shop|cart|checkout|order|payment|shipping|stock/',
        'elementor'   => '/elementor|widget|section|column|page builder/',
        'divi'        => '/divi|et_pb|divi builder/',
        'plugins'     => '/plugin|activate|deactivate|conflict|install/',
        'build'       => '/page|post|create|build|section|menu|nav|block|gutenberg/',
        'media'       => '/image|media|alt|photo|upload|attachment|gallery/',
        'performance' => '/speed|performance|cache|load|optimize|pagespeed|core web/',
        'security'    => '/security|backup|restore|hack|malware|firewall|ssl/',
        'booking'     => '/booking|appointment|amelia|calendar|schedule/',
        'membership'  => '/membership|memberpress|restrict|subscription|user role/',
        'forms'       => '/form|gravity|wpforms|contact|submit|field/',
        'email'       => '/email|smtp|mailchimp|newsletter|notification/',
    ];
    foreach ($topics as $topic => $pattern) {
        if (preg_match($pattern, $text)) return $topic;
    }
    return 'general';
}

// ── Flush high-quality pairs to VPS ──────────────────────────
add_action( 'wpi_flush_queue', 'wpilot_flush_collect_queue' );

function wpilot_flush_collect_queue() {
    if ( ! wpilot_has_consent() ) return;

    $queue = get_option( 'wpi_collect_queue', [] );
    if ( empty($queue) ) return;

    // Only send pairs rated 4 or 5 — these are training-worthy
    $good = array_filter($queue, fn($p) => ($p['rating'] ?? 3) >= 4 && !($p['sent'] ?? false));

    if ( empty($good) ) return;

    $response = wp_remote_post( WPI_COLLECTOR_ENDPOINT, [
        'timeout' => 20,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'plugin_version' => CA_VERSION,
            'batch'          => array_values($good),
            'hash'           => hash_hmac('sha256', CA_VERSION . count($good), get_option('ca_license_key','')),
        ]),
    ]);

    if ( ! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 ) {
        // Mark as sent
        foreach ($good as $id => $_) {
            if (isset($queue[$id])) $queue[$id]['sent'] = true;
        }
        update_option( 'wpi_collect_queue', $queue, false );

        $stats           = get_option( 'wpi_collect_stats', ['total'=>0,'good'=>0,'batches'=>0,'last'=>''] );
        $stats['total'] += count($queue);
        $stats['good']  += count($good);
        $stats['batches']++;
        $stats['last']   = current_time('mysql');
        update_option( 'wpi_collect_stats', $stats );
    }
}

// ── AJAX: rate a pair from JS ─────────────────────────────────
add_action( 'wp_ajax_wpi_rate_pair', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    $id     = sanitize_text_field( $_POST['pair_id'] ?? '' );
    $rating = (int)( $_POST['rating'] ?? 3 );
    wpilot_rate_exchange( $id, $rating );
    wp_send_json_success();
});

// ── AJAX: manual flush ────────────────────────────────────────
add_action( 'wp_ajax_wpi_flush_now', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    wpilot_flush_collect_queue();
    wp_send_json_success( get_option('wpi_collect_stats', []) );
});

// ── AJAX: consent ─────────────────────────────────────────────
add_action( 'wp_ajax_wpi_set_consent', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    $consent = sanitize_text_field( $_POST['consent'] ?? 'no' );
    update_option( 'wpi_data_consent', $consent === 'yes' ? 'yes' : 'no' );
    if ( $consent !== 'yes' ) update_option( 'wpi_collect_queue', [] );
    wp_send_json_success();
});

// ── Stats ─────────────────────────────────────────────────────
function wpilot_collector_stats() {
    $queue = get_option( 'wpi_collect_queue', [] );
    $good  = count(array_filter($queue, fn($p) => ($p['rating']??3) >= 4 && !($p['sent']??false)));
    return [
        'consent' => wpilot_has_consent(),
        'queued'  => count($queue),
        'unsent_good' => $good,
        'stats'   => get_option( 'wpi_collect_stats', ['total'=>0,'good'=>0,'batches'=>0,'last'=>'—'] ),
    ];
}
