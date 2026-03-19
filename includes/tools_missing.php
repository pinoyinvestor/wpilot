<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  Missing tool implementations — fills all gaps in MCP routing
// ═══════════════════════════════════════════════════════════════

// Built by Weblease

// ── WooCommerce: Create Product ────────────────────────────────
function wpilot_woo_create_product( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    $title = sanitize_text_field( $params['title'] ?? '' );
    if ( empty( $title ) ) return wpilot_err( 'Product title required.' );

    $post_id = wp_insert_post( [
        'post_title'   => $title,
        'post_content' => wp_kses_post( $params['description'] ?? '' ),
        'post_status'  => 'publish',
        'post_type'    => 'product',
    ] );

    if ( is_wp_error( $post_id ) ) return wpilot_err( $post_id->get_error_message() );

    // Set product data
    update_post_meta( $post_id, '_regular_price', sanitize_text_field( $params['price'] ?? '0' ) );
    update_post_meta( $post_id, '_price', sanitize_text_field( $params['price'] ?? '0' ) );
    if ( isset( $params['sale_price'] ) ) update_post_meta( $post_id, '_sale_price', sanitize_text_field( $params['sale_price'] ) );
    update_post_meta( $post_id, '_sku', sanitize_text_field( $params['sku'] ?? '' ) );
    update_post_meta( $post_id, '_stock_status', $params['in_stock'] ?? true ? 'instock' : 'outofstock' );
    update_post_meta( $post_id, '_manage_stock', ! empty( $params['stock'] ) ? 'yes' : 'no' );
    if ( isset( $params['stock'] ) ) update_post_meta( $post_id, '_stock', intval( $params['stock'] ) );
    update_post_meta( $post_id, '_weight', sanitize_text_field( $params['weight'] ?? '' ) );
    wp_set_object_terms( $post_id, 'simple', 'product_type' );

    // Category
    if ( ! empty( $params['category'] ) ) {
        $cat = get_term_by( 'name', $params['category'], 'product_cat' );
        if ( ! $cat ) $cat = wp_insert_term( $params['category'], 'product_cat' );
        if ( ! is_wp_error( $cat ) ) {
            $cat_id = is_object( $cat ) ? $cat->term_id : $cat['term_id'];
            wp_set_object_terms( $post_id, $cat_id, 'product_cat' );
        }
    }

    // Image from URL
    if ( ! empty( $params['image_url'] ) ) {
        $img_id = wpilot_upload_image_from_url( $params['image_url'], $title );
        if ( $img_id ) set_post_thumbnail( $post_id, $img_id );
    }

    return wpilot_ok( "Product '{$title}' created (ID: {$post_id}).", [
        'product_id' => $post_id,
        'title'      => $title,
        'price'      => $params['price'] ?? '0',
        'url'        => get_permalink( $post_id ),
    ] );
}

// ── WooCommerce: Update Store Settings ─────────────────────────
function wpilot_woo_update_store_settings( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    $updated = [];
    $map = [
        'currency'         => 'woocommerce_currency',
        'country'          => 'woocommerce_default_country',
        'address'          => 'woocommerce_store_address',
        'city'             => 'woocommerce_store_city',
        'postcode'         => 'woocommerce_store_postcode',
        'weight_unit'      => 'woocommerce_weight_unit',
        'dimension_unit'   => 'woocommerce_dimension_unit',
        'calc_taxes'       => 'woocommerce_calc_taxes',
        'prices_include_tax' => 'woocommerce_prices_include_tax',
    ];

    foreach ( $map as $key => $option ) {
        if ( isset( $params[ $key ] ) ) {
            update_option( $option, sanitize_text_field( $params[ $key ] ) );
            $updated[] = $key;
        }
    }

    if ( empty( $updated ) ) return wpilot_err( 'No settings provided. Available: ' . implode( ', ', array_keys( $map ) ) );
    return wpilot_ok( 'Store settings updated: ' . implode( ', ', $updated ) . '.', [ 'updated' => $updated ] );
}

// ── WooCommerce: Enable Payment Method ─────────────────────────
function wpilot_woo_enable_payment( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    $gateways = WC()->payment_gateways->payment_gateways();
    $method = $params['method'] ?? '';

    if ( empty( $method ) ) {
        $list = array_map( fn( $g ) => $g->id . ' (' . $g->get_title() . ') — ' . ( $g->enabled === 'yes' ? 'enabled' : 'disabled' ), $gateways );
        return wpilot_ok( 'Payment methods:', $list );
    }

    if ( isset( $gateways[ $method ] ) ) {
        $gateways[ $method ]->enabled = 'yes';
        update_option( 'woocommerce_' . $method . '_settings', array_merge(
            get_option( 'woocommerce_' . $method . '_settings', [] ),
            [ 'enabled' => 'yes' ]
        ) );
        return wpilot_ok( "Payment method '{$method}' enabled." );
    }

    return wpilot_err( "Payment method '{$method}' not found. Available: " . implode( ', ', array_keys( $gateways ) ) );
}

