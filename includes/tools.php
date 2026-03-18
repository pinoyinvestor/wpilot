<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Dispatch tool calls ────────────────────────────────────────

/**
 * Track tool errors — sends to weblease.se automatically
 * Rate limited: max 10 reports per hour per site
 */
function wpilot_track_tool_error($tool, $error, $params = []) {
    // Rate limit — max 10 per hour
    $count = intval(get_transient('wpilot_error_count') ?: 0);
    if ($count >= 10) return;
    set_transient('wpilot_error_count', $count + 1, HOUR_IN_SECONDS);

    // Don't report expected failures
    $skip = ['Backup table not found', 'already exists', 'not found', 'No log file'];
    foreach ($skip as $s) { if (stripos($error, $s) !== false) return; }

    // Collect error data (anonymized — no customer content)
    $data = [
        'tool'           => $tool,
        'error'          => substr($error, 0, 500),
        'param_keys'     => array_keys($params), // Only keys, not values
        'wp_version'     => get_bloginfo('version'),
        'php_version'    => PHP_VERSION,
        'plugin_version' => defined('CA_VERSION') ? CA_VERSION : '?',
        'builder'        => function_exists('wpilot_detect_builder') ? wpilot_detect_builder() : '?',
        'memory_limit'   => ini_get('memory_limit'),
        'hosting_tier'   => function_exists('wpilot_hosting_tier') ? wpilot_hosting_tier() : '?',
        'active_plugins' => count(get_option('active_plugins', [])),
        'timestamp'      => date('Y-m-d H:i:s'),
        // Built by Christos Ferlachidis & Daniel Hedenberg
    ];

    // Queue it — don't block the response
    $queue = get_option('wpilot_error_queue', []);
    $queue[] = $data;
    // Keep max 50 in queue
    if (count($queue) > 50) $queue = array_slice($queue, -50);
    update_option('wpilot_error_queue', $queue);
}

/**
 * Send queued errors to weblease.se (runs on shutdown, non-blocking)
 */
function wpilot_flush_error_queue() {
    $queue = get_option('wpilot_error_queue', []);
    if (empty($queue)) return;

    // Send batch
    $response = wp_remote_post('https://weblease.se/plugin/errors', [
        'timeout'  => 5,
        'blocking' => false, // Non-blocking — don't slow down the site
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode([
            'errors'  => $queue,
            'site_id' => md5(get_site_url()), // Anonymized site identifier
        ]),
    ]);

    // Clear queue regardless of success (prevents infinite retry)
    update_option('wpilot_error_queue', []);
}

function wpilot_run_tool( $tool, $params = [] ) {
    // Safety: write crash flag before executing (removed after success)
    $crash_file = WP_CONTENT_DIR . '/wpilot-crash-flag.txt';
    file_put_contents($crash_file, json_encode(['tool' => $tool, 'time' => date('Y-m-d H:i:s')]));
    // Register cleanup on successful completion
    register_shutdown_function(function() use ($crash_file) { @unlink($crash_file); });

    // Try each module in order
    $modules = [
        'wpilot_run_page_tools',
        'wpilot_run_woo_tools',
        'wpilot_run_woo_advanced_tools',
        'wpilot_run_design_tools',
        'wpilot_run_seo_tools',
        'wpilot_run_security_tools',
        'wpilot_run_file_tools',
        'wpilot_run_api_tools',
        'wpilot_run_media_tools',
        'wpilot_run_mobile_nav_tool',
        'wpilot_run_form_tools',
        'wpilot_run_comment_tools',
        'wpilot_run_pwa_tools',
        'wpilot_run_mu_tools',
        'wpilot_run_gdpr_tools',
        'wpilot_run_content_tools',
        'wpilot_run_marketing_tools',
        'wpilot_run_engage_tools',
    ];

    // Built by Christos Ferlachidis & Daniel Hedenberg

    foreach ($modules as $handler) {
        if (function_exists($handler)) {
            $result = $handler($tool, $params);
            if ($result !== null) return $result;
        }
    }

    // Fallback to plugin tools
    if (function_exists('wpilot_run_plugin_tool')) {
        return wpilot_run_plugin_tool($tool, $params);
    }

    wpilot_track_tool_error($tool, 'Unknown tool', $params);
    return wpilot_err("Unknown tool: {$tool}");
}


// ═══════════════════════════════════════════════════════════════
//  WebP Conversion Engine
//  Converts images using WordPress image editor (GD or Imagick)
//  Keeps original file as backup for one-click restore
// ═══════════════════════════════════════════════════════════════

function wpilot_convert_single_webp( $id, $quality = 82 ) {
    $file = get_attached_file( $id );
    if ( ! $file || ! file_exists( $file ) ) return wpilot_err( "Image #{$id} file not found." );

    $mime = get_post_mime_type( $id );
    // Skip if already WebP, SVG, or GIF (animated)
    if ( in_array( $mime, ['image/webp', 'image/svg+xml', 'image/gif'] ) ) {
        return wpilot_ok( "Image #{$id} skipped ({$mime}).", ['skipped' => true] );
    }

    // Only convert JPEG and PNG
    if ( ! in_array( $mime, ['image/jpeg', 'image/png'] ) ) {
        return wpilot_ok( "Image #{$id} skipped — unsupported format.", ['skipped' => true] );
    }

    // Check WebP support
    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) return wpilot_err( "Cannot open image: " . $editor->get_error_message() );

    $supports = $editor->supports_mime_type( 'image/webp' );
    if ( ! $supports ) return wpilot_err( "Server does not support WebP conversion. Install GD with WebP or Imagick." );

    // Save original path for backup
    $original_path = $file;
    $path_info     = pathinfo( $file );
    $webp_path     = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';

    // Keep original as backup
    $backup_path = $file . '.wpilot-backup';
    if ( ! file_exists( $backup_path ) ) {
        copy( $file, $backup_path );
    }

    // Set quality and save as WebP
    $editor->set_quality( $quality );
    $saved = $editor->save( $webp_path, 'image/webp' );
    if ( is_wp_error( $saved ) ) return wpilot_err( "WebP conversion failed: " . $saved->get_error_message() );

    // Calculate size savings
    $original_size = filesize( $original_path );
    $webp_size     = filesize( $webp_path );
    $saved_bytes   = $original_size - $webp_size;
    $saved_pct     = $original_size > 0 ? round( ($saved_bytes / $original_size) * 100 ) : 0;

    // Update WordPress attachment to point to WebP
    update_attached_file( $id, $webp_path );
    wp_update_post( [
        'ID'             => $id,
        'post_mime_type' => 'image/webp',
    ] );

    // Regenerate thumbnails for the WebP version
    $metadata = wp_generate_attachment_metadata( $id, $webp_path );
    wp_update_attachment_metadata( $id, $metadata );

    // Log backup for restore
    wpilot_save_image_backup( $id, $original_path, $backup_path, $mime );

    $size_kb = round( $saved_bytes / 1024 );
    return wpilot_ok(
        "Image #{$id} converted to WebP — saved {$size_kb}KB ({$saved_pct}% smaller).",
        ['id' => $id, 'saved_bytes' => $saved_bytes, 'saved_pct' => $saved_pct]
    );
}

function wpilot_bulk_convert_webp( $quality = 82 ) {
    $images = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png'],
        'numberposts'    => -1,
        'post_status'    => 'inherit',
    ] );

    if ( empty( $images ) ) return wpilot_ok( "No JPEG/PNG images found to convert." );

    // Check WebP support before starting
    $test_img = get_attached_file( $images[0]->ID );
    if ( $test_img ) {
        $editor = wp_get_image_editor( $test_img );
        if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( 'image/webp' ) ) {
            return wpilot_err( "Server does not support WebP. Install PHP GD with WebP support or Imagick." );
        }
    }

    $converted   = 0;
    $skipped     = 0;
    $failed      = 0;
    $total_saved = 0;
    $errors      = [];

    // Process in manageable batches to avoid timeout
    $batch_limit = 50;
    $batch       = array_slice( $images, 0, $batch_limit );

    foreach ( $batch as $img ) {
        $result = wpilot_convert_single_webp( $img->ID, $quality );

        if ( $result['success'] ) {
            if ( ! empty( $result['data']['skipped'] ) ) {
                $skipped++;
            } else {
                $converted++;
                $total_saved += ($result['data']['saved_bytes'] ?? 0);
            }
        } else {
            $failed++;
            $errors[] = "#{$img->ID}: " . ($result['message'] ?? 'unknown error');
        }
    }

    $remaining   = count( $images ) - $batch_limit;
    $saved_mb    = round( $total_saved / (1024 * 1024), 1 );
    $msg         = "Converted {$converted} images to WebP — saved {$saved_mb}MB total.";

    if ( $skipped ) $msg .= " Skipped {$skipped}.";
    if ( $failed )  $msg .= " Failed {$failed}.";
    if ( $remaining > 0 ) $msg .= " {$remaining} more images remaining — run again to continue.";

    return wpilot_ok( $msg, [
        'converted'   => $converted,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'total_saved' => $total_saved,
        'remaining'   => max( 0, $remaining ),
        'errors'      => array_slice( $errors, 0, 5 ),
    ] );
}

// ── Save image backup for restore ──────────────────────────────
function wpilot_save_image_backup( $id, $original_path, $backup_path, $original_mime ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'ca_backups', [
        'tool'        => 'image_webp_convert',
        'target_id'   => $id,
        'target_type' => 'image',
        'data_before' => wp_json_encode( [
            'original_path' => $original_path,
            'backup_path'   => $backup_path,
            'original_mime' => $original_mime,
        ] ),
        'created_at'  => current_time( 'mysql' ),
    ] );
}

// ── Post/CSS snapshot helpers ──────────────────────────────────
function wpilot_save_post_snapshot( $post_id ) {
    global $wpdb;
    $post = get_post($post_id);
    if (!$post) return;
    $wpdb->insert($wpdb->prefix.'ca_backups', [
        'tool'        => 'post_snapshot',
        'target_id'   => $post_id,
        'target_type' => $post->post_type,
        'data_before' => wp_json_encode(['post'=>$post->to_array(),'meta'=>get_post_meta($post_id)]),
        'created_at'  => current_time('mysql'),
    ]);
}

