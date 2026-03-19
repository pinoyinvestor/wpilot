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


// ── Cache busting — auto-clear after every change ──
function wpilot_bust_cache() {
    // Bust WordPress object cache
    wp_cache_flush();
    // Bust LiteSpeed Cache
    if ( class_exists('LiteSpeed_Cache_API') ) {
        LiteSpeed_Cache_API::purge_all();
    }
    // Bust WP Super Cache
    if ( function_exists('wp_cache_clear_cache') ) {
        wp_cache_clear_cache();
    }
    // Bust W3 Total Cache
    if ( function_exists('w3tc_flush_all') ) {
        w3tc_flush_all();
    }
    // Bust browser cache by updating a version timestamp
    update_option('wpilot_cache_ver', time(), false);
}

// Add cache version to all enqueued styles/scripts
add_filter("style_loader_tag", function($html, $handle) {
    if (strpos($handle, "wpilot") === false && strpos($handle, "ca-") === false) return $html;
    $ver = get_option("wpilot_cache_ver", "1");
    return str_replace(".css?", ".css?wv={$ver}&", str_replace(".css'", ".css?wv={$ver}'", $html));
}, 99, 2);

// ── Core helpers — wrapped with function_exists to avoid conflicts ──
if ( ! function_exists( 'wpilot_is_connected' ) ) {
    function wpilot_is_connected() { return !empty(get_option("ca_api_key","")) || !empty(get_option("wpilot_mcp_api_key_hash","")); }
}
if ( ! function_exists( 'wpilot_theme' ) ) {
    function wpilot_theme() { return get_option( 'wpilot_theme', 'dark' ); }
}
if ( ! function_exists( 'wpilot_auto_approve' ) ) {
    function wpilot_auto_approve() { return get_option( 'wpilot_auto_approve', 'no' ) === 'yes'; }
}
if ( ! function_exists( 'wpilot_detect_builder' ) ) {
    function wpilot_detect_builder() {
        if ( defined( 'ELEMENTOR_VERSION' ) )  return 'elementor';
        if ( defined( 'ET_BUILDER_VERSION' ) || defined('ET_DB_VERSION') ) return 'divi';
        if ( defined( 'FL_BUILDER_VERSION' ) ) return 'beaver';
        if ( defined( 'BRICKS_VERSION' ) )     return 'bricks';
        if ( defined( 'OXYGEN_VSB_VERSION' ) ) return 'oxygen';
        return 'gutenberg';
    }
}
if ( ! function_exists( 'wpilot_ok' ) ) {
    function wpilot_ok( $msg, $extra = [] ) { return array_merge( ['success' => true, 'message' => $msg], $extra ); }
}
if ( ! function_exists( 'wpilot_err' ) ) {
    function wpilot_err( $msg ) { return ['success' => false, 'message' => $msg]; }
}

// ── SEO Frontend Output (lightweight — always loaded for all visitors) ──
// Built by Weblease

// Apply custom robots.txt if configured
add_filter( 'robots_txt', function( $output, $public ) {
    $custom = get_option( 'wpi_custom_robots_txt', '' );
    if ( ! empty( $custom ) ) return $custom;
    return $output;
}, 10, 2 );

// Output custom head code injected by WPilot tools
add_action( 'wp_head', function() {
    $head = get_option( 'wpilot_head_code', '' );
    if ( $head ) echo $head . "\n";
}, 5 );

// Output custom footer scripts injected by WPilot tools
add_action( 'wp_footer', function() {
    $scripts = get_option( 'wpilot_footer_scripts', '' );
    if ( $scripts ) echo $scripts . "\n";
}, 99 );

// Output schema markup, Open Graph and Twitter Card tags in <head>
add_action( 'wp_head', function() {
    if ( ! is_singular() ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) return;

    // JSON-LD Schema markup
    $schema = get_post_meta( $post_id, '_wpi_schema_markup', true );
    if ( $schema ) {
        // SECURITY: Validate JSON and re-encode to prevent XSS injection
        $schema_decoded = json_decode($schema, true);
        if (is_array($schema_decoded)) {
            echo '<script type="application/ld+json">' . wp_json_encode($schema_decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }
    }

    // Open Graph — required tags always output, custom overrides applied
    $og_title = get_post_meta( $post_id, '_wpi_og_title', true )
             ?: get_the_title( $post_id );
    $og_desc  = get_post_meta( $post_id, '_wpi_og_description', true )
             ?: get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
             ?: get_post_meta( $post_id, 'rank_math_description', true )
             ?: get_post_meta( $post_id, '_aioseo_description', true );
    $og_image = get_post_meta( $post_id, '_wpi_og_image', true );

    // Fallback OG image: featured image
    if ( ! $og_image ) {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) $og_image = wp_get_attachment_url( $thumb_id );
    }

    // og:type — product for WooCommerce, article for posts, website for pages
    $post_type = get_post_type( $post_id );
    if ( $post_type === 'product' ) {
        $og_type = 'product';
    } elseif ( $post_type === 'post' ) {
        $og_type = 'article';
    } else {
        $og_type = 'website';
    }

    echo '<meta property="og:type" content="'      . esc_attr( $og_type )                    . '">' . "\n";
    echo '<meta property="og:url" content="'       . esc_url( get_permalink( $post_id ) )    . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) )      . '">' . "\n";
    if ( $og_title ) echo '<meta property="og:title" content="'       . esc_attr( $og_title ) . '">' . "\n";
    if ( $og_desc  ) echo '<meta property="og:description" content="' . esc_attr( $og_desc  ) . '">' . "\n";
    if ( $og_image ) echo '<meta property="og:image" content="'       . esc_url( $og_image  ) . '">' . "\n";

    // Twitter Card
    echo '<meta name="twitter:card" content="' . ( $og_image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
    if ( $og_title ) echo '<meta name="twitter:title" content="'       . esc_attr( $og_title ) . '">' . "\n";
    if ( $og_desc  ) echo '<meta name="twitter:description" content="' . esc_attr( $og_desc  ) . '">' . "\n";
    if ( $og_image ) echo '<meta name="twitter:image" content="'       . esc_url( $og_image  ) . '">' . "\n";
} );
if ( ! function_exists( 'wpilot_md_to_html' ) ) {
    function wpilot_md_to_html( $text ) {
        $text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $text = preg_replace( '/\*\*(.*?)\*\*/',    '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*(.*?)\*/',        '<em>$1</em>',         $text );
        $text = preg_replace( '/^#{1,3}\s+(.+)$/m', '<div class="ca-md-h">$1</div>', $text );
        $text = preg_replace( '/^[-•]\s+(.+)$/m',   '<li>$1</li>', $text );
        $text = preg_replace( '/(<li>.*?<\/li>(\n)?)+/s', '<ul class="ca-md-ul">$0</ul>', $text );
        $text = preg_replace( '/`([^`]+)`/', '<code class="ca-md-code">$1</code>', $text );
        return nl2br( $text );
    }
}

