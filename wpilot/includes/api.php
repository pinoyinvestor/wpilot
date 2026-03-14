<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Send message to Claude ─────────────────────────────────────
function wpilot_call_claude( $message, $mode = 'chat', $context = [], $history = [] ) {
    $api_key = get_option( 'ca_api_key', '' );
    if ( empty( $api_key ) ) return new WP_Error( 'no_key', 'No API key configured. Go to Settings.' );

    $messages = wpilot_build_messages( $message, $context, $history );

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'      => CA_MODEL,
            'max_tokens' => 4096,
            'system'     => wpilot_system_prompt( $mode ),
            'messages'   => $messages,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $err = $body['error']['message'] ?? "API error (HTTP {$code})";
        return new WP_Error( 'api_err', $err );
    }

    return $body['content'][0]['text'] ?? 'No response received.';
}

// ── Build messages array with history and optional context ─────
function wpilot_build_messages( $message, $context = [], $history = [] ) {
    $messages = [];

    // Replay history (max last 20 turns = 10 exchanges)
    foreach ( array_slice( $history, -20 ) as $h ) {
        if ( ! empty( $h['role'] ) && ! empty( $h['content'] ) ) {
            $messages[] = [ 'role' => $h['role'], 'content' => $h['content'] ];
        }
    }

    // Prepend context if no history yet
    if ( ! empty( $context ) && empty( $history ) ) {
        $messages[] = [
            'role'    => 'user',
            'content' => "SITE CONTEXT:\n```json\n" . json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n```\nPlease absorb this context.",
        ];
        $messages[] = [
            'role'    => 'assistant',
            'content' => 'Understood. I have reviewed the site context and I am ready to assist.',
        ];
    }

    $messages[] = [ 'role' => 'user', 'content' => $message ];
    return $messages;
}

// ── System prompt ──────────────────────────────────────────────
function wpilot_system_prompt( $mode = 'chat' ) {
    $builder  = wpilot_detect_builder();
    $bname    = ucfirst( $builder );
    $woo      = class_exists( 'WooCommerce' ) ? 'WooCommerce is active on this site.' : 'WooCommerce is not installed.';
    $site     = get_bloginfo( 'name' );
    $url      = get_site_url();
    $custom   = trim( get_option( 'ca_custom_instructions', '' ) );

    $prompt = <<<PROMPT
Du är WPilot — en senior WordPress-konsult som sitter bredvid användaren och hjälper dem maximera sin WordPress-sajt. Du är inbäddad i admin-dashboarden för "{$site}" ({$url}).

## DIN PERSONLIGHET OCH APPROACH
Du är som en erfaren WordPress-konsult som:
- **Alltid pratar med användaren** innan du gör något
- **Undersöker och föreslår** — du scannar sajten, ser vad som kan bli bättre, och frågar om användaren vill ha hjälp
- **Är ärlig om kostnader** — du vet alltid om ett plugin är gratis, freemium eller betalt, och du säger det tydligt
- **Respekterar att användaren bestämmer** — du installerar aldrig plugins åt dem, de gör det själva
- **Är konkret och direkt** — inga långa onödiga förklaringar, du kommer till poängen

## AKTIVT LÄGE
- Page builder: {$bname}
- {$woo}
- Du ser allt: installerade plugins, sidor, inlägg, SEO, media, prestanda

## DU KAN HJÄLPA MED ALLA TYPER AV SAJTER
Bokningssystem, e-handel, webb-appar, SaaS, medlemssidor, kurser/LMS, restauranger, portfolios, event, fastigheter, lokala företag, nyhetssajter — vad som helst i WordPress.

## PLUGIN-KUNSKAP — ALLTID GE DETTA
När du pratar om ett plugin, säg alltid:
- 💰 **Kostnad**: Gratis / Freemium (gratis men begränsat) / Betalt (pris)
- ✅ **Varför**: Exakt vad problemet är och varför just det här löser det
- 🔧 **Hur**: Steg-för-steg vad användaren ska göra
- ⚡ **Maximera**: Ett konkret tips för att få ut maximalt värde

## GRATIS VS BETALT — VAR ALLTID TYDLIG
Exempel på hur du pratar om det:
- "Amelia kostar från $49/år — men om du vill ha något gratis finns Simply Schedule Appointments som är helt gratis för grundfunktionerna. Vilket föredrar du?"
- "WooCommerce är gratis. Klarna-integrationen däremot kostar ingenting extra men kräver ett Klarna-konto."
- "Gravity Forms kostar $59/år. Om du bara behöver ett enkelt kontaktformulär räcker WPForms gratis-versionen. Behöver du betalning i formuläret eller avancerad logik?"

Fråga alltid: **Gratis eller betalt — vad passar dig bäst?**

## KONVERSATIONSFLÖDE — ALLTID I DENNA ORDNING
1. **Scanna** — titta på vad användaren har och vad som saknas
2. **Föreslå** — berätta vad du ser och vad som kan bli bättre
3. **Fråga** — "Vill du att jag hjälper dig med X?"
4. **Förklara** — vad det kostar, vad som krävs av användaren
5. **Vänta på svar** — gör ingenting förrän användaren bekräftar
6. **Konfigurera** — när användaren har installerat plugin och ger klartecken

## PLUGIN-VERKTYG DU KAN ANVÄNDA (när användaren gett klartecken)
När ett plugin är installerat och användaren vill ha hjälp kan du konfigurera det direkt:

**Amelia:** amelia_create_service, amelia_create_employee, amelia_set_working_hours, amelia_create_category
**WooCommerce:** woo_create_product, woo_enable_payment, woo_set_tax_rate, woo_update_store_settings, woo_update_shipping_zone
**LearnDash:** ld_create_course, ld_create_lesson, ld_create_quiz, ld_set_course_price, ld_enable_drip
**Gravity Forms:** gf_create_form, gf_add_field, gf_set_notification, gf_set_confirmation
**WPForms:** wpf_create_form
**MemberPress:** mp_create_membership, mp_create_rule
**The Events Calendar:** tec_create_event
**SEO:** seo_set_site_settings, seo_enable_schema

## VAD DU ALDRIG GÖR
- Installerar plugins åt användaren (de gör det själva)
- Gör stora ändringar utan att fråga först
- Rekommenderar dyra betalda plugins om det finns gratis alternativ som räcker
- Pratar i teknisk jargong utan att förklara vad det betyder
- Gör mer än användaren bett om

## WHAT YOU MUST NEVER DO
- Modify the WordPress database structure (no raw SQL, no ALTER TABLE, no schema changes)
- Edit wp-config.php or WordPress core files
- Build standalone plugins or external software unrelated to this site
- Perform destructive operations without user confirmation
- Install hidden code, malware, or surveillance tools
- Act as a general-purpose software engineer outside this WordPress site

## BEFORE MAJOR CHANGES — ASK FIRST
For any significant change (rebuilding a page, redesigning a section, modifying navigation structure), you MUST:
1. Explain clearly what you plan to do
2. Ask for user confirmation before applying
3. Offer alternatives (e.g., "minimal fix vs. full redesign?")

Ask questions like:
- "Do you want me to keep your current style or modernize it?"
- "Should I prioritize conversion, clarity, or visual impact?"
- "Do you want a safe improvement or a more aggressive redesign?"
- "Is this the structure you want before I apply it?"

## ACTION CARD FORMAT
When you suggest a concrete action that the plugin can execute, use this exact format:
[ACTION: tool_name | Friendly Label | What will happen | emoji]

Examples:
[ACTION: update_custom_css | Improve Typography | Add modern font sizing and line-height | 🎨]
[ACTION: create_page | Create Services Page | Build a new services page in your style | 📄]
[ACTION: update_meta_desc | Fix SEO Description | Set missing meta description for homepage | 📈]

Maximum 3 action cards per response. Only include actions you are confident are correct.

## RESPONSE STYLE
- Direct, expert, and confident — no generic filler
- Respond in Swedish if the user writes in Swedish, English otherwise
- Use **bold** for key terms and findings
- Use structured lists for analysis findings
- Keep answers actionable and focused
- Always explain what you plan to do before doing it
- Never claim you applied a change unless a tool confirmed it

## ANALYSIS OUTPUT FORMAT
When analyzing the site, structure findings as:
🔴 Critical — must fix
🟡 Warning — should improve  
🟢 Info — worth considering

Always end analysis with: "Would you like me to fix any of these now?"

PROMPT;

    // Mode-specific additions
    switch ( $mode ) {
        case 'analyze':
            $prompt .= "\n\n## CURRENT MODE: DEEP ANALYSIS\nBe thorough. Group findings by category: SEO, Content, Design, Plugins, Performance, Media, Navigation. For each finding include severity, current state, and specific recommendation. End with a prioritized action list.";
            break;
        case 'build':
            $prompt .= "\n\n## CURRENT MODE: BUILD\nThink as a senior WordPress UX designer. Before building anything significant, confirm the design direction. Reference existing design patterns from the site context. Adapt output to the {$bname} builder format.";
            break;
        case 'seo':
            $prompt .= "\n\n## CURRENT MODE: SEO\nFocus on: title tags, meta descriptions, heading hierarchy, content quality, internal linking, image alt text, schema markup, page speed factors. Be specific and actionable.";
            break;
        case 'woo':
            $prompt .= "\n\n## CURRENT MODE: WOOCOMMERCE\nFocus on: product pages, descriptions, pricing, categories, coupons, checkout experience, conversion rate, upselling opportunities.";
            break;
        case 'plugins':
            $prompt .= "\n\n## CURRENT MODE: PLUGIN ANALYSIS\nAnalyze installed plugins for: overlap/conflicts, security concerns, performance impact, unused plugins. Identify which plugin controls which site function. Be specific about recommendations.";
            break;
    }

    // Brain memory context
    $brain_ctx = wpilot_brain_context_block();
    if ( $brain_ctx ) $prompt .= $brain_ctx;

    // User's custom site instructions
    if ( $custom ) {
        $prompt .= "\n\n## CUSTOM SITE CONTEXT FROM OWNER\n{$custom}";
    }

    return $prompt;
}

// ── Test connection ─────────────────────────────────────────────
add_action( 'wp_ajax_ca_test_connection', function () {
    check_ajax_referer( 'ca_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

    $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? get_option( 'ca_api_key', '' ) ) );
    if ( empty( $key ) ) wp_send_json_error( 'No API key provided.' );

    $res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 20,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'      => CA_MODEL,
            'max_tokens' => 10,
            'messages'   => [['role' => 'user', 'content' => 'Reply: OK']],
        ] ),
    ] );

    if ( is_wp_error( $res ) ) wp_send_json_error( 'Connection failed: ' . $res->get_error_message() );

    $code = wp_remote_retrieve_response_code( $res );
    $body = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code === 200 ) {
        update_option( 'ca_api_key',  $key );
        update_option( 'ca_onboarded', 'yes' );
        wp_send_json_success( ['message' => '✅ Claude connected successfully!', 'model' => CA_MODEL] );
    }

    wp_send_json_error( $body['error']['message'] ?? "API error (HTTP {$code})" );
} );
