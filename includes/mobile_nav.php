<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPilot Mobile Navigation System
//  Enhanced hamburger, full-screen overlay, bottom bar, sticky header
//  All injected via mu-plugin — works with any theme
// ═══════════════════════════════════════════════════════════════

/**
 * Get current mobile nav configuration
 */
function wpilot_mobile_nav_config() {
    return get_option( 'wpilot_mobile_nav', [
        'enabled'   => false,
        'style'     => 'squeeze',    // squeeze | spin | arrow
        'bottom_bar'=> false,
        'auto_hide' => true,
        'items'     => [],           // bottom bar items override
        'socials'   => [],           // social links for overlay
    ]);
}

/**
 * Build WordPress menu tree (up to 3 levels)
 */
function wpilot_get_menu_tree() {
    $locations = get_nav_menu_locations();
    $menu_id   = 0;

    // Try primary, main, header — common names across themes
    foreach ( ['primary', 'main', 'header', 'menu-1', 'primary-menu'] as $loc ) {
        if ( ! empty( $locations[ $loc ] ) ) {
            $menu_id = $locations[ $loc ];
            break;
        }
    }

    // Fallback: first registered menu
    if ( ! $menu_id ) {
        $menus = wp_get_nav_menus();
        if ( ! empty( $menus ) ) $menu_id = $menus[0]->term_id;
    }

    if ( ! $menu_id ) return [];

    $items = wp_get_nav_menu_items( $menu_id );
    if ( ! $items ) return [];

    // Build parent→children map
    $tree     = [];
    $by_id    = [];
    $children = [];

    foreach ( $items as $item ) {
        $entry = [
            'id'       => $item->ID,
            'title'    => $item->title,
            'url'      => $item->url,
            'parent'   => intval( $item->menu_item_parent ),
            'children' => [],
        ];
        $by_id[ $item->ID ] = $entry;
        if ( $entry['parent'] ) {
            $children[ $entry['parent'] ][] = $item->ID;
        }
    }

    // Attach children (3 levels max)
    foreach ( $children as $parent_id => $child_ids ) {
        if ( isset( $by_id[ $parent_id ] ) ) {
            foreach ( $child_ids as $cid ) {
                $by_id[ $parent_id ]['children'][] = &$by_id[ $cid ];
            }
        }
    }

    // Top-level items only
    foreach ( $by_id as &$item ) {
        if ( $item['parent'] === 0 ) {
            $tree[] = $item;
        }
    }

    return $tree;
}

/**
 * Get WooCommerce cart count (0 if WooCommerce not active)
 */
function wpilot_woo_cart_count() {
    if ( function_exists( 'WC' ) && WC()->cart ) {
        return WC()->cart->get_cart_contents_count();
    }
    return 0;
}

// ─────────────────────────────────────────────────────────────
//  Generate the complete mu-plugin code
// ─────────────────────────────────────────────────────────────

