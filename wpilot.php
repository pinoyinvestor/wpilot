<?php
/**
 * Plugin Name:  WPilot powered by Claude AI
 * Plugin URI:   https://weblease.se/wpilot
 * Description:  Live AI assistant for WordPress — design, build, and improve your site in real time using Claude AI. Connect your own Claude API key from Anthropic.
 * Version:           2.6.0
 * Author:       Weblease
 * Author URI:   https://weblease.se
 * License:      GPL-2.0+
 * Text Domain:  wpilot
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Recovery page handler ──
if ( is_admin() && isset( $_GET['wpilot-recover'] ) && $_GET['wpilot-recover'] === '1' ) {
    add_action( 'admin_init', function() {
        if ( current_user_can( 'manage_options' ) && file_exists( __DIR__ . '/wpilot-recovery.php' ) ) {
            require_once __DIR__ . '/wpilot-recovery.php';
        }
    });
}

// ── Safe Mode: skip loading if crashed 3+ times ──
$safe_mode_file = WP_CONTENT_DIR . '/wpilot-safe-mode.txt';
if (file_exists($safe_mode_file)) {
    $sm = json_decode(file_get_contents($safe_mode_file), true);
    if (($sm['crashes'] ?? 0) >= 3 && strtotime($sm['last_crash'] ?? '') > time() - 600) {
        // Don't load WPilot — safe mode active
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WPilot Safe Mode:</strong> Plugin disabled due to repeated crashes. <a href="' . esc_url(admin_url('?wpilot-recover=1')) . '">Open Recovery</a></p></div>';
        });
        return; // Stop loading the plugin
    }
    // Auto-reset after 10 minutes
    @unlink($safe_mode_file);
}


// ── PHP version guard ─────────────────────────────────────────
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>WPilot</strong> requires PHP 7.4 or higher. Your server is running PHP ' . PHP_VERSION . '. Please contact your hosting provider.</p></div>';
    });
    return;
}

// ── Debug mode (remove before production) ───────────────────
if ( defined('WP_DEBUG') && WP_DEBUG ) {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
}

if ( ! defined( 'CA_VERSION' ) ) define( "CA_VERSION", "2.6.0" );
define( 'CA_PATH',       plugin_dir_path( __FILE__ ) );
define( 'CA_URL',        plugin_dir_url( __FILE__ ) );
define( 'CA_FREE_LIMIT',        20   );  // Free prompts before upgrade required
define( 'CA_LIFETIME_SLOTS',   20   );  // First 20 buyers get lifetime access
define( 'CA_PRICE_PRO',        19   );  // USD/month — Pro
define( 'CA_PRICE_TEAM',       49   );  // USD/month — Team (3 licenses)
define( 'CA_PRICE_LIFETIME',   299  );  // USD one-time — Lifetime
define( 'CA_LIFETIME_CLAIMED_KEY', 'wpi_lifetime_claimed_count' );

// Stripe Product/Price IDs (set these after creating products in Stripe Dashboard)
define( 'WPI_STRIPE_PRO_PRICE_ID',      getenv('WPI_STRIPE_PRO_PRICE_ID')      ?: 'price_pro_monthly' );
define( 'WPI_STRIPE_AGENCY_PRICE_ID',   getenv('WPI_STRIPE_AGENCY_PRICE_ID')   ?: 'price_agency_monthly' );
define( 'WPI_STRIPE_LIFETIME_PRICE_ID', getenv('WPI_STRIPE_LIFETIME_PRICE_ID') ?: 'price_lifetime_once' );
define( 'WPI_STRIPE_SECRET_KEY',        '' );  // loaded from get_option() in helpers
define( 'WPI_STRIPE_WEBHOOK_SECRET',    '' );  // loaded from get_option() in helpers
// Built by Christos Ferlachidis & Daniel Hedenberg
define( 'WPI_LICENSE_VALIDATE_URL',    'https://weblease.se/ai-license/validate' );
define( 'WPI_STRIPE_PUBLISHABLE_KEY',   '' );  // loaded from get_option() in helpers
define( 'WPI_LICENSE_SERVER',    'https://weblease.se/ai-license' );
define( 'CA_SLUG',       'wpilot' );
define( 'CA_MONTHLY_PRICE',          19   );   // Alias for bubble.php
define( 'WPI_WEBLEAS_ENDPOINT',      'https://weblease.se/ai-query' );  // WPilot AI server (moved from webleas_ai.php)
define( 'CA_MODEL',      'claude-sonnet-4-6' );
define( 'CA_MODEL_FAST', 'claude-haiku-4-5-20251001' ); // 10x cheaper for simple tasks

// ── Always load (lightweight — hooks and helpers) ─────────────
$_wpilot_core = [
    'includes/helpers.php',
    'includes/crypto.php',
    'includes/i18n.php',
    'includes/license.php',
    'includes/tracking.php',
    'includes/bubble.php',
    'includes/ajax.php',
    'includes/brain_ajax.php',
];
foreach ( $_wpilot_core as $f ) {
    require_once CA_PATH . $f;
}

// ── Admin-only (menu pages, onboarding) ──────────────────────
if ( is_admin() ) {
    require_once CA_PATH . 'includes/menu.php';
    require_once CA_PATH . 'includes/onboarding.php';
    // Migrate plaintext API keys to encrypted storage (runs once)
    add_action( 'admin_init', 'wpilot_migrate_encrypt_keys' );
}

// ── Heavy modules — loaded on demand ─────────────────────────
function wpilot_load_heavy() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $dir = plugin_dir_path(__FILE__) . 'includes/';

    // Core modules — always needed for chat
    $core = ['mu_consolidator', 'brain', 'business_profile', 'api', 'context', 'safeguard', 'tools', 'tools_pages', 'tools_woo', 'tools_woo_advanced', 'tools_design', 'tools_seo', 'tools_security', 'tools_files', 'tools_api', 'tools_media', 'tools_forms', 'tools_comments', 'tools_gdpr', 'tools_content', 'tools_marketing', 'tools_engage', 'backup', 'activity_log', 'parser_fix', 'design_memory', 'design_blueprints', 'site_recipes', 'header_footer_blueprints', 'webleas_ai', 'collector', 'shadow', 'mobile_nav', 'pwa'];
    foreach ($core as $m) {
        $f = $dir . $m . '.php';
        if (file_exists($f)) require_once $f;
    }

    // Heavy modules — load on demand
    // These are loaded when their tools are first called
    wpilot_register_lazy_modules();
}

/**
 * Register which modules contain which tools — loaded on demand
 */