// Admin notice when not connected
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( wpilot_is_connected() ) return;
    $screen = get_current_screen();
    if ( $screen && strpos( $screen->id, CA_SLUG ) !== false ) return;
    ?>
    <div class="notice notice-info is-dismissible" style="border-left-color:#5B8DEF;padding:14px 16px">
        <p style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:13.5px">
            <strong style="font-size:14px">⚡ WPilot <span style="font-weight:400;color:#888">powered by Claude AI</span></strong>
            <span style="color:#777">—</span>
            <span>Connect your Claude account to start building your site with AI.</span>
            <a href="<?= esc_url( admin_url( 'admin.php?page=' . CA_SLUG . '-settings' ) ) ?>" style="font-weight:700;color:#5B8DEF;white-space:nowrap">⚙️ Connect now</a>
        </p>
    </div>
    <?php
} );

// ── Role check: who can use WPilot ────────────────────────
function wpilot_can_use() {
    $type = wpilot_license_type();
    if ( $type === 'lifetime' ) return true;
    if ( in_array($type, ['pro','team','agency']) ) {
        $cached = get_transient('wpi_license_valid');
        if ( $cached === false ) {
            $cached = wpilot_remote_validate_license();
            set_transient('wpi_license_valid', $cached ? 1 : 0, HOUR_IN_SECONDS);
        }
        return (bool)$cached;
    }
    return wpilot_prompts_used() < CA_FREE_LIMIT;
}

function wpilot_remote_validate_license() {
    $key = get_option('ca_license_key','');
    if ( !$key ) return false;
    $resp = wp_remote_post( WPI_LICENSE_VALIDATE_URL, [
        'timeout' => 8,
        'body'    => ['license_key'=>$key,'site_url'=>get_site_url(),'plugin_version'=>CA_VERSION],
    ]);
    if ( is_wp_error($resp) ) return false;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ( !empty($data['warning']) ) {
        update_option('wpi_license_warning', sanitize_text_field($data['warning']));
        add_action('admin_notices', function() {
            $w = get_option('wpi_license_warning','');
            if ($w) echo '<div class="notice notice-warning is-dismissible"><p>⚠️ WPilot: '.esc_html($w).'</p></div>';
        });
    }
    return !empty($data['valid']);
}

// ── WPilot Access Control ──────────────────────────────────
// Admins can grant WPilot access to any role
function wpilot_allowed_roles() {
    return get_option( 'wpilot_allowed_roles', ['administrator', 'editor'] );
}

function wpilot_user_has_access() {
    if ( ! is_user_logged_in() ) return false;
    $user  = wp_get_current_user();
    $allowed = wpilot_allowed_roles();
    foreach ( $user->roles as $role ) {
        if ( in_array( $role, $allowed ) ) return true;
    }
    return false;
}

// Who can modify site (tools that change things) vs who can only chat
function wpilot_user_can_modify() {
    // Only admins and editors can execute tools that modify the site
    return current_user_can( 'edit_pages' );
}

// Who can manage WPilot settings (grant access, change settings)
function wpilot_user_can_manage() {
    return current_user_can( 'manage_options' );
}

// ── Admin bar notification ─────────────────────────────────────
add_action('admin_bar_menu', function($bar) {
    if ( !is_admin() ) return;
    if ( !current_user_can('manage_options') ) return;
    $connected = wpilot_is_connected();
    $icon      = $connected ? '⚡' : '⚠️';
    $url       = admin_url('admin.php?page='.CA_SLUG.($connected ? '' : '-mcp'));
    $color     = $connected ? '#10b981' : '#f59e0b';
    $bar->add_node([
        'id'    => 'aib-status',
        'title' => '<span style="color:'.$color.'">'.$icon.' AI</span>',
        'href'  => $url,
        'meta'  => ['title' => $connected ? 'WPilot ready' : 'WPilot — connect Claude'],
    ]);
    if ($connected) {
        $bar->add_node(['parent'=>'aib-status','id'=>'aib-status-chat','title'=>'💬 Open AI Chat','href'=>admin_url('admin.php?page='.CA_SLUG.'-chat')]);
        $bar->add_node(['parent'=>'aib-status','id'=>'aib-status-brain','title'=>'🧠 WPilot Brain','href'=>admin_url('admin.php?page='.CA_SLUG.'-brain')]);
    } else {
        $bar->add_node(['parent'=>'aib-status','id'=>'aib-status-setup','title'=>'🔑 Connect Claude →','href'=>admin_url('admin.php?page='.CA_SLUG.'-settings')]);
    }
}, 100);
