<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Files Tools Module
 * Contains 40 tool cases for files operations.
 */
function wpilot_run_file_tools($tool, $params = []) {
    switch ($tool) {

        case 'read_file':
        case 'view_file':
            $path = $params['path'] ?? $params['file'] ?? '';
            if (empty($path)) return wpilot_err('File path required. Example: /wp-content/themes/flavor/header.php');
            if (strpos($path, '/') !== 0) $path = ABSPATH . $path;
            $real = realpath($path);
            $wp_root = realpath(ABSPATH);
            if (!$real || strpos($real, $wp_root) !== 0) return wpilot_err('File not found or outside WordPress directory.');
            if (!is_file($real)) return wpilot_err('Not a file: ' . $path);
            // SECURITY: Block reading sensitive files that contain credentials
            $blocked_read = ['wp-config.php', '.htaccess', '.htpasswd', 'wp-settings.php', '.env', 'debug.log'];
            if (in_array(basename($real), $blocked_read)) return wpilot_err('Cannot read sensitive configuration files for security.');
            // Block files that may contain secrets
            $path_lower = strtolower($real);
            if (preg_match('/(password|credential|\.key|\.pem|\.crt)/', $path_lower)) return wpilot_err('Cannot read files that may contain sensitive data.');
            $size = filesize($real);
            if ($size > 500000) return wpilot_err('File too large (' . round($size/1024) . ' KB). Max 500KB.');
            $content = file_get_contents($real);
            $lines = substr_count($content, "\n") + 1;
            return wpilot_ok("File: {$path} ({$lines} lines, " . round($size/1024) . " KB)", [
                'path' => $path, 'content' => $content, 'lines' => $lines, 'size_kb' => round($size/1024),
            ]);

        case 'write_file':
        case 'edit_file':
            $path = $params['path'] ?? $params['file'] ?? '';
            $content = $params['content'] ?? '';
            $search = $params['search'] ?? $params['find'] ?? '';
            $replace_str = $params['replace'] ?? '';
            if (empty($path)) return wpilot_err('File path required.');
            if (strpos($path, '/') !== 0) $path = ABSPATH . $path;
            $real = realpath(dirname($path));
            $wp_root = realpath(ABSPATH);
            if (!$real || strpos($real, $wp_root) !== 0) return wpilot_err('Path outside WordPress directory.');
            $basename = basename($path);
            if (in_array($basename, ['wp-config.php', '.htaccess', '.htpasswd', 'wp-settings.php', '.env'])) {
                return wpilot_err('Cannot modify core WordPress config files for security.');
            }
            // SECURITY: Block writing to sensitive directories
            $blocked_dirs = ['/wpilot/', '/mu-plugins/wpilot-', '/wp-admin/includes/', '/wp-includes/'];
            foreach ($blocked_dirs as $bd) {
                if (strpos($path, $bd) !== false) return wpilot_err('Cannot write to protected directory.');
            }
            if (file_exists($path)) {
                $backup_dir = WP_CONTENT_DIR . '/wpilot-backups/files/' . date('Y-m-d');
                wp_mkdir_p($backup_dir);
                copy($path, $backup_dir . '/' . $basename . '.' . time() . '.bak');
            }
            if (!empty($search)) {
                if (!file_exists($path)) return wpilot_err('File not found: ' . $path);
                $existing = file_get_contents($path);
                if (strpos($existing, $search) === false) return wpilot_err("Search text not found in {$path}.");
                $existing = str_replace($search, $replace_str, $existing);
                file_put_contents($path, $existing);
                return wpilot_ok("Updated {$path} (search & replace).", ['path' => $path]);
            }
            if (empty($content)) return wpilot_err('content or search+replace required.');
            file_put_contents($path, $content);
            return wpilot_ok("Wrote {$path} (" . strlen($content) . " bytes).", ['path' => $path]);

        case 'list_files':
        case 'file_list':
            $dir = $params['path'] ?? $params['dir'] ?? '';
            if (empty($dir)) $dir = ABSPATH;
            if (strpos($dir, '/') !== 0) $dir = ABSPATH . $dir;
            $real = realpath($dir);
            $wp_root = realpath(ABSPATH);
            if (!$real || strpos($real, $wp_root) !== 0) return wpilot_err('Directory outside WordPress.');
            if (!is_dir($real)) return wpilot_err('Not a directory: ' . $dir);
            $files = [];
            foreach (scandir($real) as $f_name) {
                if ($f_name === '.' || $f_name === '..') continue;
                $full = $real . '/' . $f_name;
                $files[] = [
                    'name' => $f_name,
                    'type' => is_dir($full) ? 'dir' : 'file',
                    'size' => is_file($full) ? filesize($full) : 0,
                    'modified' => date('Y-m-d H:i', filemtime($full)),
                ];
            }
            usort($files, function($a, $b) {
                if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
                return strcmp($a['name'], $b['name']);
            });
            return wpilot_ok(count($files) . " items in {$dir}", ['files' => $files, 'path' => $dir]);

        // ═══ THEME FILE EDITOR ═══
        case 'edit_theme_file':
        case 'edit_theme':
            $file = sanitize_text_field($params['file'] ?? 'functions.php');
            $search = $params['search'] ?? $params['find'] ?? '';
            $replace_str = $params['replace'] ?? '';
            $append = $params['append'] ?? '';
            $theme_dir = get_stylesheet_directory();
            $path = $theme_dir . '/' . $file;
            if (!file_exists($path)) return wpilot_err("Theme file not found: {$file}.");
            // Backup
            $backup_dir = WP_CONTENT_DIR . '/wpilot-backups/theme/' . date('Y-m-d');
            wp_mkdir_p($backup_dir);
            copy($path, $backup_dir . '/' . basename($file) . '.' . time() . '.bak');
            $content = file_get_contents($path);
            if (!empty($search)) {
                if (strpos($content, $search) === false) return wpilot_err("Text not found in {$file}.");
                $content = str_replace($search, $replace_str, $content);
                file_put_contents($path, $content);
                return wpilot_ok("Updated theme file: {$file}", ['file' => $file, 'path' => $path]);
            }
            if (!empty($append)) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $close_pos = strrpos($content, '?>');
                    if ($close_pos !== false) {
                        $content = substr($content, 0, $close_pos) . "\n" . $append . "\n" . substr($content, $close_pos);
                    } else {
                        $content .= "\n" . $append;
                    }
                } else {
                    $content .= "\n" . $append;
                }
                file_put_contents($path, $content);
                return wpilot_ok("Appended to {$file}", ['file' => $file]);
            }
            return wpilot_ok("Theme file: {$file} (" . strlen($content) . " bytes)", [
                'file' => $file, 'content' => $content, 'lines' => substr_count($content, "\n") + 1
            ]);

        // ═══ DATABASE QUERY (read-only + safe writes) ═══
        // Built by Christos Ferlachidis & Daniel Hedenberg
        case 'db_query':
        case 'database_query':
            $query = $params['query'] ?? $params['sql'] ?? '';
            if (empty($query)) return wpilot_err('SQL query required.');
            global $wpdb;
            $query_upper = strtoupper(trim($query));
            // SECURITY: Block dangerous SQL patterns (check anywhere in query, not just start)
            $blocked = ['DROP ', 'TRUNCATE ', 'ALTER ', 'CREATE TABLE', 'GRANT ', 'REVOKE ', 'LOAD ', 'INTO OUTFILE', 'INTO DUMPFILE', 'SLEEP(', 'BENCHMARK(', 'LOAD_FILE('];
            foreach ($blocked as $b) {
                if (strpos($query_upper, $b) !== false) return wpilot_err("Blocked: dangerous SQL pattern detected.");
            }
            // Block stacked queries
            if (substr_count($query, ';') > 0) return wpilot_err("Multiple statements not allowed.");
            // Block UNION-based injection
            if (preg_match('/UNION\s+(ALL\s+)?SELECT/i', $query)) return wpilot_err("UNION SELECT not allowed.");
            $query = str_replace(['{prefix}', '{wp_}'], $wpdb->prefix, $query);
            // SECURITY: Only allow queries against known WP tables
            if (!preg_match('/(?:FROM|TABLE|INTO|UPDATE)\s+[`]?(' . preg_quote($wpdb->prefix, '/') . '\w+)[`]?/i', $query) && strpos($query_upper, 'SHOW') !== 0 && strpos($query_upper, 'DESCRIBE') !== 0) {
                return wpilot_err("Query must reference tables with the WordPress prefix ({$wpdb->prefix}).");
            }
            if (strpos($query_upper, 'SELECT') === 0 || strpos($query_upper, 'SHOW') === 0 || strpos($query_upper, 'DESCRIBE') === 0) {
                $results = $wpdb->get_results($query, ARRAY_A);
                if ($wpdb->last_error) return wpilot_err("SQL error: " . $wpdb->last_error);
                return wpilot_ok(count($results) . " rows.", ['rows' => array_slice($results, 0, 100), 'total' => count($results)]);
            }
            // Write queries disabled for security — use WordPress API functions instead
            return wpilot_err("Only SELECT, SHOW, and DESCRIBE queries are allowed. Use WordPress tools for data modifications.");

        // ═══ WP-CLI WRAPPER ═══
        case 'wp_cli':
        case 'run_command':
            $cmd = $params['command'] ?? $params['cmd'] ?? '';
            if (empty($cmd)) return wpilot_err('WP-CLI command required. Example: command="post list --post_type=page"');
            if (preg_match('/[;&|`$()\\{}]/', $cmd)) return wpilot_err('Shell operators not allowed in WP-CLI commands.');
            // Security: allowlist of safe WP-CLI subcommands only
            $allowed_cmds = ['post list', 'post get', 'post meta list', 'post meta get',
                'page list', 'plugin list', 'plugin status', 'theme list', 'theme status',
                'option get', 'option list', 'user list', 'user get',
                'widget list', 'menu list', 'menu item list', 'sidebar list',
                'cron event list', 'db tables', 'db size', 'cache flush', 'rewrite flush',
                'transient list', 'transient get', 'role list', 'cap list',
                'term list', 'taxonomy list', 'comment list', 'media list'];
            $cmd_lower = strtolower(trim($cmd));
            $allowed = false;
            foreach ($allowed_cmds as $ac) {
                if (strpos($cmd_lower, $ac) === 0) { $allowed = true; break; }
            }
            if (!$allowed) return wpilot_err("Command not allowed. Permitted: " . implode(', ', array_slice($allowed_cmds, 0, 10)) . '...');
            // Split command into args for safe execution
            $args = ['wp', '--allow-root', '--path=' . ABSPATH];
            $parts = preg_split('/\s+/', trim($cmd));
            foreach ($parts as $part) { $args[] = $part; }
            $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
            $proc = proc_open($args, $descriptors, $pipes);
            if (!is_resource($proc)) return wpilot_err('Failed to run WP-CLI.');
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 30);
            $output = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($proc);
            if ($exit !== 0) return wpilot_err("WP-CLI error: " . ($stderr ?: $output));
            return wpilot_ok("Command: wp {$cmd}", ['output' => $output, 'exit_code' => $exit]);