function wpilot_register_lazy_modules() {
    global $wpilot_lazy_modules;
    $wpilot_lazy_modules = [
        'plugin_tools'   => ['woo_configure_stripe', 'woo_configure_email', 'woo_setup_checkout', 'cf7_create_form', 'smtp_configure', 'amelia_', 'learndash_', 'memberpress_', 'gravity_', 'wpforms_', 'bulk_import_products'],
        'builder_tools'  => ['builder_create_page', 'builder_update_section', 'builder_add_widget', 'builder_update_css', 'builder_set_colors', 'builder_set_fonts', 'builder_create_header', 'builder_create_footer'],
        'blueprint'      => ['wpilot_build_site_snapshot', 'wpilot_get_blueprint'],
        'screenshot'     => ['screenshot', 'take_screenshot', 'analyze_design', 'visual_review', 'design_review', 'check_visual_bugs', 'visual_debug', 'compare_before_after', 'screenshot_before', 'screenshot_history', 'responsive_check', 'multi_device_screenshot'],
        'templates'      => ['list_templates', 'apply_template', 'use_template'],
        'brain'          => ['wpilot_brain_', 'wpilot_smart_answer'],
        'webleas_ai'     => ['wpilot_check_license'],
        'data_prep'      => ['wpilot_export_training', 'wpilot_classify_intent', 'training_stats', 'export_training_data'],
        'site_types'     => ['wpilot_detect_site_type', 'wpilot_default_pages'],
        'tools_snippets' => ['wpilot_tools_snippets'],
        'upgrade'        => ['wpilot_check_updates'],
    ];
}

/**
 * Lazy-load a module when its tool is needed
 */
