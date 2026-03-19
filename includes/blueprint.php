<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 *
 * Each copy is bound to a specific domain via license key.
 * Tampered or unlicensed copies will be disabled remotely.
 */
if ( ! defined( 'ABSPATH' ) ) exit;


// ═══════════════════════════════════════════════════════════════
// WPILOT BLUEPRINT — Full site DNA in minimal tokens
// Scans once → caches → sends compressed → re-scans on change
// ═══════════════════════════════════════════════════════════════

define('WPI_BLUEPRINT_VERSION', '1.1');
define('WPI_BLUEPRINT_KEY', 'wpilot_blueprint_v1');
define('WPI_BLUEPRINT_HASH', 'wpilot_blueprint_hash');

// ── Build full blueprint (heavy scan — runs rarely) ──────────
function wpilot_build_blueprint() {
    $bp = [];
    $bp['v'] = WPI_BLUEPRINT_VERSION;
    $bp['ts'] = time();
    $bp['wp'] = get_bloginfo('version');
    $bp['php'] = PHP_VERSION;
    $bp['url'] = get_site_url();
    $bp['name'] = get_bloginfo('name');
    $bp['lang'] = get_locale();
    $bp['mem'] = ini_get('memory_limit');

    // Theme
    $t = wp_get_theme();
    $bp['theme'] = $t->get('Name') . ' ' . $t->get('Version');
    $bp['builder'] = wpilot_detect_builder();

    // Fetch live homepage HTML once — used for header, footer, theme structure
    $home_html = wp_remote_retrieve_body(wp_remote_get(home_url("/"), ["timeout"=>5,"sslverify"=>false]));

    // Theme HTML structure — class-name summary
    if ($home_html) {
        $struct = [];
        if (preg_match("/<header[^>]*class=\"([^\"]+)\"/", $home_html, $hm)) $struct[] = "header:." . explode(" ",$hm[1])[0];
        if (preg_match("/<nav[^>]*class=\"([^\"]+)\"/", $home_html, $nm)) $struct[] = "nav:." . explode(" ",$nm[1])[0];
        if (preg_match("/<footer[^>]*class=\"([^\"]+)\"/", $home_html, $fm)) $struct[] = "footer:." . explode(" ",$fm[1])[0];
        if (strpos($home_html,"navigation-toggle")!==false) $struct[] = "has_hamburger:yes";
        if (strpos($home_html,"navigation-dropdown")!==false) $struct[] = "has_mobile_dropdown:yes";
        $bp["theme_html"] = implode("|", $struct);
    }

    // 1. Full header HTML (first <header>…</header> block, truncated to 500 chars)
    if ($home_html) {
        if (preg_match('/<header[\s>][\s\S]*?<\/header>/i', $home_html, $hm2)) {
            $bp['header_html'] = substr(preg_replace('/\s+/', ' ', $hm2[0]), 0, 500);
        }
    }

    // 2. Full footer HTML (first <footer>…</footer> block, truncated to 500 chars)
    if ($home_html) {
        if (preg_match('/<footer[\s>][\s\S]*?<\/footer>/i', $home_html, $fm2)) {
            $bp['footer_html'] = substr(preg_replace('/\s+/', ' ', $fm2[0]), 0, 500);
        }
    }

    // Security map
    $bp["security"] = [
        "ssl" => is_ssl(),
        "debug" => defined("WP_DEBUG") && WP_DEBUG,
        "file_edit" => !defined("DISALLOW_FILE_EDIT") || !DISALLOW_FILE_EDIT,
        "xmlrpc" => file_exists(WPMU_PLUGIN_DIR . "/wpilot-disable-xmlrpc.php") ? "disabled" : "enabled",
        "headers" => file_exists(WPMU_PLUGIN_DIR . "/wpilot-security-headers.php"),
    ];

    // All plugins — compact: "name:version:active"
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all = get_plugins();
    $active = get_option('active_plugins', []);
    $bp['plugins'] = [];
    foreach ($all as $file => $p) {
        $slug = explode('/', $file)[0];
        $bp['plugins'][] = $slug . ':' . $p['Version'] . ':' . (in_array($file, $active) ? '1' : '0');
    }

    // All pages — compact, with Elementor widget list appended
    $pages = get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1]);
    $bp['pages'] = [];
    foreach ($pages as $p) {
        $builder = 'none';
        if (get_post_meta($p->ID, '_elementor_edit_mode', true)) $builder = 'el';
        elseif (strpos($p->post_content, '[et_pb_') !== false) $builder = 'divi';
        elseif (strpos($p->post_content, '<!-- wp:') !== false) $builder = 'gb';

        $content = $p->post_content;
        // Get real content from Elementor
        if ($builder === 'el' && function_exists('wpilot_extract_builder_html')) {
            $el_data = json_decode(get_post_meta($p->ID, '_elementor_data', true), true);
            if (is_array($el_data)) $content = wpilot_extract_builder_html($el_data);
        }
        $text = wp_trim_words(strip_tags($content), 20, '');

        // 3. Elementor widgets — pipe-delimited type:count pairs
        $widgets_str = '';
        if ($builder === 'el' && function_exists('wpilot_count_elementor_widgets')) {
            $el_raw = get_post_meta($p->ID, '_elementor_data', true);
            if ($el_raw) {
                $el_json = json_decode($el_raw, true);
                if (is_array($el_json)) {
                    $wcounts = wpilot_count_elementor_widgets($el_json);
                    arsort($wcounts);
                    $wparts = [];
                    foreach ($wcounts as $wtype => $wcnt) {
                        $wparts[] = $wtype . ':' . $wcnt;
                    }
                    $widgets_str = implode('|', $wparts);
                }
            }
        }

        $bp['pages'][] = $p->ID . '|' . $p->post_name . '|' . $p->post_status . '|' . $builder . '|' . get_post_meta($p->ID, '_wp_page_template', true) . '|' . $text . ($widgets_str ? '|widgets:' . $widgets_str : '');
    }

    // All posts
    $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 20]);
    $bp['posts'] = array_map(fn($p) => $p->ID . '|' . $p->post_name . '|' . wp_trim_words(strip_tags($p->post_content), 10, ''), $posts);

    // WooCommerce
    if (class_exists('WooCommerce')) {
        $bp['woo'] = [
            'cur' => get_woocommerce_currency(),
            'country' => get_option('woocommerce_default_country'),
            'tax' => get_option('woocommerce_calc_taxes'),
            'coming_soon' => get_option('woocommerce_coming_soon'),
            'shop' => get_option('woocommerce_shop_page_id'),
            'cart' => get_option('woocommerce_cart_page_id'),
            'checkout' => get_option('woocommerce_checkout_page_id'),
            'myaccount' => get_option('woocommerce_myaccount_page_id'),
        ];

        // 5. WooCommerce page templates — cart/checkout/shop: template|content_type
        $woo_page_ids = [
            'shop'     => (int) get_option('woocommerce_shop_page_id'),
            'cart'     => (int) get_option('woocommerce_cart_page_id'),
            'checkout' => (int) get_option('woocommerce_checkout_page_id'),
        ];
        $woo_tpl_parts = [];
        foreach ($woo_page_ids as $label => $pid) {
            if (!$pid) continue;
            $tpl = get_post_meta($pid, '_wp_page_template', true) ?: 'default';
            $pcontent = get_post_field('post_content', $pid);
            $stripped = trim(strip_tags($pcontent));
            $only_shortcodes = (bool) preg_match('/^\[[\w_]+/', $stripped) && strlen($stripped) < 200;
            $woo_tpl_parts[] = $label . ':' . $tpl . ':' . ($only_shortcodes ? 'shortcode' : 'custom');
        }
        $bp['woo']['page_templates'] = implode('|', $woo_tpl_parts);

        // Products compact: "id|name|price|sale|stock|has_img|cats"
        $products = wc_get_products(['limit' => 100, 'status' => 'publish']);
        $bp['products'] = [];
        $bp['products_total'] = count(wc_get_products(['limit' => -1, 'return' => 'ids', 'status' => 'publish']));
        foreach ($products as $pr) {
            $cats = wp_get_post_terms($pr->get_id(), 'product_cat', ['fields' => 'names']);
            $bp['products'][] = $pr->get_id() . '|' . $pr->get_name() . '|' . $pr->get_price() . '|' . ($pr->get_sale_price() ?: '-') . '|' . $pr->get_stock_status() . '|' . ($pr->get_image_id() ? '1' : '0') . '|' . implode(',', $cats);
        }
        // Orders summary
        $bp['orders'] = [
            'total' => count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => ['completed','processing']])),
            'pending' => count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => 'pending'])),
        ];
        // Coupons
        $coupons = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 10]);
        $bp['coupons'] = array_map(function($c) {
            $amt = get_post_meta($c->ID, 'coupon_amount', true);
            $type = get_post_meta($c->ID, 'discount_type', true);
            return $c->post_title . ':' . $amt . ($type === 'percent' ? '%' : '');
        }, $coupons);

        // 7. Active payment gateways — compact pipe-delimited string of enabled gateway IDs
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $bp['payments'] = implode('|', array_keys($gateways));
    }

    // Menus compact
    $menus = wp_get_nav_menus();
    $locations = get_nav_menu_locations();
    $bp['menus'] = [];
    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id) ?: [];
        $loc = array_search($menu->term_id, $locations) ?: '-';
        $item_str = array_map(fn($i) => $i->title . '>' . str_replace(get_site_url(), '', $i->url), $items);
        $bp['menus'][] = $menu->name . '@' . $loc . ':' . implode(',', $item_str);
    }

    // Built by Weblease

    // 4. CSS summary — byte count + first 300 chars of actual CSS rules
    $raw_css = wp_get_custom_css();
    $bp['css_bytes'] = strlen($raw_css);
    if ($raw_css) {
        $bp['css_head'] = substr(preg_replace('/\s+/', ' ', $raw_css), 0, 300);
    }

    // 6. Favicon — detect type: none | image | svg | emoji
    $favicon_type = 'none';
    $site_icon_id = (int) get_option('site_icon');
    if ($site_icon_id) {
        $mime = get_post_mime_type($site_icon_id);
        $favicon_type = ($mime === 'image/svg+xml') ? 'svg' : 'image';
    } else {
        $icon_url = get_site_icon_url(32);
        if ($icon_url) {
            $favicon_type = (strpos($icon_url, 'emoji') !== false || strpos($icon_url, '.svg') !== false) ? 'emoji' : 'image';
        } elseif ($home_html && preg_match('/<link[^>]+rel=["\'](?:shortcut icon|icon)["\'][^>]*>/i', $home_html, $ficom)) {
            $favicon_type = (strpos($ficom[0], '.svg') !== false) ? 'svg' : 'image';
        }
    }
    $bp['favicon'] = $favicon_type;

    // mu-plugins
    $mu_dir = WPMU_PLUGIN_DIR;
    $bp['mu'] = [];
    if (is_dir($mu_dir)) {
        foreach (scandir($mu_dir) as $f) {
            if (str_ends_with($f, '.php')) $bp['mu'][] = $f;
        }
    }

    // Theme files
    $td = get_stylesheet_directory();
    $bp['theme_files'] = [];
    foreach (scandir($td) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_dir($td . '/' . $f)) $bp['theme_files'][] = $f . '/';
        elseif (preg_match('/\.(php|css|js|json)$/', $f)) $bp['theme_files'][] = $f;
    }

    // Templates
    $bp['templates'] = array_keys(wp_get_theme()->get_page_templates());

    // Users summary
    $bp['users'] = [];
    foreach (['administrator','editor','author','shop_manager','customer','subscriber'] as $role) {
        $count = count(get_users(['role' => $role, 'fields' => 'ID']));
        if ($count > 0) $bp['users'][] = $role . ':' . $count;
    }

    // Homepage
    $bp['front'] = [
        'type' => get_option('show_on_front'),
        'page' => get_option('page_on_front'),
        'blog' => get_option('page_for_posts'),
    ];

    // SEO plugin
    if (defined('WPSEO_VERSION')) $bp['seo'] = 'yoast:' . WPSEO_VERSION;
    elseif (class_exists('RankMath')) $bp['seo'] = 'rankmath';
    else $bp['seo'] = 'none';

    // Permalinks
    $bp['permalink'] = get_option('permalink_structure') ?: 'default';

    // ═══ WORDPRESS DNA — complete site overview ═══
    $td = get_stylesheet_directory();
    $tpl = [];
    foreach (["header.php","footer.php","sidebar.php","single.php","page.php","archive.php","index.php","functions.php","front-page.php","single-product.php","woocommerce.php"] as $tf) {
        if (file_exists($td . "/" . $tf)) $tpl[] = $tf;
    }
    $bp["tpl"] = implode(",", $tpl);

    $cpt = [];
    foreach (get_post_types(["_builtin"=>false,"public"=>true], "objects") as $pt) {
        $c = wp_count_posts($pt->name);
        $cpt[] = $pt->name . ":" . ($c->publish ?? 0);
    }
    $bp["post_types"] = implode(",", $cpt);

    global $shortcode_tags;
    $sc = array_diff(array_keys($shortcode_tags ?? []), ["wp_caption","caption","gallery","playlist","audio","video","embed"]);
    $bp["shortcodes"] = implode(",", array_slice(array_values($sc), 0, 15));

    global $wpdb;
    $core = [$wpdb->prefix."posts",$wpdb->prefix."postmeta",$wpdb->prefix."options",$wpdb->prefix."users",$wpdb->prefix."usermeta",$wpdb->prefix."terms",$wpdb->prefix."term_taxonomy",$wpdb->prefix."term_relationships",$wpdb->prefix."termmeta",$wpdb->prefix."comments",$wpdb->prefix."commentmeta",$wpdb->prefix."links"];
    $custom = array_diff($wpdb->get_col("SHOW TABLES"), $core);
    $bp["db_custom"] = count($custom) . ":" . implode(",", array_slice(array_map(function($t) use ($wpdb) { return str_replace($wpdb->prefix, "", $t); }, array_values($custom)), 0, 8));

    $bp["php_ext"] = implode(",", array_filter(["gd","imagick","curl","mbstring","xml","zip","intl","opcache"], "extension_loaded"));
    $bp["wp_const"] = implode(",", array_filter([
        defined("WP_DEBUG") && WP_DEBUG ? "DEBUG" : null,
        defined("WP_CACHE") && WP_CACHE ? "CACHE" : null,
        defined("DISALLOW_FILE_EDIT") && DISALLOW_FILE_EDIT ? "NO_EDIT" : null,
    ]));
    $bp["rest"] = rest_url() ? "on" : "off";

    // Design profile — per-site visual DNA
    if ( function_exists( 'wpilot_design_profile_compact' ) ) {
        $design = wpilot_design_profile_compact();
        if ( $design ) $bp['design_profile'] = $design;
    }

    // Generate hash to detect changes
    $hash = md5(json_encode($bp));
    $bp['hash'] = $hash;

    // Cache it
    update_option(WPI_BLUEPRINT_KEY, $bp, false);
    update_option(WPI_BLUEPRINT_HASH, $hash, false);

    return $bp;
}

