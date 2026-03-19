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


// Enhanced action parser — finds JSON in any part of action card
function wpilot_find_json_in_text($text) {
    // Strip markdown code fences: ```json ... ```
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```/', '', $text);
    $text = trim($text);
    // Strategy 0: Try to repair truncated JSON — add missing closing braces/quotes
    if (strpos($text, '{') !== false && json_decode($text, true) === null) {
        $repair = rtrim($text);
        // If JSON was truncated mid-string, close the string and object
        if (preg_match('/```json\s*(\{.+)/s', $repair, $jm)) {
            $jsonish = $jm[1];
            // Try progressively adding closing characters
            foreach (['"}', '"}', '"}}', '"}}}', "\"}"] as $suffix) {
                $try = $jsonish . $suffix;
                $decoded = json_decode($try, true);
                if (is_array($decoded) && !empty($decoded)) return $decoded;
            }
            // Try closing all open braces
            $open = substr_count($jsonish, '{') - substr_count($jsonish, '}');
            $open_q = (substr_count($jsonish, '"') % 2 !== 0) ? '"' : '';
            if ($open > 0) {
                $try = $jsonish . $open_q . str_repeat('}', $open);
                $decoded = json_decode($try, true);
                if (is_array($decoded) && !empty($decoded)) return $decoded;
            }
        }
    }
    // Strategy 1: Use PHP's json_decode with progressive substring extraction
    // Find all { positions and try json_decode from each one
    $offset = 0;
    $len = strlen($text);
    while (($start = strpos($text, '{', $offset)) !== false) {
        // Count braces manually to find the matching }
        $depth = 0;
        $in_string = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            // Handle string boundaries — count preceding backslashes for proper escape detection
            if ($ch === '"') {
                $backslashes = 0;
                for ($j = $i - 1; $j >= $start && $text[$j] === '\\'; $j--) $backslashes++;
                if ($backslashes % 2 === 0) $in_string = !$in_string;
            }
            if (!$in_string) {
                if ($ch === '{') $depth++;
                elseif ($ch === '}') $depth--;
                if ($depth === 0) {
                    $json = substr($text, $start, $i - $start + 1);
                    $decoded = json_decode($json, true);
                    if (is_array($decoded) && !empty($decoded)) return $decoded;
                    break;
                }
            }
        }
        if ($depth !== 0) break;
        $offset = $start + 1;
    }
    return null;
}

