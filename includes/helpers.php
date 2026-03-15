<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Core helpers — wrapped with function_exists to avoid conflicts ──
if ( ! function_exists( 'wpilot_is_connected' ) ) {
    function wpilot_is_connected() { return ! empty( get_option( 'ca_api_key', '' ) ); }
}
if ( ! function_exists( 'wpilot_theme' ) ) {
    function wpilot_theme() { return get_option( 'wpilot_theme', 'dark' ); }
}
if ( ! function_exists( 'wpilot_auto_approve' ) ) {
    function wpilot_auto_approve() { return get_option( 'wpilot_auto_approve', 'no' ) === 'yes'; }
}
if ( ! function_exists( 'wpilot_detect_builder' ) ) {
    function wpilot_detect_builder() {
        if ( defined( 'ELEMENTOR_VERSION' ) )  return 'elementor';
        if ( defined( 'ET_BUILDER_VERSION' ) || defined('ET_DB_VERSION') ) return 'divi';
        if ( defined( 'FL_BUILDER_VERSION' ) ) return 'beaver';
        if ( defined( 'BRICKS_VERSION' ) )     return 'bricks';
        if ( defined( 'OXYGEN_VSB_VERSION' ) ) return 'oxygen';
        return 'gutenberg';
    }
}
if ( ! function_exists( 'wpilot_ok' ) ) {
    function wpilot_ok( $msg, $extra = [] ) { return array_merge( ['success' => true, 'message' => $msg], $extra ); }
}
if ( ! function_exists( 'wpilot_err' ) ) {
    function wpilot_err( $msg ) { return ['success' => false, 'message' => $msg]; }
}
if ( ! function_exists( 'wpilot_md_to_html' ) ) {
    function wpilot_md_to_html( $text ) {
        $text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $text = preg_replace( '/\*\*(.*?)\*\*/',    '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*(.*?)\*/',        '<em>$1</em>',         $text );
        $text = preg_replace( '/^#{1,3}\s+(.+)$/m', '<div class="ca-md-h">$1</div>', $text );
        $text = preg_replace( '/^[-•]\s+(.+)$/m',   '<li>$1</li>', $text );
        $text = preg_replace( '/(<li>.*?<\/li>(\n)?)+/s', '<ul class="ca-md-ul">$0</ul>', $text );
        $text = preg_replace( '/`([^`]+)`/', '<code class="ca-md-code">$1</code>', $text );
        return nl2br( $text );
    }
}


// ── Usage Tracking ─────────────────────────────────────────
function wpilot_track_usage() {
    $today = date('Y-m-d');
    $usage = get_option('wpi_usage_stats', []);
    if (!isset($usage[$today])) $usage[$today] = ['prompts'=>0, 'tokens_est'=>0];
    $usage[$today]['prompts']++;
    $usage[$today]['tokens_est'] += 800; // rough average tokens per request
    // Keep only last 90 days
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    $usage = array_filter($usage, function($k) use ($cutoff) { return $k >= $cutoff; }, ARRAY_FILTER_USE_KEY);
    update_option('wpi_usage_stats', $usage, false);
}

function wpilot_get_usage_summary() {
    $usage = get_option('wpi_usage_stats', []);
    $today = date('Y-m-d');
    $week_ago = date('Y-m-d', strtotime('-7 days'));
    $month_ago = date('Y-m-d', strtotime('-30 days'));

    $today_prompts = $usage[$today]['prompts'] ?? 0;
    $week_prompts = 0; $month_prompts = 0; $total_prompts = 0;
    $week_tokens = 0; $month_tokens = 0; $total_tokens = 0;

    foreach ($usage as $date => $data) {
        $p = $data['prompts'] ?? 0;
        $t = $data['tokens_est'] ?? 0;
        $total_prompts += $p;
        $total_tokens += $t;
        if ($date >= $week_ago) { $week_prompts += $p; $week_tokens += $t; }
        if ($date >= $month_ago) { $month_prompts += $p; $month_tokens += $t; }
    }

    // Use real cost data if available, fall back to estimate
    $real_cost_month = 0; $real_cost_total = 0;
    $real_input_month = 0; $real_output_month = 0;
    $real_input_total = 0; $real_output_total = 0;
    foreach ($usage as $date => $data) {
        $c = $data['cost'] ?? 0;
        $it = $data['input_tokens'] ?? 0;
        $ot = $data['output_tokens'] ?? 0;
        $real_cost_total += $c;
        $real_input_total += $it;
        $real_output_total += $ot;
        if ($date >= $month_ago) {
            $real_cost_month += $c;
            $real_input_month += $it;
            $real_output_month += $ot;
        }
    }
    $est_cost_month = $real_cost_month > 0 ? round($real_cost_month, 2) : round($month_prompts * 0.01, 2);
    $est_cost_total = $real_cost_total > 0 ? round($real_cost_total, 2) : round($total_prompts * 0.01, 2);

    return [
        'today' => $today_prompts,
        'week' => $week_prompts,
        'month' => $month_prompts,
        'total' => $total_prompts,
        'tokens_month' => $real_input_month + $real_output_month,
        'tokens_total' => $real_input_total + $real_output_total,
        'input_tokens_month' => $real_input_month,
        'output_tokens_month' => $real_output_month,
        'cost_month' => $est_cost_month,
        'cost_total' => $est_cost_total,
        'daily' => $usage,
    ];
}


