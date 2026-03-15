<?php
/**
 * Plugin Name:  WPilot powered by Claude AI
 * Plugin URI:   https://weblease.se/wpilot
 * Description:  Live AI assistant for WordPress — design, build, and improve your site in real time using Claude AI. Connect your own Claude API key from Anthropic.
 * Version:      2.0.0
 * Author:       Weblease
 * Author URI:   https://weblease.se
 * License:      GPL-2.0+
 * Text Domain:  wpilot
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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

define( 'CA_VERSION',    '2.0.0' );
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

// ── Always load (lightweight — hooks and helpers) ─────────────
$_wpilot_core = [
    'includes/helpers.php',
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
}

// ── Heavy modules — loaded on demand ─────────────────────────
function wpilot_load_heavy() {
    static $loaded = false;
    if ( $loaded ) return;
    $loaded = true;

    $modules = [
        'api', 'context', 'safeguard', 'tools', 'backup',
        'site_types', 'plugin_tools', 'builder_tools',
        'activity_log', 'scheduler', 'shadow', 'collector',
        'brain', 'webleas_ai', 'data_prep', 'upgrade',
    ];
    foreach ( $modules as $m ) {
        require_once CA_PATH . 'includes/' . $m . '.php';
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

// 3. Frontend hooks (robots.txt, schema, PWA manifest)
add_action( 'template_redirect', function() {
    // Only load heavy modules for logged-in users with WPilot access
    if ( is_user_logged_in() && ( wpilot_user_has_access() || current_user_can('manage_options') ) ) {
        wpilot_load_heavy();
    }
}, 1 );

// ── Activation ────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    wpilot_load_heavy(); // Ensure backup.php + brain.php are available
    add_option( 'wpilot_theme',               'dark' );
    add_option( 'wpilot_auto_approve',        'no' );
    add_option( 'wpilot_prompts_used',        0 );
    add_option( 'ca_custom_instructions', '' );
    add_option( 'ca_onboarded',           'no' );
    add_option( 'wpi_data_consent',        'no' );
    add_option( 'wpilot_allowed_roles', ['administrator', 'editor'] );   // GDPR: default to no consent
    wpilot_backup_create_table();
    wpilot_brain_install();
    // Track activation on weblease.se
    if ( function_exists('wpilot_track_activation') ) wpilot_track_activation();
} );

register_deactivation_hook( __FILE__, function () {
    if ( function_exists('wpilot_track_deactivation') ) wpilot_track_deactivation();
    if ( function_exists('wpilot_tracking_cleanup') ) wpilot_tracking_cleanup();
} );

// ── Asset suffix (use minified unless debugging) ──────────────
function wpilot_asset_suffix() {
    return ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
}

// ── Shared enqueue helper ─────────────────────────────────────
function wpilot_enqueue_bubble() {
    // Load bubble for any user with access OR any admin (fallback)
    if ( ! wpilot_user_has_access() && ! current_user_can( 'manage_options' ) ) return;
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