function wpilot_ensure_module($tool) {
    global $wpilot_lazy_modules, $wpilot_loaded_modules;
    if (!isset($wpilot_lazy_modules)) return;
    if (!isset($wpilot_loaded_modules)) $wpilot_loaded_modules = [];

    $dir = plugin_dir_path(dirname(__FILE__) . '/wpilot.php') ?: WP_PLUGIN_DIR . '/wpilot/';
    if (substr($dir, -1) !== '/') $dir .= '/';
    $dir .= 'includes/';

    foreach ($wpilot_lazy_modules as $module => $tools) {
        if (isset($wpilot_loaded_modules[$module])) continue;
        foreach ($tools as $t) {
            if ($tool === $t || strpos($tool, $t) === 0) {
                $file = $dir . $module . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    $wpilot_loaded_modules[$module] = true;
                }
                return;
            }
        }
    }
}

/**
 * Load ALL modules — for operations that need everything (like smart_answer)
 */
function wpilot_load_all_modules() {
    static $all_loaded = false;
    if ($all_loaded) return;
    $all_loaded = true;

    wpilot_load_heavy(); // Core first

    $dir = plugin_dir_path(__FILE__) . 'includes/';
    $mem_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    $mem_used = memory_get_usage();
    $mem_free_mb = ($mem_limit > 0) ? round(($mem_limit - $mem_used) / 1048576) : 999;

    // Always load these (needed for chat)
    $essential = ['brain', 'collector', 'shadow', 'webleas_ai', 'plugin_tools', 'blueprint', 'parser_fix'];
    foreach ($essential as $m) {
        $f = $dir . $m . '.php';
        if (file_exists($f)) require_once $f;
    }

    // Load these only if we have enough memory (>64 MB free)
    if ($mem_free_mb > 64) {
        $optional = ['builder_tools', 'screenshot', 'templates', 'data_prep', 'site_types', 'tools_snippets', 'upgrade'];
        foreach ($optional as $m) {
            $f = $dir . $m . '.php';
            if (file_exists($f)) require_once $f;
        }
    }
}

// Load heavy modules ONLY when needed:
// 1. During AJAX requests (tools, chat, etc.)
add_action( 'admin_init', function() {
    if ( wp_doing_ajax() ) wpilot_load_heavy();
}, 1 );

// 2. On WPilot admin pages (context needed for rendering)
add_action( 'current_screen', function( $screen ) {
    if ( $screen && strpos( $screen->id, CA_SLUG ) !== false ) {
        wpilot_load_heavy();
    }
} );

// 3. Frontend heavy modules — only for logged-in users with access (admin/editors)
// SEO output (schema, OG, Twitter Cards, robots.txt) is handled in helpers.php for all visitors
add_action( 'template_redirect', function() {
    if ( is_user_logged_in() && ( wpilot_user_has_access() || current_user_can( 'manage_options' ) ) ) {
        wpilot_load_heavy();
    }
}, 1 );

// ── Activation ────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    wpilot_load_heavy();
    // Explicitly load modules needed for activation
    require_once CA_PATH . "includes/brain.php";
    require_once CA_PATH . "includes/collector.php";
    require_once CA_PATH . "includes/shadow.php";
    add_option( 'wpilot_theme',               'dark' );
    add_option( 'wpilot_auto_approve',        'no' );
    add_option( 'wpilot_prompts_used',        0 );
    add_option( 'ca_custom_instructions', '' );
    add_option( 'ca_onboarded',           'no' );
    add_option( 'wpi_data_consent',        'no' );   // GDPR: default to no consent
    wpilot_backup_create_table();
    wpilot_brain_install();
    // Track activation on weblease.se
    if ( function_exists('wpilot_track_activation') ) wpilot_track_activation();
    // Schedule daily screenshot cleanup
    if (!wp_next_scheduled('wpilot_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'wpilot_daily_cleanup');
    }
} );

register_deactivation_hook( __FILE__, function () {
    if ( function_exists('wpilot_track_deactivation') ) wpilot_track_deactivation();
    if ( function_exists('wpilot_tracking_cleanup') ) wpilot_tracking_cleanup();
    wp_clear_scheduled_hook('wpilot_daily_cleanup');
    if (function_exists('wpilot_cleanup_all_screenshots')) wpilot_cleanup_all_screenshots();
} );

// ── Asset suffix (use minified unless debugging) ──────────────
function wpilot_asset_suffix() {
    return ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
}

