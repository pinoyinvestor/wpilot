<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPILOT SITE KNOWLEDGE BASE
//  AI:n känner till alla webbplatstyper, bästa plugins,
//  setup-instruktioner och hur man maximerar varje typ.
// ═══════════════════════════════════════════════════════════════

function wpilot_site_knowledge() {
    return [

    // ── BOKNINGSSYSTEM ─────────────────────────────────────────
    'booking' => [
        'label'   => 'Bokningssystem',
        'icon'    => '📅',
        'desc'    => 'Frisör, klinik, PT, konsult, studio, spa',
        'plugins' => [
            ['name'=>'Amelia',           'price'=>'Fr $49/år',  'stars'=>5, 'why'=>'Bästa bokningsplugin. Personal, tjänster, betalning, SMS-påminnelser.',                  'tip'=>'Aktivera Google Calendar-synk och sätt 24h SMS-påminnelse — minskar no-shows 60%.'],
            ['name'=>'Bookly Pro',       'price'=>'$89 engång', 'stars'=>4, 'why'=>'Flexiblare än Amelia för komplexa scheman med flera platser.',                           'tip'=>'Lägg till Bookly Payments-tillägget. Betala vid bokning = ingen no-show.'],
            ['name'=>'Simply Schedule', 'price'=>'Fr gratis',  'stars'=>4, 'why'=>'Enklaste alternativet. Perfekt för konsulter med enkel kalender.',                       'tip'=>'Koppla Stripe för att ta depositionsbetalning.'],
            ['name'=>'WPForms',          'price'=>'Fr gratis',  'stars'=>5, 'why'=>'Om du inte behöver fullt bokningssystem — enkelt formulär med datum/tids-fält.',         'tip'=>'Aktivera conditional logic så formuläret anpassas efter vilken tjänst kunden väljer.'],
        ],
        'must_have' => ['Stripe eller WooCommerce Payments för betalning vid bokning','Google Calendar-synk','SMS/e-postpåminnelser'],
        'ai_task'   => 'Analysera bokningsflödet. Är det lätt att hitta? Är betalning aktiverat? Finns påminnelser? Är bokningssidan mobiloptimerad?',
    ],

    // ── E-HANDEL ───────────────────────────────────────────────
    'ecommerce' => [
        'label'   => 'E-handel',
        'icon'    => '🛍️',
        'desc'    => 'Webbutik med produkter, kundvagn, checkout',
        'plugins' => [
            ['name'=>'WooCommerce',           'price'=>'Gratis',      'stars'=>5, 'why'=>'Standard för WordPress e-handel. Komplett butik på 30 min.',                              'tip'=>'Installera Storefront-temat som är byggt för WooCommerce — laddar snabbt.'],
            ['name'=>'WooCommerce Payments',  'price'=>'2.9%+30¢',   'stars'=>5, 'why'=>'Inbyggd betalning utan extern gateway. Enklast att komma igång.',                        'tip'=>'Aktivera Klarna och Swish via WooCommerce Payments för maximalt konvertering i Sverige.'],
            ['name'=>'Yoast SEO + WooCommerce','price'=>'$69/år',    'stars'=>5, 'why'=>'Optimerar produktsidor för Google Shopping automatiskt.',                                 'tip'=>'Aktivera schema markup för produkter — ger stjärnor och pris direkt i sökresultaten.'],
            ['name'=>'CartFlows',             'price'=>'Fr $99/år',   'stars'=>5, 'why'=>'Bygger konverteringsoptimerade checkout-flöden. Upsells, order bumps, one-click.',        'tip'=>'Lägg till en order bump på checkout-sidan — snittkonvertering 15-30% extra revenue.'],
            ['name'=>'YITH WooCommerce Wishlist','price'=>'Fr gratis','stars'=>4, 'why'=>'Önskelistor ökar återköp och fungerar som gratis marknadsföring när kunder delar.',       'tip'=>'Skicka automatisk e-post när produkt i önskelistan reas.'],
            ['name'=>'Mailchimp for WooCommerce','price'=>'Gratis',   'stars'=>4, 'why'=>'Automatiska övergivna kundvagn-mail. Återvinner 15-20% av förlorade köp.',               'tip'=>'Sätt tre e-postsekvens: 1h, 24h, 72h efter övergiven kundvagn.'],
            ['name'=>'WooCommerce Product Filter','price'=>'Fr $49', 'stars'=>4, 'why'=>'Filtrerbart produktbibliotek. Kritiskt för butiker med 20+ produkter.',                  'tip'=>'Lägg filter på: pris, kategori, färg, storlek. Kunder som filtrerar konverterar 3x mer.'],
        ],
        'must_have' => ['WooCommerce','Betalgateway (Stripe/Klarna/Swish)','SEO-plugin','Abandoned cart-mail'],
        'ai_task'   => 'Analysera WooCommerce-butiken. Är checkout-flödet optimalt? Finns abandoned cart? Är produktbilder bra? Är mobilupplevelsen smidig? Vilka betalmetoder saknas?',
    ],

    // ── WEBB-APP / SAAS PÅ WORDPRESS ──────────────────────────
    'webapp' => [
        'label'   => 'Webb-app / SaaS',
        'icon'    => '⚙️',
        'desc'    => 'Verktyg, dashboards, SaaS-produkter byggda på WordPress',
        'plugins' => [
            ['name'=>'BuddyBoss Platform',    'price'=>'$228/år',     'stars'=>5, 'why'=>'Bygger kompletta app-liknande upplevelser: användarkonton, dashboards, notiser, DM.',    'tip'=>'Kombinera med LearnDash för en komplett SaaS-plattform med kurser + community + verktyg.'],
            ['name'=>'WP User Frontend',      'price'=>'Fr $49/år',   'stars'=>4, 'why'=>'Låter användare skicka in innehåll, redigera sin profil och hantera data utan wp-admin.','tip'=>'Bygg ett komplett user-facing dashboard utan en enda rad kod.'],
            ['name'=>'Gravity Forms',         'price'=>'$59/år',      'stars'=>5, 'why'=>'Kraftfulla formulär med conditional logic, beräkningar och API-kopplingar.',            'tip'=>'Använd Gravity Forms + Zapier för att koppla till externa tjänster automatiskt.'],
            ['name'=>'WPGraphQL',             'price'=>'Gratis',      'stars'=>5, 'why'=>'Exponerar WordPress-data via GraphQL API. Bygg React/Vue-frontend med WordPress som backend.','tip'=>'Kombinera med Next.js för en headless WordPress-app med blixtsnabb frontend.'],
            ['name'=>'JWT Authentication',    'price'=>'Gratis',      'stars'=>4, 'why'=>'Säker token-baserad autentisering för WordPress REST API.',                             'tip'=>'Nödvändig för headless-appar och mobilappar som använder WordPress som backend.'],
            ['name'=>'Advanced Custom Fields','price'=>'Fr gratis',   'stars'=>5, 'why'=>'Skapar anpassade datafält för alla post-typer. Grunden för alla custom data-appar.',    'tip'=>'Använd ACF + Custom Post Types för att bygga vilket dataschema som helst utan databaskod.'],
            ['name'=>'WP REST API + CORS',    'price'=>'Gratis',      'stars'=>5, 'why'=>'WordPress inbyggda REST API är komplett. Aktivera CORS för externa app-anrop.',          'tip'=>'Dokumentera ditt eget API med WP Swagger UI-plugin för proffsig developer-experience.'],
            ['name'=>'Stripe Payments',       'price'=>'2.9%+30¢',   'stars'=>5, 'why'=>'Ta betalt för SaaS-prenumerationer direkt i WordPress.',                                'tip'=>'Kombinera med MemberPress för komplett subscription-hantering med free/pro tiers.'],
        ],
        'must_have' => ['Custom Post Types','Advanced Custom Fields','User authentication','REST API eller GraphQL'],
        'ai_task'   => 'Analysera den här webb-appen. Är data-strukturen optimal? Är API:et dokumenterat? Är användarupplevelsen app-liknande eller fortfarande blogg-liknande? Hur kan WP maximeras som app-plattform?',
    ],

    // ── MEDLEMSSIDA ────────────────────────────────────────────
    'membership' => [
        'label'   => 'Medlemssida / Prenumeration',
        'icon'    => '🔐',
        'desc'    => 'Betalt innehåll, kurser, community, exklusivt material',
        'plugins' => [
            ['name'=>'MemberPress',       'price'=>'Fr $179/år',  'stars'=>5, 'why'=>'Bästa membership-plugin. Hanterar prenumerationer, åtkomstkontroll och betalning.',      'tip'=>'Skapa gratis tier → paid tier-uppgradering. Fler konverterar när de sett värdet gratis.'],
            ['name'=>'LearnDash',         'price'=>'Fr $199/år',  'stars'=>5, 'why'=>'Professionell LMS för kurser med quiz, certifikat och drip content.',                    'tip'=>'Aktivera drip content — släpp en lektion per vecka. Minskar churn och bygger vana.'],
            ['name'=>'Paid Memberships Pro','price'=>'Fr gratis', 'stars'=>4, 'why'=>'Gratis alternativ till MemberPress. Bra för enklare membership-strukturer.',             'tip'=>'Kombinera med WooCommerce för flexibla betalningsalternativ.'],
            ['name'=>'BuddyBoss',         'price'=>'$228/år',     'stars'=>5, 'why'=>'Socialt community inuti din membership-sajt: forum, grupper, direktmeddelanden.',        'tip'=>'Community är retention-motorn. Kunder som är aktiva i forumet churnar 4x mindre.'],
            ['name'=>'Sensei LMS',        'price'=>'Fr gratis',   'stars'=>4, 'why'=>'WooCommerce-integrerad LMS. Bra om du redan kör WooCommerce.',                           'tip'=>'Sälj kurser som WooCommerce-produkter — samma checkout-flöde som din butik.'],
        ],
        'must_have' => ['Membership-plugin','Betalgateway','E-postmarketing (Mailchimp/Klaviyo)','Välkomstsekvens'],
        'ai_task'   => 'Analysera membership-sajten. Är åtkomstkontroll korrekt inställd? Finns tydlig pricing-sida? Är onboarding-flödet optimalt för nya members? Är churn-prevention aktivt?',
    ],

    // ── RESTAURANG / MAT ───────────────────────────────────────
    'restaurant' => [
        'label'   => 'Restaurang / Café / Bar',
        'icon'    => '🍽️',
        'desc'    => 'Restauranger, caféer, barer, food trucks',
        'plugins' => [
            ['name'=>'Food Menu Pro',         'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Digital meny med kategorier, allergener, priser och bilder.',                            'tip'=>'Lägg alltid till bild på varje rätt. Rätter med bild beställs 3x mer.'],
            ['name'=>'WooCommerce + Food Store','price'=>'Fr $29',    'stars'=>4, 'why'=>'Komplett take-away och leveranssystem. Ingen provision till Foodora.',                   'tip'=>'Erbjud 10% rabatt för första direkt-beställning. Bygger din egna kanal.'],
            ['name'=>'OpenTable Widget',      'price'=>'Gratis',      'stars'=>4, 'why'=>'Bädda in bordbokningssystem om du redan använder OpenTable.',                           'tip'=>'Placera bokningswidget above the fold på förstasidan — inte gömd på kontaktsidan.'],
            ['name'=>'Amelia (bordbokning)',  'price'=>'Fr $49/år',   'stars'=>5, 'why'=>'Eget bordbokningssystem utan provision. Full kontroll.',                                'tip'=>'Sätt automatiska påminnelser 2h innan bokning för att minska no-shows.'],
            ['name'=>'Schema Pro',            'price'=>'$79/år',      'stars'=>5, 'why'=>'Lägger till restaurant-schema markup — visar öppettider och meny direkt i Google.',    'tip'=>'Aktivera Restaurant schema type med menu-URL, öppettider och prisintervall.'],
            ['name'=>'Smash Balloon Instagram','price'=>'Fr $49/år',  'stars'=>5, 'why'=>'Visar ditt Instagram-flöde med maträtter på sajten. Social proof som säljer.',          'tip'=>'Visa 6-9 bilder i rutnät direkt på förstasidan.'],
        ],
        'must_have' => ['Digital meny','Bordbokningssystem','Local SEO schema','Öppettider synliga på förstasidan'],
        'ai_task'   => 'Analysera restaurangsajten. Syns öppettider och adress above the fold? Finns digital meny? Är Local SEO konfigurerat? Är det enkelt att boka bord på mobil?',
    ],

    // ── PORTFOLIO / BYRÅ ───────────────────────────────────────
    'portfolio' => [
        'label'   => 'Portfolio / Byrå / Freelancer',
        'icon'    => '🎨',
        'desc'    => 'Designer, fotograf, byrå, kreativ freelancer',
        'plugins' => [
            ['name'=>'Envira Gallery',        'price'=>'Fr $29/år',   'stars'=>5, 'why'=>'Snyggaste galleri-plugin med lightbox, album och lazy loading.',                         'tip'=>'Aktivera lazy loading och WebP-konvertering för snabba sidladdningar.'],
            ['name'=>'Essential Grid',        'price'=>'$29 engång',  'stars'=>4, 'why'=>'Flexibla projekt-grid med filter efter kategori, typ och teknologi.',                   'tip'=>'Lägg till filter-knappar så besökare kan sortera bland dina projekt.'],
            ['name'=>'WPForms',               'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Professionellt kontaktformulär med filuppladdning för brief-dokument.',                 'tip'=>'Lägg till ett projektformulär med budget, tidsram och projektbeskrivning.'],
            ['name'=>'Testimonial Rotator',   'price'=>'Gratis',      'stars'=>4, 'why'=>'Visar kundomdömen i slider-format. Social proof som konverterar.',                      'tip'=>'Be varje kund om ett omdöme direkt efter leverans — konverteringsgrad är 80%+ om du frågar.'],
            ['name'=>'WP Portfolio',          'price'=>'Gratis',      'stars'=>4, 'why'=>'Custom post type för projekt med kategori, teknologi och case study.',                  'tip'=>'Skriv case studies med problem → lösning → resultat. Konverterar bättre än bildgallerier.'],
        ],
        'must_have' => ['Galleri-plugin','Kontaktformulär','Testimonials','Case studies'],
        'ai_task'   => 'Analysera portfolio-sajten. Är det tydligt vad du gör och för vem? Finns konkreta case studies? Är kontaktprocessen enkel? Syns social proof?',
    ],

    // ── FASTIGHET / MÄKLARE ────────────────────────────────────
    'realestate' => [
        'label'   => 'Fastighet / Mäklare',
        'icon'    => '🏠',
        'desc'    => 'Mäklarfirmor, hyresvärdar, fastighetsmäklare',
        'plugins' => [
            ['name'=>'WP Property',           'price'=>'Fr gratis',   'stars'=>4, 'why'=>'Komplett fastighetslista med sök, filter och kartor.',                                  'tip'=>'Lägg till Google Maps-integration för att visa fastighetens läge.'],
            ['name'=>'Essential Real Estate', 'price'=>'Fr $69',      'stars'=>5, 'why'=>'Modern fastighetsportal med avancerade sök-filter och jämförelse.',                    'tip'=>'Aktivera mortgage calculator — en av de mest populära funktionerna för bostadssökare.'],
            ['name'=>'Amelia (visningar)',    'price'=>'Fr $49/år',   'stars'=>5, 'why'=>'Boka visningar direkt på objektssidan.',                                               'tip'=>'Lägg en Boka visning-knapp direkt på varje objektssida.'],
            ['name'=>'Schema Pro',            'price'=>'$79/år',      'stars'=>5, 'why'=>'Real Estate schema markup för Google — visar pris och adress i sökresultat.',          'tip'=>'Aktivera RealEstateListing schema type.'],
        ],
        'must_have' => ['Fastighetslista-plugin','Kartintegration','Visningsbokning','Schema markup'],
        'ai_task'   => 'Analysera fastighetssajten. Är sökfunktionen intuitiv? Är objektssidorna konverteringsoptimerade? Är visningsbokning enkel?',
    ],

    // ── EVENT / KONFERENS ──────────────────────────────────────
    'events' => [
        'label'   => 'Event / Konferens / Kurs',
        'icon'    => '🎟️',
        'desc'    => 'Evenemang, konferenser, workshops, kurser',
        'plugins' => [
            ['name'=>'The Events Calendar',   'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Standard för event-hantering i WordPress. Gratis och vältestat.',                      'tip'=>'Aktivera Events Calendar Schema — visar event i Google med datum och plats.'],
            ['name'=>'WooCommerce Tickets',   'price'=>'Fr $89/år',   'stars'=>5, 'why'=>'Sälj biljetter direkt via WooCommerce. QR-kod-biljetter och check-in-app.',            'tip'=>'Aktivera early bird-priser — skapar urgency och ger dig tidig kassaflöde.'],
            ['name'=>'Gravity Forms',         'price'=>'$59/år',      'stars'=>5, 'why'=>'Anmälningsformulär med villkorsstyrd logik, betalning och bekräftelse-mail.',          'tip'=>'Använd conditional logic för att visa olika formulärfält baserat på biljetttyp.'],
            ['name'=>'Zoom Integration',      'price'=>'Fr gratis',   'stars'=>4, 'why'=>'Automatisk Zoom-länk skickas vid anmälan till online-event.',                          'tip'=>'Koppla via Zapier: ny registrering → skapa Zoom-möte → skicka länk automatiskt.'],
        ],
        'must_have' => ['Event Calendar','Biljettsystem','Bekräftelsemail','Schema markup'],
        'ai_task'   => 'Analysera event-sajten. Är det enkelt att anmäla sig? Finns biljettköp? Skickas bekräftelsemail? Visas event i Google?',
    ],

    // ── HÄLSA / KLINIK / VÅRD ─────────────────────────────────
    'health' => [
        'label'   => 'Hälsa / Klinik / Vård',
        'icon'    => '🏥',
        'desc'    => 'Tandläkare, psykolog, fysioterapeut, klinik',
        'plugins' => [
            ['name'=>'Amelia',                'price'=>'Fr $49/år',   'stars'=>5, 'why'=>'GDPR-kompatibelt bokningssystem med personalkategorier per specialitet.',              'tip'=>'Aktivera SMS-påminnelser 24h och 2h innan — kritiskt för att minska no-shows i vård.'],
            ['name'=>'WPForms HIPAA Add-on',  'price'=>'$249/år',     'stars'=>4, 'why'=>'HIPAA-kompatibla formulär för känslig patientdata (USA-marknaden).',                  'tip'=>'För Sverige: aktivera kryptering och GDPR-samtycke på alla formulär.'],
            ['name'=>'Schema Pro Medical',    'price'=>'$79/år',      'stars'=>5, 'why'=>'Medical/Physician schema markup. Visar specialitet och kontakt i Google.',             'tip'=>'Aktivera Physician eller MedicalClinic schema type.'],
            ['name'=>'Google Reviews Widget', 'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Visar Google-recensioner på sajten. Kritiskt för tillit i vård.',                     'tip'=>'Be varje patient skriva Google-recension via QR-kod i receptionen.'],
        ],
        'must_have' => ['GDPR-kompatibelt bokningssystem','Krypterade formulär','Recensioner','Medical schema'],
        'ai_task'   => 'Analysera kliniken. Är GDPR-samtycke korrekt? Är bokningsflödet enkelt på mobil? Visas specialiteter och personal tydligt? Finns patientomdömen?',
    ],

    // ── FASTIGHETSUTHYRNING / AIRBNB-STIL ─────────────────────
    'rental' => [
        'label'   => 'Uthyrning / Airbnb-stil',
        'icon'    => '🏡',
        'desc'    => 'Stuguthyrning, kontorshotell, utrustningsuthyrning',
        'plugins' => [
            ['name'=>'WP Rentals',            'price'=>'$59 engång',  'stars'=>5, 'why'=>'Komplett uthyrningssystem med kalender, priser, bokning och betalning.',              'tip'=>'Sätt dynamisk prissättning — högre pris högsäsong konfigureras direkt i pluginet.'],
            ['name'=>'Checkfront',            'price'=>'Fr $49/mo',   'stars'=>4, 'why'=>'Professionell uthyrningsplattform med eget bokningssystem och kanalhantering.',       'tip'=>'Synka med Airbnb och Booking.com via channel manager för att undvika dubbelbokningar.'],
            ['name'=>'Stripe',                'price'=>'2.9%+30¢',   'stars'=>5, 'why'=>'Ta depositionsbetalning och full betalning vid bokning.',                              'tip'=>'Aktivera deposits — t.ex. 30% vid bokning, 70% 7 dagar innan ankomst.'],
        ],
        'must_have' => ['Bokningssystem med kalender','Betalning','Tillgänglighetskalender','Automatiska bekräftelsemail'],
        'ai_task'   => 'Analysera uthyrningssajten. Är tillgänglighetskalendern uppdaterad? Är prisstrukturen tydlig? Är bokningsprocessen mobiloptimerad?',
    ],

    // ── NYHETSBREV / MEDIA ─────────────────────────────────────
    'media' => [
        'label'   => 'Media / Nyhetssajt / Blogg',
        'icon'    => '📰',
        'desc'    => 'Nyheter, magasin, blogg, podcast, YouTube-kanal',
        'plugins' => [
            ['name'=>'Rank Math SEO',         'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Bästa SEO-plugin för contenttunga sajter med fokusord per artikel.',                  'tip'=>'Aktivera Content AI för att optimera varje artikel i realtid.'],
            ['name'=>'Newsletter (by Sender)', 'price'=>'Fr gratis',  'stars'=>5, 'why'=>'Komplett nyhetsbrev-plugin inbyggt i WordPress. Upp till 2500 prenumeranter gratis.', 'tip'=>'Sätt en välkomstsekvens på 3 mail: dag 1, dag 3, dag 7.'],
            ['name'=>'Seriously Simple Podcasting','price'=>'Gratis', 'stars'=>5, 'why'=>'Publicera podcast-avsnitt i WordPress med RSS-feed till Spotify och Apple.',          'tip'=>'Lägg podcast-avsnitt med transkription — dubbel SEO-effekt.'],
            ['name'=>'Ad Inserter',           'price'=>'Gratis',      'stars'=>4, 'why'=>'Hantera Google AdSense och egna annonser utan att röra kod.',                         'tip'=>'Testa annonser mitt i artiklar — CTR är 3x högre än sidebar.'],
            ['name'=>'TablePress',            'price'=>'Gratis',      'stars'=>5, 'why'=>'Responsiva tabeller för jämförelser och datapresentationer.',                         'tip'=>'Jämförelsetabeller driver massor av organisk trafik — folk söker just dem.'],
        ],
        'must_have' => ['SEO-plugin','E-postlista','Social sharing','Schema markup för artiklar'],
        'ai_task'   => 'Analysera mediesajten. Är artiklar SEO-optimerade? Finns e-postprenumeration? Är laddningstiden bra? Används strukturerad data för artiklar?',
    ],

    // ── SAAS / PRENUMERATIONSTJÄNST ────────────────────────────
    'saas' => [
        'label'   => 'SaaS / Prenumerationstjänst',
        'icon'    => '💻',
        'desc'    => 'Mjukvarutjänster, verktyg, API-produkter med månadsbetalning',
        'plugins' => [
            ['name'=>'MemberPress',           'price'=>'Fr $179/år',  'stars'=>5, 'why'=>'Hanterar prenumerationstiers, trials, API-åtkomst och fakturering.',                  'tip'=>'Bygg Free → Starter → Pro-trappan. Freemium är bästa CAC för SaaS.'],
            ['name'=>'WooCommerce Subscriptions','price'=>'$279/år',  'stars'=>5, 'why'=>'Månads- och årsbetalning med automatisk förnyelse och dunning för misslyckade kort.',  'tip'=>'Aktivera annual discount — erbjud 2 månader gratis vid årsbetalning.'],
            ['name'=>'Stripe Billing',        'price'=>'0.5% sub',    'stars'=>5, 'why'=>'Professionell prenumerationshantering med automatisk retry och smart dunning.',       'tip'=>'Aktivera Smart Retries — räddar 30% av misslyckade betalningar automatiskt.'],
            ['name'=>'WPGraphQL',             'price'=>'Gratis',      'stars'=>5, 'why'=>'Exponerar din data via GraphQL för kunder som vill bygga på ditt API.',               'tip'=>'Dokumentera API med Swagger — proffsig developer experience attraherar tekniska kunder.'],
            ['name'=>'WP Job Manager',        'price'=>'Gratis',      'stars'=>4, 'why'=>'Om din SaaS har en marketplace-komponent — postning och listning av annonser.',       'tip'=>'Kombinera med WooCommerce för betald annonsplats.'],
            ['name'=>'Intercom / Crisp Chat', 'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Live-chatt och onboarding-flöden. Kritiskt för SaaS-konvertering.',                   'tip'=>'Sätt en automatisk trigger: om användaren inte aktiverat funktionen X efter dag 3 → skicka hjälpmeddelande.'],
        ],
        'must_have' => ['Prenumerationshantering','Stripe','Användarroller','Onboarding-flöde','Churn-prevention'],
        'ai_task'   => 'Analysera SaaS-sajten. Är pricing-sidan tydlig? Finns free trial? Är onboarding-flödet optimalt? Är churn-prevention aktivt?',
    ],

    // ── UTBILDNING / KURS ──────────────────────────────────────
    'education' => [
        'label'   => 'Utbildning / Online-kurs',
        'icon'    => '🎓',
        'desc'    => 'LMS, online-kurser, workshops, certifieringar',
        'plugins' => [
            ['name'=>'LearnDash',             'price'=>'Fr $199/år',  'stars'=>5, 'why'=>'Bästa LMS för WordPress. Quiz, certifikat, drip content, grupper.',                   'tip'=>'Aktivera drip content — en lektion per vecka håller studenter engagerade längre.'],
            ['name'=>'LifterLMS',             'price'=>'Fr gratis',   'stars'=>4, 'why'=>'Gratis alternativ till LearnDash. Bra för enklare kursstrukturer.',                    'tip'=>'Lägg till ett community-forum kopplat till kursen — ökar completion rate markant.'],
            ['name'=>'TutorLMS',              'price'=>'Fr $149/år',  'stars'=>4, 'why'=>'Modern UI med Zoom-integration och live-lektioner direkt i WordPress.',               'tip'=>'Aktivera certificates med studentens namn — delade på LinkedIn ger gratis marknadsföring.'],
            ['name'=>'MemberPress + LearnDash','price'=>'Fr $178/år', 'stars'=>5, 'why'=>'Kombinationen för att sälja kurser som prenumerationer och hantera åtkomst.',         'tip'=>'Bundle-deal: få kurser för ett fast pris — ökar ARPU dramatiskt.'],
            ['name'=>'BuddyBoss',             'price'=>'$228/år',     'stars'=>5, 'why'=>'Lägger till socialt community, grupper och direktmeddelanden till dina kurser.',      'tip'=>'Students som är aktiva i community churnar 4x mindre än isolerade studenter.'],
        ],
        'must_have' => ['LMS-plugin','Betalning','Certifikat','Community eller forum'],
        'ai_task'   => 'Analysera utbildningssajten. Är kursstrukturen tydlig? Är checkout-flödet enkelt? Finns certifikat? Hur är completion rate-design?',
    ],

    // ── LOKAL VERKSAMHET ───────────────────────────────────────
    'local' => [
        'label'   => 'Lokal verksamhet',
        'icon'    => '📍',
        'desc'    => 'Lokala butiker, hantverkare, servicefirmor',
        'plugins' => [
            ['name'=>'Rank Math Local SEO',   'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Local SEO schema markup. Visar i Google Maps och lokala sökresultat.',                'tip'=>'Fyll i NAP (Name, Address, Phone) exakt samma i plugin, Google My Business och sajten.'],
            ['name'=>'Google Reviews Widget', 'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Visar dina Google-recensioner på sajten automatiskt.',                               'tip'=>'Be varje kund lämna recension via QR-kod på kvittot eller i butiken.'],
            ['name'=>'WPForms',               'price'=>'Fr gratis',   'stars'=>5, 'why'=>'Offert- och kontaktformulär med automatisk svarsemail.',                             'tip'=>'Svara inom 1 timme på webbförfrågningar — konverteringsgraden är 7x högre än svar efter 24h.'],
            ['name'=>'Click to Chat',         'price'=>'Gratis',      'stars'=>4, 'why'=>'WhatsApp-chatknapp. Lokala kunder föredrar WhatsApp framför formulär.',              'tip'=>'Lägg knappen bottom-right med meddelande: "Hej! Hur kan vi hjälpa?"'],
            ['name'=>'Schema Pro',            'price'=>'$79/år',      'stars'=>5, 'why'=>'LocalBusiness schema. Kritiskt för att visas i Google Maps och local pack.',         'tip'=>'Aktivera öppettider, prisklass och serviceradius i schema.'],
        ],
        'must_have' => ['Local SEO schema','Google Reviews','Kontaktformulär','NAP konsistens'],
        'ai_task'   => 'Analysera den lokala verksamheten. Är Local SEO konfigurerat? Är NAP konsistent? Finns recensioner? Är mobilen optimerad? Syns öppettider tydligt?',
    ],

    ]; // end return
}

