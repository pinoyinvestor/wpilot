<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  DESIGN BLUEPRINTS — Pre-built design recipes
//
//  Each blueprint = complete design DNA:
//    colors, fonts, spacing, mood, CSS variables, section templates
//
//  Builder-aware: outputs CSS for any builder (Elementor, Divi,
//  Gutenberg, plain HTML). The CSS targets universal selectors
//  + builder-specific overrides.
//
//  Flow:
//  1. Customer says "elegant clothing store"
//  2. AI picks best blueprint → apply_blueprint
//  3. CSS variables + global styles applied
//  4. Design profile saved automatically
//  5. All future prompts stay consistent
// ═══════════════════════════════════════════════════════════════

// ── All available blueprints ──────────────────────────────────
function wpilot_get_blueprints() {
    return [

        'dark-luxury' => [
            'name'        => 'Dark Luxury',
            'description' => 'Mörk, exklusiv design med guld-accenter. Perfekt för mode, smycken, premium-produkter.',
            'keywords'    => ['luxury','dark','gold','premium','fashion','jewelry','exclusive','elegant','high-end','smycken','mode','exklusiv','lyxig'],
            'style'           => 'dark luxury',
            'mood'            => 'exclusive and sophisticated',
            'primary_color'   => '#c9a84c',
            'secondary_color' => '#8b7355',
            'accent_color'    => '#d4af37',
            'bg_color'        => '#0a0a0a',
            'text_color'      => '#e8e8e8',
            'heading_font'    => 'Playfair Display',
            'body_font'       => 'Inter',
            'border_radius'   => '2px',
            'button_style'    => 'sharp outline gold',
            'spacing'         => 'airy',
            'dark_mode'       => 'true',
            'shadow_style'    => 'none',
            'gradient'        => 'linear-gradient(135deg, #c9a84c, #d4af37)',
            'css_variables'   => [
                '--wp-primary'    => '#c9a84c',
                '--wp-secondary'  => '#8b7355',
                '--wp-accent'     => '#d4af37',
                '--wp-bg'         => '#0a0a0a',
                '--wp-bg-alt'     => '#141414',
                '--wp-text'       => '#e8e8e8',
                '--wp-text-muted' => '#999999',
                '--wp-heading'    => '#ffffff',
                '--wp-border'     => '#2a2a2a',
                '--wp-radius'     => '2px',
                '--wp-shadow'     => 'none',
            ],
            'google_fonts'    => 'Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600',
        ],

        'white-minimal' => [
            'name'        => 'White Minimal',
            'description' => 'Ren, ljus och minimalistisk. Perfekt för portfolio, konsult, arkitektur, spa.',
            'keywords'    => ['minimal','white','clean','modern','simple','portfolio','architect','spa','studio','ren','ljus','enkel','minimalistisk'],
            'style'           => 'white minimalist',
            'mood'            => 'clean and serene',
            'primary_color'   => '#1a1a1a',
            'secondary_color' => '#555555',
            'accent_color'    => '#e8c4a0',
            'bg_color'        => '#ffffff',
            'text_color'      => '#333333',
            'heading_font'    => 'Inter',
            'body_font'       => 'Inter',
            'border_radius'   => '4px',
            'button_style'    => 'minimal solid dark',
            'spacing'         => 'airy',
            'dark_mode'       => 'false',
            'shadow_style'    => 'soft',
            'gradient'        => 'none',
            'css_variables'   => [
                '--wp-primary'    => '#1a1a1a',
                '--wp-secondary'  => '#555555',
                '--wp-accent'     => '#e8c4a0',
                '--wp-bg'         => '#ffffff',
                '--wp-bg-alt'     => '#f8f8f8',
                '--wp-text'       => '#333333',
                '--wp-text-muted' => '#888888',
                '--wp-heading'    => '#1a1a1a',
                '--wp-border'     => '#e5e5e5',
                '--wp-radius'     => '4px',
                '--wp-shadow'     => '0 2px 20px rgba(0,0,0,0.06)',
            ],
            'google_fonts'    => 'Inter:wght@300;400;500;600;700',
        ],

        'colorful-playful' => [
            'name'        => 'Colorful Playful',
            'description' => 'Färgglad och lekfull. Perfekt för barn, kreativa företag, event, mat, café.',
            'keywords'    => ['colorful','playful','fun','creative','kids','food','cafe','event','bright','party','barn','lekfull','färgglad','kreativ'],
            'style'           => 'colorful playful',
            'mood'            => 'energetic and fun',
            'primary_color'   => '#6c5ce7',
            'secondary_color' => '#fd79a8',
            'accent_color'    => '#00cec9',
            'bg_color'        => '#ffffff',
            'text_color'      => '#2d3436',
            'heading_font'    => 'Poppins',
            'body_font'       => 'Nunito',
            'border_radius'   => '16px',
            'button_style'    => 'rounded gradient bold',
            'spacing'         => 'normal',
            'dark_mode'       => 'false',
            'shadow_style'    => 'soft',
            'gradient'        => 'linear-gradient(135deg, #6c5ce7, #fd79a8)',
            'css_variables'   => [
                '--wp-primary'    => '#6c5ce7',
                '--wp-secondary'  => '#fd79a8',
                '--wp-accent'     => '#00cec9',
                '--wp-bg'         => '#ffffff',
                '--wp-bg-alt'     => '#f9f7ff',
                '--wp-text'       => '#2d3436',
                '--wp-text-muted' => '#636e72',
                '--wp-heading'    => '#2d3436',
                '--wp-border'     => '#e8e0ff',
                '--wp-radius'     => '16px',
                '--wp-shadow'     => '0 8px 30px rgba(108,92,231,0.12)',
            ],
            'google_fonts'    => 'Poppins:wght@400;500;600;700;800&family=Nunito:wght@300;400;600;700',
        ],

        'corporate-pro' => [
            'name'        => 'Corporate Professional',
            'description' => 'Professionell och pålitlig. Perfekt för B2B, konsultbolag, finans, juridik, IT.',
            'keywords'    => ['corporate','professional','business','b2b','consulting','finance','law','it','tech','agency','företag','professionell'],
            'style'           => 'corporate professional',
            'mood'            => 'trustworthy and competent',
            'primary_color'   => '#1e3a5f',
            'secondary_color' => '#2c5f8a',
            'accent_color'    => '#3498db',
            'bg_color'        => '#ffffff',
            'text_color'      => '#2c3e50',
            'heading_font'    => 'Source Sans Pro',
            'body_font'       => 'Source Sans Pro',
            'border_radius'   => '6px',
            'button_style'    => 'solid professional',
            'spacing'         => 'normal',
            'dark_mode'       => 'false',
            'shadow_style'    => 'soft',
            'gradient'        => 'linear-gradient(135deg, #1e3a5f, #2c5f8a)',
            'css_variables'   => [
                '--wp-primary'    => '#1e3a5f',
                '--wp-secondary'  => '#2c5f8a',
                '--wp-accent'     => '#3498db',
                '--wp-bg'         => '#ffffff',
                '--wp-bg-alt'     => '#f4f6f9',
                '--wp-text'       => '#2c3e50',
                '--wp-text-muted' => '#7f8c8d',
                '--wp-heading'    => '#1e3a5f',
                '--wp-border'     => '#dce4ec',
                '--wp-radius'     => '6px',
                '--wp-shadow'     => '0 4px 15px rgba(30,58,95,0.08)',
            ],
            'google_fonts'    => 'Source+Sans+Pro:wght@300;400;600;700',
        ],

        'warm-organic' => [
            'name'        => 'Warm Organic',
            'description' => 'Varm och naturlig. Perfekt för ekologiskt, hälsa, wellness, heminredning, bageri.',
            // Built by Christos Ferlachidis & Daniel Hedenberg
            'keywords'    => ['organic','warm','natural','eco','health','wellness','bakery','home','interior','wood','nature','ekologisk','hälsa','naturlig','bageri','inredning'],
            'style'           => 'warm organic',
            'mood'            => 'warm and authentic',
            'primary_color'   => '#8b6f4e',
            'secondary_color' => '#a0845c',
            'accent_color'    => '#d4a76a',
            'bg_color'        => '#faf6f1',
            'text_color'      => '#3d3028',
            'heading_font'    => 'DM Serif Display',
            'body_font'       => 'DM Sans',
            'border_radius'   => '8px',
            'button_style'    => 'rounded warm solid',
            'spacing'         => 'airy',
            'dark_mode'       => 'false',
            'shadow_style'    => 'soft',
            'gradient'        => 'none',
            'css_variables'   => [
                '--wp-primary'    => '#8b6f4e',
                '--wp-secondary'  => '#a0845c',
                '--wp-accent'     => '#d4a76a',
                '--wp-bg'         => '#faf6f1',
                '--wp-bg-alt'     => '#f0e8dd',
                '--wp-text'       => '#3d3028',
                '--wp-text-muted' => '#7a6b5d',
                '--wp-heading'    => '#3d3028',
                '--wp-border'     => '#e0d5c7',
                '--wp-radius'     => '8px',
                '--wp-shadow'     => '0 4px 20px rgba(139,111,78,0.08)',
            ],
            'google_fonts'    => 'DM+Serif+Display:wght@400&family=DM+Sans:wght@300;400;500;600;700',
        ],

        'bold-modern' => [
            'name'        => 'Bold Modern',
            'description' => 'Djärv och modern. Perfekt för startup, tech, SaaS, digitala produkter, gaming.',
            'keywords'    => ['bold','modern','startup','tech','saas','digital','gaming','app','neon','gradient','djärv','teknik'],
            'style'           => 'bold modern',
            'mood'            => 'bold and innovative',
            'primary_color'   => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'accent_color'    => '#06b6d4',
            'bg_color'        => '#0f172a',
            'text_color'      => '#e2e8f0',
            'heading_font'    => 'Space Grotesk',
            'body_font'       => 'Inter',
            'border_radius'   => '12px',
            'button_style'    => 'gradient rounded bold',
            'spacing'         => 'normal',
            'dark_mode'       => 'true',
            'shadow_style'    => 'glow',
            'gradient'        => 'linear-gradient(135deg, #6366f1, #8b5cf6, #06b6d4)',
            'css_variables'   => [
                '--wp-primary'    => '#6366f1',
                '--wp-secondary'  => '#8b5cf6',
                '--wp-accent'     => '#06b6d4',
                '--wp-bg'         => '#0f172a',
                '--wp-bg-alt'     => '#1e293b',
                '--wp-text'       => '#e2e8f0',
                '--wp-text-muted' => '#94a3b8',
                '--wp-heading'    => '#ffffff',
                '--wp-border'     => '#334155',
                '--wp-radius'     => '12px',
                '--wp-shadow'     => '0 0 30px rgba(99,102,241,0.2)',
            ],
            'google_fonts'    => 'Space+Grotesk:wght@400;500;600;700&family=Inter:wght@300;400;500;600',
        ],

        'scandinavian' => [
            'name'        => 'Scandinavian',
            'description' => 'Skandinavisk design — stilren, ljus, funktionell. Perfekt för nordiska varumärken, möbler, design.',
            'keywords'    => ['scandinavian','nordic','swedish','scandi','ikea','furniture','design','hygge','lagom','skandinavisk','nordisk','svensk','möbler'],
            'style'           => 'scandinavian',
            'mood'            => 'calm and functional',
            'primary_color'   => '#2d4a3e',
            'secondary_color' => '#5a7a6b',
            'accent_color'    => '#c08b5c',
            'bg_color'        => '#f5f3ef',
            'text_color'      => '#2d2d2d',
            'heading_font'    => 'Jost',
            'body_font'       => 'Jost',
            'border_radius'   => '4px',
            'button_style'    => 'minimal flat',
            'spacing'         => 'airy',
            'dark_mode'       => 'false',
            'shadow_style'    => 'none',
            'gradient'        => 'none',
            'css_variables'   => [
                '--wp-primary'    => '#2d4a3e',
                '--wp-secondary'  => '#5a7a6b',
                '--wp-accent'     => '#c08b5c',
                '--wp-bg'         => '#f5f3ef',
                '--wp-bg-alt'     => '#eae6df',
                '--wp-text'       => '#2d2d2d',
                '--wp-text-muted' => '#777777',
                '--wp-heading'    => '#2d4a3e',
                '--wp-border'     => '#d8d3cb',
                '--wp-radius'     => '4px',
                '--wp-shadow'     => 'none',
            ],
            'google_fonts'    => 'Jost:wght@300;400;500;600;700',
        ],

        'restaurant' => [
            'name'        => 'Restaurant & Food',
            'description' => 'Varm och inbjudande. Perfekt för restauranger, barer, catering, food trucks.',
            'keywords'    => ['restaurant','food','bar','bistro','pizza','sushi','catering','menu','dining','restaurang','mat','krog'],
            'style'           => 'restaurant warm',
            'mood'            => 'inviting and appetizing',
            'primary_color'   => '#c0392b',
            'secondary_color' => '#8e3a2e',
            'accent_color'    => '#f39c12',
            'bg_color'        => '#1a1410',
            'text_color'      => '#e8ddd0',
            'heading_font'    => 'Cormorant Garamond',
            'body_font'       => 'Lato',
            'border_radius'   => '4px',
            'button_style'    => 'warm solid',
            'spacing'         => 'normal',
            'dark_mode'       => 'true',
            'shadow_style'    => 'soft',
            'gradient'        => 'none',
            'css_variables'   => [
                '--wp-primary'    => '#c0392b',
                '--wp-secondary'  => '#8e3a2e',
                '--wp-accent'     => '#f39c12',
                '--wp-bg'         => '#1a1410',
                '--wp-bg-alt'     => '#2a2018',
                '--wp-text'       => '#e8ddd0',
                '--wp-text-muted' => '#a89888',
                '--wp-heading'    => '#f5efe8',
                '--wp-border'     => '#3a3028',
                '--wp-radius'     => '4px',
                '--wp-shadow'     => '0 4px 20px rgba(0,0,0,0.3)',
            ],
            'google_fonts'    => 'Cormorant+Garamond:wght@400;500;600;700&family=Lato:wght@300;400;700',
        ],
    ];
}

