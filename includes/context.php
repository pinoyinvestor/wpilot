<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Build site context for a given scope ───────────────────────
function wpilot_build_context( $scope = 'general' ) {
    // Use cached blueprint for fast context — no heavy DB queries
    if (function_exists('wpilot_get_blueprint')) {
        $bp = wpilot_get_blueprint();
        if ($bp && $scope === 'general') {
            // Ultra-fast: return compressed blueprint directly
            $ctx = ['blueprint' => wpilot_compress_blueprint($bp)];
            // Add only dynamic data that changes frequently
            if (class_exists('WooCommerce')) {
                $ctx['blueprint']['orders']['today'] = count(wc_get_orders(['date_created' => '>' . date('Y-m-d 00:00:00'), 'limit' => 500, 'return' => 'ids']));
            }
            return $ctx;
        }
    }
    // Fallback: build context the old way
    $ctx = [
        'site'    => wpilot_ctx_site(),
        'theme'   => wpilot_ctx_theme(),
        'builder' => wpilot_detect_builder(),
        'plugins' => wpilot_ctx_plugins(),
    ];

    switch ( $scope ) {
        case 'full':
        case 'analyze':
            $ctx['pages']    = wpilot_ctx_pages();
            $ctx['posts']    = wpilot_ctx_posts();
            $ctx['menus']    = wpilot_ctx_menus();
            $ctx['images']   = wpilot_ctx_images( 20 );
            $ctx['seo']      = wpilot_ctx_seo_summary();
            $ctx['css']      = substr( wp_get_custom_css(), 0, 800 );
            $ctx['performance'] = wpilot_ctx_performance();
            $ctx['plugin_configs'] = function_exists('wpilot_ctx_plugin_configs') ? wpilot_ctx_plugin_configs() : [];
            // If user is on a specific page, include its full content
            $current_pid = isset($_POST['context']) ? json_decode(wp_unslash($_POST['context']), true) : [];
            $current_id = intval($current_pid['post_id'] ?? 0);
            if ($current_id > 0) {
                $current_post = get_post($current_id);
                if ($current_post) {
                    $raw_content = $current_post->post_content;

                    // For Elementor pages, get the rendered CSS too
                    $el_css = '';
                    if (get_post_meta($current_id, '_elementor_edit_mode', true) === 'builder') {
                        $el_css = get_post_meta($current_id, '_elementor_css', true) ?: '';
                        if (is_array($el_css)) $el_css = '';
                    }

                    // For Divi pages, extract module settings
                    $divi_settings = [];
                    if (strpos($raw_content, '[et_pb_') !== false) {
                        // Extract key Divi settings like colors, fonts from shortcode attrs
                        if (preg_match_all('/background_color="([^"]+)"/', $raw_content, $bg_m)) {
                            $divi_settings['background_colors'] = array_unique($bg_m[1]);
                        }
                        if (preg_match_all('/text_font_size="([^"]+)"/', $raw_content, $fs_m)) {
                            $divi_settings['font_sizes'] = array_unique($fs_m[1]);
                        }
                    }

                    // Get builder content for current page
                    $current_builder_html = '';
                    if (get_post_meta($current_id, '_elementor_edit_mode', true) === 'builder') {
                        $el_data_raw = get_post_meta($current_id, '_elementor_data', true);
                        $el_json_raw = json_decode($el_data_raw, true);
                        if (is_array($el_json_raw)) {
                            $current_builder_html = wpilot_extract_builder_html($el_json_raw);
                        }
                    } elseif (strpos($raw_content, '[et_pb_') !== false) {
                        if (preg_match_all('/\[et_pb_(?:code|text|fullwidth_code)[^\]]*\](.*?)\[\/et_pb_/s', $raw_content, $divi_matches)) {
                            $current_builder_html = implode(' ', $divi_matches[1]);
                        }
                    }

                    $ctx['current_page_content'] = [
                        'id' => $current_id,
                        'title' => $current_post->post_title,
                        'raw_content' => substr($raw_content, 0, 3000),
                        'content_length' => strlen($raw_content),
                        'custom_css' => get_post_meta($current_id, '_ca_custom_css', true) ?: '',
                        'elementor_css' => substr($el_css, 0, 500),
                        'divi_settings' => $divi_settings,
                        'builder_html' => substr($current_builder_html, 0, 5000),
                        'builder_html_length' => strlen($current_builder_html),
                        'post_meta_keys' => array_keys(get_post_meta($current_id)),
                    ];
                }
            }
            $ctx['file_map'] = wpilot_ctx_file_map();
            if ( class_exists( 'WooCommerce' ) ) { $ctx['woocommerce'] = wpilot_ctx_woo(); $ctx['product_map'] = wpilot_ctx_product_map(); $ctx['business'] = wpilot_ctx_business_stats(); }
            break;

        case 'seo':
            $ctx['pages'] = wpilot_ctx_pages();
            $ctx['posts'] = wpilot_ctx_posts( 30 );
            $ctx['seo']   = wpilot_ctx_seo_summary();
            break;

        case 'build':
            $ctx['pages']  = wpilot_ctx_pages();
            $ctx['menus']  = wpilot_ctx_menus();
            $ctx['images'] = wpilot_ctx_images( 25 );
            $ctx['css']    = substr( wp_get_custom_css(), 0, 2000 );
            break;

        case 'woo':
            $ctx['woocommerce'] = wpilot_ctx_woo();
            $ctx['pages']       = wpilot_ctx_pages( 10 );
            break;

        case 'media':
            $ctx['images'] = wpilot_ctx_images( 60 );
            break;

        case 'plugins':
            // already included
            break;

        default: // general / chat
            $ctx['pages'] = wpilot_ctx_pages( 12 );
            $ctx['posts'] = wpilot_ctx_posts( 6 );
            $ctx['menus'] = wpilot_ctx_menus();
            $ctx['file_map'] = wpilot_ctx_file_map_lite();
            if ( class_exists( 'WooCommerce' ) ) { $ctx['product_map'] = wpilot_ctx_product_map(); }
            break;
    }

    return $ctx;
}

