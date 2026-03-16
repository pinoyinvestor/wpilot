<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  PLUGIN CONFIGURATION TOOLS
//  WPilot kan konfigurera installerade plugins via AI-chatt.
//  Varje funktion skriver direkt till pluginets databastabeller
//  eller options — exakt samma sätt som pluginets egna UI.
// ═══════════════════════════════════════════════════════════════

function wpilot_run_plugin_tool( $tool, $params ) {
    switch ( $tool ) {

        // ══ AMELIA — Bokningssystem ════════════════════════════

        case 'amelia_create_service':
            return wpilot_amelia_create_service( $params );

        case 'amelia_create_employee':
            return wpilot_amelia_create_employee( $params );

        case 'amelia_set_working_hours':
            return wpilot_amelia_set_working_hours( $params );

        case 'amelia_create_category':
            return wpilot_amelia_create_category( $params );

        case 'amelia_update_settings':
            return wpilot_amelia_update_settings( $params );

        // ══ WOOCOMMERCE ════════════════════════════════════════

        case 'woo_create_product':
            return wpilot_woo_create_product( $params );

        case 'woo_update_shipping_zone':
            return wpilot_woo_update_shipping_zone( $params );

        case 'woo_enable_payment':
            return wpilot_woo_enable_payment( $params );

        case 'woo_set_tax_rate':
            return wpilot_woo_set_tax_rate( $params );

        case 'woo_create_shipping_class':
            return wpilot_woo_create_shipping_class( $params );

        case 'bulk_import_products':
            return wpilot_bulk_import_products($params);

        case 'woo_update_store_settings':
            return wpilot_woo_update_store_settings( $params );

        case 'woo_configure_stripe':
            return wpilot_woo_configure_stripe( $params );

        case 'woo_configure_email':
            return wpilot_woo_configure_email( $params );

        case 'woo_setup_checkout':
            return wpilot_woo_setup_checkout( $params );

        // ══ LEARNDASH ══════════════════════════════════════════

        case 'ld_create_course':
            return wpilot_ld_create_course( $params );

        case 'ld_create_lesson':
            return wpilot_ld_create_lesson( $params );

        case 'ld_create_quiz':
            return wpilot_ld_create_quiz( $params );

        case 'ld_set_course_price':
            return wpilot_ld_set_course_price( $params );

        case 'ld_enable_drip':
            return wpilot_ld_enable_drip( $params );

        // ══ GRAVITY FORMS ══════════════════════════════════════

        case 'gf_create_form':
            return wpilot_gf_create_form( $params );

        case 'gf_add_field':
            return wpilot_gf_add_field( $params );

        case 'gf_set_notification':
            return wpilot_gf_set_notification( $params );

        case 'gf_set_confirmation':
            return wpilot_gf_set_confirmation( $params );

        // ══ WPFORMS ════════════════════════════════════════════

        case 'wpf_create_form':
            return wpilot_wpf_create_form( $params );

        // ══ MEMBERPRESS ════════════════════════════════════════

        case 'mp_create_membership':
            return wpilot_mp_create_membership( $params );

        case 'mp_create_rule':
            return wpilot_mp_create_rule( $params );

        // ══ THE EVENTS CALENDAR ════════════════════════════════

        case 'tec_create_event':
            return wpilot_tec_create_event( $params );

        // ══ RANK MATH / YOAST ══════════════════════════════════

        case 'seo_set_site_settings':
            return wpilot_seo_set_site_settings( $params );

        case 'seo_enable_schema':
            return wpilot_seo_enable_schema( $params );

        // ══ GENERAL PLUGIN OPTIONS ═════════════════════════════

        case 'plugin_update_option':
            return wpilot_plugin_update_option( $params );

        case 'plugin_install':
            return wpilot_plugin_install( $params );

        case 'plugin_activate':
            return wpilot_plugin_activate( $params );


        // ══ CACHE PLUGINS ═══════════════════════════════════
        case 'cache_configure':
            return wpilot_cache_configure($params);
        case 'cache_purge':
            return wpilot_cache_purge();
        case 'cache_enable':
            return wpilot_cache_enable($params);

        // ══ SMTP / EMAIL ════════════════════════════════════
        case 'smtp_configure':
            return wpilot_smtp_configure($params);
        case 'smtp_test':
            return wpilot_smtp_test($params);

        // ══ SECURITY PLUGINS ════════════════════════════════
        case 'security_configure':
            return wpilot_security_configure($params);
        case 'security_enable_firewall':
            return wpilot_security_enable_firewall($params);
        case 'security_enable_2fa':
            return wpilot_security_enable_2fa($params);

        // ══ BACKUP PLUGINS ══════════════════════════════════
        case 'backup_configure':
            return wpilot_backup_configure($params);


        // == MULTILINGUAL -- Polylang, WPML, TranslatePress ==
        case 'multilingual_setup':
            return wpilot_multilingual_setup($params);
        case 'multilingual_add_language':
            return wpilot_multilingual_add_language($params);
        case 'multilingual_translate_page':
            return wpilot_multilingual_translate_page($params);
        case 'multilingual_bulk_translate':
            return wpilot_multilingual_bulk_translate($params);
        case 'multilingual_set_switcher':
            return wpilot_multilingual_set_switcher($params);

        // == PWA -- Progressive Web App =======================
        case 'pwa_setup':
            return wpilot_pwa_setup($params);
        case 'pwa_configure':
            return wpilot_pwa_configure($params);

        // ══ CONTACT FORM 7 ════════════════════════════════════════

        case 'cf7_create_form':
            return wpilot_cf7_create_form( $params );

        case 'cf7_list_forms':
            return wpilot_cf7_list_forms( $params );

        case 'cf7_get_form':
            return wpilot_cf7_get_form( $params );

        case 'cf7_update_form':
            return wpilot_cf7_update_form( $params );

        default:
            return wpilot_err( "Unknown plugin tool: {$tool}" );
    }
}

// ══════════════════════════════════════════════════════════════
//  AMELIA
// ══════════════════════════════════════════════════════════════

function wpilot_amelia_installed() {
    return defined('AMELIA_VERSION') || class_exists('\AmeliaBooking\Infrastructure\WP\InstallActions\ActivationHook');
}

function wpilot_amelia_create_service( $p ) {
    if ( !wpilot_amelia_installed() ) return wpilot_err('Amelia is not installed. Install it first from Plugins → Add New → search "Amelia".');
    global $wpdb;
    $table = $wpdb->prefix . 'amelia_services';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table )
        return wpilot_err('Amelia database tables not found. Deactivate and reactivate Amelia to rebuild them.');

    $name     = sanitize_text_field( $p['name']     ?? 'New Service' );
    $duration = max(15, (int)($p['duration']         ?? 60));
    $price    = max(0,  (float)($p['price']          ?? 0));
    $capacity = max(1,  (int)($p['capacity']         ?? 1));
    $color    = sanitize_hex_color( $p['color']      ?? '#4F80F7' ) ?: '#4F80F7';
    $desc     = sanitize_textarea_field( $p['description'] ?? '' );

    // Get or create category
    $cat_table = $wpdb->prefix . 'amelia_categories';
    $cat_id    = (int)$wpdb->get_var("SELECT id FROM {$cat_table} LIMIT 1");
    if ( !$cat_id ) {
        $wpdb->insert($cat_table, ['name'=>'Services','status'=>'visible','position'=>1]);
        $cat_id = $wpdb->insert_id;
    }

    $result = $wpdb->insert( $table, [
        'name'            => $name,
        'description'     => $desc,
        'color'           => $color,
        'price'           => $price,
        'status'          => 'visible',
        'categoryId'      => $cat_id,
        'duration'        => $duration * 60, // Amelia stores in seconds
        'minCapacity'     => 1,
        'maxCapacity'     => $capacity,
        'timeAfter'       => 0,
        'timeBefore'      => 0,
        'bringingAnyone'  => 0,
        'show'            => 1,
        'aggregatedPrice' => 1,
    ]);

    if ( !$result ) return wpilot_err("Could not create service. DB error: " . $wpdb->last_error);
    $id = $wpdb->insert_id;
    return wpilot_ok("✅ Amelia service \"{$name}\" created (ID: {$id}, {$duration} min, {$price} kr). Go to Amelia → Services to review it.", ['service_id'=>$id]);
}

function wpilot_amelia_create_employee( $p ) {
    if ( !wpilot_amelia_installed() ) return wpilot_err('Amelia is not installed.');
    global $wpdb;
    $table    = $wpdb->prefix . 'amelia_users';
    $name     = sanitize_text_field( $p['first_name'] ?? 'Employee' );
    $lastname = sanitize_text_field( $p['last_name']  ?? '' );
    $email    = sanitize_email( $p['email']            ?? '' );
    $phone    = sanitize_text_field( $p['phone']       ?? '' );

    if ( !$email ) return wpilot_err('Email is required for Amelia employee.');

    // Check if email already used
    if ( $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email=%s",$email)) )
        return wpilot_err("An employee with email {$email} already exists in Amelia.");

    $wpdb->insert($table, [
        'type'       => 'provider',
        'status'     => 'visible',
        'firstName'  => $name,
        'lastName'   => $lastname,
        'email'      => $email,
        'phone'      => $phone,
        'note'       => '',
        'description'=> '',
    ]);

    $id = $wpdb->insert_id;
    return wpilot_ok("✅ Amelia employee \"{$name} {$lastname}\" added (ID: {$id}). Go to Amelia → Employees to assign services and set working hours.", ['employee_id'=>$id]);
}

function wpilot_amelia_set_working_hours( $p ) {
    if ( !wpilot_amelia_installed() ) return wpilot_err('Amelia is not installed.');
    global $wpdb;
    $emp_id   = (int)($p['employee_id'] ?? 0);
    $days     = $p['days']       ?? [1,2,3,4,5]; // Mon-Fri
    $start    = $p['start_time'] ?? '09:00';
    $end      = $p['end_time']   ?? '17:00';

    if ( !$emp_id ) return wpilot_err('employee_id is required.');

    $period_table = $wpdb->prefix . 'amelia_providers_to_periods';
    $sched_table  = $wpdb->prefix . 'amelia_providers_schedule';

    // Delete existing
    $wpdb->delete($sched_table, ['userId'=>$emp_id]);

    $day_names = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    $added = [];
    foreach ((array)$days as $day) {
        $day = (int)$day;
        $wpdb->insert($sched_table, [
            'userId'  => $emp_id,
            'dayIndex'=> $day,
            'startTime'=> $start.':00',
            'endTime'  => $end.':00',
        ]);
        $added[] = $day_names[$day] ?? $day;
    }

    return wpilot_ok("✅ Working hours set for employee #{$emp_id}: " . implode(', ', $added) . " {$start}–{$end}.");
}

function wpilot_amelia_create_category( $p ) {
    if ( !wpilot_amelia_installed() ) return wpilot_err('Amelia is not installed.');
    global $wpdb;
    $name = sanitize_text_field($p['name'] ?? 'Category');
    $wpdb->insert($wpdb->prefix.'amelia_categories', ['name'=>$name,'status'=>'visible','position'=>99]);
    return wpilot_ok("✅ Amelia category \"{$name}\" created (ID: {$wpdb->insert_id}).");
}

function wpilot_amelia_update_settings( $p ) {
    if ( !wpilot_amelia_installed() ) return wpilot_err('Amelia is not installed.');
    $settings = get_option('amelia_settings', []);
    foreach ($p as $key => $val) {
        $settings[$key] = $val;
    }
    update_option('amelia_settings', $settings);
    return wpilot_ok("✅ Amelia settings updated: " . implode(', ', array_keys($p)));
}

// ══════════════════════════════════════════════════════════════
//  WOOCOMMERCE
// ══════════════════════════════════════════════════════════════

function wpilot_woo_installed() {
    return class_exists('WooCommerce');
}

function wpilot_woo_create_product( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $product = new WC_Product_Simple();
    $product->set_name( sanitize_text_field($p['name'] ?? 'New Product') );
    $product->set_regular_price( $p['price'] ?? '0' );
    $product->set_description( wp_kses_post($p['description'] ?? '') );
    $product->set_short_description( wp_kses_post($p['short_description'] ?? '') );
    $product->set_status( $p['status'] ?? 'publish' );
    if ( !empty($p['sku']) ) $product->set_sku(sanitize_text_field($p['sku']));
    if ( !empty($p['stock']) ) { $product->set_manage_stock(true); $product->set_stock_quantity((int)$p['stock']); }
    if ( !empty($p['sale_price']) ) $product->set_sale_price($p['sale_price']);
    $id = $product->save();
    if ( !empty($p['categories']) ) wp_set_object_terms($id, (array)$p['categories'], 'product_cat');
    return wpilot_ok("✅ WooCommerce product \"{$p['name']}\" created (ID: {$id}).", ['product_id'=>$id]);
}

function wpilot_woo_enable_payment( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $gateway   = sanitize_text_field($p['gateway'] ?? '');
    $enabled   = isset($p['enabled']) ? (bool)$p['enabled'] : true;
    if ( !$gateway ) return wpilot_err('gateway parameter required. E.g. "stripe", "stripe_klarna", "cod", "bacs".');
    $option_key = 'woocommerce_'.$gateway.'_settings';
    $settings   = get_option($option_key, []);
    $settings['enabled'] = $enabled ? 'yes' : 'no';

    // Optional: set title and description
    if ( !empty($p['title']) )       $settings['title']       = sanitize_text_field($p['title']);
    if ( !empty($p['description']) ) $settings['description'] = sanitize_text_field($p['description']);

    // BACS / Bank Transfer: store account details
    if ( $gateway === 'bacs' && !empty($p['account_details']) ) {
        // account_details: array of {account_name, account_number, sort_code, bank_name, iban, bic}
        $settings['accounts'] = array_map(function($acc) {
            return array_map('sanitize_text_field', (array)$acc);
        }, (array)$p['account_details']);
    }

    update_option($option_key, $settings);
    $status = $enabled ? 'enabled' : 'disabled';
    return wpilot_ok("✅ WooCommerce payment gateway \"{$gateway}\" {$status}." . ($enabled ? " Configure API keys with woo_configure_stripe if using Stripe." : ''));
}

function wpilot_woo_set_tax_rate( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    // Enable taxes and configure display
    update_option('woocommerce_calc_taxes', 'yes');
    $prices_include_tax = sanitize_text_field($p['prices_include_tax'] ?? 'no');
    update_option('woocommerce_prices_include_tax', $prices_include_tax);
    $tax_display = sanitize_text_field($p['tax_display'] ?? 'excl');
    update_option('woocommerce_tax_display_shop', $tax_display);
    update_option('woocommerce_tax_display_cart', $tax_display);

    global $wpdb;
    $rate     = (float)($p['rate']     ?? 25);
    $country  = strtoupper(sanitize_text_field($p['country'] ?? 'SE'));
    $name     = sanitize_text_field($p['name'] ?? 'VAT');
    $class    = sanitize_text_field($p['class'] ?? '');

    // Check for existing rate with same country+name+class to avoid duplicates
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
         WHERE tax_rate_country=%s AND tax_rate_name=%s AND tax_rate_class=%s LIMIT 1",
        $country, $name, $class
    ));

    if ( $existing ) {
        $wpdb->update(
            $wpdb->prefix.'woocommerce_tax_rates',
            ['tax_rate' => $rate, 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1],
            ['tax_rate_id' => $existing]
        );
        WC_Cache_Helper::invalidate_cache_group('taxes');
        return wpilot_ok("✅ Tax rate {$rate}% ({$name}) updated for {$country} (ID: {$existing}). Taxes enabled, prices shown {$tax_display}uding VAT.");
    }

    // Insert new tax rate
    $wpdb->insert($wpdb->prefix.'woocommerce_tax_rates', [
        'tax_rate_country'  => $country,
        'tax_rate'          => $rate,
        'tax_rate_name'     => $name,
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1,
        'tax_rate_order'    => 0,
        'tax_rate_class'    => $class,
    ]);
    $rate_id = $wpdb->insert_id;
    WC_Cache_Helper::invalidate_cache_group('taxes');
    return wpilot_ok("✅ Tax rate {$rate}% ({$name}) created for {$country} (ID: {$rate_id}). Taxes enabled, prices shown {$tax_display}uding VAT.");
}

