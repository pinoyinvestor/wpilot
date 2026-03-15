<?php
// WPilot Uninstall — clean up all data when plugin is deleted
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Built by Christos Ferlachidis & Daniel Hedenberg

// Remove all WPilot options
$options = [
    'ca_api_key', 'ca_chat_history', 'ca_custom_instructions', 'ca_onboarded',
    'wpilot_theme', 'wpilot_auto_approve', 'wpilot_allowed_roles',
    // wpilot_prompts_used intentionally NOT deleted - prevents free prompt abuse on reinstall
    'wpi_data_consent', 'wpi_collect_queue', 'wpi_collect_stats',
    'wpi_shadow_tests', 'wpi_shadow_topic_stats',
    'wpi_custom_robots_txt', 'wpi_pwa_enabled', 'wpi_pwa_theme_color',
    'wpi_redirects', 'wpi_newsletter_log',
    'wpi_license_valid', 'wpi_license_warning', 'wpi_lifetime_slots',
    'ca_license_key', 'wpi_lifetime_claimed_count',
    'wpi_google_fonts_html',
];
foreach ( $options as $opt ) {
    delete_option( $opt );
}

// Remove all transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpi_%'" );

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ca_backups" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpi_brain_memories" );

// Remove post meta
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wpi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_ca_%'" );

// Remove user meta
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_wpi_%'" );

// Remove mu-plugins created by WPilot
$mu_files = [
    WPMU_PLUGIN_DIR . '/wpilot-disable-xmlrpc.php',
    WPMU_PLUGIN_DIR . '/wpilot-security-headers.php',
];
foreach ( $mu_files as $f ) {
    if ( file_exists( $f ) ) @unlink( $f );
}

// Remove PWA files
$pwa_files = [ABSPATH . 'manifest.json', ABSPATH . 'sw.js'];
foreach ( $pwa_files as $f ) {
    if ( file_exists( $f ) ) @unlink( $f );
}

// Clear scheduled events
wp_clear_scheduled_hook( 'wpi_tracking_heartbeat' );
wp_clear_scheduled_hook( 'wpi_flush_queue' );
wp_clear_scheduled_hook( 'wpi_shadow_test_event' );
