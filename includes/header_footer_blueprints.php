<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  HEADER & FOOTER BLUEPRINTS — Pre-built layout templates
//
//  Uses CSS variables from the design blueprint system so
//  every header/footer automatically matches the active design.
//
//  Flow:
//  1. AI picks header/footer style based on site type
//  2. render function generates full HTML + CSS + JS
//  3. apply function stores in wp_options + creates mu-plugin
//  4. mu-plugin injects at wp_body_open / wp_footer hooks
//     and hides the theme's default header/footer
// ═══════════════════════════════════════════════════════════════


// ── Header blueprint definitions ───────────────────────────────
function wpilot_get_header_blueprints() {
    return [
        'modern' => [
            'name'        => 'Modern',
            'description' => 'Logo left, menu center, CTA button right. Clean line below.',
            'keywords'    => ['modern','clean','business','shop','ecommerce','default'],
        ],
        'minimal' => [
            'name'        => 'Minimal',
            'description' => 'Logo left, menu right. No line, no background, super clean.',
            'keywords'    => ['minimal','simple','portfolio','studio','agency'],
        ],
        'transparent' => [
            'name'        => 'Transparent',
            'description' => 'Overlays hero section. White text, no background. Position absolute.',
            'keywords'    => ['transparent','overlay','hero','landing','photography','travel'],
        ],
        'glass' => [
            'name'        => 'Glass',
            'description' => 'Sticky header with backdrop-filter blur, semi-transparent background.',
            'keywords'    => ['glass','blur','sticky','modern','tech','saas','startup'],
        ],
        'centered' => [
            'name'        => 'Centered',
            'description' => 'Logo centered above menu. Elegant, fashion-style layout.',
            'keywords'    => ['centered','elegant','fashion','luxury','brand','boutique'],
        ],
    ];
}


// ── Footer blueprint definitions ───────────────────────────────
function wpilot_get_footer_blueprints() {
    return [
        'columns' => [
            'name'        => 'Columns',
            'description' => '4-column grid: brand + description, quick links, contact info, newsletter/social.',
            'keywords'    => ['columns','full','business','corporate','shop','default'],
        ],
        'minimal' => [
            'name'        => 'Minimal',
            'description' => 'Single row: copyright left, social links right.',
            'keywords'    => ['minimal','simple','clean','portfolio','one-line'],
        ],
        'centered' => [
            'name'        => 'Centered',
            'description' => 'All centered: logo, short text, links row, copyright.',
            'keywords'    => ['centered','elegant','fashion','brand','boutique'],
        ],
        'rich' => [
            'name'        => 'Rich',
            'description' => 'Full-width dark section with brand story, links, contact, map placeholder, newsletter.',
            'keywords'    => ['rich','full','mega','restaurant','hotel','agency','premium'],
        ],
    ];
}


// ── Helper: get menu items from WP nav menus ───────────────────
function wpilot_get_nav_menu_items_html() {
    $locations = get_nav_menu_locations();
    $menu_id   = 0;

    // Try common menu location slugs
    foreach ( ['primary', 'main-menu', 'main', 'header-menu', 'primary-menu', 'menu-1'] as $loc ) {
        if ( ! empty( $locations[ $loc ] ) ) {
            $menu_id = $locations[ $loc ];
            break;
        }
    }

    // Fallback: grab first available menu
    if ( ! $menu_id ) {
        $menus = wp_get_nav_menus();
        if ( ! empty( $menus ) ) $menu_id = $menus[0]->term_id;
    }

    if ( ! $menu_id ) {
        // No menus at all — return sample items
        return '<li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li>';
    }

    $items = wp_get_nav_menu_items( $menu_id );
    if ( empty( $items ) ) {
        return '<li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li>';
    }

    $html = '';
    foreach ( $items as $item ) {
        if ( $item->menu_item_parent == 0 ) {
            $html .= '<li><a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a></li>';
        }
    }
    return $html;
}

// ── Helper: get site logo or name ──────────────────────────────
function wpilot_get_logo_html( $class = '' ) {
    $logo_id = get_theme_mod( 'custom_logo' );
    if ( $logo_id ) {
        $img = wp_get_attachment_image( $logo_id, 'medium', false, [
            'class' => 'wpilot-logo-img ' . esc_attr( $class ),
            'alt'   => get_bloginfo( 'name' ),
        ]);
        return '<a href="' . esc_url( home_url('/') ) . '" class="wpilot-logo-link">' . $img . '</a>';
    }
    return '<a href="' . esc_url( home_url('/') ) . '" class="wpilot-logo-link wpilot-logo-text ' . esc_attr( $class ) . '">' . esc_html( get_bloginfo('name') ) . '</a>';
}


