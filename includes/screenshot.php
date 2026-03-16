<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  SCREENSHOT — Visual page analysis using Chromium + Claude Vision
//
//  Takes screenshots of any page, sends to Claude Vision API
//  for design analysis, layout review, and visual bug detection.
// ═══════════════════════════════════════════════════════════════

define('WPILOT_SCREENSHOT_DIR', wp_upload_dir()['basedir'] . '/wpilot-screenshots');
define('WPILOT_SCREENSHOT_URL', wp_upload_dir()['baseurl'] . '/wpilot-screenshots');

/**
 * Take a screenshot of a URL using headless Chromium
 */
function wpilot_take_screenshot( $url, $options = [] ) {
    // Check if hosting supports screenshots
    if (function_exists('wpilot_can_use') && !wpilot_can_use_feature('screenshot')) {
        return new WP_Error('low_memory', 'Screenshots need 512MB+ PHP memory. Your hosting has ' . ini_get('memory_limit') . '. Use check_frontend instead.');
    }
    // Low-memory optimization: reduce resolution
    if (function_exists('wpilot_memory_ok') && !wpilot_memory_ok(48)) {
        $options['width'] = min($options['width'] ?? 1440, 1024);
        $options['height'] = min($options['height'] ?? 900, 768);
    } // memory_check
    $width    = intval($options['width']  ?? 1440);
    $height   = intval($options['height'] ?? 900);
    $mobile   = !empty($options['mobile']);
    $delay    = intval($options['delay'] ?? 2);

    if ($mobile) {
        $width  = 390;
        $height = 844;
    }

    // Ensure screenshot directory exists
    if (!file_exists(WPILOT_SCREENSHOT_DIR)) {
        wp_mkdir_p(WPILOT_SCREENSHOT_DIR);
    }

    // Clean old screenshots (keep last 50)
    wpilot_cleanup_screenshots();

    // Generate unique filename
    $slug = sanitize_title(wp_parse_url($url, PHP_URL_PATH) ?: 'home');
    $filename = $slug . '-' . date('Ymd-His') . ($mobile ? '-mobile' : '') . '.png';
    $filepath = WPILOT_SCREENSHOT_DIR . '/' . $filename;

    // Find Chromium binary
    $chrome = '';
    foreach (['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'] as $path) {
        if (file_exists($path)) { $chrome = $path; break; }
    }
    if (empty($chrome)) {
        return new WP_Error('no_chrome', 'Chromium not installed. Install with: apt install chromium');
    }

    // Build safe argument list (no shell interpolation)
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $budget = max(1000, $delay * 1000);
    $args = [
        $chrome,
        '--headless=new',
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--disable-software-rasterizer',
        '--hide-scrollbars',
        '--disable-extensions',
        '--no-first-run',
        '--disable-background-networking',
        '--window-size=' . $width . ',' . $height,
        '--virtual-time-budget=' . $budget,
        '--screenshot=' . $filepath,
    ];

    if ($mobile) {
        $args[] = '--user-agent=Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
    }

    $args[] = $url;

    // Use proc_open for safe execution (no shell injection)
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($args, $descriptors, $pipes);

    if (!is_resource($proc)) {
        return new WP_Error('screenshot_failed', 'Could not start Chromium process');
    }

    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 30);
    stream_set_timeout($pipes[2], 30);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($proc);

    if (!file_exists($filepath) || filesize($filepath) < 100) {
        return new WP_Error('screenshot_failed', 'Screenshot failed: ' . substr($stderr, 0, 200));
    }

    return [
        'path'     => $filepath,
        'url'      => WPILOT_SCREENSHOT_URL . '/' . $filename,
        'filename' => $filename,
        'size_kb'  => round(filesize($filepath) / 1024),
        'width'    => $width,
        'height'   => $height,
        'mobile'   => $mobile,
    ];
}

/**
 * Analyze a screenshot using Claude Vision API
 */
