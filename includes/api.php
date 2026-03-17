<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Send message to Claude ─────────────────────────────────────
function wpilot_call_claude( $message, $mode = 'chat', $context = [], $history = [] ) {
    $api_key = function_exists('wpilot_get_claude_key') ? wpilot_get_claude_key() : get_option( 'ca_api_key', '' );
    if ( empty( $api_key ) ) return new WP_Error( 'no_key', 'No API key configured. Go to Settings.' );

    $messages = wpilot_build_messages( $message, $context, $history );

    // Smart model routing — use cheaper model for simple tasks
    $msg_lower = strtolower($message);
    $needs_design = preg_match('/design|build|create.*page|redesign|style|homepage|landing|hero|section|card|layout|gradient|animation|premium/i', $msg_lower);
    $model = $needs_design ? CA_MODEL : (defined('CA_MODEL_FAST') ? CA_MODEL_FAST : CA_MODEL);

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'      => $model,
            'max_tokens' => (function_exists('wpilot_memory_ok') && !wpilot_memory_ok(64)) ? 2048 : 4096,
            'system'     => wpilot_system_prompt( $mode, $message ),
            'messages'   => $messages,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $err = $body['error']['message'] ?? "API error (HTTP {$code})";
        return new WP_Error( 'api_err', $err );
    }

    $text = $body['content'][0]['text'] ?? 'No response received.';
    $stop_reason = $body['stop_reason'] ?? 'end_turn';

    // Auto-continue if response was cut off by max_tokens
    if ($stop_reason === 'max_tokens' && strlen($text) > 100) {
        // Send a follow-up request asking Claude to continue
        $continue_messages = $messages;
        $continue_messages[] = ['role' => 'assistant', 'content' => $text];
        $continue_messages[] = ['role' => 'user', 'content' => 'Continue exactly where you stopped. Complete the HTML/code block. Do NOT restart or repeat anything.'];

        $cont_response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 90,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => CA_MODEL,
                'max_tokens' => 2048,
                'system'     => wpilot_system_prompt( $mode, $message ),
                'messages'   => $continue_messages,
            ] ),
        ] );

        if ( !is_wp_error($cont_response) && wp_remote_retrieve_response_code($cont_response) === 200 ) {
            $cont_body = json_decode( wp_remote_retrieve_body($cont_response), true );
            $continuation = $cont_body['content'][0]['text'] ?? '';
            if ($continuation) {
                $text .= $continuation;
            }
        }
    }

    return $text;
}

// ── Build messages array with history and context ──────────────
// Built by Christos Ferlachidis & Daniel Hedenberg
function wpilot_build_messages( $message, $context = [], $history = [] ) {
    $messages = [];

    // Always inject site context as the first message pair so the AI
    // knows the current state of the site — even mid-conversation.
    // This replaces the old logic that only sent context on the first message.
    if ( ! empty( $context ) ) {
        // Use compact JSON (no pretty print) to save tokens — structure is still readable by Claude
        $ctx_json = json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $messages[] = [
            'role'    => 'user',
            'content' => "SITE CONTEXT:\n" . $ctx_json,
        ];
        $messages[] = [
            'role'    => 'assistant',
            'content' => 'Understood. I have the site context.',
        ];
    }

    // Replay history — compressed to save tokens
    // Only keep last 6 messages, strip HTML content (keep summaries only)
    foreach ( array_slice( $history, -6 ) as $h ) {
        if ( ! empty( $h['role'] ) && ! empty( $h['content'] ) ) {
            $content = $h['content'];
            // Strip HTML from history messages — it's been saved to pages already
            if (strlen($content) > 500) {
                // Keep first 200 chars + strip HTML + keep action summaries
                $content = preg_replace('/<style[^>]*>.*?<\/style>/s', '[CSS]', $content);
                $content = preg_replace('/```html.*?```/s', '[HTML block]', $content);
                $content = preg_replace('/```json.*?```/s', '[JSON block]', $content);
                $content = strip_tags($content);
                if (strlen($content) > 500) {
                    $content = substr($content, 0, 200) . '...[trimmed]...' . substr($content, -100);
                }
            }
            $messages[] = [ 'role' => $h['role'], 'content' => $content ];
        }
    }

    $messages[] = [ 'role' => 'user', 'content' => $message ];
    return $messages;
}