function wpilot_save_css_snapshot() {
    global $wpdb;
    $wpdb->insert($wpdb->prefix.'ca_backups', [
        'tool'        => 'css_snapshot',
        'target_id'   => 0,
        'target_type' => 'custom_css',
        'data_before' => wp_json_encode(['css'=>wp_get_custom_css()]),
        'created_at'  => current_time('mysql'),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  Security Scanner
// ═══════════════════════════════════════════════════════════════

function wpilot_security_scan() {
    $issues = [];
    $score  = 100;

    // 1. Check WordPress version
    $wp_version = get_bloginfo('version');
    $latest = '6.7'; // approximate
    if (version_compare($wp_version, '6.0', '<')) {
        $issues[] = ['severity'=>'critical', 'issue'=>"WordPress {$wp_version} is severely outdated", 'fix'=>'Update WordPress immediately', 'auto_fix'=>false];
        $score -= 25;
    } elseif (version_compare($wp_version, $latest, '<')) {
        $issues[] = ['severity'=>'warning', 'issue'=>"WordPress {$wp_version} — newer version available", 'fix'=>'Update WordPress', 'auto_fix'=>false];
        $score -= 5;
    }

    // 2. Check for outdated plugins
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $update_plugins = get_site_transient('update_plugins');
    if ($update_plugins && !empty($update_plugins->response)) {
        $outdated = count($update_plugins->response);
        $issues[] = ['severity'=>'warning', 'issue'=>"{$outdated} plugins need updates", 'fix'=>'Update all plugins', 'auto_fix'=>false];
        $score -= min(20, $outdated * 4);
    }

    // 3. Check SSL
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $ssl = is_ssl();
    if (!$ssl) {
        $issues[] = ['severity'=>'critical', 'issue'=>'Site is NOT using HTTPS/SSL', 'fix'=>'Install SSL certificate and enable HTTPS', 'auto_fix'=>false];
        $score -= 20;
    }

    // 4. Check debug mode
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $issues[] = ['severity'=>'warning', 'issue'=>'WP_DEBUG is enabled on production', 'fix'=>'Set WP_DEBUG to false in wp-config.php', 'auto_fix'=>false];
        $score -= 10;
    }

    // 5. Check default admin username
    $admin_user = get_user_by('login', 'admin');
    if ($admin_user) {
        $issues[] = ['severity'=>'warning', 'issue'=>'Default "admin" username exists — easy brute force target', 'fix'=>'Create new admin account, transfer content, delete "admin" user', 'auto_fix'=>false];
        $score -= 10;
    }

    // 6. Check file editing
    if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
        $issues[] = ['severity'=>'warning', 'issue'=>'File editing enabled in dashboard — attackers can modify PHP files', 'fix'=>'Add define("DISALLOW_FILE_EDIT", true) to wp-config.php', 'auto_fix'=>false];
        $score -= 5;
    }

    // 7. Check XML-RPC
    $xmlrpc_enabled = true; // default on
    if ($xmlrpc_enabled) {
        $issues[] = ['severity'=>'info', 'issue'=>'XML-RPC is enabled — potential brute force vector', 'fix'=>'Disable XML-RPC if not using Jetpack or mobile apps', 'auto_fix'=>'disable_xmlrpc'];
    }

    // 8. Check for security plugin
    $has_security = false;
    $active = get_option('active_plugins', []);
    foreach ($active as $p) {
        if (preg_match('/wordfence|sucuri|ithemes-security|all-in-one-wp-security|jetpack/i', $p)) {
            $has_security = true;
            break;
        }
    }
    if (!$has_security) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No security plugin installed', 'fix'=>'Install Wordfence (free) for firewall + malware scanning', 'auto_fix'=>false];
        $score -= 10;
    }

    // 9. Check database prefix
    global $wpdb;
    if ($wpdb->prefix === 'wp_') {
        $issues[] = ['severity'=>'info', 'issue'=>'Default database prefix "wp_" — slightly easier to target with SQL injection', 'fix'=>'Consider changing database prefix (advanced)', 'auto_fix'=>false];
    }

    // 10. Check user registration
    if (get_option('users_can_register')) {
        $issues[] = ['severity'=>'warning', 'issue'=>'Open user registration enabled — spam accounts can register', 'fix'=>'Disable registration or add CAPTCHA', 'auto_fix'=>'disable_registration'];
        $score -= 5;
    }

    // 11. Check login attempts (if no protection)
    if (!$has_security) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No brute force protection on login page', 'fix'=>'Install Wordfence or Limit Login Attempts Reloaded (free)', 'auto_fix'=>false];
        $score -= 5;
    }

    // 12. Check for readme.html and license.txt (version disclosure)
    if (file_exists(ABSPATH . 'readme.html')) {
        $issues[] = ['severity'=>'info', 'issue'=>'readme.html exposes WordPress version', 'fix'=>'Delete readme.html from root', 'auto_fix'=>'delete_readme'];
    }

    $score = max(0, $score);
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));

    return wpilot_ok("Security scan complete. Score: {$score}/100 (Grade: {$grade}). Found " . count($issues) . " issues.", [
        'score'  => $score,
        'grade'  => $grade,
        'issues' => $issues,
    ]);
}

