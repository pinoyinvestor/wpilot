<?php
if ( ! defined( 'ABSPATH' ) ) exit;

//

define( 'WPI_CHECKOUT_ENDPOINT', 'https://weblease.se/api/checkout/create-session' );

// Built by Weblease

// ── AJAX: Create Stripe checkout session from plugin ─────────
add_action( 'wp_ajax_wpi_create_checkout', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.');

    $plan  = sanitize_text_field( $_POST['plan'] ?? 'pro' );
    $email = sanitize_email( get_option('wpilot_user_email', get_option('admin_email', '')) );

    $valid_plans = ['pro', 'team', 'lifetime'];
    if ( ! in_array($plan, $valid_plans) ) {
        wp_send_json_error('Invalid plan.');
    }

    // Call weblease.se to create Stripe session
    $response = wp_remote_post( WPI_CHECKOUT_ENDPOINT, [
        'timeout' => 15,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'plan'     => $plan,
            'email'    => $email,
            'site_url' => get_site_url(),
        ]),
    ]);

    if ( is_wp_error($response) ) {
        wp_send_json_error('Could not connect to payment server. Try again later.');
    }

    $body = json_decode( wp_remote_retrieve_body($response), true );

    if ( ! empty($body['url']) ) {
        wp_send_json_success([ 'checkout_url' => $body['url'] ]);
    }

    wp_send_json_error( $body['error'] ?? 'Payment server error. Try again later.' );
});

// ── AJAX: Check if license was activated after payment ───────
add_action( 'wp_ajax_wpi_check_payment_status', function() {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error();

    $email = sanitize_email( get_option('wpilot_user_email', get_option('admin_email', '')) );

    // Check server for license by email
    $response = wp_remote_post( WPI_LICENSE_VALIDATE_URL, [
        'timeout' => 10,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'email'    => $email,
            'site_url' => get_site_url(),
        ]),
    ]);

    if ( is_wp_error($response) ) {
        wp_send_json_error('Could not reach license server.');
    }

    $data = json_decode( wp_remote_retrieve_body($response), true );

    if ( ! empty($data['valid']) && ! empty($data['type']) && $data['type'] !== 'free' ) {
        // Activate license locally
        $type = sanitize_text_field($data['type']);
        update_option('wpilot_license_type', $type);
        update_option('ca_license_status', 'active');
        delete_transient('wpi_license_valid');

        wpilot_log_activity('license_upgraded',
            'License upgraded to ' . ucfirst($type),
            'Via Stripe payment'
        );

        wp_send_json_success([
            'activated' => true,
            'type'      => $type,
            'message'   => 'License activated! You now have unlimited access.',
        ]);
    }

    wp_send_json_success([
        'activated' => false,
        'message'   => 'Payment not yet processed. It may take a moment.',
    ]);
});
