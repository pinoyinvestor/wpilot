<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  BUSINESS PROFILE — The heart of every site
//
//  When a customer tells the bubble about their business,
//  we store it here. Every page, every text, every design
//  decision is informed by this profile.
//
//  "Vi heter Sneakerkliniken, vi tvättar skor. Jag älskar
//   sneakers sen barnsben" → becomes the soul of the website.
// ═══════════════════════════════════════════════════════════════

define( 'WPI_BUSINESS_PROFILE_KEY', 'wpilot_business_profile' );

// ── Save business profile ─────────────────────────────────────
function wpilot_save_business_profile( $params ) {
    $profile = get_option( WPI_BUSINESS_PROFILE_KEY, [] );

    $fields = [
        'name',           // "Sneakerkliniken"
        'tagline',        // "Vi ger dina sneakers nytt liv"
        'description',    // What they do
        'story',          // Personal story / why they started
        'founder',        // Founder name
        'location',       // City / area
        'phone',          // Phone number
        'email',          // Contact email
        'target_audience',// Who they serve
        'unique_selling', // What makes them special
        'tone',           // How they want to sound: casual, professional, playful, luxury
        'language',       // sv, en, etc.
        'industry',       // sneaker care, restaurant, fashion, etc.
        'services',       // What services/products they offer (free text or comma-separated)
        'social_instagram',
        'social_facebook',
        'social_tiktok',
        'social_linkedin',
        'website_goal',   // What they want from the website (sell, book, inform, showcase)
        'competitors',    // Who they look up to / compete with
        'keywords',       // SEO keywords they want to rank for
    ];

    $updated = false;
    foreach ( $fields as $key ) {
        if ( isset( $params[$key] ) && $params[$key] !== '' ) {
            $profile[$key] = sanitize_text_field( $params[$key] );
            $updated = true;
        }
    }

    if ( ! $updated ) {
        return wpilot_err( 'No business info provided. Tell me: name, description, story, services, target_audience, tone.' );
    }

    $profile['updated_at'] = current_time( 'mysql' );

    // Also update WordPress site name if business name provided
    if ( ! empty( $params['name'] ) ) {
        update_option( 'blogname', $params['name'] );
    }
    if ( ! empty( $params['tagline'] ) ) {
        update_option( 'blogdescription', $params['tagline'] );
    }
    if ( ! empty( $params['email'] ) ) {
        update_option( 'admin_email', $params['email'] );
    }

    update_option( WPI_BUSINESS_PROFILE_KEY, $profile, false );

    // Build summary
    $parts = [];
    if ( ! empty( $profile['name'] ) )        $parts[] = "Name: {$profile['name']}";
    if ( ! empty( $profile['industry'] ) )    $parts[] = "Industry: {$profile['industry']}";
    if ( ! empty( $profile['tone'] ) )        $parts[] = "Tone: {$profile['tone']}";
    if ( ! empty( $profile['location'] ) )    $parts[] = "Location: {$profile['location']}";
    // Built by Weblease
    if ( ! empty( $profile['services'] ) )    $parts[] = "Services: {$profile['services']}";

    return wpilot_ok(
        'Business profile saved! ' . implode( ' | ', $parts ) . '. All future content will be personalized.',
        [ 'profile' => $profile ]
    );
}

// ── Get business profile ──────────────────────────────────────
function wpilot_get_business_profile() {
    return get_option( WPI_BUSINESS_PROFILE_KEY, [] );
}

// ── Build context block for system prompt ─────────────────────
function wpilot_business_context_block() {
    $p = wpilot_get_business_profile();
    if ( empty( $p ) ) {
        return "\n\n## BIZ: not set — ask: name, what they do, their story, target audience, tone, website goal. Then save_business_profile.\n";
    }

    // Ultra-compact: pipe-delimited, no labels (AI understands context)
    $block = "\n\n## BIZ\n";
    $parts = [];
    foreach ( ['name','tagline','description','industry','tone','location','founder','services','target_audience','unique_selling','website_goal'] as $key ) {
        if ( ! empty( $p[$key] ) ) $parts[] = $p[$key];
    }
    $block .= implode( ' | ', $parts ) . "\n";
    // Story on own line (most important for content)
    if ( ! empty( $p['story'] ) ) $block .= "Story: {$p['story']}\n";
    // Socials compact
    $socials = array_filter([
        !empty($p['social_instagram']) ? "IG:{$p['social_instagram']}" : '',
        !empty($p['social_facebook']) ? "FB:{$p['social_facebook']}" : '',
        !empty($p['social_tiktok']) ? "TT:{$p['social_tiktok']}" : '',
    ]);
    if ( $socials ) $block .= implode(' ', $socials) . "\n";
    $block .= "USE their words+story in ALL content. Tone: " . ($p['tone'] ?? 'professional') . ". No generic text.\n";

    return $block;
}