// ═══════════════════════════════════════════════════════════════
//  RENDER HEADER BLUEPRINT
// ═══════════════════════════════════════════════════════════════
function wpilot_render_header_blueprint( $style, $params = [] ) {
    $blueprints = wpilot_get_header_blueprints();
    if ( ! isset( $blueprints[ $style ] ) ) {
        return wpilot_err( "Header blueprint \"{$style}\" not found. Available: " . implode(', ', array_keys($blueprints)) );
    }

    $cta_text = $params['cta_text'] ?? 'Shop Now';
    $cta_url  = $params['cta_url']  ?? '/shop';
    $menu_items = wpilot_get_nav_menu_items_html();
    $logo       = wpilot_get_logo_html();

    // ── Shared mobile menu overlay (all styles use this) ───────
    $mobile_overlay = '
<div id="wpilot-mobile-menu" class="wpilot-mobile-menu">
    <button class="wpilot-mobile-close" aria-label="Close menu">&times;</button>
    <nav class="wpilot-mobile-nav">
        <ul>' . $menu_items . '</ul>
        <a href="' . esc_url( $cta_url ) . '" class="wpilot-header-cta-mobile">' . esc_html( $cta_text ) . '</a>
    </nav>
</div>';

    // ── Hamburger button (shared) ──────────────────────────────
    $hamburger = '<button class="wpilot-hamburger" aria-label="Open menu"><span></span><span></span><span></span></button>';

    // ── Mobile menu JS (shared) ────────────────────────────────
    $mobile_js = '
<script>
(function(){
    var btn=document.querySelector(".wpilot-hamburger"),
        menu=document.getElementById("wpilot-mobile-menu"),
        close=document.querySelector(".wpilot-mobile-close");
    if(btn&&menu){
        btn.addEventListener("click",function(){menu.classList.add("open")});
        if(close)close.addEventListener("click",function(){menu.classList.remove("open")});
        menu.addEventListener("click",function(e){if(e.target===menu)menu.classList.remove("open")});
    }
})();
</script>';

    // ── Shared base CSS (mobile menu + hamburger) ──────────────
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $base_css = '
/* WPilot Header — Shared Base */
body #wpilot-header * { box-sizing: border-box; margin: 0; padding: 0; }
body #wpilot-header ul { list-style: none; }
body #wpilot-header a { text-decoration: none; }
body #wpilot-header .wpilot-logo-img { max-height: 45px; width: auto; display: block; }
body #wpilot-header .wpilot-logo-text { font-size: 1.5rem; font-weight: 700; color: var(--wp-heading) !important; }
body #wpilot-header .wpilot-header-cta {
    display: inline-block; padding: 10px 24px; background: var(--wp-primary) !important;
    color: var(--wp-bg) !important; border-radius: var(--wp-radius, 6px); font-weight: 600;
    font-size: 0.9rem; transition: all 0.3s ease; white-space: nowrap;
}
body #wpilot-header .wpilot-header-cta:hover { background: var(--wp-secondary) !important; transform: translateY(-1px); }
body #wpilot-header nav ul { display: flex; gap: 28px; align-items: center; }
body #wpilot-header nav ul li a {
    color: var(--wp-text) !important; font-size: 0.95rem; font-weight: 500;
    transition: color 0.2s ease; position: relative;
}
body #wpilot-header nav ul li a:hover { color: var(--wp-primary) !important; }

/* Hamburger */
body #wpilot-header .wpilot-hamburger {
    display: none; background: none; border: none; cursor: pointer;
    width: 28px; height: 20px; position: relative; z-index: 10;
}
body #wpilot-header .wpilot-hamburger span {
    display: block; width: 100%; height: 2px; background: var(--wp-text);
    position: absolute; left: 0; transition: all 0.3s ease;
}
body #wpilot-header .wpilot-hamburger span:nth-child(1) { top: 0; }
body #wpilot-header .wpilot-hamburger span:nth-child(2) { top: 9px; }
body #wpilot-header .wpilot-hamburger span:nth-child(3) { top: 18px; }

/* Mobile overlay */
.wpilot-mobile-menu {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 999999; opacity: 0;
    visibility: hidden; transition: all 0.3s ease;
}
.wpilot-mobile-menu.open { opacity: 1; visibility: visible; }
.wpilot-mobile-menu .wpilot-mobile-nav {
    position: absolute; top: 0; right: 0; width: 300px; max-width: 85vw; height: 100%;
    background: var(--wp-bg) !important; padding: 80px 30px 30px; overflow-y: auto;
    transform: translateX(100%); transition: transform 0.3s ease;
}
.wpilot-mobile-menu.open .wpilot-mobile-nav { transform: translateX(0); }
.wpilot-mobile-menu .wpilot-mobile-close {
    position: absolute; top: 20px; right: 20px; background: none; border: none;
    font-size: 2rem; color: var(--wp-text); cursor: pointer; z-index: 10; line-height: 1;
}
.wpilot-mobile-menu .wpilot-mobile-nav ul { display: flex; flex-direction: column; gap: 0; }
.wpilot-mobile-menu .wpilot-mobile-nav ul li a {
    display: block; padding: 14px 0; color: var(--wp-text) !important; font-size: 1.1rem;
    font-weight: 500; border-bottom: 1px solid var(--wp-border);
}
.wpilot-mobile-menu .wpilot-mobile-nav ul li a:hover { color: var(--wp-primary) !important; }
.wpilot-mobile-menu .wpilot-header-cta-mobile {
    display: block; margin-top: 24px; padding: 14px 24px; text-align: center;
    background: var(--wp-primary) !important; color: var(--wp-bg) !important;
    border-radius: var(--wp-radius, 6px); font-weight: 600; font-size: 1rem;
}

@media (max-width: 768px) {
    body #wpilot-header .wpilot-hamburger { display: block; }
    body #wpilot-header nav.wpilot-desktop-nav { display: none !important; }
    body #wpilot-header .wpilot-header-cta { display: none !important; }
}';

    // ── Style-specific CSS + HTML ──────────────────────────────
    $style_css = '';
    $header_html = '';

    switch ( $style ) {

        case 'modern':
            $style_css = '
body #wpilot-header.wpilot-header-modern {
    background: var(--wp-bg) !important; border-bottom: 1px solid var(--wp-border);
    padding: 0 40px; position: relative; z-index: 99999;
}
body #wpilot-header.wpilot-header-modern .wpilot-header-inner {
    display: flex; align-items: center; justify-content: space-between;
    max-width: 1400px; margin: 0 auto; height: 72px;
}
body #wpilot-header.wpilot-header-modern nav { position: absolute; left: 50%; transform: translateX(-50%); }
@media (max-width: 768px) {
    body #wpilot-header.wpilot-header-modern { padding: 0 20px; }
    body #wpilot-header.wpilot-header-modern nav { position: static; transform: none; }
}';
            $header_html = '