function wpilot_fix_security($issue, $params = []) {
    switch ($issue) {
        case 'disable_xmlrpc':
            // Add filter to block XML-RPC
            $mu_dir = WPMU_PLUGIN_DIR;
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            file_put_contents($mu_dir . '/wpilot-disable-xmlrpc.php',
                "<?php\n// WPilot: Disable XML-RPC for security\nadd_filter('xmlrpc_enabled', '__return_false');\n"
            );
            return wpilot_ok("XML-RPC disabled via mu-plugin.");

        case 'disable_registration':
            update_option('users_can_register', 0);
            return wpilot_ok("User registration disabled.");

        case 'delete_readme':
            $file = ABSPATH . 'readme.html';
            if (file_exists($file)) { @unlink($file); }
            $file2 = ABSPATH . 'license.txt';
            if (file_exists($file2)) { @unlink($file2); }
            return wpilot_ok("readme.html and license.txt deleted.");

        /* ── Code Injection (mu-plugin) ──────────────────── */
        case 'add_head_code':
            $code = $params['code'] ?? $params['html'] ?? $params['css'] ?? $params['content'] ?? '';
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-head-styles.php';
            $filepath = $mu_dir . '/' . $filename;
            $new_css = $code;
            $new_css = preg_replace('/<\/?style[^>]*>/i', '', $new_css);
            $new_css = trim($new_css);
            $existing_css = '';
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
                if (preg_match('/\/\* BEGIN CSS \*\/\s*(.*?)\s*\/\* END CSS \*\//s', $content, $m)) {
                    $existing_css = trim($m[1]);
                }
            }
            $new_blocks = [];
            preg_match_all('/([^{}]+)\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $new_css, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $new_blocks[trim($match[1])] = trim($match[2]);
            }
            $existing_blocks = [];
            if (!empty($existing_css)) {
                preg_match_all('/([^{}]+)\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $existing_css, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $existing_blocks[trim($match[1])] = trim($match[2]);
                }
            }
            $merged = array_merge($existing_blocks, $new_blocks);
            $merged_css = '';
            foreach ($merged as $sel => $body) {
                $merged_css .= $sel . " {\n    " . $body . "\n}\n";
            }
            $merged_css = trim($merged_css);
            $php = "<?php\n// WPilot consolidated head styles\nadd_action('wp_head', function() {\n"
                . "echo <<<'WPILOT_CSS'\n<style>\n/* BEGIN CSS */\n"
                . $merged_css . "\n"
                . "/* END CSS */\n</style>\nWPILOT_CSS;\n}, 1);\n";
            file_put_contents($filepath, $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            $sel_count = count($new_blocks);
            return wpilot_ok("CSS merged into consolidated mu-plugin: {$filename} ({$sel_count} selector(s) added/updated, " . count($merged) . " total)");

        case 'add_footer_code':
            $code = $params['code'] ?? $params['html'] ?? $params['content'] ?? '';
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-footer-scripts.php';
            $filepath = $mu_dir . '/' . $filename;
            $existing_code = '';
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
                if (preg_match('/\/\* BEGIN FOOTER \*\/\s*(.*?)\s*\/\* END FOOTER \*\//s', $content, $m)) {
                    $existing_code = trim($m[1]);
                }
            }
            $new_code = trim($code);
            if (!empty($existing_code)) {
                if (strpos($existing_code, $new_code) === false) {
                    $merged_code = $existing_code . "\n" . $new_code;
                } else {
                    $merged_code = $existing_code;
                }
            } else {
                $merged_code = $new_code;
            }
            $php = "<?php\n// WPilot consolidated footer scripts\nadd_action('wp_footer', function() {\n"
                . "echo <<<'WPILOT_HTML'\n/* BEGIN FOOTER */\n"
                . $merged_code . "\n"
                . "/* END FOOTER */\nWPILOT_HTML;\n});\n";
            file_put_contents($filepath, $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Code merged into consolidated mu-plugin: {$filename}");

        case 'cleanup_mu_plugins':
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) return wpilot_ok("No mu-plugins directory found. Nothing to clean up.");
            $head_files = glob($mu_dir . '/wpilot-custom-head-*.php') ?: [];
            $head_files = array_merge($head_files, glob($mu_dir . '/wpilot-*head*.php') ?: []);
            $head_files = array_filter($head_files, function($f) { return basename($f) !== 'wpilot-head-styles.php'; });
            $head_files = array_unique($head_files);
            $footer_files = glob($mu_dir . '/wpilot-custom-footer-*.php') ?: [];
            $footer_files = array_merge($footer_files, glob($mu_dir . '/wpilot-*footer*.php') ?: []);
            $footer_files = array_filter($footer_files, function($f) { return basename($f) !== 'wpilot-footer-scripts.php'; });
            $footer_files = array_unique($footer_files);
            $all_css = '';
            foreach ($head_files as $file) {
                $content = file_get_contents($file);
                if (preg_match("/echo\s+<<<'WPILOT_(?:HTML|CSS)'\s*\n(.*?)\nWPILOT_(?:HTML|CSS);/s", $content, $m)) {
                    $extracted = $m[1];
                    $extracted = preg_replace('/<\/?style[^>]*>/i', '', $extracted);
                    $extracted = preg_replace('/\/\* (?:BEGIN|END) CSS \*\//', '', $extracted);
                    $all_css .= trim($extracted) . "\n";
                }
            }
            $all_footer = '';
            foreach ($footer_files as $file) {
                $content = file_get_contents($file);
                if (preg_match("/echo\s+<<<'WPILOT_(?:HTML|CSS)'\s*\n(.*?)\nWPILOT_(?:HTML|CSS);/s", $content, $m)) {
                    $extracted = $m[1];
                    $extracted = preg_replace('/\/\* (?:BEGIN|END) FOOTER \*\//', '', $extracted);
                    $all_footer .= trim($extracted) . "\n";
                }
            }
            $head_path = $mu_dir . '/wpilot-head-styles.php';
            $existing_css = '';
            if (file_exists($head_path)) {
                $content = file_get_contents($head_path);
                if (preg_match('/\/\* BEGIN CSS \*\/\s*(.*?)\s*\/\* END CSS \*\//s', $content, $m)) {
                    $existing_css = trim($m[1]);
                }
            }
            $all_css = trim($all_css);
            if (!empty($all_css)) {
                $merged_blocks = [];
                $combined = $existing_css . "\n" . $all_css;
                preg_match_all('/([^{}]+)\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $combined, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $merged_blocks[trim($match[1])] = trim($match[2]);
                }
                $merged_css = '';
                foreach ($merged_blocks as $sel => $body) {
                    $merged_css .= $sel . " {\n    " . $body . "\n}\n";
                }
                $merged_css = trim($merged_css);
                $php = "<?php\n// WPilot consolidated head styles\nadd_action('wp_head', function() {\n"
                    . "echo <<<'WPILOT_CSS'\n<style>\n/* BEGIN CSS */\n"
                    . $merged_css . "\n"
                    . "/* END CSS */\n</style>\nWPILOT_CSS;\n}, 1);\n";
                file_put_contents($head_path, $php);
            }
            $footer_path = $mu_dir . '/wpilot-footer-scripts.php';
            $existing_footer = '';
            if (file_exists($footer_path)) {
                $content = file_get_contents($footer_path);
                if (preg_match('/\/\* BEGIN FOOTER \*\/\s*(.*?)\s*\/\* END FOOTER \*\//s', $content, $m)) {
                    $existing_footer = trim($m[1]);
                }
            }
            $all_footer = trim($all_footer);
            if (!empty($all_footer)) {
                if (!empty($existing_footer) && strpos($existing_footer, $all_footer) === false) {
                    $merged_footer = $existing_footer . "\n" . $all_footer;
                } elseif (empty($existing_footer)) {
                    $merged_footer = $all_footer;
                } else {
                    $merged_footer = $existing_footer;
                }
                $php = "<?php\n// WPilot consolidated footer scripts\nadd_action('wp_footer', function() {\n"
                    . "echo <<<'WPILOT_HTML'\n/* BEGIN FOOTER */\n"
                    . $merged_footer . "\n"
                    . "/* END FOOTER */\nWPILOT_HTML;\n});\n";
                file_put_contents($footer_path, $php);
            }
            $deleted = 0;
            foreach (array_merge($head_files, $footer_files) as $file) {
                if (file_exists($file)) { unlink($file); $deleted++; }
            }
            $total_merged = count($head_files) + count($footer_files);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Cleanup complete: {$total_merged} old files merged, {$deleted} deleted. Consolidated into wpilot-head-styles.php and wpilot-footer-scripts.php.");

        case 'add_php_snippet':
            $code = $params['code'] ?? '';
            $hook = sanitize_text_field($params['hook'] ?? 'init');
            $name = sanitize_file_name($params['name'] ?? 'snippet-' . time());
            $priority = intval($params['priority'] ?? 10);
            if (empty($code)) return wpilot_err('Code required.');
            // SAFETY: strip echo/print from snippets — they pollute AJAX responses
            $code = preg_replace('/\becho\s+["\']/','// echo ', $code);
            $code = preg_replace('/\bprint\s*\(/','// print(', $code);
            $code = preg_replace('/\bvar_dump\s*\(/','// var_dump(', $code);
            $code = preg_replace('/\bprint_r\s*\(/','// print_r(', $code);
            // Validate: code must not contain raw HTML tags (common AI mistake)
            if (preg_match('/<[a-z]/i', $code) && !preg_match('/echo|print/', $code)) {
                return wpilot_err('PHP snippet contains HTML. Use add_head_code for HTML or wrap in echo/print for PHP.');
            }
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('" . $hook . "', function() {\n"
                . $code . "\n"
                . "}, " . $priority . ");\n";
            // Validate PHP syntax before saving
            $test = @exec('echo ' . escapeshellarg($php) . ' | php -l 2>&1', $output, $ret);
            if ($ret !== 0 && $ret !== null) {
                return wpilot_err('PHP syntax error in snippet. Not saved. Fix the code and try again.');
            }
            // Wrap code in try/catch so it can never crash WordPress
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('" . $hook . "', function() {\n"
                . "    try {\n"
                . "        " . $code . "\n"
                . "    } catch (\\Throwable \$e) {\n"
                . "        // Auto-disable this snippet if it crashes\n"
                . "        @rename(__FILE__, __FILE__ . '.disabled');\n"
                . "    }\n"
                . "}, " . $priority . ");\n";
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("PHP snippet added via mu-plugin: {$filename} (hook: {$hook})");

        case 'list_snippets':
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            $snippets = [];
            if (is_dir($mu_dir)) {
                foreach (glob($mu_dir . '/wpilot-*.php') as $file) {
                    $content = file_get_contents($file);
                    $snippets[] = [
                        'file' => basename($file),
                        'description' => preg_match('/WPilot: (.+)/', $content, $m) ? $m[1] : basename($file),
                        'size' => filesize($file),
                    ];
                }
            }
            return wpilot_ok(count($snippets) . " WPilot snippets active.", ['snippets' => $snippets]);

        case 'remove_snippet':
            $name = sanitize_file_name($params['name'] ?? '');
            if (empty($name)) return wpilot_err('Snippet name required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            // Try multiple naming patterns
            $tries = [
                $mu_dir . '/' . $name . '.php',
                $mu_dir . '/' . $name,
                $mu_dir . '/wpilot-' . $name . '.php',
                $mu_dir . '/wpilot-' . $name,
            ];
            // Also strip wpilot- prefix if already included
            $stripped = preg_replace('/^wpilot-/', '', $name);
            $tries[] = $mu_dir . '/wpilot-' . $stripped . '.php';
            $tries[] = $mu_dir . '/' . $stripped . '.php';
            foreach ($tries as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                    return wpilot_ok("Snippet removed: " . basename($file));
                }
            }
            return wpilot_err("Snippet not found: {$name}");

        case 'add_security_headers':
            $mu_dir = WPMU_PLUGIN_DIR;
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $code = "<?php\n// WPilot: Security Headers\n"
                . "add_action('send_headers', function() {\n"
                . "    header('X-Content-Type-Options: nosniff');\n"
                . "    header('X-Frame-Options: SAMEORIGIN');\n"
                . "    header('X-XSS-Protection: 1; mode=block');\n"
                . "    header('Referrer-Policy: strict-origin-when-cross-origin');\n"
                . "    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');\n"
                . "});\n";
            file_put_contents($mu_dir . '/wpilot-security-headers.php', $code);
            return wpilot_ok("Security headers added via mu-plugin.");

        default:
            return wpilot_err("Unknown security fix: {$issue}. Manual fix required.");
    }
}

// ═══════════════════════════════════════════════════════════════
//  SEO Audit
// ═══════════════════════════════════════════════════════════════

function wpilot_seo_audit() {
    $issues = [];
    $score  = 100;

    // 1. Check SEO plugin
    $seo_plugin = 'none';
    if (defined('WPSEO_VERSION'))        $seo_plugin = 'yoast';
    elseif (class_exists('RankMath'))    $seo_plugin = 'rankmath';
    elseif (class_exists('AIOSEO\Plugin')) $seo_plugin = 'aioseo';
    if ($seo_plugin === 'none') {
        $issues[] = ['severity'=>'critical', 'issue'=>'No SEO plugin installed', 'fix'=>'Install Rank Math (free) — best free SEO plugin', 'tool'=>'plugin_install', 'params'=>['slug'=>'seo-by-rank-math']];
        $score -= 25;
    }

    // 2. Check pages for missing meta descriptions
    $pages = get_posts(['post_type'=>['page','post'], 'post_status'=>'publish', 'numberposts'=>100]);
    $missing_meta  = 0;
    $missing_title = 0;
    $no_h1         = 0;
    $multiple_h1   = 0;
    $thin_content  = 0;
    $missing_alt_pages = 0;

    foreach ($pages as $p) {
        // Meta description
        $meta = get_post_meta($p->ID, '_yoast_wpseo_metadesc', true)
             ?: get_post_meta($p->ID, 'rank_math_description', true)
             ?: get_post_meta($p->ID, '_aioseo_description', true);
        if (empty($meta)) $missing_meta++;

        // SEO title
        $seo_title = get_post_meta($p->ID, '_yoast_wpseo_title', true)
                  ?: get_post_meta($p->ID, 'rank_math_title', true);
        if (empty($seo_title) && strlen($p->post_title) < 20) $missing_title++;

        // Heading structure
        $h1_count = preg_match_all('/<h1/i', $p->post_content);
        if ($h1_count === 0 && $p->post_type === 'page') $no_h1++;
        if ($h1_count > 1) $multiple_h1++;

        // Thin content
        $word_count = str_word_count(wp_strip_all_tags($p->post_content));
        if ($word_count < 100 && $p->post_type === 'post') $thin_content++;

        // Images without alt in content
        if (preg_match_all('/<img[^>]*alt=["\']\s*["\'][^>]*>/i', $p->post_content, $m)) {
            $missing_alt_pages++;
        }
    }

    if ($missing_meta > 0) {
        $issues[] = ['severity'=>'critical', 'issue'=>"{$missing_meta} pages/posts missing meta description", 'fix'=>'Use bulk_fix_seo to auto-generate descriptions', 'tool'=>'bulk_fix_seo'];
        $score -= min(25, $missing_meta * 3);
    }
    if ($multiple_h1 > 0) {
        $issues[] = ['severity'=>'warning', 'issue'=>"{$multiple_h1} pages have multiple H1 tags", 'fix'=>'Use fix_heading_structure for each page'];
        $score -= min(10, $multiple_h1 * 2);
    }
    if ($thin_content > 0) {
        $issues[] = ['severity'=>'warning', 'issue'=>"{$thin_content} posts have thin content (<100 words)", 'fix'=>'Add more content or noindex these pages'];
        $score -= min(15, $thin_content * 3);
    }

    // 3. Check site title and tagline
    $tagline = get_bloginfo('description');
    if (empty($tagline) || $tagline === 'Just another WordPress site') {
        $issues[] = ['severity'=>'critical', 'issue'=>'Default or empty tagline — Google uses this as fallback description', 'fix'=>'Set a proper site tagline', 'tool'=>'update_tagline'];
        $score -= 15;
    }

    // 4. Check for sitemap
    $sitemap_url = get_site_url() . '/sitemap.xml';
    $sitemap_check = wp_remote_head($sitemap_url, ['timeout'=>5]);
    if (is_wp_error($sitemap_check) || wp_remote_retrieve_response_code($sitemap_check) !== 200) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No sitemap.xml found', 'fix'=>'Install Rank Math or Yoast SEO to auto-generate sitemap'];
        $score -= 10;
    }

    // 5. Check for robots.txt
    $robots_url = get_site_url() . '/robots.txt';
    $robots_check = wp_remote_head($robots_url, ['timeout'=>5]);
    if (is_wp_error($robots_check) || wp_remote_retrieve_response_code($robots_check) !== 200) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No robots.txt found', 'fix'=>'Create robots.txt with proper rules', 'tool'=>'create_robots_txt'];
        $score -= 5;
    }

    // 6. Check permalink structure
    $permalink = get_option('permalink_structure');
    if (empty($permalink) || $permalink === '/?p=%post_id%') {
        $issues[] = ['severity'=>'critical', 'issue'=>'Using default permalink structure (?p=123) — terrible for SEO', 'fix'=>'Set permalink to /%postname%/', 'tool'=>'update_permalink_structure', 'params'=>['structure'=>'/%postname%/']];
        $score -= 20;
    }

    // 7. Missing images alt text
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>-1]);
    $missing_alt = 0;
    foreach ($images as $img) {
        if (empty(get_post_meta($img->ID, '_wp_attachment_image_alt', true))) $missing_alt++;
    }
    if ($missing_alt > 0) {
        $issues[] = ['severity'=>'warning', 'issue'=>"{$missing_alt} images missing alt text", 'fix'=>'Use bulk_fix_alt_text to auto-set from titles', 'tool'=>'bulk_fix_alt_text'];
        $score -= min(15, $missing_alt);
    }

    // 8. Check for 404 page
    $has_404 = false;
    $theme_404 = get_theme_file_path('404.php');
    if (file_exists($theme_404)) $has_404 = true;
    if (!$has_404) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No custom 404 page — visitors see a generic error', 'fix'=>'Create a branded 404 page', 'tool'=>'create_404_page'];
        $score -= 5;
    }

    // 9. Check SSL for SEO
    if (!is_ssl()) {
        $issues[] = ['severity'=>'critical', 'issue'=>'No HTTPS — Google penalizes non-SSL sites in rankings', 'fix'=>'Enable SSL/HTTPS'];
        $score -= 15;
    }

    $score = max(0, $score);
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));

    return wpilot_ok("SEO Audit complete. Score: {$score}/100 (Grade: {$grade}). Found " . count($issues) . " issues.", [
        'score'  => $score,
        'grade'  => $grade,
        'issues' => $issues,
        'stats'  => [
            'total_pages'  => count($pages),
            'missing_meta' => $missing_meta,
            'missing_alt'  => $missing_alt,
            'thin_content' => $thin_content,
            'seo_plugin'   => $seo_plugin,
        ],
    ]);
}