// ── Match user description to best blueprint ──────────────────
function wpilot_match_blueprint( $description ) {
    $desc_lower = strtolower( $description );
    $blueprints = wpilot_get_blueprints();
    $best_match = null;
    $best_score = 0;

    foreach ( $blueprints as $id => $bp ) {
        $score = 0;
        foreach ( $bp['keywords'] as $kw ) {
            if ( strpos( $desc_lower, strtolower( $kw ) ) !== false ) {
                $score += strlen( $kw ); // longer keyword matches = more specific
            }
        }
        // Also check name and description
        if ( stripos( $desc_lower, strtolower( $bp['name'] ) ) !== false ) $score += 20;

        if ( $score > $best_score ) {
            $best_score = $score;
            $best_match = $id;
        }
    }

    return $best_match;
}

// ── Generate CSS from a blueprint ─────────────────────────────
// Builder-aware: adds overrides for Elementor, Divi, Gutenberg
function wpilot_blueprint_css( $blueprint_id, $builder = 'none' ) {
    $blueprints = wpilot_get_blueprints();
    if ( ! isset( $blueprints[$blueprint_id] ) ) return '';

    $bp   = $blueprints[$blueprint_id];
    $vars = $bp['css_variables'];
    $hf   = $bp['heading_font'];
    $bf   = $bp['body_font'];
    $gf   = $bp['google_fonts'];

    // CSS variables on :root
    $css = "/* WPilot Blueprint: {$bp['name']} */\n";
    $css .= "@import url('https://fonts.googleapis.com/css2?family={$gf}&display=swap');\n\n";
    $css .= ":root {\n";
    foreach ( $vars as $var => $val ) {
        $css .= "  {$var}: {$val};\n";
    }
    $css .= "}\n\n";

    // Universal base styles
    $css .= "body { background: var(--wp-bg); color: var(--wp-text); font-family: '{$bf}', sans-serif; }\n";
    $css .= "h1, h2, h3, h4, h5, h6 { font-family: '{$hf}', serif; color: var(--wp-heading); }\n";
    $css .= "a { color: var(--wp-primary); }\n";
    $css .= "a:hover { color: var(--wp-secondary); }\n\n";

    // Buttons
    $css .= ".wp-block-button__link, .button, button, input[type='submit'], .woocommerce a.button, .woocommerce button.button {\n";
    $css .= "  background: var(--wp-primary); color: var(--wp-bg); border: none;\n";
    $css .= "  border-radius: var(--wp-radius); font-family: '{$bf}', sans-serif;\n";
    $css .= "  padding: 12px 28px; font-weight: 600; transition: all 0.3s ease;\n";
    $css .= "}\n";
    $css .= ".wp-block-button__link:hover, .button:hover, button:hover, .woocommerce a.button:hover, .woocommerce button.button:hover {\n";
    $css .= "  background: var(--wp-secondary); transform: translateY(-1px);\n";
    if ( $vars['--wp-shadow'] !== 'none' ) {
        $css .= "  box-shadow: var(--wp-shadow);\n";
    }
    $css .= "}\n\n";

    // Cards and sections
    $css .= ".wp-block-group, .wp-block-cover, section { border-radius: var(--wp-radius); }\n";
    $css .= "input, textarea, select { border: 1px solid var(--wp-border); border-radius: var(--wp-radius); background: var(--wp-bg-alt); color: var(--wp-text); }\n\n";

    // WooCommerce
    $css .= ".woocommerce .products .product { border-radius: var(--wp-radius); }\n";
    $css .= ".woocommerce .price { color: var(--wp-primary); font-weight: 600; }\n";
    $css .= ".woocommerce .onsale { background: var(--wp-accent); color: var(--wp-bg); border-radius: var(--wp-radius); }\n\n";

    // Builder-specific overrides
    switch ( $builder ) {
        case 'elementor':
            $css .= "/* Elementor overrides */\n";
            $css .= ".elementor-widget-heading .elementor-heading-title { font-family: '{$hf}', serif !important; color: var(--wp-heading) !important; }\n";
            $css .= ".elementor-widget-text-editor { font-family: '{$bf}', sans-serif !important; color: var(--wp-text) !important; }\n";
            $css .= ".elementor-button { background: var(--wp-primary) !important; border-radius: var(--wp-radius) !important; font-family: '{$bf}', sans-serif !important; }\n";
            $css .= ".elementor-button:hover { background: var(--wp-secondary) !important; }\n";
            $css .= ".elementor-section { border-radius: var(--wp-radius); }\n";
            $css .= ".elementor-widget-image img { border-radius: var(--wp-radius); }\n";
            $css .= ".elementor-icon { color: var(--wp-primary); }\n";
            $css .= ".elementor-counter-number { color: var(--wp-primary); font-family: '{$hf}', serif; }\n\n";
            break;

        case 'divi':
            $css .= "/* Divi overrides */\n";
            $css .= "#main-content .container { font-family: '{$bf}', sans-serif; color: var(--wp-text); }\n";
            $css .= ".et_pb_module h1, .et_pb_module h2, .et_pb_module h3, .et_pb_module h4 { font-family: '{$hf}', serif !important; color: var(--wp-heading) !important; }\n";
            $css .= ".et_pb_button { background: var(--wp-primary) !important; border-radius: var(--wp-radius) !important; border: none !important; color: var(--wp-bg) !important; }\n";
            $css .= ".et_pb_button:hover { background: var(--wp-secondary) !important; }\n";
            $css .= ".et_pb_blurb_content .et_pb_main_blurb_image .et-waypoint { color: var(--wp-primary); }\n";
            $css .= ".et_pb_pricing_table { border-radius: var(--wp-radius); }\n\n";
            break;

        case 'gutenberg':
        case 'block':
            $css .= "/* Gutenberg block overrides */\n";
            $css .= ".wp-block-cover { border-radius: var(--wp-radius); }\n";
            $css .= ".wp-block-columns .wp-block-column { border-radius: var(--wp-radius); }\n";
            $css .= ".wp-block-image img { border-radius: var(--wp-radius); }\n";
            $css .= ".wp-block-quote { border-left-color: var(--wp-primary); }\n";
            $css .= ".wp-block-pullquote { border-color: var(--wp-primary); }\n\n";
            break;
    }

    // Sections, rows, columns
    $css .= "/* Sections & Layout */\n";
    $css .= ".wpilot-section { padding: 80px 20px; max-width: 1200px; margin: 0 auto; }\n";
    $css .= ".wpilot-section-full { padding: 80px 40px; width: 100%; }\n";
    $css .= ".wpilot-section-alt { background: var(--wp-bg-alt); }\n";
    $css .= ".wpilot-row { display: flex; flex-wrap: wrap; gap: 30px; align-items: stretch; }\n";
    $css .= ".wpilot-col-2 { flex: 1 1 calc(50% - 15px); min-width: 280px; }\n";
    $css .= ".wpilot-col-3 { flex: 1 1 calc(33.333% - 20px); min-width: 250px; }\n";
    $css .= ".wpilot-col-4 { flex: 1 1 calc(25% - 23px); min-width: 220px; }\n\n";

    // Cards
    $css .= "/* Cards */\n";
    $css .= ".wpilot-card { background: var(--wp-bg); border: 1px solid var(--wp-border); border-radius: var(--wp-radius); padding: 30px; transition: all 0.3s ease; }\n";
    $css .= ".wpilot-card:hover { transform: translateY(-4px); box-shadow: var(--wp-shadow); }\n";
    $css .= ".wpilot-card img { width: 100%; border-radius: var(--wp-radius); margin-bottom: 16px; }\n";
    $css .= ".wpilot-card h3 { font-family: '{$hf}', serif; color: var(--wp-heading); margin-bottom: 12px; }\n";
    $css .= ".wpilot-card p { color: var(--wp-text-muted); line-height: 1.6; }\n\n";

    // Buttons (multiple variants)
    $css .= "/* Button variants */\n";
    $css .= ".wpilot-btn { display: inline-block; padding: 14px 32px; font-family: '{$bf}', sans-serif; font-weight: 600; text-decoration: none; border-radius: var(--wp-radius); transition: all 0.3s ease; cursor: pointer; border: none; font-size: 16px; }\n";
    $css .= ".wpilot-btn-primary { background: var(--wp-primary); color: var(--wp-bg); }\n";
    $css .= ".wpilot-btn-primary:hover { background: var(--wp-secondary); transform: translateY(-2px); }\n";
    $css .= ".wpilot-btn-secondary { background: transparent; color: var(--wp-primary); border: 2px solid var(--wp-primary); }\n";
    $css .= ".wpilot-btn-secondary:hover { background: var(--wp-primary); color: var(--wp-bg); }\n";
    $css .= ".wpilot-btn-accent { background: var(--wp-accent); color: var(--wp-bg); }\n";
    $css .= ".wpilot-btn-accent:hover { opacity: 0.9; transform: translateY(-2px); }\n";
    $css .= ".wpilot-btn-ghost { background: transparent; color: var(--wp-text); border: 1px solid var(--wp-border); }\n";
    $css .= ".wpilot-btn-ghost:hover { border-color: var(--wp-primary); color: var(--wp-primary); }\n";
    $css .= ".wpilot-btn-sm { padding: 8px 20px; font-size: 14px; }\n";
    $css .= ".wpilot-btn-lg { padding: 18px 42px; font-size: 18px; }\n\n";

    // Typography classes
    $css .= "/* Typography */\n";
    $css .= ".wpilot-heading-xl { font-family: '{$hf}', serif; font-size: clamp(2.5rem, 5vw, 4rem); color: var(--wp-heading); line-height: 1.1; }\n";
    $css .= ".wpilot-heading-lg { font-family: '{$hf}', serif; font-size: clamp(2rem, 4vw, 3rem); color: var(--wp-heading); line-height: 1.2; }\n";
    $css .= ".wpilot-heading-md { font-family: '{$hf}', serif; font-size: clamp(1.5rem, 3vw, 2rem); color: var(--wp-heading); line-height: 1.3; }\n";
    $css .= ".wpilot-text-lg { font-size: 1.25rem; line-height: 1.7; color: var(--wp-text-muted); }\n";
    $css .= ".wpilot-text-sm { font-size: 0.875rem; color: var(--wp-text-muted); }\n";
    $css .= ".wpilot-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 2px; color: var(--wp-primary); font-weight: 600; }\n\n";

    // Hero section
    $css .= "/* Hero */\n";
    $css .= ".wpilot-hero { padding: 120px 40px; text-align: center; background: var(--wp-bg); position: relative; }\n";
    $css .= ".wpilot-hero-content { max-width: 800px; margin: 0 auto; }\n";
    $css .= ".wpilot-hero h1 { font-family: '{$hf}', serif; font-size: clamp(2.5rem, 6vw, 4.5rem); color: var(--wp-heading); margin-bottom: 24px; line-height: 1.1; }\n";
    $css .= ".wpilot-hero p { font-size: 1.25rem; color: var(--wp-text-muted); margin-bottom: 32px; max-width: 600px; margin-left: auto; margin-right: auto; }\n";
    $css .= ".wpilot-hero .wpilot-btn { margin: 0 8px; }\n\n";

    // Testimonials
    $css .= "/* Testimonials */\n";
    $css .= ".wpilot-testimonial { padding: 30px; border-left: 3px solid var(--wp-primary); background: var(--wp-bg-alt); border-radius: var(--wp-radius); }\n";
    $css .= ".wpilot-testimonial p { font-style: italic; color: var(--wp-text); line-height: 1.7; margin-bottom: 16px; }\n";
    $css .= ".wpilot-testimonial cite { color: var(--wp-primary); font-weight: 600; font-style: normal; }\n\n";

    // Features grid
    $css .= "/* Feature boxes */\n";
    $css .= ".wpilot-feature { text-align: center; padding: 40px 24px; }\n";
    $css .= ".wpilot-feature-icon { font-size: 3rem; color: var(--wp-primary); margin-bottom: 20px; }\n";
    $css .= ".wpilot-feature h3 { font-family: '{$hf}', serif; margin-bottom: 12px; }\n";
    $css .= ".wpilot-feature p { color: var(--wp-text-muted); }\n\n";

    // CTA section
    $css .= "/* CTA */\n";
    $css .= ".wpilot-cta { background: var(--wp-primary); color: var(--wp-bg); padding: 80px 40px; text-align: center; border-radius: var(--wp-radius); }\n";
    $css .= ".wpilot-cta h2 { color: var(--wp-bg); font-family: '{$hf}', serif; }\n";
    $css .= ".wpilot-cta .wpilot-btn { background: var(--wp-bg); color: var(--wp-primary); }\n\n";

    // Responsive
    $css .= "/* Responsive */\n";
    $css .= "@media (max-width: 768px) {\n";
    $css .= "  .wpilot-section { padding: 50px 16px; }\n";
    $css .= "  .wpilot-hero { padding: 80px 20px; }\n";
    $css .= "  .wpilot-row { gap: 20px; }\n";
    $css .= "  .wpilot-col-2, .wpilot-col-3, .wpilot-col-4 { flex: 1 1 100%; min-width: 100%; }\n";
    $css .= "  .wpilot-btn { display: block; width: 100%; text-align: center; margin-bottom: 12px; }\n";
    $css .= "  .wpilot-hero .wpilot-btn { margin: 6px 0; }\n";
    $css .= "}\n";
    $css .= "@media (max-width: 480px) {\n";
    $css .= "  .wpilot-section { padding: 40px 12px; }\n";
    $css .= "  .wpilot-hero { padding: 60px 16px; }\n";
    $css .= "  .wpilot-card { padding: 20px; }\n";
    $css .= "}\n\n";

    // Header & footer (theme-agnostic)
    $css .= "/* Header & Navigation */\n";
    $css .= ".site-header, header, #masthead { background: var(--wp-bg); border-bottom: 1px solid var(--wp-border); }\n";
    $css .= ".site-title a, .custom-logo-link { color: var(--wp-heading); }\n";
    $css .= ".main-navigation a, .primary-menu a, nav a { color: var(--wp-text); font-family: '{$bf}', sans-serif; }\n";
    $css .= ".main-navigation a:hover, .primary-menu a:hover, nav a:hover { color: var(--wp-primary); }\n\n";

    $css .= "/* Footer */\n";
    $css .= ".site-footer, footer, #colophon { background: var(--wp-bg-alt); color: var(--wp-text-muted); border-top: 1px solid var(--wp-border); }\n";
    $css .= ".site-footer a, footer a { color: var(--wp-text); }\n";
    $css .= ".site-footer a:hover, footer a:hover { color: var(--wp-primary); }\n";

    return $css;
}