// ── Get blueprint (cached or rebuild) ────────────────────────
function wpilot_get_blueprint($force = false) {
    if (!$force) {
        $cached = get_option(WPI_BLUEPRINT_KEY, null);
        if ($cached && isset($cached['ts'])) {
            // Check if anything changed since last scan
            $current_hash = wpilot_quick_hash();
            $stored_hash = get_option(WPI_BLUEPRINT_HASH, '');

            // Re-use cache if nothing changed (max 5 min old)
            if ($current_hash === $stored_hash && (time() - $cached['ts']) < 300) {
                return $cached;
            }
        }
    }
    return wpilot_build_blueprint();
}

// ── Quick hash to detect changes (cheap — no full scan) ──────
function wpilot_quick_hash() {
    $parts = [
        wp_count_posts('page')->publish,
        wp_count_posts('post')->publish,
        class_exists('WooCommerce') ? wp_count_posts('product')->publish : 0,
        count(get_option('active_plugins', [])),
        strlen(wp_get_custom_css()),
        get_option('page_on_front'),
        get_option('blogname'),
        class_exists('WooCommerce') ? count(wc_get_products(['limit'=>-1,'return'=>'ids','status'=>'publish'])) : 0,
    ];
    if (class_exists('WooCommerce')) {
        $parts[] = get_option('woocommerce_cart_page_id');
        $parts[] = get_option('woocommerce_checkout_page_id');
        $parts[] = get_option('woocommerce_currency');
    }
    // Include nav menu count
    $menus = wp_get_nav_menus();
    $parts[] = count($menus);
    foreach ($menus as $m) {
        $parts[] = count(wp_get_nav_menu_items($m->term_id) ?: []);
    }
    return md5(implode('|', $parts));
}

