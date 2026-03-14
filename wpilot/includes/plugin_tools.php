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

        case 'woo_update_store_settings':
            return wpilot_woo_update_store_settings( $params );

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
    if ( $wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table )
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
    $enabled   = $p['enabled'] ?? true;
    if ( !$gateway ) return wpilot_err('gateway parameter required. E.g. "stripe", "paypal", "klarna", "cod".');
    $option_key = 'woocommerce_'.$gateway.'_settings';
    $settings   = get_option($option_key, []);
    $settings['enabled'] = $enabled ? 'yes' : 'no';
    update_option($option_key, $settings);
    $status = $enabled ? 'enabled' : 'disabled';
    return wpilot_ok("✅ WooCommerce payment gateway \"{$gateway}\" {$status}. Go to WooCommerce → Settings → Payments to configure details.");
}

function wpilot_woo_set_tax_rate( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    // Enable taxes first
    update_option('woocommerce_calc_taxes', 'yes');
    global $wpdb;
    $rate     = (float)($p['rate']     ?? 25);
    $country  = strtoupper(sanitize_text_field($p['country'] ?? 'SE'));
    $name     = sanitize_text_field($p['name'] ?? 'VAT');
    $class    = sanitize_text_field($p['class'] ?? '');
    // Insert tax rate
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
    return wpilot_ok("✅ Tax rate {$rate}% ({$name}) added for {$country}. WooCommerce taxes are now enabled.");
}

function wpilot_woo_update_store_settings( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $map = [
        'currency'         => 'woocommerce_currency',
        'country'          => 'woocommerce_default_country',
        'shop_page'        => 'woocommerce_shop_page_id',
        'cart_page'        => 'woocommerce_cart_page_id',
        'checkout_page'    => 'woocommerce_checkout_page_id',
        'price_thousand'   => 'woocommerce_price_thousand_sep',
        'price_decimal'    => 'woocommerce_price_decimal_sep',
        'currency_pos'     => 'woocommerce_currency_pos',
    ];
    $updated = [];
    foreach ($map as $key => $option) {
        if ( isset($p[$key]) ) { update_option($option, sanitize_text_field($p[$key])); $updated[] = $key; }
    }
    return empty($updated)
        ? wpilot_err('No valid settings provided.')
        : wpilot_ok("✅ WooCommerce settings updated: " . implode(', ', $updated));
}

function wpilot_woo_update_shipping_zone( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name( sanitize_text_field($p['name'] ?? 'Shipping Zone') );
    if ( !empty($p['countries']) ) {
        foreach ((array)$p['countries'] as $country) {
            $zone->add_location(strtoupper($country), 'country');
        }
    }
    $zone_id = $zone->save();
    if ( !empty($p['method']) ) {
        $zone->add_shipping_method(sanitize_text_field($p['method']));
    }
    return wpilot_ok("✅ Shipping zone \"{$p['name']}\" created (ID: {$zone_id}). Go to WooCommerce → Settings → Shipping to set rates.");
}

function wpilot_woo_create_shipping_class( $p ) {
    if ( !wpilot_woo_installed() ) return wpilot_err('WooCommerce is not installed.');
    $name = sanitize_text_field($p['name'] ?? 'Shipping Class');
    $term = wp_insert_term($name, 'product_shipping_class', ['description'=>$p['description']??'','slug'=>sanitize_title($name)]);
    if ( is_wp_error($term) ) return wpilot_err($term->get_error_message());
    return wpilot_ok("✅ Shipping class \"{$name}\" created.");
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
    $slug = sanitize_text_field($p['slug'] ?? '');
    if ( !$slug ) return wpilot_err('Plugin slug required. E.g. "amelia", "woocommerce", "learndash".');
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $api = plugins_api('plugin_information',['slug'=>$slug,'fields'=>['sections'=>false]]);
    if ( is_wp_error($api) ) return wpilot_err("Plugin \"{$slug}\" not found on WordPress.org: " . $api->get_error_message());
    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result   = $upgrader->install($api->download_link);
    if ( is_wp_error($result) ) return wpilot_err("Install failed: " . $result->get_error_message());
    return wpilot_ok("✅ Plugin \"{$api->name}\" installed successfully. Activate it to start using it.");
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