function wpilot_woo_update_store_settings( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $map = [
        'currency'              => 'woocommerce_currency',
        'country'               => 'woocommerce_default_country',
        'shop_page'             => 'woocommerce_shop_page_id',
        'cart_page'             => 'woocommerce_cart_page_id',
        'checkout_page'         => 'woocommerce_checkout_page_id',
        'price_thousand'        => 'woocommerce_price_thousand_sep',
        'price_decimal'         => 'woocommerce_price_decimal_sep',
        'currency_pos'          => 'woocommerce_currency_pos',
        'prices_include_tax'    => 'woocommerce_prices_include_tax',
        'tax_display_shop'      => 'woocommerce_tax_display_shop',
        'tax_display_cart'      => 'woocommerce_tax_display_cart',
        'email_from_name'       => 'woocommerce_email_from_name',
        'email_from_address'    => 'woocommerce_email_from_address',
        'store_name'            => 'blogname',
        'store_address'         => 'woocommerce_store_address',
        'store_city'            => 'woocommerce_store_city',
        'store_postcode'        => 'woocommerce_store_postcode',
        'weight_unit'           => 'woocommerce_weight_unit',
        'dimension_unit'        => 'woocommerce_dimension_unit',
        'manage_stock'          => 'woocommerce_manage_stock',
        'hold_stock_minutes'    => 'woocommerce_hold_stock_minutes',
        'notify_low_stock'      => 'woocommerce_notify_low_stock',
        'notify_no_stock'       => 'woocommerce_notify_no_stock',
        'low_stock_amount'      => 'woocommerce_notify_low_stock_amount',
    ];
    $updated = [];
    foreach ($map as $key => $option) {
        if ( isset($p[$key]) ) {
            update_option($option, sanitize_text_field($p[$key]));
            $updated[] = $key;
        }
    }
    return empty($updated)
        ? wpilot_err('No valid settings provided. Supported: currency, country, shop_page, cart_page, checkout_page, price_thousand, price_decimal, currency_pos, prices_include_tax, tax_display_shop, tax_display_cart, email_from_name, email_from_address, store_address, weight_unit, dimension_unit, manage_stock.')
        : wpilot_ok("✅ WooCommerce settings updated: " . implode(', ', $updated));
}

function wpilot_woo_update_shipping_zone( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');

    $zone_name = sanitize_text_field($p['name'] ?? 'Shipping Zone');

    // Reuse existing zone if zone_id given, otherwise create new
    if ( !empty($p['zone_id']) ) {
        $zone = WC_Shipping_Zones::get_zone( (int)$p['zone_id'] );
        if ( !$zone ) return wpilot_err("Shipping zone ID {$p['zone_id']} not found.");
    } else {
        $zone = new WC_Shipping_Zone();
        $zone->set_zone_name( $zone_name );
    }

    if ( !empty($p['countries']) ) {
        // Remove existing locations first to avoid duplicates
        $zone->clear_locations(['country']);
        foreach ((array)$p['countries'] as $country) {
            $zone->add_location(strtoupper(sanitize_text_field($country)), 'country');
        }
    }
    if ( !empty($p['states']) ) {
        foreach ((array)$p['states'] as $state) {
            $zone->add_location(sanitize_text_field($state), 'state');
        }
    }

    $zone_id = $zone->save();
    $result_details = [];

    // Add shipping method with rates
    if ( !empty($p['method']) ) {
        $method_type = sanitize_text_field($p['method']); // flat_rate, free_shipping, local_pickup
        $instance_id = $zone->add_shipping_method($method_type);

        if ( $instance_id ) {
            $result_details[] = "method: {$method_type}";

            // Configure flat rate cost
            if ( $method_type === 'flat_rate' && isset($p['cost']) ) {
                $option_key = "woocommerce_flat_rate_{$instance_id}_settings";
                $settings = get_option($option_key, []);
                $settings['cost'] = sanitize_text_field($p['cost']);
                $settings['title'] = sanitize_text_field($p['method_title'] ?? 'Flat Rate');
                if ( isset($p['tax_status']) ) $settings['tax_status'] = sanitize_text_field($p['tax_status']);
                update_option($option_key, $settings);
                $result_details[] = "cost: {$p['cost']}";
            }

            // Configure free shipping minimum
            if ( $method_type === 'free_shipping' && isset($p['min_amount']) ) {
                $option_key = "woocommerce_free_shipping_{$instance_id}_settings";
                $settings = get_option($option_key, []);
                $settings['min_amount'] = sanitize_text_field($p['min_amount']);
                $settings['requires'] = 'min_amount';
                $settings['title'] = sanitize_text_field($p['method_title'] ?? 'Free Shipping');
                update_option($option_key, $settings);
                $result_details[] = "free over: {$p['min_amount']}";
            }

            // Local pickup title/cost
            if ( $method_type === 'local_pickup' ) {
                $option_key = "woocommerce_local_pickup_{$instance_id}_settings";
                $settings = get_option($option_key, []);
                $settings['title'] = sanitize_text_field($p['method_title'] ?? 'Local Pickup');
                if ( isset($p['cost']) ) $settings['cost'] = sanitize_text_field($p['cost']);
                update_option($option_key, $settings);
            }
        }
    }

    $detail_str = empty($result_details) ? '' : ' (' . implode(', ', $result_details) . ')';
    return wpilot_ok("✅ Shipping zone \"{$zone_name}\" saved (ID: {$zone_id}){$detail_str}.", ['zone_id' => $zone_id]);
}

function wpilot_woo_create_shipping_class( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $name = sanitize_text_field($p['name'] ?? 'Shipping Class');
    $term = wp_insert_term($name, 'product_shipping_class', ['description'=>$p['description']??'','slug'=>sanitize_title($name)]);
    if ( is_wp_error($term) ) {
        // If term already exists, return its ID
        if ( $term->get_error_code() === 'term_exists' ) {
            $existing = get_term_by('slug', sanitize_title($name), 'product_shipping_class');
            return wpilot_ok("Shipping class \"{$name}\" already exists (ID: {$existing->term_id}).", ['term_id' => $existing->term_id]);
        }
        return wpilot_err($term->get_error_message());
    }
    return wpilot_ok("✅ Shipping class \"{$name}\" created (ID: {$term['term_id']}).", ['term_id' => $term['term_id']]);
}

function wpilot_woo_configure_stripe( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    if ( !class_exists('WC_Stripe') && !defined('WC_STRIPE_VERSION') ) {
        return wpilot_err('WooCommerce Stripe plugin is not installed. Install "WooCommerce Stripe Payment Gateway" from Plugins → Add New.');
    }

    $test_mode         = isset($p['test_mode']) ? (bool)$p['test_mode'] : true;
    $pub_key           = sanitize_text_field($p['publishable_key']      ?? '');
    $secret_key        = sanitize_text_field($p['secret_key']           ?? '');
    $test_pub_key      = sanitize_text_field($p['test_publishable_key'] ?? '');
    $test_secret_key   = sanitize_text_field($p['test_secret_key']      ?? '');
    $webhook_secret    = sanitize_text_field($p['webhook_secret']       ?? '');
    $test_webhook      = sanitize_text_field($p['test_webhook_secret']  ?? '');
    $statement_desc    = sanitize_text_field($p['statement_descriptor'] ?? '');

    // Validate key prefixes
    if ( $pub_key && strpos($pub_key, 'pk_live_') !== 0 )
        return wpilot_err('publishable_key must start with "pk_live_".');
    if ( $secret_key && strpos($secret_key, 'sk_live_') !== 0 && strpos($secret_key, 'rk_live_') !== 0 )
        return wpilot_err('secret_key must start with "sk_live_" or "rk_live_".');
    if ( $test_pub_key && strpos($test_pub_key, 'pk_test_') !== 0 )
        return wpilot_err('test_publishable_key must start with "pk_test_".');
    if ( $test_secret_key && strpos($test_secret_key, 'sk_test_') !== 0 && strpos($test_secret_key, 'rk_test_') !== 0 )
        return wpilot_err('test_secret_key must start with "sk_test_" or "rk_test_".');

    $settings = get_option('woocommerce_stripe_settings', []);
    $settings['enabled']    = 'yes';
    $settings['testmode']   = $test_mode ? 'yes' : 'no';

    if ( $pub_key )         $settings['publishable_key']      = $pub_key;
    if ( $secret_key )      $settings['secret_key']           = $secret_key;
    if ( $test_pub_key )    $settings['test_publishable_key'] = $test_pub_key;
    if ( $test_secret_key ) $settings['test_secret_key']      = $test_secret_key;
    if ( $webhook_secret )  $settings['webhook_secret']       = $webhook_secret;
    if ( $test_webhook )    $settings['test_webhook_secret']  = $test_webhook;
    if ( $statement_desc )  $settings['statement_descriptor'] = $statement_desc;

    // Optional features
    if ( isset($p['saved_cards']) )    $settings['saved_cards']    = $p['saved_cards']    ? 'yes' : 'no';
    if ( isset($p['apple_pay']) )      $settings['express_checkout'] = $p['apple_pay']    ? 'yes' : 'no';
    if ( isset($p['capture']) )        $settings['capture']          = $p['capture']       ? 'yes' : 'no';

    // Enable Klarna if requested
    if ( !empty($p['enable_klarna']) ) {
        $klarna = get_option('woocommerce_stripe_klarna_settings', []);
        $klarna['enabled'] = 'yes';
        update_option('woocommerce_stripe_klarna_settings', $klarna);
    }

    update_option('woocommerce_stripe_settings', $settings);
    $mode = $test_mode ? 'TEST mode' : 'LIVE mode';
    return wpilot_ok("✅ Stripe configured ({$mode}). Card payments enabled." . (!empty($p['enable_klarna']) ? ' Klarna also enabled.' : '') . "\n\nIMPORTANT: Set up a webhook in your Stripe Dashboard → Developers → Webhooks pointing to: " . get_home_url(null, '/?wc-api=wc_stripe'), [
        'webhook_url' => get_home_url(null, '/?wc-api=wc_stripe'),
        'mode' => $test_mode ? 'test' : 'live',
    ]);
}