function wpilot_generate_mobile_nav_mu() {
    $conf = wpilot_mobile_nav_config();
    if ( empty( $conf['enabled'] ) ) return '';

    $style     = sanitize_text_field( $conf['style'] ?? 'squeeze' );
    $bottom    = ! empty( $conf['bottom_bar'] );
    $auto_hide = ! empty( $conf['auto_hide'] );
    $items     = $conf['items'] ?? [];
    $socials   = $conf['socials'] ?? [];

    // ── Build menu JSON for JS ──
    $menu_tree = wpilot_get_menu_tree();
    $menu_json = wp_json_encode( $menu_tree );

    // ── Default bottom bar items ──
    if ( empty( $items ) ) {
        $items = [
            [ 'icon' => 'home',    'label' => 'Home',    'url' => home_url( '/' ) ],
            [ 'icon' => 'search',  'label' => 'Search',  'url' => '#wpilot-mn-search' ],
            [ 'icon' => 'cart',    'label' => 'Cart',    'url' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#' ],
            [ 'icon' => 'user',    'label' => 'Account', 'url' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ],
        ];
        // Insert Shop if WooCommerce active
        if ( class_exists( 'WooCommerce' ) ) {
            array_splice( $items, 1, 0, [[ 'icon' => 'shop', 'label' => 'Shop', 'url' => wc_get_page_permalink( 'shop' ) ]] );
        }
    }

    $items_json   = wp_json_encode( $items );
    $socials_json = wp_json_encode( $socials );
    $cart_count   = wpilot_woo_cart_count();
    $has_woo      = class_exists( 'WooCommerce' ) ? 'true' : 'false';
    $ajax_url     = admin_url( 'admin-ajax.php' );
    $search_url   = home_url( '/' );

    // ── CSS ──
    $css = wpilot_mobile_nav_css( $style, $bottom, $auto_hide );

    // ── JS ──
    $js = wpilot_mobile_nav_js( $style, $bottom, $auto_hide, $has_woo, $ajax_url, $search_url );

    // ── HTML skeleton ──
    $html = wpilot_mobile_nav_html( $menu_tree, $socials, $cart_count );

    // ── Bottom bar HTML ──
    $bottom_html = $bottom ? wpilot_bottom_bar_html( $items, $cart_count ) : '';

    // Combine into mu-plugin
    $mu_code  = "<?php\n";
    $mu_code .= "// WPilot Mobile Navigation System\n";
    $mu_code .= "if ( ! defined( 'ABSPATH' ) ) exit;\n\n";
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $mu_code .= "add_action('wp_head', function() {\n";
    $mu_code .= "    if (is_admin()) return;\n";
    $mu_code .= "    echo '<style>" . wpilot_escape_mu_string( $css ) . "</style>';\n";
    $mu_code .= "}, 999);\n\n";
    $mu_code .= "add_action('wp_footer', function() {\n";
    $mu_code .= "    if (is_admin()) return;\n";
    $mu_code .= "    echo '" . wpilot_escape_mu_string( $html ) . "';\n";
    if ( $bottom ) {
        $mu_code .= "    echo '" . wpilot_escape_mu_string( $bottom_html ) . "';\n";
    }
    $mu_code .= "    echo '<script>" . wpilot_escape_mu_string( $js ) . "</script>';\n";
    $mu_code .= "}, 99);\n";

    // WooCommerce cart fragment support
    if ( class_exists( 'WooCommerce' ) ) {
        $mu_code .= "\nadd_filter('woocommerce_add_to_cart_fragments', function(\$fragments) {\n";
        $mu_code .= "    \$count = WC()->cart->get_cart_contents_count();\n";
        $mu_code .= "    \$fragments['.wpilot-mn-cart-badge'] = '<span class=\"wpilot-mn-cart-badge\"' . (\$count ? '' : ' style=\"display:none\"') . '>' . \$count . '</span>';\n";
        $mu_code .= "    return \$fragments;\n";
        $mu_code .= "});\n";
    }

    return $mu_code;
}

/**
 * Escape string for embedding inside single-quoted PHP echo
 */
function wpilot_escape_mu_string( $str ) {
    $str = str_replace( "\\", "\\\\", $str );
    $str = str_replace( "'", "\\'", $str );
    $str = preg_replace( '/\n\s*/', ' ', $str ); // collapse whitespace
    return $str;
}


// ═══════════════════════════════════════════════════════════════
//  CSS Generation
// ═══════════════════════════════════════════════════════════════

function wpilot_mobile_nav_css( $style, $bottom, $auto_hide ) {
    $css = '
/* ── WPilot Mobile Nav Reset ── */
:root {
    --wpmn-primary: var(--wp-primary, #6366f1);
    --wpmn-bg: var(--wp-bg, #ffffff);
    --wpmn-text: var(--wp-text, #1a1a2e);
    --wpmn-overlay-bg: var(--wp-bg, #ffffff);
    --wpmn-radius: 12px;
    --wpmn-transition: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    --wpmn-header-h: 60px;
    --wpmn-bottom-h: 64px;
}

/* ── Hamburger Button ── */
.wpilot-hamburger {
    display: none;
    position: relative;
    width: 28px;
    height: 22px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    z-index: 100002;
    -webkit-tap-highlight-color: transparent;
}
@media (max-width: 767px) {
    .wpilot-hamburger { display: flex; flex-direction: column; justify-content: space-between; align-items: stretch; }
}
.wpilot-hamburger span {
    display: block;
    height: 2.5px;
    background: var(--wpmn-text);
    border-radius: 2px;
    transition: var(--wpmn-transition);
    transform-origin: center;
}';

    // ── Hamburger animation styles ──
    if ( $style === 'squeeze' ) {
        $css .= '
.wpilot-hamburger.active span:nth-child(1) { transform: translateY(9.75px) rotate(45deg); }
.wpilot-hamburger.active span:nth-child(2) { opacity: 0; transform: scaleX(0); }
.wpilot-hamburger.active span:nth-child(3) { transform: translateY(-9.75px) rotate(-45deg); }';
    } elseif ( $style === 'spin' ) {
        $css .= '
.wpilot-hamburger.active { transform: rotate(180deg); }
.wpilot-hamburger.active span:nth-child(1) { transform: translateY(9.75px) rotate(45deg); }
.wpilot-hamburger.active span:nth-child(2) { opacity: 0; }
.wpilot-hamburger.active span:nth-child(3) { transform: translateY(-9.75px) rotate(-45deg); }';
    } elseif ( $style === 'arrow' ) {
        $css .= '
.wpilot-hamburger.active span:nth-child(1) { transform: translateY(4px) rotate(40deg) scaleX(0.55); transform-origin: left center; }
.wpilot-hamburger.active span:nth-child(2) { transform: scaleX(0.85); }
.wpilot-hamburger.active span:nth-child(3) { transform: translateY(-4px) rotate(-40deg) scaleX(0.55); transform-origin: left center; }';
    }

    // ── Overlay ──
    $css .= '
.wpilot-mn-overlay {
    position: fixed;
    top: 0; right: 0; bottom: 0; left: 0;
    z-index: 100001;
    background: var(--wpmn-overlay-bg);
    transform: translateX(100%);
    transition: transform var(--wpmn-transition);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    display: none;
}
@media (max-width: 767px) {
    .wpilot-mn-overlay { display: block; }
}
.wpilot-mn-overlay.open {
    transform: translateX(0);
}
.wpilot-mn-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 100000;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    opacity: 0;
    pointer-events: none;
    transition: opacity var(--wpmn-transition);
}
.wpilot-mn-backdrop.open {
    opacity: 1;
    pointer-events: auto;
}

/* ── Search bar ── */
.wpilot-mn-search {
    padding: 16px 20px 8px;
}
.wpilot-mn-search form {
    display: flex;
    gap: 8px;
}
.wpilot-mn-search input {
    flex: 1;
    padding: 12px 16px;
    border: 1.5px solid rgba(128,128,128,0.2);
    border-radius: var(--wpmn-radius);
    background: rgba(128,128,128,0.06);
    color: var(--wpmn-text);
    font-size: 15px;
    outline: none;
    transition: border-color 0.2s;
}
.wpilot-mn-search input:focus {
    border-color: var(--wpmn-primary);
}
.wpilot-mn-search button {
    padding: 12px;
    border: none;
    background: var(--wpmn-primary);
    color: #fff;
    border-radius: var(--wpmn-radius);
    cursor: pointer;
    display: flex;
    align-items: center;
}

/* ── Menu Items ── */
.wpilot-mn-list {
    list-style: none;
    margin: 0;
    padding: 8px 0;
}
.wpilot-mn-list a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 24px;
    color: var(--wpmn-text);
    text-decoration: none;
    font-size: 16px;
    font-weight: 500;
    transition: background 0.15s;
    border: none;
}
.wpilot-mn-list a:hover,
.wpilot-mn-list a:active {
    background: rgba(128,128,128,0.07);
}
.wpilot-mn-chevron {
    width: 18px;
    height: 18px;
    transition: transform 0.25s ease;
    opacity: 0.4;
    flex-shrink: 0;
}
.wpilot-mn-item.open > a .wpilot-mn-chevron {
    transform: rotate(180deg);
}

/* Level 2 */
.wpilot-mn-sub {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.35s ease;
}
.wpilot-mn-item.open > .wpilot-mn-sub {
    max-height: 1000px;
}
.wpilot-mn-sub a {
    padding-left: 44px;
    font-size: 15px;
    font-weight: 400;
    color: var(--wpmn-text);
    opacity: 0.8;
}

/* Level 3 */
.wpilot-mn-sub .wpilot-mn-sub a {
    padding-left: 64px;
    font-size: 14px;
    opacity: 0.65;
}

/* ── Socials ── */
.wpilot-mn-socials {
    display: flex;
    gap: 16px;
    justify-content: center;
    padding: 24px 20px;
    border-top: 1px solid rgba(128,128,128,0.12);
    margin-top: auto;
}
.wpilot-mn-socials a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(128,128,128,0.08);
    color: var(--wpmn-text);
    text-decoration: none;
    transition: background 0.2s, color 0.2s;
}
.wpilot-mn-socials a:hover {
    background: var(--wpmn-primary);
    color: #fff;
}';

    // ── Sticky Header ──
    if ( $auto_hide ) {
        $css .= '
@media (max-width: 767px) {
    .wpilot-mn-sticky-wrap {
        position: fixed;
        top: 0; left: 0; right: 0;
        z-index: 100002;
        background: var(--wpmn-bg);
        transition: transform 0.3s ease, box-shadow 0.3s ease, padding 0.3s ease;
        box-shadow: 0 1px 8px rgba(0,0,0,0.06);
    }
    .wpilot-mn-sticky-wrap.scrolled {
        box-shadow: 0 2px 16px rgba(0,0,0,0.1);
    }
    .wpilot-mn-sticky-wrap.hidden {
        transform: translateY(-100%);
    }
    .wpilot-mn-sticky-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 20px;
        transition: padding 0.3s ease;
    }
    .wpilot-mn-sticky-wrap.scrolled .wpilot-mn-sticky-inner {
        padding: 8px 20px;
    }
    body { padding-top: var(--wpmn-header-h) !important; }
}';
    }

    // ── Bottom Bar ──
    if ( $bottom ) {
        $css .= '
@media (max-width: 767px) {
    .wpilot-mn-bottombar {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        z-index: 99999;
        background: var(--wpmn-bg);
        border-top: 1px solid rgba(128,128,128,0.1);
        box-shadow: 0 -2px 16px rgba(0,0,0,0.06);
        display: flex;
        align-items: center;
        justify-content: space-around;
        height: var(--wpmn-bottom-h);
        padding-bottom: env(safe-area-inset-bottom, 0);
        transition: transform 0.3s ease;
    }
    .wpilot-mn-bottombar.hidden {
        transform: translateY(100%);
    }
    .wpilot-mn-bb-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 2px;
        text-decoration: none;
        color: var(--wpmn-text);
        opacity: 0.55;
        font-size: 10px;
        font-weight: 500;
        position: relative;
        padding: 6px 12px;
        -webkit-tap-highlight-color: transparent;
        transition: opacity 0.2s;
    }
    .wpilot-mn-bb-item.active,
    .wpilot-mn-bb-item:active {
        opacity: 1;
        color: var(--wpmn-primary);
    }
    .wpilot-mn-bb-item svg {
        width: 22px;
        height: 22px;
    }
    .wpilot-mn-cart-badge {
        position: absolute;
        top: 0;
        right: 4px;
        min-width: 16px;
        height: 16px;
        background: var(--wpmn-primary);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        border-radius: 99px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        line-height: 1;
    }
    body { padding-bottom: var(--wpmn-bottom-h) !important; }
}
@media (min-width: 768px) {
    .wpilot-mn-bottombar { display: none !important; }
}';
    }

    // ── Desktop hide ──
    $css .= '
@media (min-width: 768px) {
    .wpilot-mn-overlay,
    .wpilot-mn-backdrop,
    .wpilot-mn-sticky-wrap { display: none !important; }
}';

    return $css;
}


