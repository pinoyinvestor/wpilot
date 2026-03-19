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
 * WPilot MCP Server v3 — Model Context Protocol endpoint
 * Grouped tools, license validation, audit log, undo system.
 */

// Register REST API routes
add_action( 'rest_api_init', function() {
    // Main MCP endpoint
    register_rest_route( 'wpilot/v1', '/mcp', [
        'methods'             => ['POST', 'GET'],
        'callback'            => 'wpilot_mcp3_handler',
        'permission_callback' => 'wpilot_mcp3_auth',
    ] );

    // Discovery endpoint (public)
    register_rest_route( 'wpilot/v1', '/mcp/discover', [
        'methods'             => 'GET',
        'callback'            => 'wpilot_mcp3_discover',
        'permission_callback' => '__return_true',
    ] );
} );

// ── Authentication ───────────────────────────────────────────
function wpilot_mcp3_auth( $request ) {
    // WordPress Application Password auth
    if ( current_user_can( 'manage_options' ) ) return true;

    // Bearer token auth
    $token = '';
    $auth = $request->get_header( 'Authorization' );
    if ( $auth && preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
        $token = trim( $m[1] );
    }

    // Built by Weblease

    // Fallback: X-WPilot-Token header
    if ( ! $token ) {
        $token = $request->get_header( 'X-WPilot-Token' );
    }

    if ( $token && function_exists( 'wpilot_mcp_validate_key' ) && wpilot_mcp_validate_key( $token ) ) {
        // Rate limit check
        if ( function_exists( 'wpilot_mcp_rate_limit' ) && ! wpilot_mcp_rate_limit() ) {
            return new WP_Error( 'rate_limited', 'Rate limit exceeded (120/min).', [ 'status' => 429 ] );
        }

        // Track request
        if ( function_exists( 'wpilot_mcp_track_request' ) ) wpilot_mcp_track_request();

        return true;
    }

    return new WP_Error( 'rest_forbidden', 'Authentication required. Use Bearer token or WordPress Application Password.', [ 'status' => 401 ] );
}

// ── Discovery ────────────────────────────────────────────────
function wpilot_mcp3_discover() {
    $tool_count = function_exists( 'wpilot_mcp_grouped_tool_definitions' )
        ? count( wpilot_mcp_grouped_tool_definitions() )
        : 0;

    return rest_ensure_response( [
        'name'        => 'WPilot',
        'version'     => defined( 'CA_VERSION' ) ? CA_VERSION : '3.0.0',
        'description' => "WPilot MCP Server — {$tool_count} tools to manage your entire WordPress site.",
        'endpoint'    => rest_url( 'wpilot/v1/mcp' ),
        'protocol'    => '2025-06-18',
    ] );
}

// ── Main Handler ─────────────────────────────────────────────
function wpilot_mcp3_handler( $request ) {
    $method = $request->get_method();

    // GET = server info
    if ( $method === 'GET' ) {
        return rest_ensure_response( [
            'jsonrpc' => '2.0',
            'result'  => [
                'message' => 'WPilot MCP Server ready. Send POST requests with JSON-RPC 2.0.',
                'version' => defined( 'CA_VERSION' ) ? CA_VERSION : '3.0.0',
            ],
        ] );
    }

    // POST = JSON-RPC
    $body = $request->get_json_params();
    if ( empty( $body ) ) {
        return wpilot_mcp3_error( null, -32700, 'Parse error — invalid JSON.' );
    }

    // Batch requests
    if ( isset( $body[0] ) ) {
        $responses = [];
        foreach ( $body as $req ) {
            $r = wpilot_mcp3_process( $req );
            if ( $r !== null ) $responses[] = $r;
        }
        return rest_ensure_response( $responses );
    }

    $result = wpilot_mcp3_process( $body );
    if ( $result === null ) return new WP_REST_Response( null, 204 );
    return rest_ensure_response( $result );
}

