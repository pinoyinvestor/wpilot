<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  VERIFY AGENT — Quality check after AI builds/modifies pages
//
//  Analyzes rendered HTML for common issues:
//  - Mobile friendliness (viewport, overflow, tap targets)
//  - Heading hierarchy, broken images, color contrast
//  - Responsive design (media queries, fluid units, flex-wrap)
//  - Design profile consistency (colors, fonts)
//  - Auto-fixes critical issues via CSS
// ═══════════════════════════════════════════════════════════════

/**
 * Fetch rendered HTML for a page (or homepage if no ID given)
 */
function wpilot_verify_fetch_html( $page_id = 0 ) {
    if ( $page_id > 0 ) {
        $url = get_permalink( $page_id );
    } else {
        $url = home_url( '/' );
    }
    if ( ! $url ) return '';

    $response = wp_remote_get( $url, [
        'timeout'   => 8,
        'sslverify' => false,
        'headers'   => [ 'User-Agent' => 'WPilot-Verify/1.0' ],
    ] );

    if ( is_wp_error( $response ) ) return '';
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code >= 400 ) return '';

    return wp_remote_retrieve_body( $response );
}

// ─────────────────────────────────────────────────────────────
//  1. VERIFY BUILD — checks a page for common issues
// ─────────────────────────────────────────────────────────────