<header id="wpilot-header" class="wpilot-header-modern">
    <div class="wpilot-header-inner">
        ' . $logo . '
        <nav class="wpilot-desktop-nav"><ul>' . $menu_items . '</ul></nav>
        <div style="display:flex;align-items:center;gap:16px;">
            <a href="' . esc_url( $cta_url ) . '" class="wpilot-header-cta">' . esc_html( $cta_text ) . '</a>
            ' . $hamburger . '
        </div>
    </div>
</header>';
            break;

        case 'minimal':
            $style_css = '
body #wpilot-header.wpilot-header-minimal {
    background: transparent !important; padding: 0 40px; position: relative; z-index: 99999;
}
body #wpilot-header.wpilot-header-minimal .wpilot-header-inner {
    display: flex; align-items: center; justify-content: space-between;
    max-width: 1400px; margin: 0 auto; height: 72px;
}
body #wpilot-header.wpilot-header-minimal .wpilot-right-group { display: flex; align-items: center; gap: 28px; }
@media (max-width: 768px) {
    body #wpilot-header.wpilot-header-minimal { padding: 0 20px; }
}';
            $header_html = '
<header id="wpilot-header" class="wpilot-header-minimal">
    <div class="wpilot-header-inner">
        ' . $logo . '
        <div class="wpilot-right-group">
            <nav class="wpilot-desktop-nav"><ul>' . $menu_items . '</ul></nav>
            ' . $hamburger . '
        </div>
    </div>
</header>';
            break;

        case 'transparent':
            $style_css = '
body #wpilot-header.wpilot-header-transparent {
    position: absolute !important; top: 0; left: 0; width: 100%; z-index: 99999;
    background: transparent !important; padding: 0 40px;
}
body #wpilot-header.wpilot-header-transparent .wpilot-header-inner {
    display: flex; align-items: center; justify-content: space-between;
    max-width: 1400px; margin: 0 auto; height: 80px;
}
body #wpilot-header.wpilot-header-transparent .wpilot-logo-text { color: #ffffff !important; }
body #wpilot-header.wpilot-header-transparent nav ul li a { color: rgba(255,255,255,0.9) !important; }
body #wpilot-header.wpilot-header-transparent nav ul li a:hover { color: #ffffff !important; }
body #wpilot-header.wpilot-header-transparent .wpilot-header-cta {
    background: rgba(255,255,255,0.15) !important; color: #ffffff !important;
    border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(4px);
}
body #wpilot-header.wpilot-header-transparent .wpilot-header-cta:hover {
    background: rgba(255,255,255,0.25) !important;
}
body #wpilot-header.wpilot-header-transparent .wpilot-hamburger span { background: #ffffff; }
@media (max-width: 768px) {
    body #wpilot-header.wpilot-header-transparent { padding: 0 20px; }
}';
            $header_html = '
<header id="wpilot-header" class="wpilot-header-transparent">
    <div class="wpilot-header-inner">
        ' . $logo . '
        <div style="display:flex;align-items:center;gap:20px;">
            <nav class="wpilot-desktop-nav"><ul>' . $menu_items . '</ul></nav>
            <a href="' . esc_url( $cta_url ) . '" class="wpilot-header-cta">' . esc_html( $cta_text ) . '</a>
            ' . $hamburger . '
        </div>
    </div>
</header>';
            break;

        case 'glass':
            $style_css = '
body #wpilot-header.wpilot-header-glass {
    position: sticky !important; top: 0; left: 0; width: 100%; z-index: 99999;
    background: rgba(var(--wp-bg-rgb, 255,255,255), 0.75) !important;
    backdrop-filter: blur(16px) saturate(180%); -webkit-backdrop-filter: blur(16px) saturate(180%);
    border-bottom: 1px solid rgba(var(--wp-border-rgb, 200,200,200), 0.3);
    padding: 0 40px; transition: all 0.3s ease;
}
body #wpilot-header.wpilot-header-glass .wpilot-header-inner {
    display: flex; align-items: center; justify-content: space-between;
    max-width: 1400px; margin: 0 auto; height: 68px;
}
body #wpilot-header.wpilot-header-glass .wpilot-right-group { display: flex; align-items: center; gap: 24px; }
@media (max-width: 768px) {
    body #wpilot-header.wpilot-header-glass { padding: 0 20px; }
}';
            $header_html = '
<header id="wpilot-header" class="wpilot-header-glass">
    <div class="wpilot-header-inner">
        ' . $logo . '
        <div class="wpilot-right-group">
            <nav class="wpilot-desktop-nav"><ul>' . $menu_items . '</ul></nav>
            <a href="' . esc_url( $cta_url ) . '" class="wpilot-header-cta">' . esc_html( $cta_text ) . '</a>
            ' . $hamburger . '
        </div>
    </div>
</header>';
            break;

        case 'centered':
            $style_css = '
body #wpilot-header.wpilot-header-centered {
    background: var(--wp-bg) !important; padding: 24px 40px 0; position: relative; z-index: 99999;
    text-align: center; border-bottom: 1px solid var(--wp-border);
}
body #wpilot-header.wpilot-header-centered .wpilot-header-inner {
    max-width: 1400px; margin: 0 auto;
}
body #wpilot-header.wpilot-header-centered .wpilot-logo-link { display: inline-block; margin-bottom: 16px; }
body #wpilot-header.wpilot-header-centered .wpilot-logo-text { font-size: 1.8rem; letter-spacing: 2px; text-transform: uppercase; }
body #wpilot-header.wpilot-header-centered nav { display: flex; justify-content: center; padding-bottom: 16px; }
body #wpilot-header.wpilot-header-centered nav ul { justify-content: center; }
body #wpilot-header.wpilot-header-centered nav ul li a { font-size: 0.85rem; letter-spacing: 1px; text-transform: uppercase; }
body #wpilot-header.wpilot-header-centered .wpilot-hamburger { position: absolute; right: 20px; top: 30px; }
@media (max-width: 768px) {
    body #wpilot-header.wpilot-header-centered { padding: 16px 20px 0; }
}';
            $header_html = '
