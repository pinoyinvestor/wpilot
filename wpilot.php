<?php
/**
 * Plugin Name:  WPilot — Powered by Claude
 * Plugin URI:   https://weblease.se/wpilot
 * Description:  Connect Claude Code to your WordPress site. Full control via one MCP endpoint.
 * Version:      4.0.0
 * Author:       Weblease
 * Author URI:   https://weblease.se
 * License:      GPL-2.0+
 * Text Domain:  wpilot
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPILOT_VERSION', '4.0.0' );
define( 'WPILOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPILOT_FREE_LIMIT', 10 );

// ══════════════════════════════════════════════════════════════
//  HOOKS
// ══════════════════════════════════════════════════════════════

add_action( 'rest_api_init', 'wpilot_register_routes' );
add_action( 'admin_menu', 'wpilot_register_admin' );
add_action( 'admin_init', 'wpilot_handle_actions' );
add_action( 'admin_enqueue_scripts', 'wpilot_admin_styles' );

// Redirect to onboarding on first activation
register_activation_hook( __FILE__, function () {
    add_option( 'wpilot_do_activation_redirect', true );
});
add_action( 'admin_init', function () {
    if ( get_option( 'wpilot_do_activation_redirect', false ) ) {
        delete_option( 'wpilot_do_activation_redirect' );
        wp_redirect( admin_url( 'admin.php?page=wpilot' ) );
        exit;
    }
});

// ══════════════════════════════════════════════════════════════
//  MCP REST ROUTE
// ══════════════════════════════════════════════════════════════

function wpilot_register_routes() {
    register_rest_route( 'wpilot/v1', '/mcp', [
        'methods'             => [ 'GET', 'POST', 'DELETE' ],
        'callback'            => 'wpilot_mcp_endpoint',
        'permission_callback' => '__return_true',
    ]);
}

// ══════════════════════════════════════════════════════════════
//  TOKEN SYSTEM — Multiple tokens with roles
// ══════════════════════════════════════════════════════════════

/**
 * Tokens stored in wp_option 'wpilot_tokens' as:
 * [
 *   { hash, role, label, created, last_used }
 * ]
 * role = 'developer' | 'client'
 */

function wpilot_get_tokens() {
    return get_option( 'wpilot_tokens', [] );
}

function wpilot_save_tokens( $tokens ) {
    update_option( 'wpilot_tokens', $tokens );
}

/**
 * style = 'simple' | 'technical'
 * simple = warm, human, no code — for anyone
 * technical = direct, shows IDs/functions — for devs who want it
 */
function wpilot_create_token( $style, $label ) {
    $raw   = 'wpi_' . bin2hex( random_bytes( 32 ) );
    // Built by Weblease
    $hash  = hash( 'sha256', $raw );
    $tokens = wpilot_get_tokens();

    $tokens[] = [
        'hash'      => $hash,
        'style'     => $style,
        'label'     => $label,
        'created'   => current_time( 'Y-m-d H:i' ),
        'last_used' => null,
    ];

    wpilot_save_tokens( $tokens );
    return $raw;
}

/**
 * Validate token and return its data array, or false.
 * Also updates last_used timestamp.
 */
function wpilot_validate_token( $raw ) {
    if ( empty( $raw ) ) return false;

    $hash   = hash( 'sha256', $raw );
    $tokens = wpilot_get_tokens();

    foreach ( $tokens as $i => $t ) {
        if ( hash_equals( $t['hash'], $hash ) ) {
            $tokens[ $i ]['last_used'] = current_time( 'Y-m-d H:i' );
            wpilot_save_tokens( $tokens );
            return $t;
        }
    }

    return false;
}

function wpilot_revoke_token( $hash ) {
    $tokens = wpilot_get_tokens();
    $tokens = array_values( array_filter( $tokens, fn( $t ) => $t['hash'] !== $hash ) );
    wpilot_save_tokens( $tokens );
}

function wpilot_get_bearer_token() {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

    if ( function_exists( 'apache_request_headers' ) && empty( $header ) ) {
        $h = apache_request_headers();
        $header = $h['Authorization'] ?? $h['authorization'] ?? '';
    }

    return preg_match( '/^Bearer\s+(.+)$/i', $header, $m ) ? trim( $m[1] ) : '';
}

// ══════════════════════════════════════════════════════════════
//  LICENSE — Validated against weblease.se
// ══════════════════════════════════════════════════════════════

function wpilot_check_license() {
    $key = get_option( 'wpilot_license_key', '' );
    if ( empty( $key ) ) return 'free';

    $cached = get_transient( 'wpilot_license_status' );
    if ( $cached !== false ) return $cached;

    $response = wp_remote_post( 'https://weblease.se/ai-license/validate', [
        'timeout' => 10,
        'body'    => [
            'license_key' => $key,
            'site_url'    => get_site_url(),
            'plugin'      => 'wpilot',
            'version'     => WPILOT_VERSION,
        ],
    ]);

    if ( is_wp_error( $response ) ) {
        set_transient( 'wpilot_license_status', 'valid', 300 );
        return 'valid';
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $status = ( $body['valid'] ?? false ) ? 'valid' : 'expired';
    set_transient( 'wpilot_license_status', $status, 3600 );
    return $status;
}

// ══════════════════════════════════════════════════════════════
//  MCP SERVER — JSON-RPC 2.0
// ══════════════════════════════════════════════════════════════

function wpilot_mcp_endpoint( $request ) {
    header( 'Cache-Control: no-store' );
    header( 'X-Content-Type-Options: nosniff' );

    $method = $request->get_method();

    if ( $method === 'GET' ) {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'error'   => [ 'code' => -32600, 'message' => 'Use POST with JSON-RPC.' ],
        ], 200 );
    }

    if ( $method === 'DELETE' ) {
        return new WP_REST_Response( null, 204 );
    }

    // Auth — validate token and get token data
    $raw_token  = wpilot_get_bearer_token();
    $token_data = wpilot_validate_token( $raw_token );

    if ( ! $token_data ) {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'error'   => [ 'code' => -32000, 'message' => 'Unauthorized — invalid or missing API token.' ],
            'id'      => null,
        ], 401 );
    }

    $style = $token_data['style'] ?? 'simple';

    // Rate limit: 60 req/min
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_key = 'wpilot_rl_' . md5( $ip );
    $count  = intval( get_transient( $rl_key ) ?: 0 );
    if ( $count >= 60 ) {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'error'   => [ 'code' => -32000, 'message' => 'Rate limit exceeded. Try again in a minute.' ],
            'id'      => null,
        ], 429 );
    }
    set_transient( $rl_key, $count + 1, 60 );

    // Parse JSON-RPC
    $body   = $request->get_json_params();
    $rpc    = $body['method'] ?? '';
    $params = $body['params'] ?? [];
    $id     = $body['id'] ?? null;

    switch ( $rpc ) {
        case 'initialize':
            return wpilot_rpc_ok( $id, [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => [ 'tools' => (object)[] ],
                'serverInfo'      => [ 'name' => 'wpilot', 'version' => WPILOT_VERSION ],
                'instructions'    => wpilot_system_prompt( $style ),
            ]);

        case 'notifications/initialized':
            return new WP_REST_Response( null, 204 );

        case 'tools/list':
            return wpilot_rpc_ok( $id, [ 'tools' => [ wpilot_tool_definition() ] ] );

        case 'tools/call':
            return wpilot_handle_execute( $id, $params, $style );

        default:
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'error'   => [ 'code' => -32601, 'message' => "Unknown method: {$rpc}" ],
                'id'      => $id,
            ], 200 );
    }
}

