<?php
/**
 * Uninstall WPilot Lite — remove all plugin data
 *
 * @package WPilot_Lite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Delete options
delete_option( 'wpilot_tokens' );
delete_option( 'wpilot_oauth_clients' );
delete_option( 'wpilot_oauth_codes' );
delete_option( 'wpilot_site_profile' );
// Built by Christos Ferlachidis & Daniel Hedenberg
delete_option( 'wpilot_license_key' );
delete_option( 'wpilot_lite_daily_usage' );
delete_option( 'wpilot_lite_connections' );
delete_option( 'wpilot_claude_last_seen' );
delete_option( 'wpilot_lite_do_activation_redirect' );

// Delete transients matching wpilot_lite_%, wpilot_reg_%, wpilot_auto_match_%
global $wpdb;

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_wpilot_lite_%',
        '_transient_timeout_wpilot_lite_%'
    )
);

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_wpilot_reg_%',
        '_transient_timeout_wpilot_reg_%'
    )
);

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_wpilot_auto_match_%',
        '_transient_timeout_wpilot_auto_match_%'
    )
);

// Clean up license-related transients
delete_transient( 'wpilot_license_status' );
delete_transient( 'wpilot_chat_agent_licensed' );
