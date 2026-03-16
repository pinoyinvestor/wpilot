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
function wpilot_enhance_action_params(&$actions, $text) {
    // Strip markdown code fences from the full text for JSON search
    $clean_text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $clean_text = preg_replace('/\s*```/', '', $clean_text);

    foreach ($actions as &$action) {
        if (empty($action['params']) || $action['params'] === []) {
            // Extract params from description/label for common tools
            $desc = $action['label'] . ' ' . $action['description'];

            // URL tools: extract URL from description
            if (in_array($action['tool'], ['analyze_website','check_frontend','view_page','scrape_design','copy_design'])) {
                if (preg_match('/(https?:\/\/[^\s"<>]+)/', $desc, $um)) {
                    $action['params'] = ['url' => $um[1]];
                }
            }

            // User tools: extract email from description
            if (in_array($action['tool'], ['create_user'])) {
                $p = [];
                if (preg_match('/(?:username|användare|anv)[:\s]+([\w.-]+)/i', $desc, $m)) $p['username'] = $m[1];
                if (preg_match('/(?:email|e-post|epost|mail)[:\s]+([\w.@+-]+)/i', $desc, $m)) $p['email'] = $m[1];
                if (preg_match('/(?:role|roll)[:\s]+(\w+)/i', $desc, $m)) $p['role'] = $m[1];
                if (preg_match('/(?:password|lösenord|losenord)[:\s]+(\S+)/i', $desc, $m)) $p['password'] = $m[1];
                if (!empty($p)) { $action['params'] = $p; }
            }

            // Redirect tools: extract from/to from description
            if (in_array($action['tool'], ['create_redirect'])) {
                $p = [];
                if (preg_match('/(?:from|från|fran)[:\s]+(\/[\w-]+)/i', $desc, $m)) $p['from'] = $m[1];
                if (preg_match('/(?:to|till)[:\s]+(\/[\w-]+)/i', $desc, $m)) $p['to'] = $m[1];
                $p['type'] = 301;
                if (!empty($p['from']) && !empty($p['to'])) { $action['params'] = $p; }
            }
            // Try to find JSON near this action in the cleaned text
            $pos = strpos($clean_text, $action['tool']);
            if ($pos !== false) {
                $nearby = substr($clean_text, $pos, 3000);
                $found = wpilot_find_json_in_text($nearby);
                if ($found) $action['params'] = $found;
            }
        }
    }
}