function wpilot_woo_configure_email( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');

    $updated = [];

    // From name and address
    if ( !empty($p['from_name']) ) {
        update_option('woocommerce_email_from_name', sanitize_text_field($p['from_name']));
        $updated[] = 'from_name';
    }
    if ( !empty($p['from_email']) ) {
        update_option('woocommerce_email_from_address', sanitize_email($p['from_email']));
        $updated[] = 'from_email';
    }

    // Email header image and footer text
    if ( !empty($p['header_image']) ) {
        update_option('woocommerce_email_header_image', esc_url_raw($p['header_image']));
        $updated[] = 'header_image';
    }
    if ( !empty($p['footer_text']) ) {
        update_option('woocommerce_email_footer_text', wp_kses_post($p['footer_text']));
        $updated[] = 'footer_text';
    }
    if ( !empty($p['base_color']) ) {
        update_option('woocommerce_email_base_color', sanitize_hex_color($p['base_color']) ?: '#7f54b3');
        $updated[] = 'base_color';
    }
    if ( !empty($p['bg_color']) ) {
        update_option('woocommerce_email_background_color', sanitize_hex_color($p['bg_color']) ?: '#f7f7f7');
        $updated[] = 'bg_color';
    }
    if ( !empty($p['body_bg_color']) ) {
        update_option('woocommerce_email_body_background_color', sanitize_hex_color($p['body_bg_color']) ?: '#ffffff');
        $updated[] = 'body_bg_color';
    }

    // Per-email type settings: new_order, customer_processing_order, customer_completed_order, etc.
    $email_types = [
        'new_order', 'cancelled_order', 'failed_order',
        'customer_on_hold_order', 'customer_processing_order',
        'customer_completed_order', 'customer_refunded_order',
        'customer_invoice', 'customer_note', 'customer_reset_password',
        'customer_new_account',
    ];

    if ( !empty($p['email_type']) && !empty($p['recipient']) && in_array($p['email_type'], $email_types) ) {
        $key = 'woocommerce_' . $p['email_type'] . '_settings';
        $opts = get_option($key, []);
        $opts['recipient'] = sanitize_text_field($p['recipient']);
        if ( isset($p['enabled']) ) $opts['enabled'] = $p['enabled'] ? 'yes' : 'no';
        if ( !empty($p['subject']) ) $opts['subject'] = sanitize_text_field($p['subject']);
        update_option($key, $opts);
        $updated[] = "email/{$p['email_type']}";
    }

    if ( empty($updated) ) {
        return wpilot_err('No email settings provided. Supported: from_name, from_email, header_image, footer_text, base_color, bg_color, body_bg_color, email_type+recipient+subject.');
    }

    return wpilot_ok("✅ WooCommerce email settings updated: " . implode(', ', $updated));
}

function wpilot_woo_setup_checkout( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');

    $updated = [];

    // Guest checkout
    if ( isset($p['guest_checkout']) ) {
        update_option('woocommerce_enable_guest_checkout', $p['guest_checkout'] ? 'yes' : 'no');
        $updated[] = 'guest_checkout: ' . ($p['guest_checkout'] ? 'on' : 'off');
    }

    // Login at checkout
    if ( isset($p['login_reminder']) ) {
        update_option('woocommerce_enable_checkout_login_reminder', $p['login_reminder'] ? 'yes' : 'no');
        $updated[] = 'login_reminder';
    }

    // Account creation
    if ( isset($p['registration_at_checkout']) ) {
        update_option('woocommerce_enable_signup_and_login_from_checkout', $p['registration_at_checkout'] ? 'yes' : 'no');
        $updated[] = 'registration_at_checkout';
    }
    if ( isset($p['auto_generate_username']) ) {
        update_option('woocommerce_registration_generate_username', $p['auto_generate_username'] ? 'yes' : 'no');
        $updated[] = 'auto_generate_username';
    }
    if ( isset($p['auto_generate_password']) ) {
        update_option('woocommerce_registration_generate_password', $p['auto_generate_password'] ? 'yes' : 'no');
        $updated[] = 'auto_generate_password';
    }

    // Terms page
    if ( !empty($p['terms_page_id']) ) {
        update_option('woocommerce_terms_page_id', (int)$p['terms_page_id']);
        $updated[] = 'terms_page';
    }

    // Force SSL at checkout
    if ( isset($p['force_ssl'] ) ) {
        update_option('woocommerce_force_ssl_checkout', $p['force_ssl'] ? 'yes' : 'no');
        $updated[] = 'force_ssl: ' . ($p['force_ssl'] ? 'on' : 'off');
    }

    // Checkout page
    if ( !empty($p['checkout_page_id']) ) {
        update_option('woocommerce_checkout_page_id', (int)$p['checkout_page_id']);
        $updated[] = 'checkout_page';
    }

    // My Account page
    if ( !empty($p['myaccount_page_id']) ) {
        update_option('woocommerce_myaccount_page_id', (int)$p['myaccount_page_id']);
        $updated[] = 'myaccount_page';
    }

    if ( empty($updated) ) {
        return wpilot_err('No checkout settings provided. Supported: guest_checkout, login_reminder, registration_at_checkout, auto_generate_username, auto_generate_password, terms_page_id, force_ssl, checkout_page_id, myaccount_page_id.');
    }

    return wpilot_ok("✅ Checkout configured: " . implode(', ', $updated));
}

// ══════════════════════════════════════════════════════════════
//  LEARNDASH
// ══════════════════════════════════════════════════════════════

function wpilot_ld_installed() {
    return defined('LEARNDASH_VERSION') || function_exists('learndash_get_post_type_slug');
}

function wpilot_ld_create_course( $p ) {
    if ( !wpilot_ld_installed() ) return wpilot_err('LearnDash is not installed. Install it from Plugins → Add New.');
    $id = wp_insert_post([
        'post_type'    => 'sfwd-courses',
        'post_title'   => sanitize_text_field($p['title'] ?? 'New Course'),
        'post_content' => wp_kses_post($p['description'] ?? ''),
        'post_status'  => 'publish',
    ]);
    if ( is_wp_error($id) ) return wpilot_err($id->get_error_message());
    if ( !empty($p['price']) ) {
        update_post_meta($id, '_sfwd-courses', [
            'sfwd-courses_course_price_type' => $p['price_type'] ?? 'paynow',
            'sfwd-courses_course_price'      => $p['price'],
        ]);
    }
    return wpilot_ok("✅ LearnDash course \"{$p['title']}\" created (ID: {$id}). Go to LearnDash → Courses to add lessons.", ['course_id'=>$id]);
}

function wpilot_ld_create_lesson( $p ) {
    if ( !wpilot_ld_installed() ) return wpilot_err('LearnDash is not installed.');
    $course_id = (int)($p['course_id'] ?? 0);
    if ( !$course_id ) return wpilot_err('course_id is required.');
    $id = wp_insert_post([
        'post_type'    => 'sfwd-lessons',
        'post_title'   => sanitize_text_field($p['title'] ?? 'New Lesson'),
        'post_content' => wp_kses_post($p['content'] ?? ''),
        'post_status'  => 'publish',
    ]);
    if ( is_wp_error($id) ) return wpilot_err($id->get_error_message());
    update_post_meta($id, 'course_id', $course_id);
    // Add lesson to course
    $lessons = get_post_meta($course_id, 'course_lessons', true) ?: [];
    $lessons[] = $id;
    update_post_meta($course_id, 'course_lessons', $lessons);
    // Drip content
    if ( !empty($p['drip_days']) ) {
        update_post_meta($id, '_sfwd-lessons', ['sfwd-lessons_lesson_available_date'=>'+'.intval($p['drip_days']).' days']);
    }
    return wpilot_ok("✅ Lesson \"{$p['title']}\" added to course #{$course_id} (Lesson ID: {$id}).", ['lesson_id'=>$id]);
}

function wpilot_ld_create_quiz( $p ) {
    if ( !wpilot_ld_installed() ) return wpilot_err('LearnDash is not installed.');
    $id = wp_insert_post([
        'post_type'    => 'sfwd-quiz',
        'post_title'   => sanitize_text_field($p['title'] ?? 'Quiz'),
        'post_status'  => 'publish',
    ]);
    if ( is_wp_error($id) ) return wpilot_err($id->get_error_message());
    if ( !empty($p['course_id']) ) update_post_meta($id, 'course_id', (int)$p['course_id']);
    if ( !empty($p['pass_percentage']) ) {
        update_post_meta($id, '_sfwd-quiz', ['sfwd-quiz_passing_percentage'=>(int)$p['pass_percentage']]);
    }
    return wpilot_ok("✅ Quiz \"{$p['title']}\" created (ID: {$id}). Add questions via LearnDash → Quizzes.", ['quiz_id'=>$id]);
}

function wpilot_ld_set_course_price( $p ) {
    if ( !wpilot_ld_installed() ) return wpilot_err('LearnDash is not installed.');
    $course_id = (int)($p['course_id'] ?? 0);
    if ( !$course_id ) return wpilot_err('course_id required.');
    $meta = get_post_meta($course_id, '_sfwd-courses', true) ?: [];
    $meta['sfwd-courses_course_price_type'] = sanitize_text_field($p['type'] ?? 'paynow');
    $meta['sfwd-courses_course_price']      = (float)($p['price'] ?? 0);
    update_post_meta($course_id, '_sfwd-courses', $meta);
    return wpilot_ok("✅ Course #{$course_id} price set to {$p['price']} ({$p['type']}).");
}

function wpilot_ld_enable_drip( $p ) {
    if ( !wpilot_ld_installed() ) return wpilot_err('LearnDash is not installed.');
    $course_id = (int)($p['course_id'] ?? 0);
    $meta = get_post_meta($course_id,'_sfwd-courses',true) ?: [];
    $meta['sfwd-courses_course_disable_lesson_progression'] = 0;
    update_post_meta($course_id,'_sfwd-courses',$meta);
    return wpilot_ok("✅ Drip content enabled for course #{$course_id}. Students must complete lessons in order.");
}

// ══════════════════════════════════════════════════════════════
//  GRAVITY FORMS
// ══════════════════════════════════════════════════════════════

function wpilot_gf_installed() {
    return class_exists('GFForms') || function_exists('gravity_form');
}

function wpilot_gf_create_form( $p ) {
    if ( !wpilot_gf_installed() ) return wpilot_err('Gravity Forms is not installed.');
    $title  = sanitize_text_field($p['title'] ?? 'Contact Form');
    $fields = [];
    foreach ((array)($p['fields'] ?? [['type'=>'name'],['type'=>'email'],['type'=>'textarea']]) as $i => $f) {
        $fields[] = GF_Fields::create([
            'type'     => $f['type']  ?? 'text',
            'label'    => $f['label'] ?? ucfirst($f['type'] ?? 'Field'),
            'id'       => $i + 1,
            'isRequired'=> !empty($f['required']),
        ]);
    }
    $form_id = GFAPI::add_form(['title'=>$title,'fields'=>$fields,'button'=>['type'=>'text','text'=>$p['button']??'Submit']]);
    if ( is_wp_error($form_id) ) return wpilot_err($form_id->get_error_message());
    return wpilot_ok("✅ Gravity Forms form \"{$title}\" created (ID: {$form_id}). Use shortcode: [gravityforms id=\"{$form_id}\"]", ['form_id'=>$form_id, 'shortcode'=>"[gravityforms id=\"{$form_id}\"]"]);
}

