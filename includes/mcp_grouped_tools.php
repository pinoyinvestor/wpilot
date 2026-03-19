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

// Guardian anti-copy check
if ( function_exists( "wpilot_guardian_runtime_check" ) && ! wpilot_guardian_runtime_check() ) return;


/**
 * WPilot MCP Server — Grouped Tool Definitions & Router
 * Maps ~45 grouped tools to 700+ existing individual tools.
 * Each grouped tool has an 'action' parameter that selects the operation.
 */

/**
 * Return all grouped tool definitions for MCP tools/list.
 */
function wpilot_mcp_grouped_tool_definitions() {
    static $cached = null;
    if ( $cached !== null ) return $cached;

    $tools = [];

    // ── 1. Pages ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'pages',
        'description' => 'Manage WordPress pages — create, read, update, delete, list, search, duplicate.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['create','read','update','delete','list','search','duplicate'], 'description' => 'Operation to perform' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Page ID (for read/update/delete/duplicate)' ],
                'title'   => [ 'type' => 'string', 'description' => 'Page title (for create)' ],
                'content' => [ 'type' => 'string', 'description' => 'HTML content (for create/update)' ],
                'status'  => [ 'type' => 'string', 'enum' => ['draft','publish','private','pending'], 'description' => 'Page status' ],
                'search'  => [ 'type' => 'string', 'description' => 'Text to find (for search action or find-replace)' ],
                'replace' => [ 'type' => 'string', 'description' => 'Replacement text (for search action with find-replace)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 2. Posts ──────────────────────────────────────────────
    $tools[] = [
        'name' => 'posts',
        'description' => 'Manage blog posts — create, read, update, delete, list, publish, unpublish.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['create','read','update','delete','list','publish','unpublish'], 'description' => 'Operation' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Post ID' ],
                'title'   => [ 'type' => 'string', 'description' => 'Post title' ],
                'content' => [ 'type' => 'string', 'description' => 'Post content (HTML)' ],
                'status'  => [ 'type' => 'string', 'enum' => ['draft','publish','private','pending'] ],
                'category'=> [ 'type' => 'string', 'description' => 'Category name or ID' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 3. Media ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'media',
        'description' => 'Manage media library — upload images from URL, list, delete, search.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['upload','list','delete','search'], 'description' => 'Operation' ],
                'url'    => [ 'type' => 'string', 'description' => 'Image URL to upload' ],
                'id'     => [ 'type' => 'integer', 'description' => 'Media ID (for delete)' ],
                'query'  => [ 'type' => 'string', 'description' => 'Search query' ],
                'alt'    => [ 'type' => 'string', 'description' => 'Alt text for uploaded image' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 4. Menus ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'menus',
        'description' => 'Manage navigation menus — create, list items, add/remove items, set location, rename.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['create','list','add_item','remove_item','delete','rename','set_location'], 'description' => 'Operation' ],
                'name'     => [ 'type' => 'string', 'description' => 'Menu name' ],
                'menu_id'  => [ 'type' => 'integer', 'description' => 'Menu ID' ],
                'title'    => [ 'type' => 'string', 'description' => 'Menu item title' ],
                'url'      => [ 'type' => 'string', 'description' => 'Menu item URL' ],
                'page_id'  => [ 'type' => 'integer', 'description' => 'Page ID to link' ],
                'item_id'  => [ 'type' => 'integer', 'description' => 'Menu item ID (for remove)' ],
                'location' => [ 'type' => 'string', 'description' => 'Theme location (primary, footer, etc)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 5. Plugins ───────────────────────────────────────────
    $tools[] = [
        'name' => 'plugins',
        'description' => 'Manage WordPress plugins — install from repository, activate, deactivate, update, remove, list installed.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['install','activate','deactivate','update','remove','list','search'], 'description' => 'Operation' ],
                'slug'   => [ 'type' => 'string', 'description' => 'Plugin slug (e.g. "wordfence", "contact-form-7")' ],
                'plugin' => [ 'type' => 'string', 'description' => 'Plugin file path (e.g. "wordfence/wordfence.php")' ],
            ],
            'required' => ['action'],
        ],
    ];

    // Built by Weblease

    // ── 6. Themes ────────────────────────────────────────────
    $tools[] = [
        'name' => 'themes',
        'description' => 'Manage WordPress themes — list installed, activate, install new.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['list','activate','install'], 'description' => 'Operation' ],
                'slug'   => [ 'type' => 'string', 'description' => 'Theme slug' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 7. CSS ───────────────────────────────────────────────
    $tools[] = [
        'name' => 'css',
        'description' => 'Manage custom CSS — get current CSS, append rules, replace all, or reset.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['get','append','replace','reset'], 'description' => 'Operation' ],
                'css'    => [ 'type' => 'string', 'description' => 'CSS code (for append/replace)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 8. Users ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'users',
        'description' => 'Manage WordPress users — list, create, update, delete, change roles.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['list','create','update','delete','change_role','reset_password'], 'description' => 'Operation' ],
                'id'       => [ 'type' => 'integer', 'description' => 'User ID' ],
                'username' => [ 'type' => 'string' ],
                'email'    => [ 'type' => 'string' ],
                'password' => [ 'type' => 'string' ],
                'role'     => [ 'type' => 'string', 'enum' => ['administrator','editor','author','contributor','subscriber'] ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 9. Settings ──────────────────────────────────────────
    $tools[] = [
        'name' => 'settings',
        'description' => 'Manage WordPress settings — site title, tagline, permalinks, any option.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['get','update','blogname','tagline','permalink'], 'description' => 'Operation' ],
                'option' => [ 'type' => 'string', 'description' => 'Option name (for get/update)' ],
                'value'  => [ 'type' => 'string', 'description' => 'New value' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 10. Comments ─────────────────────────────────────────
    $tools[] = [
        'name' => 'comments',
        'description' => 'Manage comments — list, approve, delete, mark spam, reply, stats.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'     => [ 'type' => 'string', 'enum' => ['list','approve','delete','spam','reply','stats'], 'description' => 'Operation' ],
                'comment_id' => [ 'type' => 'integer', 'description' => 'Comment ID' ],
                'content'    => [ 'type' => 'string', 'description' => 'Reply content' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 11. Categories & Tags ────────────────────────────────
    $tools[] = [
        'name' => 'taxonomies',
        'description' => 'Manage categories and tags — create, list, assign to posts, delete.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['create_category','list_categories','create_tag','list_tags','assign','delete'], 'description' => 'Operation' ],
                'name'     => [ 'type' => 'string', 'description' => 'Category or tag name' ],
                'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID to assign to' ],
                'id'       => [ 'type' => 'integer', 'description' => 'Term ID (for delete)' ],
                'taxonomy' => [ 'type' => 'string', 'enum' => ['category','post_tag'], 'description' => 'Taxonomy type' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 12. WooCommerce Products ─────────────────────────────
    $tools[] = [
        'name' => 'woo_products',
        'description' => 'Manage WooCommerce products — create, read, update, delete, list, search, import, duplicate, set images, variations.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'      => [ 'type' => 'string', 'enum' => ['create','read','update','delete','list','search','import','duplicate','set_image','gallery','variation','set_price','set_sale','remove_sale','set_stock'], 'description' => 'Operation' ],
                'id'          => [ 'type' => 'integer', 'description' => 'Product ID' ],
                'title'       => [ 'type' => 'string', 'description' => 'Product name' ],
                'description' => [ 'type' => 'string', 'description' => 'Product description' ],
                'price'       => [ 'type' => 'string', 'description' => 'Regular price' ],
                'sale_price'  => [ 'type' => 'string', 'description' => 'Sale price' ],
                'sku'         => [ 'type' => 'string' ],
                'stock'       => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
                'category'    => [ 'type' => 'string', 'description' => 'Product category' ],
                'image_url'   => [ 'type' => 'string', 'description' => 'Product image URL' ],
                'status'      => [ 'type' => 'string', 'enum' => ['draft','publish','private'] ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 13. WooCommerce Orders ───────────────────────────────
    $tools[] = [
        'name' => 'woo_orders',
        'description' => 'Manage WooCommerce orders — list, view details, update status, refund, tracking, statistics.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['list','get','update_status','refund','receipt','tracking','stats'], 'description' => 'Operation' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Order ID' ],
                'status'  => [ 'type' => 'string', 'description' => 'New order status' ],
                'amount'  => [ 'type' => 'string', 'description' => 'Refund amount' ],
                'reason'  => [ 'type' => 'string', 'description' => 'Refund reason' ],
                'limit'   => [ 'type' => 'integer', 'description' => 'Number of orders to return' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 14. WooCommerce Coupons ──────────────────────────────
    $tools[] = [
        'name' => 'woo_coupons',
        'description' => 'Manage WooCommerce coupons — create, list, update, delete, usage stats.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'        => [ 'type' => 'string', 'enum' => ['create','list','update','delete','stats'], 'description' => 'Operation' ],
                'id'            => [ 'type' => 'integer', 'description' => 'Coupon ID' ],
                'code'          => [ 'type' => 'string', 'description' => 'Coupon code' ],
                'discount_type' => [ 'type' => 'string', 'enum' => ['percent','fixed_cart','fixed_product'] ],
                'amount'        => [ 'type' => 'string', 'description' => 'Discount amount' ],
                'usage_limit'   => [ 'type' => 'integer' ],
                'expiry_date'   => [ 'type' => 'string', 'description' => 'Expiration date (YYYY-MM-DD)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 15. WooCommerce Shipping ─────────────────────────────
    $tools[] = [
        'name' => 'woo_shipping',
        'description' => 'Manage WooCommerce shipping — zones, methods, rates, classes.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['zones','methods','rates','classes','update_zone','create_class'], 'description' => 'Operation' ],
                'zone_id'=> [ 'type' => 'integer' ],
                'name'   => [ 'type' => 'string' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 16. WooCommerce Settings ─────────────────────────────
    $tools[] = [
        'name' => 'woo_settings',
        'description' => 'Configure WooCommerce — store settings, checkout, payments, tax, email templates.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['store','checkout','payments','tax','emails','tax_rates'], 'description' => 'Operation' ],
                'setting' => [ 'type' => 'string', 'description' => 'Setting key' ],
                'value'   => [ 'type' => 'string', 'description' => 'Setting value' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 17. WooCommerce Customers ────────────────────────────
    $tools[] = [
        'name' => 'woo_customers',
        'description' => 'Manage WooCommerce customers — list, view details, stats, export.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['list','get','stats','report','export'], 'description' => 'Operation' ],
                'id'     => [ 'type' => 'integer', 'description' => 'Customer/user ID' ],
                'email'  => [ 'type' => 'string', 'description' => 'Customer email' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 18. WooCommerce Reports ──────────────────────────────
    $tools[] = [
        'name' => 'woo_reports',
        'description' => 'WooCommerce reports — sales, stock, revenue, best sellers, low stock, tax summary.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['sales','stock','revenue','best_sellers','low_stock','tax'], 'description' => 'Report type' ],
                'period' => [ 'type' => 'string', 'enum' => ['today','week','month','year','all'], 'description' => 'Time period' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 19. SEO ──────────────────────────────────────────────
    $tools[] = [
        'name' => 'seo',
        'description' => 'SEO management — audit, meta titles/descriptions, schema markup, sitemap, robots.txt, Open Graph, keywords, link checking.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'      => [ 'type' => 'string', 'enum' => ['audit','meta_title','meta_desc','schema','sitemap','robots','opengraph','keywords','broken_links','internal_links','check_page'], 'description' => 'Operation' ],
                'id'          => [ 'type' => 'integer', 'description' => 'Page/post ID' ],
                'title'       => [ 'type' => 'string', 'description' => 'Meta title' ],
                'description' => [ 'type' => 'string', 'description' => 'Meta description' ],
                'keyword'     => [ 'type' => 'string', 'description' => 'Focus keyword' ],
                'schema_type' => [ 'type' => 'string', 'description' => 'Schema type (LocalBusiness, Product, etc)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 20. Forms ────────────────────────────────────────────
    $tools[] = [
        'name' => 'forms',
        'description' => 'Manage contact forms, subscription forms, newsletter forms — create, list, view entries, delete.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['create','list','entries','delete'], 'description' => 'Operation' ],
                'type'    => [ 'type' => 'string', 'enum' => ['contact','subscribe','newsletter','custom'], 'description' => 'Form type' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Form ID' ],
                'title'   => [ 'type' => 'string', 'description' => 'Form title' ],
                'fields'  => [ 'type' => 'string', 'description' => 'Comma-separated field names' ],
                'email'   => [ 'type' => 'string', 'description' => 'Recipient email' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 21. Security ─────────────────────────────────────────
    $tools[] = [
        'name' => 'security',
        'description' => 'WordPress security — scan, audit, headers, firewall, 2FA, XML-RPC, block/unblock IPs, login attempts.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['scan','audit','headers','firewall','two_factor','xmlrpc','block_ip','unblock_ip','list_blocked','failed_logins'], 'description' => 'Operation' ],
                'ip'     => [ 'type' => 'string', 'description' => 'IP address (for block/unblock)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 22. Performance ──────────────────────────────────────
    $tools[] = [
        'name' => 'performance',
        'description' => 'Performance optimization — cache, image compression, lazy loading, minification, PageSpeed test.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['cache_enable','cache_purge','cache_configure','compress_images','webp','lazy_load','minify','pagespeed','fix','fix_render_blocking'], 'description' => 'Operation' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 23. Design ───────────────────────────────────────────
    $tools[] = [
        'name' => 'design',
        'description' => 'Site design — apply blueprints, set colors/fonts/logo/favicon, design header/footer, get recommendations.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['blueprint','profile','colors','fonts','logo','favicon','header','footer','recommend','apply','reset'], 'description' => 'Operation' ],
                'blueprint'=> [ 'type' => 'string', 'description' => 'Blueprint name' ],
                'colors'   => [ 'type' => 'object', 'description' => 'Color palette {primary, secondary, accent, bg, text}' ],
                'font'     => [ 'type' => 'string', 'description' => 'Font name' ],
                'url'      => [ 'type' => 'string', 'description' => 'Logo/favicon URL' ],
                'style'    => [ 'type' => 'string', 'description' => 'Header/footer style name' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 24. Head/Footer Code ─────────────────────────────────
    $tools[] = [
        'name' => 'head_code',
        'description' => 'Add code to HTML head or footer — analytics, tracking pixels, meta tags, custom scripts.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['add_head','add_footer'], 'description' => 'Where to add' ],
                'code'     => [ 'type' => 'string', 'description' => 'HTML/code to inject' ],
                'name'     => [ 'type' => 'string', 'description' => 'Identifier for this snippet' ],
            ],
            'required' => ['action','code'],
        ],
    ];

    // ── 25. Images ───────────────────────────────────────────
    $tools[] = [
        'name' => 'images',
        'description' => 'Image management — upload, generate AI images, search stock photos, set featured images, alt text, WebP conversion.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['upload','generate','stock_search','stock_insert','set_featured','alt_text','bulk_fix_alt','dimensions','webp'], 'description' => 'Operation' ],
                'url'     => [ 'type' => 'string', 'description' => 'Image URL' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Post/image ID' ],
                'alt'     => [ 'type' => 'string', 'description' => 'Alt text' ],
                'query'   => [ 'type' => 'string', 'description' => 'Search query for stock photos' ],
                'prompt'  => [ 'type' => 'string', 'description' => 'AI image generation prompt' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 26. Redirects ────────────────────────────────────────
    $tools[] = [
        'name' => 'redirects',
        'description' => 'Manage URL redirects — create 301/302 redirects, list existing, delete.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['create','list','delete'], 'description' => 'Operation' ],
                'from'   => [ 'type' => 'string', 'description' => 'Source URL path' ],
                'to'     => [ 'type' => 'string', 'description' => 'Destination URL' ],
                'type'   => [ 'type' => 'integer', 'enum' => [301,302], 'description' => 'Redirect type' ],
                'id'     => [ 'type' => 'integer', 'description' => 'Redirect ID (for delete)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 27. Email ────────────────────────────────────────────
    $tools[] = [
        'name' => 'email',
        'description' => 'Email management — send emails, test SMTP, configure SMTP settings, manage templates.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['send','test','configure_smtp','templates','list_templates'], 'description' => 'Operation' ],
                'to'      => [ 'type' => 'string', 'description' => 'Recipient email' ],
                'subject' => [ 'type' => 'string' ],
                'body'    => [ 'type' => 'string' ],
                'host'    => [ 'type' => 'string', 'description' => 'SMTP host' ],
                'port'    => [ 'type' => 'integer', 'description' => 'SMTP port' ],
                'user'    => [ 'type' => 'string', 'description' => 'SMTP username' ],
                'pass'    => [ 'type' => 'string', 'description' => 'SMTP password' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 28. Backup ───────────────────────────────────────────
    $tools[] = [
        'name' => 'backup',
        'description' => 'Backup management — create backup, list existing, restore, configure auto-backups.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['create','list','restore','configure'], 'description' => 'Operation' ],
                'id'     => [ 'type' => 'string', 'description' => 'Backup ID (for restore)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 29. Widgets ──────────────────────────────────────────
    $tools[] = [
        'name' => 'widgets',
        'description' => 'Manage sidebar widgets — list, add, update, remove, clear sidebar.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['list','add','update','remove','clear_sidebar'], 'description' => 'Operation' ],
                'sidebar' => [ 'type' => 'string', 'description' => 'Sidebar ID' ],
                'widget'  => [ 'type' => 'string', 'description' => 'Widget type' ],
                'title'   => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 30. Cron ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'cron',
        'description' => 'Manage WordPress cron jobs — list scheduled tasks, add new, delete, run immediately.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['list','add','delete','run'], 'description' => 'Operation' ],
                'hook'   => [ 'type' => 'string', 'description' => 'Cron hook name' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 31. Database ─────────────────────────────────────────
    $tools[] = [
        'name' => 'database',
        'description' => 'Database queries — run SELECT queries to read data. Only read operations allowed (SELECT, SHOW, DESCRIBE).',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['query','export'], 'description' => 'Operation' ],
                'sql'    => [ 'type' => 'string', 'description' => 'SQL query (SELECT only)' ],
                'format' => [ 'type' => 'string', 'enum' => ['json','csv'], 'description' => 'Export format' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 32. Analytics ────────────────────────────────────────
    $tools[] = [
        'name' => 'analytics',
        'description' => 'Analytics and tracking — setup Google Analytics, view stats, popular pages.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'     => [ 'type' => 'string', 'enum' => ['setup','stats','connect_ga','add_pixel'], 'description' => 'Operation' ],
                'tracking_id'=> [ 'type' => 'string', 'description' => 'Google Analytics or pixel ID' ],
                'type'       => [ 'type' => 'string', 'enum' => ['google','facebook','tiktok','pinterest','snapchat'], 'description' => 'Tracking platform' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 33. GDPR ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'gdpr',
        'description' => 'GDPR compliance — audit, cookie banner, export/delete user data, privacy policy.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['audit','cookie_banner','export_data','delete_data','status','privacy_policy'], 'description' => 'Operation' ],
                'email'  => [ 'type' => 'string', 'description' => 'User email (for export/delete)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 34. Check Frontend ───────────────────────────────────
    $tools[] = [
        'name' => 'check_frontend',
        'description' => 'Analyze the site frontend — finds layout issues, broken CSS, theme problems, mobile issues. Run this first to understand current state.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'url' => [ 'type' => 'string', 'description' => 'Specific URL to check (optional, defaults to homepage)' ],
            ],
        ],
    ];

    // ── 35. Site Info ────────────────────────────────────────
    $tools[] = [
        'name' => 'site_info',
        'description' => 'Get WordPress site information — name, URL, theme, active plugins, WP version, PHP version, WPilot version.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => new \stdClass(),
        ],
    ];

    // ── 36. Undo ─────────────────────────────────────────────
    $tools[] = [
        'name' => 'undo',
        'description' => 'Undo recent changes or rollback entire site. Every destructive operation is automatically saved. Use "rollback" to restore full site state (all pages, CSS, theme, plugins) from before a major operation.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['last','list','specific','rollback','rollback_list'], 'description' => '"last" = undo last change, "list" = show undo history, "specific" = undo by ID, "rollback" = restore full site snapshot, "rollback_list" = show available full snapshots' ],
                'id'     => [ 'type' => 'string', 'description' => 'Snapshot ID (for specific undo or rollback)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 37. AI Content ───────────────────────────────────────
    $tools[] = [
        'name' => 'ai_content',
        'description' => 'AI-powered content — write blog posts, rewrite content, generate product descriptions, social media posts.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['write','rewrite','blog_series','product_descriptions','social_posts'], 'description' => 'Operation' ],
                'topic'   => [ 'type' => 'string', 'description' => 'Content topic or prompt' ],
                'content' => [ 'type' => 'string', 'description' => 'Content to rewrite' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Post/product ID' ],
                'tone'    => [ 'type' => 'string', 'description' => 'Writing tone (professional, casual, fun, etc)' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 38. Export ────────────────────────────────────────────
    $tools[] = [
        'name' => 'export',
        'description' => 'Export data — pages, products, orders, customers, subscribers as JSON or CSV.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['pages','products','orders','customers','users','subscribers','site','all'], 'description' => 'What to export' ],
                'format' => [ 'type' => 'string', 'enum' => ['json','csv'], 'description' => 'Export format' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 39. Popups ───────────────────────────────────────────
    $tools[] = [
        'name' => 'popups',
        'description' => 'Manage popups — create, show, hide, delete, schedule, exit intent, discount popups.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['create','show','hide','delete','schedule','stats','exit_intent','discount'], 'description' => 'Operation' ],
                'id'      => [ 'type' => 'integer', 'description' => 'Popup ID' ],
                'title'   => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string', 'description' => 'Popup HTML content' ],
                'trigger' => [ 'type' => 'string', 'enum' => ['immediate','scroll','exit','delay','click'], 'description' => 'Trigger type' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 40. WooCommerce Stock ────────────────────────────────
    $tools[] = [
        'name' => 'woo_stock',
        'description' => 'Manage product inventory — set stock levels, manage stock, inventory reports.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['manage','set','report','inventory'], 'description' => 'Operation' ],
                'id'       => [ 'type' => 'integer', 'description' => 'Product ID' ],
                'quantity' => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
                'status'   => [ 'type' => 'string', 'enum' => ['instock','outofstock','onbackorder'] ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 41. Translations ─────────────────────────────────────
    $tools[] = [
        'name' => 'translations',
        'description' => 'Multilingual management — setup, translate pages, add languages, configure language switcher.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['setup','translate_page','add_language','set_switcher','bulk'], 'description' => 'Operation' ],
                'id'       => [ 'type' => 'integer', 'description' => 'Page ID to translate' ],
                'language' => [ 'type' => 'string', 'description' => 'Language code (sv, en, da, etc)' ],
                'content'  => [ 'type' => 'string', 'description' => 'Translated content' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 42. Social ───────────────────────────────────────────
    $tools[] = [
        'name' => 'social',
        'description' => 'Social media integration — add social links, share buttons, embed feeds, setup sharing.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'   => [ 'type' => 'string', 'enum' => ['links','share_buttons','feed','embed','setup'], 'description' => 'Operation' ],
                'platform' => [ 'type' => 'string', 'description' => 'Social platform (facebook, instagram, twitter, etc)' ],
                'url'      => [ 'type' => 'string', 'description' => 'Social profile URL' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 43. Bookings ─────────────────────────────────────────
    $tools[] = [
        'name' => 'bookings',
        'description' => 'Booking system — create booking pages, list bookings, cancel, confirm, settings, stats.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => [ 'type' => 'string', 'enum' => ['create_page','list','cancel','confirm','settings','stats'], 'description' => 'Operation' ],
                'id'     => [ 'type' => 'integer', 'description' => 'Booking ID' ],
            ],
            'required' => ['action'],
        ],
    ];

    // ── 44. Search & Replace ─────────────────────────────────
    $tools[] = [
        'name' => 'search_replace',
        'description' => 'Search and replace text across the entire site — all pages, posts, and content.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'search'  => [ 'type' => 'string', 'description' => 'Text to find' ],
                'replace' => [ 'type' => 'string', 'description' => 'Replacement text' ],
                'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview changes without applying (default: true)' ],
            ],
            'required' => ['search','replace'],
        ],
    ];

    // ── 45. Maintenance Mode ─────────────────────────────────
    $tools[] = [
        'name' => 'maintenance',
        'description' => 'Toggle maintenance mode — show "under construction" page to visitors while working on the site.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action'  => [ 'type' => 'string', 'enum' => ['enable','disable','status'], 'description' => 'Operation' ],
                'message' => [ 'type' => 'string', 'description' => 'Custom maintenance message' ],
            ],
            'required' => ['action'],
        ],
    ];


    // ── Debug & Diagnostics ──────────────────────────────────
    $tools[] = [
        'name' => 'debug',
        'description' => 'Diagnose WordPress issues. health=full check, errors=PHP log, check_page=test URL, config=WP settings, cache=status, fix=common fixes.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['health','errors','clear_errors','check_page','config','cache','fix'], 'description' => 'Diagnostic action'],
                'url' => ['type' => 'string', 'description' => 'URL to check (for check_page)'],
                'lines' => ['type' => 'integer', 'description' => 'Error log lines (max 100)'],
                'issue' => ['type' => 'string', 'description' => 'Fix: permalinks, transients, cache, all'],
            ],
            'required' => ['action'],
        ],
    ];

    // ── WordPress Power Tool ─────────────────────────────────
    $tools[] = [
        'name' => 'wordpress',
        'description' => 'General WordPress admin — read/write options, any post type CRUD, taxonomies, users, theme mods, post meta, roles, cache flush. Covers everything not in specialized tools.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['get_option','set_option','get_posts','get_post','update_post','get_terms','create_term','get_users','get_theme_mods','set_theme_mod','get_meta','set_meta','post_types','taxonomies','roles','flush_rewrite','flush_cache'], 'description' => 'Operation'],
                'name' => ['type' => 'string', 'description' => 'Option/mod/meta name'],
                'value' => ['description' => 'Value to set'],
                'id' => ['type' => 'integer', 'description' => 'Post/user ID'],
                'post_type' => ['type' => 'string', 'description' => 'Post type (default: post)'],
                'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy name'],
                'key' => ['type' => 'string', 'description' => 'Meta key'],
                'title' => ['type' => 'string', 'description' => 'Post title'],
                'content' => ['type' => 'string', 'description' => 'Post content'],
                'status' => ['type' => 'string', 'description' => 'Post status'],
                'meta' => ['type' => 'object', 'description' => 'Meta key-value pairs'],
                'role' => ['type' => 'string', 'description' => 'User role filter'],
                'search' => ['type' => 'string', 'description' => 'Search query'],
                'count' => ['type' => 'integer', 'description' => 'Number of results'],
            ],
            'required' => ['action'],
        ],
    ];

    // ── WooCommerce Power Tool ────────────────────────────────
    $tools[] = [
        'name' => 'woocommerce',
        'description' => 'Complete WooCommerce management — products CRUD, orders, customers, coupons, categories, sales reports, stock reports, best sellers, refunds, store info. Everything for e-commerce.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list_products','get_product','create_product','update_product','delete_product','list_orders','get_order','update_order','list_customers','list_coupons','list_categories','create_category','sales_report','stock_report','best_sellers','refund_order','store_info'], 'description' => 'Operation'],
                'id' => ['type' => 'integer', 'description' => 'Product/order ID'],
                'title' => ['type' => 'string', 'description' => 'Product name'],
                'price' => ['type' => 'string', 'description' => 'Product price'],
                'sale_price' => ['type' => 'string', 'description' => 'Sale price'],
                'description' => ['type' => 'string', 'description' => 'Product description'],
                'short_description' => ['type' => 'string', 'description' => 'Short description'],
                'sku' => ['type' => 'string', 'description' => 'Product SKU'],
                'stock' => ['type' => 'integer', 'description' => 'Stock quantity'],
                'category' => ['type' => 'string', 'description' => 'Category name'],
                'status' => ['type' => 'string', 'description' => 'Order/product status'],
                'note' => ['type' => 'string', 'description' => 'Order note'],
                'days' => ['type' => 'integer', 'description' => 'Report period in days'],
                'amount' => ['type' => 'number', 'description' => 'Refund amount'],
                'reason' => ['type' => 'string', 'description' => 'Refund reason'],
                'count' => ['type' => 'integer', 'description' => 'Number of results'],
                'image_url' => ['type' => 'string', 'description' => 'Product image URL'],
                'weight' => ['type' => 'string', 'description' => 'Product weight'],
            ],
            'required' => ['action'],
        ],
    ];

    $cached = $tools;
    return $tools;
}


// ══════════════════════════════════════════════════════════════
//  ROUTER — maps grouped tool + action to individual tools
// ══════════════════════════════════════════════════════════════

/**
 * Route a grouped tool call to the correct individual tool(s).
 * Returns the result from wpilot_run_tool().
 */
function wpilot_mcp_route_tool( $tool_name, $args ) {

    // Direct handlers for tools with dedicated functions
    $direct_handlers = [
        'debug'  => 'wpilot_tool_debug',
    ];

    // WooCommerce direct handlers
    $woo_direct = [
        'woo_create_product'          => 'wpilot_woo_create_product',
        'woo_update_store_settings'   => 'wpilot_woo_update_store_settings',
        'woo_enable_payment'          => 'wpilot_woo_enable_payment',
        'woo_configure_email'         => 'wpilot_woo_configure_email',
        'woo_setup_checkout'          => 'wpilot_woo_setup_checkout',
        'woo_set_tax_rate'            => 'wpilot_woo_set_tax_rate',
        'woo_create_shipping_class'   => 'wpilot_woo_create_shipping_class',
        'woo_update_shipping_zone'    => 'wpilot_woo_update_shipping_zone',
    ];

    // Other direct handlers
    $other_direct = [
        'plugin_install'              => 'wpilot_plugin_install_from_repo',
        'cache_enable'                => 'wpilot_cache_enable',
        'cache_purge'                 => 'wpilot_cache_purge',
        'cache_configure'             => 'wpilot_cache_configure',
        'smtp_configure'              => 'wpilot_smtp_configure',
        'backup_configure'            => 'wpilot_backup_configure',
        'security_enable_2fa'         => 'wpilot_security_enable_2fa',
        'security_enable_firewall'    => 'wpilot_security_enable_firewall',
        'multilingual_setup'          => 'wpilot_multilingual_setup',
        'multilingual_translate_page' => 'wpilot_multilingual_translate_page',
        'multilingual_add_language'   => 'wpilot_multilingual_add_language',
        'multilingual_set_switcher'   => 'wpilot_multilingual_set_switcher',
        'multilingual_bulk_translate' => 'wpilot_multilingual_bulk_translate',
        'get_option_value'            => 'wpilot_get_option_value',
    ];

    // Check grouped tool name first

    // Check if the resolved individual tool has a direct handler
    $action = $args['action'] ?? '';
    $route_map_all = array_merge($woo_direct, $other_direct);

    // Resolve the individual tool name from the route map
    // (We need to check after the route map resolves the action)

    // Direct handlers for power tools
    // Direct handler for plugins tool (needs admin user for install/activate)
    if ($tool_name === 'plugins') {
        $plugin_action = $args['action'] ?? '';
        if (in_array($plugin_action, ['install'], true)) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if (!empty($admins)) wp_set_current_user($admins[0]);
            if (function_exists('wpilot_plugin_install_from_repo')) {
                return wpilot_plugin_install_from_repo($args);
            }
        }
    }
    if ($tool_name === 'wordpress' && function_exists('wpilot_tool_wordpress')) {
        return wpilot_tool_wordpress($args);
    }
    if ($tool_name === 'woocommerce' && function_exists('wpilot_tool_woocommerce')) {
        return wpilot_tool_woocommerce($args);
    }

    // Direct handler for plugin install/activate (needs admin user)
    if ($tool_name === 'plugins') {
        $pa = $args['action'] ?? '';
        if (in_array($pa, ['install','activate','deactivate','delete'], true) && function_exists('wpilot_plugin_install_from_repo')) {
            // Set admin user for filesystem access
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if (!empty($admins)) wp_set_current_user($admins[0]);
        }
    }

    // Direct handler for debug tool
    $action = $args['action'] ?? '';

    // Remove 'action' from args before passing to individual tool
    $params = $args;
    unset( $params['action'] );

    // Special handling for tools that don't need routing
    switch ( $tool_name ) {

        case 'check_frontend':
            return wpilot_run_tool( 'check_frontend', $params );

        case 'site_info':
            return [
                'success' => true,
                'message' => 'Site information retrieved.',
                'data'    => [
                    'name'           => get_bloginfo( 'name' ),
                    'url'            => get_site_url(),
                    'description'    => get_bloginfo( 'description' ),
                    'theme'          => wp_get_theme()->get( 'Name' ),
                    'theme_version'  => wp_get_theme()->get( 'Version' ),
                    'wp_version'     => get_bloginfo( 'version' ),
                    'php_version'    => phpversion(),
                    'wpilot_version' => defined( 'CA_VERSION' ) ? CA_VERSION : '?',
                    'active_plugins' => array_map( function( $p ) {
                        $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false );
                        return [ 'file' => $p, 'name' => $data['Name'] ?? $p, 'version' => $data['Version'] ?? '?' ];
                    }, get_option( 'active_plugins', [] ) ),
                    'is_multisite'   => is_multisite(),
                    'language'       => get_locale(),
                    'timezone'       => wp_timezone_string(),
                    'permalink'      => get_option( 'permalink_structure' ),
                    'woocommerce'    => class_exists( 'WooCommerce' ),
                ],
            ];

        case 'settings':
            if ( $action === 'get' ) {
                $opt = $params['option'] ?? '';
                // Security: only allow safe, non-sensitive options
                $allowed = [ 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
                    'timezone_string', 'date_format', 'time_format', 'permalink_structure',
                    'posts_per_page', 'page_on_front', 'page_for_posts', 'show_on_front',
                    'WPLANG', 'default_category', 'blog_public',
                    'woocommerce_currency', 'woocommerce_store_address',
                    'woocommerce_store_city', 'woocommerce_default_country' ];
                if ( ! empty( $opt ) && ! in_array( $opt, $allowed, true ) ) {
                    return [ 'success' => false, 'message' => "Option .. is restricted for security." ];
                }
                if ( empty( $opt ) ) {
                    // Return common settings
                    return [
                        'success' => true,
                        'message' => 'WordPress settings.',
                        'data'    => [
                            'blogname'      => get_option( 'blogname' ),
                            'blogdescription' => get_option( 'blogdescription' ),
                            'siteurl'       => get_option( 'siteurl' ),
                            'home'          => get_option( 'home' ),
                            'admin_email'   => get_option( 'admin_email' ),
                            'timezone'      => get_option( 'timezone_string' ),
                            'date_format'   => get_option( 'date_format' ),
                            'time_format'   => get_option( 'time_format' ),
                            'permalink'     => get_option( 'permalink_structure' ),
                            'posts_per_page'=> get_option( 'posts_per_page' ),
                            'language'      => get_locale(),
                        ],
                    ];
                }
                $val = get_option( $opt, '__NOT_FOUND__' );
                if ( $val === '__NOT_FOUND__' ) {
                    return [ 'success' => false, 'message' => "Option '{$opt}' not found." ];
                }
                return [ 'success' => true, 'message' => "Option '{$opt}'.", 'data' => [ $opt => $val ] ];
            }
            break; // Fall through to route_map for other settings actions

        case 'search_replace':
            return wpilot_run_tool( 'search_replace', $params );

        case 'undo':
            if ( ! function_exists( 'wpilot_mcp_undo' ) ) {
                return [ 'success' => false, 'message' => 'Undo system not loaded.' ];
            }
            switch ( $action ) {
                case 'last':         return wpilot_mcp_undo();
                case 'list':         return [ 'success' => true, 'message' => 'Undo history.', 'data' => wpilot_mcp_undo_list() ];
                case 'specific':     return wpilot_mcp_undo( $params['id'] ?? null );
                case 'rollback':     return function_exists( 'wpilot_mcp_restore_full_snapshot' ) ? wpilot_mcp_restore_full_snapshot( $params['id'] ?? null ) : [ 'success' => false, 'message' => 'Rollback not available.' ];
                case 'rollback_list':return [ 'success' => true, 'message' => 'Full site snapshots.', 'data' => function_exists( 'wpilot_mcp_list_full_snapshots' ) ? wpilot_mcp_list_full_snapshots() : [] ];
                default:             return [ 'success' => false, 'message' => 'Unknown undo action. Use: last, list, specific, rollback, rollback_list' ];
            }
    }

    // ── Route map: grouped tool + action → individual tool name ──
    $route_map = [
        'pages' => [
            'create'    => 'create_html_page',
            'read'      => 'get_page',
            'update'    => 'update_page_content',
            'delete'    => 'delete',
            'list'      => 'list_pages',
            'search'    => 'replace_in_page',
            'duplicate' => 'duplicate_page',
        ],
        'posts' => [
            'create'    => 'create_post',
            'read'      => 'get_post',
            'update'    => 'update_post',
            'delete'    => 'delete_post',
            'list'      => 'list_posts',
            'publish'   => 'publish_post',
            'unpublish' => 'unpublish_post',
        ],
        'media' => [
            'upload'    => 'upload_image',
            'list'      => 'list_media',
            'delete'    => 'delete_media',
            'search'    => 'list_images',
        ],
        'menus' => [
            'create'       => 'create_menu',
            'list'         => 'list_menus',
            'add_item'     => 'add_menu_item',
            'remove_item'  => 'remove_menu_item',
            'delete'       => 'delete_menu',
            'rename'       => 'rename_menu',
            'set_location' => 'set_menu_location',
        ],
        'plugins' => [
            'install'    => 'wpilot_plugin_install_from_repo',
            'activate'   => 'activate_plugin',
            'deactivate' => 'deactivate_plugin',
            'update'     => 'update_all_plugins',
            'remove'     => 'delete_plugin',
            'list'       => 'list_plugins',
            'search'     => 'check_missing_plugins',
        ],
        'themes' => [
            'list'     => 'list_themes',
            'activate' => 'activate_theme',
            'install'  => 'theme_install',
        ],
        'css' => [
            'get'     => 'get_css',
            'append'  => 'append_custom_css',
            'replace' => 'update_custom_css',
            'reset'   => 'css_reset',
        ],
        'users' => [
            'list'           => 'list_users',
            'create'         => 'create_user',
            'update'         => 'update_user_meta',
            'delete'         => 'delete_user',
            'change_role'    => 'change_user_role',
            'reset_password' => 'send_password_reset',
        ],
        'settings' => [
            'get'       => 'wpilot_get_option_value',
            'update'    => 'update_option',
            'blogname'  => 'update_blogname',
            'tagline'   => 'update_tagline',
            'permalink' => 'update_permalink_structure',
        ],
        'comments' => [
            'list'    => 'list_comments',
            'approve' => 'approve_comment',
            'delete'  => 'delete_comment',
            'spam'    => 'spam_comment',
            'reply'   => 'reply_to_comment',
            'stats'   => 'comment_stats',
        ],
        'taxonomies' => [
            'create_category' => 'create_category',
            'list_categories' => 'list_categories',
            'create_tag'      => 'create_tag',
            'list_tags'       => 'list_categories',
            'assign'          => 'set_category',
            'delete'          => 'delete',
        ],
        'woo_products' => [
            'create'      => 'wpilot_woo_create_product',
            'read'        => 'get_page',
            'update'      => 'woo_update_product',
            'delete'      => 'delete',
            'list'        => 'list_pages',
            'search'      => 'woo_product_search',
            'import'      => 'woo_import_products',
            'duplicate'   => 'woo_duplicate_product',
            'set_image'   => 'set_product_image',
            'gallery'     => 'woo_set_product_gallery',
            'variation'   => 'woo_create_variation',
            'set_price'   => 'update_product_price',
            'set_sale'    => 'woo_set_sale',
            'remove_sale' => 'woo_remove_sale',
            'set_stock'   => 'woo_manage_stock',
        ],
        'woo_orders' => [
            'list'          => 'woo_list_orders',
            'get'           => 'woo_get_order',
            'update_status' => 'woo_update_order',
            'refund'        => 'woo_refund_order',
            'receipt'       => 'order_receipt',
            'tracking'      => 'woo_order_tracking',
            'stats'         => 'woo_order_stats',
        ],
        'woo_coupons' => [
            'create' => 'create_full_coupon',
            'list'   => 'list_coupons',
            'update' => 'update_coupon',
            'delete' => 'delete_coupon',
            'stats'  => 'coupon_stats',
        ],
        'woo_shipping' => [
            'zones'        => 'shipping_zones',
            'methods'      => 'shipping_zones',
            'rates'        => 'shipping_zones',
            'classes'      => 'wpilot_woo_create_shipping_class',
            'update_zone'  => 'wpilot_woo_update_shipping_zone',
            'create_class' => 'wpilot_woo_create_shipping_class',
        ],
        'woo_settings' => [
            'store'    => 'wpilot_woo_update_store_settings',
            'checkout' => 'wpilot_woo_setup_checkout',
            'payments' => 'wpilot_woo_enable_payment',
            'tax'      => 'woo_tax_setup',
            'emails'   => 'wpilot_woo_configure_email',
            'tax_rates'=> 'wpilot_woo_set_tax_rate',
        ],
        'woo_customers' => [
            'list'   => 'list_customers',
            'get'    => 'get_customer',
            'stats'  => 'woo_customer_stats',
            'report' => 'customer_report',
            'export' => 'export_customers',
        ],
        'woo_reports' => [
            'sales'        => 'woo_sales_report',
            'stock'        => 'stock_report',
            'revenue'      => 'revenue_report',
            'best_sellers' => 'woo_best_sellers',
            'low_stock'    => 'woo_low_stock_report',
            'tax'          => 'woo_tax_summary',
        ],
        'seo' => [
            'audit'          => 'seo_audit',
            'meta_title'     => 'update_seo_title',
            'meta_desc'      => 'update_meta_desc',
            'schema'         => 'add_schema_markup',
            'sitemap'        => 'get_sitemap',
            'robots'         => 'create_robots_txt',
            'opengraph'      => 'add_open_graph',
            'keywords'       => 'seo_keyword_check',
            'broken_links'   => 'seo_broken_links',
            'internal_links' => 'seo_internal_links',
            'check_page'     => 'seo_check_page',
        ],
        'forms' => [
            'create'  => 'create_contact_form',
            'list'    => 'list_forms',
            'entries' => 'get_form_entries',
            'delete'  => 'delete_form',
        ],
        'security' => [
            'scan'          => 'security_scan',
            'audit'         => 'full_security_check',
            'headers'       => 'add_security_headers',
            'firewall'      => 'wpilot_security_enable_firewall',
            'two_factor'    => 'wpilot_security_enable_2fa',
            'xmlrpc'        => 'disable_xmlrpc',
            'block_ip'      => 'block_ip',
            'unblock_ip'    => 'unblock_ip',
            'list_blocked'  => 'list_blocked_ips',
            'failed_logins' => 'failed_logins',
        ],
        'performance' => [
            'cache_enable'       => 'wpilot_cache_enable',
            'cache_purge'        => 'wpilot_cache_purge',
            'cache_configure'    => 'wpilot_cache_configure',
            'compress_images'    => 'compress_images',
            'webp'               => 'convert_all_images_webp',
            'lazy_load'          => 'enable_lazy_load',
            'minify'             => 'minify_assets',
            'pagespeed'          => 'pagespeed_test',
            'fix'                => 'fix_performance',
            'fix_render_blocking'=> 'fix_render_blocking',
        ],
        'design' => [
            'blueprint' => 'apply_blueprint',
            'profile'   => 'save_design_profile',
            'colors'    => 'change_colors',
            'fonts'     => 'change_font',
            'logo'      => 'set_logo',
            'favicon'   => 'set_favicon',
            'header'    => 'apply_header',
            'footer'    => 'apply_footer',
            'recommend' => 'recommend_design',
            'apply'     => 'apply_design',
            'reset'     => 'reset_design',
        ],
        'head_code' => [
            'add_head'   => 'add_head_code',
            'add_footer' => 'add_footer_code',
        ],
        'images' => [
            'upload'       => 'upload_image',
            'generate'     => 'ai_image',
            'stock_search' => 'stock_photo_search',
            'stock_insert' => 'stock_photo_insert',
            'set_featured' => 'set_featured_image',
            'alt_text'     => 'update_image_alt',
            'bulk_fix_alt' => 'bulk_fix_alt_text',
            'dimensions'   => 'add_image_dimensions',
            'webp'         => 'convert_image_webp',
        ],
        'redirects' => [
            'create' => 'create_redirect',
            'list'   => 'list_redirects',
            'delete' => 'delete_redirect',
        ],
        'email' => [
            'send'           => 'send_email',
            'test'           => 'send_test_email',
            'configure_smtp' => 'wpilot_smtp_configure',
            'templates'      => 'list_email_templates',
            'list_templates' => 'list_email_templates',
        ],
        'backup' => [
            'create'    => 'backup_now',
            'list'      => 'list_backups',
            'restore'   => 'restore_backup',
            'configure' => 'wpilot_backup_configure',
        ],
        'widgets' => [
            'list'          => 'list_widgets',
            'add'           => 'builder_add_widget',
            'update'        => 'update_widget_area',
            'remove'        => 'remove_widgets',
            'clear_sidebar' => 'clear_sidebar',
        ],
        'cron' => [
            'list'   => 'cron_list',
            'add'    => 'cron_add',
            'delete' => 'cron_delete',
            'run'    => 'cron_list',
        ],
        'database' => [
            'query'  => 'db_query',
            'export' => 'export_full_site',
        ],
        'analytics' => [
            'setup'      => 'setup_analytics',
            'stats'      => 'setup_analytics',
            'connect_ga' => 'connect_google_analytics',
            'add_pixel'  => 'add_pixel',
        ],
        'gdpr' => [
            'audit'          => 'gdpr_audit',
            'cookie_banner'  => 'gdpr_cookie_banner',
            'export_data'    => 'gdpr_export_user_data',
            'delete_data'    => 'gdpr_delete_user_data',
            'status'         => 'gdpr_status',
            'privacy_policy' => 'privacy_policy_generate',
        ],
        'ai_content' => [
            'write'                => 'ai_write_content',
            'rewrite'              => 'ai_rewrite',
            'blog_series'          => 'ai_generate_blog_series',
            'product_descriptions' => 'ai_product_descriptions',
            'social_posts'         => 'ai_social_posts',
        ],
        'export' => [
            'pages'       => 'export_full_site',
            'products'    => 'export_all_products',
            'orders'      => 'export_orders_csv',
            'customers'   => 'export_customers',
            'users'       => 'export_users_csv',
            'subscribers' => 'export_subscribers',
            'site'        => 'export_full_site',
            'all'         => 'export_full_site',
        ],
        'popups' => [
            'create'      => 'create_popup',
            'show'        => 'show_popup',
            'hide'        => 'hide_popup',
            'delete'      => 'delete_popup',
            'schedule'    => 'schedule_popup',
            'stats'       => 'popup_stats',
            'exit_intent' => 'exit_intent_popup',
            'discount'    => 'create_discount_popup',
        ],
        'woo_stock' => [
            'manage'    => 'woo_manage_stock',
            'set'       => 'set_stock',
            'report'    => 'stock_report',
            'inventory' => 'inventory_report',
        ],
        'translations' => [
            'setup'          => 'wpilot_multilingual_setup',
            'translate_page' => 'wpilot_multilingual_translate_page',
            'add_language'   => 'wpilot_multilingual_add_language',
            'set_switcher'   => 'wpilot_multilingual_set_switcher',
            'bulk'           => 'wpilot_multilingual_bulk_translate',
        ],
        'social' => [
            'links'         => 'add_social_links',
            'share_buttons' => 'add_social_share_buttons',
            'feed'          => 'add_social_feed',
            'embed'         => 'embed_social',
            'setup'         => 'setup_social',
        ],
        'bookings' => [
            'create_page' => 'create_booking_page',
            'list'        => 'list_bookings',
            'cancel'      => 'cancel_booking',
            'confirm'     => 'confirm_booking',
            'settings'    => 'booking_settings',
            'stats'       => 'booking_stats',
        ],
        'debug' => [
            'health'       => 'wpilot_tool_debug',
            'errors'       => 'wpilot_tool_debug',
            'clear_errors' => 'wpilot_tool_debug',
            'check_page'   => 'wpilot_tool_debug',
            'config'       => 'wpilot_tool_debug',
            'cache'        => 'wpilot_tool_debug',
            'fix'          => 'wpilot_tool_debug',
        ],
        'maintenance' => [
            'enable'  => 'enable_maintenance',
            'disable' => 'disable_maintenance',
            'status'  => 'maintenance_status',
        ],
    ];

    // Look up the individual tool
    if ( ! isset( $route_map[$tool_name] ) ) {
        return [ 'success' => false, 'message' => "Unknown tool: {$tool_name}" ];
    }

    $tool_routes = $route_map[$tool_name];

    if ( empty( $action ) ) {
        // If tool has no action requirement, try first route
        $individual_tool = reset( $tool_routes );
    } elseif ( isset( $tool_routes[$action] ) ) {
        $individual_tool = $tool_routes[$action];
    } else {
        $available = implode( ', ', array_keys( $tool_routes ) );
        return [ 'success' => false, 'message' => "Unknown action '{$action}' for {$tool_name}. Available: {$available}" ];
    }

    // Security: check if individual tool is blocked
    if ( function_exists( 'wpilot_mcp_is_tool_allowed' ) && ! wpilot_mcp_is_tool_allowed( $individual_tool ) ) {
        return [ 'success' => false, 'message' => "Tool '{$individual_tool}' is not available via MCP for security reasons." ];
    }

    // Database query security
    if ( $individual_tool === 'db_query' && isset( $params['sql'] ) ) {
        $sanitized = wpilot_mcp_sanitize_query( $params['sql'] );
        if ( $sanitized === false ) {
            return [ 'success' => false, 'message' => 'Only SELECT, SHOW, and DESCRIBE queries are allowed via MCP.' ];
        }
        $params['sql'] = $sanitized;
    }

    // Undo: save snapshot before destructive ops
    if ( function_exists( 'wpilot_mcp_save_snapshot' ) && in_array( $individual_tool, wpilot_mcp_destructive_tools(), true ) ) {
        wpilot_mcp_save_snapshot( $individual_tool, $params );
    }

    // Execute the individual tool
    // Check if individual tool has a direct handler function
    $all_direct = [
        'wpilot_tool_debug', 'wpilot_woo_create_product', 'wpilot_woo_update_store_settings',
        'wpilot_woo_enable_payment', 'wpilot_woo_configure_email', 'wpilot_woo_setup_checkout',
        'wpilot_woo_set_tax_rate', 'wpilot_woo_create_shipping_class', 'wpilot_woo_update_shipping_zone',
        'wpilot_plugin_install_from_repo', 'wpilot_cache_enable', 'wpilot_cache_purge',
        'wpilot_cache_configure', 'wpilot_smtp_configure', 'wpilot_backup_configure',
        'wpilot_security_enable_2fa', 'wpilot_security_enable_firewall',
        'wpilot_multilingual_setup', 'wpilot_multilingual_translate_page',
        'wpilot_multilingual_add_language', 'wpilot_multilingual_set_switcher',
        'wpilot_multilingual_bulk_translate', 'wpilot_get_option_value',
    ];

    if ( in_array( $individual_tool, $all_direct, true ) && function_exists( $individual_tool ) ) {
        return $individual_tool( $args );
    }

    return wpilot_run_tool( $individual_tool, $params );
}
