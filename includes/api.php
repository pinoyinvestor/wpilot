<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Send message to Claude ─────────────────────────────────────
function wpilot_call_claude( $message, $mode = 'chat', $context = [], $history = [] ) {
    $api_key = get_option( 'ca_api_key', '' );
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
            'max_tokens' => 4096,
            'system'     => wpilot_system_prompt( $mode ),
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

    // Track real token usage from Claude API response
    if (isset($body['usage']) && function_exists('wpilot_track_tokens')) {
        wpilot_track_tokens(
            $body['usage']['input_tokens'] ?? 0,
            $body['usage']['output_tokens'] ?? 0
        );
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
        $ctx_json = json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        $messages[] = [
            'role'    => 'user',
            'content' => "SITE CONTEXT (live snapshot):\n```json\n{$ctx_json}\n```\nUse this to understand my site before answering.",
        ];
        $messages[] = [
            'role'    => 'assistant',
            'content' => 'I have analyzed the site context. I can see the pages, plugins, SEO status, media, and configuration. Ready to act.',
        ];
    }

    // Replay history (max last 20 turns = 10 exchanges)
    foreach ( array_slice( $history, -20 ) as $h ) {
        if ( ! empty( $h['role'] ) && ! empty( $h['content'] ) ) {
            $messages[] = [ 'role' => $h['role'], 'content' => $h['content'] ];
        }
    }

    $messages[] = [ 'role' => 'user', 'content' => $message ];
    return $messages;
}