// ── Compress blueprint for Claude (minimal tokens) ───────────
function wpilot_compress_blueprint($bp) {
    // Remove hash and timestamp from compressed version
    unset($bp['hash'], $bp['ts'], $bp['v']);
    // Remove fields duplicated in security block
    unset($bp['ssl'], $bp['debug']);
    return $bp;
}


// ═══════════════════════════════════════════════════════════════
// LIVE TRACKING — blueprint auto-updates on every WordPress change


// Detect WordPress core update
add_action("upgrader_process_complete", function($upgrader, $options) {
    if (isset($options["type"]) && $options["type"] === "core") {
        wpilot_notify_update("wordpress_core", get_bloginfo("version"));
    }
    if (isset($options["type"]) && $options["type"] === "plugin") {
        wpilot_notify_update("plugin_update", json_encode($options["plugins"] ?? []));
    }
    if (isset($options["type"]) && $options["type"] === "theme") {
        wpilot_notify_update("theme_update", json_encode($options["themes"] ?? []));
    }
    wpilot_invalidate_blueprint();
}, 10, 2);

// Check for version changes on admin_init (catches manual updates too)
add_action("admin_init", function() {
    $stored_wp = get_option("wpilot_last_wp_version", "");
    $stored_php = get_option("wpilot_last_php_version", "");
    $current_wp = get_bloginfo("version");
    $current_php = PHP_VERSION;

    if ($stored_wp && $stored_wp !== $current_wp) {
        wpilot_notify_update("wordpress_updated", $stored_wp . " -> " . $current_wp);
    }
    if ($stored_php && $stored_php !== $current_php) {
        wpilot_notify_update("php_updated", $stored_php . " -> " . $current_php);
    }

    update_option("wpilot_last_wp_version", $current_wp, false);
    update_option("wpilot_last_php_version", $current_php, false);
});

