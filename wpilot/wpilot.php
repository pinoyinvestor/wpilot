<?php
/**
 * Plugin Name:  WPilot powered by Claude AI
 * Plugin URI:   https://weblease.se/ai-builder
 * Description:  Live AI assistant for WordPress — design, build, and improve your site in real time using Claude AI. Connect your own Claude API key from Anthropic.
 * Version:      1.0.0
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
        echo '<div class="notice notice-error"><p><strong>WPilot</strong> kräver PHP 7.4 eller högre. Din server kör PHP ' . PHP_VERSION . '. Kontakta din hostingleverantör.</p></div>';
    });
    return;
}

// ── Debug mode (remove before production) ───────────────────
if ( defined('WP_DEBUG') && WP_DEBUG ) {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
}

define( 'CA_VERSION',    '1.0.0' );
define( 'CA_PATH',       plugin_dir_path( __FILE__ ) );
define( 'CA_URL',        plugin_dir_url( __FILE__ ) );
define( 'CA_FREE_LIMIT',        20   );  // Free prompts before upgrade required
define( 'CA_LIFETIME_SLOTS',   20   );  // First 20 buyers get lifetime access
define( 'CA_PRICE_PRO',        19   );  // USD/month — Pro
define( 'CA_PRICE_AGENCY',     49   );  // USD/month — Agency (up to 5 sites)
define( 'CA_PRICE_LIFETIME',   149  );  // USD one-time — Lifetime
define( 'CA_LIFETIME_CLAIMED_KEY', 'wpi_lifetime_claimed_count' );

// Stripe Product/Price IDs (set these after creating products in Stripe Dashboard)
define( 'WPI_STRIPE_PRO_PRICE_ID',      getenv('WPI_STRIPE_PRO_PRICE_ID')      ?: 'price_pro_monthly' );
define( 'WPI_STRIPE_AGENCY_PRICE_ID',   getenv('WPI_STRIPE_AGENCY_PRICE_ID')   ?: 'price_agency_monthly' );
define( 'WPI_STRIPE_LIFETIME_PRICE_ID', getenv('WPI_STRIPE_LIFETIME_PRICE_ID') ?: 'price_lifetime_once' );
define( 'WPI_STRIPE_SECRET_KEY',        '' );  // loaded from get_option() in helpers
define( 'WPI_STRIPE_WEBHOOK_SECRET',    '' );  // loaded from get_option() in helpers
define( 'AIB_LICENSE_SERVER',          'https://weblease.se/ai-license' );
define( 'WPI_LICENSE_VALIDATE_URL',    'https://weblease.se/ai-license/validate' );
define( 'WPI_STRIPE_PUBLISHABLE_KEY',   '' );  // loaded from get_option() in helpers
define( 'WPI_LICENSE_SERVER',    'https://weblease.se/ai-license' );
define( 'CA_SLUG',       'wpilot' );
define( 'CA_MONTHLY_PRICE',          19   );   // Alias for bubble.php
define( 'WPI_WEBLEAS_ENDPOINT',      'https://weblease.se/ai-query' );  // WPilot AI server (moved from webleas_ai.php)
define( 'CA_MODEL',      'claude-3-5-sonnet-20241022' );

// ── Load modules ──────────────────────────────────────────────
$_wpilot_modules = [
    'includes/helpers.php',
    'includes/i18n.php',
    'includes/license.php',
    'includes/api.php',
    'includes/context.php',
    'includes/tools.php',
    'includes/backup.php',
    'includes/site_types.php',
    'includes/plugin_tools.php',
    'includes/builder_tools.php',
    'includes/onboarding.php',
    'includes/activity_log.php',
    'includes/scheduler.php',
    'includes/shadow.php',
    'includes/collector.php',
    'includes/brain.php',
    'includes/webleas_ai.php',
    'includes/data_prep.php',
    'includes/ajax.php',
    'includes/brain_ajax.php',
    'includes/menu.php',
    'includes/bubble.php',
];
foreach ( $_wpilot_modules as $f ) {
    require_once CA_PATH . $f;
}

// ── Activation ────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    add_option( 'wpilot_theme',               'dark' );
    add_option( 'wpilot_auto_approve',        'no' );
    add_option( 'wpilot_prompts_used',        0 );
    add_option( 'ca_custom_instructions', '' );
    add_option( 'ca_onboarded',           'no' );
    wpilot_backup_create_table();
} );

// ── Shared enqueue helper ─────────────────────────────────────
function wpilot_enqueue_bubble() {
    if ( ! wpilot_can_use() ) return;
    wp_enqueue_style(  'aib-bubble', CA_URL . 'assets/bubble.css', [], CA_VERSION );
    wp_enqueue_script( 'aib-bubble', CA_URL . 'assets/bubble.js',  ['jquery'], CA_VERSION, true );
}

// ── Admin: bubble + full plugin UI ────────────────────────────
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    wpilot_enqueue_bubble();
    // Full admin UI only on our plugin pages
    if ( strpos( $hook, CA_SLUG ) !== false ) {
        wp_enqueue_style(  'aib-admin', CA_URL . 'assets/admin.css', [], CA_VERSION );
        wp_enqueue_script( 'aib-admin', CA_URL . 'assets/admin.js',  ['jquery','aib-bubble'], CA_VERSION, true );
        // ── Localize CA object for admin.js ──────────────────
        wp_localize_script( 'aib-admin', 'CA', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('ca_nonce'),
            'connected'   => wpilot_is_connected() ? '1' : '0',
            'used'        => (int) get_option('wpilot_prompts_used', 0),
            'limit'       => CA_FREE_LIMIT,
            'locked'      => wpilot_can_use() ? '0' : '1',
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
