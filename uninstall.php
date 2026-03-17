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

// Remove all transients (wpi_ and wpilot_ prefixes)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpilot_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpilot_%'" );

// Remove all wpilot_ options not in the explicit list
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpilot_%' AND option_name != 'wpilot_prompts_used'" );

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ca_backups" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpi_brain_memories" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpilot_training" );

// Remove post meta
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wpi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_ca_%'" );

// Remove user meta
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_wpi_%'" );

// Remove PWA files
$pwa_files = [ABSPATH . 'manifest.json', ABSPATH . 'sw.js'];
foreach ( $pwa_files as $f ) {
    if ( file_exists( $f ) ) @unlink( $f );
}

// Delete screenshots directory
$upload_dir = wp_upload_dir()['basedir'];
$ss_dir = $upload_dir . '/wpilot-screenshots';
if ( is_dir( $ss_dir ) ) {
    $files = glob( $ss_dir . '/*' );
    foreach ( $files as $f ) { if ( is_file( $f ) ) @unlink( $f ); }
    @rmdir( $ss_dir );
}

// Delete receipt and CSV files
$receipts = glob( $upload_dir . '/wpilot-receipt-*.txt' );
if ( $receipts ) foreach ( $receipts as $r ) @unlink( $r );
$csvs = glob( $upload_dir . '/wpilot-*.csv' );
if ( $csvs ) foreach ( $csvs as $c ) @unlink( $c );

// Delete backups directory
$backups_dir = WP_CONTENT_DIR . '/wpilot-backups';
if ( is_dir( $backups_dir ) ) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $backups_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iter as $item ) {
        $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
    }
    @rmdir( $backups_dir );
}

// Remove mu-plugins created by WPilot (glob for any wpilot-*.php)
$mu_glob = glob( WPMU_PLUGIN_DIR . '/wpilot-*.php' );
if ( $mu_glob ) foreach ( $mu_glob as $f ) @unlink( $f );

// Clear scheduled events
wp_clear_scheduled_hook( 'wpi_tracking_heartbeat' );
wp_clear_scheduled_hook( 'wpi_flush_queue' );
wp_clear_scheduled_hook( 'wpi_shadow_test_event' );
wp_clear_scheduled_hook( 'wpilot_daily_cleanup' );
wp_clear_scheduled_hook( 'wpilot_heartbeat' );
wp_clear_scheduled_hook( 'wpilot_run_scheduled' );