<header id="wpilot-header" class="wpilot-header-centered">
    <div class="wpilot-header-inner">
        ' . $logo . '
        <nav class="wpilot-desktop-nav"><ul>' . $menu_items . '</ul></nav>
        ' . $hamburger . '
    </div>
</header>';
            break;
    }

    // ── Assemble full output ───────────────────────────────────
    $output  = '<style id="wpilot-header-css">' . $base_css . $style_css . '</style>';
    $output .= $header_html;
    $output .= $mobile_overlay;
    $output .= $mobile_js;

    return $output;
}


// ═══════════════════════════════════════════════════════════════
//  RENDER FOOTER BLUEPRINT
// ═══════════════════════════════════════════════════════════════
function wpilot_render_footer_blueprint( $style, $params = [] ) {
    $blueprints = wpilot_get_footer_blueprints();
    if ( ! isset( $blueprints[ $style ] ) ) {
        return wpilot_err( "Footer blueprint \"{$style}\" not found. Available: " . implode(', ', array_keys($blueprints)) );
    }

    $site_name   = get_bloginfo( 'name' );
    $site_desc   = $params['description'] ?? get_bloginfo( 'description' );
    $year        = date( 'Y' );
    $email       = $params['email']   ?? get_option( 'admin_email', 'info@example.com' );
    $phone       = $params['phone']   ?? '';
    $address     = $params['address'] ?? '';

    // Social links — defaults are placeholders
    $socials = [
        'facebook'  => $params['facebook']  ?? '#',
        'instagram' => $params['instagram'] ?? '#',
        'twitter'   => $params['twitter']   ?? '#',
        'linkedin'  => $params['linkedin']  ?? '#',
    ];

    $social_html = '';
    foreach ( $socials as $platform => $url ) {
        if ( $url && $url !== '#' ) {
            $social_html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" class="wpilot-social-link" aria-label="' . esc_attr( ucfirst( $platform ) ) . '">' . wpilot_social_svg( $platform ) . '</a>';
        } else {
            $social_html .= '<a href="#" class="wpilot-social-link" aria-label="' . esc_attr( ucfirst( $platform ) ) . '">' . wpilot_social_svg( $platform ) . '</a>';
        }
    }

    // ── Shared footer CSS ──────────────────────────────────────
    $base_css = '
/* WPilot Footer — Shared Base */
body #wpilot-footer * { box-sizing: border-box; margin: 0; padding: 0; }
body #wpilot-footer { font-size: 0.95rem; line-height: 1.6; }
body #wpilot-footer ul { list-style: none; }
body #wpilot-footer a { text-decoration: none; color: var(--wp-text-muted); transition: color 0.2s ease; }
body #wpilot-footer a:hover { color: var(--wp-primary) !important; }
body #wpilot-footer h3, body #wpilot-footer h4 { color: var(--wp-heading) !important; margin-bottom: 16px; font-size: 1rem; font-weight: 600; }
body #wpilot-footer .wpilot-social-links { display: flex; gap: 12px; }
body #wpilot-footer .wpilot-social-link {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--wp-border);
    transition: all 0.3s ease;
}
body #wpilot-footer .wpilot-social-link:hover {
    background: var(--wp-primary) !important; border-color: var(--wp-primary) !important;
}
body #wpilot-footer .wpilot-social-link:hover svg { fill: var(--wp-bg); }
body #wpilot-footer .wpilot-social-link svg { width: 16px; height: 16px; fill: var(--wp-text-muted); transition: fill 0.3s ease; }
body #wpilot-footer .wpilot-footer-bottom {
    border-top: 1px solid var(--wp-border); padding-top: 24px; margin-top: 40px;
    color: var(--wp-text-muted); font-size: 0.85rem;
}';

    $style_css   = '';
    $footer_html = '';

    switch ( $style ) {

        case 'columns':
            $style_css = '
body #wpilot-footer.wpilot-footer-columns {
    background: var(--wp-bg-alt) !important; color: var(--wp-text) !important;
    padding: 60px 40px 30px; border-top: 1px solid var(--wp-border);
}
body #wpilot-footer.wpilot-footer-columns .wpilot-footer-inner {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: 1.5fr 1fr 1fr 1.2fr; gap: 40px;
}
body #wpilot-footer.wpilot-footer-columns .wpilot-footer-col p { color: var(--wp-text-muted); margin-bottom: 12px; }
body #wpilot-footer.wpilot-footer-columns .wpilot-footer-col ul li { margin-bottom: 8px; }
body #wpilot-footer.wpilot-footer-columns .wpilot-footer-col ul li a { color: var(--wp-text-muted) !important; }
body #wpilot-footer.wpilot-footer-columns .wpilot-newsletter-form {
    display: flex; gap: 8px; margin-top: 12px;
}
body #wpilot-footer.wpilot-footer-columns .wpilot-newsletter-form input {
    flex: 1; padding: 10px 14px; border: 1px solid var(--wp-border); border-radius: var(--wp-radius, 6px);
    background: var(--wp-bg); color: var(--wp-text); font-size: 0.9rem;
}
body #wpilot-footer.wpilot-footer-columns .wpilot-newsletter-form button {
    padding: 10px 20px; background: var(--wp-primary) !important; color: var(--wp-bg) !important;
    border: none; border-radius: var(--wp-radius, 6px); font-weight: 600; cursor: pointer;
    transition: background 0.3s ease;
}
body #wpilot-footer.wpilot-footer-columns .wpilot-newsletter-form button:hover { background: var(--wp-secondary) !important; }
body #wpilot-footer.wpilot-footer-columns .wpilot-footer-bottom-row {
    max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
}
@media (max-width: 900px) {
    body #wpilot-footer.wpilot-footer-columns .wpilot-footer-inner { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
    body #wpilot-footer.wpilot-footer-columns { padding: 40px 20px 24px; }
    body #wpilot-footer.wpilot-footer-columns .wpilot-footer-inner { grid-template-columns: 1fr; gap: 30px; }
    body #wpilot-footer.wpilot-footer-columns .wpilot-footer-bottom-row { flex-direction: column; text-align: center; }
}';
            $contact_lines = '';
            if ( $address ) $contact_lines .= '<li>' . esc_html( $address ) . '</li>';
            if ( $phone )   $contact_lines .= '<li><a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a></li>';
            if ( $email )   $contact_lines .= '<li><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></li>';

            $footer_html = '