// ── Individual context readers ─────────────────────────────────
function wpilot_ctx_site() {
    return [
        'name'        => get_bloginfo( 'name' ),
        'tagline'     => get_bloginfo( 'description' ),
        'url'         => get_site_url(),
        'wp_version'  => get_bloginfo( 'version' ),
        'php_version' => PHP_VERSION,
        'language'    => get_locale(),
        'front_page'  => get_option( 'show_on_front' ),
        'front_id'    => (int) get_option( 'page_on_front' ),
        'blog_id'     => (int) get_option( 'page_for_posts' ),
    ];
}

function wpilot_ctx_theme() {
    $t = wp_get_theme();
    $theme_dir = $t->get_stylesheet_directory();

    // Check what the theme supports
    $supports = [];
    foreach (['custom-logo','post-thumbnails','title-tag','html5','responsive-embeds','wide-align','editor-styles'] as $feature) {
        if (current_theme_supports($feature)) $supports[] = $feature;
    }

    // Get custom CSS size
    $custom_css = wp_get_custom_css();
    $custom_css_lines = $custom_css ? substr_count($custom_css, "\n") + 1 : 0;

    // Read theme files (truncated for context size)
    $header_php = file_exists($theme_dir . '/header.php') ? substr(file_get_contents($theme_dir . '/header.php'), 0, 200) : '';
    $footer_php = file_exists($theme_dir . '/footer.php') ? substr(file_get_contents($theme_dir . '/footer.php'), 0, 200) : '';
    $functions_php = file_exists($theme_dir . '/functions.php') ? substr(file_get_contents($theme_dir . '/functions.php'), 0, 300) : '';

    return [
        'name'           => $t->get( 'Name' ),
        'version'        => $t->get( 'Version' ),
        'author'         => $t->get( 'Author' ),
        'parent'         => $t->get( 'Template' ) ?: null,
        'is_child_theme' => $t->get( 'Template' ) ? true : false,
        'supports'       => $supports,
        'custom_css_bytes' => strlen($custom_css),
        // header_php removed — Claude knows WordPress themes
        // footer_php removed
        // functions_php removed
    ];
}