function wpilot_bulk_fix_seo() {
    $pages = get_posts(['post_type'=>['page','post'], 'post_status'=>'publish', 'numberposts'=>100]);
    $fixed = 0;

    foreach ($pages as $p) {
        $meta = get_post_meta($p->ID, '_yoast_wpseo_metadesc', true)
             ?: get_post_meta($p->ID, 'rank_math_description', true)
             ?: get_post_meta($p->ID, '_aioseo_description', true);

        if (empty($meta)) {
            // Auto-generate from content
            $content = wp_strip_all_tags($p->post_content);
            $desc = wp_trim_words($content, 25, '...');
            if (strlen($desc) < 50) $desc = $p->post_title . ' — ' . $desc;
            $desc = substr($desc, 0, 160);

            update_post_meta($p->ID, '_yoast_wpseo_metadesc', $desc);
            update_post_meta($p->ID, 'rank_math_description', $desc);
            update_post_meta($p->ID, '_aioseo_description', $desc);
            $fixed++;
        }
    }

    return wpilot_ok("Auto-generated meta descriptions for {$fixed} pages/posts.");
}

// ═══════════════════════════════════════════════════════════════
//  Site Health Check
// ═══════════════════════════════════════════════════════════════

function wpilot_site_health_check() {
    $checks = [];

    // PHP version
    $php = PHP_VERSION;
    $checks['php'] = [
        'label' => "PHP {$php}",
        'status' => version_compare($php, '8.0', '>=') ? 'good' : (version_compare($php, '7.4', '>=') ? 'warning' : 'critical'),
        'note' => version_compare($php, '8.0', '<') ? 'Upgrade to PHP 8.0+ for better performance' : 'Up to date',
    ];

    // WordPress version
    $wp = get_bloginfo('version');
    $checks['wordpress'] = [
        'label' => "WordPress {$wp}",
        'status' => version_compare($wp, '6.0', '>=') ? 'good' : 'warning',
    ];

    // Memory limit
    $memory = ini_get('memory_limit');
    $memory_mb = wp_convert_hr_to_bytes($memory) / 1048576;
    $checks['memory'] = [
        'label' => "Memory limit: {$memory}",
        'status' => $memory_mb >= 256 ? 'good' : ($memory_mb >= 128 ? 'warning' : 'critical'),
        'note' => $memory_mb < 256 ? 'Increase to at least 256M' : 'Sufficient',
    ];

    // Max execution time
    $max_exec = ini_get('max_execution_time');
    $checks['execution_time'] = [
        'label' => "Max execution time: {$max_exec}s",
        'status' => $max_exec >= 60 ? 'good' : 'warning',
    ];

    // Active plugins count
    $plugin_count = count(get_option('active_plugins', []));
    $checks['plugins'] = [
        'label' => "{$plugin_count} active plugins",
        'status' => $plugin_count <= 15 ? 'good' : ($plugin_count <= 25 ? 'warning' : 'critical'),
        'note' => $plugin_count > 20 ? 'Too many plugins slow down the site. Audit and remove unused ones.' : 'Reasonable count',
    ];

    // Database size
    global $wpdb;
    $db_size = $wpdb->get_var("SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $checks['database'] = [
        'label' => "Database: {$db_size}MB",
        'status' => $db_size < 500 ? 'good' : ($db_size < 1000 ? 'warning' : 'critical'),
        'note' => $db_size > 500 ? 'Consider database optimization' : 'Normal size',
    ];

    // Uploads folder size (estimate)
    $upload_dir = wp_upload_dir();
    $checks['uploads'] = [
        'label' => 'Upload path: ' . $upload_dir['basedir'],
        'status' => 'good',
    ];

    // Caching
    $has_cache = false;
    foreach (get_option('active_plugins', []) as $p) {
        if (preg_match('/wp-rocket|litespeed|w3-total-cache|wp-super-cache|autoptimize|wp-fastest-cache/i', $p)) {
            $has_cache = true;
            break;
        }
    }
    $checks['caching'] = [
        'label' => $has_cache ? 'Cache plugin active' : 'No cache plugin',
        'status' => $has_cache ? 'good' : 'warning',
        'note' => $has_cache ? '' : 'Install LiteSpeed Cache (free) or WP Super Cache for faster load times',
    ];

    // Object cache
    $checks['object_cache'] = [
        'label' => wp_using_ext_object_cache() ? 'Object cache: active' : 'Object cache: not configured',
        'status' => wp_using_ext_object_cache() ? 'good' : 'info',
    ];

    $good = count(array_filter($checks, fn($c) => $c['status'] === 'good'));
    $total = count($checks);

    return wpilot_ok("Site health: {$good}/{$total} checks passed.", ['checks' => $checks]);
}

// ── Apply custom robots.txt ────────────────────────────────
add_filter('robots_txt', function($output, $public) {
    $custom = get_option('wpi_custom_robots_txt', '');
    if (!empty($custom)) return $custom;
    return $output;
}, 10, 2);

// ── Output schema markup in head ───────────────────────────
add_action('wp_head', function() {
    if (!is_singular()) return;
    $schema = get_post_meta(get_the_ID(), '_wpi_schema_markup', true);
    if ($schema) {
        echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
    }
    // Open Graph
    $og_title = get_post_meta(get_the_ID(), '_wpi_og_title', true);
    $og_desc  = get_post_meta(get_the_ID(), '_wpi_og_description', true);
    $og_image = get_post_meta(get_the_ID(), '_wpi_og_image', true);
    if ($og_title) echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
    if ($og_desc)  echo '<meta property="og:description" content="' . esc_attr($og_desc) . '">' . "\n";
    if ($og_image) echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
});

// ═══════════════════════════════════════════════════════════════
//  WooCommerce Dashboard & Analytics
// ═══════════════════════════════════════════════════════════════

function wpilot_woo_dashboard($p) {
    $period = sanitize_text_field($p['period'] ?? 'today');

    switch ($period) {
        case 'today':     $after = date('Y-m-d 00:00:00'); break;
        case 'yesterday': $after = date('Y-m-d 00:00:00', strtotime('-1 day')); break;
        case 'week':      $after = date('Y-m-d 00:00:00', strtotime('-7 days')); break;
        case 'month':     $after = date('Y-m-d 00:00:00', strtotime('-30 days')); break;
        case 'year':      $after = date('Y-m-d 00:00:00', strtotime('-365 days')); break;
        default:          $after = date('Y-m-d 00:00:00');
    }

    $orders = wc_get_orders([
        'date_created' => '>' . strtotime($after),
        'limit'  => -1,
        'status' => ['wc-completed', 'wc-processing', 'wc-on-hold'],
    ]);

    $total_revenue = 0;
    $total_orders  = count($orders);
    $total_items   = 0;
    $statuses      = ['completed'=>0, 'processing'=>0, 'on-hold'=>0, 'refunded'=>0];

    foreach ($orders as $order) {
        $total_revenue += floatval($order->get_total());
        $total_items   += $order->get_item_count();
        $status = str_replace('wc-', '', $order->get_status());
        if (isset($statuses[$status])) $statuses[$status]++;
    }

    // Built by Christos Ferlachidis & Daniel Hedenberg
    $avg_order = $total_orders > 0 ? round($total_revenue / $total_orders, 2) : 0;

    // Refunded orders
    $refunds = wc_get_orders([
        'date_created' => '>' . strtotime($after),
        'limit' => -1,
        'status' => 'wc-refunded',
    ]);
    $refund_total = 0;
    foreach ($refunds as $r) $refund_total += floatval($r->get_total());
    $statuses['refunded'] = count($refunds);

    // Total customers
    $customers = count(get_users(['role'=>'customer', 'date_query'=>[['after'=>$after]]]));

    // Low stock products
    $low_stock = [];
    $products = wc_get_products(['limit'=>-1, 'stock_status'=>'instock']);
    foreach ($products as $prod) {
        $stock = $prod->get_stock_quantity();
        if ($stock !== null && $stock <= 5 && $stock > 0) {
            $low_stock[] = ['name'=>$prod->get_name(), 'stock'=>$stock, 'id'=>$prod->get_id()];
        }
    }

    $currency = get_woocommerce_currency_symbol();

    return wpilot_ok("WooCommerce Dashboard ({$period}):\n\n" .
        "Revenue: {$currency}" . number_format($total_revenue, 2) . "\n" .
        "Orders: {$total_orders} (avg: {$currency}{$avg_order})\n" .
        "Items sold: {$total_items}\n" .
        "New customers: {$customers}\n" .
        ($refund_total > 0 ? "Refunds: {$currency}" . number_format($refund_total, 2) . " ({$statuses['refunded']} orders)\n" : '') .
        (count($low_stock) > 0 ? "Low stock: " . count($low_stock) . " products" : ''), [
        'revenue'      => $total_revenue,
        'orders'       => $total_orders,
        'avg_order'    => $avg_order,
        'items_sold'   => $total_items,
        'customers'    => $customers,
        'statuses'     => $statuses,
        'refund_total' => $refund_total,
        'low_stock'    => array_slice($low_stock, 0, 10),
        'currency'     => $currency,
        'period'       => $period,
    ]);
}