<footer id="wpilot-footer" class="wpilot-footer-columns">
    <div class="wpilot-footer-inner">
        <div class="wpilot-footer-col">
            <h4>' . esc_html( $site_name ) . '</h4>
            <p>' . esc_html( $site_desc ) . '</p>
            <div class="wpilot-social-links">' . $social_html . '</div>
        </div>
        <div class="wpilot-footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="' . esc_url( home_url('/') ) . '">Home</a></li>
                <li><a href="' . esc_url( home_url('/about') ) . '">About</a></li>
                <li><a href="' . esc_url( home_url('/shop') ) . '">Shop</a></li>
                <li><a href="' . esc_url( home_url('/contact') ) . '">Contact</a></li>
            </ul>
        </div>
        <div class="wpilot-footer-col">
            <h4>Contact</h4>
            <ul>' . $contact_lines . '</ul>
        </div>
        <div class="wpilot-footer-col">
            <h4>Newsletter</h4>
            <p>Stay updated with our latest news.</p>
            <form class="wpilot-newsletter-form" onsubmit="return false;">
                <input type="email" placeholder="Your email" aria-label="Email">
                <button type="submit">Subscribe</button>
            </form>
        </div>
    </div>
    <div class="wpilot-footer-bottom">
        <div class="wpilot-footer-bottom-row">
            <span>&copy; ' . $year . ' ' . esc_html( $site_name ) . '. All rights reserved.</span>
            <span>Powered by WPilot</span>
        </div>
    </div>
</footer>';
            break;

        case 'minimal':
            $style_css = '
body #wpilot-footer.wpilot-footer-minimal {
    background: var(--wp-bg-alt) !important; padding: 24px 40px;
    border-top: 1px solid var(--wp-border);
}
body #wpilot-footer.wpilot-footer-minimal .wpilot-footer-inner {
    max-width: 1400px; margin: 0 auto;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
}
body #wpilot-footer.wpilot-footer-minimal .wpilot-copyright { color: var(--wp-text-muted); font-size: 0.9rem; }
@media (max-width: 600px) {
    body #wpilot-footer.wpilot-footer-minimal { padding: 20px; }
    body #wpilot-footer.wpilot-footer-minimal .wpilot-footer-inner { flex-direction: column; text-align: center; }
}';
            $footer_html = '
<footer id="wpilot-footer" class="wpilot-footer-minimal">
    <div class="wpilot-footer-inner">
        <span class="wpilot-copyright">&copy; ' . $year . ' ' . esc_html( $site_name ) . '</span>
        <div class="wpilot-social-links">' . $social_html . '</div>
    </div>
</footer>';
            break;

        case 'centered':
            // Built by Christos Ferlachidis & Daniel Hedenberg
            $style_css = '
body #wpilot-footer.wpilot-footer-centered {
    background: var(--wp-bg-alt) !important; padding: 60px 40px 30px;
    border-top: 1px solid var(--wp-border); text-align: center;
}
body #wpilot-footer.wpilot-footer-centered .wpilot-footer-inner { max-width: 600px; margin: 0 auto; }
body #wpilot-footer.wpilot-footer-centered .wpilot-logo-link { display: inline-block; margin-bottom: 16px; }
body #wpilot-footer.wpilot-footer-centered .wpilot-logo-text { font-size: 1.5rem; }
body #wpilot-footer.wpilot-footer-centered .wpilot-footer-desc { color: var(--wp-text-muted); margin-bottom: 24px; max-width: 400px; margin-left: auto; margin-right: auto; }
body #wpilot-footer.wpilot-footer-centered .wpilot-footer-links { display: flex; justify-content: center; flex-wrap: wrap; gap: 24px; margin-bottom: 24px; }
body #wpilot-footer.wpilot-footer-centered .wpilot-footer-links a { color: var(--wp-text) !important; font-weight: 500; }
body #wpilot-footer.wpilot-footer-centered .wpilot-social-links { justify-content: center; margin-bottom: 24px; }
body #wpilot-footer.wpilot-footer-centered .wpilot-copyright { color: var(--wp-text-muted); font-size: 0.85rem; }
@media (max-width: 600px) {
    body #wpilot-footer.wpilot-footer-centered { padding: 40px 20px 24px; }
}';
            $logo_footer = wpilot_get_logo_html();
            $footer_html = '