function wpilot_ctx_plugins() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // WPilot plugin knowledge: category, free/paid, configurability
    $plugin_kb = [
        // Booking
        'amelia'            => ['cat'=>'Booking',       'pricing'=>'Paid $49+/yr',    'free_alt'=>'Simply Schedule Appointments', 'wpilot_can_configure'=>true],
        'bookly'            => ['cat'=>'Booking',       'pricing'=>'Free / Pro $89',  'free_alt'=>null,                           'wpilot_can_configure'=>true],
        'simply-schedule-appointments'=>['cat'=>'Booking','pricing'=>'Free/Pro',      'free_alt'=>null,                           'wpilot_can_configure'=>false],
        // Ecommerce
        'woocommerce'       => ['cat'=>'Ecommerce',     'pricing'=>'Free',            'free_alt'=>null,                           'wpilot_can_configure'=>true],
        'woocommerce-payments'=>['cat'=>'Ecommerce',    'pricing'=>'Free (2.9%+30¢)','free_alt'=>null,                            'wpilot_can_configure'=>true],
        'cartflows'         => ['cat'=>'Checkout',      'pricing'=>'Free / Pro $99+/yr','free_alt'=>null,                         'wpilot_can_configure'=>false],
        // Forms
        'gravityforms'      => ['cat'=>'Forms',         'pricing'=>'Paid $59+/yr',    'free_alt'=>'WPForms (free)',                'wpilot_can_configure'=>true],
        'wpforms'           => ['cat'=>'Forms',         'pricing'=>'Free / Pro $49+/yr','free_alt'=>null,                         'wpilot_can_configure'=>true],
        'contact-form-7'    => ['cat'=>'Forms',         'pricing'=>'Free',            'free_alt'=>null,                           'wpilot_can_configure'=>false],
        'ninja-forms'       => ['cat'=>'Forms',         'pricing'=>'Free / Pro',      'free_alt'=>null,                           'wpilot_can_configure'=>false],
        // SEO
        'wordpress-seo'     => ['cat'=>'SEO',           'pricing'=>'Free / Premium $99/yr','free_alt'=>'Rank Math (free)',         'wpilot_can_configure'=>true],
        'seo-by-rank-math'  => ['cat'=>'SEO',           'pricing'=>'Free / Pro $59/yr','free_alt'=>null,                          'wpilot_can_configure'=>true],
        // LMS
        'sfwd-lms'          => ['cat'=>'LMS',           'pricing'=>'Paid $199+/yr',   'free_alt'=>'LifterLMS (free tier)',         'wpilot_can_configure'=>true],
        'lifterlms'         => ['cat'=>'LMS',           'pricing'=>'Free / Add-ons',  'free_alt'=>null,                           'wpilot_can_configure'=>false],
        'tutor-lms'         => ['cat'=>'LMS',           'pricing'=>'Free / Pro $149/yr','free_alt'=>null,                         'wpilot_can_configure'=>false],
        // Membership
        'memberpress'       => ['cat'=>'Membership',    'pricing'=>'Paid $179+/yr',   'free_alt'=>'Paid Memberships Pro (free)',   'wpilot_can_configure'=>true],
        'paid-memberships-pro'=>['cat'=>'Membership',   'pricing'=>'Free / Plus $247/yr','free_alt'=>null,                        'wpilot_can_configure'=>false],
        // Events
        'the-events-calendar'=>['cat'=>'Events',        'pricing'=>'Free',            'free_alt'=>null,                           'wpilot_can_configure'=>true],
        // Page builders
        'elementor'         => ['cat'=>'Page Builder',  'pricing'=>'Free / Pro $59+/yr','free_alt'=>null,                         'wpilot_can_configure'=>false],
        'beaver-builder'    => ['cat'=>'Page Builder',  'pricing'=>'Paid $99+/yr',    'free_alt'=>'Elementor (free)',              'wpilot_can_configure'=>false],
        'bricks'            => ['cat'=>'Page Builder',  'pricing'=>'Paid $79/yr',     'free_alt'=>'Elementor (free)',              'wpilot_can_configure'=>false],
        'oxygen'            => ['cat'=>'Page Builder',  'pricing'=>'Paid $129',       'free_alt'=>'Elementor (free)',              'wpilot_can_configure'=>false],
        // Performance
        'wp-rocket'         => ['cat'=>'Performance',   'pricing'=>'Paid $59+/yr',    'free_alt'=>'LiteSpeed Cache (free)',        'wpilot_can_configure'=>false],
        'litespeed-cache'   => ['cat'=>'Performance',   'pricing'=>'Free',            'free_alt'=>null,                           'wpilot_can_configure'=>false],
        'w3-total-cache'    => ['cat'=>'Performance',   'pricing'=>'Free / Pro $99/yr','free_alt'=>null,                          'wpilot_can_configure'=>false],
        'autoptimize'       => ['cat'=>'Performance',   'pricing'=>'Free',            'free_alt'=>null,                           'wpilot_can_configure'=>false],
        // Security
        'wordfence'         => ['cat'=>'Security',      'pricing'=>'Free / Premium $99/yr','free_alt'=>null,                      'wpilot_can_configure'=>false],
        'really-simple-ssl' => ['cat'=>'Security',      'pricing'=>'Free / Pro',      'free_alt'=>null,                           'wpilot_can_configure'=>false],
        // Backup
        'updraftplus'       => ['cat'=>'Backup',        'pricing'=>'Free / Premium $70+','free_alt'=>null,                        'wpilot_can_configure'=>false],
        // Email marketing
        'mailchimp-for-wp'  => ['cat'=>'Email',         'pricing'=>'Free / Pro $59/yr','free_alt'=>null,                          'wpilot_can_configure'=>false],
        'klaviyo-for-woocommerce'=>['cat'=>'Email',     'pricing'=>'Free (usage-based)','free_alt'=>null,                         'wpilot_can_configure'=>false],
        // Media
        'smush'             => ['cat'=>'Media',         'pricing'=>'Free / Pro $72/yr','free_alt'=>'Squirrly SEO (free)',          'wpilot_can_configure'=>false],
        'imagify'           => ['cat'=>'Media',         'pricing'=>'Free / Paid',     'free_alt'=>null,                           'wpilot_can_configure'=>false],
        // Social
        'smashballoon-social-post-feed'=>['cat'=>'Social','pricing'=>'Free / Pro $49/yr','free_alt'=>null,                        'wpilot_can_configure'=>false],
        // Analytics
        'google-analytics-for-wordpress'=>['cat'=>'Analytics','pricing'=>'Free',     'free_alt'=>null,                            'wpilot_can_configure'=>false],
        // Multilingual
        'polylang'          => ['cat'=>'Multilingual',  'pricing'=>'Free / Pro $99/yr','free_alt'=>null,                          'wpilot_can_configure'=>false],
        'wpml'              => ['cat'=>'Multilingual',  'pricing'=>'Paid $39+/yr',    'free_alt'=>'Polylang (free)',               'wpilot_can_configure'=>false],
        // Custom fields
        'advanced-custom-fields'=>['cat'=>'Custom Fields','pricing'=>'Free / Pro $49/yr','free_alt'=>null,                        'wpilot_can_configure'=>false],
    ];

    $all       = get_plugins();
    $active    = get_option( 'active_plugins', [] );
    $inactive  = [];
    $active_list = [];

    foreach ( $all as $file => $p ) {
        $slug = explode('/',$file)[0];
        $kb   = $plugin_kb[$slug] ?? null;
// Built by Weblease
        $info = [
            'name'              => $p['Name'],
            'version'           => $p['Version'],
            'slug'              => $slug,
            'category'          => $kb['cat']     ?? 'General',
            // pricing removed — Claude knows plugin pricing
            // free_alt removed
            // wpilot_can_configure removed
        ];
        if ( in_array($file, $active) ) {
            $active_list[] = $info;
        } else {
            $inactive[] = $info;
        }
    }

    // Group active plugins by category for AI clarity
    $by_category = [];
    foreach ($active_list as $p) {
        $by_category[$p['category']][] = $p['name'];
    }

    // Detect what is MISSING that could help this site
    $has_seo      = isset($by_category['SEO']);
    $has_backup   = isset($by_category['Backup']);
    $has_security = isset($by_category['Security']);
    $has_perf     = isset($by_category['Performance']);
    $has_forms    = isset($by_category['Forms']);
    $has_booking  = isset($by_category['Booking']);
    $has_email    = isset($by_category['Email']);

    $missing = [];
    if (!$has_seo)      $missing[] = 'SEO plugin (Rank Math — free, highly recommended)';
    if (!$has_backup)   $missing[] = 'Backup plugin (UpdraftPlus — free version is enough for most sites)';
    if (!$has_security) $missing[] = 'Security plugin (Wordfence — free version protects against most attacks)';
    if (!$has_perf)     $missing[] = 'Performance/cache plugin (LiteSpeed Cache or W3 Total Cache — both free)';
    if (!$has_forms)    $missing[] = 'Contact form plugin (WPForms or Contact Form 7 — both free)';

    return [
        'active_count'   => count($active_list),
        'inactive_count' => count($inactive),
        'total_installed'=> count($all),
        'active_plugins' => $active_list,
        'inactive_plugins'=> array_slice($inactive, 0, 10),
        'by_category'    => $by_category,
        'missing_essentials' => $missing,
        'wpilot_configurable' => array_values(array_filter($active_list, fn($p) => !empty($p['wpilot_can_configure']))),
    ];
}