// ── WooCommerce: Configure Email ───────────────────────────────
function wpilot_woo_configure_email( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    $emails = WC()->mailer()->get_emails();
    $email_id = $params['email'] ?? '';

    if ( empty( $email_id ) ) {
        $list = array_map( fn( $e ) => $e->id . ': ' . $e->get_title() . ' (' . ( $e->is_enabled() ? 'on' : 'off' ) . ')', $emails );
        return wpilot_ok( 'WooCommerce emails:', $list );
    }

    return wpilot_ok( 'Email configuration requires manual setup in WooCommerce > Settings > Emails.' );
}

// ── WooCommerce: Setup Checkout ────────────────────────────────
function wpilot_woo_setup_checkout( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    $updated = [];
    if ( isset( $params['enable_guest'] ) ) {
        update_option( 'woocommerce_enable_guest_checkout', $params['enable_guest'] ? 'yes' : 'no' );
        $updated[] = 'guest_checkout';
    }
    if ( isset( $params['enable_signup'] ) ) {
        update_option( 'woocommerce_enable_signup_and_login_from_checkout', $params['enable_signup'] ? 'yes' : 'no' );
        $updated[] = 'signup_at_checkout';
    }
    if ( isset( $params['terms_page_id'] ) ) {
        update_option( 'woocommerce_terms_page_id', intval( $params['terms_page_id'] ) );
        $updated[] = 'terms_page';
    }

    if ( empty( $updated ) ) return wpilot_err( 'No checkout settings provided. Available: enable_guest, enable_signup, terms_page_id' );
    return wpilot_ok( 'Checkout updated: ' . implode( ', ', $updated ) . '.', [ 'updated' => $updated ] );
}

// ── WooCommerce: Tax Rates ─────────────────────────────────────
function wpilot_woo_set_tax_rate( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    update_option( 'woocommerce_calc_taxes', 'yes' );
    global $wpdb;

    $country = sanitize_text_field( $params['country'] ?? '*' );
    $rate    = floatval( $params['rate'] ?? 25 );
    $name    = sanitize_text_field( $params['name'] ?? 'Moms' );

    $wpdb->insert( $wpdb->prefix . 'woocommerce_tax_rates', [
        'tax_rate_country'  => $country,
        'tax_rate'          => $rate,
        'tax_rate_name'     => $name,
        'tax_rate_priority' => 1,
        'tax_rate_order'    => 0,
        'tax_rate_shipping' => 1,
    ] );

    return wpilot_ok( "Tax rate added: {$name} {$rate}% for {$country}." );
}

// ── WooCommerce: Shipping Classes ──────────────────────────────
function wpilot_woo_create_shipping_class( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );

    $name = sanitize_text_field( $params['name'] ?? '' );
    if ( empty( $name ) ) return wpilot_err( 'Shipping class name required.' );

    $result = wp_insert_term( $name, 'product_shipping_class', [
        'description' => sanitize_text_field( $params['description'] ?? '' ),
        'slug'        => sanitize_title( $params['slug'] ?? $name ),
    ] );

    if ( is_wp_error( $result ) ) return wpilot_err( $result->get_error_message() );
    return wpilot_ok( "Shipping class '{$name}' created.", [ 'term_id' => $result['term_id'] ] );
}

// ── WooCommerce: Update Shipping Zone ──────────────────────────
function wpilot_woo_update_shipping_zone( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce not active.' );
    return wpilot_ok( 'Shipping zones can be managed via woo_shipping tool with actions: zones, methods, rates.' );
}

// ── Plugin Install ─────────────────────────────────────────────
function wpilot_plugin_install_from_repo( $params ) {
    $slug = sanitize_text_field( $params['slug'] ?? '' );
    if ( empty( $slug ) ) return wpilot_err( 'Plugin slug required (e.g. "wordfence", "yoast-seo").' );

    // Security: only allow WordPress.org plugins
    if ( ! preg_match( '/^[a-z0-9\-]+$/', $slug ) ) return wpilot_err( 'Invalid plugin slug.' );

    include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    include_once ABSPATH . 'wp-admin/includes/file.php';

    $api = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
    if ( is_wp_error( $api ) ) return wpilot_err( "Plugin '{$slug}' not found on WordPress.org." );

    $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
    $result = $upgrader->install( $api->download_link );

    if ( is_wp_error( $result ) ) return wpilot_err( 'Install failed: ' . $result->get_error_message() );
    if ( ! $result ) return wpilot_err( 'Install failed.' );

    // Activate if requested
    if ( ! empty( $params['activate'] ) ) {
        $plugin_file = $upgrader->plugin_info();
        if ( $plugin_file ) activate_plugin( $plugin_file );
    }

    return wpilot_ok( "Plugin '{$api->name}' installed" . ( ! empty( $params['activate'] ) ? ' and activated' : '' ) . '.', [
        'name'    => $api->name,
        'version' => $api->version,
        'slug'    => $slug,
    ] );
}

