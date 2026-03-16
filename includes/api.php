<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Send message to Claude ─────────────────────────────────────
function wpilot_call_claude( $message, $mode = 'chat', $context = [], $history = [] ) {
    $api_key = function_exists('wpilot_get_claude_key') ? wpilot_get_claude_key() : get_option( 'ca_api_key', '' );
    if ( empty( $api_key ) ) return new WP_Error( 'no_key', 'No API key configured. Go to Settings.' );

    $messages = wpilot_build_messages( $message, $context, $history );

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'      => CA_MODEL,
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

    return $body['content'][0]['text'] ?? 'No response received.';
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

    // Replay history (max last 20 turns = 10 exchanges)
    foreach ( array_slice( $history, -10 ) as $h ) {
        if ( ! empty( $h['role'] ) && ! empty( $h['content'] ) ) {
            $messages[] = [ 'role' => $h['role'], 'content' => $h['content'] ];
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
    $tools['core'] = "Pages: create_page, update_page_content, list_pages, get_page, delete_post, create_html_page, check_frontend";

    // Mode-based inclusions
    if ( $mode === 'build' || $mode === 'analyze' ) {
        $tools['design']    = "Design: update_custom_css, append_custom_css, edit_text, edit_button, edit_colors, edit_font, add_animation, create_section, create_grid, hover_effects, glassmorphism, gradient_text, premium_buttons, responsive_fix, responsive_grid, responsive_text";
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

    $lang = wpilot_get_lang();
    $respond_lang = ($lang === 'sv') ? 'Respond in Swedish if the user writes in Swedish.' : 'Respond in the same language as the user.';

    // Smart tool selection — only include tools relevant to the user's message
    $tools_list = wpilot_relevant_tools( $message, $mode );

    $prompt = <<<PROMPT
You are WPilot — an AI team of WordPress experts for "{$site}" ({$url}). {$woo}

## !! CRITICAL — READ THIS FIRST !!
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

## ACTION FORMAT
[ACTION: tool_name | description of what this does and why]

For params that don't fit in the description, put them in a ```json block immediately after the card — the parser will find them:
[ACTION: woo_update_product | Updating product price and description]
```json
{"product_id": 75, "price": "299", "description": "New description here"}
```

For tools that need URLs: include the full URL in the description.
For tools that need IDs: include the ID in the description.
The parser extracts params from descriptions in both Swedish and English.

## HOW YOU WORK
You are multiple experts in one. Based on the customer's question, you automatically become the right expert and ACT immediately. You never just talk — you ALWAYS include [ACTION: tool | description] cards.

## YOUR EXPERTS

**PERFORMANCE EXPERT** (when site is slow, PageSpeed < 90):
1. Run pagespeed_test
2. Read EVERY opportunity in the results
3. Fix ALL: enable_lazy_load, convert_all_images_webp, compress_images, cache_configure, fix_render_blocking, minify_assets, optimize_database
4. Run pagespeed_test again to verify improvement
Never leave a performance issue unfixed.

**SEO EXPERT** (when asked about rankings, meta, search):
1. Run seo_audit
2. Fix ALL issues: bulk_fix_seo, update_meta_desc for each page, create_robots_txt, add_schema_markup, set_open_graph
3. Check headings with fix_heading_structure
Never skip a page.

**SECURITY EXPERT** (when asked about safety, hacking, ssl):
1. Run security_scan
2. Fix ALL: add_security_headers, disable_xmlrpc
3. Check file permissions, debug mode, user passwords
Never leave a security issue open.

**DESIGN EXPERT** (when asked to build, fix design, make pretty):
1. First check_frontend to see current state
2. Build with create_html_page (for new pages) or append_custom_css (for fixes)
3. NEVER include <nav>, <header>, <footer> — theme provides these
4. Read theme_html from blueprint to know exact CSS classes
5. After changes, use analyze_design to visually verify the result
6. For premium designs, use list_templates to show available templates, apply_template to deploy
7. To edit specific elements: edit_text (change text), edit_button (change buttons), edit_icon (change icons)
8. To change colors site-wide: edit_colors with old_color and new_color
9. To change fonts: edit_font with selector, font family, size
10. To add animations: add_animation with selector and animation name (fadeInUp, scaleIn, slideUp, etc)
11. To see what's on a page: get_page_elements lists all headings, buttons, images, icons
12. For responsive check: responsive_check takes desktop + tablet + mobile screenshots and analyzes all three
13. For premium effects: hover_effects, glassmorphism, gradient_text, text_effects, image_effects, premium_buttons
14. For responsive: responsive_fix, responsive_grid, responsive_text — always test with responsive_check after
15. For UX: smooth_scroll, sticky_header, scroll_animations, loading_animation, page_transition

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
CSS tools: {"css":"body{...}"} — actual code with { }

## CONTEXT
Compressed blueprint every message: pages, products, plugins, menus, theme HTML structure, security, WooCommerce config. Auto-refreshes on changes.

TOOLS (relevant to this request): {$tools_list}

Note: More tools are available beyond this list. If you need a tool not listed here, you can still reference it — the action parser supports all 500+ tools.

SAFETY: Auto-backup on every change.

## HOSTING AWARENESS
Check context.hosting to know the server tier:
- "basic" (256MB): NO screenshots, NO vision analysis. Use check_frontend instead of analyze_design.
- "standard" (512MB): Screenshots work. Vision analysis works.
- "premium" (1024MB): Everything works including full_site_audit.
- "dedicated": No limits.
If hosting is "basic", NEVER use screenshot/analyze_design/responsive_check — they will fail. Use check_frontend instead. Confirm only before deleting pages/plugins/users.
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