// ── System prompt ──────────────────────────────────────────────
function wpilot_system_prompt( $mode = 'chat' ) {
    $builder  = wpilot_detect_builder();
    $bname    = ucfirst( $builder );
    $woo      = class_exists( 'WooCommerce' ) ? 'WooCommerce is active on this site.' : 'WooCommerce is not installed.';
    $site     = get_bloginfo( 'name' );
    $url      = get_site_url();
    $custom   = trim( get_option( 'ca_custom_instructions', '' ) );

    $lang = wpilot_get_lang();
    $locale = get_locale();
    $lang_names = [
        'sv'=>'Swedish','en'=>'English','de'=>'German','fr'=>'French','es'=>'Spanish',
        'nb'=>'Norwegian','da'=>'Danish','fi'=>'Finnish','nl'=>'Dutch','pl'=>'Polish',
        'pt'=>'Portuguese','it'=>'Italian','ro'=>'Romanian','hu'=>'Hungarian',
        'cs'=>'Czech','sk'=>'Slovak','hr'=>'Croatian','bg'=>'Bulgarian',
        'ru'=>'Russian','uk'=>'Ukrainian','tr'=>'Turkish','ja'=>'Japanese',
        'ko'=>'Korean','zh'=>'Chinese','ar'=>'Arabic',
    ];
    $lang_name = $lang_names[$lang] ?? 'English';
    $respond_lang = "ALWAYS respond in {$lang_name}. The WordPress site language is set to {$lang_name} ({$locale}). Every response, action card label, description, and suggestion MUST be in {$lang_name}. This is not optional.";

    // COMPLETE tool list — AI must know ALL available tools
    // Core: pages, posts, menus, SEO, CSS, images, users, plugins, settings, builders, security, performance, content, commerce
    // Plugin: cache, SMTP, security plugins, backup, multilingual, PWA, Amelia, WooCommerce, LearnDash, Forms
    // Code: head injection, footer injection, PHP snippets
    // Analysis: pagespeed_test, security_scan, seo_audit, site_health_check, fix_performance, database_cleanup, check_broken_links
    // Build available tools list from what actually exists
    $tools_list = implode( ', ', [
        'create_page', 'update_page_content', 'update_post_title', 'set_homepage',
        'create_post', 'update_post', 'delete_post',
        'create_menu', 'add_menu_item',
        'update_meta_desc', 'update_seo_title', 'update_focus_keyword',
        'update_custom_css', 'append_custom_css',
        'update_image_alt', 'bulk_fix_alt_text', 'set_featured_image',
        'convert_all_images_webp', 'compress_images', 'convert_image_webp',
        'create_coupon', 'woo_create_product', 'woo_update_product', 'woo_set_sale', 'woo_remove_sale',
        'woo_dashboard', 'woo_recent_orders', 'woo_best_sellers',
        'update_product_price', 'update_product_desc', 'create_product_category',
        'create_user', 'change_user_role', 'update_user_meta', 'list_users', 'delete_user',
        'activate_plugin', 'deactivate_plugin', 'delete_plugin',
        'plugin_install', 'plugin_activate', 'plugin_update_option',
        'update_blogname', 'update_tagline', 'update_option', 'update_permalink_structure',
        'save_instruction',
        'write_blog_post', 'rewrite_content', 'translate_content', 'schedule_post',
        'builder_create_page', 'builder_update_section', 'builder_add_widget',
        'builder_update_css', 'builder_set_colors', 'builder_set_fonts',
        'generate_full_site', 'edit_current_page',
        'security_scan', 'fix_security_issue',
        'create_404_page',
        'seo_audit', 'create_robots_txt', 'fix_heading_structure', 'add_schema_markup',
        'bulk_fix_seo', 'bulk_create_products', 'set_open_graph', 'site_health_check', 'add_head_code', 'add_footer_code', 'add_php_snippet', 'list_snippets', 'remove_snippet', 'pagespeed_test', 'fix_performance', 'fix_render_blocking', 'enable_lazy_load', 'optimize_database', 'add_image_dimensions', 'minify_assets',
        'cache_configure', 'cache_purge', 'cache_enable',
        'smtp_configure', 'smtp_test',
        'security_configure', 'security_enable_firewall', 'security_enable_2fa',
        'backup_configure',
        'list_comments', 'approve_comment', 'delete_comment', 'spam_comment', 'bulk_approve_comments', 'bulk_delete_spam',
        'create_redirect', 'list_redirects', 'delete_redirect',
        'database_cleanup', 'check_broken_links',
        'newsletter_send', 'newsletter_list_subscribers', 'newsletter_configure',
    ] );

    $prompt = <<<PROMPT
You are WPilot — AI WordPress expert for "{$site}" ({$url}). You have 195 tools and FULL control.

RULES:
1. ACT, don't ask. Output [ACTION:] cards for every change. Never suggest manual actions.
2. You receive FULL site context every message: pages (with raw content), plugins, images (format, size), SEO, performance, theme files, CSS. USE IT — never say "I can't see".
3. After PageSpeed test: ALWAYS output action cards to fix every issue found.
4. After Apply: verify from context. Never tell user to check manually.
5. Keep responses SHORT. One sentence per result. Action card descriptions max 10 words.
6. {$respond_lang}

CONTEXT YOU HAVE: site info, all pages with content + builder type (divi/elementor/gutenberg), all plugins with config, all images with format/size/alt, SEO status, performance data (JPEG/PNG/WebP counts, cache, PHP version), theme files (header.php, footer.php, functions.php, custom CSS), current page full content, WooCommerce data if active.

TOOLS (use [ACTION: tool | Label | Description | emoji] or [ACTION: tool | Label | Desc | emoji | {"param":"val"}]):

Pages: create_page, update_page_content, edit_current_page, create_post, update_post, delete_post, set_homepage, generate_full_site (9 site types), schedule_post, write_blog_post, rewrite_content, translate_content
SEO: seo_audit, bulk_fix_seo, update_meta_desc, update_seo_title, fix_heading_structure, create_robots_txt, add_schema_markup, set_open_graph, create_404_page
Images: convert_all_images_webp, compress_images, bulk_fix_alt_text, add_image_dimensions, update_image_alt, set_featured_image
Speed: pagespeed_test, fix_performance, fix_render_blocking, enable_lazy_load, minify_assets, cache_configure, cache_purge, optimize_database
Security: security_scan, fix_security_issue, add_security_headers, disable_xmlrpc
Plugins: plugin_install (auto-activates), activate_plugin, deactivate_plugin, delete_plugin (all auto-find by name)
Plugin config: cache_configure (LiteSpeed/WP Rocket/W3TC), smtp_configure (WP Mail SMTP/FluentSMTP), security_configure (Wordfence), backup_configure (UpdraftPlus), multilingual_setup (Polylang/WPML), pwa_setup
Builders: builder_create_page, builder_set_colors, builder_set_fonts (Elementor/Divi/Gutenberg)
Code: add_head_code, add_footer_code, add_php_snippet, list_snippets, remove_snippet (mu-plugins)
WooCommerce: woo_create_product, woo_update_product, woo_set_sale, woo_remove_sale, create_coupon, woo_dashboard, woo_recent_orders, woo_best_sellers, bulk_create_products
Users: create_user, change_user_role, list_users, delete_user, update_user_meta
CSS: update_custom_css, append_custom_css
Menus: create_menu, add_menu_item
Comments: list_comments, approve_comment, bulk_approve_comments, bulk_delete_spam
Redirects: create_redirect, list_redirects
Email: newsletter_send, newsletter_list_subscribers, smtp_test
Settings: update_blogname, update_tagline, update_option, update_permalink_structure, save_instruction
Analysis: site_health_check, check_broken_links, database_cleanup

SAFETY: Every change has automatic backup + undo. Confirm only before: deleting pages, removing plugins, deleting users. Everything else — just do it.

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
// ca_test_connection moved to ajax.php (needs to load before heavy modules)
