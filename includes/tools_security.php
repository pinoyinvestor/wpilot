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
if (!defined('ABSPATH')) exit;

/**
 * WPilot Security Tools Module
 * Contains 13 tool cases for security operations.
 */
function wpilot_run_security_tools($tool, $params = []) {
    switch ($tool) {

        case 'add_security_headers':
            return wpilot_fix_security('add_security_headers', $params);

        case 'disable_xmlrpc':
            return wpilot_fix_security('disable_xmlrpc', $params);

        /* ── PageSpeed Test ──────────────────────────────── */
        /* ── Performance Fix Tools ───────────────────────── */
        case 'security_scan':
            return wpilot_security_scan();

        case 'fix_security_issue':
            $issue = sanitize_text_field($params['issue'] ?? '');
            // Auto-detect issue from description if not set
            if (empty($issue)) {
                $desc = sanitize_text_field($params['description'] ?? '');
                $desc_lower = strtolower($desc);
                if (strpos($desc_lower, 'header') !== false) $issue = 'add_security_headers';
                elseif (strpos($desc_lower, 'xml') !== false || strpos($desc_lower, 'xmlrpc') !== false) $issue = 'disable_xmlrpc';
                elseif (strpos($desc_lower, 'readme') !== false) $issue = 'delete_readme';
                elseif (strpos($desc_lower, 'registr') !== false) $issue = 'disable_registration';
                else $issue = 'add_security_headers'; // default
            }
            return wpilot_fix_security($issue, $params);

        /* ── 404 Page ───────────────────────────────────────── */
        case 'block_ip':
        case 'ban_ip':
            $ip = sanitize_text_field($params['ip'] ?? '');
            $reason = sanitize_text_field($params['reason'] ?? 'Blocked by WPilot');
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return wpilot_err('Valid IP address required.');
            $blocked = get_option('wpilot_blocked_ips', []);
            $blocked[$ip] = ['reason' => $reason, 'date' => date('Y-m-d H:i:s')];
            update_option('wpilot_blocked_ips', $blocked);
            // Add to .htaccess if Apache
            $htaccess = ABSPATH . '.htaccess';
            if (file_exists($htaccess)) {
                $content = file_get_contents($htaccess);
                if (strpos($content, $ip) === false) {
                    $rule = "\n# WPilot blocked IP\nDeny from {$ip}\n";
                    $content = str_replace('# END WordPress', $rule . "# END WordPress", $content);
                    file_put_contents($htaccess, $content);
                }
            }
            return wpilot_ok("Blocked IP: {$ip}", ['ip' => $ip, 'reason' => $reason]);

        case 'unblock_ip':
            $ip = sanitize_text_field($params['ip'] ?? '');
            $blocked = get_option('wpilot_blocked_ips', []);
            unset($blocked[$ip]);
            update_option('wpilot_blocked_ips', $blocked);
            return wpilot_ok("Unblocked IP: {$ip}");

        case 'list_blocked_ips':
            $blocked = get_option('wpilot_blocked_ips', []);
            $list = [];
            foreach ($blocked as $ip => $data) $list[] = array_merge(['ip' => $ip], $data);
            return wpilot_ok(count($list) . " blocked IPs.", ['ips' => $list]);

        case 'failed_logins':
        case 'login_attempts':
            global $wpdb;
            // Check if Wordfence or Limit Login Attempts is active
            $attempts = [];
            // Try WPilot's own tracking
            $log = get_option('wpilot_failed_logins', []);
// Built by Weblease
            // Try WordPress default
            if (empty($log)) {
                $log_file = WP_CONTENT_DIR . '/debug.log';
                if (file_exists($log_file)) {
                    $lines = file($log_file);
                    foreach (array_reverse($lines) as $line) {
                        if (stripos($line, 'authentication') !== false || stripos($line, 'login failed') !== false) {
                            $attempts[] = trim($line);
                            if (count($attempts) >= 20) break;
                        }
                    }
                }
            }
            return wpilot_ok(count($attempts) . " failed login entries found.", ['attempts' => $attempts]);

        case 'security_audit':
        case 'full_security_check':
            $issues = [];
            $score = 100;
            // Check debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) { $issues[] = ['severity' => 'high', 'issue' => 'WP_DEBUG is enabled', 'fix' => 'Set WP_DEBUG to false in wp-config.php']; $score -= 15; }
            // Check file editing
            if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) { $issues[] = ['severity' => 'medium', 'issue' => 'File editing enabled in admin', 'fix' => "Add define('DISALLOW_FILE_EDIT', true) to wp-config.php"]; $score -= 10; }
            // Check admin username
            if (username_exists('admin')) { $issues[] = ['severity' => 'high', 'issue' => 'Default "admin" username exists', 'fix' => 'Rename admin user']; $score -= 15; }
            // Check SSL
            if (!is_ssl() && strpos(get_site_url(), 'https') === false) { $issues[] = ['severity' => 'high', 'issue' => 'No SSL/HTTPS', 'fix' => 'Install SSL certificate']; $score -= 20; }
            // Check WP version
            global $wp_version;
            $latest = get_site_transient('update_core');
            if ($latest && !empty($latest->updates) && version_compare($wp_version, $latest->updates[0]->version, '<')) {
                $issues[] = ['severity' => 'high', 'issue' => "WordPress outdated: {$wp_version} (latest: " . $latest->updates[0]->version . ")", 'fix' => 'Update WordPress'];
                $score -= 15;
            }
            // Check plugin updates
            $update_plugins = get_site_transient('update_plugins');
            $outdated = !empty($update_plugins->response) ? count($update_plugins->response) : 0;
            if ($outdated > 0) { $issues[] = ['severity' => 'medium', 'issue' => "{$outdated} plugins need updates", 'fix' => 'Update plugins']; $score -= min($outdated * 5, 20); }
            // Check xmlrpc
            $xmlrpc_test = wp_remote_get(home_url('/xmlrpc.php'), ['timeout' => 5]);
            if (!is_wp_error($xmlrpc_test) && wp_remote_retrieve_response_code($xmlrpc_test) === 200) {
                $issues[] = ['severity' => 'medium', 'issue' => 'XML-RPC enabled', 'fix' => 'Use disable_xmlrpc tool']; $score -= 5;
            }
            // Check security headers
            $home_resp = wp_remote_get(home_url('/'), ['timeout' => 5]);
            if (!is_wp_error($home_resp)) {
                $h = wp_remote_retrieve_headers($home_resp);
                if (empty($h['x-frame-options'])) { $issues[] = ['severity' => 'medium', 'issue' => 'Missing X-Frame-Options header']; $score -= 5; }
                if (empty($h['x-content-type-options'])) { $issues[] = ['severity' => 'low', 'issue' => 'Missing X-Content-Type-Options header']; $score -= 3; }
                if (empty($h['strict-transport-security'])) { $issues[] = ['severity' => 'low', 'issue' => 'Missing HSTS header']; $score -= 3; }
            }
            // Check blocked IPs
            $blocked = count(get_option('wpilot_blocked_ips', []));
            $score = max(0, $score);
            $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));
            return wpilot_ok("Security score: {$score}/100 (Grade {$grade}). " . count($issues) . " issues found.", [
                'score' => $score, 'grade' => $grade, 'issues' => $issues, 'blocked_ips' => $blocked,
            ]);

        case 'configure_wordfence':
            if (!is_plugin_active('wordfence/wordfence.php')) return wpilot_err('Wordfence not installed. Use plugin_install to add it.');
            $settings = [];
            if (isset($params['firewall'])) $settings['firewallEnabled'] = $params['firewall'] ? 1 : 0;
            if (isset($params['brute_force'])) $settings['loginSecurityEnabled'] = $params['brute_force'] ? 1 : 0;
            if (isset($params['scan_schedule'])) $settings['scheduledScansEnabled'] = 1;
            if (isset($params['block_fake_google'])) $settings['blockFakeCrawlers'] = $params['block_fake_google'] ? 1 : 0;
            foreach ($settings as $k => $v) update_option('wordfence_' . $k, $v);
            return wpilot_ok("Wordfence configured: " . count($settings) . " settings updated.", ['settings' => $settings]);

        // ═══ PLUGIN POWER INTEGRATIONS ═══

        default:
            return null; // Not handled by this module
    }
}