// ── Apply a blueprint to the site ─────────────────────────────
function wpilot_apply_blueprint( $params ) {
    $blueprint_id = sanitize_text_field( $params['blueprint'] ?? $params['id'] ?? $params['name'] ?? '' );
    $description  = sanitize_text_field( $params['description'] ?? '' );

    // If description given but no ID, auto-match
    if ( empty( $blueprint_id ) && ! empty( $description ) ) {
        $blueprint_id = wpilot_match_blueprint( $description );
    }

    // Normalize ID
    $blueprint_id = sanitize_title( $blueprint_id );

    $blueprints = wpilot_get_blueprints();
    if ( ! isset( $blueprints[$blueprint_id] ) ) {
        // Try fuzzy match by name
        foreach ( $blueprints as $id => $bp ) {
            if ( stripos( $id, $blueprint_id ) !== false || stripos( $bp['name'], $blueprint_id ) !== false ) {
                $blueprint_id = $id;
                break;
            }
        }
    }

    if ( ! isset( $blueprints[$blueprint_id] ) ) {
        $available = array_map( fn($id, $bp) => "{$id} ({$bp['name']})", array_keys($blueprints), $blueprints );
        return wpilot_err( "Blueprint \"{$blueprint_id}\" not found. Available: " . implode(', ', $available) );
    }

    $bp      = $blueprints[$blueprint_id];
    $builder = function_exists( 'wpilot_detect_builder' ) ? wpilot_detect_builder() : 'none';

    // 1. Generate and apply CSS
    $css = wpilot_blueprint_css( $blueprint_id, $builder );
    if ( function_exists( 'wpilot_save_css_snapshot' ) ) wpilot_save_css_snapshot();
    wp_update_custom_css_post( $css );

    // 2. Save design profile
    $profile_params = [];
    $profile_keys = ['style','mood','primary_color','secondary_color','accent_color','bg_color','text_color',
                     'heading_font','body_font','border_radius','button_style','spacing','dark_mode','shadow_style','gradient'];
    foreach ( $profile_keys as $key ) {
        if ( ! empty( $bp[$key] ) ) $profile_params[$key] = $bp[$key];
    }
    $profile_params['notes'] = "Blueprint: {$bp['name']}. Builder: {$builder}.";

    if ( function_exists( 'wpilot_save_design_profile' ) ) {
        wpilot_save_design_profile( $profile_params );
    }

    // 3. Inject Google Fonts via mu-plugin (works with any builder)
    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );
    $font_url = "https://fonts.googleapis.com/css2?family={$bp['google_fonts']}&display=swap";
    $font_php = "<?php\n// WPilot Blueprint Font Loader — {$bp['name']}\nif (!defined('ABSPATH')) exit;\nadd_action('wp_enqueue_scripts', function() {\n    wp_enqueue_style('wpilot-blueprint-fonts', '" . esc_url($font_url) . "', [], null);\n}, 5);\n";
    file_put_contents( $mu_dir . '/wpilot-blueprint-fonts.php', $font_php );

    // 4. Fire blueprint invalidation
    if ( function_exists( 'wpilot_invalidate_blueprint' ) ) wpilot_invalidate_blueprint();
    if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();

    $builder_note = $builder !== 'none' ? " Builder-specific overrides for {$builder} included." : '';

    return wpilot_ok(
        "Blueprint \"{$bp['name']}\" applied! CSS variables, fonts, buttons, WooCommerce, and {$builder} overrides all set.{$builder_note} Design profile saved — all future changes will follow this style.",
        [
            'blueprint'  => $blueprint_id,
            'name'       => $bp['name'],
            'builder'    => $builder,
            'css_length' => strlen( $css ),
            'profile'    => $profile_params,
        ]
    );
}