// ── Detect site type from installed plugins + content ─────────
function wpilot_detect_site_type() {
    $types = [];
    if ( class_exists('WooCommerce') )                       $types[] = 'ecommerce';
    if ( class_exists('Amelia\Core\Kernel') ||
         class_exists('BooklyLib\Kernel') )                  $types[] = 'booking';
    if ( class_exists('LearnDash_Settings_Section') ||
         class_exists('LLMS') )                              $types[] = 'education';
    if ( class_exists('MemberPress') ||
         class_exists('PMPRO_VERSION') )                     $types[] = 'membership';
    if ( function_exists('tribe_get_events') )               $types[] = 'events';
    if ( post_type_exists('property') ||
         post_type_exists('listing') )                       $types[] = 'realestate';
    return empty($types) ? ['general'] : $types;
}

// ── Build Shopify-like site context for AI ────────────────────
function wpilot_site_type_context() {
    $kb       = wpilot_site_knowledge();
    $detected = wpilot_detect_site_type();
    $context  = [];
    foreach ( $detected as $type ) {
        if ( isset($kb[$type]) ) {
            $context[$type] = [
                'label'       => $kb[$type]['label'],
                'must_have'   => $kb[$type]['must_have'],
                'ai_task'     => $kb[$type]['ai_task'],
                'plugin_names'=> array_column($kb[$type]['plugins'], 'name'),
            ];
        }
    }
    return $context;
}

// ── AJAX: get full plugin recommendations for a site type ─────
add_action('wp_ajax_wpi_site_type_plugins', function() {
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    check_ajax_referer('ca_nonce','nonce');
    $type = sanitize_text_field($_POST['type'] ?? '');
    $kb   = wpilot_site_knowledge();
    if ( isset($kb[$type]) ) {
        wp_send_json_success($kb[$type]);
    } else {
        wp_send_json_error('Unknown site type');
    }
});

// ── AJAX: AI analysis for a specific site type ────────────────
add_action('wp_ajax_wpi_analyze_site_type', function() {
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized.', 403);
    check_ajax_referer('ca_nonce','nonce');
    if (!wpilot_is_connected()) wp_send_json_error('Not connected');
    $type    = sanitize_text_field($_POST['type'] ?? '');
    $kb      = wpilot_site_knowledge();
    $prompt  = $kb[$type]['ai_task'] ?? 'Analyze this WordPress site and give recommendations.';
    $context = wpilot_build_context('analyze');
    $result  = wpilot_call_claude($prompt, 'analyze', $context, []);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
    wpilot_increment_prompts();
    wp_send_json_success(['analysis' => $result]);
});