function wpilot_rpc_ok( $id, $result ) {
    return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'result' => $result, 'id' => $id ], 200 );
}

function wpilot_rpc_tool_result( $id, $text, $is_error ) {
    return new WP_REST_Response([
        'jsonrpc' => '2.0',
        'result'  => [ 'content' => [ [ 'type' => 'text', 'text' => $text ] ], 'isError' => $is_error ],
        'id'      => $id,
    ], 200 );
}

// ══════════════════════════════════════════════════════════════
//  THE ONE TOOL: execute_php
// ══════════════════════════════════════════════════════════════

function wpilot_tool_definition() {
    return [
        'name'        => 'execute_php',
        'description' => 'Execute PHP code on this WordPress site. Full access to all WordPress functions, $wpdb, WooCommerce, and installed plugin APIs. Use `return` to send data back.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'code' => [
                    'type'        => 'string',
                    'description' => 'PHP code to execute. No <?php tags. Full WordPress context. Use `return` to send data back.',
                ],
            ],
            'required' => [ 'code' ],
        ],
    ];
}

function wpilot_handle_execute( $id, $params, $style = 'simple' ) {
    $code = $params['arguments']['code'] ?? '';

    if ( empty( $code ) ) {
        return wpilot_rpc_tool_result( $id, 'No code provided.', true );
    }

    // ── License check ──
    $license = wpilot_check_license();

    if ( $license === 'expired' ) {
        return wpilot_rpc_tool_result( $id, "Your WPilot license has expired. Renew at weblease.se/wpilot to continue.", true );
    }

    if ( $license === 'free' ) {
        $used = intval( get_option( 'wpilot_free_requests', 0 ) );
        if ( $used >= WPILOT_FREE_LIMIT ) {
            return wpilot_rpc_tool_result( $id,
                "You've used all " . WPILOT_FREE_LIMIT . " free requests. Get a license at weblease.se/wpilot to continue — plans start at \$9/month.",
                true
            );
        }
        update_option( 'wpilot_free_requests', $used + 1 );
    }

    // ── Security: block dangerous operations ──
    $blocked = [
        'exec\s*\(', 'shell_exec\s*\(', 'system\s*\(', 'passthru\s*\(',
        'popen\s*\(', 'proc_open\s*\(', 'pcntl_exec\s*\(',
        'file_put_contents\s*\(', 'fwrite\s*\(', 'fopen\s*\(',
        'unlink\s*\(', 'rmdir\s*\(', 'rename\s*\(',
        '\$wpdb\s*->\s*query\s*\(\s*["\']?\s*(DROP|TRUNCATE|ALTER)',
        'wp-config\.php',
        'wpilot_tokens',          // Can't read/modify own tokens
        'wpilot_license_key',     // Can't read license key
        'wpilot_api_key_hash',    // Legacy — block too
    ];

    foreach ( $blocked as $pattern ) {
        if ( preg_match( '/' . $pattern . '/i', $code ) ) {
            return wpilot_rpc_tool_result( $id, "Blocked for security reasons. Use WordPress functions instead.", true );
        }
    }

    // ── Execute ──
    // NOTE: Intentional eval() — this is the core feature. Authenticated
    // users (token + rate limited + license) can run WordPress PHP.
    // Security: token auth, rate limiting, blocked patterns, no shell/fs.
    @set_time_limit( 30 );
    ob_start();
    $return_value = null;
    $error = null;

    try {
        $fn = function() use ( $code ) {
            return eval( $code ); // Intentional — see security note above
        };
        $return_value = $fn();
    } catch ( \Throwable $e ) {
        $error = $e->getMessage() . ' on line ' . $e->getLine();
    }

    $output = ob_get_clean();

    if ( $error ) {
        return wpilot_rpc_tool_result( $id, "Error: {$error}", true );
    }

    $result = '';
    if ( $return_value !== null && $return_value !== '' ) {
        $result = is_array( $return_value ) || is_object( $return_value )
            ? json_encode( $return_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
            : (string) $return_value;
    }
    if ( ! empty( $output ) ) {
        $result = $result ? $result . "\n\nOutput:\n" . $output : $output;
    }
    if ( empty( $result ) ) {
        $result = 'Done.';
    }
    if ( strlen( $result ) > 50000 ) {
        $result = substr( $result, 0, 50000 ) . "\n\n[Truncated]";
    }

    // ── AI Training: collect anonymized data if consent given ──
    if ( get_option( 'wpilot_training_consent', false ) ) {
        wpilot_collect_training( $code, $result, ! $error );
    }

    return wpilot_rpc_tool_result( $id, $result, false );
}

// ══════════════════════════════════════════════════════════════
//  AI TRAINING DATA COLLECTION
// ══════════════════════════════════════════════════════════════

/**
 * Collect anonymized training data from execute_php calls.
 * Stored locally, flushed to weblease.se every 2 hours.
 * Only runs if user has given consent in settings.
 *
 * What we collect: PHP code patterns + result types (not personal data)
 * What we strip: passwords, emails, API keys, URLs, names
 */
function wpilot_collect_training( $code, $result, $success ) {
    // Anonymize: strip anything that looks sensitive
    $clean_code = wpilot_anonymize( $code );
    $clean_result = wpilot_anonymize( substr( $result, 0, 2000 ) );

    // Skip if code is too short to be useful
    if ( strlen( $clean_code ) < 20 ) return;

    $entry = [
        'code'       => $clean_code,
        'result'     => $clean_result,
        'success'    => $success,
        'wp_version' => get_bloginfo( 'version' ),
        'theme'      => wp_get_theme()->get( 'Name' ),
        'woo'        => class_exists( 'WooCommerce' ) ? 1 : 0,
        'ts'         => time(),
    ];

    $queue = get_option( 'wpilot_training_queue', [] );
    $queue[] = $entry;

    // Keep max 500 entries locally
    if ( count( $queue ) > 500 ) {
        $queue = array_slice( $queue, -500 );
    }

    update_option( 'wpilot_training_queue', $queue, false );

    // Schedule flush every 2 hours
    if ( ! wp_next_scheduled( 'wpilot_flush_training' ) ) {
        wp_schedule_single_event( time() + 7200, 'wpilot_flush_training' );
    }
}

/**
 * Remove sensitive data from code/results before storing.
 */
function wpilot_anonymize( $text ) {
    // Strip email addresses
    $text = preg_replace( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $text );
    // Strip URLs with domains
    $text = preg_replace( '#https?://[^\s"\'<>]+#', '[URL]', $text );
    // Strip anything that looks like a password or key
    $text = preg_replace( '/(?:password|passwd|secret|key|token|api_key|auth)\s*[=:]\s*["\']?[^\s"\'<>,;]+/i', '[REDACTED]', $text );
    // Strip phone numbers
    $text = preg_replace( '/\+?\d[\d\s-]{8,}/', '[PHONE]', $text );
    // Strip IP addresses
    $text = preg_replace( '/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', '[IP]', $text );
    return $text;
}

/**
 * Flush training data to weblease.se (runs via WP cron).
 */
add_action( 'wpilot_flush_training', 'wpilot_flush_training_data' );

function wpilot_flush_training_data() {
    if ( ! get_option( 'wpilot_training_consent', false ) ) return;

    $queue = get_option( 'wpilot_training_queue', [] );
    if ( empty( $queue ) ) return;

    $batch = array_slice( $queue, 0, 100 );

    $response = wp_remote_post( 'https://weblease.se/ai-training/ingest', [
        'timeout' => 15,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'site_hash'      => md5( get_site_url() ),
            'plugin_version' => WPILOT_VERSION,
            'entries'        => $batch,
        ]),
    ]);

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $remaining = array_slice( $queue, 100 );
        update_option( 'wpilot_training_queue', $remaining, false );

        $stats = get_option( 'wpilot_training_stats', [ 'total' => 0, 'batches' => 0 ] );
        $stats['total']   += count( $batch );
        $stats['batches'] += 1;
        $stats['last']     = current_time( 'Y-m-d H:i' );
        update_option( 'wpilot_training_stats', $stats );
    }
}

