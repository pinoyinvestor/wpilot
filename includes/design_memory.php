<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  DESIGN MEMORY — Per-site design DNA persistence
//
//  Solves: AI has no memory between prompts. Each customer's
//  WordPress stores its own design profile in wp_options.
//  Every prompt reads the profile → stays consistent.
//
//  Flow:
//  1. User picks a style → AI builds it → save_design_profile
//  2. Next prompt → system prompt includes design profile
//  3. AI sees "this site uses white minimal with Playfair Display"
//  4. Every subsequent change stays consistent
// ═══════════════════════════════════════════════════════════════

define( 'WPI_DESIGN_PROFILE_KEY', 'wpilot_design_profile' );

// ── Save design profile (called by AI tool or auto-detect) ────
function wpilot_save_design_profile( $params ) {
    $profile = get_option( WPI_DESIGN_PROFILE_KEY, [] );

    // Merge new values into existing profile (don't overwrite unrelated fields)
    $allowed_keys = [
        'style',           // e.g. "minimalist", "luxury dark", "colorful playful", "corporate"
        'primary_color',   // e.g. "#1a1a2e"
        'secondary_color', // e.g. "#e94560"
        'accent_color',    // e.g. "#0f3460"
        'bg_color',        // e.g. "#ffffff"
        'text_color',      // e.g. "#333333"
        'heading_font',    // e.g. "Playfair Display"
        'body_font',       // e.g. "Inter"
        'border_radius',   // e.g. "8px" or "rounded" or "sharp"
        'button_style',    // e.g. "rounded gradient", "flat solid", "outline"
        'spacing',         // e.g. "airy", "compact", "normal"
        'mood',            // e.g. "elegant", "bold", "playful", "professional"
        'dark_mode',       // e.g. true/false
        'gradient',        // e.g. "linear-gradient(135deg, #667eea, #764ba2)"
        'shadow_style',    // e.g. "soft", "hard", "none"
        'notes',           // free text from AI about design decisions
    ];

    $updated = false;
    foreach ( $allowed_keys as $key ) {
        if ( isset( $params[$key] ) && $params[$key] !== '' ) {
            $profile[$key] = sanitize_text_field( $params[$key] );
            $updated = true;
        }
    }

    if ( ! $updated ) {
        return wpilot_err( 'No design values provided. Use keys like: style, primary_color, heading_font, mood, etc.' );
    }

    $profile['updated_at'] = current_time( 'mysql' );
    $profile['version']    = ( $profile['version'] ?? 0 ) + 1;

    update_option( WPI_DESIGN_PROFILE_KEY, $profile, false );

    // Also store in Brain preferences for cross-reference
    if ( function_exists( 'wpilot_brain_learn_preference' ) ) {
        if ( ! empty( $params['style'] ) ) wpilot_brain_learn_preference( 'design_style', $params['style'] );
        if ( ! empty( $params['primary_color'] ) ) wpilot_brain_learn_preference( 'primary_color', $params['primary_color'] );
        if ( ! empty( $params['heading_font'] ) ) wpilot_brain_learn_preference( 'heading_font', $params['heading_font'] );
    }

    // Build readable summary
    $summary_parts = [];
    if ( ! empty( $profile['style'] ) )          $summary_parts[] = "Style: {$profile['style']}";
    if ( ! empty( $profile['primary_color'] ) )   $summary_parts[] = "Primary: {$profile['primary_color']}";
    if ( ! empty( $profile['secondary_color'] ) ) $summary_parts[] = "Secondary: {$profile['secondary_color']}";
    if ( ! empty( $profile['heading_font'] ) )    $summary_parts[] = "Headings: {$profile['heading_font']}";
    if ( ! empty( $profile['body_font'] ) )       $summary_parts[] = "Body: {$profile['body_font']}";
    if ( ! empty( $profile['mood'] ) )            $summary_parts[] = "Mood: {$profile['mood']}";

    return wpilot_ok(
        'Design profile saved (v' . $profile['version'] . '). ' . implode( ', ', $summary_parts ),
        [ 'profile' => $profile ]
    );
}

// ── Get current design profile ────────────────────────────────
function wpilot_get_design_profile() {
    return get_option( WPI_DESIGN_PROFILE_KEY, [] );
}