function wpilot_ctx_pages( $limit = 20 ) {
    $pages = get_pages( ['number' => $limit, 'sort_column' => 'menu_order'] );
    return array_map( function( $p ) {
        $content = $p->post_content;
        $word_count = str_word_count(wp_strip_all_tags($content));

        // Detect builder data
        $builder_type = 'none';
        if (strpos($content, '[et_pb_') !== false) $builder_type = 'divi';
        elseif (get_post_meta($p->ID, '_elementor_data', true)) $builder_type = 'elementor';
        elseif (strpos($content, '<!-- wp:') !== false) $builder_type = 'gutenberg';

        // Get content from builder data (Elementor stores in meta, not post_content)
        $builder_html = '';
        if ($builder_type === 'elementor') {
            $el_data = get_post_meta($p->ID, '_elementor_data', true);
            if ($el_data) {
                $el_json = json_decode($el_data, true);
                if (is_array($el_json)) {
                    $builder_html = wpilot_extract_builder_html($el_json);
                }
            }
        } elseif ($builder_type === 'divi') {
            // Extract content from Divi shortcodes
            if (preg_match_all('/\[et_pb_(?:code|text|fullwidth_code)[^\]]*\](.*?)\[\/et_pb_/s', $content, $dm)) {
                $builder_html = implode(' ', $dm[1]);
            }
        }
        // Use builder content if post_content is empty
        $text_source = !empty($builder_html) ? $builder_html : $content;

        // Get content summary (first 500 chars of clean text + HTML structure hints)
        $clean_text = wp_trim_words(wp_strip_all_tags($text_source), 80, '...');

        // Find images in content without dimensions
        $imgs_no_dims = 0;
        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            foreach ($matches[0] as $img) {
                if (strpos($img, 'width=') === false || strpos($img, 'height=') === false) {
                    $imgs_no_dims++;
                }
            }
        }

        // Builder-specific data
        $divi_modules = [];
        $elementor_widgets = [];
        if ($builder_type === 'divi') {
            preg_match_all('/\[et_pb_(\w+)/i', $content, $divi_m);
            if (!empty($divi_m[1])) {
                $divi_modules = array_count_values($divi_m[1]);
            }
        }
        if ($builder_type === 'elementor') {
            $el_data = get_post_meta($p->ID, '_elementor_data', true);
            if ($el_data) {
                $el_json = json_decode($el_data, true);
                if (is_array($el_json)) {
                    $elementor_widgets = wpilot_count_elementor_widgets($el_json);
                }
            }
        }

        return [
            'id'             => $p->ID,
            'title'          => $p->post_title,
            'slug'           => $p->post_name,
            'status'         => $p->post_status,
            'template'       => get_post_meta($p->ID, '_wp_page_template', true) ?: 'default',
            'content_preview'=> $clean_text,
            'raw_content'    => substr($text_source, 0, 200),
            'builder_content_length' => strlen($builder_html),
            'word_count'     => $word_count,
            'has_meta_desc'  => wpilot_has_meta_desc($p->ID),
            'builder'        => $builder_type,
            'images_in_content' => substr_count(strtolower($content), '<img'),
            'images_no_dimensions' => $imgs_no_dims,
            // has_slider removed
            // has_video removed
            'divi_modules'   => $divi_modules,
            'elementor_widgets' => $elementor_widgets,
            'content_length' => strlen($content),
        ];
    }, $pages );
}

function wpilot_ctx_posts( $limit = 20 ) {
    $posts = get_posts( ['numberposts' => $limit, 'post_status' => 'publish'] );
    return array_map( fn( $p ) => [
        'id'           => $p->ID,
        'title'        => $p->post_title,
        'slug'         => $p->post_name,
        'date'         => $p->post_date,
        'excerpt'      => wp_trim_words( wp_strip_all_tags( $p->post_content ), 18 ),
        'has_meta_desc'=> wpilot_has_meta_desc( $p->ID ),
    ], $posts );
}

function wpilot_ctx_menus() {
    $menus     = wp_get_nav_menus();
    $locations = get_nav_menu_locations();
    $result    = [];
    foreach ( $menus as $menu ) {
        $items    = wp_get_nav_menu_items( $menu->term_id ) ?: [];
        $result[] = [
            'id'       => $menu->term_id,
            'name'     => $menu->name,
            'location' => array_search( $menu->term_id, $locations ) ?: 'unassigned',
            'items'    => array_map( fn($i) => [
                'title'  => $i->title,
                'url'    => $i->url,
                'parent' => (int)$i->menu_item_parent,
            ], $items ),
        ];
    }
    return $result;
}

function wpilot_ctx_images( $limit = 30 ) {
    $images = get_posts( ['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => $limit] );
    return array_map( function( $img ) {
        $alt  = get_post_meta( $img->ID, '_wp_attachment_image_alt', true );
        $meta = wp_get_attachment_metadata( $img->ID );
        $file = get_attached_file( $img->ID );
        $size = $file && file_exists($file) ? filesize($file) : 0;
        return [
            'id'          => $img->ID,
            'title'       => $img->post_title,
            'url'         => wp_get_attachment_url( $img->ID ),
            'alt'         => $alt ?: '',
            'missing_alt' => empty( $alt ),
            'width'       => $meta['width']  ?? null,
            'height'      => $meta['height'] ?? null,
            'mime'        => $img->post_mime_type,
            'format'      => pathinfo( $file ?: '', PATHINFO_EXTENSION ),
            'size_kb'     => round( $size / 1024 ),
            'is_webp'     => $img->post_mime_type === 'image/webp',
        ];
    }, $images );
}

function wpilot_ctx_seo_summary() {
    $pages = wpilot_ctx_pages( 60 );
    $posts = wpilot_ctx_posts( 60 );
    return [
        'seo_plugin'              => wpilot_detect_seo_plugin(),
        'pages_missing_meta'      => count( array_filter( $pages, fn($p) => ! $p['has_meta_desc'] ) ),
        'posts_missing_meta'      => count( array_filter( $posts, fn($p) => ! $p['has_meta_desc'] ) ),
        'site_name'               => get_bloginfo( 'name' ),
        'site_description'        => get_bloginfo( 'description' ),
    ];
}

function wpilot_ctx_woo() {
    if ( ! class_exists( 'WooCommerce' ) ) return [];
    $products  = wc_get_products( ['limit' => 20, 'status' => 'publish'] );
    $prod_data = array_map( fn($p) => [
        'id'        => $p->get_id(),
        'name'      => $p->get_name(),
        'price'     => $p->get_price(),
        'stock'     => $p->get_stock_quantity(),
        'has_desc'  => strlen( $p->get_description() ) > 20,
        'has_image' => (bool) $p->get_image_id(),
        'cat_ids'   => $p->get_category_ids(),
    ], $products );

    $cats = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => false] );
    return [
        'product_count' => count( $prod_data ),
        'products'      => $prod_data,
        'categories'    => array_map( fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'count' => $c->count], $cats ),
    ];
}

