<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  DATA PREP — Prepares WPilot data for Llama fine-tuning
//
//  Three data sources combined:
//  1. Brain memories (site-specific, high confidence)
//  2. Collector queue (anonymized exchanges, rated by signals)
//  3. Shadow test results (WPilot AI vs Claude comparisons)
//
//  All three get enriched with:
//  - Intent classification (how-to, fix, create, explain, optimize)
//  - Difficulty score (beginner/intermediate/advanced)
//  - WordPress-specific tags
//  - Quality score (0-100)
//
//  Export format: ChatML JSONL — works directly with:
//  LLaMA-Factory, Axolotl, Unsloth, Hugging Face TRL
// ═══════════════════════════════════════════════════════════════

// ── Classify intent of a question ────────────────────────────
function wpilot_classify_intent( $text ) {
    $text = strtolower($text);
    if ( preg_match('/hur.*(skapar|bygger|lägger till|add|create|make|build)/u', $text) ) return 'how_to_create';
    if ( preg_match('/hur.*(fixar|löser|åtgärdar|fix|solve|repair)/u', $text) )           return 'how_to_fix';
    if ( preg_match('/optimera|snabba|förbättra|optimize|improve|speed/u', $text) )        return 'optimize';
    if ( preg_match('/vad är|vad betyder|explain|what is|förklara/u', $text) )             return 'explain';
    if ( preg_match('/ändra|byt|uppdatera|change|update|modify/u', $text) )               return 'modify';
    if ( preg_match('/ta bort|radera|delete|remove/u', $text) )                           return 'delete';
    if ( preg_match('/backup|restore|återställ/u', $text) )                               return 'safety';
    if ( preg_match('/säkerhet|security|hack|malware/u', $text) )                         return 'security';
    return 'general';
}

// ── Estimate difficulty ───────────────────────────────────────
function wpilot_classify_difficulty( $text ) {
    $text = strtolower($text);
    // Advanced: code, hooks, filters, database, custom post types
    if ( preg_match('/function|hook|filter|action|php|mysql|rest api|custom post type|meta box|shortcode|wp_query/i', $text) )
        return 'advanced';
    // Intermediate: specific plugin settings, CSS, performance
    if ( preg_match('/elementor|divi|woocommerce|css|cache|cron|redirect|htaccess|nginx/i', $text) )
        return 'intermediate';
    return 'beginner';
}

// ── Score quality of a training pair ────────────────────────
function wpilot_score_pair_quality( $question, $answer ) {
    $score = 50; // base

    // Good signals
    if ( strlen($question) > 30 ) $score += 10;  // specific question
    if ( strlen($answer)   > 100) $score += 10;  // substantial answer
    if ( preg_match('/\d+/', $answer) )  $score += 5;   // contains numbers/steps
    if ( preg_match('/```|`[^`]+`/', $answer) ) $score += 10;  // has code
    if ( preg_match('/\n/', $answer) ) $score += 5;             // multi-line
    if ( preg_match('/plugin|theme|wordpress|wp-/i', $answer) ) $score += 5;  // WP-specific

    // Bad signals
    if ( strlen($answer) < 50  ) $score -= 20;  // too short
    if ( strlen($question) < 10) $score -= 20;  // too vague
    if ( preg_match('/sorry|cannot|I don\'t know/i', $answer) ) $score -= 30;  // Claude refused
    if ( preg_match('/\[URL\]|\[EMAIL\]/i', $answer) ) $score -= 5;  // heavy anonymization

    return max(0, min(100, $score));
}