// ── Primary AI action parser ────────────────────────────────────
// Extracts ALL [ACTION: tool | description] cards and their code blocks
// Returns: [["tool" => "...", "label" => "...", "params" => [...]], ...]
function wpilot_parse_ai_actions($response_text) {
    $actions = [];
    if (empty($response_text)) return $actions;

    // Find all [ACTION: tool | label] tags with their positions in the ORIGINAL text
    // Supports: [ACTION: tool], [ACTION: tool | label], [ACTION: tool | label | desc | icon]
    preg_match_all(
        '/\[ACTION:\s*([^\]\|]+?)(?:\|([^\]\|]*?))?(?:\|([^\]\|]*?))?(?:\|([^\]]*?))?\]/s',
        $response_text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    if (empty($matches)) return $actions;

    $total_matches = count($matches);

    foreach ($matches as $idx => $match) {
        $tool  = trim($match[1][0]);
        $label = trim($match[2][0] ?? '');
        $desc  = trim($match[3][0] ?? '');
        $icon  = trim($match[4][0] ?? '');

        // Clean tool name — remove trailing punctuation/emoji
        $tool = preg_replace('/[\s\-—:]+$/', '', $tool);
        $tool = trim($tool);

        // Determine the text segment AFTER this action tag, up to the next action tag
        $tag_end = $match[0][1] + strlen($match[0][0]);
        $next_tag_start = ($idx + 1 < $total_matches) ? $matches[$idx + 1][0][1] : strlen($response_text);
        $segment = substr($response_text, $tag_end, $next_tag_start - $tag_end);

        // ── Extract params from the NEXT code block (do NOT strip fences first) ──
        $params = [];
        $bt = '```';

        // 1) Try ```json ... ``` block (greedy within segment)
        if (preg_match('/```json\s*(.*?)```/s', $segment, $jm)) {
            $json_text = trim($jm[1]);
            $decoded = wpilot_find_json_in_text($json_text);
            if ($decoded) $params = $decoded;
        }
        // Also try bare JSON on same line or next line (no fences)
        if (empty($params) && preg_match('/\{[^}]/s', $segment)) {
            $decoded = wpilot_find_json_in_text($segment);
            if ($decoded) $params = $decoded;
        }

        // 2) Try ```css ... ``` block
        if (empty($params) && preg_match('/```css\s*(.*?)```/s', $segment, $cm)) {
            $css_content = trim($cm[1]);
            if (strlen($css_content) > 0) {
                $params = ['css' => $css_content];
            }
        }

        // 3) Try ```html ... ``` block
        if (empty($params) && preg_match('/```html\s*(.*?)```/s', $segment, $hm)) {
            $html_content = trim($hm[1]);
            // Clean up truncated HTML
            $html_content = preg_replace('/<[^>]*$/', '', $html_content);
            if (substr_count($html_content, '<style') > substr_count($html_content, '</style')) $html_content .= '</style>';
            if (substr_count($html_content, '<div') > substr_count($html_content, '</div>')) $html_content .= '</div>';
            if (strlen($html_content) > 0) {
                $params = ['html' => $html_content, 'content' => $html_content];
            }
        }

        // 4) Try unclosed code blocks (AI truncated or single fence)
        if (empty($params)) {
            if (preg_match('/```json\s*(.*)/s', $segment, $jm)) {
                $decoded = wpilot_find_json_in_text(trim($jm[1]));
                if ($decoded) $params = $decoded;
            } elseif (preg_match('/```css\s*(.*)/s', $segment, $cm)) {
                $css = trim(preg_replace('/\s*```.*$/s', '', $cm[1]));
                if (strlen($css) > 0) $params = ['css' => $css];
            } elseif (preg_match('/```html\s*(.*)/s', $segment, $hm)) {
                $html = trim(preg_replace('/\s*```.*$/s', '', $hm[1]));
                if (strlen($html) > 0) $params = ['html' => $html, 'content' => $html];
            }
        }

        // ── Extract inline params from label/description text ──
        // Built by Weblease
        $inline_text = $label . ' ' . $desc . ' ' . ($match[0][0] ?? '');

        // Page/post ID: "page ID 150", "id 150", "post_id 75", "product_id 75"
        if (!isset($params['id']) && !isset($params['post_id'])) {
            if (preg_match('/(?:page|post|product)?[_\s]*(?:id|ID)[:\s]+(\d+)/i', $inline_text, $im)) {
                $params['id'] = intval($im[1]);
            }
        }

        // Slug: "slug:about", "slug about-us"
        if (!isset($params['slug'])) {
            if (preg_match('/slug[:\s]+["\']?([a-z0-9\-_]+)["\']?/i', $inline_text, $sm)) {
                $params['slug'] = $sm[1];
            }
        }

        // Product ID specifically
        if (!isset($params['product_id'])) {
            if (preg_match('/product[_\s]*id[:\s]+(\d+)/i', $inline_text, $pm)) {
                $params['product_id'] = intval($pm[1]);
            }
        }

        // Price
        if (!isset($params['price'])) {
            if (preg_match('/(?:price|pris)[:\s]+\$?(\d+(?:\.\d+)?)/i', $inline_text, $pp)) {
                $params['price'] = $pp[1];
            }
        }

        // URL
        if (!isset($params['url'])) {
            if (preg_match('/(https?:\/\/[^\s"<>]+)/', $inline_text, $um)) {
                $params['url'] = $um[1];
            }
        }

        // ── Smart param fixing for replace_in_page ──
        // If Claude provided html block instead of search/replace JSON, parse the label for intent
        if (in_array($tool, ['replace_in_page', 'edit_page_css']) && empty($params['search']) && empty($params['find'])) {
            // Try to extract old→new from two consecutive html blocks in the segment
            if (preg_match_all('//s', $segment, $html_blocks) && count($html_blocks[1]) >= 2) {
                $params['search'] = trim($html_blocks[1][0]);
                $params['replace'] = trim($html_blocks[1][1]);
                unset($params['html'], $params['content']);
            }
            // If only one html block — try to use the page content to find what to replace
            elseif (!empty($params['html']) && !empty($params['id'])) {
                $page_content = get_post_field('post_content', intval($params['id']));
                $new_html = $params['html'];
                // Extract the tag type and try to find similar element in page
                if (preg_match('/<(a|button|h[1-6]|p|div|span)[^>]*>/', $new_html, $tag_match)) {
                    $tag = $tag_match[1];
                    // Find all elements of same tag in page content
                    if (preg_match_all('/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/s', $page_content, $existing)) {
                        // If there is text overlap (like class name), pick the closest match
                        foreach ($existing[0] as $candidate) {
                            // Check if the label mentions text that appears in this candidate
                            $label_words = preg_split('/[\s,;|]+/', strtolower($label));
                            $candidate_lower = strtolower($candidate);
                            $overlap = 0;
                            foreach ($label_words as $w) {
                                if (strlen($w) > 2 && strpos($candidate_lower, $w) !== false) $overlap++;
                            }
                            if ($overlap > 0) {
                                $params['search'] = $candidate;
                                $params['replace'] = $new_html;
                                unset($params['html'], $params['content']);
                                break;
                            }
                        }
                    }
                }
            }
        }

        $actions[] = [
            'tool'        => $tool,
            'label'       => $label ?: $tool,
            'description' => $desc,
            'icon'        => $icon,
            'params'      => $params,
        ];
    }

    return $actions;
}

// ── Standalone action executor ──────────────────────────────────
// Takes parsed actions array, executes each via wpilot_run_tool()
// Returns results array — callable from anywhere (AJAX, cron, CLI)
function wpilot_auto_execute_actions($actions) {
    if (empty($actions) || !function_exists('wpilot_safe_run_tool')) return [];

    $results  = [];
    $ok_count = 0;
    $total    = count($actions);

    foreach ($actions as $idx => $a) {
        $tool   = $a['tool'];
        $params = $a['params'] ?? [];
        $label  = $a['label'] ?? $tool;

        // Create backup before execution
        $backup_id = function_exists('wpilot_backup_log') ? wpilot_backup_log($tool, $params) : null;

        // Execute the tool
        $_tool_start = microtime(true);
        $r = wpilot_safe_run_tool($tool, $params);
        $_tool_ms = intval((microtime(true) - $_tool_start) * 1000);

        // Collect usage data
        if (function_exists('wpilot_collect_tool_usage')) {
            // Removed: wpilot_collect_tool_usage (module deleted)
        }

        $entry = [
            'tool'      => $tool,
            'label'     => $label,
            'params'    => $params,
            'backup_id' => $backup_id,
        ];

        if (!empty($r['success'])) {
            $ok_count++;
            $entry['status']  = 'done';
            $entry['message'] = $r['message'] ?? 'OK';

            if (function_exists('wpilot_log_activity')) {
                wpilot_log_activity($tool, '[Auto] ' . $label, $r['message'] ?? '', $backup_id, 'ok');
            }
        } else {
            $err_msg = $r['message'] ?? 'Failed';
            $entry['status']  = 'failed';
            $entry['message'] = $err_msg;

            if (function_exists('wpilot_log_activity')) {
                wpilot_log_activity($tool, '[Auto] ' . $label, $err_msg, $backup_id, 'error');
            }
        }

        $results[] = $entry;
    }

    return [
        'results'  => $results,
        'total'    => $total,
        'ok_count' => $ok_count,
        'summary'  => $ok_count . '/' . $total . ' actions completed',
    ];
}

// ── Legacy compat: compact format parser (delegates to new parser) ──
function wpilot_parse_compact_actions($text) {
    return wpilot_parse_ai_actions($text);
}

// Enhanced standard parser — finds JSON in any pipe-separated part
function wpilot_enhance_action_params(&$actions, $text) {
    // With wpilot_parse_ai_actions, params are already extracted
    // This function now only fills in missing params for actions parsed by the old wpilot_parse_actions()

    // Strip markdown code fences from the full text for JSON search
    $clean_text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $clean_text = preg_replace('/\s*```/', '', $clean_text);

    // Find ALL [ACTION:...] positions in original text to scope JSON search per action
    preg_match_all('/\[ACTION:\s*[^\]]+\]/', $text, $action_matches, PREG_OFFSET_CAPTURE);
    $action_positions = array_map(function($m) { return $m[1]; }, $action_matches[0] ?? []);

    foreach ($actions as $idx => &$action) {
        // Skip if already has meaningful params (from wpilot_parse_ai_actions)
        $has_full_params = !empty($action['params']) && (
            isset($action['params']['html']) || isset($action['params']['css']) ||
            isset($action['params']['code']) || isset($action['params']['content']) ||
            count($action['params']) >= 2
        );
        if ($has_full_params) continue;

        // Extract params from description/label for common tools
        $desc = $action['label'] . ' ' . $action['description'];

        // URL tools: extract URL from description
        if (in_array($action['tool'], ['analyze_website','check_frontend','view_page','scrape_design','copy_design'])) {
            if (preg_match('/(https?:\/\/[^\s"<>]+)/', $desc, $um)) {
                $action['params'] = ['url' => $um[1]];
                continue;
            }
        }

        // User tools: extract email from description
        if (in_array($action['tool'], ['create_user'])) {
            $p = [];
            if (preg_match('/(?:username|användare|anv)[:\s]+([\w.-]+)/i', $desc, $m)) $p['username'] = $m[1];
            if (preg_match('/(?:email|e-post|epost|mail)[:\s]+([\w.@+-]+)/i', $desc, $m)) $p['email'] = $m[1];
            if (preg_match('/(?:role|roll)[:\s]+(\w+)/i', $desc, $m)) $p['role'] = $m[1];
            if (preg_match('/(?:password|lösenord|losenord)[:\s]+(\S+)/i', $desc, $m)) $p['password'] = $m[1];
            if (!empty($p)) { $action['params'] = $p; continue; }
        }

        // Redirect tools: extract from/to from description
        if (in_array($action['tool'], ['create_redirect'])) {
            $p = [];
            if (preg_match('/(?:from|från|fran)[:\s]+(\/[\w-]+)/i', $desc, $m)) $p['from'] = $m[1];
            if (preg_match('/(?:to|till)[:\s]+(\/[\w-]+)/i', $desc, $m)) $p['to'] = $m[1];
            $p['type'] = 301;
            if (!empty($p['from']) && !empty($p['to'])) { $action['params'] = $p; continue; }
        }

        // Scope search from this action's position to the next
        if (isset($action_positions[$idx])) {
            $start = $action_positions[$idx];
            $end = isset($action_positions[$idx + 1]) ? $action_positions[$idx + 1] : strlen($text);
            $segment = substr($text, $start, max($end - $start, 50000));

            // Try code blocks in ORIGINAL text (not stripped)
            if (preg_match('/```json\s*(.*?)```/s', $segment, $jm)) {
                $found = wpilot_find_json_in_text(trim($jm[1]));
                if ($found) { $action['params'] = $found; continue; }
            }
            if (preg_match('/```css\s*(.*?)```/s', $segment, $cm)) {
                $action['params'] = ['css' => trim($cm[1])];
                continue;
            }
            if (preg_match('/```html\s*(.*?)```/s', $segment, $hm)) {
                $html = trim($hm[1]);
                $action['params'] = ['html' => $html, 'content' => $html];
                continue;
            }

            // Fallback: try JSON in stripped segment
            $clean = preg_replace('/```(?:json)?\s*/i', '', $segment);
            $clean = preg_replace('/\s*```/', '', $clean);
            $found = wpilot_find_json_in_text($clean);
            if ($found) { $action['params'] = $found; continue; }
        }

        // Last resort: search from tool name position
        $search_offset = 0;
        for ($i = 0; $i <= $idx; $i++) {
            $pos = strpos($clean_text, $actions[$i]['tool'], $search_offset);
            if ($pos !== false) $search_offset = $pos + strlen($actions[$i]['tool']);
        }
        if ($search_offset > 0) {
            $nearby = substr($clean_text, $search_offset - strlen($action['tool']), 3000);
            $found = wpilot_find_json_in_text($nearby);
            if ($found) $action['params'] = $found;
        }
    }
}