function wpilot_gf_set_notification( $p ) {
    if ( !wpilot_gf_installed() ) return wpilot_err('Gravity Forms is not installed.');
    $form_id = (int)($p['form_id'] ?? 0);
    $form    = GFAPI::get_form($form_id);
    if ( !$form ) return wpilot_err("Form #{$form_id} not found.");
    $notification = [
        'id'      => uniqid(),
        'name'    => $p['name']  ?? 'Admin Notification',
        'to'      => $p['to']    ?? get_option('admin_email'),
        'subject' => $p['subject'] ?? 'New form submission: {form_title}',
        'message' => $p['message'] ?? '{all_fields}',
        'isActive'=> true,
    ];
    $form['notifications'][] = $notification;
    GFAPI::update_form($form);
    return wpilot_ok("✅ Notification added to form #{$form_id} — emails will be sent to {$notification['to']}.");
}

function wpilot_gf_set_confirmation( $p ) {
    if ( !wpilot_gf_installed() ) return wpilot_err('Gravity Forms is not installed.');
    $form_id = (int)($p['form_id'] ?? 0);
    $form    = GFAPI::get_form($form_id);
    if ( !$form ) return wpilot_err("Form #{$form_id} not found.");
    $form['confirmations'] = [[
        'id'      => uniqid(),
        'name'    => 'Default',
        'type'    => $p['type']    ?? 'message',
        'message' => $p['message'] ?? 'Thank you! We will be in touch shortly.',
        'isDefault'=> true,
        'isActive' => true,
    ]];
    GFAPI::update_form($form);
    return wpilot_ok("✅ Confirmation message updated for form #{$form_id}.");
}

function wpilot_gf_add_field( $p ) {
    if ( !wpilot_gf_installed() ) return wpilot_err('Gravity Forms is not installed.');
    $form_id = (int)($p['form_id'] ?? 0);
    $form    = GFAPI::get_form($form_id);
    if ( !$form ) return wpilot_err("Form #{$form_id} not found.");
    $new_id  = max(array_column($form['fields']??[],'id'),0) + 1;
    $form['fields'][] = GF_Fields::create(['type'=>$p['type']??'text','label'=>$p['label']??'Field','id'=>$new_id,'isRequired'=>!empty($p['required'])]);
    GFAPI::update_form($form);
    return wpilot_ok("✅ Field \"{$p['label']}\" added to Gravity Forms form #{$form_id}.");
}

// ══════════════════════════════════════════════════════════════
//  WPFORMS
// ══════════════════════════════════════════════════════════════

function wpilot_wpf_create_form( $p ) {
    if ( !function_exists('wpforms') ) return wpilot_err('WPForms is not installed. Install it from Plugins → Add New → search "WPForms".');
    $fields = [];
    $defaults = $p['fields'] ?? [
        ['type'=>'name','label'=>'Name','required'=>true],
        ['type'=>'email','label'=>'Email','required'=>true],
        ['type'=>'textarea','label'=>'Message','required'=>false],
    ];
    $id_counter = 1;
    foreach ($defaults as $f) {
        $fields[$id_counter] = ['id'=>$id_counter,'type'=>$f['type']??'text','label'=>$f['label']??'Field','required'=>!empty($f['required'])?'1':''];
        $id_counter++;
    }
    $form_data = [
        'field_id' => $id_counter,
        'settings' => [
            'form_title'       => sanitize_text_field($p['title'] ?? 'Contact Form'),
            'submit_text'      => sanitize_text_field($p['submit'] ?? 'Send Message'),
            'notification_enable' => '1',
            'notifications'    => [1=>['notification_name'=>'Default','email'=>'{admin_email}','subject'=>'New Contact: {form_name}','message'=>'{all_fields}']],
            'confirmations'    => [1=>['type'=>'message','message'=>$p['confirmation']??'<p>Thank you! We\'ll be in touch soon.</p>']],
        ],
        'fields' => $fields,
    ];
    $form_id = wp_insert_post(['post_type'=>'wpforms','post_title'=>$form_data['settings']['form_title'],'post_status'=>'publish','post_content'=>wpforms_encode($form_data)]);
    if ( is_wp_error($form_id) ) return wpilot_err($form_id->get_error_message());
    return wpilot_ok("✅ WPForms form \"{$form_data['settings']['form_title']}\" created (ID: {$form_id}). Add to page with shortcode: [wpforms id=\"{$form_id}\"]", ['form_id'=>$form_id,'shortcode'=>"[wpforms id=\"{$form_id}\"]"]);
}

// ══════════════════════════════════════════════════════════════
//  MEMBERPRESS
// ══════════════════════════════════════════════════════════════

function wpilot_mp_installed() {
    return defined('MEPR_PLUGIN_NAME') || class_exists('MeprProduct');
}

function wpilot_mp_create_membership( $p ) {
    if ( !wpilot_mp_installed() ) return wpilot_err('MemberPress is not installed.');
    $id = wp_insert_post([
        'post_type'   => 'memberpressproduct',
        'post_title'  => sanitize_text_field($p['name'] ?? 'Membership'),
        'post_status' => 'publish',
    ]);
    if ( is_wp_error($id) ) return wpilot_err($id->get_error_message());
    $product = new MeprProduct($id);
    $product->price         = (float)($p['price'] ?? 0);
    $product->period        = (int)($p['period'] ?? 1);
    $product->period_type   = $p['period_type']  ?? 'months';
    $product->trial         = !empty($p['trial_days']);
    $product->trial_days    = (int)($p['trial_days'] ?? 0);
    $product->trial_amount  = (float)($p['trial_amount'] ?? 0);
    $product->store($id);
    return wpilot_ok("✅ MemberPress membership \"{$p['name']}\" created (ID: {$id}, {$p['price']} / {$p['period']} {$p['period_type']}). Set up access rules next.", ['membership_id'=>$id]);
}

function wpilot_mp_create_rule( $p ) {
    if ( !wpilot_mp_installed() ) return wpilot_err('MemberPress is not installed.');
    $rule = new MeprRule();
    $rule->mepr_type      = sanitize_text_field($p['type'] ?? 'all');
    $rule->mepr_content   = sanitize_text_field($p['content'] ?? '');
    $rule->mepr_drip_enabled = !empty($p['drip']);
    $rule->store();
    if ( !empty($p['membership_id']) ) {
        $rule->add_product((int)$p['membership_id']);
    }
    return wpilot_ok("✅ MemberPress access rule created. Content type: {$p['type']}.");
}

// ══════════════════════════════════════════════════════════════
//  THE EVENTS CALENDAR
// ══════════════════════════════════════════════════════════════

function wpilot_tec_installed() {
    return class_exists('Tribe__Events__Main');
}

function wpilot_tec_create_event( $p ) {
    if ( !wpilot_tec_installed() ) return wpilot_err('The Events Calendar is not installed. Install it from Plugins → Add New → search "The Events Calendar".');
    $id = wp_insert_post([
        'post_type'    => 'tribe_events',
        'post_title'   => sanitize_text_field($p['title'] ?? 'New Event'),
        'post_content' => wp_kses_post($p['description'] ?? ''),
        'post_status'  => 'publish',
    ]);
    if ( is_wp_error($id) ) return wpilot_err($id->get_error_message());
    if ( !empty($p['start_date']) ) update_post_meta($id, '_EventStartDate',    $p['start_date']);
    if ( !empty($p['end_date']) )   update_post_meta($id, '_EventEndDate',      $p['end_date']);
    if ( !empty($p['venue']) )      update_post_meta($id, '_EventVenueID',      $p['venue']);
    if ( !empty($p['cost']) )       update_post_meta($id, '_EventCost',         $p['cost']);
    if ( !empty($p['url']) )        update_post_meta($id, '_EventURL',          esc_url_raw($p['url']));
    if ( !empty($p['organizer']) )  update_post_meta($id, '_EventOrganizerID',  $p['organizer']);
    return wpilot_ok("✅ Event \"{$p['title']}\" created (ID: {$id}). View at: " . get_permalink($id), ['event_id'=>$id]);
}

// ══════════════════════════════════════════════════════════════
//  SEO (Rank Math + Yoast)
// ══════════════════════════════════════════════════════════════

function wpilot_seo_set_site_settings( $p ) {
    $plugin = wpilot_detect_seo_plugin();
    if ( $plugin === 'Rank Math' ) {
        if ( !empty($p['local_seo']) )  update_option('rank_math_modules', array_merge((array)get_option('rank_math_modules',[]),['local-seo']));
        if ( !empty($p['schema']) )     update_option('rank_math_modules', array_merge((array)get_option('rank_math_modules',[]),['rich-snippet']));
        if ( !empty($p['sitemap']) )    update_option('rank_math_sitemap_options', ['items_per_page'=>200,'include_images'=>'on']);
        return wpilot_ok("✅ Rank Math settings updated: " . implode(', ', array_keys($p)));
    }
    if ( $plugin === 'Yoast SEO' ) {
        if ( !empty($p['og_enabled']) ) update_option('wpseo_social', array_merge((array)get_option('wpseo_social',[]),['opengraph'=>true]));
        if ( !empty($p['schema_type']) ) update_option('wpseo_titles', array_merge((array)get_option('wpseo_titles',[]),['company_or_person'=>$p['schema_type']]));
        return wpilot_ok("✅ Yoast SEO settings updated: " . implode(', ', array_keys($p)));
    }
    return wpilot_err('No SEO plugin detected. Install Rank Math or Yoast SEO first.');
}

function wpilot_seo_enable_schema( $p ) {
    $type = sanitize_text_field($p['type'] ?? 'LocalBusiness');
    $name = sanitize_text_field($p['name'] ?? get_bloginfo('name'));
    if ( class_exists('RankMath') ) {
        $opts = get_option('rank_math_general_settings',[]);
        $opts['knowledgegraph_type'] = 'organization';
        $opts['knowledgegraph_name'] = $name;
        update_option('rank_math_general_settings', $opts);
        return wpilot_ok("✅ Rank Math schema set to {$type} for \"{$name}\".");
    }
    if ( defined('WPSEO_VERSION') ) {
        $opts = get_option('wpseo_titles',[]);
        $opts['company_or_person'] = strtolower($type) === 'person' ? 'person' : 'company';
        $opts['company_name'] = $name;
        update_option('wpseo_titles', $opts);
        return wpilot_ok("✅ Yoast SEO schema set to {$type} for \"{$name}\".");
    }
    return wpilot_err('No SEO plugin active.');
}

// ══════════════════════════════════════════════════════════════
//  GENERIC PLUGIN OPTION UPDATE
// ══════════════════════════════════════════════════════════════

function wpilot_plugin_update_option( $p ) {
    $key   = sanitize_text_field($p['option_key'] ?? '');
    $value = $p['value'] ?? '';
    if ( !$key ) return wpilot_err('option_key required.');
    // Safety: only allow known plugin option prefixes
    $allowed_prefixes = ['woocommerce_','amelia_','learndash_','wpforms_','rank_math','wpseo','tribe_','mepr','bookly_','gform_','elementor_','astra_','kadence_'];
    $safe = false;
    foreach ($allowed_prefixes as $prefix) { if (( strpos($key, $prefix) === 0 )) { $safe=true; break; } }
    if ( !$safe ) return wpilot_err("Option key \"{$key}\" is not in the allowed plugin options list.");
    update_option($key, $value);
    return wpilot_ok("✅ Plugin option \"{$key}\" updated.");
}

// ══════════════════════════════════════════════════════════════
//  PLUGIN INSTALL + ACTIVATE
// ══════════════════════════════════════════════════════════════

function wpilot_plugin_install( $p ) {
    $slug     = sanitize_text_field($p['slug'] ?? '');
    $activate = ! empty($p['activate']);
    if ( !$slug ) return wpilot_err('Plugin slug required. E.g. "amelia", "woocommerce", "learndash".');
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $api = plugins_api('plugin_information',['slug'=>$slug,'fields'=>['sections'=>false]]);
    if ( is_wp_error($api) ) return wpilot_err("Plugin \"{$slug}\" not found on WordPress.org: " . $api->get_error_message());
    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result   = $upgrader->install($api->download_link);
    if ( is_wp_error($result) ) return wpilot_err("Install failed: " . $result->get_error_message());
    if ( $result === false )    return wpilot_err("Install failed: unknown error.");

    // Find the installed plugin file and optionally activate
    wp_clean_plugins_cache( false );
    $all_plugins = get_plugins();
    $plugin_file = null;
    foreach ( $all_plugins as $file => $data ) {
        if ( strpos($file, $slug . '/') === 0 || $file === $slug . '.php' ) {
            $plugin_file = $file; break;
        }
    }

    if ( $activate && $plugin_file ) {
        $act_result = activate_plugin( $plugin_file );
        if ( is_wp_error($act_result) ) {
            return wpilot_ok("✅ Plugin \"{$api->name}\" installed but activation failed: " . $act_result->get_error_message(), ['plugin_file' => $plugin_file]);
        }
        return wpilot_ok("✅ Plugin \"{$api->name}\" installed and activated.", ['plugin_file' => $plugin_file, 'activated' => true]);
    }

    return wpilot_ok("✅ Plugin \"{$api->name}\" installed successfully.", ['plugin_file' => $plugin_file ?? $slug]);
}