function wpilot_has_meta_desc( $post_id ) {
    return ! empty( get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) )
        || ! empty( get_post_meta( $post_id, '_aioseo_description',   true ) )
        || ! empty( get_post_meta( $post_id, 'rank_math_description',  true ) );
}

function wpilot_detect_seo_plugin() {
    if ( defined( 'WPSEO_VERSION' ) )         return 'Yoast SEO';
    if ( class_exists( 'RankMath' ) )         return 'Rank Math';
    if ( class_exists( 'AIOSEO_Plugin' ) )    return 'All in One SEO';
    return 'None detected';
}


function wpilot_ctx_plugin_configs() {
    $configs = [];

    // Divi settings
    if (defined('ET_BUILDER_VERSION')) {
        $divi_opts = get_option('et_divi', []);
        $configs['divi'] = [
            'version' => ET_BUILDER_VERSION,
            'accent_color' => $divi_opts['accent_color'] ?? '',
            'body_font' => $divi_opts['body_font'] ?? '',
            'header_font' => $divi_opts['header_font'] ?? '',
            'body_font_size' => $divi_opts['body_font_size'] ?? '',
            'logo' => $divi_opts['divi_logo'] ?? '',
        ];
    }

    // Elementor settings
    if (defined('ELEMENTOR_VERSION')) {
        $configs['elementor'] = [
            'version' => ELEMENTOR_VERSION,
            'default_generic_fonts' => get_option('elementor_default_generic_fonts', ''),
            'container_width' => get_option('elementor_container_width', 1140),
            'space_between_widgets' => get_option('elementor_space_between_widgets', 20),
        ];
    }

    // SEO plugin settings
    if (defined('WPSEO_VERSION')) {
        $configs['yoast'] = [
            'version' => WPSEO_VERSION,
            'titles' => get_option('wpseo_titles', []),
        ];
    }
    if (class_exists('RankMath')) {
        $configs['rankmath'] = [
            'general' => get_option('rank-math-options-general', []),
        ];
    }

    // WooCommerce settings
    if (class_exists('WooCommerce')) {
        $configs['woocommerce'] = [
            'version' => WC()->version ?? '',
            'currency' => get_woocommerce_currency(),
            'store_address' => get_option('woocommerce_store_address', ''),
            'store_city' => get_option('woocommerce_store_city', ''),
            'store_country' => get_option('woocommerce_default_country', ''),
            'calc_taxes' => get_option('woocommerce_calc_taxes', 'no'),
            'enable_reviews' => get_option('woocommerce_enable_reviews', 'yes'),
        ];
    }

    return $configs;
}

// Count Elementor widgets recursively
function wpilot_count_elementor_widgets($elements) {
    $counts = [];
    foreach ($elements as $el) {
        if (isset($el['widgetType'])) {
            $type = $el['widgetType'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        if (!empty($el['elements'])) {
            $sub = wpilot_count_elementor_widgets($el['elements']);
            foreach ($sub as $k => $v) $counts[$k] = ($counts[$k] ?? 0) + $v;
        }
    }
    return $counts;
}

function wpilot_ctx_performance() {
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>-1]);
    $total_size = 0;
    $jpeg_count = 0; $png_count = 0; $webp_count = 0; $other_count = 0;
    $large_images = [];
    foreach ($images as $img) {
        $file = get_attached_file($img->ID);
        $size = $file && file_exists($file) ? filesize($file) : 0;
        $total_size += $size;
        $mime = $img->post_mime_type;
        if ($mime === 'image/jpeg') $jpeg_count++;
        elseif ($mime === 'image/png') $png_count++;
        elseif ($mime === 'image/webp') $webp_count++;
        else $other_count++;
        if ($size > 200 * 1024) {
            $large_images[] = ['id'=>$img->ID, 'title'=>$img->post_title, 'size_kb'=>round($size/1024)];
        }
    }

    // Check cache
    $has_cache = false;
    $cache_plugin = 'None';
    foreach (get_option('active_plugins', []) as $p) {
        if (preg_match('/litespeed|wp-rocket|w3-total-cache|wp-super-cache|wp-fastest-cache/i', $p)) {
            $has_cache = true;
            $cache_plugin = explode('/', $p)[0];
            break;
        }
    }

    return [
        'total_images'    => count($images),
        'total_size_mb'   => round($total_size / 1048576, 1),
        'jpeg_count'      => $jpeg_count,
        'png_count'       => $png_count,
        'webp_count'      => $webp_count,
        'not_webp'        => $jpeg_count + $png_count,
        'large_images'    => array_slice($large_images, 0, 10),
        'has_cache'       => $has_cache,
        'cache_plugin'    => $cache_plugin,
        'php_version'     => PHP_VERSION,
        'memory_limit'    => ini_get('memory_limit'),
        'max_execution'   => ini_get('max_execution_time'),
        'active_plugins'  => count(get_option('active_plugins', [])),
    ];
}



// Extract readable HTML from Elementor JSON data
function wpilot_extract_builder_html($elements) {
    $html = '';
    foreach ($elements as $el) {
        if (isset($el['widgetType'])) {
            $settings = $el['settings'] ?? [];
            switch ($el['widgetType']) {
                case 'html':
                    $html .= $settings['html'] ?? '';
                    break;
                case 'heading':
                    $html .= '<h2>' . ($settings['title'] ?? '') . '</h2>';
                    break;
                case 'text-editor':
                    $html .= $settings['editor'] ?? '';
                    break;
                case 'button':
                    $html .= '<a href="' . ($settings['link']['url'] ?? '#') . '">' . ($settings['text'] ?? 'Button') . '</a> ';
                    break;
                case 'image':
                    $html .= '<img src="' . ($settings['image']['url'] ?? '') . '"> ';
                    break;
                case 'icon-box':
                    $html .= '<div>' . ($settings['title_text'] ?? '') . ': ' . ($settings['description_text'] ?? '') . '</div>';
                    break;
            }
        }
        if (!empty($el['elements'])) {
            $html .= wpilot_extract_builder_html($el['elements']);
        }
    }
    return $html;
}