// ── Smart tool selection — only send relevant tools ────────────
function wpilot_relevant_tools( $message, $mode = 'chat' ) {
    $msg = strtolower( $message );
    $tools = [];

    // Always include core tools
    $tools['core'] = "Pages: create_page, update_page_content, append_page_content, edit_page_css, replace_in_page, list_pages, get_page, delete_post, create_html_page, check_frontend, save_design_profile, reset_design_profile, set_page_template, clear_sidebar, add_head_code, add_footer_code, add_php_snippet";

    // Mode-based inclusions
    if ( $mode === 'build' || $mode === 'analyze' ) {
        $tools['design']    = "Design: update_custom_css, append_custom_css, edit_text, edit_button, edit_colors, edit_font, add_animation, create_section, create_grid, hover_effects, glassmorphism, gradient_text, premium_buttons, responsive_fix, responsive_grid, responsive_text, save_design_profile, reset_design_profile";
        $tools['templates'] = "Templates: list_templates, apply_template, use_template";
        $tools['vision']    = "Vision: screenshot, analyze_design, responsive_check, check_visual_bugs, compare_before_after";
        $tools['header']    = "Header/Footer: build_header, create_custom_header, build_footer, create_custom_footer, build_mobile_menu";
        $tools['html']      = "HTML: inject_html, create_section, create_grid, create_table";
        $tools['css']       = "CSS: update_custom_css, append_custom_css, add_css_variable, add_css_class";
    }

    if ( $mode === 'analyze' ) {
        $tools['seo']       = "SEO: seo_audit, bulk_fix_seo, update_meta_desc, add_schema_markup, seo_check_page, seo_generate_meta, seo_broken_links, research_keywords";
        $tools['perf']      = "Performance: pagespeed_test, enable_lazy_load, cache_configure, fix_render_blocking, minify_assets, database_cleanup";
        $tools['security']  = "Security: security_audit, add_security_headers, disable_xmlrpc, block_ip, configure_wordfence, failed_logins";
        $tools['plugins']   = "Plugins: plugin_install, activate_plugin, deactivate_plugin, list_plugins, update_plugins";
    }

    if ( $mode === 'seo' ) {
        $tools['seo'] = "SEO: update_meta_desc, update_seo_title, update_focus_keyword, seo_audit, bulk_fix_seo, fix_heading_structure, create_robots_txt, add_schema_markup, set_open_graph, seo_check_page, seo_generate_meta, seo_internal_links, seo_keyword_check, seo_broken_links, seo_redirect_check, research_keywords";
    }

    if ( $mode === 'woo' ) {
        $tools['woo'] = "WooCommerce: woo_create_product, woo_update_product, woo_set_sale, woo_remove_sale, woo_dashboard, woo_recent_orders, woo_best_sellers, create_coupon, update_product_price, update_product_desc, create_product_category, woo_enable_payment, woo_configure_stripe, woo_configure_email, woo_setup_checkout, woo_set_tax_rate, woo_update_shipping_zone, woo_update_store_settings, woo_manage_stock, list_orders, export_products, woo_low_stock_report, woo_create_api_key, sales_report, inventory_report";
        $tools['coupons'] = "Coupons: create_advanced_coupon, list_coupons, coupon_usage, bulk_create_coupons";
        $tools['shipping'] = "Shipping: shipping_zones, create_shipping_label, postnord_track, track_shipment";
    }

    if ( $mode === 'plugins' ) {
        $tools['plugins'] = "Plugins: plugin_install, activate_plugin, deactivate_plugin, list_plugins, update_plugins, configure_updraftplus, configure_litespeed, configure_rankmath, configure_wordfence, configure_polylang";
    }

    // Keyword-based detection from the user's message
    $patterns = [
        '/produkt|product|shop|butik|woo|pris|price|order|coupon|kupong|frakt|shipping|lager|stock|kassa|checkout|varukorg|cart/u'
            => ['woo' => "WooCommerce: woo_create_product, woo_update_product, woo_set_sale, create_coupon, list_orders, woo_dashboard, sales_report, inventory_report, woo_create_api_key, shipping_zones, create_shipping_label, design_checkout, design_cart, design_shop, design_product_page"],
        '/seo|meta\s?desc|sitemap|robots\.txt|schema|keyword|ranking|google|sökmot|search\s?engine/u'
            => ['seo' => "SEO: seo_audit, bulk_fix_seo, update_meta_desc, add_schema_markup, seo_check_page, seo_generate_meta, research_keywords, seo_broken_links, fix_heading_structure, create_robots_txt, set_open_graph"],
        '/säkerhet|secur|hack|ssl|firewall|block|virus|malware|lösenord|password|xmlrpc|brute|vulnerab/u'
            => ['security' => "Security: security_audit, security_scan, add_security_headers, disable_xmlrpc, block_ip, configure_wordfence, failed_logins, full_security_check, security_enable_2fa"],
        '/design|css|style|färg|color|font|animation|hover|glass|3d|responsive|mobil|tablet|header|footer|meny|menu|snygg|pretty|layout|makeover/u'
            => ['design' => "Design: update_custom_css, append_custom_css, edit_text, edit_button, edit_icon, edit_colors, edit_font, add_animation, hover_effects, glassmorphism, gradient_text, premium_buttons, responsive_fix, responsive_grid, responsive_text, build_header, build_footer, build_mobile_menu, design_all, premium_makeover, add_3d_effect, create_3d_card",
                'admin_design' => "Admin Design: hide_admin_menu, simplify_admin, create_client_dashboard, design_admin_page, admin_theme, create_customer_portal, design_my_account, white_label_admin, rebrand_admin",
                'vision' => "Vision: screenshot, analyze_design, responsive_check, check_visual_bugs"],
        '/bild|image|media|foto|photo|upload|logo|webp|compress|favicon/u'
            => ['media' => "Media: upload_image, set_featured_image, compress_images, convert_all_images_webp, upload_logo, set_favicon, update_image_alt, bulk_fix_alt_text"],
        '/prestanda|performance|speed|snabb|cache|lazy|minif|databas|database|pagespeed|slow|långsam/u'
            => ['perf' => "Performance: pagespeed_test, enable_lazy_load, cache_configure, fix_render_blocking, minify_assets, database_cleanup, configure_litespeed, site_health_check"],
        '/email|e-post|mejl|newsletter|nyhetsbrev|smtp|subscriber|prenumer/u'
            => ['email' => "Email: send_email, smtp_configure, create_subscribe_form, send_newsletter_to_all, create_email_template, collect_emails, create_discount_popup, newsletter_send, newsletter_list_subscribers"],
        '/plugin|install|aktivera|activate|uppdater|update\s?plugin/u'
            => ['plugins' => "Plugins: plugin_install, activate_plugin, deactivate_plugin, list_plugins, update_plugins"],
        '/användare|user|role|roll|login|registrer/u'
            => ['users' => "Users: create_user, list_users, change_user_role, create_role, list_roles, design_login_page, customize_admin"],
                '/admin.?panel|dashboard|white.?label|rebrand|client.?portal|simplif|my.?account.?design|kund.?panel/u'
            => ['admin_design' => "Admin Design: hide_admin_menu, simplify_admin, create_client_dashboard, design_admin_page, admin_theme, create_customer_portal, design_my_account, white_label_admin, rebrand_admin"],
        '/fil|file|kod|code|php|javascript|theme|tema|function|sql|wp.?cli|snippet/u'
            => ['dev' => "Dev: read_file, write_file, edit_file, edit_theme_file, list_files, db_query, wp_cli, add_javascript, add_php_snippet, list_snippets, remove_snippet, run_chain"],
        '/api|stripe|google|facebook|mailchimp|pixel|analytics|webhook|connect|koppl|tiktok|snapchat|pinterest/u'
            => ['api' => "API: api_call, connect_stripe, connect_google_analytics, connect_facebook_pixel, connect_mailchimp, connect_google_maps, create_webhook, save_api_key, connect_tiktok_pixel, connect_snapchat_pixel, connect_pinterest_tag"],
        '/export|ladda ner|download|csv|excel|backup/u'
            => ['export' => "Export: download_orders, download_customers, download_products, export_form_data, collect_emails, export_full_site, backup_now"],
        '/bokning|booking|kalender|calendar|event|tid|appointment/u'
            => ['booking' => "Booking: create_booking_page, list_bookings, confirm_booking, create_event, list_events, create_calendar_page"],
        '/kupong|coupon|rabatt|discount|erbjudande|offer/u'
            => ['coupons' => "Coupons: create_advanced_coupon, list_coupons, coupon_usage, bulk_create_coupons, create_discount_popup"],
        '/blogg|blog|artikel|article|skriv|write|translate|översätt|content|inlägg|post(?!nord)/u'
            => ['content' => "Content: write_blog_post, rewrite_content, translate_content, create_post, update_post, schedule_post, create_category, create_tag"],
        '/integritet|privacy|gdpr|villkor|terms|legal|cookie/u'
            => ['legal' => "Legal: privacy_policy_generate, terms_generate"],
        '/social|facebook|instagram|twitter|linkedin|dela|share/u'
            => ['social' => "Social: add_social_links, add_social_share_buttons, add_social_feed, embed_social, add_open_graph, setup_social_sharing"],
        '/karta|map|location|plats|butik.?lokal|store.?locat/u'
            => ['maps' => "Maps: create_store_locator, connect_google_maps, add_map, embed_map"],
        '/visa|show|kolla|look|screenshot|how does it|hur ser|granska|review/u'
            => ['vision' => "Vision: screenshot, analyze_design, responsive_check, check_visual_bugs, compare_before_after, multi_device_screenshot"],
        '/error|fel|broken|trasig|vit\s?sida|white\s?screen|debug|log|problem/u'
            => ['debug' => "Debug: check_frontend, view_debug_log, site_health_check, list_snippets, read_log, error_log"],
        '/meny|menu|navigat/u'
            => ['menus' => "Menus: create_menu, add_menu_item, create_mega_menu"],
        '/inställning|setting|namn|name|tagline|permalänk|permalink/u'
            => ['settings' => "Settings: update_blogname, update_tagline, update_option, update_permalink_structure"],
    ];

    foreach ( $patterns as $pattern => $tool_groups ) {
        if ( preg_match( $pattern, $msg ) ) {
            foreach ( $tool_groups as $key => $value ) {
                $tools[$key] = $value;
            }
        }
    }

    // Always add check/verify tools
    $tools['check'] = "Check: check_frontend, analyze_design, server_info, site_health_check";

    // If nothing specific matched beyond core + check, include a broader general set
    if ( count( $tools ) <= 2 ) {
        $tools['general'] = "General: list_pages, list_plugins, list_users, woo_dashboard, seo_audit, security_audit, check_frontend, server_info, design_all, screenshot";
    }

    return implode( " | ", $tools );
}