// ── Process JSON-RPC ─────────────────────────────────────────
function wpilot_mcp3_process( $req ) {
    $id     = $req['id'] ?? null;
    $method = $req['method'] ?? '';
    $params = $req['params'] ?? [];

    switch ( $method ) {
        case 'initialize':
            return wpilot_mcp3_result( $id, [
                'protocolVersion' => '2025-06-18',
                'serverInfo'      => [
                    'name'    => 'wpilot',
                    'version' => defined( 'CA_VERSION' ) ? CA_VERSION : '3.0.0',
                ],
                'capabilities' => [
                    'tools'     => [ 'listChanged' => false ],
                    'resources' => [ 'listChanged' => false ],
                    'prompts'   => [ 'listChanged' => false ],
                ],
            ] );

        case 'tools/list':
            return wpilot_mcp3_tools_list( $id );

        case 'tools/call':
            return wpilot_mcp3_tools_call( $id, $params );

        case 'resources/list':
            return wpilot_mcp3_result( $id, [ 'resources' => wpilot_mcp3_resources() ] );

        case 'resources/read':
            return wpilot_mcp3_resource_read( $id, $params );

        case 'prompts/list':
            return wpilot_mcp3_result( $id, [ 'prompts' => wpilot_mcp3_prompts() ] );

        case 'prompts/get':
            return wpilot_mcp3_prompt_get( $id, $params );

        case 'ping':
            return wpilot_mcp3_result( $id, [] );

        case 'notifications/initialized':
            return null;

        default:
            return wpilot_mcp3_error( $id, -32601, "Method not found: {$method}" );
    }
}

// ── tools/list ───────────────────────────────────────────────
function wpilot_mcp3_tools_list( $id ) {
    $all_tools = wpilot_mcp_grouped_tool_definitions();

    // Filter by license tier
    $tier = function_exists( 'wpilot_mcp_check_license' ) ? wpilot_mcp_check_license() : 'pro';

    if ( ! in_array( $tier, ['pro', 'team', 'lifetime'], true ) ) {
        $free_names = function_exists( 'wpilot_mcp_free_tools' ) ? wpilot_mcp_free_tools() : ['site_info','pages','check_frontend','posts'];
        $all_tools = array_filter( $all_tools, fn( $t ) => in_array( $t['name'], $free_names, true ) );
        $all_tools = array_values( $all_tools );
    }

    return wpilot_mcp3_result( $id, [ 'tools' => $all_tools ] );
}