// ── Shared enqueue helper ─────────────────────────────────────
function wpilot_enqueue_bubble() {
    if ( ! wpilot_can_use() ) return;
    $sfx = wpilot_asset_suffix();
    wp_enqueue_style(  'aib-bubble', CA_URL . "assets/bubble{$sfx}.css", [], CA_VERSION );
    wp_enqueue_script( 'aib-bubble', CA_URL . "assets/bubble{$sfx}.js",  ['jquery'], CA_VERSION, true );
}

// ── Admin: bubble + full plugin UI ────────────────────────────
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    wpilot_enqueue_bubble();
    // Full admin UI only on our plugin pages
    if ( strpos( $hook, CA_SLUG ) !== false ) {
        $sfx = wpilot_asset_suffix();
        wp_enqueue_style(  'aib-admin', CA_URL . "assets/admin{$sfx}.css", [], CA_VERSION );
        wp_enqueue_script( 'aib-admin', CA_URL . "assets/admin{$sfx}.js",  ['jquery','aib-bubble'], CA_VERSION, true );
        // ── Localize CA object for admin.js ──────────────────
        wp_localize_script( 'aib-admin', 'CA', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('ca_nonce'),
            'connected'   => wpilot_is_connected() ? 'yes' : 'no',
            'used'        => (int) get_option('wpilot_prompts_used', 0),
            'limit'       => CA_FREE_LIMIT,
            'locked'      => wpilot_can_use() ? 'no' : 'yes',
            'theme'       => get_option('wpilot_theme', 'dark'),
            'auto_approve'=> get_option('wpilot_auto_approve', 'no'),
            'onboarded'   => get_option('ca_onboarded', 'no'),
            'slug'        => CA_SLUG,
            'version'     => CA_VERSION,
            'tier'        => wpilot_get_license_tier(),
            'admin_url'   => admin_url(),
            'plugin_url'  => admin_url('admin.php?page='.CA_SLUG),
            'is_admin'    => current_user_can('manage_options') ? 'yes' : 'no',
            'site_name'   => get_bloginfo('name'),
            'site_url'    => get_site_url(),
            'page_title'  => get_admin_page_title(),
            'i18n'        => [
                'error_no_credits' => wpilot_t('error_no_credits'),
                'add_credits'      => wpilot_t('add_credits_btn'),
            ],
            'strings'     => [
                'thinking'  => wpilot_t('thinking'),
                'error'     => wpilot_t('error'),
                'apply'     => wpilot_t('apply'),
                'skip'      => wpilot_t('skip'),
                'undo'      => wpilot_t('undo'),
                'analyzing' => wpilot_t('analyzing'),
            ],
        ]);
    }
} );