// ── Cache/Performance Tools ────────────────────────────────────
function wpilot_cache_enable( $params ) {
    return wpilot_ok( 'For caching, install a cache plugin: WP Super Cache (slug: wp-super-cache) or LiteSpeed Cache (slug: litespeed-cache). Use plugins tool to install.' );
}

function wpilot_cache_purge( $params ) {
    wp_cache_flush();
    if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
    if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
    if ( function_exists( 'litespeed_purge_all' ) ) litespeed_purge_all();
    return wpilot_ok( 'Cache purged.' );
}

function wpilot_cache_configure( $params ) {
    return wpilot_ok( 'Cache configuration depends on the active cache plugin. Use debug(action:health) to check cache status.' );
}

// ── SMTP Configure ─────────────────────────────────────────────
function wpilot_smtp_configure( $params ) {
    $host = sanitize_text_field( $params['host'] ?? '' );
    $port = intval( $params['port'] ?? 587 );
    $user = sanitize_text_field( $params['user'] ?? '' );
    $pass = sanitize_text_field( $params['pass'] ?? '' );
    $from = sanitize_email( $params['from'] ?? '' );

    if ( empty( $host ) || empty( $user ) ) {
        return wpilot_err( 'SMTP requires: host, user, pass, port (default 587), from (email).' );
    }

    update_option( 'wpilot_smtp_host', $host );
    update_option( 'wpilot_smtp_port', $port );
    update_option( 'wpilot_smtp_user', $user );
    update_option( 'wpilot_smtp_pass', $pass );
    if ( $from ) update_option( 'wpilot_smtp_from', $from );

    return wpilot_ok( "SMTP configured: {$host}:{$port} as {$user}." );
}

// ── Backup Configure ───────────────────────────────────────────
function wpilot_backup_configure( $params ) {
    return wpilot_ok( 'Backups are automatic before every destructive WPilot action. For scheduled backups, install UpdraftPlus (slug: updraftplus) via the plugins tool.' );
}

// ── Security: 2FA ──────────────────────────────────────────────
function wpilot_security_enable_2fa( $params ) {
    return wpilot_ok( 'For two-factor authentication, install Wordfence (slug: wordfence) or Two Factor (slug: two-factor) via the plugins tool.' );
}

// ── Security: Firewall ─────────────────────────────────────────
function wpilot_security_enable_firewall( $params ) {
    return wpilot_ok( 'For a web application firewall, install Wordfence (slug: wordfence) via the plugins tool. It includes firewall, malware scan, and login security.' );
}

// ── Multilingual Tools ─────────────────────────────────────────
function wpilot_multilingual_setup( $params ) {
    return wpilot_ok( 'For multilingual support, install Polylang (slug: polylang) or WPML via the plugins tool. Then use translations tool actions.' );
}

function wpilot_multilingual_translate_page( $params ) {
    return wpilot_ok( 'Page translation requires Polylang or WPML plugin. Install one first via plugins tool.' );
}

function wpilot_multilingual_add_language( $params ) {
    return wpilot_ok( 'Adding languages requires Polylang or WPML plugin. Install one first via plugins tool.' );
}

function wpilot_multilingual_set_switcher( $params ) {
    return wpilot_ok( 'Language switcher requires Polylang or WPML plugin. Install one first via plugins tool.' );
}

function wpilot_multilingual_bulk_translate( $params ) {
    return wpilot_ok( 'Bulk translation requires Polylang or WPML plugin. Install one first via plugins tool.' );
}

// ── Settings: Get Option ───────────────────────────────────────
function wpilot_get_option_value( $params ) {
    $opt = sanitize_text_field( $params['option'] ?? '' );
    if ( empty( $opt ) ) return wpilot_err( 'Option name required.' );

    // Safety: block sensitive options
    $blocked = [ 'admin_password', 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'db_password' ];
    if ( in_array( strtolower( $opt ), $blocked, true ) ) return wpilot_err( 'Cannot read sensitive option.' );

    $val = get_option( $opt, '__NOT_FOUND__' );
    if ( $val === '__NOT_FOUND__' ) return wpilot_err( "Option '{$opt}' not found." );

    return wpilot_ok( "Option '{$opt}'.", [ $opt => $val ] );
}

// ── Image upload helper ────────────────────────────────────────
if ( ! function_exists( 'wpilot_upload_image_from_url' ) ) {
    function wpilot_upload_image_from_url( $url, $title = '' ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) return false;

        $file = [
            'name'     => basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'image.jpg',
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload( $file, 0, $title );
        if ( is_wp_error( $id ) ) { @unlink( $tmp ); return false; }
        return $id;
    }
}
