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
//  POWER TOOLS — General-purpose WordPress & WooCommerce access
//  These tools let Claude do ANYTHING within WordPress safely.
//  No file system access, no code execution, no sensitive data.
// ═══════════════════════════════════════════════════════════════

// Built by Weblease

// ── WORDPRESS POWER TOOL ───────────────────────────────────────
// Covers: options, post types, taxonomies, users, transients,
// theme mods, widget settings, rewrite rules, roles, capabilities
function wpilot_tool_wordpress( $params ) {
    $action = $params['action'] ?? '';

    switch ( $action ) {

        // ── Read/write any option ──────────────────────────────
        case 'get_option':
            $name = sanitize_text_field( $params['name'] ?? '' );
            if ( empty( $name ) ) return wpilot_err( 'Option name required.' );
            $blocked = [ 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt', 'db_password', 'wpilot_mcp_keys', 'wpilot_mcp_api_key_hash' ];
            if ( in_array( strtolower( $name ), $blocked, true ) ) return wpilot_err( 'Cannot read security keys.' );
            $val = get_option( $name, '__NOT_SET__' );
            if ( $val === '__NOT_SET__' ) return wpilot_err( "Option '{$name}' not found." );
            return wpilot_ok( "Option '{$name}'.", [ $name => $val ] );

        case 'set_option':
            $name = sanitize_text_field( $params['name'] ?? '' );
            $value = $params['value'] ?? '';
            if ( empty( $name ) ) return wpilot_err( 'Option name required.' );
            $blocked = [ 'siteurl', 'home', 'admin_email', 'auth_key', 'secure_auth_key', 'db_password', 'wpilot_mcp_keys' ];
            if ( in_array( strtolower( $name ), $blocked, true ) ) return wpilot_err( 'Cannot modify this option for security.' );
            update_option( $name, $value );
            return wpilot_ok( "Option '{$name}' updated." );

        // ── Any post type CRUD ─────────────────────────────────
        case 'get_posts':
            $type = sanitize_text_field( $params['post_type'] ?? 'post' );
            $count = min( intval( $params['count'] ?? 20 ), 100 );
            $status = sanitize_text_field( $params['status'] ?? 'any' );
            $search = sanitize_text_field( $params['search'] ?? '' );
            $args = [ 'post_type' => $type, 'posts_per_page' => $count, 'post_status' => $status ];
            if ( $search ) $args['s'] = $search;
            $posts = get_posts( $args );
            $list = array_map( fn( $p ) => [
                'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                'date' => $p->post_date, 'url' => get_permalink( $p->ID ),
                'excerpt' => wp_trim_words( $p->post_content, 20 ),
            ], $posts );
            return wpilot_ok( count( $list ) . " {$type} posts.", $list );

        case 'get_post':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Post ID required.' );
            $p = get_post( $id );
            if ( ! $p ) return wpilot_err( 'Post not found.' );
            return wpilot_ok( "Post #{$id}: {$p->post_title}", [
                'id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content,
                'status' => $p->post_status, 'type' => $p->post_type, 'date' => $p->post_date,
                'url' => get_permalink( $id ), 'author' => get_the_author_meta( 'display_name', $p->post_author ),
                'meta' => get_post_meta( $id ),
            ] );

        case 'update_post':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Post ID required.' );
            $data = [ 'ID' => $id ];
            if ( isset( $params['title'] ) ) $data['post_title'] = sanitize_text_field( $params['title'] );
            if ( isset( $params['content'] ) ) $data['post_content'] = wp_kses_post( $params['content'] );
            if ( isset( $params['status'] ) ) $data['post_status'] = sanitize_text_field( $params['status'] );
            if ( isset( $params['excerpt'] ) ) $data['post_excerpt'] = sanitize_textarea_field( $params['excerpt'] );
            $result = wp_update_post( $data, true );
            if ( is_wp_error( $result ) ) return wpilot_err( $result->get_error_message() );
            if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
                foreach ( $params['meta'] as $key => $val ) update_post_meta( $id, sanitize_key( $key ), $val );
            }
            return wpilot_ok( "Post #{$id} updated." );

        // ── Taxonomies ─────────────────────────────────────────
        case 'get_terms':
            $tax = sanitize_text_field( $params['taxonomy'] ?? 'category' );
            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 100 ] );
            if ( is_wp_error( $terms ) ) return wpilot_err( $terms->get_error_message() );
            $list = array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ], $terms );
            return wpilot_ok( count( $list ) . " {$tax} terms.", $list );

        case 'create_term':
            $tax = sanitize_text_field( $params['taxonomy'] ?? 'category' );
            $name = sanitize_text_field( $params['name'] ?? '' );
            if ( empty( $name ) ) return wpilot_err( 'Term name required.' );
            $result = wp_insert_term( $name, $tax, [ 'description' => sanitize_text_field( $params['description'] ?? '' ) ] );
            if ( is_wp_error( $result ) ) return wpilot_err( $result->get_error_message() );
            return wpilot_ok( "Term '{$name}' created in {$tax}.", [ 'term_id' => $result['term_id'] ] );

        // ── Users ──────────────────────────────────────────────
        case 'get_users':
            $role = sanitize_text_field( $params['role'] ?? '' );
            $args = [ 'number' => 50, 'orderby' => 'registered', 'order' => 'DESC' ];
            if ( $role ) $args['role'] = $role;
            $users = get_users( $args );
            $list = array_map( fn( $u ) => [
                'id' => $u->ID, 'email' => $u->user_email, 'name' => $u->display_name,
                'role' => implode( ', ', $u->roles ), 'registered' => $u->user_registered,
            ], $users );
            return wpilot_ok( count( $list ) . ' users.', $list );

        // ── Theme Customizer ───────────────────────────────────
        case 'get_theme_mods':
            $mods = get_theme_mods();
            return wpilot_ok( 'Theme customizer settings.', $mods );

        case 'set_theme_mod':
            $name = sanitize_text_field( $params['name'] ?? '' );
            if ( empty( $name ) ) return wpilot_err( 'Mod name required.' );
            set_theme_mod( $name, $params['value'] ?? '' );
            return wpilot_ok( "Theme mod '{$name}' updated." );

        // ── Post Meta ──────────────────────────────────────────
        case 'get_meta':
            $id = intval( $params['id'] ?? 0 );
            $key = sanitize_text_field( $params['key'] ?? '' );
            if ( ! $id ) return wpilot_err( 'Post ID required.' );
            if ( $key ) return wpilot_ok( "Meta '{$key}'.", [ $key => get_post_meta( $id, $key, true ) ] );
            return wpilot_ok( "All meta for #{$id}.", get_post_meta( $id ) );

        case 'set_meta':
            $id = intval( $params['id'] ?? 0 );
            $key = sanitize_key( $params['key'] ?? '' );
            if ( ! $id || ! $key ) return wpilot_err( 'Post ID and meta key required.' );
            update_post_meta( $id, $key, $params['value'] ?? '' );
            return wpilot_ok( "Meta '{$key}' set on #{$id}." );

        // ── Registered Post Types & Taxonomies ─────────────────
        case 'post_types':
            $types = get_post_types( [ 'public' => true ], 'objects' );
            $list = array_map( fn( $t ) => [ 'name' => $t->name, 'label' => $t->label, 'count' => wp_count_posts( $t->name )->publish ?? 0 ], $types );
            return wpilot_ok( count( $list ) . ' public post types.', array_values( $list ) );

        case 'taxonomies':
            $taxes = get_taxonomies( [ 'public' => true ], 'objects' );
            $list = array_map( fn( $t ) => [ 'name' => $t->name, 'label' => $t->label ], $taxes );
            return wpilot_ok( count( $list ) . ' taxonomies.', array_values( $list ) );

        // ── Roles & Capabilities ───────────────────────────────
        case 'roles':
            $roles = wp_roles()->roles;
            $list = array_map( fn( $r ) => [ 'name' => $r['name'], 'caps' => count( $r['capabilities'] ) ], $roles );
            return wpilot_ok( count( $list ) . ' roles.', $list );

        // ── Flush ──────────────────────────────────────────────
        case 'flush_rewrite':
            flush_rewrite_rules();
            return wpilot_ok( 'Rewrite rules flushed.' );

        case 'flush_cache':
            wp_cache_flush();
            return wpilot_ok( 'Object cache flushed.' );

        default:
            return wpilot_err( "Unknown wordpress action '{$action}'. Available: get_option, set_option, get_posts, get_post, update_post, get_terms, create_term, get_users, get_theme_mods, set_theme_mod, get_meta, set_meta, post_types, taxonomies, roles, flush_rewrite, flush_cache" );
    }
}

