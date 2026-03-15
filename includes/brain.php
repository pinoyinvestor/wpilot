<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  AI BRAIN — Site-specific learning layer
//
//  Concept:
//  1. Every approved action + conversation is logged as a "memory"
//  2. Memories are grouped: preferences, solutions, patterns
//  3. On each new request, the Brain answers first (from memory)
//  4. If confidence < threshold → Claude is called instead
//  5. When Claude answers something new → Brain stores it
//  6. Over time the Brain handles more and more on its own
// ═══════════════════════════════════════════════════════════════

define( 'WPI_BRAIN_TABLE',      'wpi_brain_memories' );
define( 'WPI_CONFIDENCE_MIN',   0.72 );   // min score to answer without Claude
define( 'WPI_MEMORY_LIMIT',     500  );   // max stored memories

// ── Install brain DB table ─────────────────────────────────────
function wpilot_brain_install() {
    global $wpdb;
    $t    = $wpdb->prefix . WPI_BRAIN_TABLE;
    $cs   = $wpdb->get_charset_collate();
    $sql  = "CREATE TABLE IF NOT EXISTS {$t} (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        memory_type VARCHAR(40)  NOT NULL DEFAULT 'general',
        trigger_key TEXT         NOT NULL,
        response    LONGTEXT     NOT NULL,
        context     TEXT         DEFAULT NULL,
        confidence  FLOAT        NOT NULL DEFAULT 1.0,
        use_count   INT UNSIGNED NOT NULL DEFAULT 0,
        approved    TINYINT(1)   NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (memory_type),
        INDEX idx_confidence (confidence),
        INDEX idx_approved (approved)
    ) {$cs};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'init', function() {
    if ( get_option('wpi_brain_version') !== '1.0' ) {
        wpilot_brain_install();
        update_option( 'wpi_brain_version', '1.0' );
    }
});

// ── Save a memory ──────────────────────────────────────────────
function wpilot_brain_remember( $type, $trigger, $response, $context = '', $confidence = 1.0, $approved = 0 ) {
    global $wpdb;
    $t = $wpdb->prefix . WPI_BRAIN_TABLE;

    // Don't exceed limit — prune oldest low-confidence memories
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
    if ( $count >= WPI_MEMORY_LIMIT ) {
        $wpdb->query("DELETE FROM {$t} WHERE approved=0 AND confidence < 0.5 ORDER BY use_count ASC, created_at ASC LIMIT 20");
    }

    // Check if similar memory already exists → update instead
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, use_count FROM {$t} WHERE memory_type=%s AND trigger_key=%s LIMIT 1",
        $type, $trigger
    ) );

    if ( $existing ) {
        $wpdb->update( $t, [
            'response'   => $response,
            'context'    => $context,
            'confidence' => min( 1.0, $confidence + 0.05 ),  // gets more confident with reuse
            'use_count'  => $existing->use_count + 1,
            'approved'   => $approved ? 1 : $existing->approved,
            'updated_at' => current_time('mysql'),
        ], [ 'id' => $existing->id ] );
        return $existing->id;
    }

    $wpdb->insert( $t, [
        'memory_type' => $type,
        'trigger_key' => $trigger,
        'response'    => $response,
        'context'     => $context,
        'confidence'  => $confidence,
        'approved'    => $approved ? 1 : 0,
        'created_at'  => current_time('mysql'),
        'updated_at'  => current_time('mysql'),
    ] );
    return $wpdb->insert_id;
}

// ── Mark a memory as approved (user clicked Apply) ────────────
function wpilot_brain_approve( $memory_id ) {
    global $wpdb;
    $t = $wpdb->prefix . WPI_BRAIN_TABLE;
    $wpdb->update( $t, [
        'approved'   => 1,
        'confidence' => 1.0,
        'use_count'  => $wpdb->get_var($wpdb->prepare("SELECT use_count+1 FROM {$t} WHERE id=%d", $memory_id)),
    ], [ 'id' => $memory_id ] );
}