function wpilot_woo_recent_orders($p) {
    $limit = min(intval($p['limit'] ?? 10), 50);
    $orders = wc_get_orders(['limit'=>$limit, 'orderby'=>'date', 'order'=>'DESC']);
    $list = [];
    $currency = get_woocommerce_currency_symbol();
    foreach ($orders as $o) {
        $list[] = [
            'id'       => $o->get_id(),
            'status'   => $o->get_status(),
            'total'    => $currency . $o->get_total(),
            'customer' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
            'email'    => $o->get_billing_email(),
            'items'    => $o->get_item_count(),
            'date'     => $o->get_date_created()->format('Y-m-d H:i'),
        ];
    }
    return wpilot_ok("Last {$limit} orders:", ['orders'=>$list]);
}

function wpilot_woo_best_sellers($p) {
    $limit  = min(intval($p['limit'] ?? 10), 30);
    $period = sanitize_text_field($p['period'] ?? 'month');

    global $wpdb;
    $after = date('Y-m-d', strtotime("-1 {$period}"));

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT oi.order_item_name as name,
               SUM(oim.meta_value) as qty,
               oim2.meta_value as product_id
        FROM {$wpdb->prefix}woocommerce_order_items oi
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_product_id'
        JOIN {$wpdb->posts} p ON oi.order_id = p.ID
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed','wc-processing')
        AND p.post_date >= %s
        GROUP BY oim2.meta_value
        ORDER BY qty DESC
        LIMIT %d
    ", $after, $limit));

    $best = [];
    foreach ($results as $r) {
        $prod = wc_get_product($r->product_id);
        $best[] = [
            'name'     => $r->name,
            'id'       => $r->product_id,
            'qty_sold' => intval($r->qty),
            'price'    => $prod ? $prod->get_price() : '?',
            'stock'    => $prod ? $prod->get_stock_quantity() : null,
        ];
    }

    return wpilot_ok("Best sellers (last {$period}):", ['products'=>$best]);
}

// ═══════════════════════════════════════════════════════════════
//  Database Cleanup
// ═══════════════════════════════════════════════════════════════

function wpilot_database_cleanup($p) {
    global $wpdb;
    $dry_run = isset($p['dry_run']) && $p['dry_run'];
    $results = [];

    // 1. Post revisions
    $revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
    if (!$dry_run && $revisions > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
    }
    $results['revisions'] = intval($revisions);

    // 2. Auto-drafts
    $auto_drafts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    if (!$dry_run && $auto_drafts > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    }
    $results['auto_drafts'] = intval($auto_drafts);

    // 3. Trashed posts
    $trash = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
    if (!$dry_run && $trash > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
    }
    $results['trashed'] = intval($trash);

    // 4. Spam comments
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $spam = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    if (!$dry_run && $spam > 0) {
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    }
    $results['spam_comments'] = intval($spam);

    // 5. Trashed comments
    $trash_comments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
    if (!$dry_run && $trash_comments > 0) {
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
    }
    $results['trashed_comments'] = intval($trash_comments);

    // 6. Orphaned post meta
    $orphan_meta = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
    if (!$dry_run && $orphan_meta > 0) {
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
    }
    $results['orphaned_postmeta'] = intval($orphan_meta);

    // 7. Orphaned comment meta
    $orphan_cmeta = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    if (!$dry_run && $orphan_cmeta > 0) {
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    }
    $results['orphaned_commentmeta'] = intval($orphan_cmeta);

    // 8. Expired transients
    $transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
    if (!$dry_run && $transients > 0) {
        $wpdb->query("DELETE a, b FROM {$wpdb->options} a INNER JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_transient_timeout_', '_transient_') WHERE a.option_name LIKE '_transient_timeout_%' AND a.option_value < UNIX_TIMESTAMP()");
    }
    $results['expired_transients'] = intval($transients);

    // 9. Optimize tables
    if (!$dry_run) {
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
        foreach ($tables as $table) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $wpdb->query("OPTIMIZE TABLE `{$table}`");
            }
        }
        $results['tables_optimized'] = count($tables);
    }

    $total = array_sum(array_filter($results, fn($v) => is_int($v) && $v > 0));
    $action = $dry_run ? 'Found' : 'Cleaned';

    return wpilot_ok("{$action}: {$results['revisions']} revisions, {$results['auto_drafts']} auto-drafts, {$results['trashed']} trashed posts, {$results['spam_comments']} spam comments, {$results['trashed_comments']} trashed comments, {$results['orphaned_postmeta']} orphaned meta, {$results['expired_transients']} expired transients." . (!$dry_run ? " Database tables optimized." : ""), $results);
}

// ═══════════════════════════════════════════════════════════════
//  Broken Link Checker
// ═══════════════════════════════════════════════════════════════

function wpilot_check_broken_links($p) {
    $limit = min(intval($p['limit'] ?? 50), 200);

    // Get all published content
    $posts = get_posts(['post_type'=>['page','post'], 'post_status'=>'publish', 'numberposts'=>$limit]);

    $broken  = [];
    $checked = 0;

    foreach ($posts as $post) {
        // Extract URLs from content
        preg_match_all('/href=["\']([^"\']+)["\']/', $post->post_content, $matches);
        if (empty($matches[1])) continue;

        foreach ($matches[1] as $url) {
            // Skip anchors, mailto, tel, javascript
            if (preg_match('/^(#|mailto:|tel:|javascript:)/', $url)) continue;
            // Skip external URLs (too slow to check all)
            if (strpos($url, get_site_url()) === false && preg_match('/^https?:\/\//', $url)) continue;

            // Make relative URLs absolute
            if (strpos($url, '/') === 0) $url = get_site_url() . $url;

            $checked++;
            if ($checked > 100) break 2; // safety limit

            $response = wp_remote_head($url, ['timeout'=>5, 'redirection'=>3]);
            $code = wp_remote_retrieve_response_code($response);

            if (is_wp_error($response) || $code >= 400) {
                $broken[] = [
                    'url'       => $url,
                    'status'    => is_wp_error($response) ? 'error' : $code,
                    'found_in'  => $post->post_title,
                    'post_id'   => $post->ID,
                    'post_type' => $post->post_type,
                ];
            }
        }
    }

    if (empty($broken)) return wpilot_ok("No broken internal links found. Checked {$checked} links across " . count($posts) . " pages/posts.");

    return wpilot_ok("Found " . count($broken) . " broken links (checked {$checked} total).", [
        'broken' => $broken,
        'checked' => $checked,
        'pages_scanned' => count($posts),
    ]);
}

// ── Handle WPilot redirects ────────────────────────────────
add_action('template_redirect', function() {
    $redirects = get_option('wpi_redirects', []);
    if (empty($redirects)) return;

    $request = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($request, PHP_URL_PATH);
    $path = rtrim($path, '/');

    foreach ($redirects as $from => $data) {
        $from_clean = rtrim($from, '/');
        if ($path === $from_clean || $request === $from) {
            wp_redirect($data['to'], $data['type']);
            exit;
        }
    }
});

// ══════════════════════════════════════════════════════════════
//  NEWSLETTER — Send emails to users, customers, subscribers
//  Works with: Mailchimp for WP, MailPoet, Newsletter plugin,
//  or native wp_mail() fallback using WordPress user database
// ══════════════════════════════════════════════════════════════

function wpilot_newsletter_list_subscribers($p) {
    $group = sanitize_text_field($p['group'] ?? 'all');
    $emails = [];
    $sources = [];

    // 1. WordPress users
    if ($group === 'all' || $group === 'users') {
        $users = get_users(['fields'=>['user_email','display_name','ID'], 'number'=>1000]);
        foreach ($users as $u) {
            $roles = implode(',', (new WP_User($u->ID))->roles);
            $emails[$u->user_email] = [
                'email'  => $u->user_email,
                'name'   => $u->display_name,
                'source' => 'wordpress_user',
                'role'   => $roles,
            ];
        }
        $sources[] = 'WordPress users (' . count($users) . ')';
    }

    // 2. WooCommerce customers (orders)
    if (($group === 'all' || $group === 'customers') && class_exists('WooCommerce')) {
        $orders = wc_get_orders(['limit'=>500, 'status'=>['wc-completed','wc-processing']]);
        $cust_count = 0;
        foreach ($orders as $order) {
            $email = $order->get_billing_email();
            if ($email && !isset($emails[$email])) {
                $emails[$email] = [
                    'email'  => $email,
                    'name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'source' => 'woocommerce_customer',
                    'role'   => 'customer',
                ];
                $cust_count++;
            }
        }
        $sources[] = 'WooCommerce customers (' . $cust_count . ')';
    }

    // 3. Comment authors (with consent — they left their email publicly)
    // Built by Christos Ferlachidis & Daniel Hedenberg
    if ($group === 'all' || $group === 'commenters') {
        $comments = get_comments(['status'=>'approve', 'number'=>500, 'type'=>'comment']);
        $comm_count = 0;
        foreach ($comments as $c) {
            if ($c->comment_author_email && !isset($emails[$c->comment_author_email])) {
                $emails[$c->comment_author_email] = [
                    'email'  => $c->comment_author_email,
                    'name'   => $c->comment_author,
                    'source' => 'commenter',
                    'role'   => 'commenter',
                ];
                $comm_count++;
            }
        }
        $sources[] = 'Comment authors (' . $comm_count . ')';
    }

    // 4. MailPoet subscribers
    if (class_exists('\MailPoet\API\API')) {
        try {
            $api = \MailPoet\API\API::MP('v1');
            $subscribers = $api->getSubscribers(['status'=>'subscribed', 'limit'=>1000]);
            $mp_count = 0;
            foreach ($subscribers as $sub) {
                if (!isset($emails[$sub['email']])) {
                    $emails[$sub['email']] = [
                        'email'  => $sub['email'],
                        'name'   => ($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? ''),
                        'source' => 'mailpoet',
                        'role'   => 'subscriber',
                    ];
                    $mp_count++;
                }
            }
            $sources[] = 'MailPoet (' . $mp_count . ')';
        } catch (\Exception $e) {}
    }

    // 5. Newsletter plugin subscribers
    if (class_exists('Newsletter')) {
        global $wpdb;
        $table = $wpdb->prefix . 'newsletter';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $subs = $wpdb->get_results("SELECT email, name FROM {$table} WHERE status = 'C' LIMIT 1000");
            $nl_count = 0;
            foreach ($subs as $s) {
                if (!isset($emails[$s->email])) {
                    $emails[$s->email] = [
                        'email'  => $s->email,
                        'name'   => $s->name ?? '',
                        'source' => 'newsletter_plugin',
                        'role'   => 'subscriber',
                    ];
                    $nl_count++;
                }
            }
            $sources[] = 'Newsletter plugin (' . $nl_count . ')';
        }
    }

    // Remove admin email and obvious system emails
    $admin_email = get_option('admin_email');
    unset($emails[$admin_email]);
    $emails = array_filter($emails, function($e) {
        return !preg_match('/(noreply|no-reply|admin@|info@localhost|test@)/i', $e['email']);
    });

    $total = count($emails);
    $by_role = [];
    foreach ($emails as $e) {
        $role = $e['role'] ?? 'unknown';
        $by_role[$role] = ($by_role[$role] ?? 0) + 1;
    }

    return wpilot_ok("Found {$total} email addresses. Sources: " . implode(', ', $sources) . ".", [
        'total'      => $total,
        'by_role'    => $by_role,
        'sources'    => $sources,
        'emails'     => array_values($emails),
    ]);
}