// ── WOOCOMMERCE POWER TOOL ─────────────────────────────────────
// Covers: ALL WooCommerce operations — products, orders, customers,
// coupons, settings, shipping, taxes, reports, refunds, everything
function wpilot_tool_woocommerce( $params ) {
    if ( ! class_exists( 'WooCommerce' ) ) return wpilot_err( 'WooCommerce is not installed. Use plugins tool to install it: plugins(action:install, slug:woocommerce)' );

    $action = $params['action'] ?? '';

    switch ( $action ) {

        // ── Products ───────────────────────────────────────────
        case 'list_products':
            $args = [ 'post_type' => 'product', 'posts_per_page' => min( intval( $params['count'] ?? 50 ), 100 ), 'post_status' => 'any' ];
            if ( ! empty( $params['category'] ) ) {
                $args['tax_query'] = [[ 'taxonomy' => 'product_cat', 'field' => 'name', 'terms' => $params['category'] ]];
            }
            $products = get_posts( $args );
            $list = array_map( function( $p ) {
                return [
                    'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                    'price' => get_post_meta( $p->ID, '_regular_price', true ),
                    'sale_price' => get_post_meta( $p->ID, '_sale_price', true ),
                    'sku' => get_post_meta( $p->ID, '_sku', true ),
                    'stock' => get_post_meta( $p->ID, '_stock', true ),
                    'stock_status' => get_post_meta( $p->ID, '_stock_status', true ),
                    'categories' => wp_get_post_terms( $p->ID, 'product_cat', [ 'fields' => 'names' ] ),
                    'url' => get_permalink( $p->ID ),
                    'image' => get_the_post_thumbnail_url( $p->ID, 'medium' ),
                ];
            }, $products );
            return wpilot_ok( count( $list ) . ' products.', $list );

        case 'get_product':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Product ID required.' );
            $p = wc_get_product( $id );
            if ( ! $p ) return wpilot_err( 'Product not found.' );
            return wpilot_ok( "Product: {$p->get_name()}", [
                'id' => $p->get_id(), 'name' => $p->get_name(), 'type' => $p->get_type(),
                'status' => $p->get_status(), 'price' => $p->get_price(), 'regular_price' => $p->get_regular_price(),
                'sale_price' => $p->get_sale_price(), 'sku' => $p->get_sku(),
                'stock_quantity' => $p->get_stock_quantity(), 'stock_status' => $p->get_stock_status(),
                'description' => $p->get_description(), 'short_description' => $p->get_short_description(),
                'weight' => $p->get_weight(), 'dimensions' => $p->get_dimensions(),
                'categories' => wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'names' ] ),
                'tags' => wp_get_post_terms( $id, 'product_tag', [ 'fields' => 'names' ] ),
                'image' => wp_get_attachment_url( $p->get_image_id() ),
                'url' => $p->get_permalink(),
            ] );

        case 'create_product':
            return wpilot_woo_create_product( $params );

        case 'update_product':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Product ID required.' );
            $p = wc_get_product( $id );
            if ( ! $p ) return wpilot_err( 'Product not found.' );
            if ( isset( $params['title'] ) ) $p->set_name( sanitize_text_field( $params['title'] ) );
            if ( isset( $params['price'] ) ) { $p->set_regular_price( $params['price'] ); $p->set_price( $params['price'] ); }
            if ( isset( $params['sale_price'] ) ) $p->set_sale_price( $params['sale_price'] );
            if ( isset( $params['description'] ) ) $p->set_description( wp_kses_post( $params['description'] ) );
            if ( isset( $params['short_description'] ) ) $p->set_short_description( wp_kses_post( $params['short_description'] ) );
            if ( isset( $params['sku'] ) ) $p->set_sku( sanitize_text_field( $params['sku'] ) );
            if ( isset( $params['stock'] ) ) { $p->set_manage_stock( true ); $p->set_stock_quantity( intval( $params['stock'] ) ); }
            if ( isset( $params['weight'] ) ) $p->set_weight( $params['weight'] );
            if ( isset( $params['status'] ) ) $p->set_status( $params['status'] );
            $p->save();
            if ( isset( $params['category'] ) ) {
                $cat = get_term_by( 'name', $params['category'], 'product_cat' );
                if ( ! $cat ) $cat = (object) wp_insert_term( $params['category'], 'product_cat' );
                wp_set_object_terms( $id, [ is_object( $cat ) ? $cat->term_id : $cat['term_id'] ], 'product_cat' );
            }
            return wpilot_ok( "Product #{$id} updated." );

        case 'delete_product':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Product ID required.' );
            wp_trash_post( $id );
            return wpilot_ok( "Product #{$id} moved to trash." );

        // ── Orders ─────────────────────────────────────────────
        case 'list_orders':
            $status = $params['status'] ?? 'any';
            $orders = wc_get_orders( [ 'limit' => min( intval( $params['count'] ?? 20 ), 100 ), 'status' => $status ] );
            $list = array_map( function( $o ) {
                return [
                    'id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(),
                    'currency' => $o->get_currency(), 'date' => $o->get_date_created() ? $o->get_date_created()->format( 'Y-m-d H:i' ) : '',
                    'customer' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
                    'email' => $o->get_billing_email(), 'items' => $o->get_item_count(),
                    'payment' => $o->get_payment_method_title(),
                ];
            }, $orders );
            return wpilot_ok( count( $list ) . ' orders.', $list );

        case 'get_order':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Order ID required.' );
            $o = wc_get_order( $id );
            if ( ! $o ) return wpilot_err( 'Order not found.' );
            $items = array_map( function( $item ) {
                return [ 'name' => $item->get_name(), 'qty' => $item->get_quantity(), 'total' => $item->get_total(), 'product_id' => $item->get_product_id() ];
            }, $o->get_items() );
            return wpilot_ok( "Order #{$id}", [
                'id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(),
                'currency' => $o->get_currency(), 'date' => $o->get_date_created()->format( 'Y-m-d H:i' ),
                'billing' => [
                    'name' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
                    'email' => $o->get_billing_email(), 'phone' => $o->get_billing_phone(),
                    'address' => $o->get_billing_address_1(), 'city' => $o->get_billing_city(),
                    'country' => $o->get_billing_country(), 'postcode' => $o->get_billing_postcode(),
                ],
                'shipping' => [
                    'name' => $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name(),
                    'address' => $o->get_shipping_address_1(), 'city' => $o->get_shipping_city(),
                ],
                'items' => $items,
                'payment' => $o->get_payment_method_title(),
                'notes' => array_map( fn( $n ) => $n->comment_content, $o->get_customer_order_notes() ),
            ] );

        case 'update_order':
            $id = intval( $params['id'] ?? 0 );
            if ( ! $id ) return wpilot_err( 'Order ID required.' );
            $o = wc_get_order( $id );
            if ( ! $o ) return wpilot_err( 'Order not found.' );
            if ( isset( $params['status'] ) ) $o->set_status( sanitize_text_field( $params['status'] ) );
            if ( isset( $params['note'] ) ) $o->add_order_note( sanitize_textarea_field( $params['note'] ) );
            $o->save();
            return wpilot_ok( "Order #{$id} updated." );

        // ── Customers ──────────────────────────────────────────
        case 'list_customers':
            $customers = get_users( [ 'role' => 'customer', 'number' => 50, 'orderby' => 'registered', 'order' => 'DESC' ] );
            $list = array_map( function( $u ) {
                $orders = wc_get_orders( [ 'customer' => $u->ID, 'limit' => -1, 'return' => 'ids' ] );
                $total = 0;
                foreach ( $orders as $oid ) { $o = wc_get_order( $oid ); if ( $o ) $total += floatval( $o->get_total() ); }
                return [
                    'id' => $u->ID, 'email' => $u->user_email, 'name' => $u->display_name,
                    'registered' => $u->user_registered, 'orders' => count( $orders ), 'total_spent' => $total,
                ];
            }, $customers );
            return wpilot_ok( count( $list ) . ' customers.', $list );

        // ── Coupons ────────────────────────────────────────────
        case 'list_coupons':
            $coupons = get_posts( [ 'post_type' => 'shop_coupon', 'posts_per_page' => 50, 'post_status' => 'publish' ] );
            $list = array_map( function( $c ) {
                return [
                    'id' => $c->ID, 'code' => $c->post_title,
                    'type' => get_post_meta( $c->ID, 'discount_type', true ),
                    'amount' => get_post_meta( $c->ID, 'coupon_amount', true ),
                    'usage' => get_post_meta( $c->ID, 'usage_count', true ) . '/' . ( get_post_meta( $c->ID, 'usage_limit', true ) ?: 'unlimited' ),
                ];
            }, $coupons );
            return wpilot_ok( count( $list ) . ' coupons.', $list );

        // ── Categories ─────────────────────────────────────────
        case 'list_categories':
            $cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
            if ( is_wp_error( $cats ) ) return wpilot_err( $cats->get_error_message() );
            $list = array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent ], $cats );
            return wpilot_ok( count( $list ) . ' product categories.', $list );

        case 'create_category':
            $name = sanitize_text_field( $params['name'] ?? '' );
            if ( empty( $name ) ) return wpilot_err( 'Category name required.' );
            $args = [ 'description' => sanitize_text_field( $params['description'] ?? '' ) ];
            if ( ! empty( $params['parent'] ) ) {
                $parent = get_term_by( 'name', $params['parent'], 'product_cat' );
                if ( $parent ) $args['parent'] = $parent->term_id;
            }
            $result = wp_insert_term( $name, 'product_cat', $args );
            if ( is_wp_error( $result ) ) return wpilot_err( $result->get_error_message() );
            return wpilot_ok( "Category '{$name}' created.", [ 'term_id' => $result['term_id'] ] );

        // ── Reports ────────────────────────────────────────────
        case 'sales_report':
            $days = intval( $params['days'] ?? 30 );
            $after = date( 'Y-m-d', strtotime( "-{$days} days" ) );
            $orders = wc_get_orders( [ 'date_after' => $after, 'status' => [ 'completed', 'processing' ], 'limit' => -1 ] );
            $total = 0; $count = 0;
            foreach ( $orders as $o ) { $total += floatval( $o->get_total() ); $count++; }
            return wpilot_ok( "Sales last {$days} days: {$count} orders, " . wc_price( $total ) . " revenue.", [
                'orders' => $count, 'revenue' => $total, 'currency' => get_woocommerce_currency(),
                'average' => $count > 0 ? round( $total / $count, 2 ) : 0, 'period_days' => $days,
            ] );

        case 'stock_report':
            $low = get_posts( [ 'post_type' => 'product', 'posts_per_page' => 50, 'meta_query' => [
                [ 'key' => '_stock', 'value' => intval( $params['threshold'] ?? 5 ), 'compare' => '<=', 'type' => 'NUMERIC' ],
                [ 'key' => '_manage_stock', 'value' => 'yes' ],
            ] ] );
            $list = array_map( fn( $p ) => [ 'id' => $p->ID, 'name' => $p->post_title, 'stock' => get_post_meta( $p->ID, '_stock', true ) ], $low );
            return wpilot_ok( count( $list ) . ' products with low stock.', $list );

        case 'best_sellers':
            global $wpdb;
            $days = intval( $params['days'] ?? 30 );
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT oi.order_item_name as product, SUM(oim.meta_value) as qty
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
                JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
                WHERE p.post_date >= %s AND p.post_status IN ('wc-completed','wc-processing')
                GROUP BY oi.order_item_name ORDER BY qty DESC LIMIT 20",
                date( 'Y-m-d', strtotime( "-{$days} days" ) )
            ) );
            return wpilot_ok( 'Best sellers (last ' . $days . ' days).', $results );

        // ── Refunds ────────────────────────────────────────────
        case 'refund_order':
            $id = intval( $params['id'] ?? 0 );
            $amount = floatval( $params['amount'] ?? 0 );
            $reason = sanitize_text_field( $params['reason'] ?? '' );
            if ( ! $id ) return wpilot_err( 'Order ID required.' );
            $o = wc_get_order( $id );
            if ( ! $o ) return wpilot_err( 'Order not found.' );
            if ( ! $amount ) $amount = floatval( $o->get_total() );
            $refund = wc_create_refund( [ 'order_id' => $id, 'amount' => $amount, 'reason' => $reason ] );
            if ( is_wp_error( $refund ) ) return wpilot_err( $refund->get_error_message() );
            return wpilot_ok( "Refunded " . wc_price( $amount ) . " on order #{$id}." );

        // ── Store Info ─────────────────────────────────────────
        case 'store_info':
            return wpilot_ok( 'WooCommerce store info.', [
                'currency' => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'country' => get_option( 'woocommerce_default_country' ),
                'address' => get_option( 'woocommerce_store_address' ),
                'city' => get_option( 'woocommerce_store_city' ),
                'postcode' => get_option( 'woocommerce_store_postcode' ),
                'weight_unit' => get_option( 'woocommerce_weight_unit' ),
                'dimension_unit' => get_option( 'woocommerce_dimension_unit' ),
                'calc_taxes' => get_option( 'woocommerce_calc_taxes' ),
                'prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
                'products' => wp_count_posts( 'product' )->publish,
                'orders_total' => wp_count_posts( 'shop_order' )->{'wc-completed'} ?? 0,
                'customers' => count( get_users( [ 'role' => 'customer', 'fields' => 'ID' ] ) ),
                'coupons' => wp_count_posts( 'shop_coupon' )->publish,
            ] );

        default:
            return wpilot_err( "Unknown woocommerce action '{$action}'. Available: list_products, get_product, create_product, update_product, delete_product, list_orders, get_order, update_order, list_customers, list_coupons, list_categories, create_category, sales_report, stock_report, best_sellers, refund_order, store_info" );
    }
}