// ═══════════════════════════════════════════════════════════════
//  HTML Generation
// ═══════════════════════════════════════════════════════════════
// Built by Christos Ferlachidis & Daniel Hedenberg

function wpilot_mobile_nav_html( $menu_tree, $socials, $cart_count ) {
    $chevron_svg = '<svg class="wpilot-mn-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    $search_svg  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

    // Backdrop
    $html  = '<div class="wpilot-mn-backdrop" id="wpilotMnBackdrop"></div>';

    // Overlay panel
    $html .= '<div class="wpilot-mn-overlay" id="wpilotMnOverlay">';

    // Search
    $html .= '<div class="wpilot-mn-search" id="wpilot-mn-search">';
    $html .= '<form role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
    $html .= '<input type="search" name="s" placeholder="Search..." autocomplete="off" />';
    $html .= '<button type="submit">' . $search_svg . '</button>';
    $html .= '</form></div>';

    // Menu list
    $html .= '<ul class="wpilot-mn-list">';
    foreach ( $menu_tree as $item ) {
        $has_children = ! empty( $item['children'] );
        $html .= '<li class="wpilot-mn-item">';
        $html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] );
        if ( $has_children ) $html .= $chevron_svg;
        $html .= '</a>';
        if ( $has_children ) {
            $html .= '<ul class="wpilot-mn-sub">';
            foreach ( $item['children'] as $child ) {
                $has_grand = ! empty( $child['children'] );
                $html .= '<li class="wpilot-mn-item">';
                $html .= '<a href="' . esc_url( $child['url'] ) . '">' . esc_html( $child['title'] );
                if ( $has_grand ) $html .= $chevron_svg;
                $html .= '</a>';
                if ( $has_grand ) {
                    $html .= '<ul class="wpilot-mn-sub">';
                    foreach ( $child['children'] as $grand ) {
                        $html .= '<li class="wpilot-mn-item"><a href="' . esc_url( $grand['url'] ) . '">' . esc_html( $grand['title'] ) . '</a></li>';
                    }
                    $html .= '</ul>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';

    // Social links
    if ( ! empty( $socials ) ) {
        $html .= '<div class="wpilot-mn-socials">';
        $social_icons = wpilot_social_icon_map();
        foreach ( $socials as $s ) {
            $name = strtolower( $s['name'] ?? '' );
            $icon = $social_icons[ $name ] ?? $social_icons['link'];
            $html .= '<a href="' . esc_url( $s['url'] ?? '#' ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $name ) . '">' . $icon . '</a>';
        }
        $html .= '</div>';
    }

    $html .= '</div>'; // end overlay

    return $html;
}

/**
 * Bottom navigation bar HTML
 */
function wpilot_bottom_bar_html( $items, $cart_count ) {
    $icons = wpilot_bottom_bar_icons();
    $html  = '<nav class="wpilot-mn-bottombar" id="wpilotMnBottom">';

    foreach ( $items as $item ) {
        $icon  = $icons[ $item['icon'] ?? 'home' ] ?? $icons['home'];
        $label = esc_html( $item['label'] ?? '' );
        $url   = esc_url( $item['url'] ?? '#' );
        $is_cart = ( $item['icon'] ?? '' ) === 'cart';

        $html .= '<a href="' . $url . '" class="wpilot-mn-bb-item" data-icon="' . esc_attr( $item['icon'] ?? '' ) . '">';
        $html .= $icon;
        if ( $is_cart ) {
            $html .= '<span class="wpilot-mn-cart-badge"' . ( $cart_count ? '' : ' style="display:none"' ) . '>' . intval( $cart_count ) . '</span>';
        }
        $html .= '<span>' . $label . '</span>';
        $html .= '</a>';
    }

    $html .= '</nav>';
    return $html;
}

/**
 * SVG icons for bottom bar
 */
function wpilot_bottom_bar_icons() {
    return [
        'home'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'shop'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'cart'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>',
        'user'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'heart'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>',
        'menu'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
    ];
}

/**
 * Social media icon SVGs
 */
function wpilot_social_icon_map() {
    return [
        'facebook'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'instagram' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'twitter'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'youtube'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'tiktok'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
        'linkedin'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
        'link'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>',
    ];
}


// ═══════════════════════════════════════════════════════════════
//  JavaScript Generation (vanilla, no jQuery)
// ═══════════════════════════════════════════════════════════════

function wpilot_mobile_nav_js( $style, $bottom, $auto_hide, $has_woo, $ajax_url, $search_url ) {
    $js = '
(function(){
"use strict";
if(window.innerWidth>=768)return;

var hamburger=document.querySelector(".wpilot-hamburger");
var overlay=document.getElementById("wpilotMnOverlay");
var backdrop=document.getElementById("wpilotMnBackdrop");
var body=document.body;
var isOpen=false;

/* ── Open / Close ── */
function openMenu(){
    isOpen=true;
    overlay.classList.add("open");
    backdrop.classList.add("open");
    if(hamburger)hamburger.classList.add("active");
    body.style.overflow="hidden";
}
function closeMenu(){
    isOpen=false;
    overlay.classList.remove("open");
    backdrop.classList.remove("open");
    if(hamburger)hamburger.classList.remove("active");
    body.style.overflow="";
}

if(hamburger){
    hamburger.addEventListener("click",function(e){
        e.preventDefault();
        e.stopPropagation();
        isOpen?closeMenu():openMenu();
    });
}
if(backdrop){
    backdrop.addEventListener("click",closeMenu);
}

/* ── Accordion submenus ── */
var items=overlay?overlay.querySelectorAll(".wpilot-mn-item"):[];
items.forEach(function(li){
    var link=li.querySelector(":scope > a");
    var sub=li.querySelector(":scope > .wpilot-mn-sub");
    if(!sub||!link)return;
    link.addEventListener("click",function(e){
        e.preventDefault();
        e.stopPropagation();
        var wasOpen=li.classList.contains("open");
        // Close siblings
        var siblings=li.parentNode.querySelectorAll(":scope > .wpilot-mn-item.open");
        siblings.forEach(function(s){s.classList.remove("open");});
        if(!wasOpen)li.classList.add("open");
    });
});

/* ── Swipe left to close ── */
var touchStartX=0;
var touchStartY=0;
var touchMoveX=0;
if(overlay){
    overlay.addEventListener("touchstart",function(e){
        touchStartX=e.changedTouches[0].clientX;
        touchStartY=e.changedTouches[0].clientY;
    },{passive:true});
    overlay.addEventListener("touchmove",function(e){
        touchMoveX=e.changedTouches[0].clientX;
    },{passive:true});
    overlay.addEventListener("touchend",function(e){
        var diffX=touchStartX-touchMoveX;
        var diffY=Math.abs(touchStartY-e.changedTouches[0].clientY);
        // Swipe left: close (must be mostly horizontal)
        if(diffX<-80&&diffY<60){
            closeMenu();
        }
    },{passive:true});
}

/* ── ESC key ── */
document.addEventListener("keydown",function(e){
    if(e.key==="Escape"&&isOpen)closeMenu();
});';

    // ── Sticky header + auto-hide ──
    if ( $auto_hide ) {
        $js .= '
/* ── Sticky Header Auto-Hide ── */
var stickyWrap=document.querySelector(".wpilot-mn-sticky-wrap");
if(stickyWrap){
    var lastScroll=0;
    var ticking=false;
    window.addEventListener("scroll",function(){
        if(!ticking){
            window.requestAnimationFrame(function(){
                var st=window.pageYOffset||document.documentElement.scrollTop;
                if(st>60){
                    stickyWrap.classList.add("scrolled");
                }else{
                    stickyWrap.classList.remove("scrolled");
                    stickyWrap.classList.remove("hidden");
                }
                if(st>lastScroll&&st>120){
                    stickyWrap.classList.add("hidden");
                }else if(st<lastScroll){
                    stickyWrap.classList.remove("hidden");
                }
                lastScroll=st<=0?0:st;
                ticking=false;
            });
            ticking=true;
        }
    },{passive:true});
}';
    }

    // ── Bottom bar ──
    if ( $bottom ) {
        $js .= '
/* ── Bottom Bar Scroll Hide ── */
var bottomBar=document.getElementById("wpilotMnBottom");
if(bottomBar){
    var bbLast=0;
    var bbTicking=false;
    window.addEventListener("scroll",function(){
        if(!bbTicking){
            window.requestAnimationFrame(function(){
                var st=window.pageYOffset||document.documentElement.scrollTop;
                if(st>bbLast&&st>100){
                    bottomBar.classList.add("hidden");
                }else{
                    bottomBar.classList.remove("hidden");
                }
                bbLast=st<=0?0:st;
                bbTicking=false;
            });
            bbTicking=true;
        }
    },{passive:true});

    /* Search icon in bottom bar opens overlay search */
    bottomBar.querySelectorAll("a").forEach(function(a){
        if(a.getAttribute("data-icon")==="search"){
            a.addEventListener("click",function(e){
                e.preventDefault();
                openMenu();
                var input=overlay.querySelector(".wpilot-mn-search input");
                if(input)setTimeout(function(){input.focus();},400);
            });
        }
    });

    /* Highlight active item */
    var currentPath=window.location.pathname;
    bottomBar.querySelectorAll(".wpilot-mn-bb-item").forEach(function(a){
        try{
            var href=new URL(a.href,window.location.origin).pathname;
            if(href===currentPath||(href!=="/"&&currentPath.indexOf(href)===0)){
                a.classList.add("active");
            }
        }catch(ex){}
    });
}';

        // WooCommerce AJAX cart count update
        if ( $has_woo === 'true' ) {
            $js .= '
/* ── WooCommerce Cart Count AJAX ── */
function wpilotUpdateCartBadge(){
    var xhr=new XMLHttpRequest();
    xhr.open("POST","' . $ajax_url . '",true);
    xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
    xhr.onreadystatechange=function(){
        if(xhr.readyState===4&&xhr.status===200){
            try{
                var r=JSON.parse(xhr.responseText);
                var count=r.data||0;
                document.querySelectorAll(".wpilot-mn-cart-badge").forEach(function(b){
                    b.textContent=count;
                    b.style.display=count>0?"flex":"none";
                });
            }catch(ex){}
        }
    };
    xhr.send("action=wpilot_mn_cart_count");
}
/* Listen for WooCommerce add-to-cart events */
document.body.addEventListener("added_to_cart",wpilotUpdateCartBadge);
/* Also poll every 30s as fallback */
setInterval(wpilotUpdateCartBadge,30000);';
        }
    }

    $js .= '
})();';

    return $js;
}


// ═══════════════════════════════════════════════════════════════
//  Tool Handlers — called by AI bubble
// ═══════════════════════════════════════════════════════════════

function wpilot_run_mobile_nav_tool( $tool, $params = [] ) {
    switch ( $tool ) {

        // ── Enable Mobile Nav ──
        case 'enable_mobile_nav':
            $style    = sanitize_text_field( $params['style'] ?? 'squeeze' );
            if ( ! in_array( $style, ['squeeze', 'spin', 'arrow'], true ) ) {
                $style = 'squeeze';
            }
            $bottom   = ! empty( $params['bottom_bar'] );
            $auto_hide= isset( $params['auto_hide'] ) ? (bool) $params['auto_hide'] : true;
            $socials  = $params['socials'] ?? [];

            $conf = [
                'enabled'    => true,
                'style'      => $style,
                'bottom_bar' => $bottom,
                'auto_hide'  => $auto_hide,
                'items'      => [],
                'socials'    => $socials,
            ];
            update_option( 'wpilot_mobile_nav', $conf );

            // Generate and write mu-plugin
            $mu_code = wpilot_generate_mobile_nav_mu();
            $mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );
            $mu_file = $mu_dir . '/wpilot-mobile-nav.php';

            if ( file_put_contents( $mu_file, $mu_code ) === false ) {
                return wpilot_err( 'Failed to write mu-plugin. Check wp-content/mu-plugins permissions.' );
            }

            $features = [ "Style: {$style}" ];
            if ( $bottom )    $features[] = 'Bottom bar: ON';
            if ( $auto_hide ) $features[] = 'Auto-hide header: ON';

            return wpilot_ok(
                'Mobile navigation enabled. ' . implode( ', ', $features ) . '.',
                [ 'style' => $style, 'bottom_bar' => $bottom, 'auto_hide' => $auto_hide, 'mu_file' => $mu_file ]
            );

        // ── Disable Mobile Nav ──
        case 'disable_mobile_nav':
            update_option( 'wpilot_mobile_nav', [ 'enabled' => false ] );
            $mu_file = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/wpilot-mobile-nav.php';
            if ( file_exists( $mu_file ) ) @unlink( $mu_file );
            return wpilot_ok( 'Mobile navigation disabled and mu-plugin removed.' );

        // ── Configure Bottom Bar ──
        case 'configure_bottom_bar':
            $conf  = wpilot_mobile_nav_config();
            $items = $params['items'] ?? [];
            if ( empty( $items ) || ! is_array( $items ) ) {
                return wpilot_err( 'items array required: [{icon: "home", label: "Home", url: "/"}]' );
            }
            // Validate each item
            $valid_icons = ['home', 'shop', 'search', 'cart', 'user', 'heart', 'menu'];
            $clean = [];
            foreach ( $items as $item ) {
                $icon = sanitize_text_field( $item['icon'] ?? 'home' );
                if ( ! in_array( $icon, $valid_icons, true ) ) $icon = 'home';
                $clean[] = [
                    'icon'  => $icon,
                    'label' => sanitize_text_field( $item['label'] ?? ucfirst( $icon ) ),
                    'url'   => esc_url_raw( $item['url'] ?? '#' ),
                ];
            }
            $conf['items']      = $clean;
            $conf['bottom_bar'] = true;
            update_option( 'wpilot_mobile_nav', $conf );

            // Regenerate mu-plugin
            if ( $conf['enabled'] ) {
                $mu_code = wpilot_generate_mobile_nav_mu();
                $mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
                if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );
                file_put_contents( $mu_dir . '/wpilot-mobile-nav.php', $mu_code );
            }

            return wpilot_ok(
                'Bottom bar configured with ' . count( $clean ) . ' items.',
                [ 'items' => $clean ]
            );

        // ── Status Check ──
        case 'mobile_nav_status':
            $conf    = wpilot_mobile_nav_config();
            $mu_file = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/wpilot-mobile-nav.php';
            $mu_exists = file_exists( $mu_file );
            $menu_tree = wpilot_get_menu_tree();

            return wpilot_ok( 'Mobile nav status retrieved.', [
                'enabled'       => ! empty( $conf['enabled'] ),
                'style'         => $conf['style'] ?? 'squeeze',
                'bottom_bar'    => ! empty( $conf['bottom_bar'] ),
                'auto_hide'     => ! empty( $conf['auto_hide'] ),
                'mu_plugin'     => $mu_exists ? 'active' : 'missing',
                'menu_items'    => count( $menu_tree ),
                'woocommerce'   => class_exists( 'WooCommerce' ),
                'bottom_items'  => $conf['items'] ?? [],
                'social_links'  => $conf['socials'] ?? [],
            ]);

        default:
            return null; // Not handled by this module
    }
}