// Detect fatal errors and notify
add_action("shutdown", function() {
    $error = error_get_last();
    if ($error && in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Only notify once per error (throttle)
        $error_hash = md5($error["message"] . $error["file"]);
        $last_error = get_transient("wpilot_last_error_hash");
        if ($last_error !== $error_hash) {
            set_transient("wpilot_last_error_hash", $error_hash, 3600);
            wpilot_notify_update("fatal_error", json_encode([
                "message" => substr($error["message"], 0, 200),
                "file" => basename($error["file"]),
                "line" => $error["line"],
            ]));
        }
    }
});


function wpilot_notify_update($event, $details = "") {
    $site_url = get_site_url();
    $license = get_option("ca_license_key", "");

    // Non-blocking — fire and forget
    wp_remote_post("https://weblease.se/api/plugin-events/notify", [
        "timeout" => 5,
        "blocking" => false,
        "headers" => ["Content-Type" => "application/json"],
        "body" => wp_json_encode([
            "event" => $event,
            "details" => $details,
            "site_url" => $site_url,
            "license" => $license,
            "wp_version" => get_bloginfo("version"),
            "php_version" => PHP_VERSION,
            "plugin_version" => defined("CA_VERSION") ? CA_VERSION : "unknown",
            "timestamp" => gmdate("Y-m-d H:i:s"),
        ]),
    ]);
}


