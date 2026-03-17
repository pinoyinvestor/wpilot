<?php
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

// Compact format parser: [ACTION: tool]\n{json}
function wpilot_parse_compact_actions($text) {
    $actions = [];
    $idx = 0;
    preg_match_all('/\[ACTION:\s*([^\]\|]+?)\s*\]/', $text, $cm, PREG_OFFSET_CAPTURE);
    foreach ($cm[1] as $match) {
        $tool = trim($match[0]);
        // Remove trailing punctuation/emoji from tool name
        $tool = preg_replace('/[\s\-—]+$/', '', $tool);
        $tool = trim($tool);
        
        $afterPos = $match[1] + strlen($match[0]) + 1;
        // Find next ACTION tag to scope search, fallback to 50000 chars
        $nextAction = isset($cm[1][$idx + 1]) ? $cm[1][$idx + 1][1] : $afterPos + 50000;
        $after = substr($text, $afterPos, $nextAction - $afterPos);
        $idx++;
        // Strip markdown code fences before JSON search
        $after = preg_replace('/```(?:json)?\s*/i', '', $after);
        $after = preg_replace('/\s*```/', '', $after);
        $after = trim($after);
        // Try HTML block first (```html ... ```) — preferred for page content
        $params = [];
        $bt = '`' . '`' . '`';
        // Match closed block first, then unclosed (truncated response)
        if (preg_match('/' . preg_quote($bt) . 'html\s*(.*?)' . preg_quote($bt) . '/s', $after, $hm)
            || preg_match('/' . preg_quote($bt) . 'html\s*(.*)/s', $after, $hm)) {
            $html_content = trim($hm[1]);
            // Clean up truncated HTML — remove trailing incomplete tags and close style/div
            $html_content = preg_replace('/<[^>]*$/', '', $html_content); // remove incomplete tag at end
            if (substr_count($html_content, '<style') > substr_count($html_content, '</style')) $html_content .= '</style>';
            if (substr_count($html_content, '<div') > substr_count($html_content, '</div>')) $html_content .= '</div>';
            // Remove error messages appended after HTML
            $html_content = preg_replace('/\n\n.*Auto-approved:.*$/s', '', $html_content);
            if (strlen($html_content) > 10) {
                $params = ['html' => $html_content];
                if (preg_match('/slug[:\s]+["\']?([a-z0-9\-]+)/i', $tool, $sm)) $params['slug'] = $sm[1];
                if (preg_match('/title[:\s]+["\']?([^"\']+)/i', $tool, $tm)) $params['title'] = trim($tm[1]);
                if (preg_match('/(?:ID|id)\s+(\d+)/', $tool, $im)) $params['post_id'] = intval($im[1]);
            }
        }
        // Fallback: try JSON block
        if (empty($params)) {
            $clean_after = preg_replace('/```(?:json)?\s*/i', '', $after);
            $clean_after = preg_replace('/\s*```/', '', $clean_after);
            $params = wpilot_find_json_in_text(trim($clean_after)) ?: [];
        }

        $actions[] = [
            'tool' => $tool,
            'label' => $tool,
            'description' => '',
            'icon' => '',
            'params' => $params,
        ];
    }
    return $actions;
}

// Enhanced standard parser — finds JSON in any pipe-separated part
// Built by Christos Ferlachidis & Daniel Hedenberg
function wpilot_enhance_action_params(&$actions, $text) {
    // Strip markdown code fences from the full text for JSON search
    $clean_text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $clean_text = preg_replace('/\s*```/', '', $clean_text);

    // Find ALL [ACTION:...] positions in original text to scope JSON search per action
    preg_match_all('/\[ACTION:\s*[^\]]+\]/', $text, $action_matches, PREG_OFFSET_CAPTURE);
    $action_positions = array_map(function($m) { return $m[1]; }, $action_matches[0] ?? []);

    foreach ($actions as $idx => &$action) {
        // Always try to find full JSON params — even if we have partial inline params
        $has_full_params = !empty($action['params']) && (isset($action['params']['html']) || isset($action['params']['code']) || isset($action['params']['content']) || count($action['params']) >= 3);
        if (!$has_full_params) {
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

                // Try HTML block first — closed or unclosed (truncated)
                $bt = '`' . '`' . '`';
                if (preg_match('/' . preg_quote($bt) . 'html\s*(.*?)' . preg_quote($bt) . '/s', $segment, $hm)
                    || preg_match('/' . preg_quote($bt) . 'html\s*(.*)/s', $segment, $hm)) {
                    $html = trim($hm[1]);
                    if (strlen($html) > 10) {
                        $action['params'] = array_merge($action['params'] ?: [], ['html' => $html]);
                        // Extract slug/title/id from action label
                        $label = $action['label'] . ' ' . ($action_matches[0][$idx][0] ?? '');
                        if (preg_match('/slug[:\s]+["\']?([a-z0-9\-]+)/i', $label, $sm)) $action['params']['slug'] = $sm[1];
                        if (preg_match('/(?:ID|id)\s+(\d+)/', $label, $im)) $action['params']['post_id'] = intval($im[1]);
                        continue;
                    }
                }

                // Try JSON block
                $clean = preg_replace('/```(?:json)?\s*/i', '', $segment);
                $clean = preg_replace('/\s*```/', '', $clean);
                $found = wpilot_find_json_in_text($clean);
                if ($found) { $action['params'] = $found; continue; }
            }

            // Fallback: search from tool name position but use offset tracking
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
}