// ── Export Brain memories as training pairs ──────────────────
function wpilot_export_brain_as_training( $min_confidence = 0.8, $min_use_count = 2 ) {
    global $wpdb;
    $t = $wpdb->prefix . WPI_BRAIN_TABLE;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT trigger_key, response, memory_type, confidence, use_count, context
         FROM {$t}
         WHERE confidence >= %f AND use_count >= %d AND approved = 1
         ORDER BY use_count DESC, confidence DESC
         LIMIT 1000",
        $min_confidence, $min_use_count
    ));

    $pairs = [];
    foreach ($rows as $row) {
        $q       = wpilot_anonymize($row->trigger_key);
        $a       = wpilot_anonymize($row->response);
        $quality = wpilot_score_pair_quality($q, $a);

        if ($quality < 40) continue;

        $pairs[] = [
            'source'     => 'brain',
            'question'   => $q,
            'answer'     => $a,
            'topic'      => $row->memory_type,
            'intent'     => wpilot_classify_intent($q),
            'difficulty' => wpilot_classify_difficulty($q . ' ' . $a),
            'quality'    => $quality,
            'confidence' => (float)$row->confidence,
            'use_count'  => (int)$row->use_count,
        ];
    }

    return $pairs;
}

// ── Export collector queue as training pairs ─────────────────
function wpilot_export_collector_as_training( $min_rating = 4 ) {
    $queue = get_option('wpi_collect_queue', []);
    $pairs = [];

    foreach ($queue as $item) {
        if (($item['rating'] ?? 0) < $min_rating) continue;

        $q       = $item['q'] ?? '';
        $a       = $item['a'] ?? '';
        $quality = wpilot_score_pair_quality($q, $a);

        if ($quality < 40) continue;

        $pairs[] = [
            'source'     => 'collector',
            'question'   => $q,
            'answer'     => $a,
            'topic'      => $item['topic'] ?? 'general',
            'intent'     => wpilot_classify_intent($q),
            'difficulty' => wpilot_classify_difficulty($q . ' ' . $a),
            'quality'    => $quality,
            'rating'     => (int)($item['rating'] ?? 3),
            'builder'    => $item['builder'] ?? '',
            'ctx'        => $item['ctx'] ?? [],
        ];
    }

    return $pairs;
}

// ── Combine all sources and deduplicate ───────────────────────
function wpilot_get_all_training_pairs() {
    $brain     = wpilot_export_brain_as_training();
    $collector = wpilot_export_collector_as_training();

    $all = array_merge($brain, $collector);

    // Deduplicate by question similarity (simple hash)
    $seen = [];
    $deduped = [];
    foreach ($all as $pair) {
        // Normalize question for dedup
        $key = md5(strtolower(preg_replace('/\s+/', ' ', trim($pair['question']))));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $deduped[]  = $pair;
        }
    }

    // Sort by quality descending
    usort($deduped, fn($a,$b) => ($b['quality'] ?? 0) <=> ($a['quality'] ?? 0));

    return $deduped;
}

// ── Format single pair as ChatML for Llama ───────────────────
function wpilot_format_as_chatml( $pair, $site_context = [] ) {
    $system = "Du är WPilot, en senior WordPress-konsult byggd av Weblease. ";
    $system .= "Du hjälper WordPress-användare att bygga, optimera och förbättra sina sajter. ";
    $system .= "Du kan Elementor, Divi, WooCommerce, SEO, prestanda, säkerhet och alla stora WordPress-plugins. ";
    $system .= "Var alltid ärlig om kostnader (gratis vs betalda plugins). Fråga alltid innan du gör ändringar.";

    // Add context hints if available
    if (!empty($site_context['type'])) {
        $system .= " Sajten är av typen: {$site_context['type']}.";
    }
    if (!empty($pair['builder'])) {
        $system .= " Aktiv sidbyggare: {$pair['builder']}.";
    }

    return [
        'messages' => [
            ['role' => 'system',    'content' => $system],
            ['role' => 'user',      'content' => $pair['question']],
            ['role' => 'assistant', 'content' => $pair['answer']],
        ],
        '_meta' => [
            'source'     => $pair['source'] ?? 'unknown',
            'topic'      => $pair['topic'] ?? 'general',
            'intent'     => $pair['intent'] ?? 'general',
            'difficulty' => $pair['difficulty'] ?? 'beginner',
            'quality'    => $pair['quality'] ?? 50,
        ]
    ];
}