// ── Build design context block for system prompt ──────────────
// Built by Christos Ferlachidis & Daniel Hedenberg
function wpilot_design_context_block() {
    $profile = wpilot_get_design_profile();
    if ( empty( $profile ) ) {
        return "\n\n## DESIGN: not set — after any design change call save_design_profile\n";
    }

    // Ultra-compact design profile — one line per category
    $block = "\n\n## DESIGN (follow exactly, use var() in CSS)\n";
    $colors = array_filter([
        !empty($profile['primary_color']) ? "pri:{$profile['primary_color']}" : '',
        !empty($profile['secondary_color']) ? "sec:{$profile['secondary_color']}" : '',
        !empty($profile['accent_color']) ? "acc:{$profile['accent_color']}" : '',
        !empty($profile['bg_color']) ? "bg:{$profile['bg_color']}" : '',
        !empty($profile['text_color']) ? "txt:{$profile['text_color']}" : '',
    ]);
    if ($colors) $block .= implode(' ', $colors) . "\n";
    $typo = array_filter([
        !empty($profile['heading_font']) ? "H:{$profile['heading_font']}" : '',
        !empty($profile['body_font']) ? "B:{$profile['body_font']}" : '',
        !empty($profile['border_radius']) ? "r:{$profile['border_radius']}" : '',
    ]);
    if ($typo) $block .= implode(' ', $typo) . "\n";
    $meta = array_filter([
        !empty($profile['style']) ? $profile['style'] : '',
        !empty($profile['mood']) ? $profile['mood'] : '',
        !empty($profile['dark_mode']) && $profile['dark_mode'] === 'true' ? 'DARK' : '',
    ]);
    if ($meta) $block .= implode(' | ', $meta) . "\n";

    return $block;
}

// ── Auto-extract design tokens from CSS ───────────────────────
// Called after update_custom_css or append_custom_css to detect
// design choices and auto-save to profile.
function wpilot_auto_detect_design_from_css( $css ) {
    $detected = [];

    // Extract colors (hex)
    preg_match_all( '/#([0-9a-fA-F]{3,8})\b/', $css, $colors );
    $color_counts = array_count_values( $colors[0] ?? [] );
    arsort( $color_counts );
    $top_colors = array_slice( array_keys( $color_counts ), 0, 5 );

    // Try to identify primary/secondary/bg from usage context
    if ( preg_match( '/(?:background|bg)(?:-color)?:\s*(#[0-9a-fA-F]{3,8})/', $css, $m ) ) {
        $detected['bg_color'] = $m[1];
    }
    if ( preg_match( '/(?:^|\n|;)\s*color:\s*(#[0-9a-fA-F]{3,8})/', $css, $m ) ) {
        $detected['text_color'] = $m[1];
    }

    // Extract fonts
    if ( preg_match( '/font-family:\s*["\']?([^"\';\},]+)/', $css, $m ) ) {
        $font = trim( $m[1] );
        if ( ! in_array( strtolower( $font ), [ 'inherit', 'sans-serif', 'serif', 'monospace', 'system-ui' ] ) ) {
            $detected['body_font'] = $font;
        }
    }

    // Detect dark mode from background
    if ( ! empty( $detected['bg_color'] ) ) {
        $hex = ltrim( $detected['bg_color'], '#' );
        if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $brightness = ( $r * 299 + $g * 587 + $b * 114 ) / 1000;
        if ( $brightness < 80 ) $detected['dark_mode'] = 'true';
    }

    // Detect border-radius
    if ( preg_match( '/border-radius:\s*(\d+(?:px|rem|em|%))/', $css, $m ) ) {
        $detected['border_radius'] = $m[1];
    }

    // Detect gradients
    if ( preg_match( '/(linear-gradient\([^)]+\))/', $css, $m ) ) {
        $detected['gradient'] = $m[1];
    }

    // Only save if we detected meaningful tokens
    if ( count( $detected ) >= 2 ) {
        $profile = wpilot_get_design_profile();
        // Don't overwrite manually set values with auto-detected ones
        foreach ( $detected as $key => $val ) {
            if ( empty( $profile[$key] ) ) {
                $profile[$key] = $val;
            }
        }
        $profile['auto_detected'] = true;
        $profile['updated_at']    = current_time( 'mysql' );
        $profile['version']       = ( $profile['version'] ?? 0 ) + 1;
        update_option( WPI_DESIGN_PROFILE_KEY, $profile, false );
    }

    return $detected;
}

// ── Reset design profile ──────────────────────────────────────
function wpilot_reset_design_profile() {
    delete_option( WPI_DESIGN_PROFILE_KEY );
    return wpilot_ok( 'Design profile reset. The AI will ask for design preferences on next interaction.' );
}

// ── Get design profile as compact string (for blueprint) ──────
function wpilot_design_profile_compact() {
    $p = wpilot_get_design_profile();
    if ( empty( $p ) ) return '';

    $parts = [];
    if ( ! empty( $p['style'] ) )          $parts[] = $p['style'];
    if ( ! empty( $p['primary_color'] ) )   $parts[] = 'pri:' . $p['primary_color'];
    if ( ! empty( $p['secondary_color'] ) ) $parts[] = 'sec:' . $p['secondary_color'];
    if ( ! empty( $p['bg_color'] ) )        $parts[] = 'bg:' . $p['bg_color'];
    if ( ! empty( $p['heading_font'] ) )    $parts[] = 'hf:' . $p['heading_font'];
    if ( ! empty( $p['body_font'] ) )       $parts[] = 'bf:' . $p['body_font'];
    if ( ! empty( $p['mood'] ) )            $parts[] = $p['mood'];

    return implode( '|', $parts );
}