// ── Real Token Tracking from Claude API ────────────────────
function wpilot_track_tokens($input_tokens, $output_tokens) {
    $today = date('Y-m-d');
    $usage = get_option('wpi_usage_stats', []);
    if (!isset($usage[$today])) $usage[$today] = ['prompts'=>0, 'tokens_est'=>0, 'input_tokens'=>0, 'output_tokens'=>0, 'cost'=>0];
    $usage[$today]['input_tokens'] = ($usage[$today]['input_tokens'] ?? 0) + $input_tokens;
    $usage[$today]['output_tokens'] = ($usage[$today]['output_tokens'] ?? 0) + $output_tokens;
    // Real cost: Sonnet input=$3/MTok, output=$15/MTok
    $cost = ($input_tokens / 1000000 * 3) + ($output_tokens / 1000000 * 15);
    $usage[$today]['cost'] = round(($usage[$today]['cost'] ?? 0) + $cost, 4);
    // Prune entries older than 90 days
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    foreach (array_keys($usage) as $d) { if ($d < $cutoff) unset($usage[$d]); }
    update_option('wpi_usage_stats', $usage, false);
}

// Admin notice when not connected
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( wpilot_is_connected() ) return;
    $screen = get_current_screen();
    if ( $screen && strpos( $screen->id, CA_SLUG ) !== false ) return;
    ?>
    <div class="notice notice-info is-dismissible" style="border-left-color:#5B8DEF;padding:14px 16px">
        <p style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:13.5px">
            <strong style="font-size:14px">⚡ WPilot <span style="font-weight:400;color:#888">powered by Claude AI</span></strong>
            <span style="color:#777">—</span>
            <span>Connect your Claude API key to start designing and building your site live with AI.</span>
            <a href="<?= esc_url( admin_url( 'admin.php?page=' . CA_SLUG . '-settings' ) ) ?>" style="font-weight:700;color:#5B8DEF;white-space:nowrap">⚙️ Connect now</a>
        </p>
    </div>
    <?php
} );

// ── Role check: who can use WPilot ────────────────────────
function wpilot_can_use() {
    $type = wpilot_license_type();
    if ( $type === 'lifetime' ) return true;
    if ( in_array($type, ['pro','team','agency']) ) {
        $cached = get_transient('wpi_license_valid');
        if ( $cached === false ) {
            $cached = wpilot_remote_validate_license();
            set_transient('wpi_license_valid', $cached ? 1 : 0, HOUR_IN_SECONDS);
        }
        return (bool)$cached;
// Built by Christos Ferlachidis & Daniel Hedenberg
    }
    return wpilot_prompts_used() < CA_FREE_LIMIT;
}

function wpilot_remote_validate_license() {
    $key = get_option('ca_license_key','');
    if ( !$key ) return false;
    $resp = wp_remote_post( WPI_LICENSE_VALIDATE_URL, [
        'timeout' => 8,
        'body'    => ['license_key'=>$key,'site_url'=>get_site_url(),'plugin_version'=>CA_VERSION],
    ]);
    if ( is_wp_error($resp) ) return false;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ( !empty($data['warning']) ) {
        update_option('wpi_license_warning', sanitize_text_field($data['warning']));
        add_action('admin_notices', function() {
            $w = get_option('wpi_license_warning','');
            if ($w) echo '<div class="notice notice-warning is-dismissible"><p>⚠️ WPilot: '.esc_html($w).'</p></div>';
        });
    }
    return !empty($data['valid']);
}

// ── WPilot Access Control ──────────────────────────────────
// Admins can grant WPilot access to any role
function wpilot_allowed_roles() {
    return get_option( 'wpilot_allowed_roles', ['administrator', 'editor'] );
}

function wpilot_user_has_access() {
    if ( ! is_user_logged_in() ) return false;
    $user  = wp_get_current_user();
    $allowed = wpilot_allowed_roles();
    foreach ( $user->roles as $role ) {
        if ( in_array( $role, $allowed ) ) return true;
    }
    return false;
}

// Who can modify site (tools that change things) vs who can only chat
function wpilot_user_can_modify() {
    // Only admins and editors can execute tools that modify the site
    return current_user_can( 'edit_pages' );
}

// Who can manage WPilot settings (grant access, change settings)
function wpilot_user_can_manage() {
    return current_user_can( 'manage_options' );
}

// ── Admin bar notification ─────────────────────────────────────
add_action('admin_bar_menu', function($bar) {
    if ( !is_admin() ) return;
    if ( !current_user_can('manage_options') ) return;
    $connected = wpilot_is_connected();
    $icon      = $connected ? '⚡' : '⚠️';
    $url       = admin_url('admin.php?page='.CA_SLUG.($connected ? '' : '-settings'));
    $color     = $connected ? '#10b981' : '#f59e0b';
    $bar->add_node([
        'id'    => 'aib-status',
        'title' => '<span style="color:'.$color.'">'.$icon.' AI</span>',
        'href'  => $url,
        'meta'  => ['title' => $connected ? 'WPilot ready' : 'WPilot — connect API key'],
    ]);
    if ($connected) {
        $bar->add_node(['parent'=>'aib-status','id'=>'aib-status-chat','title'=>'💬 Open AI Chat','href'=>admin_url('admin.php?page='.CA_SLUG.'-chat')]);
        $bar->add_node(['parent'=>'aib-status','id'=>'aib-status-brain','title'=>'🧠 WPilot Brain','href'=>admin_url('admin.php?page='.CA_SLUG.'-brain')]);
    } else {
        $bar->add_node(['parent'=>'aib-status','id'=>'aib-status-setup','title'=>'🔑 Connect API key →','href'=>admin_url('admin.php?page='.CA_SLUG.'-settings')]);
    }
}, 100);