// ── Find best matching memory for a query ─────────────────────
function wpilot_brain_recall( $query, $type = null, $min_confidence = WPI_CONFIDENCE_MIN ) {
    global $wpdb;
    $t     = $wpdb->prefix . WPI_BRAIN_TABLE;
    $words = wpilot_brain_keywords( $query );
    if ( empty( $words ) ) return null;

    $where   = "confidence >= %f AND approved = 1";
    $params  = [ $min_confidence ];

    if ( $type ) {
        $where  .= " AND memory_type = %s";
        $params[] = $type;
    }

    $memories = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t} WHERE {$where} ORDER BY confidence DESC, use_count DESC LIMIT 50",
        ...$params
    ) );

    if ( empty( $memories ) ) return null;

    $best_score  = 0;
    $best_memory = null;

    foreach ( $memories as $m ) {
        $score = wpilot_brain_similarity( $query, $m->trigger_key, $words );
        if ( $score > $best_score ) {
            $best_score  = $score;
            $best_memory = $m;
        }
    }

    // Only return if similarity is strong enough
    if ( $best_score < 0.45 ) return null;

    // Increment use counter
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$t} SET use_count = use_count+1 WHERE id = %d",
        $best_memory->id
    ) );

    $best_memory->match_score = round( $best_score, 3 );
// Built by Christos Ferlachidis & Daniel Hedenberg
    return $best_memory;
}

// ── Keyword extraction ─────────────────────────────────────────
function wpilot_brain_keywords( $text ) {
    $stop = ['i','a','the','to','of','is','it','in','on','and','or','for','my','me','do',
             'be','are','was','has','have','this','that','with','what','how','can','we',
             'du','jag','vi','är','har','att','och','det','den','en','ett','av','för','med',
             'på','som','om','inte','vill','kan','ska','så'];
    $words = preg_split('/\s+/', strtolower( preg_replace('/[^\p{L}\p{N} ]/u', ' ', $text) ) );
    return array_values( array_unique( array_filter( $words, fn($w) => strlen($w) > 2 && !in_array($w,$stop) ) ) );
}

// ── Cosine-like keyword similarity ────────────────────────────
function wpilot_brain_similarity( $query, $trigger, $query_words = null ) {
    $qw = $query_words ?? wpilot_brain_keywords( $query );
    $tw = wpilot_brain_keywords( $trigger );
    if ( empty($qw) || empty($tw) ) return 0;

    $intersection = count( array_intersect( $qw, $tw ) );
    $union        = count( array_unique( array_merge( $qw, $tw ) ) );
    $jaccard      = $union > 0 ? $intersection / $union : 0;

    // Boost if query is substring of trigger or vice versa
    $boost = 0;
    if ( stripos( $trigger, $query ) !== false || stripos( $query, $trigger ) !== false ) $boost = 0.2;

    return min( 1.0, $jaccard + $boost );
}

// ── Learn from a Claude conversation exchange ──────────────────
function wpilot_brain_learn_from_exchange( $question, $answer, $mode = 'chat', $approved = false ) {
    // Determine memory type
    $type = 'chat';
    if ( stripos($question,'css') !== false || stripos($question,'design') !== false || stripos($question,'style') !== false ) $type = 'css';
    elseif ( stripos($question,'seo') !== false || stripos($question,'meta') !== false || stripos($question,'title') !== false ) $type = 'seo';
    elseif ( stripos($question,'page') !== false || stripos($question,'build') !== false || stripos($question,'create') !== false ) $type = 'build';
    elseif ( stripos($question,'plugin') !== false ) $type = 'plugin';
    elseif ( stripos($question,'woo') !== false || stripos($question,'product') !== false ) $type = 'woo';
    elseif ( stripos($question,'image') !== false || stripos($question,'alt') !== false || stripos($question,'media') !== false ) $type = 'media';
    elseif ( $mode !== 'chat' ) $type = $mode;

    // Confidence: 0.7 by default, 1.0 if user approved
    $confidence = $approved ? 1.0 : 0.70;

    return wpilot_brain_remember( $type, $question, $answer, '', $confidence, $approved ? 1 : 0 );
}