// Lite file map for chat mode — just essentials (~150 tokens)
function wpilot_ctx_file_map_lite() {
    $map = [];

    // Theme files (just names, no sizes)
    $theme_dir = get_stylesheet_directory();
    $files = [];
    foreach (@scandir($theme_dir) ?: [] as $f) {
        if ($f === "." || $f === "..") continue;
        if (is_dir($theme_dir . "/" . $f)) $files[] = $f . "/";
        elseif (preg_match("/\.(php|css|js|json)$/", $f)) $files[] = $f;
    }
    $map["theme"] = implode(", ", $files);

    // mu-plugins count
    $mu_count = 0;
    if (is_dir(WPMU_PLUGIN_DIR)) {
        $mu_count = count(array_filter(@scandir(WPMU_PLUGIN_DIR) ?: [], fn($f) => str_ends_with($f, ".php")));
    }
    $map["mu_plugins"] = $mu_count;

    // Key config
    $map["debug"] = defined("WP_DEBUG") && WP_DEBUG;
    $map["cache"] = defined("WP_CACHE") && WP_CACHE;
    $map["memory"] = defined("WP_MEMORY_LIMIT") ? WP_MEMORY_LIMIT : ini_get("memory_limit");

    // Available templates
    $templates = array_keys(wp_get_theme()->get_page_templates());
    $map["templates"] = $templates;

    // Custom post types (non-builtin)
    $cpt = [];
    foreach (get_post_types(["_builtin" => false, "public" => true], "objects") as $pt) {
        $count = wp_count_posts($pt->name);
        $cpt[] = $pt->name . ":" . ($count->publish ?? 0);
    }
    // Built by Weblease
    $map["post_types"] = implode(", ", $cpt);

    return $map;
}