function wpilot_newsletter_send($p) {
    $subject = sanitize_text_field($p['subject'] ?? '');
    $body    = wp_kses_post($p['body'] ?? '');
    $group   = sanitize_text_field($p['group'] ?? 'all');
    $role    = sanitize_text_field($p['role'] ?? '');

    if (empty($subject)) return wpilot_err('Subject required.');
    if (empty($body))    return wpilot_err('Email body required.');

    // Check if MailPoet is available for sending
    if (class_exists('\MailPoet\API\API')) {
        // Use MailPoet to send — much better deliverability
        try {
            $api = \MailPoet\API\API::MP('v1');
            $lists = $api->getLists();
            if (!empty($lists)) {
                return wpilot_ok("MailPoet is installed. For best deliverability, create the newsletter through MailPoet > Newsletters. I've prepared the content for you.\n\nSubject: {$subject}\n\nOr I can send via WordPress wp_mail() directly — but MailPoet has better tracking and unsubscribe handling.");
            }
        } catch (\Exception $e) {}
    }

    // Collect recipients
    $result = wpilot_newsletter_list_subscribers(['group' => $group]);
    if (!$result['success'] || empty($result['emails'])) {
        return wpilot_err('No recipients found for group: ' . $group);
    }

    $recipients = $result['emails'];

    // Filter by role if specified
    if ($role) {
        $recipients = array_filter($recipients, function($e) use ($role) {
            return $e['role'] === $role;
        });
    }

    if (empty($recipients)) return wpilot_err("No recipients match role: {$role}");

    $total = count($recipients);
    $site_name = get_bloginfo('name');
    $site_url  = get_site_url();

    // Build HTML email template
    $html = wpilot_newsletter_template($subject, $body, $site_name, $site_url);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
    ];

    // Send in batches to avoid timeout
    $sent    = 0;
    $failed  = 0;
    $batch   = 0;
    $limit   = 50; // max per batch
    $batches = array_chunk(array_values($recipients), $limit);

    foreach ($batches as $batch_recipients) {
        foreach ($batch_recipients as $recipient) {
            $personalized_html = str_replace(
                ['{{name}}', '{{email}}'],
                [esc_html($recipient['name'] ?: 'there'), esc_html($recipient['email'])],
                $html
            );

            $result = wp_mail($recipient['email'], $subject, $personalized_html, $headers);
            if ($result) $sent++;
            else $failed++;
        }
        $batch++;

        // Safety: max 3 batches (150 emails) per call to avoid timeout
        if ($batch >= 3) break;
    }

    $remaining = $total - ($batch * $limit);
    $msg = "Newsletter sent to {$sent} recipients.";
    if ($failed > 0)    $msg .= " {$failed} failed.";
    if ($remaining > 0) $msg .= " {$remaining} remaining — run again to continue.";
    $msg .= "\n\nFor large lists (100+), use a dedicated email service (MailPoet, Mailchimp, SendGrid) for better deliverability.";

    // Log the send
    $log = get_option('wpi_newsletter_log', []);
    $log[] = [
        'subject'    => $subject,
        'sent'       => $sent,
        'failed'     => $failed,
        'group'      => $group,
        'role'       => $role,
        'date'       => current_time('mysql'),
    ];
    if (count($log) > 50) $log = array_slice($log, -50);
    update_option('wpi_newsletter_log', $log);

    return wpilot_ok($msg, [
        'sent'      => $sent,
        'failed'    => $failed,
        'remaining' => max(0, $remaining),
        'total'     => $total,
    ]);
}

function wpilot_newsletter_template($subject, $body, $site_name, $site_url) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">'
        . '<div style="max-width:600px;margin:0 auto;padding:20px">'
        . '<div style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">'
        // Header
        . '<div style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);padding:30px 32px;text-align:center">'
        . '<h1 style="color:#fff;margin:0;font-size:22px;font-weight:700">' . esc_html($site_name) . '</h1>'
        . '</div>'
        // Body
        . '<div style="padding:32px;font-size:15px;line-height:1.7;color:#333">'
        . '<p style="margin:0 0 8px;color:#999;font-size:13px">' . esc_html($subject) . '</p>'
        . '<p>Hi {{name}},</p>'
        . $body
        . '</div>'
        // Footer
        . '<div style="padding:20px 32px;background:#f9fafb;border-top:1px solid #eee;text-align:center;font-size:12px;color:#999">'
        . '<p style="margin:0">Sent from <a href="' . esc_url($site_url) . '" style="color:#5B7FFF;text-decoration:none">' . esc_html($site_name) . '</a></p>'
        . '<p style="margin:6px 0 0;font-size:11px">You received this because you are a member/customer at ' . esc_html($site_name) . '.</p>'
        . '</div>'
        . '</div></div></body></html>';
}

function wpilot_newsletter_configure($p) {
    // Check for email marketing plugins
    if (class_exists('\MailPoet\API\API')) {
        return wpilot_ok("MailPoet is installed. Use it for:\n- Automated welcome emails\n- Abandoned cart emails (WooCommerce)\n- Newsletter signup forms\n- Beautiful email templates\n\nI can help you create newsletters — just tell me what to write about.");
    }
    if (class_exists('Newsletter')) {
        return wpilot_ok("Newsletter plugin is installed. Subscribers are collected automatically. Use newsletter_send to send emails, or newsletter_list_subscribers to see your list.");
    }
    if (function_exists('mc4wp_get_api')) {
        return wpilot_ok("Mailchimp for WP is installed. Your subscribers sync to Mailchimp. Send newsletters from your Mailchimp dashboard for best deliverability.");
    }

    return wpilot_ok("No email marketing plugin installed. Options:\n\n**MailPoet** (FREE up to 1000 subscribers) — Best all-in-one: forms, templates, automation, WooCommerce integration. Sends directly from your server.\n\n**Newsletter** (FREE) — Simple and lightweight. Collects subscribers, sends newsletters.\n\n**Mailchimp for WP** (FREE) — Syncs with Mailchimp. Best if you already use Mailchimp.\n\nFor most sites, **MailPoet is the best free choice**. Want me to install it?", [
        'not_installed' => true,
        'suggested' => [
            ['slug'=>'mailpoet', 'name'=>'MailPoet', 'reason'=>'Best free email marketing, forms + automation'],
            ['slug'=>'newsletter', 'name'=>'Newsletter', 'reason'=>'Lightweight, simple newsletter sending'],
            ['slug'=>'mailchimp-for-wp', 'name'=>'Mailchimp for WP', 'reason'=>'Free Mailchimp integration'],
        ],
    ]);
}


// Full Site Generator
function wpilot_generate_full_site($p) {
    $site_type = sanitize_text_field($p['site_type'] ?? 'general');
    $business = sanitize_text_field($p['business_name'] ?? get_bloginfo('name'));
    $description = sanitize_text_field($p['description'] ?? '');
    $pages_data = $p['pages'] ?? [];
    if (empty($pages_data)) $pages_data = wpilot_default_pages_for_type($site_type, $business);
    $created = [];
    foreach ($pages_data as $page) {
        $title = sanitize_text_field($page['title'] ?? 'Untitled');
        $slug = sanitize_title($page['slug'] ?? $title);
        $existing = get_page_by_path($slug);
        if ($existing) { $created[] = ['id'=>$existing->ID,'title'=>$title,'status'=>'exists']; continue; }
        $id = wp_insert_post(['post_title'=>$title,'post_name'=>$slug,'post_content'=>wp_kses_post($page['content']??''),'post_status'=>'publish','post_type'=>'page','menu_order'=>$page['order']??0]);
        if (is_wp_error($id)) continue;
        if (!empty($page['is_front'])) { update_option('show_on_front','page'); update_option('page_on_front',$id); }
        if (!empty($page['is_blog'])) update_option('page_for_posts',$id);
        $desc = wp_trim_words(wp_strip_all_tags($page['content']??''),25,'...');
        update_post_meta($id,'rank_math_description',substr($desc?:$title,0,160));
        $created[] = ['id'=>$id,'title'=>$title,'status'=>'created','url'=>get_permalink($id)];
    }
    $menu_name = $business.' Menu';
    $menu_id = wp_create_nav_menu($menu_name);
    if (!is_wp_error($menu_id)) {
        foreach ($created as $i=>$pg) {
            wp_update_nav_menu_item($menu_id,0,['menu-item-title'=>$pg['title'],'menu-item-object'=>'page','menu-item-object-id'=>$pg['id'],'menu-item-type'=>'post_type','menu-item-status'=>'publish','menu-item-position'=>$i+1]);
        }
        $locations = get_theme_mod('nav_menu_locations',[]);
        foreach (['primary','main-menu','header-menu','main','header'] as $try) {
            $all = get_registered_nav_menus();
            if (isset($all[$try])) { $locations[$try]=$menu_id; break; }
        }
        set_theme_mod('nav_menu_locations',$locations);
    }
    if ($description) update_option('blogdescription',$description);
    $count = count(array_filter($created,fn($p)=>$p['status']==='created'));
    return wpilot_ok("Site created: " . $count . " pages + menu. Navigate to any page and tell me what to change.", ["pages"=>$created]);
}

