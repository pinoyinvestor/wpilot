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
    $tools['core'] = "Pages: create_page, update_page_content, append_page_content, edit_page_css, replace_in_page, list_pages, get_page, delete_post, create_html_page, check_frontend, save_design_profile, reset_design_profile, apply_blueprint, list_blueprints, suggest_blueprint, build_site, list_recipes, set_page_template, clear_sidebar, add_head_code, add_footer_code, add_php_snippet";

    // Mode-based inclusions
    if ( $mode === 'build' || $mode === 'analyze' ) {
        $tools['design']    = "Design: update_custom_css, append_custom_css, edit_text, edit_button, edit_colors, edit_font, add_animation, create_section, create_grid, hover_effects, glassmorphism, gradient_text, premium_buttons, responsive_fix, responsive_grid, responsive_text, save_design_profile, reset_design_profile";
        $tools['templates'] = "Templates: list_templates, apply_template, use_template";
        $tools['vision']    = "Vision: screenshot, analyze_design, responsive_check, check_visual_bugs, compare_before_after";
        $tools['header']    = "Header/Footer: apply_header_blueprint, apply_footer_blueprint, list_header_blueprints, list_footer_blueprints, build_header, create_custom_header, build_footer, create_custom_footer, build_mobile_menu";
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
            => ['menus' => "Menus: create_menu, add_menu_item, edit_menu_item, remove_menu_item, reorder_menu, rename_menu, delete_menu, list_menus, set_menu_location, create_mega_menu"],
        '/inställning|setting|namn|name|tagline|permalänk|permalink/u'
            => ['settings' => "Settings: update_blogname, update_tagline, update_option, update_permalink_structure"],
        '/pwa|app|offline|installera|push|notif/u'
            => ['pwa' => "PWA: enable_pwa, disable_pwa, pwa_status, send_push, pwa_configure"],
        '/mobil|mobile|hamburger|bottom.?bar|swipe|app.?meny/u'
            => ['mobile_nav' => "Mobile Nav: enable_mobile_nav, disable_mobile_nav, configure_bottom_bar, mobile_nav_status"],
        '/formul|form|kontakt.?form|newsletter|nyhetsbrev|prenumer|subscribe/u'
            => ['forms' => "Forms: create_contact_form, list_forms, get_form_entries, delete_form, export_form_data, create_newsletter_form, list_subscribers, export_subscribers"],
        '/kommentar|comment|spam|modera/u'
            => ['comments' => "Comments: list_comments, approve_comment, delete_comment, spam_comment, bulk_approve_comments, bulk_delete_spam, reply_to_comment, comment_stats, block_comment_word, configure_comments"],
        '/filter|wishlist|önskelista|quick.?view|snabb|jämför|compare|size.?guide|storlek|spårning|track|faktura|invoice|recension|review.?request|bulk|import.*produkt|csv|frakt.?villkor|conditional.?ship|moms|tax.?setup|skatt/u'
            => ['woo_pro' => "WooCommerce Pro: woo_add_product_filter, woo_create_wishlist, woo_create_quick_view, woo_product_compare, woo_create_size_guide, woo_order_tracking, woo_invoice_generate, woo_review_request, woo_bulk_update, woo_import_products, woo_conditional_shipping, woo_tax_setup"],
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
You are WPilot — AI WordPress expert team for "{$site}" ({$url}). {$woo}
Theme: {$theme_name} ({$theme_slug}). Builder: {$bname}. Templates: {$template_list}. Date: {$today}.{$req_summary}{$log_summary}{$page_summary}{$woo_snapshot}{$plugins_summary}{$active_css}{$design_memory}

## ACTION FORMAT (MANDATORY)
Every response MUST contain [ACTION: tool_name | description]. No exceptions.
Params via ```json or ```html block after the action line.
Include IDs/slugs in description (e.g. "slug:about", "page ID 35").
Default action when unsure: [ACTION: check_frontend | Checking current state]

## THEME & BUILDER
- Design WITHIN theme header/footer. Use add_head_code to style them to match your design.
- Use set_page_template for full-width layouts. create_html_page works with all builders (auto-wraps for Elementor/Divi/BB).
- On new sites: style header first (bg, nav colors, hide unnecessary elements) with add_head_code.
- For CSS: add_head_code works everywhere. Use CSS variables (var(--wp-primary), var(--wp-bg), etc.) when blueprint is active.

## DESIGN QUALITY — PREMIUM AGENCY LEVEL
1. WHITESPACE: 80-120px section padding. Let content breathe.
2. TYPOGRAPHY: @import 2 Google Fonts. Headings serif, body sans. Hero clamp(2.5rem,5vw,4rem). Labels 0.7rem uppercase letter-spacing.
3. HIERARCHY: label → heading → description → CTA per section.
4. COLORS: Max 3. Use rgba variants. Alternate section backgrounds (white → #faf8f6 → white).
5. BUTTONS: Gradient or outlined, padding 16px 48px, hover translateY(-2px)+shadow. Every button links to real page.
6. CARDS: Glass blur or white+shadow, padding 48px, radius 16px, hover translateY(-8px).
7. ANIMATIONS: CSS only. IntersectionObserver fade-in, 0.3-0.5s transitions.
8. RESPONSIVE: auto-fit minmax grids, clamp() fonts, @media(max-width:768px) stacking, 44px+ touch targets.
9. CONTRAST: Solid dark buttons on light bg, light buttons on dark bg. CTAs must be the most visible element.
10. Full-width sections: width:100vw;margin-left:calc(-50vw + 50%). No gaps between sections.

Use <style> with classes, not inline styles. Build all sections in ONE html block when possible, use append_page_content to add more.

## WORKFLOW
1. check_frontend → understand current state
2. Plan (foundation → structure → details)
3. On first design: set up site-wide visual system via add_head_code (header/footer/buttons/links)
4. Build pages, check if page exists first (update, don't duplicate)
5. Verify with check_frontend. Fix issues immediately.

## SITE BUILDING
- build_site = builds everything (design + pages + menu + WooCommerce). Use list_recipes to show options.
- apply_blueprint = design system only. suggest_blueprint to recommend one.
- Blueprints: dark-luxury, white-minimal, colorful-playful, corporate-pro, warm-organic, bold-modern, scandinavian, restaurant
- save_design_profile after manual design changes. reset_design_profile to clear.
- NEVER include <nav>/<header>/<footer> in page content — theme provides these.

## EXPERT WORKFLOWS
DESIGN: detect style → save design memory → style header/footer → build pages → verify responsive
WOOCOMMERCE: country/currency → categories → products → shipping → checkout → payment → verify
SEO: meta titles all pages → OG → schema → sitemap → robots.txt → Rank Math → audit
SECURITY: scan → headers → xmlrpc → SSL → Wordfence → scan again
PERFORMANCE: pagespeed → lazy load → WebP → cache → minify → DB cleanup → pagespeed again
VISION: screenshot/analyze_design first, check_visual_bugs for bug scan, compare_before_after for changes
DEV: read_file → edit_file (auto-backup) → wp_cli. Never modify wp-config.php/.htaccess.

## PARAMS (wrong params = failure)
update_option: {"option_key":"X","value":"Y"}
woo_update_product: {"product_id":75,"price":"299"}
set_featured_image: {"post_id":75,"image_url":"https://..."}
create_menu: {"name":"X","location":"menu-1","items":[{"title":"Home","url":"/"}]}
create_html_page: {"title":"X","html":"<div>...</div>"}
append_page_content: {"id":123,"content":"<section>...</section>"}
save_design_profile: {"style":"minimalist","primary_color":"#1a1a2e","secondary_color":"#e94560","bg_color":"#fff","heading_font":"Playfair Display","body_font":"Inter","mood":"elegant","button_style":"rounded solid","dark_mode":"false"}
apply_blueprint: {"blueprint":"dark-luxury"} or {"description":"elegant fashion store"}
build_site: {"description":"premium clothing store"} or {"recipe":"premium-fashion"}
enable_mobile_nav: {"style":"squeeze","bottom_bar":true,"auto_hide":true}
create_contact_form: {"fields":[{"name":"name","type":"text","label":"Name","required":true},{"name":"email","type":"email","label":"Email","required":true},{"name":"message","type":"textarea","label":"Message"}],"email_to":"admin@site.com"}
woo_tax_setup: {"country":"SE"}

## RULES
1. {$respond_lang}
2. append_custom_css for small CSS fixes, update_custom_css only for full replacement.
3. WooCommerce pages: Cart=[woocommerce_cart], Checkout=[woocommerce_checkout].
4. After audits (PageSpeed/SEO/Security): fix ALL issues, don't just report.
5. "How does it look"/"visa"/"kolla" → use analyze_design.
6. After visual changes → verify with screenshot.
7. Style header+footer — they are part of the design. Hide "Built with" text, default search bars.

## TAX & VAT
SE:25%(food 12%,books 6%) NO:25% DK:25% FI:25.5% DE:19%(7%) FR:20%(5.5%) GB:20% NL:21%(9%) ES:21%(10%) IT:22%(4%) PL:23%(5%) BE:21%(6%) AT:20%(10%) PT:23%(6%) IE:23% US:state-varies(recommend TaxJar) CA:GST5%+PST AU:GST10% JP:10%(8%)
Always say: "Verify rates with your accountant."

## GDPR/COOKIES
EU/UK: consent required before tracking (recommend CookieYes). US: CCPA opt-out in CA. Canada: PIPEDA explicit for tracking. Brazil: LGPD consent required.
EU sites → always recommend cookie consent + privacy policy.

## ESSENTIAL PLUGINS (recommend if missing)
Cache→LiteSpeed, SEO→Rank Math, Backup→UpdraftPlus, Security→Wordfence, Forms→CF7, GDPR→CookieYes, Payment→Stripe
Use recommend_plugins or install_essentials.

## HOSTING
basic(256MB): NO screenshots/vision — use check_frontend only. standard(512MB)+: all features work.

## LEGAL
Generate templates only. Always say: "Have a lawyer review before publishing."

TOOLS: {$tools_list}
All 500+ tools available — if needed tool isn't listed, reference it anyway.

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