function wpilot_plugin_activate( $p ) {
    $slug = sanitize_text_field($p['slug'] ?? '');
    if ( !$slug ) return wpilot_err('Plugin slug required.');
    // Find plugin file
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = get_plugins();
    $plugin_file = null;
    foreach ($plugins as $file => $data) {
        if ( ( strpos($file, $slug.'/') === 0 ) || $file === $slug.'.php' ) { $plugin_file=$file; break; }
    }
    if ( !$plugin_file ) return wpilot_err("Plugin \"{$slug}\" not found. Install it first.");
    $result = activate_plugin($plugin_file);
    if ( is_wp_error($result) ) return wpilot_err("Could not activate: " . $result->get_error_message());
    return wpilot_ok("✅ Plugin activated: {$plugin_file}.");
}

// ══════════════════════════════════════════════════════════════
//  CACHE PLUGINS — LiteSpeed, WP Rocket, W3TC, WP Fastest, WP Super Cache
// ══════════════════════════════════════════════════════════════

function wpilot_cache_configure($p) {
    // LiteSpeed Cache
    if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
        $opts = get_option('litespeed.conf.cache', []);
        update_option('litespeed.conf.cache', 1);
        update_option('litespeed.conf.cache-browser', 1);
        update_option('litespeed.conf.css_minify', 1);
        update_option('litespeed.conf.js_minify', 1);
        update_option('litespeed.conf.css_combine', 1);
        update_option('litespeed.conf.js_combine', 1);
        update_option('litespeed.conf.optm-qs_rm', 1);
        update_option('litespeed.conf.media_lazy', 1);
        update_option('litespeed.conf.media_placeholder_resp', 1);
        return wpilot_ok("LiteSpeed Cache configured: page cache ON, browser cache ON, CSS/JS minified + combined, lazy load images ON, query strings removed.");
    }

    // WP Rocket
    if (defined('WP_ROCKET_VERSION') || function_exists('rocket_init')) {
        $opts = get_option('wp_rocket_settings', []);
        $opts['cache_mobile'] = 1;
        $opts['do_caching_mobile_files'] = 1;
        $opts['minify_css'] = 1;
        $opts['minify_js'] = 1;
        $opts['minify_concatenate_css'] = 1;
        $opts['minify_concatenate_js'] = 1;
        $opts['lazyload'] = 1;
        $opts['lazyload_iframes'] = 1;
        $opts['cache_webp'] = 1;
        $opts['remove_query_strings'] = 1;
        update_option('wp_rocket_settings', $opts);
        return wpilot_ok("WP Rocket configured: mobile cache ON, CSS/JS minified + combined, lazy load ON, WebP cache ON, query strings removed.");
    }

    // W3 Total Cache
    if (defined('W3TC') || class_exists('W3_Root')) {
        update_option('w3tc_pgcache_enabled', 1);
        update_option('w3tc_browsercache_enabled', 1);
        update_option('w3tc_minify_enabled', 1);
        update_option('w3tc_lazyload_enabled', 1);
        return wpilot_ok("W3 Total Cache configured: page cache ON, browser cache ON, minify ON, lazy load ON.");
    }

    // WP Super Cache
    if (defined('WPCACHEHOME') || function_exists('wp_cache_phase2')) {
        update_option('ossdl_off_cdn_url', '');
        $wp_cache_config = ABSPATH . 'wp-content/wp-cache-config.php';
        if (file_exists($wp_cache_config)) {
            update_option('wpsupercache_enabled', 1);
        }
        return wpilot_ok("WP Super Cache enabled.");
    }

    // WP Fastest Cache
    if (defined('WPFC_WP_CONTENT_DIR') || class_exists('WpFastestCache')) {
        $opts = ['wpFastestCacheStatus'=>'on','wpFastestCacheMinifyHtml'=>'on','wpFastestCacheMinifyCss'=>'on',
                 'wpFastestCacheCombineCss'=>'on','wpFastestCacheMinifyJs'=>'on','wpFastestCacheCombineJs'=>'on',
                 'wpFastestCacheLazyLoad'=>'on','wpFastestCacheMobile'=>'on'];
        update_option('WpFastestCache', wp_json_encode($opts));
        return wpilot_ok("WP Fastest Cache configured: cache ON, minify ON, combine ON, lazy load ON, mobile cache ON.");
    }

    // No cache plugin
    return wpilot_ok("No cache plugin installed. Recommendation: **LiteSpeed Cache** (free, best performance) or **WP Super Cache** (free, simple).\n\nI can install one for you right now.", [
        'not_installed' => true,
        'suggested' => [
            ['slug'=>'litespeed-cache', 'name'=>'LiteSpeed Cache', 'reason'=>'Best free cache plugin, lazy load, CDN support'],
            ['slug'=>'wp-super-cache', 'name'=>'WP Super Cache', 'reason'=>'Simple and reliable, by Automattic'],
        ],
    ]);
}

function wpilot_cache_purge() {
    $purged = [];

    // LiteSpeed
    if (class_exists('LiteSpeed\Purge') || defined('LSCWP_V')) {
        if (function_exists('litespeed_purge_all')) litespeed_purge_all();
        do_action('litespeed_purge_all');
        $purged[] = 'LiteSpeed Cache';
    }
    // WP Rocket
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $purged[] = 'WP Rocket';
    }
    // W3TC
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $purged[] = 'W3 Total Cache';
    }
    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $purged[] = 'WP Super Cache';
    }
    // WP Fastest Cache
    if (class_exists('WpFastestCache')) {
        $wpfc = new WpFastestCache();
        if (method_exists($wpfc, 'deleteCache')) $wpfc->deleteCache(true);
        $purged[] = 'WP Fastest Cache';
    }
    // WordPress built-in
    wp_cache_flush();

    if (empty($purged)) return wpilot_ok("WordPress object cache cleared. No page cache plugin detected.");
    return wpilot_ok("Cache purged: " . implode(', ', $purged) . " + WordPress object cache.");
}

function wpilot_cache_enable($p) {
    $feature = sanitize_text_field($p['feature'] ?? 'all');
    return wpilot_cache_configure($p);
}

// ══════════════════════════════════════════════════════════════
//  SMTP — WP Mail SMTP, Post SMTP, FluentSMTP
// ══════════════════════════════════════════════════════════════

// Built by Christos Ferlachidis & Daniel Hedenberg

function wpilot_smtp_configure($p) {
    $host = sanitize_text_field($p['host'] ?? '');
    $port = intval($p['port'] ?? 587);
    $user = sanitize_text_field($p['username'] ?? '');
    $pass = $p['password'] ?? '';
    $from_email = sanitize_email($p['from_email'] ?? get_option('admin_email'));
    $from_name  = sanitize_text_field($p['from_name'] ?? get_bloginfo('name'));
    $encryption = sanitize_text_field($p['encryption'] ?? 'tls');

    // WP Mail SMTP (most popular)
    if (function_exists('wp_mail_smtp') || class_exists('WPMailSMTP\Core')) {
        $opts = get_option('wp_mail_smtp', []);
        $opts['mail'] = [
            'from_email'       => $from_email,
            'from_name'        => $from_name,
            'mailer'           => 'smtp',
            'return_path'      => true,
            'from_email_force' => true,
            'from_name_force'  => false,
        ];
        $opts['smtp'] = [
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encryption,
            'auth'       => true,
            'user'       => $user,
            'pass'       => $pass,
            'autotls'    => true,
        ];
        update_option('wp_mail_smtp', $opts);
        return wpilot_ok("WP Mail SMTP configured: {$host}:{$port} ({$encryption}), from: {$from_name} <{$from_email}>. Test with smtp_test tool.");
    }

    // FluentSMTP
    if (defined('FLUENTMAIL') || class_exists('FluentMail\App\App')) {
        $settings = [
            'connections' => [
                'primary' => [
                    'provider_settings' => [
                        'sender_name'    => $from_name,
                        'sender_email'   => $from_email,
                        'force_from_name'=> 'yes',
                        'host'           => $host,
                        'port'           => $port,
                        'auth'           => 'yes',
                        'username'       => $user,
                        'password'       => $pass,
                        'encryption'     => $encryption,
                    ],
                    'provider' => 'smtp',
                    'title'    => 'Primary SMTP',
                ],
            ],
            'misc' => ['log_emails'=>'yes'],
        ];
        update_option('fluentmail-settings', $settings);
        return wpilot_ok("FluentSMTP configured: {$host}:{$port}, logging enabled.");
    }

    // Post SMTP
    if (class_exists('PostmanOptions') || defined('POST_SMTP_VER')) {
        update_option('postman_options', [
            'hostname'             => $host,
            'port'                 => $port,
            'auth_type'            => 'plain',
            'enc_type'             => $encryption === 'ssl' ? 'ssl' : 'tls',
            'basic_auth_username'  => $user,
            'basic_auth_password'  => $pass,
            'sender_email'         => $from_email,
            'sender_name'          => $from_name,
            'transport_type'       => 'smtp',
        ]);
        return wpilot_ok("Post SMTP configured: {$host}:{$port}.");
    }

    // No SMTP plugin
    if (!$host) {
        return wpilot_ok("No SMTP plugin installed. WordPress uses PHP mail() by default which often lands in spam.\n\nRecommendation: **WP Mail SMTP** (free) or **FluentSMTP** (free).\n\nTell me your email provider (Gmail, Outlook, custom) and I'll set it up.", [
            'not_installed' => true,
            'suggested' => [
                ['slug'=>'wp-mail-smtp', 'name'=>'WP Mail SMTP', 'reason'=>'Most popular, supports Gmail/Outlook/SendGrid'],
                ['slug'=>'fluent-smtp', 'name'=>'FluentSMTP', 'reason'=>'Free, email logging, multiple providers'],
            ],
        ]);
    }

    return wpilot_ok("SMTP credentials received but no SMTP plugin is installed. Install WP Mail SMTP first, then I'll configure it automatically.", [
        'not_installed' => true,
        'credentials_saved' => true,
    ]);
}

function wpilot_smtp_test($p) {
    $to = sanitize_email($p['to'] ?? get_option('admin_email'));
    if ( !$to ) return wpilot_err('No recipient email address. Provide "to" parameter or set admin email in WordPress settings.');
    $subject = 'WPilot SMTP Test — ' . date('Y-m-d H:i');
    $body    = '<p>This is a test email sent by <strong>WPilot</strong> to verify your SMTP configuration is working correctly.</p><p>If you received this, your email delivery is set up properly!</p>';
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    // Capture PHPMailer error — WP 5.5+ uses $wp_phpmailer
    $mailer_error = '';
    $capture_error = function( $wp_error ) use ( &$mailer_error ) {
        if ( is_wp_error( $wp_error ) ) {
            $mailer_error = $wp_error->get_error_message();
        }
    };
    add_action( 'wp_mail_failed', $capture_error );

    $result = wp_mail( $to, $subject, $body, $headers );

    remove_action( 'wp_mail_failed', $capture_error );

    if ( $result ) {
        return wpilot_ok("✅ Test email sent to {$to}. Check your inbox (and spam folder). If it ends up in spam, configure SPF/DKIM records for your domain.");
    }

    // Also check legacy $phpmailer / $wp_phpmailer globals as fallback
    if ( !$mailer_error ) {
        global $wp_phpmailer, $phpmailer;
        $pm = $wp_phpmailer ?? $phpmailer ?? null;
        if ( $pm && is_object($pm) && !empty($pm->ErrorInfo) ) {
            $mailer_error = $pm->ErrorInfo;
        }
    }

    return wpilot_err("Email failed to send." . ($mailer_error ? " PHPMailer error: {$mailer_error}" : " Check your SMTP settings or install WP Mail SMTP plugin."));
}

// ══════════════════════════════════════════════════════════════
//  SECURITY PLUGINS — Wordfence, All-In-One Security
// ══════════════════════════════════════════════════════════════

