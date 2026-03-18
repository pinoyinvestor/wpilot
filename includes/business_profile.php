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
    // Built by Christos Ferlachidis & Daniel Hedenberg
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
        return "\n\n## BUSINESS PROFILE: NOT SET\n"
            . "No business profile exists. On the FIRST interaction, ask the customer:\n"
            . "1. What is your business name?\n"
            . "2. What do you do? (products/services)\n"
            . "3. What is your story? Why did you start?\n"
            . "4. Who are your customers? (target audience)\n"
            . "5. What tone do you want? (casual, professional, playful, luxury)\n"
            . "6. What is the goal of your website? (sell, book appointments, showcase work)\n"
            . "Then call save_business_profile with all the answers.\n"
            . "After saving, suggest a blueprint and offer to build the complete site.\n";
    }

    $block = "\n\n## BUSINESS PROFILE — WHO THIS CUSTOMER IS\n";
    $block .= "**Use this information in ALL content you write. Make it personal, not generic.**\n\n";

    $labels = [
        'name'            => 'Business Name',
        'tagline'         => 'Tagline',
        'description'     => 'What They Do',
        'story'           => 'Their Story',
        'founder'         => 'Founder',
        'location'        => 'Location',
        'target_audience' => 'Target Audience',
        'unique_selling'  => 'What Makes Them Special',
        'tone'            => 'Brand Tone',
        'industry'        => 'Industry',
        'services'        => 'Services/Products',
        'website_goal'    => 'Website Goal',
        'competitors'     => 'Inspiration/Competitors',
        'keywords'        => 'SEO Keywords',
    ];

    foreach ( $labels as $key => $label ) {
        if ( ! empty( $p[$key] ) ) {
            $block .= "- **{$label}**: {$p[$key]}\n";
        }
    }

    // Social links
    $socials = [];
    if ( ! empty( $p['social_instagram'] ) ) $socials[] = "IG: {$p['social_instagram']}";
    if ( ! empty( $p['social_facebook'] ) )  $socials[] = "FB: {$p['social_facebook']}";
    if ( ! empty( $p['social_tiktok'] ) )    $socials[] = "TT: {$p['social_tiktok']}";
    if ( ! empty( $p['social_linkedin'] ) )  $socials[] = "LI: {$p['social_linkedin']}";
    if ( $socials ) $block .= "- **Social**: " . implode( ', ', $socials ) . "\n";

    $block .= "\n**CONTENT RULES**: When writing ANY text (hero, about, services, CTA), use the customer's own words and story. ";
    $block .= "Match their tone ({$p['tone']}). Reference their unique selling points. ";
    $block .= "Don't use generic phrases like 'We are a leading company' — be specific to THEIR business.\n";

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