// ── AJAX: get training data stats ────────────────────────────
add_action( 'wp_ajax_wpi_training_stats', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);

    $all     = wpilot_get_all_training_pairs();
    $by_topic = [];
    $by_intent = [];
    $by_diff = ['beginner'=>0,'intermediate'=>0,'advanced'=>0];

    foreach ($all as $p) {
        $t = $p['topic'] ?? 'general';
        $i = $p['intent'] ?? 'general';
        $d = $p['difficulty'] ?? 'beginner';
        $by_topic[$t]  = ($by_topic[$t] ?? 0) + 1;
        $by_intent[$i] = ($by_intent[$i] ?? 0) + 1;
        $by_diff[$d]   = ($by_diff[$d] ?? 0) + 1;
    }

    arsort($by_topic);

    $target = 50000;
    $count  = count($all);
    $pct    = min(100, round($count / $target * 100, 1));

    // Readiness milestones
    $milestones = [
        ['pairs' => 1000,  'model' => 'Llama 3.2 3B',  'label' => 'Liten modell — enkel WordPress-hjälp'],
        ['pairs' => 10000, 'model' => 'Llama 3.2 7B',  'label' => 'Medelmodell — täcker 60% av frågor'],
        ['pairs' => 50000, 'model' => 'Llama 3.3 70B', 'label' => 'Fullstark modell — ersätter Claude'],
    ];

    $next_milestone = null;
    foreach ($milestones as $m) {
        if ($count < $m['pairs']) {
            $next_milestone = $m;
            $next_milestone['remaining'] = $m['pairs'] - $count;
            break;
        }
    }

    wp_send_json_success([
        'total'          => $count,
        'target'         => $target,
        'pct'            => $pct,
        'by_topic'       => $by_topic,
        'by_intent'      => $by_intent,
        'by_difficulty'  => $by_diff,
        'next_milestone' => $next_milestone,
        'avg_quality'    => $count > 0 ? round(array_sum(array_column($all, 'quality')) / $count) : 0,
        'ready_to_train' => $count >= 1000,
        'collector'      => wpilot_collector_stats(),
        'route_stats'    => wpilot_route_stats(),
    ]);
});

// ── AJAX: export JSONL for download/VPS send ─────────────────
add_action( 'wp_ajax_wpi_export_training', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);

    $pairs  = wpilot_get_all_training_pairs();
    $min_q  = (int)($_POST['min_quality'] ?? 50);
    $pairs  = array_filter($pairs, fn($p) => ($p['quality'] ?? 0) >= $min_q);

    $jsonl  = '';
    foreach ($pairs as $pair) {
        $formatted = wpilot_format_as_chatml($pair);
        unset($formatted['_meta']); // remove meta from actual training file
        $jsonl .= wp_json_encode($formatted, JSON_UNESCAPED_UNICODE) . "\n";
    }

    wp_send_json_success([
        'jsonl'  => $jsonl,
        'count'  => count($pairs),
        'bytes'  => strlen($jsonl),
    ]);
});

// ── AJAX: send batch to VPS directly ─────────────────────────
add_action( 'wp_ajax_wpi_push_to_vps', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);

    $pairs = wpilot_get_all_training_pairs();
    $pairs = array_filter($pairs, fn($p) => ($p['quality'] ?? 0) >= 50);

    if (empty($pairs)) {
        wp_send_json_error('No quality pairs to send yet.');
    }

    $response = wp_remote_post( WPI_COLLECTOR_ENDPOINT, [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'plugin_version' => CA_VERSION,
            'batch'          => array_values($pairs),
            'hash'           => hash_hmac('sha256', CA_VERSION . count($pairs), get_option('ca_license_key','')),
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200) {
        wp_send_json_success(['sent' => count($pairs), 'vps' => $body]);
    } else {
        wp_send_json_error("VPS returned HTTP $code");
    }
});