function wpilot_security_configure($p) {
    // Wordfence
    if (defined('WORDFENCE_VERSION') || class_exists('wordfence')) {
        update_option('wordfence_waf_status', 'enabled');
        update_option('wordfenceScanEnabled', 1);
        update_option('loginSec_maxFailures', intval($p['max_login_attempts'] ?? 5));
        update_option('loginSec_lockoutMins', intval($p['lockout_minutes'] ?? 20));
        update_option('loginSec_maxForgotPasswd', 3);
        update_option('blockFakeBots', 1);
        update_option('liveTrafficEnabled', 1);
        return wpilot_ok("Wordfence configured: firewall ON, brute force protection (max " . ($p['max_login_attempts'] ?? 5) . " attempts, " . ($p['lockout_minutes'] ?? 20) . "min lockout), fake bot blocking ON, live traffic ON.");
    }

    // All-In-One Security
    if (defined('AIO_WP_SECURITY_VERSION') || class_exists('AIO_WP_Security')) {
        $opts = get_option('aio_wp_security_configs', []);
        $opts['aiowps_enable_login_lockdown'] = 1;
        $opts['aiowps_max_login_attempts'] = intval($p['max_login_attempts'] ?? 3);
        $opts['aiowps_lockout_time_length'] = intval($p['lockout_minutes'] ?? 60);
        $opts['aiowps_enable_brute_force_attack_prevention'] = 1;
        $opts['aiowps_enable_basic_firewall'] = 1;
        $opts['aiowps_disable_xmlrpc_pingback_methods'] = 1;
        update_option('aio_wp_security_configs', $opts);
        return wpilot_ok("All-In-One WP Security configured: login lockdown ON, firewall ON, XML-RPC pingbacks disabled.");
    }

    // No security plugin
    return wpilot_ok("No security plugin installed. Your site has no firewall or brute force protection.\n\nRecommendation: **Wordfence** (free) — firewall, malware scanner, login protection.\n\nI can install it right now.", [
        'not_installed' => true,
        'suggested' => [
            ['slug'=>'wordfence', 'name'=>'Wordfence Security', 'reason'=>'Best free firewall + malware scanner'],
            ['slug'=>'all-in-one-wp-security-and-firewall', 'name'=>'All-In-One Security', 'reason'=>'Free, beginner-friendly'],
        ],
    ]);
}

function wpilot_security_enable_firewall($p) {
    if (defined('WORDFENCE_VERSION')) {
        update_option('wordfence_waf_status', 'enabled');
        return wpilot_ok("Wordfence firewall enabled.");
    }
    return wpilot_security_configure($p);
}

function wpilot_security_enable_2fa($p) {
    if (defined('WORDFENCE_VERSION')) {
        update_option('wf2FAEnabled', 1);
        return wpilot_ok("Wordfence 2FA enabled. Users can set up two-factor authentication in their profile.");
    }
    return wpilot_ok("2FA requires a security plugin. Install Wordfence (free) for two-factor authentication.");
}

// ══════════════════════════════════════════════════════════════
//  BACKUP PLUGINS — UpdraftPlus
// ══════════════════════════════════════════════════════════════

function wpilot_backup_configure($p) {
    // UpdraftPlus
    if (class_exists('UpdraftPlus') || defined('UPDRAFTPLUS_DIR')) {
        $schedule_files = sanitize_text_field($p['files_schedule'] ?? 'weekly');
        $schedule_db    = sanitize_text_field($p['db_schedule'] ?? 'daily');
        $retain_files   = intval($p['retain_files'] ?? 4);
        $retain_db      = intval($p['retain_db'] ?? 7);

        update_option('updraft_interval_database', $schedule_db);
        update_option('updraft_interval', $schedule_files);
        update_option('updraft_retain', $retain_files);
        update_option('updraft_retain_db', $retain_db);
        update_option('updraft_include_plugins', 1);
        update_option('updraft_include_themes', 1);
        update_option('updraft_include_uploads', 1);

        return wpilot_ok("UpdraftPlus configured: database backup {$schedule_db} (keep {$retain_db}), files backup {$schedule_files} (keep {$retain_files}). Includes plugins, themes, and uploads.");
    }

    return wpilot_ok("No backup plugin installed. Your site has NO automated backups — one crash and everything is lost.\n\nRecommendation: **UpdraftPlus** (free) — scheduled backups to Google Drive, Dropbox, or email.\n\nI can install it right now.", [
        'not_installed' => true,
        'suggested' => [
            ['slug'=>'updraftplus', 'name'=>'UpdraftPlus', 'reason'=>'Best free backup, scheduled, cloud storage'],
        ],
    ]);
}


// ==============================================================
//  MULTILINGUAL -- Polylang, WPML, TranslatePress
// ==============================================================

function wpilot_multilingual_setup($p) {
    $languages = $p['languages'] ?? ['en', 'sv'];
    $default   = sanitize_text_field($p['default'] ?? 'sv');

    // Language data (name, locale, flag)
    $lang_data = [
        'sv' => ['name'=>'Svenska', 'locale'=>'sv_SE', 'flag'=>'se'],
        'en' => ['name'=>'English', 'locale'=>'en_US', 'flag'=>'us'],
        'de' => ['name'=>'Deutsch', 'locale'=>'de_DE', 'flag'=>'de'],
        'fr' => ['name'=>'Fran\u00e7ais', 'locale'=>'fr_FR', 'flag'=>'fr'],
        'es' => ['name'=>'Espa\u00f1ol', 'locale'=>'es_ES', 'flag'=>'es'],
        'no' => ['name'=>'Norsk', 'locale'=>'nb_NO', 'flag'=>'no'],
        'da' => ['name'=>'Dansk', 'locale'=>'da_DK', 'flag'=>'dk'],
        'fi' => ['name'=>'Suomi', 'locale'=>'fi', 'flag'=>'fi'],
        'nl' => ['name'=>'Nederlands', 'locale'=>'nl_NL', 'flag'=>'nl'],
        'it' => ['name'=>'Italiano', 'locale'=>'it_IT', 'flag'=>'it'],
        'pt' => ['name'=>'Portugu\u00eas', 'locale'=>'pt_PT', 'flag'=>'pt'],
        'pl' => ['name'=>'Polski', 'locale'=>'pl_PL', 'flag'=>'pl'],
        'ru' => ['name'=>'\u0420\u0443\u0441\u0441\u043a\u0438\u0439', 'locale'=>'ru_RU', 'flag'=>'ru'],
        'ja' => ['name'=>'\u65e5\u672c\u8a9e', 'locale'=>'ja', 'flag'=>'jp'],
        'zh' => ['name'=>'\u4e2d\u6587', 'locale'=>'zh_CN', 'flag'=>'cn'],
        'ko' => ['name'=>'\ud55c\uad6d\uc5b4', 'locale'=>'ko_KR', 'flag'=>'kr'],
        'ar' => ['name'=>'\u0627\u0644\u0639\u0631\u0628\u064a\u0629', 'locale'=>'ar', 'flag'=>'sa'],
        'tr' => ['name'=>'T\u00fcrk\u00e7e', 'locale'=>'tr_TR', 'flag'=>'tr'],
    ];

    // Polylang
    if (function_exists('pll_languages_list') || defined('POLYLANG_VERSION')) {
        $existing = function_exists('pll_languages_list') ? pll_languages_list(['fields'=>'slug']) : [];
        $added = [];

        foreach ((array)$languages as $lang) {
            $lang = strtolower(trim($lang));
            if (in_array($lang, $existing)) continue;
            if (!isset($lang_data[$lang])) continue;

            $ld = $lang_data[$lang];
            if (function_exists('PLL') && method_exists(PLL()->model, 'add_language')) {
                PLL()->model->add_language([
                    'name'   => $ld['name'],
                    'slug'   => $lang,
                    'locale' => $ld['locale'],
                    'flag'   => $ld['flag'],
                    'rtl'    => in_array($lang, ['ar', 'he', 'fa']) ? 1 : 0,
                ]);
                $added[] = $ld['name'];
            }
        }

        if (function_exists('PLL') && method_exists(PLL()->model, 'update_default_lang')) {
            PLL()->model->update_default_lang($default);
        }

        $msg = empty($added)
            ? "Polylang already configured with: " . implode(', ', $existing) . ". Default: {$default}."
            : "Polylang configured. Added: " . implode(', ', $added) . ". Default: {$default}.";

        return wpilot_ok($msg . "\n\nNext: I\'ll create a language switcher and start translating your pages.", [
            'plugin' => 'polylang',
            'languages' => $languages,
            'default' => $default,
        ]);
    }

    // TranslatePress
    if (class_exists('TRP_Translate_Press') || defined('TRP_PLUGIN_VERSION')) {
        $settings = get_option('trp_settings', []);
        $settings['default-language'] = $lang_data[$default]['locale'] ?? 'sv_SE';
        $settings['translation-languages'] = array_map(function($l) use ($lang_data) {
            return $lang_data[$l]['locale'] ?? $l;
        }, (array)$languages);
        update_option('trp_settings', $settings);
        return wpilot_ok("TranslatePress configured with " . count($languages) . " languages. Default: {$default}. Users can translate directly on the frontend.", [
            'plugin' => 'translatepress',
        ]);
    }

    // WPML
    if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) {
        return wpilot_ok("WPML is installed. WPML must be configured through its own setup wizard (WPML > Languages). I can help you:\n1. Set up the language switcher\n2. Translate pages one by one\n3. Configure URL structure (subdirectory vs subdomain)", [
            'plugin' => 'wpml',
        ]);
    }

    // No multilingual plugin
    return wpilot_ok("No multilingual plugin installed. Here are your options:\n\n**Polylang** (FREE) -- Best free option. Each page gets a translated copy. Language switcher in menu.\n\n**TranslatePress** (FREE) -- Translate directly on the frontend. Visual editor. Easiest to use.\n\n**WPML** (PAID -- \$39/year) -- Most powerful, best for WooCommerce multilingual stores.\n\nFor most sites, **Polylang is the best free choice**. Want me to install it?", [
        'not_installed' => true,
        'suggested' => [
            ['slug'=>'polylang', 'name'=>'Polylang', 'reason'=>'Best free multilingual plugin, full control'],
            ['slug'=>'translatepress-multilingual', 'name'=>'TranslatePress', 'reason'=>'Free, visual frontend translation'],
        ],
    ]);
}

function wpilot_multilingual_add_language($p) {
    $lang = sanitize_text_field($p['language'] ?? '');
    if (!$lang) return wpilot_err('Language code required (e.g. "en", "de", "fr").');
    return wpilot_multilingual_setup(['languages' => [$lang], 'default' => sanitize_text_field($p['default'] ?? '')]);
}

function wpilot_multilingual_translate_page($p) {
    $page_id = intval($p['page_id'] ?? 0);
    $lang    = sanitize_text_field($p['language'] ?? 'en');
    $translated_title   = sanitize_text_field($p['translated_title'] ?? '');
    $translated_content = wp_kses_post($p['translated_content'] ?? '');

    if (!$page_id) return wpilot_err('page_id required.');
    $post = get_post($page_id);
    if (!$post) return wpilot_err("Page #{$page_id} not found.");

    if (empty($translated_content)) {
        return wpilot_ok("Ready to translate \"{$post->post_title}\" to {$lang}. Provide the translated content.", [
            'source_id' => $page_id,
            'source_title' => $post->post_title,
            'source_content' => wp_strip_all_tags($post->post_content),
            'target_language' => $lang,
            'needs_translation' => true,
        ]);
    }

    // Create translated post
    $new_id = wp_insert_post([
        'post_title'   => $translated_title ?: $post->post_title . " ({$lang})",
        'post_content' => $translated_content,
        'post_status'  => $post->post_status,
        'post_type'    => $post->post_type,
    ]);
    if (is_wp_error($new_id)) return wpilot_err($new_id->get_error_message());

    // Link with Polylang if available
    if (function_exists('pll_set_post_language') && function_exists('pll_save_post_translations')) {
        $source_lang = function_exists('pll_get_post_language') ? pll_get_post_language($page_id) : '';
        pll_set_post_language($new_id, $lang);
        if ($source_lang) {
            $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($page_id) : [];
            $translations[$lang] = $new_id;
            pll_save_post_translations($translations);
        }
    }

    // Copy SEO meta
    foreach (['_yoast_wpseo_metadesc','rank_math_description','_yoast_wpseo_title','rank_math_title'] as $key) {
        $val = get_post_meta($page_id, $key, true);
        if ($val) update_post_meta($new_id, $key, $val);
    }

    update_post_meta($new_id, '_wpi_translated_from', $page_id);
    update_post_meta($new_id, '_wpi_language', $lang);

    return wpilot_ok("Translated page created: \"{$translated_title}\" ({$lang}, ID: {$new_id}). Linked to original #{$page_id}.", ['id'=>$new_id]);
}