<footer id="wpilot-footer" class="wpilot-footer-centered">
    <div class="wpilot-footer-inner">
        ' . $logo_footer . '
        <p class="wpilot-footer-desc">' . esc_html( $site_desc ) . '</p>
        <div class="wpilot-footer-links">
            <a href="' . esc_url( home_url('/') ) . '">Home</a>
            <a href="' . esc_url( home_url('/about') ) . '">About</a>
            <a href="' . esc_url( home_url('/shop') ) . '">Shop</a>
            <a href="' . esc_url( home_url('/contact') ) . '">Contact</a>
        </div>
        <div class="wpilot-social-links">' . $social_html . '</div>
        <span class="wpilot-copyright">&copy; ' . $year . ' ' . esc_html( $site_name ) . '. All rights reserved.</span>
    </div>
</footer>';
            break;

        case 'rich':
            $style_css = '
body #wpilot-footer.wpilot-footer-rich {
    background: var(--wp-bg-alt) !important; color: var(--wp-text) !important;
    padding: 80px 40px 30px; border-top: 1px solid var(--wp-border);
}
body #wpilot-footer.wpilot-footer-rich .wpilot-footer-top {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: 1.5fr 1fr 1fr 1.5fr; gap: 40px; margin-bottom: 50px;
}
body #wpilot-footer.wpilot-footer-rich .wpilot-footer-col p { color: var(--wp-text-muted); margin-bottom: 12px; }
body #wpilot-footer.wpilot-footer-rich .wpilot-footer-col ul li { margin-bottom: 8px; }
body #wpilot-footer.wpilot-footer-rich .wpilot-footer-col ul li a { color: var(--wp-text-muted) !important; }
body #wpilot-footer.wpilot-footer-rich .wpilot-footer-mid {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 50px;
    padding-top: 40px; border-top: 1px solid var(--wp-border);
}
body #wpilot-footer.wpilot-footer-rich .wpilot-map-placeholder {
    width: 100%; height: 200px; background: var(--wp-bg); border: 1px solid var(--wp-border);
    border-radius: var(--wp-radius, 6px); display: flex; align-items: center; justify-content: center;
    color: var(--wp-text-muted); font-size: 0.9rem;
}
body #wpilot-footer.wpilot-footer-rich .wpilot-newsletter-block p { color: var(--wp-text-muted); margin-bottom: 16px; }
body #wpilot-footer.wpilot-footer-rich .wpilot-newsletter-form {
    display: flex; gap: 8px;
}
body #wpilot-footer.wpilot-footer-rich .wpilot-newsletter-form input {
    flex: 1; padding: 12px 16px; border: 1px solid var(--wp-border); border-radius: var(--wp-radius, 6px);
    background: var(--wp-bg); color: var(--wp-text); font-size: 0.95rem;
}
body #wpilot-footer.wpilot-footer-rich .wpilot-newsletter-form button {
    padding: 12px 24px; background: var(--wp-primary) !important; color: var(--wp-bg) !important;
    border: none; border-radius: var(--wp-radius, 6px); font-weight: 600; cursor: pointer;
    transition: background 0.3s ease;
}
body #wpilot-footer.wpilot-footer-rich .wpilot-newsletter-form button:hover { background: var(--wp-secondary) !important; }
body #wpilot-footer.wpilot-footer-rich .wpilot-footer-bottom-row {
    max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between;
    align-items: center; flex-wrap: wrap; gap: 12px;
}
@media (max-width: 900px) {
    body #wpilot-footer.wpilot-footer-rich .wpilot-footer-top { grid-template-columns: 1fr 1fr; }
    body #wpilot-footer.wpilot-footer-rich .wpilot-footer-mid { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    body #wpilot-footer.wpilot-footer-rich { padding: 50px 20px 24px; }
    body #wpilot-footer.wpilot-footer-rich .wpilot-footer-top { grid-template-columns: 1fr; gap: 30px; }
    body #wpilot-footer.wpilot-footer-rich .wpilot-footer-bottom-row { flex-direction: column; text-align: center; }
    body #wpilot-footer.wpilot-footer-rich .wpilot-newsletter-form { flex-direction: column; }
}';
            $contact_lines = '';
            if ( $address ) $contact_lines .= '<li>' . esc_html( $address ) . '</li>';
            if ( $phone )   $contact_lines .= '<li><a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a></li>';
            if ( $email )   $contact_lines .= '<li><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></li>';

            $footer_html = '
<footer id="wpilot-footer" class="wpilot-footer-rich">
    <div class="wpilot-footer-top">
        <div class="wpilot-footer-col">
            <h4>' . esc_html( $site_name ) . '</h4>
            <p>' . esc_html( $site_desc ) . '</p>
            <div class="wpilot-social-links" style="margin-top:16px;">' . $social_html . '</div>
        </div>
        <div class="wpilot-footer-col">
            <h4>Navigation</h4>
            <ul>
                <li><a href="' . esc_url( home_url('/') ) . '">Home</a></li>
                <li><a href="' . esc_url( home_url('/about') ) . '">About Us</a></li>
                <li><a href="' . esc_url( home_url('/services') ) . '">Services</a></li>
                <li><a href="' . esc_url( home_url('/shop') ) . '">Shop</a></li>
                <li><a href="' . esc_url( home_url('/blog') ) . '">Blog</a></li>
            </ul>
        </div>
        <div class="wpilot-footer-col">
            <h4>Contact</h4>
            <ul>' . $contact_lines . '</ul>
        </div>
        <div class="wpilot-footer-col">
            <h4>Support</h4>
            <ul>
                <li><a href="' . esc_url( home_url('/faq') ) . '">FAQ</a></li>
                <li><a href="' . esc_url( home_url('/privacy-policy') ) . '">Privacy Policy</a></li>
                <li><a href="' . esc_url( home_url('/terms') ) . '">Terms of Service</a></li>
                <li><a href="' . esc_url( home_url('/contact') ) . '">Contact Us</a></li>
            </ul>
        </div>
    </div>
    <div class="wpilot-footer-mid">
        <div class="wpilot-map-placeholder">
            <span>Map Embed — Replace with Google Maps iframe</span>
        </div>
        <div class="wpilot-newsletter-block">
            <h4>Subscribe to Our Newsletter</h4>
            <p>Get the latest updates, offers and news delivered straight to your inbox.</p>
            <form class="wpilot-newsletter-form" onsubmit="return false;">
                <input type="email" placeholder="Enter your email" aria-label="Email">
                <button type="submit">Subscribe</button>
            </form>
        </div>
    </div>
    <div class="wpilot-footer-bottom">
        <div class="wpilot-footer-bottom-row">
            <span>&copy; ' . $year . ' ' . esc_html( $site_name ) . '. All rights reserved.</span>
            <span>Powered by WPilot</span>
        </div>
    </div>