// ── Generate personalized content for site recipe sections ────
// Replaces generic placeholder text with business-specific content
function wpilot_personalize_section( $section, $profile ) {
    if ( empty( $profile ) ) return $section;

    $name     = $profile['name'] ?? '';
    $story    = $profile['story'] ?? '';
    $desc     = $profile['description'] ?? '';
    $services = $profile['services'] ?? '';
    $audience = $profile['target_audience'] ?? '';
    $usp      = $profile['unique_selling'] ?? '';
    $founder  = $profile['founder'] ?? '';
    $location = $profile['location'] ?? '';
    $tone     = $profile['tone'] ?? 'professional';
    $goal     = $profile['website_goal'] ?? '';

    $type = $section['type'] ?? '';

    switch ( $type ) {
        case 'hero':
            if ( $name ) $section['heading'] = $name;
            if ( $desc ) {
                $section['subheading'] = $desc;
            } elseif ( $usp ) {
                $section['subheading'] = $usp;
            }
            // Personalize CTA based on website goal
            if ( $goal ) {
                $goal_lower = strtolower( $goal );
                if ( strpos($goal_lower, 'book') !== false || strpos($goal_lower, 'boka') !== false ) {
                    $section['cta_primary'] = 'Boka tid';
                    $section['cta_link'] = '/contact';
                } elseif ( strpos($goal_lower, 'sell') !== false || strpos($goal_lower, 'sälj') !== false || strpos($goal_lower, 'shop') !== false ) {
                    $section['cta_primary'] = 'Handla nu';
                    $section['cta_link'] = '/shop';
                }
            }
            break;

        case 'text-block':
            // If this is on an About page, use the story
            if ( $story ) {
                $section['content'] = $story;
                if ( $founder ) $section['content'] .= " — {$founder}";
            }
            break;

        case 'features':
            // If services provided, use them as feature items
            if ( $services && ( !isset($section['items']) || empty($section['items']) || ( $section['heading'] ?? '' ) === 'How We Help' ) ) {
                $service_list = array_map( 'trim', preg_split( '/[,\n;]/', $services ) );
                $icons = ['✦', '✧', '★', '◆', '●', '▸', '◉', '❖'];
                $items = [];
                foreach ( array_slice($service_list, 0, 6) as $i => $svc ) {
                    if ( ! empty($svc) ) {
                        $items[] = [
                            'icon'  => $icons[$i % count($icons)],
                            'title' => $svc,
                            'desc'  => '',
                        ];
                    }
                }
                if ( $items ) {
                    $section['items'] = $items;
                    $section['heading'] = $section['heading'] ?? 'Våra tjänster';
                }
            }
            break;

        case 'cta':
            if ( $name ) {
                $section['heading'] = $section['heading'] ?? "Välkommen till {$name}";
            }
            break;

        case 'contact-info':
            if ( ! empty($profile['email']) ) $section['email'] = $profile['email'];
            if ( ! empty($profile['phone']) ) $section['phone'] = $profile['phone'];
            if ( $location ) {
                $section['show_hours'] = true;
                $section['address'] = $location;
            }
            break;

        case 'split':
            if ( $story && ( $section['text'] ?? '' ) === '' ) {
                $section['text'] = $story;
            }
            if ( $usp && empty($section['heading']) ) {
                $section['heading'] = $usp;
            }
            break;
    }

    return $section;
}

// ── Reset business profile ────────────────────────────────────
function wpilot_reset_business_profile() {
    delete_option( WPI_BUSINESS_PROFILE_KEY );
    return wpilot_ok( 'Business profile cleared.' );
}

// ── Compact string for blueprint context ──────────────────────
function wpilot_business_profile_compact() {
    $p = wpilot_get_business_profile();
    if ( empty( $p ) ) return '';
    $parts = [];
    if ( ! empty( $p['name'] ) )     $parts[] = $p['name'];
    if ( ! empty( $p['industry'] ) ) $parts[] = $p['industry'];
    if ( ! empty( $p['tone'] ) )     $parts[] = 'tone:' . $p['tone'];
    if ( ! empty( $p['location'] ) ) $parts[] = $p['location'];
    return implode( '|', $parts );
}