function wpilot_default_pages_for_type($type,$biz) {
    $p = [['title'=>'Home','slug'=>'home','is_front'=>true,'order'=>1,'content'=>'<h1>Welcome to '.$biz.'</h1>']];
    switch($type) {
        case 'booking':
            $p[] = ['title'=>'Services','slug'=>'services','order'=>2,'content'=>'<h2>Our Services</h2>'];
            $p[] = ['title'=>'Book Now','slug'=>'book','order'=>3,'content'=>'<h2>Book an Appointment</h2>'];
            $p[] = ['title'=>'About Us','slug'=>'about','order'=>4,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Contact Us</h2>'];
            $p[] = ['title'=>'FAQ','slug'=>'faq','order'=>6,'content'=>'<h2>FAQ</h2>']; break;
        case 'ecommerce':
            $p[] = ['title'=>'Shop','slug'=>'shop','order'=>2,'content'=>'<h2>Products</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>3,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>4,'content'=>'<h2>Contact</h2>'];
            $p[] = ['title'=>'FAQ','slug'=>'faq','order'=>5,'content'=>'<h2>FAQ</h2>'];
            $p[] = ['title'=>'Shipping','slug'=>'shipping','order'=>6,'content'=>'<h2>Shipping & Returns</h2>']; break;
        case 'restaurant':
            $p[] = ['title'=>'Menu','slug'=>'menu','order'=>2,'content'=>'<h2>Our Menu</h2>'];
            $p[] = ['title'=>'Reservations','slug'=>'reservations','order'=>3,'content'=>'<h2>Book a Table</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>4,'content'=>'<h2>Our Story</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Find Us</h2>']; break;
        case 'portfolio':
            $p[] = ['title'=>'Portfolio','slug'=>'portfolio','order'=>2,'content'=>'<h2>Our Work</h2>'];
            $p[] = ['title'=>'Services','slug'=>'services','order'=>3,'content'=>'<h2>What We Do</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>4,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Get in Touch</h2>']; break;
        case 'education':
            $p[] = ['title'=>'Courses','slug'=>'courses','order'=>2,'content'=>'<h2>Our Courses</h2>'];
            $p[] = ['title'=>'Pricing','slug'=>'pricing','order'=>3,'content'=>'<h2>Pricing</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>4,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Contact</h2>']; break;

        default:
            $p[] = ['title'=>'About','slug'=>'about','order'=>2,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Services','slug'=>'services','order'=>3,'content'=>'<h2>Our Services</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>4,'content'=>'<h2>Contact Us</h2>'];
            $p[] = ['title'=>'Blog','slug'=>'blog','is_blog'=>True,'order'=>5,'content'=>'']; break;
    }
    $p[] = ['title'=>'Privacy Policy','slug'=>'privacy-policy','order'=>90,'content'=>'<h2>Privacy Policy</h2>'];
    return $p;
}


function wpilot_delete_directory($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? wpilot_delete_directory($path) : @unlink($path);
    }
    return @rmdir($dir);
}


// ═══════════════════════════════════════════════════════════════
//  PageSpeed Test — calls Google PageSpeed Insights API (free)
// ═══════════════════════════════════════════════════════════════

function wpilot_pagespeed_test($p) {
    $url = esc_url_raw($p['url'] ?? get_site_url());
    $strategy = sanitize_text_field($p['strategy'] ?? 'mobile'); // mobile or desktop

    // Google PageSpeed Insights API (free, no key required for basic use)
    $api_key = get_option('wpi_google_api_key', 'AIzaSyCQUl3oP-NN7DHY2VJz084oGRtBq_guTk4');
    $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url='
        . urlencode($url)
        . '&strategy=' . $strategy
        . '&category=performance&category=seo&category=best-practices&category=accessibility'
        . ($api_key ? '&key=' . $api_key : '');

    // Check cache first (results valid for 10 min)
    $cache_key = 'wpi_pagespeed_' . md5($url . $strategy);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    // Call API with retry on rate limit
    $response = wp_remote_get($api_url, ['timeout' => 90]);

    if (is_wp_error($response)) {
        return wpilot_err('PageSpeed test failed: ' . $response->get_error_message() . '. Try again in a minute.');
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 429) {
        // Rate limited - wait 5 seconds and retry once
        sleep(5);
        $response = wp_remote_get($api_url, ['timeout' => 90]);
        if (is_wp_error($response)) return wpilot_err('PageSpeed temporarily unavailable. Try again in 1-2 minutes.');
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 429) {
            return wpilot_err('Google PageSpeed is rate limited. Wait 1-2 minutes and try again. This is a Google API limit, not a WPilot issue.');
        }
    }
    if ($code !== 200) {
        return wpilot_err('PageSpeed API error (HTTP ' . $code . '). Try again shortly.');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data) || !isset($data['lighthouseResult'])) {
        return wpilot_err('Invalid PageSpeed response');
    }

    $lh = $data['lighthouseResult'];
    $cats = $lh['categories'] ?? [];
    $audits = $lh['audits'] ?? [];

    // Extract scores
    $scores = [];
    foreach (['performance', 'seo', 'best-practices', 'accessibility'] as $cat) {
        if (isset($cats[$cat])) {
            $scores[$cat] = round(($cats[$cat]['score'] ?? 0) * 100);
        }
    }

    // Extract key metrics
    $metrics = [];
    $metric_keys = [
        'first-contentful-paint' => 'FCP',
        'largest-contentful-paint' => 'LCP',
        'total-blocking-time' => 'TBT',
        'cumulative-layout-shift' => 'CLS',
        'speed-index' => 'Speed Index',
        'interactive' => 'Time to Interactive',
    ];
    foreach ($metric_keys as $key => $label) {
        if (isset($audits[$key])) {
            $metrics[$label] = [
                'value' => $audits[$key]['displayValue'] ?? '?',
                'score' => round(($audits[$key]['score'] ?? 0) * 100),
            ];
        }
    }

    // Extract opportunities (things to fix)
    $opportunities = [];
    $opp_keys = [
        'render-blocking-resources' => 'Render-blocking resources',
        'unused-javascript' => 'Unused JavaScript',
        'unused-css-rules' => 'Unused CSS',
        'modern-image-formats' => 'Use WebP/AVIF images',
        'uses-optimized-images' => 'Optimize images',
        'uses-text-compression' => 'Enable text compression',
        'uses-responsive-images' => 'Properly size images',
        'efficient-animated-content' => 'Efficient animations',
        'offscreen-images' => 'Defer offscreen images',
        'unminified-javascript' => 'Minify JavaScript',
        'unminified-css' => 'Minify CSS',
        'uses-long-cache-ttl' => 'Cache static assets',
        'dom-size' => 'Reduce DOM size',
        'bootup-time' => 'Reduce JavaScript execution',
        'mainthread-work-breakdown' => 'Minimize main-thread work',
        'font-display' => 'Font display',
        'third-party-summary' => 'Third-party code',
    ];

    foreach ($opp_keys as $key => $label) {
        if (isset($audits[$key]) && ($audits[$key]['score'] ?? 1) < 1) {
            $savings = '';
            if (isset($audits[$key]['details']['overallSavingsMs'])) {
                $savings = round($audits[$key]['details']['overallSavingsMs']) . 'ms';
            } elseif (isset($audits[$key]['details']['overallSavingsBytes'])) {
                $savings = round($audits[$key]['details']['overallSavingsBytes'] / 1024) . 'KB';
            }
            $opportunities[] = [
                'issue' => $label,
                'savings' => $savings,
                'score' => round(($audits[$key]['score'] ?? 0) * 100),
                'displayValue' => $audits[$key]['displayValue'] ?? '',
            ];
        }
    }

    // Sort by score (worst first)
    usort($opportunities, function($a, $b) { return $a['score'] - $b['score']; });

    // Build result message
    $perf_score = $scores['performance'] ?? 0;
    $grade = $perf_score >= 90 ? 'A' : ($perf_score >= 50 ? 'B' : ($perf_score >= 25 ? 'C' : 'D'));

    $msg = "PageSpeed Results ({$strategy}):\n\n";
    $msg .= "Performance: {$perf_score}/100 (Grade: {$grade})\n";
    if (isset($scores['seo'])) $msg .= "SEO: {$scores['seo']}/100\n";
    if (isset($scores['accessibility'])) $msg .= "Accessibility: {$scores['accessibility']}/100\n";
    if (isset($scores['best-practices'])) $msg .= "Best Practices: {$scores['best-practices']}/100\n";
    $msg .= "\nMetrics:\n";
    foreach ($metrics as $label => $m) {
        $msg .= "- {$label}: {$m['value']}\n";
    }
    if (!empty($opportunities)) {
        $msg .= "\nTop issues to fix:\n";
        foreach (array_slice($opportunities, 0, 8) as $opp) {
            $save = $opp['savings'] ? " (save {$opp['savings']})" : '';
            $msg .= "- {$opp['issue']}{$save}\n";
        }
    }

    $result = wpilot_ok($msg, [
        'url' => $url,
        'strategy' => $strategy,
        'scores' => $scores,
        'metrics' => $metrics,
        'opportunities' => $opportunities,
        'grade' => $grade,
    ]);

    // Cache for 10 minutes to avoid rate limits
    set_transient($cache_key, $result, 600);

    return $result;
}


// ═══════════════════════════════════════════════════════════════
//  Performance Fix Tools
// ═══════════════════════════════════════════════════════════════

function wpilot_fix_performance($p) {
    $results = [];

    // 1. Configure cache if available
    if (function_exists('wpilot_cache_configure')) {
        $cache = wpilot_cache_configure($p);
        $results[] = 'Cache: ' . ($cache['message'] ?? 'configured');
    }

    // 2. Convert images to WebP
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>['image/jpeg','image/png'],'numberposts'=>-1]);
    $not_webp = count($images);
    if ($not_webp > 0) {
        $results[] = $not_webp . ' images can be converted to WebP (use convert_all_images_webp)';
    }

    // 3. Check for lazy loading
    $has_lazy = false;
    foreach (get_option('active_plugins', []) as $plugin) {
        if (preg_match('/litespeed|wp-rocket|lazy/i', $plugin)) $has_lazy = true;
    }
    if (!$has_lazy) {
        $results[] = 'No lazy loading detected — enable it for faster page loads';
    } else {
        $results[] = 'Lazy loading: active';
    }

    // 4. Database size
    global $wpdb;
    $revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
    $transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
    if ($revisions > 50 || $transients > 20) {
        $results[] = "Database bloat: {$revisions} revisions, {$transients} expired transients — clean up to speed up queries";
    }

    // 5. Plugin count
    $plugin_count = count(get_option('active_plugins', []));
    if ($plugin_count > 20) {
        $results[] = "{$plugin_count} active plugins — consider deactivating unused ones";
    }

    // 6. PHP version
    $php = PHP_VERSION;
    if (version_compare($php, '8.0', '<')) {
        $results[] = "PHP {$php} is slow — upgrade to 8.0+ for 20-30% speed boost";
    }

    return wpilot_ok("Performance analysis:\n" . implode("\n", array_map(function($r) { return "- " . $r; }, $results)), [
        'not_webp' => $not_webp,
        'revisions' => $revisions,
        'transients' => $transients,
        'plugin_count' => $plugin_count,
        'php_version' => $php,
        'has_lazy' => $has_lazy,
    ]);
}

