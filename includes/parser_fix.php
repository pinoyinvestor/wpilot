<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Enhanced action parser — finds JSON in any part of action card
function wpilot_find_json_in_text($text) {
    // Strip markdown code fences: ```json ... ```
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```/', '', $text);
    $text = trim($text);
    $start = strpos($text, '{');
    if ($start === false) return null;
    $depth = 0;
    $in_string = false;
    for ($i = $start; $i < strlen($text); $i++) {
        $ch = $text[$i];
        if ($ch === '"' && ($i === 0 || $text[$i-1] !== '\\')) $in_string = !$in_string;
        if (!$in_string) {
            if ($ch === '{') $depth++;
            if ($ch === '}') $depth--;
            if ($depth === 0) {
                $json = substr($text, $start, $i - $start + 1);
                $decoded = json_decode($json, true);
                if (is_array($decoded) && !empty($decoded)) return $decoded;
                return null;
            }
        }
    }
    return null;
}

// Compact format parser: [ACTION: tool]\n{json}
function wpilot_parse_compact_actions($text) {
    $actions = [];
    preg_match_all('/\[ACTION:\s*([^\]\|]+?)\s*\]/', $text, $cm, PREG_OFFSET_CAPTURE);
    foreach ($cm[1] as $match) {
        $tool = trim($match[0]);
        // Remove trailing punctuation/emoji from tool name
        $tool = preg_replace('/[\s\-—]+$/', '', $tool);
        $tool = trim($tool);
        
        $afterPos = $match[1] + strlen($match[0]) + 1;
        $after = substr($text, $afterPos, 3000);
        // Strip markdown code fences before JSON search
        $after = preg_replace('/```(?:json)?\s*/i', '', $after);
        $after = preg_replace('/\s*```/', '', $after);
        $after = trim($after);
        $params = wpilot_find_json_in_text($after) ?: [];
        
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
        if (empty($action['params']) || $action['params'] === []) {
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

            // Scope JSON search: from this action's position to the next action's position
            if (isset($action_positions[$idx])) {
                $start = $action_positions[$idx];
                $end = isset($action_positions[$idx + 1]) ? $action_positions[$idx + 1] : strlen($text);
                $segment = substr($text, $start, $end - $start);
                // Clean markdown fences from segment
                $segment = preg_replace('/```(?:json)?\s*/i', '', $segment);
                $segment = preg_replace('/\s*```/', '', $segment);
                $found = wpilot_find_json_in_text($segment);
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