</footer>';
            break;
    }

    return '<style id="wpilot-footer-css">' . $base_css . $style_css . '</style>' . $footer_html;
}


// ═══════════════════════════════════════════════════════════════
//  APPLY HEADER BLUEPRINT — store + create mu-plugin
// ═══════════════════════════════════════════════════════════════
function wpilot_apply_header_blueprint( $params = [] ) {
    $style = sanitize_text_field( $params['style'] ?? $params['header'] ?? 'modern' );

    $blueprints = wpilot_get_header_blueprints();
    if ( ! isset( $blueprints[ $style ] ) ) {
        // Fuzzy match
        foreach ( $blueprints as $id => $bp ) {
            if ( stripos( $id, $style ) !== false || stripos( $bp['name'], $style ) !== false ) {
                $style = $id;
                break;
            }
        }
    }
    if ( ! isset( $blueprints[ $style ] ) ) {
        return wpilot_err( "Header blueprint \"{$style}\" not found. Available: " . implode(', ', array_keys($blueprints)) );
    }

    $html = wpilot_render_header_blueprint( $style, $params );
    if ( is_array( $html ) && isset( $html['success'] ) && ! $html['success'] ) {
        return $html; // Error from render
    }

    // Store in wp_options
    update_option( 'wpilot_custom_header', $html, false );
    update_option( 'wpilot_header_style', $style, false );

    // Create mu-plugin to inject header
    $mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );

    $mu_php = "<?php\n";
    $mu_php .= "// WPilot Custom Header — Injected via mu-plugin\n";
    $mu_php .= "// Style: {$style} | Generated: " . current_time('Y-m-d H:i') . "\n";
    $mu_php .= "if (!defined('ABSPATH')) exit;\n\n";
    $mu_php .= "// Inject custom header right after <body>\n";
    $mu_php .= "add_action('wp_body_open', function() {\n";
    $mu_php .= "    \$header = get_option('wpilot_custom_header', '');\n";
    $mu_php .= "    if (\$header) echo \$header;\n";
    $mu_php .= "}, 1);\n\n";
    $mu_php .= "// Hide ALL theme headers/navs — only wpilot-header should show\n";
    $mu_php .= "add_action('wp_head', function() {\n";
    $mu_php .= "    echo '<style id=\"wpilot-hide-theme-header\">\n';\n";
    $mu_php .= "    echo '/* Kill ALL theme headers */\n';\n";
    $mu_php .= "    echo '#masthead, #masthead.site-header,\n';\n";
    $mu_php .= "    echo '.site-header:not(#wpilot-header),\n';\n";
    $mu_php .= "    echo '#site-header:not(#wpilot-header),\n';\n";
    $mu_php .= "    echo 'header.site-header:not(#wpilot-header),\n';\n";
    $mu_php .= "    echo '.storefront-primary-navigation,\n';\n";
    $mu_php .= "    echo '.storefront-secondary-navigation,\n';\n";
    $mu_php .= "    echo '.storefront-handheld-footer-bar,\n';\n";
    $mu_php .= "    echo '.elementor-location-header,\n';\n";
    $mu_php .= "    echo '#et-main-area .et-l--header,\n';\n";
    $mu_php .= "    echo '.flavor-header,\n';\n";
    $mu_php .= "    echo '.menu-toggle,\n';\n";
    $mu_php .= "    echo '.storefront-sticky-add-to-cart\n';\n";
    $mu_php .= "    echo '{ display: none !important; visibility: hidden !important; height: 0 !important; overflow: hidden !important; }\n';\n";
    $mu_php .= "    echo '#wpilot-header { display: flex !important; }\n';\n";
    $mu_php .= "    echo '</style>';\n";
    $mu_php .= "}, 999999);\n";

    if ( function_exists( 'wpilot_mu_register' ) ) {
        $result = wpilot_mu_register( 'header', $mu_php );
    } else {
        // Fallback: write directly
        $result = file_put_contents( $mu_dir . '/wpilot-custom-header.php', $mu_php );
    }
    if ( $result === false ) {
        return wpilot_err( 'Failed to write mu-plugin file. Check file permissions on wp-content/mu-plugins/.' );
    }

    if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();

    return wpilot_ok(
        "Header blueprint \"{$blueprints[$style]['name']}\" applied! Custom header is now live and theme header is hidden.",
        [
            'style'    => $style,
            'name'     => $blueprints[$style]['name'],
            'mu_file'  => WPILOT_MU_FILENAME ?? 'wpilot-custom-header.php',
        ]
    );
}


