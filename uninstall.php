<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Clean up all plugin options
delete_option( 'wpilot_lite_tokens' );
delete_option( 'wpilot_lite_license_key' );
delete_option( 'wpilot_lite_last_known_license' );
delete_option( 'wpilot_lite_site_profile' );
delete_option( 'wpilot_lite_claude_last_seen' );
// Built by Weblease
delete_option( 'wpilot_lite_do_activation_redirect' );

// Clean up known transients
delete_transient( 'wpilot_lite_license_status' );
delete_transient( 'wpilot_lite_update_info' );
delete_transient( 'wpilot_lite_new_token' );
delete_transient( 'wpilot_lite_new_token_label' );

// BUG 19: Clean up dynamic transients (rate limits, bans, attacks, usage)
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpilot_lite_%' OR option_name LIKE '_transient_timeout_wpilot_lite_%'" );