// Built by Christos Ferlachidis & Daniel Hedenberg

        // ═══ ACTION CHAIN — Run multiple tools in sequence ═══
        case 'run_chain':
        case 'action_chain':
        case 'multi_action':
            $steps = $params['steps'] ?? $params['actions'] ?? [];
            if (empty($steps) || !is_array($steps)) return wpilot_err('steps array required: [{"tool":"x","params":{}}, ...]');
            $results = [];
            foreach ($steps as $i => $step) {
                $tool = $step['tool'] ?? '';
                $step_params = $step['params'] ?? [];
                if (empty($tool)) { $results[] = ['step' => $i+1, 'error' => 'Missing tool']; continue; }
                $r = wpilot_run_tool($tool, $step_params);
                $results[] = ['step' => $i+1, 'tool' => $tool, 'success' => $r['success'] ?? false, 'message' => $r['message'] ?? ''];
                if (!($r['success'] ?? false) && !empty($params['stop_on_error'])) break;
            }
            $passed = count(array_filter($results, fn($r) => $r['success'] ?? false));
            return wpilot_ok("{$passed}/" . count($results) . " steps completed.", ['results' => $results]);

        // ═══ CRON / SCHEDULED TASKS ═══
        case 'schedule_action':
        case 'cron_add':
            $action = sanitize_text_field($params['action'] ?? $params['tool'] ?? '');
            $when = $params['when'] ?? $params['time'] ?? '';
            $action_params = $params['params'] ?? $params['tool_params'] ?? [];
            if (empty($action)) return wpilot_err('action (tool name) and when (datetime) required.');
            $timestamp = strtotime($when);
            if (!$timestamp || $timestamp < time()) $timestamp = time() + 3600;
            $scheduled = get_option('wpilot_scheduled_actions', []);
            $id = uniqid('wpi_');
            $scheduled[$id] = [
                'tool' => $action, 'params' => $action_params,
                'scheduled_at' => date('Y-m-d H:i:s'), 'run_at' => date('Y-m-d H:i:s', $timestamp), 'status' => 'pending',
            ];
            update_option('wpilot_scheduled_actions', $scheduled);
            wp_schedule_single_event($timestamp, 'wpilot_run_scheduled', [$id]);
            return wpilot_ok("Scheduled '{$action}' for " . date('Y-m-d H:i', $timestamp), ['id' => $id]);

        case 'list_scheduled':
        case 'cron_list':
            $scheduled = get_option('wpilot_scheduled_actions', []);
            $list = [];
            foreach ($scheduled as $id => $s) $list[] = array_merge(['id' => $id], $s);
            return wpilot_ok(count($list) . " scheduled actions.", ['actions' => $list]);

        case 'cancel_scheduled':
        case 'cron_delete':
            $id = sanitize_text_field($params['id'] ?? '');
            $scheduled = get_option('wpilot_scheduled_actions', []);
            if (isset($scheduled[$id])) { unset($scheduled[$id]); update_option('wpilot_scheduled_actions', $scheduled); return wpilot_ok("Cancelled: {$id}"); }
            return wpilot_err("Not found: {$id}");

        // ═══ FULL LOG VIEWER ═══
        case 'read_log':
        case 'error_log':
            $lines = min(intval($params['lines'] ?? 50), 200);
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (!file_exists($log_file)) $log_file = ini_get('error_log');
            if (!file_exists($log_file)) return wpilot_err('No log file found. Enable WP_DEBUG_LOG.');
            $file_lines = file($log_file);
            $total = count($file_lines);
            $tail = array_slice($file_lines, -$lines);
            return wpilot_ok("Last {$lines} of {$total} lines.", ['log' => implode('', $tail), 'total_lines' => $total]);

        
        // ═══ CHECKOUT PAGE DESIGN ═══
        case 'configure_updraftplus':
        case 'setup_backups':
            if (!class_exists('UpdraftPlus')) return wpilot_err('UpdraftPlus not installed.');
            $schedule_files = sanitize_text_field($params['files_schedule'] ?? 'weekly');
            $schedule_db = sanitize_text_field($params['db_schedule'] ?? 'daily');
            $retain = intval($params['retain'] ?? 5);
            $settings = get_option('updraft_interval', 'manual');
            update_option('updraft_interval', $schedule_files);
            update_option('updraft_interval_database', $schedule_db);
            update_option('updraft_retain', $retain);
            update_option('updraft_retain_db', $retain);
            // Configure storage if provided
            if (!empty($params['storage'])) {
                update_option('updraft_service', [$params['storage']]);
            }
            return wpilot_ok("UpdraftPlus configured: files={$schedule_files}, db={$schedule_db}, retain={$retain}.");

        case 'configure_litespeed':
        case 'setup_cache':
            if (!defined('LSCWP_V')) return wpilot_err('LiteSpeed Cache not installed.');
            $settings = [];
            if (isset($params['cache'])) { update_option('litespeed.conf.cache', $params['cache'] ? 1 : 0); $settings[] = 'cache=' . ($params['cache'] ? 'on' : 'off'); }
            if (isset($params['css_minify'])) { update_option('litespeed.conf.optm-css_min', $params['css_minify'] ? 1 : 0); $settings[] = 'css_minify'; }
            if (isset($params['js_minify'])) { update_option('litespeed.conf.optm-js_min', $params['js_minify'] ? 1 : 0); $settings[] = 'js_minify'; }
            if (isset($params['lazy_load'])) { update_option('litespeed.conf.media-lazy', $params['lazy_load'] ? 1 : 0); $settings[] = 'lazy_load'; }
            if (isset($params['browser_cache'])) { update_option('litespeed.conf.cache-browser', $params['browser_cache'] ? 1 : 0); $settings[] = 'browser_cache'; }
            // IMPORTANT: Keep CSS inline OFF (known to break themes)
            update_option('litespeed.conf.optm-css_comb_ext_inl', 0);
            update_option('litespeed.conf.css_async_inline', 0);
            return wpilot_ok("LiteSpeed configured: " . implode(', ', $settings));

        case 'configure_rankmath':
        case 'setup_seo_plugin':
            if (!class_exists('RankMath')) return wpilot_err('Rank Math not installed.');
            $settings = [];
            if (isset($params['sitemap'])) { update_option('rank_math_modules', array_merge(get_option('rank_math_modules', []), ['sitemap'])); $settings[] = 'sitemap'; }
            if (isset($params['schema'])) { update_option('rank_math_modules', array_merge(get_option('rank_math_modules', []), ['rich-snippet'])); $settings[] = 'schema'; }
            if (isset($params['breadcrumbs'])) { update_option('rank_math_breadcrumbs', $params['breadcrumbs'] ? 'on' : 'off'); $settings[] = 'breadcrumbs'; }
            if (isset($params['noindex_empty_cats'])) { update_option('rank_math_noindex_empty_category', $params['noindex_empty_cats'] ? 'on' : 'off'); $settings[] = 'noindex_empty'; }
            return wpilot_ok("Rank Math configured: " . implode(', ', $settings));

        case 'configure_polylang':
        case 'setup_multilang':
            if (!function_exists('pll_languages_list')) return wpilot_err('Polylang not installed.');
            $languages = pll_languages_list(['fields' => 'name']);
            return wpilot_ok(count($languages) . " languages configured: " . implode(', ', $languages), ['languages' => $languages]);

        case 'translate_page':
            if (!function_exists('pll_set_post_language')) return wpilot_err('Polylang required for translations.');
            $page_id = intval($params['page_id'] ?? 0);
            $lang = sanitize_text_field($params['language'] ?? $params['lang'] ?? '');
            if (!$page_id || empty($lang)) return wpilot_err('page_id and language required.');
            pll_set_post_language($page_id, $lang);
            return wpilot_ok("Page {$page_id} language set to {$lang}.");

        
        // ═══ SERVER PERFORMANCE INFO ═══
        case 'server_info':
        case 'system_info':
            $info = [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'memory_limit' => WP_MEMORY_LIMIT,
                'memory_used' => round(memory_get_usage() / 1048576, 1) . ' MB',
                'memory_peak' => round(memory_get_peak_usage() / 1048576, 1) . ' MB',
                'php_memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time') . 's',
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max' => ini_get('post_max_size'),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                'active_plugins' => count(get_option('active_plugins', [])),
                'theme' => get_template(),
                'builder' => wpilot_detect_builder(),
                'woocommerce' => class_exists('WooCommerce') ? WC()->version : 'not installed',
                'ssl' => is_ssl() ? 'yes' : 'no',
                'multisite' => is_multisite() ? 'yes' : 'no',
                'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'yes' : 'no',
                'debug_mode' => defined('WP_DEBUG') && WP_DEBUG ? 'yes' : 'no',
                'wpilot_tools' => substr_count(file_get_contents(__FILE__), "case '"),
            ];
            global $wpdb;
            $db_size = 0;
            foreach ($wpdb->get_results("SHOW TABLE STATUS") as $t) $db_size += $t->Data_length + $t->Index_length;
            $info['database_size'] = round($db_size / 1048576, 1) . ' MB';
            $info['db_tables'] = count($wpdb->get_results("SHOW TABLES"));
            return wpilot_ok("Server info: PHP " . PHP_VERSION . ", " . $info['memory_used'] . " used.", $info);

        case 'clear_screenshots':
        case 'delete_screenshots':
            wpilot_cleanup_all_screenshots();
            return wpilot_ok("All screenshots deleted.");

        case 'clear_temp_files':
        case 'cleanup':
            wpilot_cleanup_all_screenshots();
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpilot_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpilot_%'");
            delete_transient('wpilot_ctx_general');
            delete_transient('wpilot_ctx_build');
            delete_transient('wpilot_ctx_analyze');
            return wpilot_ok("All temp files, screenshots, and caches cleared.");

        case 'storage_usage':
            $dir = wp_upload_dir()['basedir'] . '/wpilot-screenshots';
            $screenshots = is_dir($dir) ? glob($dir . '/*.png') : [];
            $ss_size = 0;
            foreach ($screenshots as $f) $ss_size += filesize($f);
            $receipts = glob(wp_upload_dir()['basedir'] . '/wpilot-receipt-*.txt');
            $csvs = glob(wp_upload_dir()['basedir'] . '/wpilot-*.csv');
            $backups_dir = WP_CONTENT_DIR . '/wpilot-backups';
            $backup_size = 0;
            if (is_dir($backups_dir)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backups_dir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iter as $file) $backup_size += $file->getSize();
            }
            return wpilot_ok("WPilot storage usage", [
                'screenshots' => count($screenshots) . ' files (' . round($ss_size/1024) . ' KB)',
                'receipts' => count($receipts) . ' files',
                'csv_exports' => count($csvs) . ' files',
                'backups' => round($backup_size/1024) . ' KB',
                'total_kb' => round(($ss_size + $backup_size) / 1024),
            ]);

        
        // ═══ CUSTOM HEADER BUILDER ═══

        default:
            return null; // Not handled by this module
    }
}