// ── Learn a user preference ────────────────────────────────────
function wpilot_brain_learn_preference( $key, $value ) {
    global $wpdb;
    $prefs = get_option('wpi_brain_prefs', []);
    $prefs[ $key ] = [
        'value'   => $value,
        'learned' => current_time('mysql'),
        'count'   => ($prefs[$key]['count'] ?? 0) + 1,
    ];
    update_option('wpi_brain_prefs', $prefs);

    // Also store as a memory so it's included in context
    wpilot_brain_remember( 'preference', "user preference: {$key}", $value, '', 1.0, 1 );
}

// ── Get all preferences as a formatted string ─────────────────
function wpilot_brain_get_preferences() {
    $prefs = get_option('wpi_brain_prefs', []);
    if ( empty($prefs) ) return '';
    $lines = [];
    foreach ( $prefs as $k => $v ) {
        $lines[] = "- {$k}: " . (is_array($v) ? $v['value'] : $v);
    }
    return implode("\n", $lines);
}

// ── Build brain context block for Claude system prompt ────────
function wpilot_brain_context_block() {
    global $wpdb;
    $t = $wpdb->prefix . WPI_BRAIN_TABLE;

    $prefs    = wpilot_brain_get_preferences();
    $count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE approved=1");
    $top_used = $wpdb->get_results("SELECT trigger_key, response, memory_type, use_count FROM {$t} WHERE approved=1 ORDER BY use_count DESC LIMIT 10");

    if ( ! $count && ! $prefs ) return '';

    $block = "\n\n## SITE AI MEMORY — LEARNED FROM THIS SITE\n";
    $block .= "You have {$count} approved memories from working on this site. Use them to give better, site-specific answers.\n";

    if ( $prefs ) {
        $block .= "\n### Learned user preferences:\n{$prefs}\n";
    }

    if ( $top_used ) {
        $block .= "\n### Most-used solutions on this site:\n";
        foreach ( $top_used as $m ) {
            $short = mb_substr( strip_tags($m->response), 0, 120 );
            $block .= "- [{$m->memory_type}] {$m->trigger_key}: {$short}…\n";
        }
    }

    return $block;
}

// ── Stats ──────────────────────────────────────────────────────
function wpilot_brain_stats() {
    global $wpdb;
    $t = $wpdb->prefix . WPI_BRAIN_TABLE;
    return [
        'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
        'approved'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE approved=1"),
        'pending'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE approved=0"),
        'by_type'   => $wpdb->get_results("SELECT memory_type, COUNT(*) as n FROM {$t} GROUP BY memory_type ORDER BY n DESC"),
        'top'       => $wpdb->get_results("SELECT * FROM {$t} WHERE approved=1 ORDER BY use_count DESC LIMIT 5"),
        'prefs'     => get_option('wpi_brain_prefs', []),
    ];
}

// ── Get all memories (for admin view) ─────────────────────────
function wpilot_brain_get_all( $limit = 100, $offset = 0, $type = null ) {
    global $wpdb;
    $t    = $wpdb->prefix . WPI_BRAIN_TABLE;
    if ( $type ) {
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE memory_type=%s ORDER BY approved DESC, use_count DESC, updated_at DESC LIMIT %d OFFSET %d",
            $type, (int)$limit, (int)$offset
        ) );
    }
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$t} ORDER BY approved DESC, use_count DESC, updated_at DESC LIMIT %d OFFSET %d",
        (int)$limit, (int)$offset
    ) );
}

// ── Delete a memory ────────────────────────────────────────────
function wpilot_brain_forget( $id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . WPI_BRAIN_TABLE, [ 'id' => (int)$id ] );
}

// ── Wipe all memories ─────────────────────────────────────────
function wpilot_brain_reset() {
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . WPI_BRAIN_TABLE);
    delete_option('wpi_brain_prefs');
}