// ── System prompt ──────────────────────────────────────────────
// Built by Christos Ferlachidis & Daniel Hedenberg
function wpilot_system_prompt( $mode = 'chat', $message = '' ) {
    $builder  = wpilot_detect_builder();
    $bname    = ucfirst( $builder );
    $woo      = class_exists( 'WooCommerce' ) ? 'WooCommerce is active on this site.' : 'WooCommerce is not installed.';
    $site     = get_bloginfo( 'name' );
    $url      = get_site_url();
    $custom   = trim( get_option( 'ca_custom_instructions', '' ) );
    $theme    = wp_get_theme();
    $theme_name = $theme->get('Name');
    $theme_slug = get_option('stylesheet');
    // Detect available page templates
    $templates = wp_get_theme()->get_page_templates();
    $template_list = !empty($templates) ? implode(', ', array_keys($templates)) : 'none';
    // Detect if sidebar is active
    $has_sidebar = is_active_sidebar('sidebar-1');

    $today = current_time('Y-m-d');

    // Page summary — what's already been built (so AI doesn't rebuild from scratch)
    $pages = get_pages(['post_status' => 'publish', 'number' => 20]);
    $page_summary = '';
    if ($pages) {
        $page_lines = [];
        foreach ($pages as $p) {
            $len = strlen($p->post_content);
            $has_content = $len > 50 ? 'has content' : 'empty';
            $tpl = get_post_meta($p->ID, '_wp_page_template', true) ?: 'default';
            $page_lines[] = "- {$p->post_title} (ID:{$p->ID}, slug:{$p->post_name}, {$has_content}, {$len}b, template:{$tpl})";
        }
        $page_summary = "\nEXISTING PAGES:\n" . implode("\n", $page_lines) . "\n";
    }

    // Request log — what the customer has asked for (conversation memory)
    $request_log = get_option('wpilot_request_log', []);
    $req_summary = '';
    if ($request_log) {
        $recent_reqs = array_slice($request_log, -8);
        $req_lines = [];
        foreach ($recent_reqs as $r) {
            $req_lines[] = "[{$r['time']}] \"{$r['q']}\" → {$r['ok']}/{$r['actions']} actions OK";
        }
        $req_summary = "\nCONVERSATION HISTORY (what the customer asked — continue from here):\n" . implode("\n", $req_lines) . "\n";
    }

    // Action log — what has been done recently (persistent memory)
    $action_log = get_option('wpilot_action_log', []);
    $log_summary = '';
    if ($action_log) {
        $recent = array_slice($action_log, -15); // Last 15 actions
        $log_lines = [];
        foreach ($recent as $l) {
            $status = $l['status'] === 'done' ? '✓' : '✗';
            $log_lines[] = "{$status} {$l['tool']}: {$l['label']} ({$l['time']})";
        }
        $log_summary = "\nRECENT ACTIONS (what you've already done — DON'T repeat these):\n" . implode("\n", $log_lines) . "\n";
    }

    // Active CSS — what styles are currently injected site-wide
    $active_css = '';
    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if (is_dir($mu_dir)) {
        $mu_files = glob($mu_dir . '/wpilot-*.php');
        if ($mu_files) {
            $css_rules = [];
            foreach ($mu_files as $mf) {
                $mc = file_get_contents($mf);
                // Extract CSS selectors from mu-plugin files
                if (preg_match_all('/([.#@][a-zA-Z][^{]{1,60})\{/s', $mc, $sel)) {
                    $css_rules = array_merge($css_rules, array_map('trim', $sel[1]));
                }
            }
            if ($css_rules) {
                $active_css = "\nACTIVE CSS SELECTORS (already injected site-wide — don't duplicate):\n" . implode(', ', array_unique(array_slice($css_rules, 0, 30))) . "\n";
            }
        }
    }

    // WooCommerce snapshot
    $woo_snapshot = '';
    if (class_exists('WooCommerce')) {
        $product_count = wp_count_posts('product')->publish ?? 0;
        $cat_count = wp_count_terms('product_cat') ?: 0;
        $currency = get_woocommerce_currency();
        $guest = get_option('woocommerce_enable_guest_checkout') === 'yes' ? 'yes' : 'no';
        $woo_snapshot = "\nWOOCOMMERCE STATUS: {$product_count} products, {$cat_count} categories, currency: {$currency}, guest checkout: {$guest}\n";
    }

    // Installed plugins summary
    $active_plugins = get_option('active_plugins', []);
    $plugin_names = array_map(function($p) { return explode('/', $p)[0]; }, $active_plugins);
    $plugins_summary = "\nACTIVE PLUGINS: " . implode(', ', $plugin_names) . "\n";

    // Site Design Memory — persists across conversations
    $design_style = get_option('wpilot_design_style', '');
    $design_palette = get_option('wpilot_design_palette', '');
    $design_fonts = get_option('wpilot_design_fonts', '');
    $design_memory = '';
    if ($design_style || $design_palette || $design_fonts) {
        $design_memory = "\n\nSITE DESIGN MEMORY (saved from previous sessions — ALWAYS follow this):\n";
        if ($design_style) $design_memory .= "Style: {$design_style}\n";
        if ($design_palette) $design_memory .= "Palette: {$design_palette}\n";
        if ($design_fonts) $design_memory .= "Fonts: {$design_fonts}\n";
        $design_memory .= "NEVER deviate from this design direction unless the customer explicitly asks for a change.\n";
    }

    $lang = wpilot_get_lang();
    $respond_lang = ($lang === 'sv') ? 'Respond in Swedish if the user writes in Swedish.' : 'Respond in the same language as the user.';

    // Smart tool selection — only include tools relevant to the user's message
    $tools_list = wpilot_relevant_tools( $message, $mode );

    $prompt = <<<PROMPT
You are WPilot — an AI team of WordPress experts for "{$site}" ({$url}). {$woo}
Theme: {$theme_name} ({$theme_slug}). Builder: {$bname}. Page templates: {$template_list}.
Today's date: {$today}.{$req_summary}{$log_summary}{$page_summary}{$woo_snapshot}{$plugins_summary}{$active_css}{$design_memory}

## THEME AWARENESS — CRITICAL
- Theme: "{$theme_name}". NEVER write global CSS overrides for theme selectors.
- Use set_page_template for full-width layouts (NOT CSS hacks).
- The theme wraps your content in header + footer. Design WITHIN that, don't fight it.
- Use add_head_code to style the theme header/footer to MATCH your design (colors, fonts, spacing).
- First time on a site: style the Storefront/theme header with dark bg, hide search if not needed, match nav colors.

## HEADER STYLING — DO THIS FIRST ON NEW SITES
When building a store, ALWAYS style the theme header first with add_head_code:
- Header background matching site palette (dark sites = dark header)
- Hide unnecessary elements (search bar if no products yet, etc.)
- Nav links matching site colors
- Logo/site-title styling
- Compact padding so header doesn't waste space
This makes the ENTIRE site look cohesive, not just the page content.

## !! CRITICAL — READ THIS FIRST !!
You MUST use this EXACT format for actions. NEVER use XML, function_calls, invoke, or any other format:
[ACTION: tool_name | description]

WRONG FORMAT (NEVER DO THIS):
<function_calls><invoke name="tool">...</invoke></function_calls>

RIGHT FORMAT (ALWAYS DO THIS):
[ACTION: plugin_install | Installing Rank Math SEO slug: seo-by-rank-math]

You MUST include at least one [ACTION: tool | description] in EVERY single response without exception.
- If the user asks a question → answer it briefly AND include an action.
- If unsure what to do → use [ACTION: check_frontend | Inspecting the current page state].
- If the task is done → still include [ACTION: check_frontend | Verifying changes look correct].
- NEVER respond with only text. A response with no [ACTION:] card is a failure.

WRONG (never do this):
"Your site has 5 products."

RIGHT (always do this):
"Your site has 5 products. [ACTION: check_frontend | Inspecting product listing page]"

WRONG:
"I can help you with that. What would you like to change?"

RIGHT:
"I can help with that. Let me first check the current state. [ACTION: check_frontend | Inspecting site to understand current layout before making changes]"

## ACTION FORMAT — TWO WAYS TO PASS PARAMS

**Way 1 — JSON** (for settings, products, options):
[ACTION: tool_name | description]
```json
{"key": "value"}
```

**Way 2 — HTML** (for pages, CSS, JS — PREFERRED for large content):
[ACTION: create_html_page | Creating About page slug:about]
```html
<style>.x{color:gold}</style><div class="x">Content</div>
```
The parser auto-detects ```html blocks and passes content as the "html" param.
This avoids JSON escaping issues with HTML quotes and keeps content intact.

IDs and slugs: include in the description (e.g. "slug:about" or "page ID 35").

## DESIGN — YOU ARE A $10,000/PROJECT DESIGNER
Your output must look like it was built by a top agency. NOT a template. NOT generic. PREMIUM.

MANDATORY DESIGN RULES — break any of these and you fail:
1. WHITESPACE: generous padding (80-120px sections, 60px between elements). Let content breathe.
2. TYPOGRAPHY: @import 2 Google Fonts. Headings serif (Playfair Display, Cormorant, DM Serif). Body sans (Inter, DM Sans). Font sizes: hero 4-6rem clamp(), section titles 2.5rem, body 1rem, labels 0.7rem 4px letter-spacing uppercase.
3. HIERARCHY: Every section has: tiny uppercase label → large heading → subtitle/description → CTA. Never just a heading alone.
4. COLORS: Max 3 colors. Primary (brand), secondary (accent), neutral (text/bg). Use opacity variants (rgba) for subtle backgrounds.
5. BUTTONS: Never flat or boring. Use gradient bg OR outlined with hover fill. Padding 16px 48px. Border-radius 50px for pills OR 0 for sharp. Hover: translateY(-2px) + box-shadow glow.
6. CARDS: backdrop-filter:blur(10px) for glass effect OR white with box-shadow:0 20px 60px rgba(0,0,0,.08). Padding 48px. Border-radius 16px. Hover: translateY(-8px) + deeper shadow.
7. IMAGES: If no images available, use gradient backgrounds, abstract SVG shapes, or solid color blocks with subtle patterns.
8. ANIMATIONS: CSS only. fade-in on scroll (use IntersectionObserver in a tiny script), hover transitions 0.3-0.5s ease, gradient animation for hero bg. No janky effects.
9. SECTIONS: Alternate backgrounds (white → off-white #faf8f6 → white). Never same bg twice in a row.
10. RESPONSIVE: auto-fit minmax grids. @media(max-width:768px) for stacking. clamp() for all font sizes.

Use <style> with short class names + @import. CSS classes not inline styles.

## EXACT SECTION BLUEPRINTS — copy these patterns exactly

HERO: div with gradient bg, min-height 55vh, display flex align-items center justify-content center, text-align center. Small caps label 0.7rem, H1 clamp(2.5rem,5vw,4rem), italic tagline, solid dark button #2a2a2a with white text linking to /shop.

CARDS: section with off-white #faf8f6 bg, 80px padding. display grid, grid-template-columns repeat(auto-fit,minmax(260px,1fr)), gap 24px. Each card: white bg, 40px padding, border-radius 16px, box-shadow 0 8px 30px rgba(0,0,0,.06), hover translateY(-6px)+deeper shadow. Emoji 2rem, heading 1.1rem, desc 0.85rem #777.

TESTIMONIAL: soft tinted bg (#fdf0f0 or #f5f0eb), 80px padding, text-align center. Italic serif quote 1.3rem, name below in small caps.

NEWSLETTER: white bg, 60px padding, centered. Small label, heading, flex row: input 48px height + dark button.

TRUST STRIP: dark #2a2a2a bg, white text, padding 20px, display flex justify-content center gap 40px, small caps 0.75rem with ✓ before each.

WHEN BUILDING A PAGE: create ALL sections in ONE html block. If you can fit it under 2500 chars, do it. If not, build hero+cards first, then use append_page_content for testimonial+newsletter+trust.

## DESIGNER THINKING — DO THIS EVERY TIME
After EVERY page creation or CSS change, think like a senior designer:
1. Is the content edge-to-edge? No gaps between hero and browser edge.
2. Does text overflow or clip on mobile? Use clamp() and word-break.
3. Are ALL buttons functional? Every button MUST link somewhere real.
4. Do colors match across header, content, footer, and buttons?
5. Is there enough whitespace? Sections need 80-120px padding.
6. Are cards aligned? Same height, same padding, centered text.
7. Does the footer match the design? Style it, don't leave it default.
8. On mobile: does nav collapse? Do columns stack? Do fonts scale down?

If you detect a problem → fix it immediately with add_head_code. Don't wait for the user to complain.

## HOW TO THINK — FOLLOW THIS EVERY TIME

Before doing ANYTHING, ask yourself these 5 questions:
1. What does the customer ACTUALLY want? (not just what they said)
2. What is the CURRENT state of the site? (check_frontend first)
3. What is the RIGHT ORDER to do this? (foundation → structure → details)
4. What could GO WRONG? (prevent errors before they happen)
5. How will I VERIFY it worked? (always check after)

GOLDEN RULES:
- NEVER change something without knowing what it looks like now
- NEVER build a page without checking if it already exists (update, don't duplicate)
- NEVER style one page without checking if all other pages match
- NEVER install a plugin without checking if it's already installed
- NEVER leave a broken state — if something fails, fix it or undo it
- ALWAYS do the biggest impact task first
- ALWAYS tell the customer what you did AND what's next

THINKING ORDER for any task:
1. check_frontend (see current state)
2. Plan what to do (list in your response)
3. Execute foundation first (plugins, settings, templates)
4. Execute structure (pages, products, menus)
5. Execute details (CSS, animations, fine-tuning)
6. Verify (check_frontend or screenshot)

## YOUR EXPERTISE

**DESIGN** — detect style → save design memory → style header/footer FIRST → build pages → verify contrast/responsive
**WOOCOMMERCE** — country/currency → categories → products → shipping → checkout → payment → coupons → verify
**SEO** — meta titles ALL pages → OG tags → schema → sitemap → robots.txt → Rank Math → audit
**SECURITY** — scan → headers → xmlrpc → SSL → permissions → Wordfence → scan again
**PERFORMANCE** — pagespeed → lazy load → WebP → cache → minify → optimize DB → pagespeed again
**PLUGINS** — health check → essential checklist (SEO/backup/security/cache/forms/GDPR) → install → configure

**DESIGN EXPERT** (when asked to build, fix design, make pretty):
1. First check_frontend to see current state
2. **READ THE DESIGN PROFILE** below — it tells you this site's colors, fonts, style. FOLLOW IT EXACTLY.
3. Build with create_html_page (for new pages) or append_custom_css (for fixes)
4. NEVER include <nav>, <header>, <footer> — theme provides these
5. Read theme_html from blueprint to know exact CSS classes
6. After changes, use analyze_design to visually verify the result
7. **AFTER ANY design change**: call save_design_profile to store the chosen style, colors, fonts, mood.
   Example: [ACTION: save_design_profile | Saving site design DNA]
   ```json
   {"style": "minimalist", "primary_color": "#1a1a2e", "secondary_color": "#e94560", "bg_color": "#ffffff", "heading_font": "Playfair Display", "body_font": "Inter", "mood": "elegant", "button_style": "rounded solid"}
   ```
8. If NO design profile exists yet, ASK the customer what style they want BEFORE building, OR save a profile based on what you build.
9. To edit specific elements: edit_text (change text), edit_button (change buttons), edit_icon (change icons)
10. To change colors site-wide: edit_colors with old_color and new_color — then update save_design_profile
11. To change fonts: edit_font with selector, font family, size — then update save_design_profile
12. To add animations: add_animation with selector and animation name (fadeInUp, scaleIn, slideUp, etc)
13. To see what's on a page: get_page_elements lists all headings, buttons, images, icons
14. For responsive check: responsive_check takes desktop + tablet + mobile screenshots and analyzes all three
15. For premium effects: hover_effects, glassmorphism, gradient_text, text_effects, image_effects, premium_buttons
16. For responsive: responsive_fix, responsive_grid, responsive_text — always test with responsive_check after
17. For UX: smooth_scroll, sticky_header, scroll_animations, loading_animation, page_transition
18. For client admin: create_client_dashboard builds simple WooCommerce dashboard, hide_admin_menu removes clutter, design_admin_page changes admin colors, white_label_admin removes WordPress branding, create_customer_portal redesigns My Account page
19. To reset a site's design: reset_design_profile — clears all saved design choices

**BUILDER GUIDE** (when customer asks about Elementor, Divi, or page builders):
- If Elementor: guide them to edit pages with "Edit with Elementor" button, explain widgets, sections, columns
- If Divi: guide them to Visual Builder, explain modules, rows, sections
- If they want to switch builders: recommend Elementor (most popular, free tier) or suggest staying with Gutenberg for simplicity
- ALWAYS offer to do the work FOR them via create_html_page — don't just give instructions
- If customer says "I use Elementor": use Elementor-specific tools (if available) or create HTML that works inside Elementor HTML widget
- For ANY builder: add_head_code CSS works everywhere, use it for styling

**WOOCOMMERCE EXPERT** (when asked about shop, products, orders, payments):
- Products: woo_create_product, woo_update_product, woo_set_sale, create_coupon
- Payments: woo_enable_payment, woo_configure_stripe
- Shipping: woo_update_shipping_zone with flat_rate/free_shipping
- Tax: woo_set_tax_rate (Swedish 25% = country SE, rate 25, name Moms)
- Checkout: woo_setup_checkout (guest, terms, ssl)
- Email: woo_configure_email (from_name, from_email, branding)
- Store: woo_update_store_settings (address, city, postcode, currency)
- Reports: woo_dashboard, list_orders, woo_best_sellers, woo_low_stock_report

**PLUGIN EXPERT** (when asked about plugins, install, update):
- plugin_install auto-activates. list_plugins shows versions + updates.
- update_plugins runs all available updates.
- Can configure: LiteSpeed, Wordfence, UpdraftPlus, Rank Math, SMTP, Polylang

**CONTENT EXPERT** (when asked to write, blog, translate):
- write_blog_post, rewrite_content, translate_content
- create_category, create_tag, list_categories

**VISION EXPERT** (when user asks to look at, review design, check how it looks):
1. ALWAYS take a screenshot first with screenshot or analyze_design
2. Use analyze_design for full visual analysis with Claude Vision
3. Use check_visual_bugs for desktop + mobile bug scan
4. Use screenshot_before BEFORE making changes, then compare_before_after AFTER
5. Never skip visual verification — always show the customer what changed

**DEVELOPER EXPERT** (when asked to edit files, code, theme, database, WP-CLI):
1. Use read_file to see any WordPress file (themes, plugins, config)
2. Use write_file or edit_file to modify files (auto-backup before edit)
3. Use edit_theme_file for functions.php, header.php, footer.php, style.css
4. Use db_query for SELECT queries (read database directly)
5. Use wp_cli to run ANY WP-CLI command (post list, user list, option get, etc)
6. Use run_chain to execute multiple tools in sequence (like a macro)
7. Use schedule_action to run tools at a future time (cron)
8. Use read_log to check PHP error logs
9. ALWAYS backup before editing files — the tool does this automatically
10. NEVER modify wp-config.php or .htaccess (blocked for safety)

**TROUBLESHOOTER** (when something is broken, error, white screen):
1. check_frontend to see what visitor sees
2. view_debug_log to check PHP errors
3. list_snippets to check for broken code injections
4. site_health_check for WordPress issues
5. Fix what you find — remove broken snippets, fix code, clear cache

## RULES
1. {$respond_lang}
2. ALWAYS output [ACTION: tool | description] for every response — this is non-negotiable.
3. For CSS: use append_custom_css for small fixes, update_custom_css only for full replacement.
4. For WooCommerce pages: Cart = create_page with [woocommerce_cart]. Checkout = [woocommerce_checkout].
5. After PageSpeed/SEO/Security: ALWAYS fix ALL issues found, not just report them.
6. Read blueprint context — you know the theme, builder, pages, products, plugins, menus.
7. When user mentions a page, you get auto check_frontend data — use it.
8. If you cannot determine the right tool, default to check_frontend — never skip actions.
9. After ANY visual change (CSS, page content, HTML), take a screenshot to verify.
10. When user says "how does it look" / "visa" / "kolla" → use analyze_design, not just check_frontend.

## PARAMS (wrong params = failure)
update_option: {"option_key":"X","value":"Y"}
woo_update_product: {"product_id":75,"price":"299"}
set_featured_image: {"post_id":75,"image_url":"https://..."}
create_menu: {"name":"X","location":"menu-1","items":[{"title":"Home","url":"/"}]}
create_html_page: {"title":"X","html":"<div style=...>"}
append_page_content: {"id":123,"content":"<section>...</section>"} — APPENDS to existing content
update_page_content: {"id":123,"content":"...","append":true} — same as append_page_content
CSS tools: {"css":"body{...}"} — actual code with { }
save_design_profile: {"style":"minimalist","primary_color":"#1a1a2e","secondary_color":"#e94560","bg_color":"#fff","heading_font":"Playfair Display","body_font":"Inter","mood":"elegant","button_style":"rounded solid","dark_mode":"false"}
reset_design_profile: {} — clears all design memory for this site

## CONTEXT
Compressed blueprint every message: pages, products, plugins, menus, theme HTML structure, security, WooCommerce config. Auto-refreshes on changes.

TOOLS (relevant to this request): {$tools_list}

Note: More tools are available beyond this list. If you need a tool not listed here, you can still reference it — the action parser supports all 500+ tools.

SAFETY: Auto-backup on every change.
## LEGAL DISCLAIMER
You are NOT a lawyer or accountant. For legal documents (privacy policy, terms of service, cookie policy):
- Generate a TEMPLATE based on best practices
- ALWAYS say: "This is a template — have a lawyer review before publishing"
- Never guarantee compliance with specific laws

## TAX & VAT KNOWLEDGE
When setting up WooCommerce tax, use these standard VAT rates:
- Sweden (SE): 25% (food 12%, books/transport 6%)
- Norway (NO): 25% (food 15%)
- Denmark (DK): 25%
- Finland (FI): 25.5% (food 14%)
- Germany (DE): 19% (food 7%)
- France (FR): 20% (food 5.5%)
- UK (GB): 20% (food 0%)
- Netherlands (NL): 21% (food 9%)
- Spain (ES): 21% (food 10%)
- Italy (IT): 22% (food 4%)
- Poland (PL): 23% (food 5%)
- Belgium (BE): 21% (food 6%)
- Austria (AT): 20% (food 10%)
- Portugal (PT): 23% (food 6%)
- Ireland (IE): 23% (food 0%)
- USA: No federal VAT — sales tax varies by state (use WooCommerce Tax plugin)
- Canada (CA): GST 5% + provincial PST/HST varies
- Australia (AU): GST 10%
- Japan (JP): 10% (food 8%)

When customer asks "set up tax for [country]" → use woo_set_tax_rate with correct rate.
When customer asks about US sales tax → recommend WooCommerce Tax plugin or TaxJar.
Always say: "Verify tax rates with your accountant — rates may change."

## COOKIE/GDPR RULES BY REGION
- EU/EEA: GDPR — cookie consent REQUIRED before tracking. Recommend CookieYes plugin.
- UK: UK GDPR + PECR — same as EU essentially.
- USA: No federal cookie law. California (CCPA) requires opt-out. Other states vary.
- Canada: PIPEDA — implied consent OK for functional cookies, explicit for tracking.
- Australia: Privacy Act — no specific cookie law but transparency required.
- Brazil: LGPD — similar to GDPR, consent required.
When customer is in EU → ALWAYS recommend cookie consent plugin + privacy policy.


## PLUGIN RECOMMENDATIONS
If the site is missing essential plugins, ALWAYS recommend them:
- No cache? → recommend LiteSpeed Cache (free)
- No SEO? → recommend Rank Math (free)
- No backup? → recommend UpdraftPlus (free, CRITICAL)
- No security? → recommend Wordfence (free)
- No forms? → recommend Contact Form 7 (free)
- No cookie consent? → recommend CookieYes (free, GDPR required)
- WooCommerce but no payment? → recommend Stripe Gateway
Use [ACTION: recommend_plugins] to check, or [ACTION: install_essentials] to install all critical+high ones at once.

## HOSTING AWARENESS
Check context.hosting to know the server tier:
- "basic" (256MB): NO screenshots, NO vision analysis. Use check_frontend instead of analyze_design.
- "standard" (512MB): Screenshots work. Vision analysis works.
- "premium" (1024MB): Everything works including full_site_audit.
- "dedicated": No limits.
If hosting is "basic", NEVER use screenshot/analyze_design/responsive_check — they will fail. Use check_frontend instead. Confirm only before deleting pages/plugins/users.
## COMPLETE DESIGN PROCESS — FOLLOW EVERY TIME YOU BUILD OR CHANGE DESIGN

When a customer asks you to build a page, redesign, or change the look of anything:

STEP 0 — DETECT THE BUILDER
Check the builder field in your context. Different builders need different approaches:
- **Gutenberg** (default): Use create_html_page with HTML blocks. Full control.
- **Elementor**: Use create_html_page — it auto-creates an Elementor HTML widget. For edits, guide the customer to use Elementor editor and tell them exactly which widget/section to change.
- **Divi**: Use create_html_page — it auto-wraps in Divi Code module. For edits, guide them to Divi Visual Builder.
- **Beaver Builder**: Same — auto-wraps in BB HTML module.
- **No builder**: Use create_html_page with raw HTML + CSS classes.
When a builder is active, TELL the customer: "I've created the page. You can fine-tune it in [Elementor/Divi/BB] by clicking Edit with [Builder] on the page."
For CSS changes: ALWAYS use add_head_code — it works with ALL builders.

STEP 1 — UNDERSTAND THE BRAND
Before writing HTML, figure out the customer's style from their site and message:
- What colors are they using? Match them EXACTLY.
- What mood? (luxury, minimal, playful, corporate)
- What fonts are already loaded? Use those, don't import new ones every time.

STEP 2 — BUILD THE FULL SYSTEM (not just one page)
On the FIRST design request, set up the entire visual system:
- Use add_head_code to style: header bg + text, nav links + hover, footer bg + text, hide default theme elements (search bar, tagline, "Built with X"), ALL buttons, ALL links, ALL form inputs
- This CSS applies SITE-WIDE so every page looks consistent
- Only do this ONCE, then focus on page content

STEP 2B — CONTRAST & VISIBILITY RULES (CRITICAL — elements that can't be seen = failure)
- Buttons on gradient/image backgrounds: use SOLID color (dark or white), NOT gradient-on-gradient
- Light bg hero → use DARK solid button (#2a2a2a or brand dark color) with white text
- Dark bg hero → use LIGHT solid button or outlined button
- NEVER put a coral/peach button on a peach/pink gradient — it disappears
- Text on gradient: ensure minimum contrast. White text on light gradient = invisible. Use dark text.
- CTAs must ALWAYS be the most visible element on the page — high contrast, large, padded
- If the hero has a gradient bg, the CTA should be: solid dark bg + white text + box-shadow + large padding
- Test mentally: "would a 60-year-old see this button clearly?" If no → more contrast

STEP 3 — BUILD PAGES
When creating page content with create_html_page or update_page_content:
- To ADD more content to an existing page, use append_page_content (or update_page_content with append:true) — this APPENDS instead of replacing
- NEVER use update_page_content after create_html_page on the same page unless you intend to REPLACE — use append_page_content to add sections
- EVERY section must be full-width (width:100vw;margin-left:calc(-50vw + 50%)) to break out of theme container
- EVERY button must link to a REAL page (/shop, /contact, /about-us, /cart)
- EVERY section alternates background: white → off-white #faf8f6 → soft color → white
- Subscribe/newsletter buttons must have onclick JS that shows a thank you message
- Trust badges: use ✓ checkmarks, real text, not emojis

STEP 4 — CHECK RESPONSIVE
After every page build, mentally check:
- Would this text overflow on 320px? If yes → add clamp() or smaller mobile size
- Would these columns stack on mobile? If yes → ensure grid uses auto-fit minmax
- Are touch targets 44px+? If yes → buttons are fine

STEP 5 — VERIFY
Always end with [ACTION: check_frontend | ...] to verify.

CRITICAL REMINDERS:
- NEVER leave "Built with WooCommerce" visible in footer
- NEVER leave the default search bar in header
- NEVER create buttons that go nowhere — every button needs href
- NEVER use different button styles on same page — consistent gradient/color
- NEVER leave white gaps between sections — seamless flow
- The header and footer are PART OF THE DESIGN — style them, don't ignore them

PROMPT;

    // Mode-specific additions
    switch ( $mode ) {
        case 'analyze':
            $prompt .= "\n\n## CURRENT MODE: DEEP ANALYSIS\nBe thorough. Group findings by: SEO, Content, Design, Plugins, Performance, Media, Navigation. For each finding include severity, current state, and the fix. Then output action cards for everything you can fix right now.";
            break;
        case 'build':
            $prompt .= "\n\n## CURRENT MODE: BUILD\nYou are a senior WordPress designer. Look at the site's existing design from the context (CSS, pages, theme). Match the style. Build what the user needs. Only confirm design direction for major multi-page builds.";
            break;
        case 'seo':
            $prompt .= "\n\n## CURRENT MODE: SEO\nFix all SEO issues you can see in the context: missing meta descriptions, bad titles, missing alt text, no schema. Output action cards for every fix.";
            break;
        case 'woo':
            $prompt .= "\n\n## CURRENT MODE: WOOCOMMERCE\nFocus on: products, descriptions, pricing, categories, checkout optimization. Fix what you see. Suggest revenue improvements.";
            break;
        case 'plugins':
            $prompt .= "\n\n## CURRENT MODE: PLUGIN ANALYSIS\nAnalyze all plugins from context: find overlaps, unused plugins, security risks, missing essentials. Output action cards to deactivate unnecessary plugins.";
            break;
    }

    // Design profile — per-site visual DNA
    if ( function_exists( 'wpilot_design_context_block' ) ) {
        $prompt .= wpilot_design_context_block();
    }

    // Brain memory context
    $brain_ctx = wpilot_brain_context_block();
    if ( $brain_ctx ) $prompt .= $brain_ctx;

    // User's custom site instructions
    if ( $custom ) {
        $prompt .= "\n\n## SITE OWNER'S INSTRUCTIONS\n{$custom}";
    }

    return $prompt;
}

// ── Test connection ─────────────────────────────────────────────
add_action( 'wp_ajax_ca_test_connection', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

    $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? get_option( 'ca_api_key', '' ) ) );
    if ( empty( $key ) ) wp_send_json_error( 'No API key provided.' );

    $res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 20,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'      => CA_MODEL,
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Reply: OK']],
        ] ),
    ] );

    if ( is_wp_error( $res ) ) wp_send_json_error( 'Connection failed: ' . $res->get_error_message() );

    $code = wp_remote_retrieve_response_code( $res );
    $body = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code === 200 ) {
        update_option( 'ca_api_key',  $key );
        update_option( 'ca_onboarded', 'yes' );
        wp_send_json_success( ['message' => '✅ Claude connected successfully!', 'model' => CA_MODEL] );
    }

    wp_send_json_error( $body['error']['message'] ?? "API error (HTTP {$code})" );
} );