// Built by Christos Ferlachidis & Daniel Hedenberg
function wpilot_multilingual_bulk_translate($p) {
    $lang = sanitize_text_field($p['language'] ?? 'en');
    $post_type = sanitize_text_field($p['post_type'] ?? 'page');

    $posts = get_posts(['post_type'=>$post_type, 'post_status'=>'publish', 'numberposts'=>-1]);

    $needs_translation = [];
    foreach ($posts as $post) {
        $has_translation = false;

        if (function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($post->ID);
            if (isset($translations[$lang])) $has_translation = true;
        }

        if (!$has_translation) {
            $existing = get_posts([
                'post_type'  => $post_type,
                'meta_query' => [
                    ['key'=>'_wpi_translated_from', 'value'=>$post->ID],
                    ['key'=>'_wpi_language', 'value'=>$lang],
                ],
                'numberposts' => 1,
            ]);
            if (!empty($existing)) $has_translation = true;
        }

        if (!$has_translation) {
            $needs_translation[] = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
            ];
        }
    }

    if (empty($needs_translation)) {
        return wpilot_ok("All {$post_type}s already have a {$lang} translation.");
    }

    return wpilot_ok(count($needs_translation) . " {$post_type}s need translation to {$lang}. I\'ll translate them one by one -- starting with the most important pages.", [
        'needs_translation' => $needs_translation,
        'language' => $lang,
        'total' => count($needs_translation),
    ]);
}

function wpilot_multilingual_set_switcher($p) {
    $location = sanitize_text_field($p['location'] ?? 'menu');

    // Polylang language switcher
    if (function_exists('PLL') && defined('POLYLANG_VERSION')) {
        $opts = get_option('polylang', []);

        switch ($location) {
            case 'menu':
                $locations = get_nav_menu_locations();
                $primary = $locations['primary'] ?? $locations['main-menu'] ?? $locations['header-menu'] ?? null;
                if ($primary) {
                    if (!isset($opts['nav_menus'])) $opts['nav_menus'] = [];
                    $opts['nav_menus'][$primary] = [
                        'switcher' => 1,
                        'show_names' => 1,
                        'show_flags' => 1,
                        'hide_current' => 0,
                        'dropdown' => 0,
                    ];
                }
                break;
            case 'widget':
                return wpilot_ok("Polylang has a built-in language switcher widget. Go to Appearance > Widgets and add 'Polylang Language Switcher' to your sidebar or footer.");
            case 'shortcode':
                return wpilot_ok("Use this shortcode anywhere on your site:\n\n`[polylang_langswitcher]`\n\nOr in a page builder, add a shortcode widget with this content.");
        }

        update_option('polylang', $opts);
        return wpilot_ok("Language switcher added to {$location}. Shows flags + language names.");
    }

    // TranslatePress
    if (defined('TRP_PLUGIN_VERSION')) {
        $settings = get_option('trp_settings', []);
        $settings['add-subdirectory-to-default-language'] = 'yes';
        $settings['force-language-in-custom-links'] = 'yes';
        update_option('trp_settings', $settings);
        return wpilot_ok("TranslatePress language switcher is automatic -- it appears as a floating widget on the frontend. To customize placement, use shortcode: `[language-switcher]`");
    }

    return wpilot_ok("No multilingual plugin installed. Install Polylang first, then I\'ll set up the language switcher.");
}

// ==============================================================
//  PWA -- Progressive Web App
// ==============================================================

function wpilot_pwa_setup($p) {
    if (defined('PWA_VERSION') || class_exists('PWA')) {
        return wpilot_ok("PWA plugin already installed and active. Use pwa_configure to customize settings.");
    }
    if (defined('JEsuspended') || class_exists('SuperPWA')) {
        return wpilot_pwa_configure_superpwa($p);
    }

    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all_plugins = get_plugins();
    foreach ($all_plugins as $file => $data) {
        if (stripos($data['Name'], 'super progressive') !== false || stripos($file, 'developer/developer.php') !== false) {
            activate_plugin($file);
            return wpilot_ok("SuperPWA activated! Your site is now a Progressive Web App. Users can 'Add to Home Screen' on mobile.");
        }
    }

    return wpilot_pwa_manual_setup($p);
}

function wpilot_pwa_configure($p) {
    if (function_exists('developer_admin_interface') || class_exists('developer')) {
        return wpilot_pwa_configure_superpwa($p);
    }
    return wpilot_pwa_manual_setup($p);
}

function wpilot_pwa_configure_superpwa($p) {
    $settings = get_option('developer_settings', []);
    $settings['app_name']        = sanitize_text_field($p['app_name'] ?? get_bloginfo('name'));
    $settings['app_short_name']  = sanitize_text_field($p['short_name'] ?? substr(get_bloginfo('name'), 0, 12));
    $settings['description']     = sanitize_text_field($p['description'] ?? get_bloginfo('description'));
    $settings['background_color']= sanitize_hex_color($p['background_color'] ?? '#ffffff');
    $settings['theme_color']     = sanitize_hex_color($p['theme_color'] ?? '#5B7FFF');
    $settings['start_url']       = esc_url_raw($p['start_url'] ?? '/');
    $settings['display']         = sanitize_text_field($p['display'] ?? 'standalone');
    $settings['orientation']     = sanitize_text_field($p['orientation'] ?? 'portrait');
    if (!empty($p['icon'])) $settings['icon'] = esc_url_raw($p['icon']);
    update_option('developer_settings', $settings);
    return wpilot_ok("SuperPWA configured: \"{$settings['app_name']}\", theme color: {$settings['theme_color']}, display: {$settings['display']}. Users can now install your site as an app on their phone.");
}

function wpilot_pwa_manual_setup($p) {
    $site_name   = sanitize_text_field($p['app_name'] ?? get_bloginfo('name'));
    $short_name  = sanitize_text_field($p['short_name'] ?? substr($site_name, 0, 12));
    $description = sanitize_text_field($p['description'] ?? get_bloginfo('description'));
    $theme_color = sanitize_hex_color($p['theme_color'] ?? '#5B7FFF');
    $bg_color    = sanitize_hex_color($p['background_color'] ?? '#ffffff');
    $start_url   = esc_url_raw($p['start_url'] ?? '/');
    $display     = sanitize_text_field($p['display'] ?? 'standalone');
    $icon        = esc_url_raw($p['icon'] ?? '');

    if (empty($icon)) {
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $icon = wp_get_attachment_image_url($site_icon_id, 'full');
        }
    }

    // 1. Create manifest.json
    $manifest = [
        'name'             => $site_name,
        'short_name'       => $short_name,
        'description'      => $description,
        'start_url'        => $start_url,
        'display'          => $display,
        'background_color' => $bg_color,
        'theme_color'      => $theme_color,
        'orientation'      => 'portrait-primary',
        'icons'            => [],
    ];

    if ($icon) {
        foreach ([72, 96, 128, 144, 152, 192, 384, 512] as $size) {
            $manifest['icons'][] = [
                'src'   => $icon,
                'sizes' => "{$size}x{$size}",
                'type'  => 'image/png',
            ];
        }
    }

    $manifest_path = ABSPATH . 'manifest.json';
    file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // 2. Create basic service worker
    $sw_content = <<<'JS'
// WPilot PWA Service Worker
const CACHE_NAME = 'wpilot-pwa-v1';
const OFFLINE_URL = '/';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(['/', '/wp-content/themes/' + document.title]);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)));
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }
    event.respondWith(
        caches.match(event.request).then((cached) => {
            return cached || fetch(event.request).then((response) => {
                if (response.status === 200 && response.type === 'basic') {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            });
        })
    );
});
JS;

    $sw_path = ABSPATH . 'sw.js';
    file_put_contents($sw_path, $sw_content);

    // 3. Save settings for the wp_head hook
    update_option('wpi_pwa_enabled', 'yes');
    update_option('wpi_pwa_theme_color', $theme_color);

    $features = [
        "manifest.json created",
        "Service worker (sw.js) created with offline caching",
        "Theme color: {$theme_color}",
    ];
    if ($icon) $features[] = "App icon set";

    return wpilot_ok("Your site is now a PWA! " . implode('. ', $features) . ".\n\nUsers on mobile can tap 'Add to Home Screen' to install your site as an app. It works offline and loads instantly.", [
        'manifest_url'  => get_site_url() . '/manifest.json',
        'sw_url'        => get_site_url() . '/sw.js',
        'theme_color'   => $theme_color,
        'has_icon'      => !empty($icon),
    ]);
}

// -- PWA manifest + service worker registration ---------------
add_action('wp_head', function() {
    if (get_option('wpi_pwa_enabled') !== 'yes') return;
    $theme_color = get_option('wpi_pwa_theme_color', '#5B7FFF');
    echo '<link rel="manifest" href="' . esc_url(get_site_url() . '/manifest.json') . '">' . "\n";
    echo '<meta name="theme-color" content="' . esc_attr($theme_color) . '">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
});

add_action('wp_footer', function() {
    if (get_option('wpi_pwa_enabled') !== 'yes') return;
    echo '<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("' . esc_url(get_site_url() . '/sw.js') . '")}</script>' . "\n";
});

// ══════════════════════════════════════════════════════════════
//  CONTACT FORM 7 (CF7)
//  5M+ active installs — most installed WordPress plugin.
//  Uses wpcf7_contact_form post type + meta for form body/mail.
// ══════════════════════════════════════════════════════════════

function wpilot_cf7_installed() {
    return defined('WPCF7_VERSION') || class_exists('WPCF7') || function_exists('wpcf7');
}

/**
 * Build a CF7 shortcode tag for a single field definition.
 * CF7 tag format: [type* name "placeholder"] or [type name "placeholder"]
 * For select/checkbox/radio: [select name "opt1" "opt2"]
 */
function wpilot_cf7_build_tag( $field, $index ) {
    $label    = sanitize_text_field( $field['label']    ?? 'Field ' . $index );
    $type     = sanitize_text_field( $field['type']     ?? 'text' );
    $required = ! empty( $field['required'] );
    $options  = $field['options'] ?? [];

    // Derive a machine-safe name from the label
    $name = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $label ) );
    $name = trim( $name, '-' ) ?: 'field-' . $index;

    // Map friendly type names to CF7 tag types
    $type_map = [
        'text'     => 'text',
        'email'    => 'email',
        'tel'      => 'tel',
        'phone'    => 'tel',
        'textarea' => 'textarea',
        'select'   => 'select',
        'checkbox' => 'checkbox',
        'radio'    => 'radio',
        'file'     => 'file',
        'date'     => 'date',
        'number'   => 'number',
        'url'      => 'url',
    ];
    $cf7_type = $type_map[ $type ] ?? 'text';

    $req_mark = $required ? '*' : '';

    if ( in_array( $cf7_type, [ 'select', 'checkbox', 'radio' ], true ) ) {
        // Options are quoted strings after the name
        if ( empty( $options ) ) {
            $options = [ 'Option 1', 'Option 2', 'Option 3' ];
        }
        $opts_str = implode( ' ', array_map( function( $o ) {
            return '"' . esc_attr( $o ) . '"';
        }, (array) $options ) );
        return "[{$cf7_type}{$req_mark} {$name} {$opts_str}]";
    }

    if ( $cf7_type === 'textarea' ) {
        return "[textarea{$req_mark} {$name}]";
    }

    if ( $cf7_type === 'file' ) {
        return "[file{$req_mark} {$name} limit:5mb filetypes:pdf|doc|docx|jpg|png]";
    }

    return "[{$cf7_type}{$req_mark} {$name}]";
}

/**
 * Build the full CF7 form body from a fields array.
 * Returns an HTML string with <p>Label [tag]</p> rows + submit button.
 */
function wpilot_cf7_build_form_body( $fields ) {
    $rows   = [];
    $index  = 1;
    foreach ( $fields as $field ) {
        $label = sanitize_text_field( $field['label'] ?? 'Field ' . $index );
        $tag   = wpilot_cf7_build_tag( $field, $index );
        $rows[] = "<p><label>{$label}<br />\n    {$tag}</label></p>";
        $index++;
    }
    $rows[] = '<p>[submit "Send"]</p>';
    return implode( "\n", $rows );
}

/**
 * Build the mail body listing all fields.
 * CF7 uses [field-name] placeholders in mail templates.
 */