// Smart business context — auto-detects site type and provides relevant stats
function wpilot_ctx_business_stats() {
    $stats = [];

    // ═══ WOOCOMMERCE ORDERS & REVENUE ═══
    if (class_exists('WooCommerce')) {
        // Today
        $today_orders = wc_get_orders(['date_created' => '>' . date('Y-m-d 00:00:00'), 'limit' => 500, 'return' => 'ids']);
        $today_rev = 0;
        foreach ($today_orders as $oid) { $o = wc_get_order($oid); if ($o) $today_rev += $o->get_total(); }

        // This week
        $week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $week_orders = wc_get_orders(['date_created' => '>' . $week_start, 'limit' => 500, 'return' => 'ids']);
        $week_rev = 0;
        foreach ($week_orders as $oid) { $o = wc_get_order($oid); if ($o) $week_rev += $o->get_total(); }

        // This month
        $month_start = date('Y-m-01 00:00:00');
        $month_orders = wc_get_orders(['date_created' => '>' . $month_start, 'limit' => 500, 'return' => 'ids']);
        $month_rev = 0;
        foreach ($month_orders as $oid) { $o = wc_get_order($oid); if ($o) $month_rev += $o->get_total(); }

        // All time
        $all_orders = wc_get_orders(['limit' => 500, 'return' => 'ids', 'status' => ['completed','processing']]);
        $all_rev = 0;
        foreach (array_slice($all_orders, 0, 500) as $oid) { $o = wc_get_order($oid); if ($o) $all_rev += $o->get_total(); }

        // Order statuses
        $status_counts = [];
        foreach (['pending','processing','on-hold','completed','cancelled','refunded','failed'] as $s) {
            $c = count(wc_get_orders(['status' => $s, 'limit' => 500, 'return' => 'ids']));
            if ($c > 0) $status_counts[$s] = $c;
        }

        // Best sellers (top 5)
        $best = [];
        $product_sales = [];
        foreach (array_slice($all_orders, 0, 200) as $oid) {
            $o = wc_get_order($oid);
            if (!$o) continue;
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                $product_sales[$pid] = ($product_sales[$pid] ?? 0) + $item->get_quantity();
            }
        }
        arsort($product_sales);
        foreach (array_slice($product_sales, 0, 5, true) as $pid => $qty) {
            $p = wc_get_product($pid);
            if ($p) $best[] = $p->get_name() . ' (' . $qty . ' sold)';
        }

        // Average order value
        $avg = count($all_orders) > 0 ? round($all_rev / count($all_orders)) : 0;

        $currency = get_woocommerce_currency();
        $stats['orders'] = [
            'today' => ['count' => count($today_orders), 'revenue' => $today_rev . ' ' . $currency],
            'this_week' => ['count' => count($week_orders), 'revenue' => $week_rev . ' ' . $currency],
            'this_month' => ['count' => count($month_orders), 'revenue' => $month_rev . ' ' . $currency],
            'all_time' => ['count' => count($all_orders), 'revenue' => $all_rev . ' ' . $currency],
            'avg_order_value' => $avg . ' ' . $currency,
            'by_status' => $status_counts,
            'best_sellers' => $best,
        ];

        // Low stock alerts
        $low_stock = [];
        $prods = wc_get_products(['limit' => 100, 'stock_status' => 'instock']);
        foreach ($prods as $p) {
            $stock = $p->get_stock_quantity();
            if ($stock !== null && $stock <= 5) {
                $low_stock[] = $p->get_name() . ' (' . $stock . ' left)';
            }
        }
        if ($low_stock) $stats['low_stock_alerts'] = $low_stock;

        // Recent reviews
        $reviews = get_comments(['post_type' => 'product', 'number' => 5, 'status' => 'approve']);
        if ($reviews) {
            $stats['recent_reviews'] = array_map(fn($r) => [
                'product' => get_the_title($r->comment_post_ID),
                'rating' => get_comment_meta($r->comment_ID, 'rating', true),
                'text' => wp_trim_words($r->comment_content, 10),
            ], $reviews);
        }
    }

    // ═══ BOOKINGS (Amelia, Bookly, Simply Schedule) ═══
    // Built by Weblease
    if (defined('FLAVOR') || class_exists('\\AmeliaBooking\\Application\\Services\\Booking\\BookingApplicationService')) {
        // Amelia
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tbl = $prefix . 'amelia_appointments';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") === $tbl) {
            $today_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE DATE(bookingStart) = CURDATE() AND status != 'canceled'");
            $week_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE YEARWEEK(bookingStart) = YEARWEEK(CURDATE()) AND status != 'canceled'");
            $month_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE MONTH(bookingStart) = MONTH(CURDATE()) AND YEAR(bookingStart) = YEAR(CURDATE()) AND status != 'canceled'");
            $upcoming = $wpdb->get_results("SELECT bookingStart, status FROM {$tbl} WHERE bookingStart >= NOW() AND status != 'canceled' ORDER BY bookingStart LIMIT 5");

            $stats['bookings'] = [
                'plugin' => 'Amelia',
                'today' => (int)$today_bookings,
                'this_week' => (int)$week_bookings,
                'this_month' => (int)$month_bookings,
                'upcoming' => array_map(fn($b) => $b->bookingStart . ' (' . $b->status . ')', $upcoming),
            ];

            // Services
            $services_tbl = $prefix . 'amelia_services';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$services_tbl}'") === $services_tbl) {
                $services = $wpdb->get_results("SELECT name, price, duration FROM {$services_tbl} WHERE status = 'visible'");
                $stats['bookings']['services'] = array_map(fn($s) => $s->name . ' (' . $s->price . ', ' . round($s->duration/60) . 'min)', $services);
            }
        }
    }

    if (class_exists('BooklyLib\\Plugin') || defined('FLAVOR_BOOKLY')) {
        // Bookly
        global $wpdb;
        $tbl = $wpdb->prefix . 'bookly_appointments';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") === $tbl) {
            $today_b = $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE DATE(start_date) = CURDATE()");
            $month_b = $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE MONTH(start_date) = MONTH(CURDATE()) AND YEAR(start_date) = YEAR(CURDATE())");
            $stats['bookings'] = [
                'plugin' => 'Bookly',
                'today' => (int)$today_b,
                'this_month' => (int)$month_b,
            ];
        }
    }

    // ═══ LEARNDASH (LMS) ═══
    if (defined('LEARNDASH_VERSION')) {
        $courses = get_posts(['post_type' => 'sfwd-courses', 'numberposts' => 100, 'post_status' => 'publish']);
        $total_enrollments = 0;
        $course_list = [];
        foreach ($courses as $c) {
            $enrolled = count(learndash_get_users_for_course($c->ID, ['fields' => 'ID'], false) ?: []);
            $total_enrollments += $enrolled;
            $course_list[] = $c->post_title . ' (' . $enrolled . ' students)';
        }
        $stats['lms'] = [
            'plugin' => 'LearnDash',
            'courses' => count($courses),
            'total_enrollments' => $total_enrollments,
            'course_list' => $course_list,
        ];
    }

    // ═══ MEMBERSHIP (MemberPress, Paid Memberships Pro) ═══
    if (defined('MEPR_VERSION')) {
        $memberships = get_posts(['post_type' => 'memberpressproduct', 'numberposts' => 100]);
        $stats['membership'] = [
            'plugin' => 'MemberPress',
            'plans' => count($memberships),
            'plan_names' => array_map(fn($m) => $m->post_title, $memberships),
        ];
    }

    // ═══ CONTACT FORM SUBMISSIONS ═══
    if (defined('WPCF7_VERSION')) {
        // Count CF7 forms
        $forms = get_posts(['post_type' => 'wpcf7_contact_form', 'numberposts' => 100]);
        $stats['forms'] = ['plugin' => 'Contact Form 7', 'count' => count($forms), 'names' => array_map(fn($f) => $f->post_title, $forms)];
    }
    if (class_exists('WPForms')) {
        $forms = get_posts(['post_type' => 'wpforms', 'numberposts' => 100]);
        $stats['forms'] = ['plugin' => 'WPForms', 'count' => count($forms)];
    }

    // ═══ SITE TYPE DETECTION ═══
    $site_type = 'website';
    if (!empty($stats['orders'])) $site_type = 'e-commerce';
    if (!empty($stats['bookings'])) $site_type = 'booking';
    if (!empty($stats['lms'])) $site_type = 'lms';
    if (!empty($stats['membership'])) $site_type = 'membership';
    $stats['site_type'] = $site_type;

    return !empty($stats) ? $stats : null;
}

// Compact product map — all WooCommerce data in minimal tokens
function wpilot_ctx_product_map() {
    if (!class_exists('WooCommerce')) return null;

    $map = [];

    // Products summary
    $products = wc_get_products(['limit' => 50, 'status' => 'publish']);
    $prod_list = [];
    foreach ($products as $p) {
        $cats = wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']);
        $img_id = $p->get_image_id();
        $prod_list[] = [
            'id' => $p->get_id(),
            'name' => $p->get_name(),
            'price' => $p->get_price(),
            'sale' => $p->get_sale_price() ?: null,
            'sku' => $p->get_sku() ?: null,
            'stock' => $p->get_stock_status(),
            'type' => $p->get_type(),
            'cats' => implode(', ', $cats),
            'has_img' => !empty($img_id),
            'img_url' => $img_id ? wp_get_attachment_url($img_id) : null,
            'has_desc' => strlen($p->get_description()) > 20,
            'short_desc' => wp_trim_words(strip_tags($p->get_short_description() ?: $p->get_description()), 8, '...'),
        ];
    }
    $map['products'] = $prod_list;

    // Categories
    $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    $map['categories'] = array_map(fn($c) => $c->name . ' (' . $c->count . ')', is_array($cats) ? $cats : []);

    // Coupons
    $coupons = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 10]);
    $map['coupons'] = array_map(function($c) {
        $type = get_post_meta($c->ID, 'discount_type', true);
        $amount = get_post_meta($c->ID, 'coupon_amount', true);
        return $c->post_title . ' (' . $amount . ($type === 'percent' ? '%' : ' off') . ')';
    }, $coupons);

    // Store config
    $map['config'] = [
        'currency' => get_woocommerce_currency(),
        'country' => get_option('woocommerce_default_country'),
        'taxes' => get_option('woocommerce_calc_taxes', 'no'),
        'shipping' => !empty(WC()->shipping()->get_shipping_methods()),
        // Built by Weblease
        'payments' => array_keys(array_filter(WC()->payment_gateways()->get_available_payment_gateways())),
        'checkout_page' => get_option('woocommerce_checkout_page_id'),
        'cart_page' => get_option('woocommerce_cart_page_id'),
        'coming_soon' => get_option('woocommerce_coming_soon', 'no'),
    ];

    // Recent orders summary
    $orders = wc_get_orders(['limit' => 5, 'orderby' => 'date', 'order' => 'DESC']);
    $map['recent_orders'] = count($orders);
    if (!empty($orders)) {
        $total = 0;
        foreach ($orders as $o) $total += $o->get_total();
        $map['orders_total'] = $total;
    }

    // Pages WooCommerce uses
    $map['pages'] = [
        'shop' => get_option('woocommerce_shop_page_id'),
        'cart' => get_option('woocommerce_cart_page_id'),
        'checkout' => get_option('woocommerce_checkout_page_id'),
        'myaccount' => get_option('woocommerce_myaccount_page_id'),
        'terms' => get_option('woocommerce_terms_page_id'),
    ];

    return $map;
}