// ═══════════════════════════════════════════════════════════════

// Invalidate blueprint cache when content changes
function wpilot_invalidate_blueprint() {
    delete_option(WPI_BLUEPRINT_HASH); // Forces rebuild on next request
}

// Pages/Posts
add_action('save_post', 'wpilot_invalidate_blueprint');
add_action('delete_post', 'wpilot_invalidate_blueprint');
add_action('wp_trash_post', 'wpilot_invalidate_blueprint');

// Plugins
add_action('activated_plugin', 'wpilot_invalidate_blueprint');
add_action('deactivated_plugin', 'wpilot_invalidate_blueprint');
add_action('deleted_plugin', 'wpilot_invalidate_blueprint');
add_action('upgrader_process_complete', 'wpilot_invalidate_blueprint');

// Theme
add_action('switch_theme', 'wpilot_invalidate_blueprint');

// Menus
add_action('wp_update_nav_menu', 'wpilot_invalidate_blueprint');
add_action('wp_delete_nav_menu', 'wpilot_invalidate_blueprint');

// WooCommerce
add_action('woocommerce_new_product', 'wpilot_invalidate_blueprint');
add_action('woocommerce_update_product', 'wpilot_invalidate_blueprint');
add_action('woocommerce_delete_product', 'wpilot_invalidate_blueprint');
add_action('woocommerce_new_order', 'wpilot_invalidate_blueprint');
add_action('woocommerce_order_status_changed', 'wpilot_invalidate_blueprint');