function wpilot_cf7_build_mail_body( $fields ) {
    $lines = [];
    $index = 1;
    foreach ( $fields as $field ) {
        $label = sanitize_text_field( $field['label'] ?? 'Field ' . $index );
        $name  = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $label ) );
        $name  = trim( $name, '-' ) ?: 'field-' . $index;
        $lines[] = "{$label}: [{$name}]";
        $index++;
    }
    return implode( "\n", $lines );
}

// Built by Christos Ferlachidis & Daniel Hedenberg

function wpilot_cf7_create_form( $p ) {
    if ( ! wpilot_cf7_installed() ) {
        return wpilot_err( 'Contact Form 7 is not installed. Install it from Plugins → Add New → search "Contact Form 7" (free, 5M+ installs).' );
    }

    $title  = sanitize_text_field( $p['title'] ?? 'Contact Form' );
    $fields = (array) ( $p['fields'] ?? [
        [ 'label' => 'Your Name',    'type' => 'text',     'required' => true  ],
        [ 'label' => 'Your Email',   'type' => 'email',    'required' => true  ],
        [ 'label' => 'Your Message', 'type' => 'textarea', 'required' => false ],
    ] );

    $form_body = wpilot_cf7_build_form_body( $fields );
    $mail_body = wpilot_cf7_build_mail_body( $fields );
    $admin_email = get_option( 'admin_email' );
    $site_title  = get_bloginfo( 'name' );

    // Mail settings stored as post meta '_mail'
    $mail = [
        'subject'    => "New message from [{$site_title}]: {$title}",
        'sender'     => "{$site_title} <wordpress@" . parse_url( get_home_url(), PHP_URL_HOST ) . ">",
        'recipient'  => $admin_email,
        'body'       => "From: [your-name] <[your-email]>\nSubject: " . $title . "\n\n{$mail_body}\n\n--\nThis email was sent via Contact Form 7 on {$site_title}.",
        'additional_headers' => 'Reply-To: [your-email]',
        'attachments'        => '',
        'use_html'           => false,
        'exclude_blank'      => false,
    ];

    // Fallback: direct wp_insert_post (works even if CF7 class not fully loaded)
    $post_id = wp_insert_post( [
        'post_type'   => 'wpcf7_contact_form',
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_name'   => sanitize_title( $title ),
    ] );

    if ( is_wp_error( $post_id ) ) {
        return wpilot_err( 'Could not create CF7 form: ' . $post_id->get_error_message() );
    }

    update_post_meta( $post_id, '_form',     $form_body );
    update_post_meta( $post_id, '_mail',     $mail );
    update_post_meta( $post_id, '_messages', [
        'mail_sent_ok'     => 'Thank you for your message. It has been sent.',
        'mail_sent_ng'     => 'There was an error trying to send your message. Please try again later.',
        'validation_error' => 'One or more fields have an error. Please check and try again.',
    ] );
    update_post_meta( $post_id, '_locale',   get_locale() );

    $shortcode = '[contact-form-7 id="' . $post_id . '" title="' . esc_attr( $title ) . '"]';
    return wpilot_ok(
        "Contact Form 7 form \"{$title}\" created (ID: {$post_id}). Embed it on any page with:\n\n{$shortcode}",
        [ 'form_id' => $post_id, 'shortcode' => $shortcode ]
    );
}

function wpilot_cf7_list_forms( $p ) {
    if ( ! wpilot_cf7_installed() ) {
        return wpilot_err( 'Contact Form 7 is not installed.' );
    }

    $posts = get_posts( [
        'post_type'      => 'wpcf7_contact_form',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    if ( empty( $posts ) ) {
        return wpilot_ok( 'No Contact Form 7 forms found. Create one with cf7_create_form.', [ 'forms' => [] ] );
    }

    global $wpdb;
    $forms = [];
    foreach ( $posts as $post ) {
        $form_id   = $post->ID;
        $shortcode = '[contact-form-7 id="' . $form_id . '" title="' . esc_attr( $post->post_title ) . '"]';

        // Count submissions via flamingo (CF7 companion plugin) or meta
        $submission_count = 0;
        if ( post_type_exists( 'flamingo_inbound' ) ) {
            $submission_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'flamingo_inbound'
                 AND pm.meta_key = '_meta' AND pm.meta_value LIKE %s",
                '%' . $wpdb->esc_like( '"contact-form-id";i:' . $form_id ) . '%'
            ) );
        }

        $forms[] = [
            'id'               => $form_id,
            'title'            => $post->post_title,
            'shortcode'        => $shortcode,
            'submission_count' => $submission_count,
            'edit_url'         => admin_url( 'admin.php?page=wpcf7&action=edit&post=' . $form_id ),
        ];
    }

    $count = count( $forms );
    return wpilot_ok(
        "{$count} Contact Form 7 form(s) found.",
        [ 'forms' => $forms, 'count' => $count ]
    );
}

function wpilot_cf7_get_form( $p ) {
    if ( ! wpilot_cf7_installed() ) {
        return wpilot_err( 'Contact Form 7 is not installed.' );
    }

    $form_id = (int) ( $p['form_id'] ?? 0 );
    if ( ! $form_id ) {
        return wpilot_err( 'form_id is required.' );
    }

    $post = get_post( $form_id );
    if ( ! $post || $post->post_type !== 'wpcf7_contact_form' ) {
        return wpilot_err( "CF7 form #{$form_id} not found. Use cf7_list_forms to see available forms." );
    }

    $form_body = get_post_meta( $form_id, '_form',     true );
    $mail      = get_post_meta( $form_id, '_mail',     true );
    $messages  = get_post_meta( $form_id, '_messages', true );

    // Also try CF7 API for richer data
    if ( class_exists( 'WPCF7_ContactForm' ) ) {
        $cf7 = WPCF7_ContactForm::get_instance( $form_id );
        if ( $cf7 ) {
            $form_body = $cf7->prop( 'form' ) ?: $form_body;
            $mail      = $cf7->prop( 'mail' ) ?: $mail;
            $messages  = $cf7->prop( 'messages' ) ?: $messages;
        }
    }

    $shortcode = '[contact-form-7 id="' . $form_id . '" title="' . esc_attr( $post->post_title ) . '"]';

    return wpilot_ok(
        "CF7 form \"{$post->post_title}\" (ID: {$form_id}) retrieved.",
        [
            'form_id'   => $form_id,
            'title'     => $post->post_title,
            'shortcode' => $shortcode,
            'form_body' => $form_body,
            'mail'      => $mail,
            'messages'  => $messages,
            'edit_url'  => admin_url( 'admin.php?page=wpcf7&action=edit&post=' . $form_id ),
        ]
    );
}

function wpilot_cf7_update_form( $p ) {
    if ( ! wpilot_cf7_installed() ) {
        return wpilot_err( 'Contact Form 7 is not installed.' );
    }

    $form_id = (int) ( $p['form_id'] ?? 0 );
    if ( ! $form_id ) {
        return wpilot_err( 'form_id is required.' );
    }

    $post = get_post( $form_id );
    if ( ! $post || $post->post_type !== 'wpcf7_contact_form' ) {
        return wpilot_err( "CF7 form #{$form_id} not found. Use cf7_list_forms to see available forms." );
    }

    $updated = [];

    // Update title
    if ( ! empty( $p['title'] ) ) {
        wp_update_post( [ 'ID' => $form_id, 'post_title' => sanitize_text_field( $p['title'] ) ] );
        $updated[] = 'title';
    }

    // Rebuild form body from new fields array
    if ( ! empty( $p['fields'] ) ) {
        $form_body = wpilot_cf7_build_form_body( (array) $p['fields'] );
        update_post_meta( $form_id, '_form', $form_body );
        $updated[] = 'form_body';
    }

    // Update raw form body directly (overrides fields rebuild)
    if ( ! empty( $p['form_body'] ) ) {
        update_post_meta( $form_id, '_form', wp_kses_post( $p['form_body'] ) );
        $updated[] = 'form_body';
    }

    // Update mail settings (merge, not replace)
    if ( ! empty( $p['mail'] ) ) {
        $existing_mail = get_post_meta( $form_id, '_mail', true ) ?: [];
        $new_mail = array_merge( (array) $existing_mail, (array) $p['mail'] );
        // Sanitize mail fields
        if ( ! empty( $new_mail['recipient'] ) )  $new_mail['recipient']  = sanitize_email( $new_mail['recipient'] );
        if ( ! empty( $new_mail['subject'] ) )    $new_mail['subject']    = sanitize_text_field( $new_mail['subject'] );
        if ( ! empty( $new_mail['sender'] ) )     $new_mail['sender']     = sanitize_text_field( $new_mail['sender'] );
        update_post_meta( $form_id, '_mail', $new_mail );
        $updated[] = 'mail';
    }

    // Update messages
    if ( ! empty( $p['messages'] ) ) {
        $existing_msgs = get_post_meta( $form_id, '_messages', true ) ?: [];
        $new_msgs = array_merge( (array) $existing_msgs, array_map( 'sanitize_text_field', (array) $p['messages'] ) );
        update_post_meta( $form_id, '_messages', $new_msgs );
        $updated[] = 'messages';
    }

    // Sync via CF7 API if available (flushes CF7 caches)
    if ( class_exists( 'WPCF7_ContactForm' ) ) {
        $cf7 = WPCF7_ContactForm::get_instance( $form_id );
        if ( $cf7 ) {
            $props = [];
            if ( in_array( 'form_body', $updated, true ) ) {
                $props['form'] = get_post_meta( $form_id, '_form', true );
            }
            if ( in_array( 'mail', $updated, true ) ) {
                $props['mail'] = get_post_meta( $form_id, '_mail', true );
            }
            if ( in_array( 'messages', $updated, true ) ) {
                $props['messages'] = get_post_meta( $form_id, '_messages', true );
            }
            if ( ! empty( $props ) ) {
                $cf7->set_properties( $props );
                $cf7->save();
            }
        }
    }

    if ( empty( $updated ) ) {
        return wpilot_err( 'No update parameters provided. Supported: title, fields, form_body, mail (recipient/subject/body/sender), messages.' );
    }

    $title = get_the_title( $form_id );
    return wpilot_ok(
        "CF7 form \"{$title}\" (ID: {$form_id}) updated: " . implode( ', ', $updated ) . '.',
        [ 'form_id' => $form_id, 'updated' => $updated ]
    );
}

function wpilot_bulk_import_products($params) {
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
    $csv = $params['csv'] ?? '';
    if (empty($csv)) return wpilot_err('CSV data required. Format: name,price,description,sku,category,image_url,stock');

    $lines = preg_split("/\r\n|\r|\n/", trim($csv));
    if (count($lines) < 2) return wpilot_err('CSV must have header row + at least 1 data row.');

    $headers = array_map('strtolower', array_map('trim', str_getcsv(array_shift($lines))));
    $created = 0; $failed = 0; $errors = [];

    foreach ($lines as $i => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $cols = str_getcsv($line);
        $row = [];
        foreach ($headers as $j => $h) { $row[$h] = $cols[$j] ?? ''; }

        $name = sanitize_text_field($row['name'] ?? '');
        if (empty($name)) { $failed++; $errors[] = "Row " . ($i+2) . ": missing name"; continue; }

        try {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            if (!empty($row['price'])) $product->set_regular_price(sanitize_text_field($row['price']));
            if (!empty($row['description'])) $product->set_description(wp_kses_post($row['description']));
            if (!empty($row['sku'])) $product->set_sku(sanitize_text_field($row['sku']));
            if (!empty($row['stock'])) { $product->set_manage_stock(true); $product->set_stock_quantity(intval($row['stock'])); }
            if (!empty($row['sale_price'])) $product->set_sale_price(sanitize_text_field($row['sale_price']));
            $product->set_status('publish');
            $pid = $product->save();

            if (!empty($row['category'])) {
                $cat_name = sanitize_text_field($row['category']);
                $term = get_term_by('name', $cat_name, 'product_cat');
                if (!$term) { $result = wp_insert_term($cat_name, 'product_cat'); $term_id = is_array($result) ? $result['term_id'] : 0; }
                else { $term_id = $term->term_id; }
                if ($term_id) wp_set_object_terms($pid, [$term_id], 'product_cat');
            }

            if (!empty($row['image_url'])) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $img_id = media_sideload_image(esc_url_raw($row['image_url']), $pid, $name, 'id');
                if (!is_wp_error($img_id)) set_post_thumbnail($pid, $img_id);
            }
            $created++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = "Row " . ($i+2) . ": " . $e->getMessage();
        }
    }

    return wpilot_ok("Imported {$created} products" . ($failed ? ", {$failed} failed" : "") . ".", [
        'created' => $created, 'failed' => $failed, 'errors' => array_slice($errors, 0, 5),
    ]);
}