// ── tools/call ───────────────────────────────────────────────
function wpilot_mcp3_tools_call( $id, $params ) {
    $name = $params['name'] ?? '';
    $args = $params['arguments'] ?? [];

    if ( empty( $name ) ) {
        return wpilot_mcp3_error( $id, -32602, 'Tool name required.' );
    }

    // 0. Guardian gate — license + domain + tamper check before any tool execution
    if ( function_exists( 'wpilot_guardian_mcp_gate' ) ) {
        $guardian_check = wpilot_guardian_mcp_gate( $name );
        if ( $guardian_check !== true && is_array( $guardian_check ) && ! empty( $guardian_check['blocked'] ) ) {
            return wpilot_mcp3_result( $id, [
                'content' => [[ 'type' => 'text', 'text' => $guardian_check['message'] ]],
                'isError' => true,
            ] );
        }
    }

    // 0. Guardian gate — license + domain + integrity
    if ( function_exists( "wpilot_guardian_mcp_gate" ) ) {
        $gate = wpilot_guardian_mcp_gate( $name );
        if ( $gate !== true && is_array( $gate ) && ! empty( $gate["blocked"] ) ) {
            return wpilot_mcp3_result( $id, [
                "content" => [[ "type" => "text", "text" => $gate["message"] ]],
                "isError" => true,
            ] );
        }
    }

    // 1. License check
    $tier = function_exists( 'wpilot_mcp_check_license' ) ? wpilot_mcp_check_license() : 'pro';
    if ( function_exists( 'wpilot_mcp_tool_allowed_for_tier' ) && ! wpilot_mcp_tool_allowed_for_tier( $name, $tier ) ) {
        return wpilot_mcp3_result( $id, [
            'content' => [[ 'type' => 'text', 'text' => "This tool requires a WPilot Pro license. Current tier: {$tier}. Upgrade at https://weblease.se/wpilot" ]],
            'isError' => true,
        ] );
    }

    // 2. Destructive rate limit check
    if ( function_exists( 'wpilot_mcp_check_destructive_rate' ) ) {
        $rate_check = wpilot_mcp_check_destructive_rate( $name );
        if ( is_array( $rate_check ) ) {
            return wpilot_mcp3_result( $id, [
                'content' => [[ 'type' => 'text', 'text' => $rate_check['message'] ]],
                'isError' => true,
            ] );
        }
    }

    // 3. Bulk delete protection
    if ( function_exists( 'wpilot_mcp_check_bulk_delete' ) ) {
        $bulk_check = wpilot_mcp_check_bulk_delete( $name, $args );
        if ( is_array( $bulk_check ) ) {
            return wpilot_mcp3_result( $id, [
                'content' => [[ 'type' => 'text', 'text' => $bulk_check['message'] ]],
                'isError' => true,
            ] );
        }
    }

    // 4. Full site snapshot before major operations
    if ( function_exists( 'wpilot_mcp_major_operations' ) && function_exists( 'wpilot_mcp_save_full_snapshot' ) ) {
        // Resolve the actual individual tool that will run
        $action = $args['action'] ?? '';
        $major_ops = wpilot_mcp_major_operations();
        // Check if any tool in the route is a major operation
        if ( in_array( $name, ['design'], true ) && in_array( $action, ['blueprint','apply','reset'], true ) ) {
            wpilot_mcp_save_full_snapshot( "{$name}:{$action}" );
        } elseif ( in_array( $name, ['themes'], true ) && in_array( $action, ['activate'], true ) ) {
            wpilot_mcp_save_full_snapshot( "{$name}:{$action}" );
        } elseif ( in_array( $name, ['css'], true ) && $action === 'reset' ) {
            wpilot_mcp_save_full_snapshot( "{$name}:{$action}" );
        }
    }

    // 5. Capability check — destructive ops require WordPress admin
    $destructive_tools = [ 'users', 'plugins', 'themes', 'settings', 'security', 'woo_settings' ];
    $destructive_actions = [ 'delete', 'remove', 'reset', 'install', 'activate',
        'deactivate', 'update', 'create_admin', 'bulk_delete' ];
    $tool_action = $args['action'] ?? '';
    if ( in_array( $name, $destructive_tools, true ) && in_array( $tool_action, $destructive_actions, true ) ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return wpilot_mcp3_result( $id, [
                'content' => [[ 'type' => 'text', 'text' => 'This operation requires WordPress administrator privileges.' ]],
                'isError' => true,
            ] );
        }
    }

    // 5. Load heavy modules
    if ( function_exists( 'wpilot_load_heavy' ) ) wpilot_load_heavy();

    // 6. Execute with timing
    $start = microtime( true );

    $result = wpilot_mcp_route_tool( $name, $args );

    $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

    // 4. Format result
    $success = is_array( $result ) && ! empty( $result['success'] );
    $message = is_array( $result ) ? ( $result['message'] ?? 'No message' ) : (string) $result;
    $data    = is_array( $result ) ? ( $result['data'] ?? null ) : null;

    // Build text output
    $text = $message;
    if ( $data ) {
        if ( is_array( $data ) || is_object( $data ) ) {
            $text .= "\n\n" . wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        } else {
            $text .= "\n\n" . (string) $data;
        }
    }

    // 5. Audit log
    $action = $args['action'] ?? null;
    if ( function_exists( 'wpilot_mcp_audit_log' ) ) {
        wpilot_mcp_audit_log( $name, $action, $args, substr( $message, 0, 500 ), ! $success, $duration_ms );
    }

    // 6. Training data collection (existing system)
    if ( function_exists( 'wpilot_collect_tool_usage' ) ) {
        wpilot_collect_tool_usage( $name, $args, ! $success, $duration_ms, 'mcp', $success ? '' : $message );
    }

    return wpilot_mcp3_result( $id, [
        'content' => [[ 'type' => 'text', 'text' => $text ]],
        'isError' => ! $success,
    ] );
}

// ── Resources ────────────────────────────────────────────────
function wpilot_mcp3_resources() {
    $resources = [
        [
            'uri'         => 'wpilot://site/info',
            'name'        => 'Site Information',
            'description' => 'WordPress site name, URL, theme, plugins, version info.',
            'mimeType'    => 'application/json',
        ],
        [
            'uri'         => 'wpilot://site/pages',
            'name'        => 'All Pages',
            'description' => 'List of all pages with IDs, titles, URLs, and status.',
            'mimeType'    => 'application/json',
        ],
        [
            'uri'         => 'wpilot://site/posts',
            'name'        => 'Recent Posts',
            'description' => 'Recent blog posts with IDs, titles, dates.',
            'mimeType'    => 'application/json',
        ],
        [
            'uri'         => 'wpilot://site/css',
            'name'        => 'Custom CSS',
            'description' => 'Current custom CSS applied to the site.',
            'mimeType'    => 'text/css',
        ],
        [
            'uri'         => 'wpilot://site/plugins',
            'name'        => 'Active Plugins',
            'description' => 'List of active plugins with versions.',
            'mimeType'    => 'application/json',
        ],
        [
            'uri'         => 'wpilot://site/theme',
            'name'        => 'Theme Info',
            'description' => 'Current theme name, version, and available templates.',
            'mimeType'    => 'application/json',
        ],
    ];

    // Add WooCommerce resource if active
    if ( class_exists( 'WooCommerce' ) ) {
        $resources[] = [
            'uri'         => 'wpilot://site/woocommerce',
            'name'        => 'WooCommerce Status',
            'description' => 'Store currency, product count, order count, payment methods.',
            'mimeType'    => 'application/json',
        ];
    }

    return $resources;
}