// Handle training consent toggle
add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_POST['wpilot_action'] ) ) return;
    if ( $_POST['wpilot_action'] !== 'toggle_training' ) return;
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wpilot_admin' ) ) return;

    $consent = isset( $_POST['training_consent'] ) && $_POST['training_consent'] === '1';
    update_option( 'wpilot_training_consent', $consent );

    wp_redirect( admin_url( 'admin.php?page=wpilot&saved=training' ) );
    exit;
}, 5 );

// ══════════════════════════════════════════════════════════════
//  SYSTEM PROMPT — Adapts to style (simple vs technical)
// ══════════════════════════════════════════════════════════════

function wpilot_system_prompt( $style = 'simple' ) {
    $site_name = get_bloginfo( 'name' ) ?: 'this website';
    $site_url  = get_site_url();
    $theme     = wp_get_theme()->get( 'Name' );
    $language  = get_locale();
    $woo       = class_exists( 'WooCommerce' ) ? "\n- WooCommerce is active." : '';
    $profile   = get_option( 'wpilot_site_profile', [] );

    $owner    = $profile['owner_name'] ?? '';
    $business = $profile['business_type'] ?? '';
    $tone     = $profile['tone'] ?? 'friendly and professional';
    $lang     = $profile['language'] ?? '';

    if ( empty( $lang ) ) {
        $lang = str_starts_with( $language, 'sv' ) ? 'Swedish' : 'the same language the user writes in';
    }

    // ── Site context ──
    $prompt = "You are WPilot, a WordPress assistant connected to \"{$site_name}\" ({$site_url}).

SITE CONTEXT:
- Theme: {$theme}
- WordPress language: {$language}{$woo}";

    if ( $owner )    $prompt .= "\n- Owner: {$owner}";
    if ( $business ) $prompt .= "\n- Business: {$business}";

    // ── Communication style ──
    if ( $style === 'technical' ) {
        $prompt .= "

COMMUNICATION:
- Respond in {$lang}.
- Be direct and technical. Show data structures, IDs, function names, hook names.
- When making changes, report specifics (post IDs, option values, queries used).
- If something fails, show the error details and suggest a fix.
- You can discuss architecture and trade-offs.";

    } else {
        $prompt .= "

COMMUNICATION:
- Respond in {$lang}.
- Be {$tone}. Talk like a helpful friend — warm, natural, human.
" . ( $owner ? "- Use \"{$owner}\" when it feels natural.\n" : '' ) . "- NEVER show code, function names, PHP, HTML, CSS, or technical details unless specifically asked. The user cares about WHAT happened, not HOW.
- Good: \"Done! I created a contact page with a form and your phone number.\"
- Bad: \"I called wp_insert_post() with post_type=page and added a wp:html block containing...\"
- If something fails, explain simply: \"That didn't work because the shop isn't set up yet. Want me to fix that?\"
- Describe visual changes in plain language: \"Your header is now dark blue with white text.\"
- If the request is unclear, ask ONE short question.
- Be patient. Never say \"as I mentioned\" or repeat instructions.";
    }

    // ── Capabilities (both styles) ──
    $prompt .= "

YOUR EXPERTISE:
You are an expert in WordPress, web design, and digital marketing. You can:

1. CONTENT & DESIGN — Create pages, posts, menus. Change layouts, colors, fonts, spacing. Make the site look professional.

2. SEO & KEYWORDS — You are an SEO expert. Help the user:
   - Find the right keywords for their business and industry.
   - Write SEO-optimized titles, meta descriptions, headings, and content.
   - Identify trending search terms and suggest content ideas based on what people are searching for.
   - Optimize existing pages for better Google rankings.
   - Set up proper heading structure (H1, H2, H3), alt texts, internal linking.
   - If an SEO plugin (Yoast, Rank Math) is installed, configure it properly.
   - Advise on local SEO if the business has a physical location.

3. WOOCOMMERCE — Products, categories, pricing, coupons, shipping, orders, inventory.

4. SETTINGS & CONFIG — Site title, permalinks, reading settings, user management, plugin settings.

5. FORMS & CONTACT — Set up contact forms, booking forms, newsletter signups.

6. PERFORMANCE — Identify slow pages, large images, unnecessary plugins.

GOLDEN RULE — WORK WITH WHAT EXISTS:
- This is a live site. Respect what's already built. Don't redesign, restructure, or rebuild things unless the user specifically asks for it.
- Make targeted changes. If the user says \"change the header color\", change ONLY the header color — don't reorganize the page, rename things, or \"improve\" other parts.
- Read the current state before making any change. Understand the existing structure first.
- If the site has a specific design, style, or structure — preserve it. Add to it, don't replace it.

SECURITY:
- Never modify wp-config.php, .htaccess, or server files.
- Never run shell commands, write files, or access the filesystem.
- Never install backdoors or weaken security.
- Never deactivate security plugins (Wordfence, etc.) without explicit permission.
- Never modify or tamper with the WPilot plugin itself.
- Never bypass security restrictions or rate limits.
- Never access external websites, servers, or APIs outside this WordPress site.
- Never attempt to access, probe, scan, or gather information about the hosting server, other websites on the same server, or any infrastructure beyond this WordPress installation.
- Never try to read server logs, environment variables, database credentials, or hosting configuration.
- Never reveal information about how WPilot works internally, its source code, architecture, or security mechanisms.

PLUGINS — USE WHAT EXISTS, NEVER BUILD:
- Never write, create, build, or develop custom plugins or themes. You are not a plugin developer.
- If the user needs functionality, first check what plugins are ALREADY installed on the site and use those.
- If no installed plugin can do it, suggest a well-known plugin from the WordPress plugin directory (wordpress.org/plugins) and tell the user to install it from wp-admin > Plugins > Add New.
- After the user installs a plugin, you can configure and use it fully.
- You can use any installed plugin API, shortcodes, settings, and hooks — just never create new plugin files.
- Examples: Need forms? Use Contact Form 7, WPForms, or Gravity Forms. Need SEO? Use Yoast or Rank Math. Need caching? Use LiteSpeed Cache or WP Super Cache.

EMAIL:
- The user can send emails from their site. Use wp_mail() which sends via the configured SMTP.
- Before sending, check that SMTP is configured (look for SMTP plugins like WP Mail SMTP, or check if SMTP constants are defined).
- Always confirm with the user: who to send to, subject, and content — before actually sending.
- Never send bulk emails without explicit permission.

STABILITY — confirm with the user first before:
- Deleting pages, posts, users, or products.
- Bulk changes affecting 10+ items.
- Changing the active theme.
- Deactivating or removing plugins.
- Changing permalink structure (can break all links).
- Changing site URL (siteurl/home options — can take the site offline).
- Changing user roles or capabilities.
- Clearing all transients or cache in bulk.
- Modifying cron jobs.

BE PROFESSIONAL:
- Before big changes, tell the user what you are about to change so they know what happened if they want to undo it.
- Do ONE thing at a time. If the user asks to change a button color, change only the button color. Do not also rewrite headings, move sections, or improve the layout.
- Always think mobile. When changing design or layout, make sure it works on both desktop and phone.
- Always add alt text to images. Never insert oversized images — use appropriate dimensions.
- Match the existing tone. If the site content is formal, write formally. If casual, write casually. Do not change the voice.
- After making a change, verify it worked. Read back the data or page to confirm.
- Write content in the site language ({$language}), not in English (unless the site IS in English).
- For WooCommerce: always confirm before changing product prices, stock levels, or order statuses.