// ── Frontend: bubble on all public pages for logged-in users ──
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_user_logged_in() ) return;
    wpilot_enqueue_bubble();
} );
// Execute scheduled WPilot actions
add_action('wpilot_run_scheduled', function($id) {
    $scheduled = get_option('wpilot_scheduled_actions', []);
    if (!isset($scheduled[$id])) return;
    wpilot_load_heavy();
    $result = wpilot_run_tool($scheduled[$id]['tool'], $scheduled[$id]['params']);
    $scheduled[$id]['status'] = ($result['success'] ?? false) ? 'completed' : 'failed';
    $scheduled[$id]['result'] = $result['message'] ?? '';
    $scheduled[$id]['completed_at'] = date('Y-m-d H:i:s');
    update_option('wpilot_scheduled_actions', $scheduled);
});
// WPilot Webhook REST endpoints
add_action('rest_api_init', function() {
    register_rest_route('wpilot/v1', '/webhook/(?P<slug>[a-z0-9-]+)', [
        'methods' => ['GET','POST'],
        'callback' => function($request) {
            $slug = $request['slug'];
            $webhooks = get_option('wpilot_webhooks', []);
            if (!isset($webhooks[$slug])) return new WP_REST_Response(['error' => 'Not found'], 404);
            $wh = $webhooks[$slug];
            // SECURITY: Always require secret for webhooks (timing-safe comparison)
            $secret = $request->get_header('X-Webhook-Secret') ?? $request->get_param('secret');
            if (empty($wh['secret']) || empty($secret) || !hash_equals($wh['secret'], (string)$secret)) {
                return new WP_REST_Response(['error' => 'Invalid or missing secret'], 403);
            }
            // Increment counter
            $webhooks[$slug]['calls'] = ($wh['calls'] ?? 0) + 1;
            $webhooks[$slug]['last_call'] = date('Y-m-d H:i:s');
            update_option('wpilot_webhooks', $webhooks);
            // Execute action if configured
            if (!empty($wh['action'])) {
                wpilot_load_heavy();
                $params = $request->get_json_params() ?: $request->get_query_params();
                $result = wpilot_run_tool($wh['action'], $params);
                return new WP_REST_Response($result, 200);
            }
            return new WP_REST_Response(['ok' => true, 'slug' => $slug], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Memory guard — check if we have enough RAM for an operation
 */
function wpilot_memory_ok($needed_mb = 32) {
    $limit = wp_convert_hr_to_bytes(WP_MEMORY_LIMIT);
    $used = memory_get_usage();
    $available = $limit - $used;
    return $available > ($needed_mb * 1048576);
}

/**
 * Set optimal memory limit for WPilot operations
 */
function wpilot_ensure_memory() {
    $current = wp_convert_hr_to_bytes(WP_MEMORY_LIMIT);
    if ($current < 128 * 1048576) {
        @ini_set('memory_limit', '128M');
    }
}
/**
 * Detect hosting tier based on PHP memory limit
 * Returns: 'basic' (<=256MB), 'standard' (<=512MB), 'premium' (<=1024MB), 'dedicated' (>1024MB)
 */
function wpilot_hosting_tier() {
    $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    $mb = ($limit <= 0) ? 9999 : $limit / 1048576;
    if ($mb <= 256) return 'basic';      // Privatpaket - shared hosting
    if ($mb <= 512) return 'standard';   // Företagspaket
    if ($mb <= 1024) return 'premium';   // Företag Plus
    return 'dedicated';                   // VPS/dedicated
}

/**
 * Check if a feature is available on this hosting tier
 */
function wpilot_can_use_feature($feature) {
    $tier = wpilot_hosting_tier();
    $requirements = [
        'screenshot'       => 'standard',  // Needs 512MB+ for Chromium
        'vision_analysis'  => 'standard',  // Screenshot + API call
        'responsive_check' => 'standard',  // 3 screenshots
        'full_site_audit'  => 'premium',   // Multiple screenshots + vision
        'bulk_import'      => 'standard',  // Memory for CSV processing
    ];
    $tiers = ['basic' => 1, 'standard' => 2, 'premium' => 3, 'dedicated' => 4];
    $needed = $requirements[$feature] ?? 'basic';
    return ($tiers[$tier] ?? 1) >= ($tiers[$needed] ?? 1);
}

// ── Screenshot cleanup on cache purge ─────────────────────────
add_action('litespeed_purged_all', 'wpilot_cleanup_all_screenshots');
add_action('w3tc_flush_all', 'wpilot_cleanup_all_screenshots');
add_action('wp_cache_cleared', 'wpilot_cleanup_all_screenshots');
add_action('ce_action_cache_cleared', 'wpilot_cleanup_all_screenshots');
add_action('breeze_clear_all_cache', 'wpilot_cleanup_all_screenshots');
add_action('wp_rocket_after_purge_cache_all', 'wpilot_cleanup_all_screenshots');
add_action('autoptimize_action_cachepurged', 'wpilot_cleanup_all_screenshots');

function wpilot_cleanup_all_screenshots() {
    $dir = wp_upload_dir()['basedir'] . '/wpilot-screenshots';
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.png');
    $count = 0;
    foreach ($files as $f) { @unlink($f); $count++; }
    // Also delete receipt files
    $receipts = glob(wp_upload_dir()['basedir'] . '/wpilot-receipt-*.txt');
    foreach ($receipts as $r) @unlink($r);
    // Clean CSV exports
    $csvs = glob(wp_upload_dir()['basedir'] . '/wpilot-*.csv');
    foreach ($csvs as $c) @unlink($c);
}

// ── Daily cleanup cron (delete screenshots older than 24h) ────
add_action('wpilot_daily_cleanup', function() {
    $dir = wp_upload_dir()['basedir'] . '/wpilot-screenshots';
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*.png');
    $cutoff = time() - 86400;
    foreach ($files as $f) {
        if (filemtime($f) < $cutoff) @unlink($f);
    }
    // Clean old receipts (7 days)
    $receipts = glob(wp_upload_dir()['basedir'] . '/wpilot-receipt-*.txt');
    foreach ($receipts as $r) { if (filemtime($r) < time() - 604800) @unlink($r); }
    // Clean old CSV exports (7 days)
    $csvs = glob(wp_upload_dir()['basedir'] . '/wpilot-*.csv');
    foreach ($csvs as $c) { if (filemtime($c) < time() - 604800) @unlink($c); }
});
// ═══ AUTO-UPDATE FROM WEBLEASE.SE ═══
add_filter('pre_set_site_transient_update_plugins', 'wpilot_check_for_updates');
function wpilot_check_for_updates($transient) {
    if (empty($transient->checked)) return $transient;

    $response = wp_remote_get('https://weblease.se/plugin/update-check', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $transient;
    }

    $update = json_decode(wp_remote_retrieve_body($response));
    if (!$update || empty($update->version)) return $transient;

    $plugin_file = 'wpilot/wpilot.php';
    $current_version = $transient->checked[$plugin_file] ?? CA_VERSION;

    if (version_compare($update->version, $current_version, '>')) {
        $transient->response[$plugin_file] = (object) [
            'slug'        => 'wpilot',
            'plugin'      => $plugin_file,
            'new_version' => $update->version,
            'url'         => 'https://weblease.se/wpilot',
            'package'     => $update->download_url,
            'tested'      => $update->tested ?? '',
            'requires'    => $update->requires ?? '6.0',
            'requires_php' => $update->requires_php ?? '7.4',
        ];
    }

    return $transient;
}

// Show plugin details in the "View Details" modal
add_filter('plugins_api', 'wpilot_plugin_info', 20, 3);
function wpilot_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'wpilot') {
        return $result;
    }

    $response = wp_remote_get('https://weblease.se/plugin/update-check', ['timeout' => 10]);
    if (is_wp_error($response)) return $result;

    $update = json_decode(wp_remote_retrieve_body($response));
    if (!$update) return $result;

    return (object) [
        'name'          => 'WPilot — AI WordPress Assistant',
        'slug'          => 'wpilot',
        'version'       => $update->version,
        'author'        => '<a href="https://weblease.se">Weblease</a>',
        'author_profile' => 'https://weblease.se',
        'homepage'      => 'https://weblease.se/wpilot',
        'download_link' => $update->download_url,
        'requires'      => $update->requires ?? '6.0',
        'tested'        => $update->tested ?? '',
        'requires_php'  => $update->requires_php ?? '7.4',
        'sections'      => [
            'description'  => $update->sections->description ?? '',
            'installation' => $update->sections->installation ?? '',
            'changelog'    => $update->changelog ?? '',
        ],
        'banners' => [
            'high' => $update->banner_high ?? '',
            'low'  => $update->banner_low ?? '',
        ],
    ];
}
// Show admin notice for missing essential plugins (once per week)
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    $last_check = get_transient('wpilot_plugin_check');
    if ($last_check) return;

    $installed = array_map(function($p) { return explode('/', $p)[0]; }, get_option('active_plugins', []));
    $missing = [];
    if (!in_array('updraftplus', $installed) && !in_array('duplicator', $installed)) $missing[] = 'Backup (UpdraftPlus)';
    if (!in_array('litespeed-cache', $installed) && !in_array('wp-super-cache', $installed) && !in_array('w3-total-cache', $installed)) $missing[] = 'Cache';
    if (!in_array('wordfence', $installed) && !in_array('sucuri-scanner', $installed)) $missing[] = 'Security';

    if (!empty($missing)) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>WPilot:</strong> Your site is missing essential plugins: ' . implode(', ', $missing) . '. <a href="' . admin_url('admin.php?page=wpilot-chat') . '">Ask WPilot to install them</a></p></div>';
    }
    set_transient('wpilot_plugin_check', 1, WEEK_IN_SECONDS);
}); // wpilot_missing_plugins_notice
// Flush error queue on shutdown (non-blocking)
add_action('shutdown', function() {
    if (function_exists('wpilot_flush_error_queue') && defined('DOING_AJAX') && DOING_AJAX) {
        wpilot_flush_error_queue();
    }
});
