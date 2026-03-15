<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Build site context for a given scope ───────────────────────
function wpilot_build_context( $scope = 'general' ) {
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
            $ctx['images']   = wpilot_ctx_images( 40 );
            $ctx['seo']      = wpilot_ctx_seo_summary();
            $ctx['css']      = substr( wp_get_custom_css(), 0, 3000 );
            $ctx['performance'] = wpilot_ctx_performance();
            // If user is on a specific page, include its full content
            $current_pid = isset($_POST['context']) ? json_decode(wp_unslash($_POST['context']), true) : [];
            $current_id = intval($current_pid['post_id'] ?? 0);
            if ($current_id > 0) {
                $current_post = get_post($current_id);
                if ($current_post) {
                    $ctx['current_page_content'] = [
                        'id' => $current_id,
                        'title' => $current_post->post_title,
                        'content' => substr($current_post->post_content, 0, 5000),
                        'content_length' => strlen($current_post->post_content),
                        'custom_css_for_page' => get_post_meta($current_id, '_ca_custom_css', true) ?: '',
                    ];
                }
            }
            if ( class_exists( 'WooCommerce' ) ) $ctx['woocommerce'] = wpilot_ctx_woo();
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

    return [
        'name'           => $t->get( 'Name' ),
        'version'        => $t->get( 'Version' ),
        'author'         => $t->get( 'Author' ),
        'parent'         => $t->get( 'Template' ) ?: null,
        'is_child_theme' => $t->get( 'Template' ) ? true : false,
        'supports'       => $supports,
        'custom_css_lines' => $custom_css_lines,
        'custom_css_bytes' => strlen($custom_css),
        'has_header_php' => file_exists($theme_dir . '/header.php'),
        'has_footer_php' => file_exists($theme_dir . '/footer.php'),
        'has_functions'  => file_exists($theme_dir . '/functions.php'),
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
// Built by Christos Ferlachidis & Daniel Hedenberg
        $info = [
            'name'              => $p['Name'],
            'version'           => $p['Version'],
            'slug'              => $slug,
            'category'          => $kb['cat']     ?? 'General',
            'pricing'           => $kb['pricing']  ?? 'Unknown',
            'free_alternative'  => $kb['free_alt'] ?? null,
            'wpilot_can_configure' => $kb['wpilot_can_configure'] ?? false,
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
        'wpilot_configurable' => array_values(array_filter($active_list, fn($p) => $p['wpilot_can_configure'])),
    ];
}

function wpilot_ctx_pages( $limit = 50 ) {
    $pages = get_pages( ['number' => $limit, 'sort_column' => 'menu_order'] );
    return array_map( function( $p ) {
        $content = $p->post_content;
        $word_count = str_word_count(wp_strip_all_tags($content));

        // Detect builder data
        $builder_type = 'none';
        if (strpos($content, '[et_pb_') !== false) $builder_type = 'divi';
        elseif (get_post_meta($p->ID, '_elementor_data', true)) $builder_type = 'elementor';
        elseif (strpos($content, '<!-- wp:') !== false) $builder_type = 'gutenberg';

        // Get content summary (first 500 chars of clean text + HTML structure hints)
        $clean_text = wp_trim_words(wp_strip_all_tags($content), 80, '...');

        // Find images in content without dimensions
        $imgs_no_dims = 0;
        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            foreach ($matches[0] as $img) {
                if (strpos($img, 'width=') === false || strpos($img, 'height=') === false) {
                    $imgs_no_dims++;
                }
            }
        }

        // Divi-specific: detect modules
        $divi_modules = [];
        if ($builder_type === 'divi') {
            preg_match_all('/\[et_pb_(\w+)/i', $content, $divi_m);
            if (!empty($divi_m[1])) {
                $divi_modules = array_count_values($divi_m[1]);
            }
        }

        return [
            'id'             => $p->ID,
            'title'          => $p->post_title,
            'slug'           => $p->post_name,
            'status'         => $p->post_status,
            'template'       => get_post_meta($p->ID, '_wp_page_template', true) ?: 'default',
            'content_preview'=> $clean_text,
            'word_count'     => $word_count,
            'has_meta_desc'  => wpilot_has_meta_desc($p->ID),
            'builder'        => $builder_type,
            'images_in_content' => substr_count(strtolower($content), '<img'),
            'images_no_dimensions' => $imgs_no_dims,
            'has_slider'     => strpos($content, 'slider') !== false || strpos($content, 'et_pb_slider') !== false || strpos($content, 'carousel') !== false,
            'has_video'      => strpos($content, '<video') !== false || strpos($content, 'youtube') !== false || strpos($content, 'vimeo') !== false,
            'divi_modules'   => $divi_modules,
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
    $products  = wc_get_products( ['limit' => 30, 'status' => 'publish'] );
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