// ── Compact file map (full version) — FileZilla-like view for AI (minimal tokens) ──
function wpilot_ctx_file_map() {
    $map = [];
    
    // 1. Theme file structure
    $theme_dir = get_stylesheet_directory();
    $theme_files = [];
    $scan = function($dir, $prefix = '') use (&$scan, &$theme_files, $theme_dir) {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if (!$items) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            $rel = $prefix . $item;
            if (is_dir($path)) {
                // Count files in subdirectory
                $count = count(array_filter(@scandir($path) ?: [], fn($f) => $f !== '.' && $f !== '..' && !is_dir($path.'/'.$f)));
                $theme_files[] = $rel . '/ (' . $count . ' files)';
            } else {
                $ext = pathinfo($item, PATHINFO_EXTENSION);
                $size = filesize($path);
                if (in_array($ext, ['php','css','js','json','html'])) {
                    $theme_files[] = $rel . ' (' . round($size/1024, 1) . 'kb)';
                }
            }
        }
    };
    $scan($theme_dir);
    $map['theme_files'] = $theme_files;
    
    // 2. Uploads structure (folders + file counts)
    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    $upload_folders = [];
    if (is_dir($base)) {
        $dirs = @scandir($base);
        if ($dirs) {
            foreach ($dirs as $d) {
                if ($d === '.' || $d === '..' || !is_dir($base . '/' . $d)) continue;
                // Count all files recursively
                $count = 0;
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/' . $d, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iter as $file) { if ($file->isFile()) $count++; }
                $upload_folders[] = $d . '/ (' . $count . ' files)';
            }
        }
    }
    $map['uploads'] = $upload_folders;
    
    // 3. mu-plugins (code injections)
    $mu_dir = WPMU_PLUGIN_DIR;
    $mu_plugins = [];
    if (is_dir($mu_dir)) {
        foreach (@scandir($mu_dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !str_ends_with($f, '.php')) continue;
            $content = file_get_contents($mu_dir . '/' . $f);
            $first_line = strtok($content, "\n");
            $mu_plugins[] = $f . ' — ' . trim(str_replace(['<?php','//','/*','*/'], '', $first_line));
        }
    }
    // Built by Weblease
    $map['mu_plugins'] = $mu_plugins;
    
    // 4. Key wp-config settings (no secrets!)
    $map['wp_config'] = [
        'WP_DEBUG' => defined('WP_DEBUG') ? WP_DEBUG : false,
        'WP_CACHE' => defined('WP_CACHE') ? WP_CACHE : false,
        'DISALLOW_FILE_EDIT' => defined('DISALLOW_FILE_EDIT') ? DISALLOW_FILE_EDIT : false,
        'WP_MEMORY_LIMIT' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : ini_get('memory_limit'),
        'DB_CHARSET' => defined('DB_CHARSET') ? DB_CHARSET : 'unknown',
        'table_prefix' => $GLOBALS['table_prefix'] ?? 'wp_',
    ];
    
    // 5. .htaccess exists?
    $map['htaccess'] = file_exists(ABSPATH . '.htaccess') ? 'exists (' . round(filesize(ABSPATH . '.htaccess')/1024, 1) . 'kb)' : 'missing';
    
    // 6. Available page templates
    $templates = wp_get_theme()->get_page_templates();
    $map['page_templates'] = array_merge(['default' => 'Default'], $templates);
    
    // 7. Registered post types (custom)
    $custom_types = [];
    foreach (get_post_types(['_builtin' => false, 'public' => true], 'objects') as $pt) {
        $count = wp_count_posts($pt->name);
        $custom_types[] = $pt->name . ' (' . ($count->publish ?? 0) . ' published)';
    }
    $map['custom_post_types'] = $custom_types;
    
    // 8. Registered shortcodes
    global $shortcode_tags;
    $map['shortcodes'] = array_keys($shortcode_tags ?? []);
    
    // 9. Active widgets/sidebars
    $sidebars = wp_get_sidebars_widgets();
    $sidebar_summary = [];
    foreach ($sidebars as $id => $widgets) {
        if ($id === 'wp_inactive_widgets' || !is_array($widgets)) continue;
        $sidebar_summary[$id] = count($widgets) . ' widgets';
    }
    $map['sidebars'] = $sidebar_summary;
    
    // 10. Cron jobs (scheduled tasks)
    $crons = _get_cron_array();
    $cron_hooks = [];
    if ($crons) {
        foreach ($crons as $time => $hooks) {
            foreach ($hooks as $hook => $data) {
                if (strpos($hook, 'wp_') === 0 || strpos($hook, 'woocommerce_') === 0) continue; // skip core
                $cron_hooks[] = $hook;
            }
        }
    }
    $map['custom_crons'] = array_unique($cron_hooks);
    
    return $map;
}