function wpilot_mcp3_resource_read( $id, $params ) {
    $uri = $params['uri'] ?? '';

    switch ( $uri ) {
        case 'wpilot://site/info':
            $info = [
                'name'        => get_bloginfo( 'name' ),
                'description' => get_bloginfo( 'description' ),
                'url'         => get_site_url(),
                'home'        => get_home_url(),
                'theme'       => wp_get_theme()->get( 'Name' ),
                'wp_version'  => get_bloginfo( 'version' ),
                'php_version' => phpversion(),
                'language'    => get_locale(),
                'timezone'    => wp_timezone_string(),
                'permalink'   => get_option( 'permalink_structure' ),
                'multisite'   => is_multisite(),
                'wpilot'      => defined( 'CA_VERSION' ) ? CA_VERSION : '?',
            ];
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ]]
            ] );

        case 'wpilot://site/pages':
            $pages = get_pages( [ 'post_status' => 'any', 'number' => 100 ] );
            $list = array_map( fn( $p ) => [
                'id' => $p->ID, 'title' => $p->post_title,
                'url' => get_permalink( $p->ID ), 'status' => $p->post_status,
                'modified' => $p->post_modified,
            ], $pages );
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ]]
            ] );

        case 'wpilot://site/posts':
            $posts = get_posts( [ 'numberposts' => 20, 'post_status' => 'any' ] );
            $list = array_map( fn( $p ) => [
                'id' => $p->ID, 'title' => $p->post_title,
                'date' => $p->post_date, 'status' => $p->post_status,
            ], $posts );
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ]]
            ] );

        case 'wpilot://site/css':
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'text/css', 'text' => wp_get_custom_css() ]]
            ] );

        case 'wpilot://site/plugins':
            $active = get_option( 'active_plugins', [] );
            $list = array_map( function( $p ) {
                $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false );
                return [ 'file' => $p, 'name' => $data['Name'] ?? $p, 'version' => $data['Version'] ?? '?' ];
            }, $active );
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $list, JSON_PRETTY_PRINT ) ]]
            ] );

        case 'wpilot://site/theme':
            $theme = wp_get_theme();
            $info = [
                'name'      => $theme->get( 'Name' ),
                'version'   => $theme->get( 'Version' ),
                'author'    => $theme->get( 'Author' ),
                'template'  => $theme->get_template(),
                'stylesheet'=> $theme->get_stylesheet(),
            ];
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $info, JSON_PRETTY_PRINT ) ]]
            ] );

        case 'wpilot://site/woocommerce':
            if ( ! class_exists( 'WooCommerce' ) ) {
                return wpilot_mcp3_error( $id, -32602, 'WooCommerce is not active.' );
            }
            $info = [
                'currency'        => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'product_count'   => (int) wp_count_posts( 'product' )->publish,
                'order_count'     => (int) wp_count_posts( 'shop_order' )->{'wc-completed'} ?? 0,
                'store_address'   => get_option( 'woocommerce_store_address' ),
                'store_city'      => get_option( 'woocommerce_store_city' ),
                'store_country'   => get_option( 'woocommerce_default_country' ),
            ];
            return wpilot_mcp3_result( $id, [
                'contents' => [[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $info, JSON_PRETTY_PRINT ) ]]
            ] );

        default:
            return wpilot_mcp3_error( $id, -32602, "Unknown resource: {$uri}" );
    }
}