// Options that matter
add_action('update_option_woocommerce_currency', 'wpilot_invalidate_blueprint');
add_action('update_option_woocommerce_default_country', 'wpilot_invalidate_blueprint');
add_action('update_option_woocommerce_cart_page_id', 'wpilot_invalidate_blueprint');
add_action('update_option_woocommerce_checkout_page_id', 'wpilot_invalidate_blueprint');
add_action('update_option_page_on_front', 'wpilot_invalidate_blueprint');
add_action('update_option_show_on_front', 'wpilot_invalidate_blueprint');
add_action('update_option_blogname', 'wpilot_invalidate_blueprint');
add_action('update_option_WPLANG', 'wpilot_invalidate_blueprint');
add_action('update_option_permalink_structure', 'wpilot_invalidate_blueprint');
add_action('update_option_active_plugins', 'wpilot_invalidate_blueprint');

// CSS
add_action('wp_update_custom_css_post', 'wpilot_invalidate_blueprint');

// Users
add_action('user_register', 'wpilot_invalidate_blueprint');
add_action('delete_user', 'wpilot_invalidate_blueprint');
add_action('set_user_role', 'wpilot_invalidate_blueprint');

// Media
add_action('add_attachment', 'wpilot_invalidate_blueprint');
add_action('delete_attachment', 'wpilot_invalidate_blueprint');

// After WPilot tool runs — always invalidate so next chat sees the change
add_action('wpilot_after_tool', 'wpilot_invalidate_blueprint');

// Missing hooks found by audit
add_action('wp_create_nav_menu', 'wpilot_invalidate_blueprint');
add_action('woocommerce_trash_product', 'wpilot_invalidate_blueprint');
add_action('edit_attachment', 'wpilot_invalidate_blueprint');
add_action('update_option_woocommerce_myaccount_page_id', 'wpilot_invalidate_blueprint');
add_action('update_option_woocommerce_coming_soon', 'wpilot_invalidate_blueprint');
add_action('created_term', 'wpilot_invalidate_blueprint');
add_action('edited_term', 'wpilot_invalidate_blueprint');