function wpilot_verify_build( $page_id = 0 ) {
    $html = wpilot_verify_fetch_html( $page_id );
    if ( empty( $html ) ) {
        return [ 'issues' => [[ 'type' => 'fetch_failed', 'severity' => 'critical', 'detail' => 'Could not fetch page HTML.' ]] ];
    }

    $issues = [];

    // --- Viewport meta ---
    if ( stripos( $html, 'name="viewport"' ) === false && stripos( $html, "name='viewport'" ) === false ) {
        $issues[] = [ 'type' => 'no_viewport', 'severity' => 'critical', 'detail' => 'Missing <meta name="viewport"> tag — page will not be mobile-friendly.' ];
    }

    // --- Horizontal overflow indicators (fixed widths > 100vw) ---
    if ( preg_match( '/width\s*:\s*(\d{4,})px/i', $html, $m ) && (int) $m[1] > 1400 ) {
        $issues[] = [ 'type' => 'horizontal_overflow', 'severity' => 'critical', 'detail' => "Fixed width {$m[1]}px detected — may cause horizontal scroll on mobile." ];
    }

    // --- Images without width/max-width ---
    if ( preg_match_all( '/<img[^>]*>/i', $html, $imgs ) ) {
        $no_size = 0;
        foreach ( $imgs[0] as $tag ) {
            $has_width = preg_match( '/(?:width|max-width)\s*[:=]/i', $tag );
            $has_class = preg_match( '/class\s*=/i', $tag );
            if ( ! $has_width && ! $has_class ) $no_size++;
        }
        if ( $no_size > 0 ) {
            $issues[] = [ 'type' => 'img_no_width', 'severity' => 'warning', 'detail' => "{$no_size} image(s) missing width/max-width — may overflow on mobile.", 'count' => $no_size ];
        }
    }

    // --- Buttons without min-height 44px (tap target) ---
    if ( preg_match_all( '/<(?:button|a[^>]*class="[^"]*btn)[^>]*style="([^"]*)"/i', $html, $btns ) ) {
        foreach ( $btns[1] as $style ) {
            if ( preg_match( '/min-height\s*:\s*(\d+)/', $style, $mh ) ) {
                if ( (int) $mh[1] < 44 ) {
                    $issues[] = [ 'type' => 'small_tap_target', 'severity' => 'warning', 'detail' => "Button has min-height {$mh[1]}px — should be at least 44px for touch." ];
                }
            }
        }
    }

    // --- Heading hierarchy (h1 → h2 → h3) ---
    if ( preg_match_all( '/<h(\d)[^>]*>/i', $html, $headings ) ) {
        $levels = array_map( 'intval', $headings[1] );
        $h1_count = count( array_filter( $levels, function( $l ) { return $l === 1; } ) );
        if ( $h1_count === 0 ) {
            $issues[] = [ 'type' => 'no_h1', 'severity' => 'warning', 'detail' => 'Page has no H1 heading — bad for SEO.' ];
        } elseif ( $h1_count > 1 ) {
            $issues[] = [ 'type' => 'multiple_h1', 'severity' => 'warning', 'detail' => "Page has {$h1_count} H1 headings — should have exactly one." ];
        }
        // Check for skipped levels
        $prev = 0;
        foreach ( $levels as $l ) {
            if ( $prev > 0 && $l > $prev + 1 ) {
                $issues[] = [ 'type' => 'heading_skip', 'severity' => 'info', 'detail' => "Heading jumps from H{$prev} to H{$l} — consider H" . ( $prev + 1 ) . " instead." ];
                break;
            }
            $prev = $l;
        }
    }

    // --- Broken images (empty src or placeholder) ---
    if ( preg_match_all( '/<img[^>]*src=["\']([^"\']*)["\'][^>]*>/i', $html, $srcs ) ) {
        foreach ( $srcs[1] as $src ) {
            $src_trimmed = trim( $src );
            if ( empty( $src_trimmed ) || $src_trimmed === '#' || $src_trimmed === 'about:blank' ) {
                $issues[] = [ 'type' => 'broken_img', 'severity' => 'critical', 'detail' => 'Image with empty/placeholder src detected.' ];
                break;
            }
        }
    }

    // --- Color contrast (basic check: light text on light bg or dark on dark) ---
    // Check inline styles for very low contrast combinations
    if ( preg_match_all( '/style="[^"]*color\s*:\s*(#[0-9a-fA-F]{3,6})[^"]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $colors ) ) {
        for ( $i = 0; $i < count( $colors[1] ); $i++ ) {
            $ratio = wpilot_verify_contrast_ratio( $colors[1][$i], $colors[2][$i] );
            if ( $ratio < 3.0 ) {
                $issues[] = [ 'type' => 'low_contrast', 'severity' => 'warning', 'detail' => "Low contrast ({$ratio}:1) between text {$colors[1][$i]} and bg {$colors[2][$i]} — WCAG requires 4.5:1.", 'ratio' => $ratio ];
            }
        }
    }
    // Also check reverse order (background before color)
    if ( preg_match_all( '/style="[^"]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})[^"]*(?<![a-z-])color\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $colors2 ) ) {
        for ( $i = 0; $i < count( $colors2[1] ); $i++ ) {
            $ratio = wpilot_verify_contrast_ratio( $colors2[2][$i], $colors2[1][$i] );
            if ( $ratio < 3.0 ) {
                $issues[] = [ 'type' => 'low_contrast', 'severity' => 'warning', 'detail' => "Low contrast ({$ratio}:1) between text {$colors2[2][$i]} and bg {$colors2[1][$i]}.", 'ratio' => $ratio ];
            }
        }
    }

    // --- Duplicate headers/footers ---
    $header_count = preg_match_all( '/<header[\s>]/i', $html );
    $footer_count = preg_match_all( '/<footer[\s>]/i', $html );
    if ( $header_count > 2 ) {
        $issues[] = [ 'type' => 'duplicate_header', 'severity' => 'warning', 'detail' => "{$header_count} <header> elements found — likely duplicate headers." ];
    }
    if ( $footer_count > 2 ) {
        $issues[] = [ 'type' => 'duplicate_footer', 'severity' => 'warning', 'detail' => "{$footer_count} <footer> elements found — likely duplicate footers." ];
    }

    // --- Padding on mobile sections (sections with no padding in inline styles) ---
    if ( preg_match_all( '/<section[^>]*style="([^"]*)"/i', $html, $sections ) ) {
        foreach ( $sections[1] as $style ) {
            if ( stripos( $style, 'padding' ) === false ) {
                $issues[] = [ 'type' => 'no_section_padding', 'severity' => 'info', 'detail' => 'Section with inline style but no padding — may look cramped on mobile.' ];
                break;
            }
        }
    }


    return [
        'page_id'  => $page_id,
        'issues'   => $issues,
        'critical' => count( array_filter( $issues, function( $i ) { return $i['severity'] === 'critical'; } ) ),
        'warnings' => count( array_filter( $issues, function( $i ) { return $i['severity'] === 'warning'; } ) ),
        'info'     => count( array_filter( $issues, function( $i ) { return $i['severity'] === 'info'; } ) ),
        'total'    => count( $issues ),
    ];
}

// ─────────────────────────────────────────────────────────────
//  2. VERIFY RESPONSIVE — checks responsive design quality
// ─────────────────────────────────────────────────────────────

function wpilot_verify_responsive( $page_id = 0 ) {
    $html = wpilot_verify_fetch_html( $page_id );
    if ( empty( $html ) ) {
        return [ 'pass' => false, 'details' => [ 'Could not fetch page HTML.' ] ];
    }

    $checks  = [];
    $passed  = 0;
    $total   = 0;

    // 1. @media queries present
    $total++;
    $has_media = preg_match( '/@media\s*\(/', $html ) || preg_match( '/@media\s*screen/', $html );
    $checks[] = [ 'check' => 'Media queries present', 'pass' => (bool) $has_media ];
    if ( $has_media ) $passed++;

    // 2. clamp() or vw units on fonts
    $total++;
    $has_fluid = preg_match( '/font-size\s*:\s*clamp\(/i', $html ) || preg_match( '/font-size\s*:\s*[\d.]+vw/i', $html );
    $checks[] = [ 'check' => 'Fluid font sizing (clamp/vw)', 'pass' => (bool) $has_fluid ];
    if ( $has_fluid ) $passed++;

    // 3. flex-wrap on columns
    $total++;
    $has_flexwrap = preg_match( '/flex-wrap\s*:\s*wrap/i', $html );
    $checks[] = [ 'check' => 'flex-wrap: wrap on layouts', 'pass' => (bool) $has_flexwrap ];
    if ( $has_flexwrap ) $passed++;

    // 4. max-width: 100% on images
    $total++;
    $has_img_max = preg_match( '/img[^{]*\{[^}]*max-width\s*:\s*100%/i', $html ) ||
                   preg_match( '/<img[^>]*style="[^"]*max-width\s*:\s*100%/i', $html ) ||
                   preg_match( '/img\s*\{[^}]*max-width/i', $html );
    $checks[] = [ 'check' => 'Images have max-width: 100%', 'pass' => (bool) $has_img_max ];
    if ( $has_img_max ) $passed++;

    // 5. No fixed widths > 400px in inline styles
    $total++;
    $has_fixed = preg_match_all( '/style="[^"]*width\s*:\s*(\d+)px/i', $html, $widths );
    $bad_fixed = false;
    if ( $has_fixed ) {
        foreach ( $widths[1] as $w ) {
            if ( (int) $w > 400 ) { $bad_fixed = true; break; }
        }
    }
    $checks[] = [ 'check' => 'No fixed widths > 400px in inline styles', 'pass' => ! $bad_fixed ];
    if ( ! $bad_fixed ) $passed++;

    $score = $total > 0 ? round( ( $passed / $total ) * 100 ) : 0;

    return [
        'page_id' => $page_id,
        'pass'    => $score >= 60,
        'score'   => $score,
        'passed'  => $passed,
        'total'   => $total,
        'checks'  => $checks,
    ];
}

// ─────────────────────────────────────────────────────────────
//  3. VERIFY DESIGN CONSISTENCY — matches design profile
// ─────────────────────────────────────────────────────────────

function wpilot_verify_design_consistency( $page_id = 0 ) {
    $profile = get_option( 'wpilot_design_profile', [] );
    if ( empty( $profile ) ) {
        return [ 'score' => 100, 'detail' => 'No design profile saved — nothing to check against.', 'mismatches' => [] ];
    }

    $html = wpilot_verify_fetch_html( $page_id );
    if ( empty( $html ) ) {
        return [ 'score' => 0, 'detail' => 'Could not fetch page HTML.', 'mismatches' => [] ];
    }

    $checks     = 0;
    $matches    = 0;
    $mismatches = [];

    // Extract all hex colors from HTML
    preg_match_all( '/#([0-9a-fA-F]{3,6})\b/', $html, $html_colors );
    $html_colors_norm = array_map( 'wpilot_verify_normalize_hex', $html_colors[0] );
    $html_colors_norm = array_unique( $html_colors_norm );

    // Check profile colors against page
    $color_keys = [ 'primary_color', 'secondary_color', 'accent_color', 'bg_color', 'text_color' ];
    foreach ( $color_keys as $key ) {
        if ( empty( $profile[ $key ] ) ) continue;
        $checks++;
        $norm = wpilot_verify_normalize_hex( $profile[ $key ] );
        if ( in_array( $norm, $html_colors_norm, true ) ) {
            $matches++;
        } else {
            $mismatches[] = [ 'type' => 'color', 'key' => $key, 'expected' => $profile[ $key ], 'detail' => "Profile color {$key} ({$profile[$key]}) not found on page." ];
        }
    }

    // Check fonts
    $font_keys = [ 'heading_font', 'body_font' ];
    foreach ( $font_keys as $key ) {
        if ( empty( $profile[ $key ] ) ) continue;
        $checks++;
        $font = $profile[ $key ];
        if ( stripos( $html, $font ) !== false ) {
            $matches++;
        } else {
            $mismatches[] = [ 'type' => 'font', 'key' => $key, 'expected' => $font, 'detail' => "Profile font {$key} ({$font}) not found on page." ];
        }
    }

    // Built by Christos Ferlachidis & Daniel Hedenberg
    // Flag hardcoded colors that don't match any profile color
    $profile_colors = [];
    foreach ( $color_keys as $key ) {
        if ( ! empty( $profile[ $key ] ) ) {
            $profile_colors[] = wpilot_verify_normalize_hex( $profile[ $key ] );
        }
    }
    // Only flag inline style colors (not all colors on page)
    if ( preg_match_all( '/style="[^"]*(?:color|background(?:-color)?)\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $inline_colors ) ) {
        $rogue = [];
        foreach ( $inline_colors[1] as $c ) {
            $norm = wpilot_verify_normalize_hex( $c );
            if ( ! in_array( $norm, $profile_colors, true ) && ! in_array( $norm, [ '#ffffff', '#000000', '#fff', '#000', '#333333', '#f5f5f5' ], true ) ) {
                $rogue[] = $c;
            }
        }
        $rogue = array_unique( $rogue );
        if ( count( $rogue ) > 0 ) {
            $list = implode( ', ', array_slice( $rogue, 0, 5 ) );
            $mismatches[] = [ 'type' => 'rogue_colors', 'count' => count( $rogue ), 'detail' => "Hardcoded colors not in profile: {$list}" ];
        }
    }

    $score = $checks > 0 ? round( ( $matches / $checks ) * 100 ) : 100;

    return [
        'page_id'    => $page_id,
        'score'      => $score,
        'checks'     => $checks,
        'matches'    => $matches,
        'mismatches' => $mismatches,
    ];
}

// ─────────────────────────────────────────────────────────────
//  4. AUTO-FIX ISSUES — generates CSS fixes for critical issues
// ─────────────────────────────────────────────────────────────

function wpilot_auto_fix_issues( $issues ) {
    if ( empty( $issues ) ) return [ 'fixed' => [], 'css' => '' ];

    $css_fixes = [];
    $fixed     = [];

    foreach ( $issues as $issue ) {
        if ( ( $issue['severity'] ?? '' ) !== 'critical' && ( $issue['severity'] ?? '' ) !== 'warning' ) continue;

        switch ( $issue['type'] ?? '' ) {
            case 'horizontal_overflow':
                $css_fixes[] = "/* Fix: prevent horizontal overflow */\nhtml, body { max-width: 100vw; overflow-x: hidden; }\n* { box-sizing: border-box; }";
                $fixed[] = 'Horizontal overflow fixed (overflow-x: hidden)';
                break;

            case 'img_no_width':
                $css_fixes[] = "/* Fix: constrain images */\nimg { max-width: 100%; height: auto; }";
                $fixed[] = 'Images constrained to max-width: 100%';
                break;

            case 'small_tap_target':
                $css_fixes[] = "/* Fix: tap target size */\nbutton, .btn, a.btn, input[type='submit'], input[type='button'] { min-height: 44px; min-width: 44px; }";
                $fixed[] = 'Button tap targets set to min 44px';
                break;

            case 'no_section_padding':
                $css_fixes[] = "/* Fix: section padding for mobile */\n@media (max-width: 768px) { section { padding-left: 16px; padding-right: 16px; } }";
                $fixed[] = 'Section padding added for mobile';
                break;

            case 'low_contrast':
                // Can't auto-fix without knowing the intended design — flag it
                $fixed[] = 'Low contrast flagged (manual fix recommended: adjust text or background color)';
                break;

            case 'duplicate_header':
                $css_fixes[] = "/* Fix: hide duplicate headers */\nheader ~ header { display: none !important; }";
                $fixed[] = 'Duplicate headers hidden';
                break;

            case 'duplicate_footer':
                $css_fixes[] = "/* Fix: hide duplicate footers */\nfooter ~ footer { display: none !important; }";
                $fixed[] = 'Duplicate footers hidden';
                break;
        }
    }

    // Apply combined CSS
    $combined_css = implode( "\n\n", array_unique( $css_fixes ) );
    if ( ! empty( $combined_css ) ) {
        $existing = wp_get_custom_css();
        $marker   = '/* WPilot Auto-Fix */';
        // Remove old auto-fix block if present
        $existing = preg_replace( '/' . preg_quote( $marker, '/' ) . '.*?' . preg_quote( '/* /WPilot Auto-Fix */', '/' ) . '/s', '', $existing );
        $new_css  = trim( $existing ) . "\n\n{$marker}\n{$combined_css}\n/* /WPilot Auto-Fix */";
        wp_update_custom_css_post( $new_css );
    }

    return [
        'fixed' => $fixed,
        'css'   => $combined_css,
        'count' => count( $fixed ),
    ];
}

// ─────────────────────────────────────────────────────────────
//  5. VERIFY AND FIX — main loop (verify → fix → re-verify)
// ─────────────────────────────────────────────────────────────

function wpilot_verify_and_fix( $page_id = 0 ) {
    $max_iterations = 3;
    $iteration      = 0;
    $all_fixes      = [];
    $final_result   = null;

    while ( $iteration < $max_iterations ) {
        $iteration++;
        $result = wpilot_verify_build( $page_id );

        // No critical/warning issues — we're done
        $actionable = array_filter( $result['issues'], function( $i ) {
            return in_array( $i['severity'], [ 'critical', 'warning' ] );
        } );

        if ( empty( $actionable ) ) {
            $final_result = $result;
            break;
        }

        // Try to fix
        $fix = wpilot_auto_fix_issues( $actionable );
        $all_fixes = array_merge( $all_fixes, $fix['fixed'] );

        // If nothing was actually fixed via CSS, stop to avoid infinite loop
        if ( empty( $fix['css'] ) ) {
            $final_result = $result;
            break;
        }

        $final_result = $result;
    }

    $responsive = wpilot_verify_responsive( $page_id );
    $design     = wpilot_verify_design_consistency( $page_id );

    return [
        'page_id'       => $page_id,
        'iterations'    => $iteration,
        'build_result'  => $final_result,
        'responsive'    => $responsive,
        'design'        => $design,
        'fixes_applied' => $all_fixes,
        'status'        => ( $final_result['critical'] ?? 0 ) === 0 ? 'pass' : 'issues_remain',
    ];
}

// ─────────────────────────────────────────────────────────────
//  6. TOOL DISPATCHER — called from tools.php
// ─────────────────────────────────────────────────────────────

function wpilot_run_verify_tools( $tool, $params = [] ) {
    switch ( $tool ) {

        case 'verify_site':
            $page_id = intval( $params['page_id'] ?? 0 );
            $scope   = $params['scope'] ?? 'current';

            if ( $scope === 'all' ) {
                // Verify all published pages
                $pages = get_posts( [
                    'post_type'   => [ 'page', 'post' ],
                    'post_status' => 'publish',
                    'numberposts' => 20,
                    'fields'      => 'ids',
                ] );
                $results = [];
                $total_issues = 0;
                foreach ( $pages as $pid ) {
                    $r = wpilot_verify_build( $pid );
                    $results[] = [
                        'page_id'  => $pid,
                        'title'    => get_the_title( $pid ),
                        'critical' => $r['critical'],
                        'warnings' => $r['warnings'],
                        'total'    => $r['total'],
                    ];
                    $total_issues += $r['total'];
                }
                $page_count = count( $pages );
                $summary = "{$page_count} pages checked — {$total_issues} total issues found.";
                return wpilot_ok( $summary, [ 'pages' => $results, 'total_issues' => $total_issues ] );
            }

            // Single page
            $build      = wpilot_verify_build( $page_id );
            $responsive = wpilot_verify_responsive( $page_id );
            $design     = wpilot_verify_design_consistency( $page_id );

            $title = $page_id > 0 ? get_the_title( $page_id ) : 'Homepage';
            $msg   = "Verified \"{$title}\": {$build['critical']} critical, {$build['warnings']} warnings, {$build['info']} info.";
            $msg  .= " Responsive: {$responsive['score']}%. Design consistency: {$design['score']}%.";

            return wpilot_ok( $msg, [
                'build'      => $build,
                'responsive' => $responsive,
                'design'     => $design,
            ] );

        case 'fix_all_issues':
            $page_id = intval( $params['page_id'] ?? 0 );
            $scope   = $params['scope'] ?? 'current';

            if ( $scope === 'all' ) {
                $pages = get_posts( [
                    'post_type'   => [ 'page', 'post' ],
                    'post_status' => 'publish',
                    'numberposts' => 20,
                    'fields'      => 'ids',
                ] );
                $all_fixes = [];
                foreach ( $pages as $pid ) {
                    $result = wpilot_verify_and_fix( $pid );
                    $all_fixes = array_merge( $all_fixes, $result['fixes_applied'] );
                }
                $all_fixes = array_unique( $all_fixes );
                $count = count( $all_fixes );
                $list  = $count > 0 ? implode( '; ', $all_fixes ) : 'No fixable issues found.';
                return wpilot_ok( "Scanned " . count( $pages ) . " pages — {$count} fixes applied: {$list}" );
            }

            // Single page
            $result = wpilot_verify_and_fix( $page_id );
            $count  = count( $result['fixes_applied'] );
            $status = $result['status'];
            $title  = $page_id > 0 ? get_the_title( $page_id ) : 'Homepage';

            if ( $count === 0 ) {
                return wpilot_ok( "Verified \"{$title}\" — no fixable issues found. Status: {$status}." );
            }

            $list = implode( '; ', $result['fixes_applied'] );
            return wpilot_ok( "Fixed {$count} issues on \"{$title}\": {$list}. Status: {$status}.", [
                'iterations'    => $result['iterations'],
                'fixes_applied' => $result['fixes_applied'],
                'responsive'    => $result['responsive'],
                'design'        => $result['design'],
            ] );

        default:
            return null;
    }
}

// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Normalize hex color to 6-digit lowercase
 */
function wpilot_verify_normalize_hex( $hex ) {
    $hex = strtolower( trim( $hex, '# ' ) );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return '#' . substr( $hex, 0, 6 );
}

/**
 * Calculate relative luminance of a hex color
 */
function wpilot_verify_luminance( $hex ) {
    $hex = ltrim( $hex, '#' );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec( substr( $hex, 0, 2 ) ) / 255;
    $g = hexdec( substr( $hex, 2, 2 ) ) / 255;
    $b = hexdec( substr( $hex, 4, 2 ) ) / 255;

    $r = $r <= 0.03928 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
    $g = $g <= 0.03928 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
    $b = $b <= 0.03928 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/**
 * Calculate WCAG contrast ratio between two hex colors
 */
function wpilot_verify_contrast_ratio( $hex1, $hex2 ) {
    $l1 = wpilot_verify_luminance( $hex1 );
    $l2 = wpilot_verify_luminance( $hex2 );
    $lighter = max( $l1, $l2 );
    $darker  = min( $l1, $l2 );
    return round( ( $lighter + 0.05 ) / ( $darker + 0.05 ), 1 );
}