function wpilot_fix_render_blocking($p) {
    // This requires cache plugin configuration
    // LiteSpeed, WP Rocket, Autoptimize can defer/async CSS/JS

    if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
        update_option('litespeed.conf.optm-css_async', 1);
        update_option('litespeed.conf.optm-js_defer', 1);
        update_option('litespeed.conf.optm-js_inline_defer', 1);
        update_option('litespeed.conf.css_minify', 1);
        update_option('litespeed.conf.js_minify', 1);
        update_option('litespeed.conf.css_combine', 1);
        update_option('litespeed.conf.js_combine', 1);
        update_option('litespeed.conf.optm-qs_rm', 1);
        do_action('litespeed_purge_all');
        return wpilot_ok("LiteSpeed configured: CSS async, JS deferred, CSS/JS minified + combined, query strings removed. Cache purged.");
    }

    if (defined('WP_ROCKET_VERSION')) {
        $opts = get_option('wp_rocket_settings', []);
        $opts['defer_all_js'] = 1;
        $opts['async_css'] = 1;
        $opts['minify_css'] = 1;
        $opts['minify_js'] = 1;
        $opts['minify_concatenate_css'] = 1;
        $opts['minify_concatenate_js'] = 1;
        $opts['remove_query_strings'] = 1;
        update_option('wp_rocket_settings', $opts);
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        return wpilot_ok("WP Rocket configured: JS deferred, CSS async, minified + combined.");
    }

    // No cache plugin — add inline via mu-plugin
    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

    $code = "<?php\n// WPilot: Defer render-blocking resources\n"
        . "add_action('wp_enqueue_scripts', function() {\n"
        . "    // Add defer to all scripts\n"
        . "    add_filter('script_loader_tag', function(\$tag, \$handle) {\n"
        . "        if (is_admin()) return \$tag;\n"
        . "        if (strpos(\$tag, 'defer') !== false || strpos(\$tag, 'async') !== false) return \$tag;\n"
        . "        return str_replace(' src=', ' defer src=', \$tag);\n"
        . "    }, 10, 2);\n"
        . "}, 20);\n";

    file_put_contents($mu_dir . '/wpilot-defer-scripts.php', $code);
    return wpilot_ok("Created mu-plugin to defer all scripts. For best results, install LiteSpeed Cache or WP Rocket.");
}

function wpilot_enable_lazy_load($p) {
    // WordPress 5.5+ has native lazy loading, but we can enhance it

    if (defined('LSCWP_V')) {
        update_option('litespeed.conf.media_lazy', 1);
        update_option('litespeed.conf.media_lazy_placeholder', 1);
        update_option('litespeed.conf.media_placeholder_resp', 1);
        update_option('litespeed.conf.media_iframe_lazy', 1);
        return wpilot_ok("LiteSpeed lazy loading enabled: images, iframes, responsive placeholders.");
    }

    if (defined('WP_ROCKET_VERSION')) {
        $opts = get_option('wp_rocket_settings', []);
        $opts['lazyload'] = 1;
        $opts['lazyload_iframes'] = 1;
        $opts['lazyload_youtube'] = 1;
        update_option('wp_rocket_settings', $opts);
        return wpilot_ok("WP Rocket lazy loading enabled: images, iframes, YouTube.");
    }

    // WordPress native lazy loading is already on by default (5.5+)
    // Add enhanced lazy loading via mu-plugin
    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

    $code = "<?php\n// WPilot: Enhanced lazy loading\n"
        . "add_filter('wp_lazy_loading_enabled', '__return_true');\n"
        . "add_filter('wp_img_tag_add_loading_attr', function() { return 'lazy'; });\n";

    file_put_contents($mu_dir . '/wpilot-lazy-load.php', $code);
    return wpilot_ok("Enhanced lazy loading enabled. WordPress native + mu-plugin. For best results, install LiteSpeed Cache.");
}

function wpilot_add_image_dimensions($p) {
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>-1]);
    $fixed = 0;

    foreach ($images as $img) {
        $meta = wp_get_attachment_metadata($img->ID);
        if (empty($meta['width']) || empty($meta['height'])) {
            $file = get_attached_file($img->ID);
            if ($file && file_exists($file)) {
                $size = wp_getimagesize($file);
                if ($size) {
                    $meta['width'] = $size[0];
                    $meta['height'] = $size[1];
                    wp_update_attachment_metadata($img->ID, $meta);
                    $fixed++;
                }
            }
        }
    }

    // Also fix images in content that lack width/height attributes
    $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>100]);
    $content_fixed = 0;
    foreach ($posts as $post) {
        $content = $post->post_content;
        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            $changed = false;
            foreach ($matches[0] as $img_tag) {
                if (strpos($img_tag, 'width=') === false || strpos($img_tag, 'height=') === false) {
                    // Try to get dimensions from attachment
                    if (preg_match('/wp-image-(\d+)/', $img_tag, $id_match)) {
                        $meta = wp_get_attachment_metadata(intval($id_match[1]));
                        if ($meta && !empty($meta['width']) && !empty($meta['height'])) {
                            $new_tag = $img_tag;
                            if (strpos($new_tag, 'width=') === false) {
                                $new_tag = str_replace('<img', '<img width="' . $meta['width'] . '"', $new_tag);
                            }
                            if (strpos($new_tag, 'height=') === false) {
                                $new_tag = str_replace('<img', '<img height="' . $meta['height'] . '"', $new_tag);
                            }
                            $content = str_replace($img_tag, $new_tag, $content);
                            $changed = true;
                            $content_fixed++;
                        }
                    }
                }
            }
            if ($changed) {
                wp_update_post(['ID' => $post->ID, 'post_content' => $content]);
            }
        }
    }

    return wpilot_ok("Fixed dimensions: {$fixed} image metadata + {$content_fixed} images in content now have width/height attributes.");
}

function wpilot_minify_assets($p) {
    // Configure minification in cache plugins
    if (function_exists('wpilot_cache_configure')) {
        return wpilot_cache_configure($p);
    }
    return wpilot_ok("Install a cache plugin (LiteSpeed Cache or WP Rocket) for CSS/JS minification. Use cache_configure after installing.");
}


// ═══════════════════════════════════════════════════════════════
//  Image Compression — compress images using WordPress image editor
// ═══════════════════════════════════════════════════════════════

function wpilot_compress_images($p) {
    $quality = intval($p['quality'] ?? 70);
    $limit = intval($p['limit'] ?? 50);
    $min_size = intval($p['min_size_kb'] ?? 100); // only compress images larger than this

    $images = get_posts([
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'numberposts' => $limit,
        'post_status' => 'inherit',
    ]);

    $compressed = 0;
    $saved_total = 0;
    $skipped = 0;

    foreach ($images as $img) {
        $file = get_attached_file($img->ID);
        if (!$file || !file_exists($file)) { $skipped++; continue; }

        $size = filesize($file);
        if ($size < $min_size * 1024) { $skipped++; continue; }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) { $skipped++; continue; }

        // Backup original
        $backup = $file . '.wpilot-compress-backup';
        if (!file_exists($backup)) copy($file, $backup);

        // Compress
        $editor->set_quality($quality);
        $saved = $editor->save($file);

        if (!is_wp_error($saved)) {
            $new_size = filesize($file);
            $saved_bytes = $size - $new_size;
            if ($saved_bytes > 0) {
                $saved_total += $saved_bytes;
                $compressed++;

                // Regenerate thumbnails
                $metadata = wp_generate_attachment_metadata($img->ID, $file);
                wp_update_attachment_metadata($img->ID, $metadata);
            }
        }
    }

    $saved_kb = round($saved_total / 1024);
    $saved_mb = round($saved_total / 1048576, 1);

    return wpilot_ok(
        "Compressed {$compressed} images — saved {$saved_kb}KB ({$saved_mb}MB). Skipped {$skipped} (already small or unsupported).",
        ['compressed' => $compressed, 'saved_bytes' => $saved_total, 'skipped' => $skipped]
    );
}

// ── Elementor button editor helper ──
function wpilot_walk_edit_button(&$elements, $old_label, $new_label, $new_url = '') {
    $found = false;
    foreach ($elements as &$el) {
        if (($el['widgetType'] ?? '') === 'button' && ($el['settings']['text'] ?? '') === $old_label) {
            if ($new_label) $el['settings']['text'] = $new_label;
            if ($new_url) $el['settings']['link']['url'] = $new_url;
            $found = true;
        }
        if (!empty($el['elements'])) {
            if (wpilot_walk_edit_button($el['elements'], $old_label, $new_label, $new_url)) $found = true;
        }
    }
    return $found;
}

// ── Collect Elementor elements for listing ──
function wpilot_collect_elements($elements, &$result, $depth = 0) {
    foreach ($elements as $el) {
        $type = $el['elType'] ?? '?';
        $widget = $el['widgetType'] ?? '';
        $s = $el['settings'] ?? [];
        if ($widget === 'heading' || $widget === 'text-editor') {
            $result[] = ['type' => $widget, 'text' => substr(strip_tags($s['title'] ?? $s['editor'] ?? ''), 0, 80)];
        } elseif ($widget === 'button') {
            $result[] = ['type' => 'button', 'text' => $s['text'] ?? '', 'url' => $s['link']['url'] ?? ''];
        } elseif ($widget === 'image') {
            $result[] = ['type' => 'image', 'src' => $s['image']['url'] ?? '', 'alt' => $s['caption'] ?? ''];
        } elseif ($widget === 'icon') {
            $result[] = ['type' => 'icon', 'icon' => $s['selected_icon']['value'] ?? ''];
        } elseif ($widget === 'html') {
            // Extract elements from HTML widget
            $html = $s['html'] ?? '';
            if (preg_match_all('/<h([1-6])[^>]*>([^<]+)<\/h\1>/i', $html, $hm)) {
                foreach ($hm[2] as $i => $t) $result[] = ['type' => 'heading_h'.$hm[1][$i], 'text' => trim($t)];
            }
            if (preg_match_all('/<(?:a|button)[^>]*>([^<]{1,50})<\/(?:a|button)>/i', $html, $bm)) {
                foreach ($bm[1] as $t) $result[] = ['type' => 'button', 'text' => trim($t)];
            }
        }
        if (!empty($el['elements'])) wpilot_collect_elements($el['elements'], $result, $depth + 1);
    }
}
