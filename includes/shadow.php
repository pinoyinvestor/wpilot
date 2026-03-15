<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  SHADOW MODE — WPilot AI observes Claude silently
//
//  Every time Claude answers:
//  1. The same question is sent to WPilot AI in background
//  2. WPilot AI generates its own answer attempt
//  3. Similarity score is calculated vs Claude's answer
//  4. If similarity >= threshold → WPilot AI is "ready" for this type
//  5. Confidence builds up per topic category
//  6. When a category hits 85%+ → WPilot AI takes over for that category
//
//  The customer never sees any of this. Claude always answers.
//  The switch to WPilot AI happens silently when confidence is high.
// ═══════════════════════════════════════════════════════════════

define( 'WPI_SHADOW_THRESHOLD',   0.82 );  // similarity needed to "pass"
define( 'WPI_SHADOW_MIN_TESTS',   5    );  // min tests before takeover allowed
define( 'WPI_SHADOW_TAKEOVER',    0.85 );  // pass rate needed to take over

// ── Fire shadow test asynchronously after Claude answers ───────
function wpilot_shadow_fire( $question, $claude_answer, $topic ) {
    if ( ! wpilot_has_consent() ) return;
    if ( get_option('wpi_shadow_active','yes') !== 'yes' ) return;

    // Schedule as async wp-cron so it doesn't slow down the response
    wp_schedule_single_event( time() + 2, 'wpi_shadow_test_event', [
        $question, $claude_answer, $topic
    ]);
}

// ── The actual shadow test (runs in background) ────────────────
add_action( 'wpi_shadow_test_event', 'wpilot_run_shadow_test', 10, 3 );

function wpilot_run_shadow_test( $question, $claude_answer, $topic ) {
    $license = get_option('ca_license_key','');

    // Query WPilot AI with the same question
    $response = wp_remote_post( WPI_WEBLEAS_ENDPOINT, [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'q'      => wpilot_anonymize( $question ),
            'mode'   => 'shadow',
            'lang'   => wpilot_get_lang(),
            'license'=> md5( $license . 'wai1' ),
            'v'      => CA_VERSION,
        ]),
    ]);

    if ( is_wp_error($response) ) return;
    if ( wp_remote_retrieve_response_code($response) !== 200 ) return;

    $body = json_decode( wp_remote_retrieve_body($response), true );
    if ( empty($body['answer']) ) return;

    $wai_answer = $body['answer'];

    // Calculate similarity between WPilot AI answer and Claude answer
    $similarity = wpilot_answer_similarity( $claude_answer, $wai_answer );

    // Log this shadow test result
    wpilot_shadow_log( $topic, $similarity, $question );

    // Store the comparison for admin review
    $tests = get_option('wpi_shadow_tests', []);
    $tests[] = [
        'topic'      => $topic,
        'question'   => mb_substr( wpilot_anonymize($question), 0, 120 ),
        'similarity' => round($similarity, 3),
        'passed'     => $similarity >= WPI_SHADOW_THRESHOLD ? 1 : 0,
        'ts'         => time(),
    ];
    // Keep last 500 tests
    if ( count($tests) > 500 ) $tests = array_slice($tests, -500);
    update_option('wpi_shadow_tests', $tests);

    // Update topic readiness
    wpilot_shadow_update_readiness( $topic );
}

// ── Calculate similarity between two text answers ─────────────
function wpilot_answer_similarity( $text_a, $text_b ) {
    // Normalize both texts
    $a = strtolower( preg_replace('/\s+/',' ', strip_tags($text_a)) );
    $b = strtolower( preg_replace('/\s+/',' ', strip_tags($text_b)) );

    // Word overlap (Jaccard)
    $wa = array_unique( str_word_count($a, 1) );
    $wb = array_unique( str_word_count($b, 1) );

    // Remove stop words
    $stop = ['the','a','an','is','it','in','on','and','or','to','of','for','be','are','was'];
    $wa = array_diff($wa, $stop);
    $wb = array_diff($wb, $stop);

    if ( empty($wa) || empty($wb) ) return 0;

    $intersection = count( array_intersect($wa, $wb) );
    $union        = count( array_unique( array_merge($wa, $wb) ) );
    $jaccard      = $union > 0 ? $intersection / $union : 0;

    // Length similarity bonus (similar length = similar depth)
    $len_a = strlen($a);
    $len_b = strlen($b);
    $len_ratio = $len_a > 0 ? min($len_a,$len_b) / max($len_a,$len_b) : 0;

    // Weighted score
    return round( ($jaccard * 0.7) + ($len_ratio * 0.3), 4 );
}

// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Log result per topic ───────────────────────────────────────
function wpilot_shadow_log( $topic, $similarity, $question ) {
    $log = get_option('wpilot_shadow_log', []);
    if ( !isset($log[$topic]) ) {
        $log[$topic] = ['tests'=>0,'passed'=>0,'total_sim'=>0,'ready'=>false];
    }
    $log[$topic]['tests']     += 1;
    $log[$topic]['total_sim'] += $similarity;
    if ( $similarity >= WPI_SHADOW_THRESHOLD ) $log[$topic]['passed'] += 1;
    $log[$topic]['avg_sim']    = round( $log[$topic]['total_sim'] / $log[$topic]['tests'], 3 );
    $log[$topic]['pass_rate']  = round( $log[$topic]['passed'] / $log[$topic]['tests'], 3 );
    $log[$topic]['ready']      = (
        $log[$topic]['tests'] >= WPI_SHADOW_MIN_TESTS &&
        $log[$topic]['pass_rate'] >= WPI_SHADOW_TAKEOVER
    );
    $log[$topic]['last']       = current_time('mysql');
    update_option('wpilot_shadow_log', $log);
}

// ── Recalculate readiness for a topic ─────────────────────────
function wpilot_shadow_update_readiness( $topic ) {
    $log = get_option('wpilot_shadow_log', []);
    if ( empty($log[$topic]) ) return;

    $t = $log[$topic];
    if ( $t['tests'] >= WPI_SHADOW_MIN_TESTS && $t['pass_rate'] >= WPI_SHADOW_TAKEOVER ) {
        // Mark this topic as "WPilot AI ready" — routing will use WAI for it
        $ready = get_option('wpi_wai_ready_topics', []);
        if ( !in_array($topic, $ready) ) {
            $ready[] = $topic;
            update_option('wpi_wai_ready_topics', $ready);

            // Log the milestone
            $milestones   = get_option('wpi_shadow_milestones', []);
            $milestones[] = [
                'topic'     => $topic,
                'achieved'  => current_time('mysql'),
                'tests'     => $t['tests'],
                'pass_rate' => $t['pass_rate'],
            ];
            update_option('wpi_shadow_milestones', $milestones);
        }
    }
}

// ── Check if WPilot AI is ready for a given topic ─────────────
function wpilot_wai_ready_for( $topic ) {
    if ( get_option('wpi_webleas_active','yes') !== 'yes' ) return false;
    $ready = get_option('wpi_wai_ready_topics', []);
    return in_array( $topic, $ready );
}

// ── Get full shadow stats for admin page ─────────────────────
function wpilot_shadow_stats() {
    $log        = get_option('wpilot_shadow_log', []);
    $milestones = get_option('wpi_shadow_milestones', []);
    $ready      = get_option('wpi_wai_ready_topics', []);
    $tests      = get_option('wpi_shadow_tests', []);

    $total_tests  = array_sum( array_column($log, 'tests') );
    $total_passed = array_sum( array_column($log, 'passed') );
    $overall_rate = $total_tests > 0 ? round($total_passed/$total_tests*100) : 0;

    return [
        'by_topic'     => $log,
        'ready_topics' => $ready,
        'milestones'   => $milestones,
        'recent_tests' => array_slice($tests, -20),
        'total_tests'  => $total_tests,
        'total_passed' => $total_passed,
        'overall_rate' => $overall_rate,
        'topics_ready' => count($ready),
        'topics_total' => count($log),
    ];
}

// ── Admin AJAX: get shadow stats ───────────────────────────────
add_action('wp_ajax_wpi_shadow_stats', function() {
    check_ajax_referer('ca_nonce','nonce');
    if ( !current_user_can('manage_options') ) wp_send_json_error();
    wp_send_json_success( wpilot_shadow_stats() );
});

// ── Admin AJAX: toggle shadow mode ────────────────────────────
add_action('wp_ajax_wpi_toggle_shadow', function() {
    check_ajax_referer('ca_nonce','nonce');
    if ( !current_user_can('manage_options') ) wp_send_json_error();
    $v = sanitize_text_field($_POST['active'] ?? 'yes');
    update_option('wpi_shadow_active', $v === 'yes' ? 'yes' : 'no');
    wp_send_json_success();
});

// ── Admin AJAX: reset a topic (force re-test) ─────────────────
add_action('wp_ajax_wpi_shadow_reset_topic', function() {
    check_ajax_referer('ca_nonce','nonce');
    if ( !current_user_can('manage_options') ) wp_send_json_error();
    $topic = sanitize_text_field($_POST['topic'] ?? '');
    $log   = get_option('wpilot_shadow_log', []);
    unset($log[$topic]);
    update_option('wpilot_shadow_log', $log);
    $ready = get_option('wpi_wai_ready_topics', []);
    update_option('wpi_wai_ready_topics', array_values(array_diff($ready, [$topic])));
    wp_send_json_success();
});