function wpilot_analyze_screenshot( $screenshot_path, $analysis_type = 'design', $context = '' ) {
    $api_key = get_option( 'ca_api_key', '' );
    if ( empty($api_key) ) return new WP_Error('no_key', 'No API key configured.');

    if (!file_exists($screenshot_path)) {
        return new WP_Error('no_file', 'Screenshot file not found.');
    }

    // Read and encode image
    $image_data = file_get_contents($screenshot_path);
    $base64 = base64_encode($image_data);
    $media_type = 'image/png';

    // Build analysis prompt based on type
    $prompts = [
        'design' => "You are a senior web designer reviewing this webpage screenshot. Analyze:
1. **Visual hierarchy** — Is important content prominent? Is the eye drawn correctly?
2. **Color scheme** — Are colors harmonious? Is contrast adequate for readability?
3. **Typography** — Font sizes, line heights, readability. Is text hierarchy clear?
4. **Spacing** — Padding, margins, whitespace. Too cramped or too sparse?
5. **Layout** — Alignment, grid consistency, responsive readiness
6. **CTA visibility** — Are buttons/actions clear and clickable?
7. **Overall quality score** — Rate 1-10 with specific improvements

Be specific. Reference exact areas (top-left, hero section, footer, etc).
After your analysis, output ACTION cards for CSS fixes:
[ACTION: append_custom_css | Fixing spacing and typography issues]",

        'bugs' => "You are a QA engineer inspecting this webpage screenshot for visual bugs. Check:
1. **Overlapping elements** — Text on images, elements stacking wrong
2. **Broken layout** — Misaligned columns, overflow, horizontal scroll
3. **Missing images** — Broken image placeholders, missing backgrounds
4. **Text issues** — Truncated text, tiny fonts, contrast failures
5. **CSS leaks** — Raw CSS code visible, unstyled elements
6. **Navigation** — Missing, duplicated, or broken navigation

List each bug with severity (critical/major/minor).
Output [ACTION:] cards to fix each bug found.",

        'comparison' => "Compare this screenshot to the design described below and identify differences:
{$context}

Check: colors, fonts, spacing, layout, content, images. List what matches and what needs fixing.
Output [ACTION:] cards for each fix needed.",

        'accessibility' => "Review this webpage screenshot for accessibility:
1. **Color contrast** — WCAG AA compliance (4.5:1 text, 3:1 large text)
2. **Font sizes** — Minimum 16px body, readable headings
3. **Touch targets** — Minimum 44x44px for buttons/links
4. **Visual indicators** — Not relying on color alone
Rate overall accessibility 1-10.
Output [ACTION:] cards for each fix needed.",

        'seo_visual' => "Analyze this webpage from an SEO perspective (visual elements):
1. **H1 tag** — Is there one clear primary heading?
2. **Content structure** — Logical heading hierarchy visible?
3. **CTA** — Clear calls to action?
4. **Mobile friendly** — Would this pass Google's mobile test?
Output [ACTION:] cards for improvements.",

        'full' => "You are reviewing this webpage screenshot. Do a COMPLETE analysis:

**DESIGN** (rate 1-10): hierarchy, colors, typography, spacing, layout, CTAs
**BUGS** (list all): overlaps, broken layout, missing images, text issues
**ACCESSIBILITY** (rate 1-10): contrast, font sizes, touch targets
**SEO** (rate 1-10): headings, structure, mobile-friendliness
**PERFORMANCE HINTS**: heavy images, too many elements, render-blocking hints

For EACH issue found, output an [ACTION:] card to fix it.
Example: [ACTION: append_custom_css | Fix hero section spacing - padding too small]",
    ];

    $prompt = $prompts[$analysis_type] ?? $prompts['full'];

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode([
            'model'      => CA_MODEL,
            'max_tokens' => 2048,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type'         => 'base64',
                            'media_type'   => $media_type,
                            'data'         => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ]),
    ]);

    if ( is_wp_error($response) ) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200) {
        return new WP_Error('vision_error', $body['error']['message'] ?? "Vision API error (HTTP {$code})");
    }

    return $body['content'][0]['text'] ?? 'No analysis received.';
}

/**
 * Compare before/after screenshots with Claude Vision
 */
function wpilot_compare_screenshots( $before_path, $after_path ) {
    $api_key = get_option( 'ca_api_key', '' );
    if ( empty($api_key) ) return new WP_Error('no_key', 'No API key.');

    if (!file_exists($before_path) || !file_exists($after_path)) {
        return new WP_Error('missing', 'Before or after screenshot missing.');
    }

    $before_b64 = base64_encode(file_get_contents($before_path));
    $after_b64  = base64_encode(file_get_contents($after_path));

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode([
            'model'      => CA_MODEL,
            'max_tokens' => 2048,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'BEFORE change:'],
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $before_b64]],
                    ['type' => 'text', 'text' => 'AFTER change:'],
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $after_b64]],
                    ['type' => 'text', 'text' => "Compare these before/after screenshots. List:\n1. What changed (improvements)\n2. What broke (regressions, visual bugs)\n3. What still needs work\n4. Improvement score: -5 (worse) to +5 (much better)\n\nOutput [ACTION:] cards for any remaining fixes needed."],
                ],
            ]],
        ]),
    ]);

    if ( is_wp_error($response) ) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200) {
        return new WP_Error('vision_error', $body['error']['message'] ?? "Vision API error");
    }

    return $body['content'][0]['text'] ?? 'No comparison received.';
}

/**
 * Clean up old screenshots (keep newest 50)
 */
function wpilot_cleanup_screenshots() {
    if (!file_exists(WPILOT_SCREENSHOT_DIR)) return;

    $files = glob(WPILOT_SCREENSHOT_DIR . '/*.png');
    if (count($files) <= 50) return;

    usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });

    $to_delete = array_slice($files, 0, count($files) - 50);
    foreach ($to_delete as $f) {
        @unlink($f);
    }
}

/**
 * Get screenshot history
 */
function wpilot_get_screenshot_history( $limit = 20 ) {
    if (!file_exists(WPILOT_SCREENSHOT_DIR)) return [];

    $files = glob(WPILOT_SCREENSHOT_DIR . '/*.png');
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });

    $history = [];
    foreach (array_slice($files, 0, $limit) as $f) {
        $history[] = [
            'filename' => basename($f),
            'url'      => WPILOT_SCREENSHOT_URL . '/' . basename($f),
            'size_kb'  => round(filesize($f) / 1024),
            'date'     => date('Y-m-d H:i:s', filemtime($f)),
        ];
    }
    return $history;
}