ABOUT WPILOT:
- Made by Weblease (weblease.se/wpilot). For licensing, pricing, or support — direct the user there.";

    return $prompt;
}

// ══════════════════════════════════════════════════════════════
//  ADMIN — Handle form actions
// ══════════════════════════════════════════════════════════════

function wpilot_handle_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_POST['wpilot_action'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wpilot_admin' ) ) return;

    $action = $_POST['wpilot_action'];

    // Save site profile (onboarding)
    if ( $action === 'save_profile' ) {
        update_option( 'wpilot_site_profile', [
            'owner_name'    => sanitize_text_field( $_POST['owner_name'] ?? '' ),
            'business_type' => sanitize_text_field( $_POST['business_type'] ?? '' ),
            'tone'          => sanitize_text_field( $_POST['tone'] ?? 'friendly and professional' ),
            'language'      => sanitize_text_field( $_POST['language'] ?? '' ),
            'completed'     => true,
        ]);
        wp_redirect( admin_url( 'admin.php?page=wpilot&saved=profile' ) );
        exit;
    }

    // Save license
    if ( $action === 'save_license' ) {
        $key = sanitize_text_field( trim( $_POST['license_key'] ?? '' ) );
        if ( $key ) {
            update_option( 'wpilot_license_key', $key );
            delete_transient( 'wpilot_license_status' );
        }
        wp_redirect( admin_url( 'admin.php?page=wpilot&saved=license' ) );
        exit;
    }

    // Remove license
    if ( $action === 'remove_license' ) {
        delete_option( 'wpilot_license_key' );
        delete_transient( 'wpilot_license_status' );
        wp_redirect( admin_url( 'admin.php?page=wpilot' ) );
        exit;
    }

    // Create token
    if ( $action === 'create_token' ) {
        $style = in_array( $_POST['token_style'] ?? '', [ 'simple', 'technical' ] ) ? $_POST['token_style'] : 'simple';
        $label = sanitize_text_field( $_POST['token_label'] ?? '' ) ?: ( $style === 'technical' ? 'Technical' : 'My connection' );
        $raw   = wpilot_create_token( $style, $label );
        set_transient( 'wpilot_new_token', $raw, 120 );
        set_transient( 'wpilot_new_token_style', $style, 120 );
        set_transient( 'wpilot_new_token_label', $label, 120 );
        wp_redirect( admin_url( 'admin.php?page=wpilot&saved=token' ) );
        exit;
    }

    // Revoke token
    if ( $action === 'revoke_token' ) {
        $hash = sanitize_text_field( $_POST['token_hash'] ?? '' );
        if ( $hash ) wpilot_revoke_token( $hash );
        wp_redirect( admin_url( 'admin.php?page=wpilot&saved=revoked' ) );
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
//  ADMIN — Menu + Styles
// ══════════════════════════════════════════════════════════════

function wpilot_register_admin() {
    add_menu_page( 'WPilot', 'WPilot', 'manage_options', 'wpilot', 'wpilot_admin_page', 'dashicons-cloud', 80 );
}

function wpilot_admin_styles( $hook ) {
    if ( $hook !== 'toplevel_page_wpilot' ) return;
    wp_add_inline_style( 'wp-admin', '
        .wpilot-wrap { max-width: 780px; margin: 0 auto; padding: 30px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

        /* Header */
        .wpilot-hero { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); border-radius: 16px; padding: 36px 40px; color: #fff; margin-bottom: 24px; position: relative; overflow: hidden; }
        .wpilot-hero::after { content: ""; position: absolute; top: -50%; right: -20%; width: 300px; height: 300px; background: radial-gradient(circle, rgba(78,201,176,0.15) 0%, transparent 70%); }
        .wpilot-hero h1 { font-size: 32px; font-weight: 700; margin: 0 0 4px; display: flex; align-items: center; gap: 12px; }
        .wpilot-hero .tagline { color: #94a3b8; font-size: 15px; margin: 0; }
        .wpilot-badge { font-size: 11px; background: rgba(78,201,176,0.2); color: #4ec9b0; padding: 4px 12px; border-radius: 20px; font-weight: 600; letter-spacing: 0.02em; }

        /* Cards */
        .wpilot-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 32px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.02); transition: box-shadow 0.2s; }
        .wpilot-card:hover { box-shadow: 0 2px 6px rgba(0,0,0,0.06), 0 8px 24px rgba(0,0,0,0.04); }
        .wpilot-card h2 { margin: 0 0 4px; font-size: 18px; font-weight: 600; display: flex; align-items: center; gap: 8px; color: #1e293b; }
        .wpilot-card .subtitle { color: #64748b; font-size: 14px; margin: 0 0 20px; line-height: 1.5; }

        /* Step indicator */
        .wpilot-step { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #1a1a2e; color: #4ec9b0; font-size: 13px; font-weight: 700; margin-right: 4px; flex-shrink: 0; }
        .wpilot-step-done { background: #22c55e; color: #fff; }
        .wpilot-check { color: #22c55e; font-size: 16px; font-weight: 700; }
        .wpilot-locked { opacity: 0.4; pointer-events: none; filter: grayscale(0.5); }

        /* Form fields */
        .wpilot-field { margin-bottom: 18px; }
        .wpilot-field label { display: block; font-weight: 600; font-size: 13px; color: #374151; margin-bottom: 6px; letter-spacing: 0.01em; }
        .wpilot-field .hint { display: block; font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .wpilot-field input[type=text], .wpilot-field select { width: 100%; max-width: 420px; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 14px; color: #1e293b; background: #fafbfc; transition: all 0.15s; }
        .wpilot-field input:focus, .wpilot-field select:focus { border-color: #4ec9b0; outline: none; box-shadow: 0 0 0 4px rgba(78,201,176,0.12); background: #fff; }
        .wpilot-field input::placeholder { color: #cbd5e1; }

        /* Buttons */
        .wpilot-btn { display: inline-flex; align-items: center; gap: 6px; padding: 11px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; letter-spacing: 0.01em; }
        .wpilot-btn-primary { background: #1a1a2e; color: #fff; }
        .wpilot-btn-primary:hover { background: #2d2d44; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(26,26,46,0.3); }
        .wpilot-btn-green { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; }
        .wpilot-btn-green:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(34,197,94,0.4); }
        .wpilot-btn-danger { background: transparent; border: 1.5px solid #fca5a5; color: #dc2626; font-size: 12px; padding: 6px 14px; border-radius: 8px; }
        .wpilot-btn-danger:hover { background: #fef2f2; }

        /* Alerts */
        .wpilot-alert { padding: 16px 20px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; line-height: 1.5; }
        .wpilot-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .wpilot-alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .wpilot-alert strong { display: block; margin-bottom: 2px; }

        /* Code block */
        .wpilot-code { display: block; padding: 16px 18px; background: #0f172a; color: #4ec9b0; border-radius: 10px; font-size: 13px; font-family: "SF Mono", "Fira Code", Monaco, Consolas, monospace; word-break: break-all; line-height: 1.7; cursor: pointer; transition: all 0.15s; border: 2px solid transparent; position: relative; }
        .wpilot-code:hover { border-color: #4ec9b0; }
        .wpilot-code-hint { display: block; font-size: 11px; color: #64748b; margin-top: 6px; }

        /* Progress bar */
        .wpilot-progress { background: #f1f5f9; border-radius: 8px; height: 10px; margin: 10px 0 20px; overflow: hidden; }
        .wpilot-progress-bar { height: 100%; border-radius: 8px; transition: width 0.4s ease; }

        /* Token table */
        .wpilot-token-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .wpilot-token-table th { text-align: left; font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; padding: 10px 14px; border-bottom: 2px solid #f1f5f9; font-weight: 600; }
        .wpilot-token-table td { padding: 14px; border-bottom: 1px solid #f8fafc; font-size: 14px; color: #475569; }
        .wpilot-token-table tr:hover td { background: #fafbfc; }
        .wpilot-style-badge { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .wpilot-style-simple { background: #e0f2fe; color: #0284c7; }
        .wpilot-style-technical { background: #ede9fe; color: #7c3aed; }

        /* Guide steps */
        .wpilot-guide { display: grid; grid-template-columns: 40px 1fr; gap: 0 16px; margin: 20px 0; }
        .wpilot-guide-num { width: 32px; height: 32px; border-radius: 50%; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; margin-top: 2px; }
        .wpilot-guide-step { padding-bottom: 20px; border-left: 2px solid #f1f5f9; margin-left: -32px; padding-left: 48px; }
        .wpilot-guide-step:last-child { border: none; }
        .wpilot-guide-step h3 { margin: 0 0 4px; font-size: 15px; font-weight: 600; color: #1e293b; }
        .wpilot-guide-step p { margin: 0; font-size: 13px; color: #64748b; line-height: 1.5; }

        /* Examples grid */
        .wpilot-examples-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        @media (max-width: 600px) { .wpilot-examples-grid { grid-template-columns: 1fr; } }
        .wpilot-example-cat { background: #f8fafc; border-radius: 10px; padding: 18px 20px; border: 1px solid #f1f5f9; }
        .wpilot-example-cat h3 { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 10px; font-weight: 600; }
        .wpilot-example-cat p { margin: 0; padding: 6px 0; color: #475569; font-size: 13px; font-style: italic; line-height: 1.5; border-bottom: 1px solid #f1f5f9; }
        .wpilot-example-cat p:last-child { border: none; }

        /* Pricing */
        .wpilot-pricing { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 20px; }
        @media (max-width: 700px) { .wpilot-pricing { grid-template-columns: 1fr; } }
        .wpilot-plan { background: #fff; border: 2px solid #e2e8f0; border-radius: 14px; padding: 28px 24px; text-align: center; transition: all 0.2s; position: relative; }
        .wpilot-plan:hover { border-color: #4ec9b0; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .wpilot-plan-popular { border-color: #4ec9b0; box-shadow: 0 4px 16px rgba(78,201,176,0.15); }
        .wpilot-plan-popular::before { content: "Most popular"; position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, #4ec9b0, #22c55e); color: #fff; font-size: 11px; font-weight: 700; padding: 4px 16px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.05em; }
        .wpilot-plan h3 { margin: 0 0 8px; font-size: 18px; font-weight: 700; color: #1e293b; }
        .wpilot-plan .price { font-size: 36px; font-weight: 800; color: #1a1a2e; margin: 12px 0 4px; }
        .wpilot-plan .price span { font-size: 15px; font-weight: 400; color: #94a3b8; }
        .wpilot-plan .price-note { font-size: 13px; color: #94a3b8; margin: 0 0 16px; }
        .wpilot-plan ul { list-style: none; padding: 0; margin: 0 0 20px; text-align: left; }
        .wpilot-plan ul li { padding: 6px 0; font-size: 13px; color: #475569; display: flex; gap: 8px; align-items: start; }
        .wpilot-plan ul li::before { content: "\\2713"; color: #22c55e; font-weight: 700; flex-shrink: 0; }
        .wpilot-plan .wpilot-btn { width: 100%; justify-content: center; }

        /* Help section */
        .wpilot-help { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 28px 32px; margin-bottom: 20px; }
        .wpilot-help h2 { color: #1e293b; margin: 0 0 16px; font-size: 17px; }
        .wpilot-help-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 600px) { .wpilot-help-grid { grid-template-columns: 1fr; } }
        .wpilot-help-item { display: flex; gap: 12px; }
        .wpilot-help-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .wpilot-help-item h3 { margin: 0; font-size: 14px; font-weight: 600; color: #1e293b; }
        .wpilot-help-item p { margin: 2px 0 0; font-size: 13px; color: #64748b; line-height: 1.4; }
        .wpilot-help-item a { color: #4ec9b0; text-decoration: none; font-weight: 500; }
        .wpilot-help-item a:hover { text-decoration: underline; }
    ' );
}

// ══════════════════════════════════════════════════════════════
//  ADMIN PAGE
// ══════════════════════════════════════════════════════════════

function wpilot_admin_page() {
    $profile     = get_option( 'wpilot_site_profile', [] );
    $onboarded   = ! empty( $profile['completed'] );
    $license_key = get_option( 'wpilot_license_key', '' );
    $license     = ! empty( $license_key ) ? wpilot_check_license() : 'free';
    $has_license = $license === 'valid';
    $free_used   = intval( get_option( 'wpilot_free_requests', 0 ) );
    $tokens      = wpilot_get_tokens();
    $new_token   = get_transient( 'wpilot_new_token' );
    $new_style   = get_transient( 'wpilot_new_token_style' );
    $new_label   = get_transient( 'wpilot_new_token_label' );
    $site_url    = get_site_url();
    $saved       = $_GET['saved'] ?? '';

    ?>
    <div class="wpilot-wrap">

        <?php // ─────────── HERO HEADER ─────────── ?>
        <div class="wpilot-hero">
            <h1>WPilot <span class="wpilot-badge">v<?php echo WPILOT_VERSION; ?></span></h1>
            <p class="tagline">Manage your entire WordPress site with AI. Just talk — Claude does the rest.</p>
            <div style="display:flex;gap:24px;margin-top:20px;flex-wrap:wrap;">
                <div style="background:rgba(255,255,255,0.08);border-radius:10px;padding:14px 18px;flex:1;min-width:200px;">
                    <div style="font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">What you need</div>
                    <div style="font-size:14px;color:#e2e8f0;line-height:1.7;">
                        1. <strong style="color:#4ec9b0;">Claude</strong> — Desktop app or CLI from <a href="https://claude.ai" target="_blank" style="color:#4ec9b0;">claude.ai</a> (subscription required)<br>
                        2. <strong style="color:#4ec9b0;">WPilot license</strong> — from <a href="https://weblease.se/wpilot" target="_blank" style="color:#4ec9b0;">weblease.se</a> (10 free requests to try)
                    </div>
                </div>
            </div>
        </div>

        <?php // ─────────── ALERTS ─────────── ?>
        <?php if ( $saved === 'profile' ): ?>
            <div class="wpilot-alert wpilot-alert-success"><strong>Profile saved!</strong> Now activate your license to get started.</div>
        <?php elseif ( $saved === 'license' ): ?>
            <div class="wpilot-alert wpilot-alert-success"><strong>License activated!</strong> You have unlimited requests. Create a token to connect.</div>
        <?php elseif ( $saved === 'revoked' ): ?>
            <div class="wpilot-alert wpilot-alert-warning"><strong>Token revoked.</strong> That connection will no longer work.</div>
        <?php endif; ?>

        <?php // ─────────── STEP 1: PROFILE ─────────── ?>
        <div class="wpilot-card">
            <h2>
                <span class="wpilot-step <?php echo $onboarded ? 'wpilot-step-done' : ''; ?>"><?php echo $onboarded ? '&#10003;' : '1'; ?></span>
                <?php echo $onboarded ? 'Your Profile' : 'Tell us about yourself'; ?>
            </h2>
            <p class="subtitle"><?php echo $onboarded ? 'Claude uses this to personalize how it talks and what it suggests.' : 'This takes 30 seconds and helps Claude understand your site and how you want to communicate.'; ?></p>

            <form method="post">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="save_profile">

                <div class="wpilot-field">
                    <label for="owner_name">Your name</label>
                    <input type="text" id="owner_name" name="owner_name" value="<?php echo esc_attr( $profile['owner_name'] ?? '' ); ?>" placeholder="e.g. Lisa">
                    <span class="hint">Claude will use your name in conversation.</span>
                </div>

                <div class="wpilot-field">
                    <label for="business_type">What is your site about?</label>
                    <input type="text" id="business_type" name="business_type" value="<?php echo esc_attr( $profile['business_type'] ?? '' ); ?>" placeholder="e.g. Flower shop, restaurant, portfolio, blog...">
                    <span class="hint">Helps Claude suggest the right keywords, design, and content for your industry.</span>
                </div>

                <div class="wpilot-field">
                    <label for="tone">How should Claude talk to you?</label>
                    <select id="tone" name="tone">
                        <?php
                        $tones = [
                            'friendly and professional' => 'Friendly & professional — like a helpful colleague',
                            'casual and relaxed'        => 'Casual & relaxed — like chatting with a friend',
                            'formal and business-like'  => 'Formal & business-like — straight to the point',
                            'warm and personal'         => 'Warm & personal — encouraging and supportive',
                            'short and direct'          => 'Short & direct — just the facts, no small talk',
                        ];
                        $cur = $profile['tone'] ?? 'friendly and professional';
                        foreach ( $tones as $v => $l ) {
                            echo '<option value="' . $v . '"' . selected( $cur, $v, false ) . '>' . $l . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="wpilot-field">
                    <label for="language">What language should Claude respond in?</label>
                    <select id="language" name="language">
                        <?php
                        $langs = [
                            '' => 'Auto-detect (matches whatever language you write in)',
                            'Swedish' => 'Swedish', 'English' => 'English',
                            'Spanish' => 'Spanish', 'German' => 'German', 'French' => 'French',
                            'Norwegian' => 'Norwegian', 'Danish' => 'Danish', 'Finnish' => 'Finnish',
                            'Dutch' => 'Dutch', 'Italian' => 'Italian', 'Portuguese' => 'Portuguese',
                            'Arabic' => 'Arabic', 'Greek' => 'Greek', 'Turkish' => 'Turkish', 'Polish' => 'Polish',
                        ];
                        $cur_l = $profile['language'] ?? '';
                        foreach ( $langs as $v => $l ) {
                            echo '<option value="' . $v . '"' . selected( $cur_l, $v, false ) . '>' . $l . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <button type="submit" class="wpilot-btn wpilot-btn-primary">
                    <?php echo $onboarded ? 'Update Profile' : 'Save & Continue'; ?>
                </button>
            </form>
        </div>

        <?php // ─────────── STEP 2: LICENSE ─────────── ?>
        <div class="wpilot-card <?php echo ! $onboarded ? 'wpilot-locked' : ''; ?>">
            <h2>
                <span class="wpilot-step <?php echo $has_license ? 'wpilot-step-done' : ''; ?>"><?php echo $has_license ? '&#10003;' : '2'; ?></span>
                License
            </h2>

            <?php if ( $has_license ): ?>
                <p class="subtitle">Unlimited requests. You can use Claude as much as you want.</p>
                <form method="post">
                    <?php wp_nonce_field( 'wpilot_admin' ); ?>
                    <input type="hidden" name="wpilot_action" value="remove_license">
                    <button type="submit" class="wpilot-btn wpilot-btn-danger">Remove license</button>
                </form>

            <?php elseif ( $license === 'expired' ): ?>
                <p class="subtitle" style="color:#dc2626;">Your license has expired. Renew it to continue using WPilot.</p>
                <form method="post" style="display:flex;gap:8px;align-items:end;">
                    <?php wp_nonce_field( 'wpilot_admin' ); ?>
                    <input type="hidden" name="wpilot_action" value="save_license">
                    <div class="wpilot-field" style="margin:0;flex:1;">
                        <input type="text" name="license_key" value="<?php echo esc_attr( $license_key ); ?>" placeholder="Paste your new license key here">
                    </div>
                    <button type="submit" class="wpilot-btn wpilot-btn-primary">Update</button>
                </form>
                <p style="margin-top:10px;font-size:13px;"><a href="https://weblease.se/wpilot" target="_blank" style="color:#4ec9b0;font-weight:600;">Renew at weblease.se/wpilot</a></p>

            <?php else: ?>
                <p class="subtitle">You have <strong><?php echo WPILOT_FREE_LIMIT - $free_used; ?> free requests</strong> remaining. Get a license for unlimited use.</p>
                <div class="wpilot-progress">
                    <div class="wpilot-progress-bar" style="width:<?php echo min( 100, ( $free_used / WPILOT_FREE_LIMIT ) * 100 ); ?>%;background:<?php echo $free_used >= WPILOT_FREE_LIMIT ? '#dc2626' : 'linear-gradient(90deg, #4ec9b0, #22c55e)'; ?>;"></div>
                </div>

                <form method="post" style="display:flex;gap:8px;align-items:end;">
                    <?php wp_nonce_field( 'wpilot_admin' ); ?>
                    <input type="hidden" name="wpilot_action" value="save_license">
                    <div class="wpilot-field" style="margin:0;flex:1;">
                        <input type="text" name="license_key" placeholder="Paste your license key here">
                    </div>
                    <button type="submit" class="wpilot-btn wpilot-btn-green">Activate License</button>
                </form>
                <p style="margin-top:14px;font-size:13px;color:#64748b;">
                    No license yet? Choose a plan below or visit <a href="https://weblease.se/wpilot" target="_blank" style="color:#4ec9b0;font-weight:600;">weblease.se/wpilot</a>
                </p>

                <?php // ── Pricing cards ── ?>
                <?php $checkout_base = 'https://weblease.se/wpilot-checkout?site=' . urlencode( $site_url ) . '&email=' . urlencode( get_option( 'admin_email' ) ); ?>
                <div class="wpilot-pricing">
                    <div class="wpilot-plan">
                        <h3>Pro</h3>
                        <div class="price">$9<span>/month</span></div>
                        <p class="price-note">For one website</p>
                        <ul>
                            <li>Unlimited requests</li>
                            <li>1 license = 1 website</li>
                            <li>Unlimited connections per site</li>
                            <li>Simple + Technical mode</li>
                            <li>SEO & keyword expert</li>
                            <li>Email support</li>
                        </ul>
                        <a href="<?php echo esc_url( $checkout_base . '&plan=pro' ); ?>" target="_blank" class="wpilot-btn wpilot-btn-primary">Get Pro</a>
                    </div>

                    <div class="wpilot-plan wpilot-plan-popular">
                        <h3>Team</h3>
                        <div class="price">$29<span>/month</span></div>
                        <p class="price-note">For agencies & multiple sites</p>
                        <ul>
                            <li>Unlimited requests</li>
                            <li>3 licenses = 3 websites</li>
                            <li>Unlimited connections per site</li>
                            <li>Simple + Technical mode</li>
                            <li>SEO & keyword expert</li>
                            <li>Priority support</li>
                        </ul>
                        <a href="<?php echo esc_url( $checkout_base . '&plan=team' ); ?>" target="_blank" class="wpilot-btn wpilot-btn-green">Get Team</a>
                    </div>

                    <div class="wpilot-plan">
                        <h3>Lifetime</h3>
                        <div class="price">$149<span> once</span></div>
                        <p class="price-note">Pay once, use forever</p>
                        <ul>
                            <li>Unlimited requests — forever</li>
                            <li>1 license = 1 website</li>
                            <li>Unlimited connections per site</li>
                            <li>All features included</li>
                            <li>SEO & keyword expert</li>
                            <li>Email support</li>
                        </ul>
                        <a href="<?php echo esc_url( $checkout_base . '&plan=lifetime' ); ?>" target="_blank" class="wpilot-btn wpilot-btn-primary">Get Lifetime</a>
                    </div>
                </div>
                <p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:10px;">Each license is locked to one website and cannot be transferred to another site.</p>
                <div style="background:#f8fafc;border-radius:10px;padding:18px 22px;margin-top:16px;border:1px solid #f1f5f9;">
                    <h3 style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1e293b;">After purchase you get:</h3>
                    <ul style="margin:0;padding:0;list-style:none;font-size:13px;color:#475569;line-height:2;">
                        <li>&#10003; <strong>License key</strong> sent to your email — paste it above to activate</li>
                        <li>&#10003; <strong>MCP connection token</strong> included in the email — ready to paste into Claude</li>
                        <li>&#10003; <strong>Weblease account</strong> at <a href="https://weblease.se/wpilot-account" target="_blank" style="color:#4ec9b0;">weblease.se/wpilot-account</a> — view your license, download keys anytime</li>
                    </ul>
                    <p style="margin:10px 0 0;font-size:12px;color:#94a3b8;">Secure payment via Stripe. Instant delivery.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php // ─────────── STEP 3: CONNECT ─────────── ?>
        <div class="wpilot-card <?php echo ! $onboarded ? 'wpilot-locked' : ''; ?>">
            <h2>
                <span class="wpilot-step <?php echo ! empty( $tokens ) ? 'wpilot-step-done' : ''; ?>"><?php echo ! empty( $tokens ) ? '&#10003;' : '3'; ?></span>
                Connect Claude
            </h2>

            <?php if ( $new_token && $saved === 'token' ): ?>
                <div class="wpilot-alert wpilot-alert-success">
                    <strong>Connection created: <?php echo esc_html( $new_label ); ?></strong>
                    Follow the steps below to connect. The token is only shown once — copy it now.
                </div>

                <div style="display:flex;gap:12px;margin-bottom:20px;">
                    <button type="button" onclick="document.getElementById('guide-desktop').style.display='block';document.getElementById('guide-terminal').style.display='none';this.classList.add('active');this.nextElementSibling.classList.remove('active');" class="wpilot-tab active" style="padding:8px 20px;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:14px;font-weight:600;cursor:pointer;">Claude Desktop App</button>
                    <button type="button" onclick="document.getElementById('guide-terminal').style.display='block';document.getElementById('guide-desktop').style.display='none';this.classList.add('active');this.previousElementSibling.classList.remove('active');" class="wpilot-tab" style="padding:8px 20px;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:14px;font-weight:600;cursor:pointer;">Terminal / CLI</button>
                </div>
                <style>.wpilot-tab.active{background:#1a1a2e !important;color:#4ec9b0 !important;border-color:#1a1a2e !important;}</style>

                <?php // ── Desktop App Guide ── ?>
                <div id="guide-desktop">
                    <div class="wpilot-guide">
                        <div class="wpilot-guide-num">1</div>
                        <div class="wpilot-guide-step">
                            <h3>Open Claude Desktop</h3>
                            <p>Open the Claude app on your computer. You need a Claude subscription (<a href="https://claude.ai" target="_blank" style="color:#4ec9b0;">claude.ai</a>).</p>
                        </div>

                        <div class="wpilot-guide-num">2</div>
                        <div class="wpilot-guide-step">
                            <h3>Go to Settings</h3>
                            <p>Click your profile picture (top right) &rarr; <strong>Settings</strong> &rarr; <strong>Developer</strong> &rarr; <strong>Edit Config</strong>.</p>
                        </div>

                        <div class="wpilot-guide-num">3</div>
                        <div class="wpilot-guide-step">
                            <h3>Add WPilot to your config file</h3>
                            <p>A file opens. Add the following inside <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">"mcpServers"</code>:</p>
                            <code class="wpilot-code" onclick="navigator.clipboard.writeText(this.innerText);this.style.borderColor='#22c55e';" title="Click to copy">"wpilot": {
  "command": "npx",
  "args": ["-y", "mcp-remote", "<?php echo esc_url( $site_url ); ?>/wp-json/wpilot/v1/mcp"],
  "env": {
    "AUTHORIZATION": "Bearer <?php echo esc_html( $new_token ); ?>"
  }
}</code>
                            <span class="wpilot-code-hint">Click to copy. Make sure the JSON is valid (add a comma before if there are other servers).</span>
                        </div>

                        <div class="wpilot-guide-num">4</div>
                        <div class="wpilot-guide-step">
                            <h3>Restart Claude Desktop</h3>
                            <p>Close and reopen the app. You will see a hammer icon — that means WPilot is connected. Start chatting!</p>
                        </div>
                    </div>
                </div>

                <?php // ── Terminal / CLI Guide ── ?>
                <div id="guide-terminal" style="display:none;">
                    <div class="wpilot-guide">
                        <div class="wpilot-guide-num">1</div>
                        <div class="wpilot-guide-step">
                            <h3>Open a terminal</h3>
                            <p>Mac: open <strong>Terminal</strong>. Windows: open <strong>PowerShell</strong> or <strong>Command Prompt</strong>.</p>
                        </div>

                        <div class="wpilot-guide-num">2</div>
                        <div class="wpilot-guide-step">
                            <h3>Paste this command and press Enter</h3>
                            <code class="wpilot-code" onclick="navigator.clipboard.writeText(this.innerText);this.style.borderColor='#22c55e';" title="Click to copy">claude mcp add --transport http wpilot <?php echo esc_url( $site_url ); ?>/wp-json/wpilot/v1/mcp --header "Authorization:Bearer <?php echo esc_html( $new_token ); ?>"</code>
                            <span class="wpilot-code-hint">Click the command to copy it to your clipboard.</span>
                        </div>

                        <div class="wpilot-guide-num">3</div>
                        <div class="wpilot-guide-step">
                            <h3>Start Claude Code</h3>
                            <p>Type <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">claude</code> in your terminal. It is now connected to your site. Just start talking!</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="subtitle">Create a connection token. Each person who uses Claude on this site needs their own token.</p>
            <?php endif; ?>

            <form method="post" style="display:flex;gap:10px;align-items:end;margin-top:20px;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="create_token">

                <div class="wpilot-field" style="margin:0;">
                    <label>Response style</label>
                    <select name="token_style" style="width:280px;">
                        <option value="simple">Simple — friendly language, no technical details</option>
                        <option value="technical">Technical — includes code references and IDs</option>
                    </select>
                </div>

                <div class="wpilot-field" style="margin:0;flex:1;min-width:160px;">
                    <label>Who is this for?</label>
                    <input type="text" name="token_label" placeholder="e.g. Lisa, Office Mac, My phone">
                </div>

                <button type="submit" class="wpilot-btn wpilot-btn-primary" style="margin-bottom:0;">Create Connection</button>
            </form>

            <?php if ( ! empty( $tokens ) ): ?>
                <table class="wpilot-token-table">
                    <thead>
                        <tr><th>Name</th><th>Style</th><th>Created</th><th>Last used</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $tokens as $t ):
                            $s = $t['style'] ?? ( ( $t['role'] ?? '' ) === 'developer' ? 'technical' : 'simple' );
                        ?>
                            <tr>
                                <td style="font-weight:500;"><?php echo esc_html( $t['label'] ?? '—' ); ?></td>
                                <td><span class="wpilot-style-badge wpilot-style-<?php echo esc_attr( $s ); ?>"><?php echo $s === 'technical' ? 'Technical' : 'Simple'; ?></span></td>
                                <td><?php echo esc_html( $t['created'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $t['last_used'] ?? 'Never' ); ?></td>
                                <td style="text-align:right;">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this connection? It will stop working immediately.');">
                                        <?php wp_nonce_field( 'wpilot_admin' ); ?>
                                        <input type="hidden" name="wpilot_action" value="revoke_token">
                                        <input type="hidden" name="token_hash" value="<?php echo esc_attr( $t['hash'] ); ?>">
                                        <button type="submit" class="wpilot-btn wpilot-btn-danger">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php // ─────────── WHAT CAN CLAUDE DO ─────────── ?>
        <div class="wpilot-card">
            <h2>What can you do with WPilot?</h2>
            <p class="subtitle">Once connected, just talk to Claude naturally. Here are some examples:</p>

            <div class="wpilot-examples-grid">
                <div class="wpilot-example-cat">
                    <h3>Design & Content</h3>
                    <p>"Create a contact page with a form"</p>
                    <p>"Make the header dark blue"</p>
                    <p>"Add a hero image to the homepage"</p>
                </div>
                <div class="wpilot-example-cat">
                    <h3>SEO & Keywords</h3>
                    <p>"What keywords should I target?"</p>
                    <p>"Optimize my homepage for Google"</p>
                    <p>"Write a meta description for the About page"</p>
                </div>
                <div class="wpilot-example-cat">
                    <h3>WooCommerce</h3>
                    <p>"Add a product for $49"</p>
                    <p>"Set up a 20% off sale"</p>
                    <p>"How many orders this month?"</p>
                </div>
                <div class="wpilot-example-cat">
                    <h3>Site Management</h3>
                    <p>"Show me all pages on the site"</p>
                    <p>"Change the site title"</p>
                    <p>"Send an email to my team"</p>
                </div>
            </div>
        </div>

        <?php // ─────────── HELP & SUPPORT ─────────── ?>
        <div class="wpilot-help">
            <h2>Need help?</h2>
            <div class="wpilot-help-grid">
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#e0f2fe;">&#128218;</div>
                    <div>
                        <h3>Getting started</h3>
                        <p>Fill in your profile, activate your license, create a connection, and paste the command in Claude. Done!</p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#fef3c7;">&#128272;</div>
                    <div>
                        <h3>Lost your token?</h3>
                        <p>Revoke the old connection above and create a new one. Then paste the new command in Claude.</p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#e0f2fe;">&#128100;</div>
                    <div>
                        <h3>Your account</h3>
                        <p>View your license, download keys, and manage billing at <a href="https://weblease.se/wpilot-account" target="_blank">weblease.se/wpilot-account</a></p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#fef2f2;">&#10060;</div>
                    <div>
                        <h3>Cancel license</h3>
                        <p>Log in at <a href="https://weblease.se/wpilot-account" target="_blank">weblease.se/wpilot-account</a> &rarr; Subscription &rarr; Cancel. Takes effect at end of billing period.</p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#fef3c7;">&#128274;</div>
                    <div>
                        <h3>Forgot password?</h3>
                        <p>Reset it at <a href="https://weblease.se/forgot-password" target="_blank">weblease.se/forgot-password</a> — enter your email and you will get a reset link.</p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#ede9fe;">&#128231;</div>
                    <div>
                        <h3>Email support</h3>
                        <p>Questions or problems? Email us at <a href="mailto:support@weblease.se">support@weblease.se</a> — we reply within 24 hours.</p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#f0fdf4;">&#128270;</div>
                    <div>
                        <h3>Lost your license key?</h3>
                        <p>Check your email (search for "WPilot") or log in at <a href="https://weblease.se/wpilot-account" target="_blank">weblease.se/wpilot-account</a> to view your key.</p>
                    </div>
                </div>
                <div class="wpilot-help-item">
                    <div class="wpilot-help-icon" style="background:#f0fdf4;">&#127760;</div>
                    <div>
                        <h3>Website</h3>
                        <p>Everything about WPilot at <a href="https://weblease.se/wpilot" target="_blank">weblease.se/wpilot</a></p>
                    </div>
                </div>
            </div>
        </div>

        <?php // ─────────── AI TRAINING CONSENT ─────────── ?>
        <?php
        $training_on = get_option( 'wpilot_training_consent', false );
        $training_stats = get_option( 'wpilot_training_stats', [ 'total' => 0, 'batches' => 0, 'last' => 'Never' ] );
        $training_queue = count( get_option( 'wpilot_training_queue', [] ) );
        ?>
        <div class="wpilot-card">
            <h2>AI Training Data</h2>
            <p class="subtitle">Help us build a better AI. When enabled, anonymized usage data is sent to Weblease to improve WPilot for everyone.</p>

            <div style="background:#f8fafc;border-radius:10px;padding:18px 22px;margin-bottom:16px;border:1px solid #f1f5f9;">
                <h3 style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1e293b;">What we collect:</h3>
                <ul style="margin:0;padding:0 0 0 18px;font-size:13px;color:#475569;line-height:1.8;">
                    <li>WordPress function patterns (what Claude does on your site)</li>
                    <li>Success/error rates and result types</li>
                    <li>WordPress version, theme name, WooCommerce active</li>
                </ul>
                <h3 style="margin:12px 0 8px;font-size:14px;font-weight:600;color:#1e293b;">What we never collect:</h3>
                <ul style="margin:0;padding:0 0 0 18px;font-size:13px;color:#475569;line-height:1.8;">
                    <li>Passwords, API keys, or credentials</li>
                    <li>Email addresses, phone numbers, or personal data</li>
                    <li>Your site URL or domain name (hashed only)</li>
                    <li>Customer names, orders, or payment information</li>
                </ul>
            </div>

            <form method="post" style="display:flex;align-items:center;gap:16px;">
                <?php wp_nonce_field( 'wpilot_admin' ); ?>
                <input type="hidden" name="wpilot_action" value="toggle_training">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:500;">
                    <input type="hidden" name="training_consent" value="0">
                    <input type="checkbox" name="training_consent" value="1" <?php checked( $training_on ); ?> style="width:18px;height:18px;accent-color:#4ec9b0;">
                    I agree to share anonymized usage data to improve WPilot
                </label>
                <button type="submit" class="wpilot-btn wpilot-btn-primary" style="padding:8px 18px;font-size:13px;">Save</button>
            </form>

            <?php if ( $training_on ): ?>
                <div style="margin-top:16px;font-size:12px;color:#94a3b8;">
                    Contributions: <?php echo intval( $training_stats['total'] ); ?> sent
                    &middot; <?php echo $training_queue; ?> queued
                    &middot; Last sync: <?php echo esc_html( $training_stats['last'] ?? 'Never' ); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( $saved === 'training' ): ?>
            <div class="wpilot-alert <?php echo $training_on ? 'wpilot-alert-success' : 'wpilot-alert-warning'; ?>">
                <?php echo $training_on ? 'Thank you! AI training data collection is now active.' : 'AI training data collection has been turned off.'; ?>
            </div>
        <?php endif; ?>

        <p style="text-align:center;color:#cbd5e1;font-size:12px;margin-top:8px;">
            WPilot v<?php echo WPILOT_VERSION; ?> &mdash; Powered by Claude &mdash; Made by <a href="https://weblease.se" target="_blank" style="color:#94a3b8;">Weblease</a>
        </p>
    </div>
    <?php
}