// ── Prompts ──────────────────────────────────────────────────
function wpilot_mcp3_prompts() {
    return [
        [
            'name'        => 'build_site',
            'description' => 'Build a complete website for a business — pages, design, navigation, SEO.',
            'arguments'   => [
                [ 'name' => 'business_type', 'description' => 'Type of business (restaurant, salon, electrician, etc)', 'required' => true ],
                [ 'name' => 'business_name', 'description' => 'Name of the business', 'required' => false ],
                [ 'name' => 'style', 'description' => 'Design style (modern, minimal, luxury, playful)', 'required' => false ],
            ],
        ],
        [
            'name'        => 'improve_page',
            'description' => 'Analyze and improve a specific page — design, content, SEO, responsive.',
            'arguments'   => [
                [ 'name' => 'page_id', 'description' => 'Page ID to improve', 'required' => false ],
            ],
        ],
        [
            'name'        => 'seo_fix',
            'description' => 'Full SEO audit and fix — meta titles, descriptions, schema, images, links.',
        ],
        [
            'name'        => 'setup_store',
            'description' => 'Set up a WooCommerce store — products, categories, shipping, payments, checkout.',
            'arguments'   => [
                [ 'name' => 'store_type', 'description' => 'Type of store (clothing, food, digital, etc)', 'required' => false ],
            ],
        ],
        [
            'name'        => 'security_audit',
            'description' => 'Full security audit — scan, fix vulnerabilities, set headers, configure firewall.',
        ],
    ];
}

function wpilot_mcp3_prompt_get( $id, $params ) {
    $name = $params['name'] ?? '';
    $pargs = $params['arguments'] ?? [];

    $prompts = [
        'build_site' => function() use ( $pargs ) {
            $type  = $pargs['business_type'] ?? 'business';
            $bname = $pargs['business_name'] ?? '';
            $style = $pargs['style'] ?? 'modern';
            return "Build a complete, premium {$style} website for a {$type}" .
                ( $bname ? " called \"{$bname}\"" : "" ) .
                ".\n\nSteps:\n1. Use site_info to understand current setup\n2. Use check_frontend to analyze current state\n3. Create homepage with hero, services, testimonials, CTA using pages tool\n4. Create sub-pages: Services, About, Contact\n5. Set up navigation with menus tool\n6. Apply design (colors, fonts, logo) with design tool\n7. Add custom CSS for premium look\n8. Upload relevant images\n9. Run seo audit and fix everything\n10. Final check_frontend to verify";
        },
        'improve_page' => function() use ( $pargs ) {
            $pid = $pargs['page_id'] ?? '';
            return "Improve this WordPress page" . ( $pid ? " (ID: {$pid})" : "" ) .
                ":\n1. Use check_frontend to see current state\n2. Read the page with pages tool (action: read)\n3. Improve design, content, typography, spacing\n4. Add responsive CSS\n5. Fix layout issues\n6. Verify with check_frontend";
        },
        'seo_fix' => function() {
            return "Fix all SEO issues on this WordPress site:\n1. Run seo tool (action: audit)\n2. Fix every issue found — meta titles, descriptions\n3. Add schema markup where missing\n4. Check and fix image alt texts with images tool\n5. Check broken links\n6. Verify robots.txt and sitemap\n7. Run seo audit again to confirm all fixed";
        },
        'setup_store' => function() use ( $pargs ) {
            $type = $pargs['store_type'] ?? 'online store';
            return "Set up a WooCommerce {$type}:\n1. Use site_info to check WooCommerce status\n2. Configure store settings with woo_settings\n3. Set up shipping zones with woo_shipping\n4. Configure payment methods\n5. Create product categories\n6. Add sample products with woo_products\n7. Set up checkout flow\n8. Design the shop page\n9. Configure email templates\n10. Run a test to verify";
        },
        'security_audit' => function() {
            return "Full security audit:\n1. Run security tool (action: scan)\n2. Fix all vulnerabilities found\n3. Add security headers\n4. Disable XML-RPC if not needed\n5. Check for failed login attempts\n6. Configure firewall rules\n7. Verify file permissions\n8. Run scan again to confirm";
        },
    ];

    if ( ! isset( $prompts[$name] ) ) {
        return wpilot_mcp3_error( $id, -32602, "Unknown prompt: {$name}" );
    }

    return wpilot_mcp3_result( $id, [
        'messages' => [[
            'role'    => 'user',
            'content' => [[ 'type' => 'text', 'text' => $prompts[$name]() ]],
        ]],
    ] );
}

// ── JSON-RPC Helpers ─────────────────────────────────────────
function wpilot_mcp3_result( $id, $result ) {
    return [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ];
}

function wpilot_mcp3_error( $id, $code, $message ) {
    return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ];
}