// ═══════════════════════════════════════════════════════════════
//  APPLY FOOTER BLUEPRINT — store + create mu-plugin
// ═══════════════════════════════════════════════════════════════
function wpilot_apply_footer_blueprint( $params = [] ) {
    $style = sanitize_text_field( $params['style'] ?? $params['footer'] ?? 'columns' );

    $blueprints = wpilot_get_footer_blueprints();
    if ( ! isset( $blueprints[ $style ] ) ) {
        foreach ( $blueprints as $id => $bp ) {
            if ( stripos( $id, $style ) !== false || stripos( $bp['name'], $style ) !== false ) {
                $style = $id;
                break;
            }
        }
    }
    if ( ! isset( $blueprints[ $style ] ) ) {
        return wpilot_err( "Footer blueprint \"{$style}\" not found. Available: " . implode(', ', array_keys($blueprints)) );
    }

    $html = wpilot_render_footer_blueprint( $style, $params );
    if ( is_array( $html ) && isset( $html['success'] ) && ! $html['success'] ) {
        return $html;
    }

    // Store in wp_options
    update_option( 'wpilot_custom_footer', $html, false );
    update_option( 'wpilot_footer_style', $style, false );

    // Create mu-plugin to inject footer
    $mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );

    $mu_php = "<?php\n";
    $mu_php .= "// WPilot Custom Footer — Injected via mu-plugin\n";
    $mu_php .= "// Style: {$style} | Generated: " . current_time('Y-m-d H:i') . "\n";
    $mu_php .= "if (!defined('ABSPATH')) exit;\n\n";
    $mu_php .= "// Inject custom footer before </body>\n";
    $mu_php .= "add_action('wp_footer', function() {\n";
    $mu_php .= "    \$footer = get_option('wpilot_custom_footer', '');\n";
    $mu_php .= "    if (\$footer) echo \$footer;\n";
    $mu_php .= "}, 5);\n\n";
    $mu_php .= "// Hide ALL theme footers — only wpilot-footer should show\n";
    $mu_php .= "add_action('wp_head', function() {\n";
    $mu_php .= "    echo '<style id=\"wpilot-hide-theme-footer\">\n';\n";
    $mu_php .= "    echo '#colophon, #colophon.site-footer,\n';\n";
    $mu_php .= "    echo '.site-footer:not(#wpilot-footer),\n';\n";
    $mu_php .= "    echo 'footer.site-footer:not(#wpilot-footer),\n';\n";
    $mu_php .= "    echo '#site-footer:not(#wpilot-footer),\n';\n";
    $mu_php .= "    echo '.elementor-location-footer,\n';\n";
    $mu_php .= "    echo '#et-main-area .et-l--footer,\n';\n";
    $mu_php .= "    echo '.storefront-footer-bar,\n';\n";
    $mu_php .= "    echo '.site-info,\n';\n";
    $mu_php .= "    echo '.flavor-footer\n';\n";
    $mu_php .= "    echo '{ display: none !important; visibility: hidden !important; height: 0 !important; overflow: hidden !important; }\n';\n";
    $mu_php .= "    echo '#wpilot-footer { display: block !important; }\n';\n";
    $mu_php .= "    echo '</style>';\n";
    $mu_php .= "}, 999999);\n";

    if ( function_exists( 'wpilot_mu_register' ) ) {
        $result = wpilot_mu_register( 'footer', $mu_php );
    } else {
        // Fallback: write directly
        $result = file_put_contents( $mu_dir . '/wpilot-custom-footer.php', $mu_php );
    }
    if ( $result === false ) {
        return wpilot_err( 'Failed to write mu-plugin file. Check file permissions on wp-content/mu-plugins/.' );
    }

    if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();

    return wpilot_ok(
        "Footer blueprint \"{$blueprints[$style]['name']}\" applied! Custom footer is now live and theme footer is hidden.",
        [
            'style'    => $style,
            'name'     => $blueprints[$style]['name'],
            'mu_file'  => WPILOT_MU_FILENAME ?? 'wpilot-custom-footer.php',
        ]
    );
}


// ═══════════════════════════════════════════════════════════════
//  LIST BLUEPRINTS — tool response format
// ═══════════════════════════════════════════════════════════════
function wpilot_list_header_blueprints( $params = [] ) {
    $blueprints = wpilot_get_header_blueprints();
    $current    = get_option( 'wpilot_header_style', '' );
    $list       = [];

    foreach ( $blueprints as $id => $bp ) {
        $list[] = [
            'id'          => $id,
            'name'        => $bp['name'],
            'description' => $bp['description'],
            'active'      => ( $id === $current ),
        ];
    }

    $summary = count( $list ) . " header blueprints available:\n";
    foreach ( $list as $bp ) {
        $active  = $bp['active'] ? ' [ACTIVE]' : '';
        $summary .= "- **{$bp['name']}** ({$bp['id']}): {$bp['description']}{$active}\n";
    }

    return wpilot_ok( $summary, [ 'headers' => $list ] );
}

function wpilot_list_footer_blueprints( $params = [] ) {
    $blueprints = wpilot_get_footer_blueprints();
    $current    = get_option( 'wpilot_footer_style', '' );
    $list       = [];

    foreach ( $blueprints as $id => $bp ) {
        $list[] = [
            'id'          => $id,
            'name'        => $bp['name'],
            'description' => $bp['description'],
            'active'      => ( $id === $current ),
        ];
    }

    $summary = count( $list ) . " footer blueprints available:\n";
    foreach ( $list as $bp ) {
        $active  = $bp['active'] ? ' [ACTIVE]' : '';
        $summary .= "- **{$bp['name']}** ({$bp['id']}): {$bp['description']}{$active}\n";
    }

    return wpilot_ok( $summary, [ 'footers' => $list ] );
}


// ═══════════════════════════════════════════════════════════════
//  SOCIAL SVG HELPER — inline SVG icons for social links
// ═══════════════════════════════════════════════════════════════
function wpilot_social_svg( $platform ) {
    $icons = [
        'facebook'  => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'instagram' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'twitter'   => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'linkedin'  => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
    ];
    return $icons[ $platform ] ?? '';
}