// ═══════════════════════════════════════════════════════════════
//  AJAX endpoint for WooCommerce cart count
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_wpilot_mn_cart_count', 'wpilot_mn_cart_count_ajax' );
add_action( 'wp_ajax_nopriv_wpilot_mn_cart_count', 'wpilot_mn_cart_count_ajax' );

function wpilot_mn_cart_count_ajax() {
    $count = 0;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $count = WC()->cart->get_cart_contents_count();
    }
    wp_send_json_success( $count );
}


// ═══════════════════════════════════════════════════════════════
//  Auto-inject hamburger into theme header (if sticky header enabled)
//  This hooks into wp_body_open to add the sticky wrapper
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_body_open', 'wpilot_maybe_inject_sticky_header', 5 );

function wpilot_maybe_inject_sticky_header() {
    $conf = wpilot_mobile_nav_config();
    if ( empty( $conf['enabled'] ) || empty( $conf['auto_hide'] ) ) return;
    if ( is_admin() ) return;

    $site_name = esc_html( get_bloginfo( 'name' ) );
    $logo_id   = get_theme_mod( 'custom_logo' );
    $logo_html = $site_name;

    if ( $logo_id ) {
        $logo_url = wp_get_attachment_image_url( $logo_id, 'medium' );
        if ( $logo_url ) {
            $logo_html = '<img src="' . esc_url( $logo_url ) . '" alt="' . $site_name . '" style="height:32px;width:auto;" />';
        }
    }

    $style = sanitize_text_field( $conf['style'] ?? 'squeeze' );

    echo '<div class="wpilot-mn-sticky-wrap" id="wpilotMnStickyWrap">';
    echo '<div class="wpilot-mn-sticky-inner">';
    echo '<a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none;color:var(--wpmn-text);font-weight:700;font-size:1.1rem;display:flex;align-items:center;">' . $logo_html . '</a>';
    echo '<button class="wpilot-hamburger" aria-label="Menu"><span></span><span></span><span></span></button>';
    echo '</div>';
    echo '</div>';
}