// ── List available blueprints ─────────────────────────────────
function wpilot_list_blueprints( $params = [] ) {
    $blueprints = wpilot_get_blueprints();
    $list = [];

    foreach ( $blueprints as $id => $bp ) {
        $list[] = [
            'id'          => $id,
            'name'        => $bp['name'],
            'description' => $bp['description'],
            'style'       => $bp['style'],
            'mood'        => $bp['mood'],
            'colors'      => "{$bp['primary_color']}, {$bp['secondary_color']}, {$bp['accent_color']}",
            'fonts'       => "{$bp['heading_font']} / {$bp['body_font']}",
            'dark'        => $bp['dark_mode'] === 'true',
        ];
    }

    // If description provided, sort by relevance
    $desc = $params['description'] ?? $params['query'] ?? '';
    if ( ! empty( $desc ) ) {
        $desc_lower = strtolower( $desc );
        usort( $list, function( $a, $b ) use ( $desc_lower, $blueprints ) {
            $score_a = 0; $score_b = 0;
            foreach ( $blueprints[$a['id']]['keywords'] as $kw ) {
                if ( strpos( $desc_lower, strtolower($kw) ) !== false ) $score_a += strlen($kw);
            }
            foreach ( $blueprints[$b['id']]['keywords'] as $kw ) {
                if ( strpos( $desc_lower, strtolower($kw) ) !== false ) $score_b += strlen($kw);
            }
            return $score_b - $score_a;
        });
    }

    $summary = count($list) . " design blueprints available:\n";
    foreach ( $list as $bp ) {
        $dark = $bp['dark'] ? ' [DARK]' : '';
        $summary .= "- **{$bp['name']}** ({$bp['id']}): {$bp['description']} | {$bp['fonts']}{$dark}\n";
    }

    return wpilot_ok( $summary, [ 'blueprints' => $list ] );
}

// ── Suggest blueprint based on site context ───────────────────
function wpilot_suggest_blueprint( $params = [] ) {
    $description = $params['description'] ?? $params['query'] ?? $params['business'] ?? '';

    // Also consider WooCommerce product categories if available
    if ( empty( $description ) && class_exists( 'WooCommerce' ) ) {
        $cats = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => true, 'fields' => 'names'] );
        if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
            $description = implode( ' ', $cats );
        }
    }

    if ( empty( $description ) ) {
        $description = get_bloginfo( 'name' ) . ' ' . get_bloginfo( 'description' );
    }

    $match = wpilot_match_blueprint( $description );
    $blueprints = wpilot_get_blueprints();

    if ( $match && isset( $blueprints[$match] ) ) {
        $bp = $blueprints[$match];
        return wpilot_ok(
            "Recommended blueprint: **{$bp['name']}** — {$bp['description']}\nStyle: {$bp['style']}, Mood: {$bp['mood']}, Colors: {$bp['primary_color']}/{$bp['secondary_color']}, Fonts: {$bp['heading_font']}/{$bp['body_font']}",
            [ 'recommended' => $match, 'blueprint' => $bp ]
        );
    }

    return wpilot_list_blueprints( $params );
}
