<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  SITE RECIPES — Complete site generation from one command
//
//  Blueprint = design DNA (colors, fonts, CSS)
//  Recipe = full site (pages, sections, WooCommerce, menus)
//
//  "Build me a premium clothing store" → recipe picks blueprint
//  + generates homepage, about, contact, shop layout, footer,
//  WooCommerce config, menus — everything.
//
//  Each recipe = JSON instructions the AI executes step by step
// ═══════════════════════════════════════════════════════════════
// Built by Christos Ferlachidis & Daniel Hedenberg

// ── Available site recipes ────────────────────────────────────
function wpilot_get_site_recipes() {
    return [

        'premium-fashion' => [
            'name'           => 'Premium Fashion Store',
            'description'    => 'Lyxig klädbutik inspirerad av Apple/Zara — minimalistisk, bilddriven, premium känsla',
            'keywords'       => ['fashion','clothing','clothes','apparel','boutique','luxury','apple','zara','h&m','kläder','mode','butik','klädbutik'],
            'blueprint'      => 'dark-luxury',
            'header_style'   => 'transparent',
            'footer_style'   => 'columns',
            'woo_required'   => true,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'Premium {business_type} — curated for the modern aesthetic', 'cta_primary' => 'Shop Now', 'cta_link' => '/shop', 'cta_secondary' => 'Our Story', 'cta_link2' => '/about', 'style' => 'fullscreen dark overlay'],
                        ['type' => 'products', 'heading' => 'New Arrivals', 'count' => 4, 'style' => 'grid clean'],
                        ['type' => 'split',    'heading' => 'Crafted with Purpose', 'text' => 'Every piece is selected for quality, sustainability, and timeless design. We believe fashion should be intentional.', 'image' => 'lifestyle', 'style' => 'image-left'],
                        ['type' => 'products', 'heading' => 'Best Sellers', 'count' => 4, 'orderby' => 'popularity', 'style' => 'grid clean'],
                        ['type' => 'cta',      'heading' => 'Join the Movement', 'text' => 'Be the first to know about new drops and exclusive offers.', 'cta' => 'Subscribe', 'style' => 'dark gradient'],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About Us',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Story', 'subheading' => 'Where quality meets conscious design'],
                        ['type' => 'text-block', 'content' => 'Founded with a simple mission: to create clothing that speaks through quality, not logos. Every fabric is hand-selected, every stitch intentional.'],
                        ['type' => 'features', 'items' => [
                            ['icon' => '✦', 'title' => 'Sustainable Materials', 'desc' => 'Organic cotton, recycled fibers, responsible sourcing'],
                            ['icon' => '✦', 'title' => 'Timeless Design', 'desc' => 'Pieces that transcend seasons and trends'],
                            ['icon' => '✦', 'title' => 'Fair Production', 'desc' => 'Ethically manufactured in certified facilities'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Get in Touch', 'subheading' => 'We would love to hear from you'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'minimal'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Shop',    'url' => '/shop'],
                ['title' => 'New In',  'url' => '/shop?orderby=date'],
                ['title' => 'About',   'url' => '/about'],
                ['title' => 'Contact', 'url' => '/contact'],
            ],
            'woo_config' => [
                'currency'       => 'auto',
                'guest_checkout' => true,
                'catalog_style'  => 'grid-3',
            ],
        ],

        'restaurant-bar' => [
            'name'           => 'Restaurant & Bar',
            'description'    => 'Varm restaurangsajt med meny, bokning, öppettider — mörk och inbjudande',
            'keywords'       => ['restaurant','bar','bistro','cafe','pizza','sushi','food','dining','menu','restaurang','krog','mat','café','pizzeria'],
            'blueprint'      => 'restaurant',
            'header_style'   => 'glass',
            'footer_style'   => 'rich',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'An unforgettable dining experience', 'cta_primary' => 'View Menu', 'cta_link' => '/menu', 'cta_secondary' => 'Reserve a Table', 'cta_link2' => '/contact', 'style' => 'fullscreen dark overlay'],
                        ['type' => 'split',    'heading' => 'Our Philosophy', 'text' => 'Fresh, local ingredients transformed into extraordinary dishes. Every plate tells a story of tradition and innovation.', 'image' => 'food', 'style' => 'image-right'],
                        ['type' => 'features', 'items' => [
                            ['icon' => '🍽', 'title' => 'Fresh Ingredients', 'desc' => 'Locally sourced, seasonal produce'],
                            ['icon' => '👨‍🍳', 'title' => 'Expert Chefs', 'desc' => 'Passionate about every detail'],
                            ['icon' => '🍷', 'title' => 'Curated Wines', 'desc' => 'Hand-picked selection from around the world'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Reserve Your Table', 'text' => 'Join us for an evening to remember.', 'cta' => 'Book Now', 'style' => 'warm'],
                    ],
                ],
                [
                    'slug'     => 'menu',
                    'title'    => 'Menu',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Menu', 'subheading' => 'Seasonal flavors, timeless techniques'],
                        ['type' => 'menu-section', 'category' => 'Starters', 'items' => [
                            ['name' => 'Bruschetta', 'desc' => 'Grilled bread, tomato, basil, olive oil', 'price' => '95'],
                            ['name' => 'Caesar Salad', 'desc' => 'Romaine, parmesan, croutons, anchovy dressing', 'price' => '125'],
                            ['name' => 'Soup of the Day', 'desc' => 'Ask your server for today\'s selection', 'price' => '85'],
                        ]],
                        ['type' => 'menu-section', 'category' => 'Main Courses', 'items' => [
                            ['name' => 'Grilled Salmon', 'desc' => 'Atlantic salmon, asparagus, lemon butter', 'price' => '265'],
                            ['name' => 'Beef Tenderloin', 'desc' => 'Aged beef, truffle mash, red wine jus', 'price' => '325'],
                            ['name' => 'Pasta Primavera', 'desc' => 'Fresh pasta, seasonal vegetables, pesto', 'price' => '195'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Story'],
                        ['type' => 'text-block', 'content' => 'Since opening our doors, we have been dedicated to creating memorable dining experiences. Our kitchen is driven by respect for ingredients and a love for bringing people together.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact & Reservations',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Visit Us'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'show_hours' => true, 'hours' => 'Mon-Thu: 11-22 | Fri-Sat: 11-23 | Sun: 12-21', 'style' => 'warm'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Menu',    'url' => '/menu'],
                ['title' => 'About',   'url' => '/about'],
                ['title' => 'Contact', 'url' => '/contact'],
            ],
        ],

        'tech-startup' => [
            'name'           => 'Tech Startup / SaaS',
            'description'    => 'Modern tech-sajt med pricing, features, CTA — mörk gradient, bold',
            'keywords'       => ['tech','startup','saas','app','software','digital','product','platform','api','teknik','startup','mjukvara','plattform'],
            'blueprint'      => 'bold-modern',
            'header_style'   => 'glass',
            'footer_style'   => 'minimal',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => 'The Future of {business_type}', 'subheading' => 'Powerful, simple, built for teams that move fast.', 'cta_primary' => 'Get Started Free', 'cta_link' => '/pricing', 'cta_secondary' => 'See How It Works', 'cta_link2' => '/about', 'style' => 'gradient bold'],
                        ['type' => 'features', 'heading' => 'Why Teams Choose Us', 'items' => [
                            ['icon' => '⚡', 'title' => 'Lightning Fast', 'desc' => 'Sub-second response times, globally distributed'],
                            ['icon' => '🔒', 'title' => 'Enterprise Security', 'desc' => 'SOC2 compliant, end-to-end encryption'],
                            ['icon' => '🔌', 'title' => 'Integrations', 'desc' => 'Connect with 100+ tools your team already uses'],
                            ['icon' => '📊', 'title' => 'Analytics', 'desc' => 'Real-time dashboards and custom reports'],
                        ]],
                        ['type' => 'stats', 'items' => [
                            ['number' => '10K+', 'label' => 'Active Users'],
                            ['number' => '99.9%', 'label' => 'Uptime'],
                            ['number' => '150+', 'label' => 'Countries'],
                        ]],
                        ['type' => 'testimonials', 'items' => [
                            ['quote' => 'This completely transformed how our team works. We shipped 3x faster in the first month.', 'author' => 'Sarah K., CTO at TechCorp'],
                            ['quote' => 'Finally a tool that just works. No bloat, no complexity, just results.', 'author' => 'Marcus L., Head of Product'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Ready to Get Started?', 'text' => 'Free 14-day trial. No credit card required.', 'cta' => 'Start Free Trial', 'style' => 'gradient bold'],
                    ],
                ],
                [
                    'slug'     => 'pricing',
                    'title'    => 'Pricing',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Simple, Transparent Pricing', 'subheading' => 'No hidden fees. Cancel anytime.'],
                        ['type' => 'pricing', 'plans' => [
                            ['name' => 'Starter', 'price' => 'Free', 'features' => ['Up to 3 users', '1 project', 'Basic analytics', 'Community support'], 'cta' => 'Get Started'],
                            ['name' => 'Pro', 'price' => '$29/mo', 'features' => ['Unlimited users', 'Unlimited projects', 'Advanced analytics', 'Priority support', 'API access'], 'cta' => 'Start Free Trial', 'featured' => true],
                            ['name' => 'Enterprise', 'price' => 'Custom', 'features' => ['Everything in Pro', 'SSO & SAML', 'Dedicated account manager', 'Custom integrations', 'SLA guarantee'], 'cta' => 'Contact Sales'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Built by Developers, for Developers'],
                        ['type' => 'text-block', 'content' => 'We started this company because we were tired of tools that promised simplicity but delivered complexity. Our mission is to build software that gets out of your way.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Talk to Us'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'modern'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Features', 'url' => '/#features'],
                ['title' => 'Pricing',  'url' => '/pricing'],
                ['title' => 'About',    'url' => '/about'],
                ['title' => 'Contact',  'url' => '/contact'],
            ],
        ],

        'consulting-agency' => [
            'name'           => 'Consulting / Agency',
            'description'    => 'Professionell konsultsajt med tjänster, team, case studies',
            'keywords'       => ['consulting','agency','b2b','services','professional','firm','bureau','byrå','konsult','tjänster','företag','rådgivning'],
            'blueprint'      => 'corporate-pro',
            'header_style'   => 'modern',
            'footer_style'   => 'columns',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'Strategic {business_type} that drives results', 'cta_primary' => 'Our Services', 'cta_link' => '/services', 'cta_secondary' => 'Get in Touch', 'cta_link2' => '/contact', 'style' => 'professional'],
                        ['type' => 'features', 'heading' => 'How We Help', 'items' => [
                            ['icon' => '📈', 'title' => 'Strategy', 'desc' => 'Data-driven strategies that align with your business goals'],
                            ['icon' => '🎯', 'title' => 'Execution', 'desc' => 'From planning to implementation — we deliver results'],
                            ['icon' => '🤝', 'title' => 'Partnership', 'desc' => 'We work as an extension of your team'],
                        ]],
                        ['type' => 'stats', 'items' => [
                            ['number' => '200+', 'label' => 'Projects Delivered'],
                            ['number' => '50+', 'label' => 'Happy Clients'],
                            ['number' => '15+', 'label' => 'Years Experience'],
                        ]],
                        ['type' => 'testimonials', 'items' => [
                            ['quote' => 'Working with this team transformed our digital presence. Revenue increased 40% in 6 months.', 'author' => 'Anna S., CEO'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Let\'s Build Something Great', 'text' => 'Book a free consultation call.', 'cta' => 'Schedule a Call'],
                    ],
                ],
                [
                    'slug'     => 'services',
                    'title'    => 'Services',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Services', 'subheading' => 'Tailored solutions for your business'],
                        ['type' => 'features', 'columns' => 2, 'items' => [
                            ['icon' => '💡', 'title' => 'Strategic Consulting', 'desc' => 'Business analysis, market research, growth strategies, competitive positioning'],
                            ['icon' => '🖥', 'title' => 'Digital Transformation', 'desc' => 'Technology roadmaps, process automation, system integration'],
                            ['icon' => '📊', 'title' => 'Marketing & Growth', 'desc' => 'SEO, content strategy, paid advertising, conversion optimization'],
                            ['icon' => '🛡', 'title' => 'Operations', 'desc' => 'Process improvement, team optimization, quality management'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About Us'],
                        ['type' => 'text-block', 'content' => 'We are a team of experienced consultants dedicated to helping businesses grow. With decades of combined experience, we bring strategic thinking and hands-on execution to every engagement.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Contact Us'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'professional'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Services', 'url' => '/services'],
                ['title' => 'About',    'url' => '/about'],
                ['title' => 'Contact',  'url' => '/contact'],
            ],
        ],

        'wellness-spa' => [
            'name'           => 'Wellness & Spa',
            'description'    => 'Lugn och harmonisk sajt — bokning, tjänster, priser',
            'keywords'       => ['spa','wellness','yoga','massage','beauty','salon','therapy','hälsa','skönhet','salong','massage','terapi','yoga'],
            'blueprint'      => 'warm-organic',
            'header_style'   => 'minimal',
            'footer_style'   => 'centered',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'Find your balance. Restore your energy.', 'cta_primary' => 'Book a Session', 'cta_link' => '/contact', 'cta_secondary' => 'Our Treatments', 'cta_link2' => '/services', 'style' => 'serene'],
                        ['type' => 'features', 'heading' => 'Our Treatments', 'items' => [
                            ['icon' => '🧘', 'title' => 'Yoga & Meditation', 'desc' => 'Group classes and private sessions for all levels'],
                            ['icon' => '💆', 'title' => 'Massage Therapy', 'desc' => 'Deep tissue, Swedish, hot stone, and aromatherapy'],
                            ['icon' => '✨', 'title' => 'Facial Treatments', 'desc' => 'Customized skincare with organic products'],
                        ]],
                        ['type' => 'split',    'heading' => 'A Space for Healing', 'text' => 'Our sanctuary is designed to help you disconnect from the noise and reconnect with yourself. Every detail is curated for your wellbeing.', 'image' => 'spa', 'style' => 'image-right'],
                        ['type' => 'cta',      'heading' => 'Your Journey Starts Here', 'text' => 'Book your first session and discover a new you.', 'cta' => 'Book Now', 'style' => 'warm organic'],
                    ],
                ],
                [
                    'slug'     => 'services',
                    'title'    => 'Treatments & Prices',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Treatments'],
                        ['type' => 'price-list', 'items' => [
                            ['name' => 'Swedish Massage (60 min)', 'price' => '895 kr'],
                            ['name' => 'Deep Tissue Massage (60 min)', 'price' => '995 kr'],
                            ['name' => 'Hot Stone Massage (75 min)', 'price' => '1 195 kr'],
                            ['name' => 'Facial Treatment (45 min)', 'price' => '795 kr'],
                            ['name' => 'Yoga Class (drop-in)', 'price' => '195 kr'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Book & Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Book Your Visit'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'show_hours' => true, 'hours' => 'Mon-Fri: 9-20 | Sat: 10-18 | Sun: Closed', 'style' => 'warm'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Treatments', 'url' => '/services'],
                ['title' => 'About',      'url' => '/about'],
                ['title' => 'Book Now',    'url' => '/contact'],
            ],
        ],

        'ecommerce-general' => [
            'name'           => 'General E-commerce',
            'description'    => 'Skandinavisk webshop — ren, funktionell, perfekt för alla typer av produkter',
            'keywords'       => ['shop','store','ecommerce','e-commerce','webshop','products','sell','online','butik','handla','produkter','sälja','webbutik'],
            'blueprint'      => 'scandinavian',
            'header_style'   => 'modern',
            'footer_style'   => 'columns',
            'woo_required'   => true,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => 'Welcome to {site_name}', 'subheading' => 'Quality products, thoughtfully curated', 'cta_primary' => 'Shop All', 'cta_link' => '/shop', 'style' => 'clean minimal'],
                        ['type' => 'products', 'heading' => 'Featured Products', 'count' => 8, 'style' => 'grid-4 clean'],
                        ['type' => 'split',    'heading' => 'Why Shop With Us', 'text' => 'Free shipping on orders over 499 kr. Easy 30-day returns. Secure payments.', 'style' => 'icons-row'],
                        ['type' => 'products', 'heading' => 'On Sale', 'count' => 4, 'on_sale' => true],
                        ['type' => 'cta',      'heading' => 'Stay Updated', 'text' => 'Get exclusive deals and new arrivals straight to your inbox.', 'cta' => 'Subscribe'],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About Us',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About {site_name}'],
                        ['type' => 'text-block', 'content' => 'We are passionate about bringing you products that combine quality, design, and value. Every item in our store is carefully selected to meet our high standards.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Contact Us'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'clean'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Shop',    'url' => '/shop'],
                ['title' => 'About',   'url' => '/about'],
                ['title' => 'Contact', 'url' => '/contact'],
            ],
            'woo_config' => [
                'currency'       => 'auto',
                'guest_checkout' => true,
                'catalog_style'  => 'grid-4',
            ],
        ],

        'portfolio-photographer' => [
            'name'           => 'Portfolio / Photographer',
            'description'    => 'Bilddriven portfolio med galleri, lightbox, om-sida och kontakt — minimalistisk och kreativ',
            'keywords'       => ['portfolio','photographer','photo','gallery','creative','artist','designer','fotograf','konstnär','kreativ','galleri','bilder'],
            'blueprint'      => 'white-minimal',
            'header_style'   => 'minimal',
            'footer_style'   => 'centered',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'Capturing moments that last forever', 'cta_primary' => 'View Portfolio', 'cta_link' => '/portfolio', 'cta_secondary' => 'About Me', 'cta_link2' => '/about', 'style' => 'fullscreen minimal'],
                        ['type' => 'features', 'heading' => 'Selected Work', 'items' => [
                            ['icon' => '📸', 'title' => 'Portraits', 'desc' => 'Natural light, authentic expressions, timeless results'],
                            ['icon' => '🏔', 'title' => 'Landscape', 'desc' => 'Wide vistas and intimate details from around the world'],
                            ['icon' => '💍', 'title' => 'Weddings', 'desc' => 'Your love story told through honest, beautiful imagery'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Let\'s Create Something Beautiful', 'text' => 'Available for commissions, collaborations, and events.', 'cta' => 'Get in Touch', 'style' => 'minimal'],
                    ],
                ],
                [
                    'slug'     => 'portfolio',
                    'title'    => 'Portfolio',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Portfolio', 'subheading' => 'A selection of recent work'],
                        ['type' => 'features', 'heading' => 'Gallery', 'columns' => 3, 'items' => [
                            ['icon' => '◻', 'title' => 'Project 1', 'desc' => 'Add your images here'],
                            ['icon' => '◻', 'title' => 'Project 2', 'desc' => 'Add your images here'],
                            ['icon' => '◻', 'title' => 'Project 3', 'desc' => 'Add your images here'],
                            ['icon' => '◻', 'title' => 'Project 4', 'desc' => 'Add your images here'],
                            ['icon' => '◻', 'title' => 'Project 5', 'desc' => 'Add your images here'],
                            ['icon' => '◻', 'title' => 'Project 6', 'desc' => 'Add your images here'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About Me'],
                        ['type' => 'split',    'heading' => 'The Person Behind the Lens', 'text' => 'With a passion for light and composition, I create images that tell stories. Every shoot is an opportunity to find beauty in the everyday.', 'image' => 'portrait', 'style' => 'image-right'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Get in Touch', 'subheading' => 'Bookings, collaborations, or just to say hello'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'minimal'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Portfolio', 'url' => '/portfolio'],
                ['title' => 'About',     'url' => '/about'],
                ['title' => 'Contact',   'url' => '/contact'],
            ],
        ],

        'blog-magazine' => [
            'name'           => 'Blog / Magazine',
            'description'    => 'Tidningsinspirerad blogg med featured posts, kategorier, sidebar och nyhetsbrev',
            'keywords'       => ['blog','magazine','news','journalist','writer','blogger','tidning','nyheter','artikel','skribent','blogg','magasin'],
            'blueprint'      => 'scandinavian',
            'header_style'   => 'modern',
            'footer_style'   => 'columns',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'Stories, insights, and ideas that matter', 'cta_primary' => 'Read Latest', 'cta_link' => '/blog', 'cta_secondary' => 'About Us', 'cta_link2' => '/about', 'style' => 'clean editorial'],
                        ['type' => 'features', 'heading' => 'Recent Articles', 'columns' => 3, 'items' => [
                            ['icon' => '📝', 'title' => 'Featured Article', 'desc' => 'Your most important story goes here — compelling headline and excerpt'],
                            ['icon' => '📝', 'title' => 'Latest Post', 'desc' => 'Fresh perspectives on topics your readers care about'],
                            ['icon' => '📝', 'title' => 'Popular Read', 'desc' => 'The article everyone is talking about this week'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Never Miss a Story', 'text' => 'Subscribe to our newsletter and get the best articles delivered to your inbox every week.', 'cta' => 'Subscribe', 'style' => 'clean'],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About {site_name}', 'subheading' => 'Our mission and the team behind the stories'],
                        ['type' => 'text-block', 'content' => 'We believe in the power of well-told stories. Our team of writers and editors is dedicated to bringing you thoughtful, well-researched content that informs, inspires, and entertains.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Contact Us', 'subheading' => 'Pitches, feedback, and collaboration inquiries'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'clean'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Home',    'url' => '/'],
                ['title' => 'Blog',    'url' => '/blog'],
                ['title' => 'About',   'url' => '/about'],
                ['title' => 'Contact', 'url' => '/contact'],
            ],
        ],

        'real-estate' => [
            'name'           => 'Real Estate',
            'description'    => 'Professionell fastighetssajt med listningar, sökfilter, karta och mäklarprofiler',
            'keywords'       => ['real estate','property','realtor','apartment','house','fastigheter','mäklare','bostad','lägenhet','villa','hem','hyra','köpa'],
            'blueprint'      => 'corporate-pro',
            'header_style'   => 'modern',
            'footer_style'   => 'rich',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => 'Find Your Dream Home', 'subheading' => '{site_name} — trusted real estate professionals', 'cta_primary' => 'Browse Properties', 'cta_link' => '/properties', 'cta_secondary' => 'Contact an Agent', 'cta_link2' => '/contact', 'style' => 'professional bold'],
                        ['type' => 'features', 'heading' => 'Featured Listings', 'columns' => 3, 'items' => [
                            ['icon' => '🏠', 'title' => 'Modern Apartment', 'desc' => '3 rooms, 85 m² — centrally located with balcony and parking'],
                            ['icon' => '🏡', 'title' => 'Family Villa', 'desc' => '5 rooms, 180 m² — quiet neighborhood, large garden, garage'],
                            ['icon' => '🏢', 'title' => 'Penthouse Suite', 'desc' => '4 rooms, 120 m² — rooftop terrace with panoramic city views'],
                        ]],
                        ['type' => 'stats', 'items' => [
                            ['number' => '500+', 'label' => 'Properties Sold'],
                            ['number' => '98%', 'label' => 'Client Satisfaction'],
                            ['number' => '15+', 'label' => 'Years Experience'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Ready to Make a Move?', 'text' => 'Book a free consultation with one of our experienced agents.', 'cta' => 'Get Started', 'style' => 'professional'],
                    ],
                ],
                [
                    'slug'     => 'properties',
                    'title'    => 'Properties',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Properties', 'subheading' => 'Browse available listings'],
                        ['type' => 'features', 'columns' => 3, 'items' => [
                            ['icon' => '🏠', 'title' => 'Listing 1', 'desc' => 'Property details, location, and price'],
                            ['icon' => '🏠', 'title' => 'Listing 2', 'desc' => 'Property details, location, and price'],
                            ['icon' => '🏠', 'title' => 'Listing 3', 'desc' => 'Property details, location, and price'],
                            ['icon' => '🏠', 'title' => 'Listing 4', 'desc' => 'Property details, location, and price'],
                            ['icon' => '🏠', 'title' => 'Listing 5', 'desc' => 'Property details, location, and price'],
                            ['icon' => '🏠', 'title' => 'Listing 6', 'desc' => 'Property details, location, and price'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About Us',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About {site_name}'],
                        ['type' => 'text-block', 'content' => 'With decades of combined experience in the property market, our team of dedicated agents helps buyers and sellers navigate every step of the process with confidence and care.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Contact Us', 'subheading' => 'Get in touch with our team'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'show_hours' => true, 'hours' => 'Mon-Fri: 9-18 | Sat: 10-14 | Sun: By appointment', 'style' => 'professional'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Properties', 'url' => '/properties'],
                ['title' => 'About',      'url' => '/about'],
                ['title' => 'Contact',    'url' => '/contact'],
            ],
        ],

        'education-courses' => [
            'name'           => 'Education / Courses',
            'description'    => 'Utbildningsplattform med kurskatalog, prisnivåer, omdömen och FAQ',
            'keywords'       => ['education','courses','school','academy','learn','training','utbildning','kurser','skola','akademi','lärande','undervisning','kurs'],
            'blueprint'      => 'bold-modern',
            'header_style'   => 'glass',
            'footer_style'   => 'columns',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => 'Learn Without Limits', 'subheading' => '{site_name} — courses designed for real-world results', 'cta_primary' => 'Browse Courses', 'cta_link' => '/courses', 'cta_secondary' => 'About Us', 'cta_link2' => '/about', 'style' => 'gradient bold'],
                        ['type' => 'features', 'heading' => 'Popular Courses', 'columns' => 3, 'items' => [
                            ['icon' => '🎓', 'title' => 'Fundamentals', 'desc' => 'Build a strong foundation with our beginner-friendly courses'],
                            ['icon' => '🚀', 'title' => 'Advanced', 'desc' => 'Take your skills to the next level with in-depth training'],
                            ['icon' => '💼', 'title' => 'Professional', 'desc' => 'Industry-ready programs with certification'],
                        ]],
                        ['type' => 'stats', 'items' => [
                            ['number' => '5,000+', 'label' => 'Students'],
                            ['number' => '50+', 'label' => 'Courses'],
                            ['number' => '95%', 'label' => 'Completion Rate'],
                        ]],
                        ['type' => 'testimonials', 'items' => [
                            ['quote' => 'The courses are practical and well-structured. I landed my dream job within 3 months of completing the program.', 'author' => 'Emma L., Graduate'],
                            ['quote' => 'Best investment I have made in my career. The instructors are world-class.', 'author' => 'Johan S., Student'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Start Learning Today', 'text' => 'Join thousands of students building their future.', 'cta' => 'Explore Courses', 'style' => 'gradient bold'],
                    ],
                ],
                [
                    'slug'     => 'courses',
                    'title'    => 'Courses',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Our Courses', 'subheading' => 'Choose the plan that fits your goals'],
                        ['type' => 'pricing', 'plans' => [
                            ['name' => 'Starter', 'price' => 'Free', 'features' => ['3 free courses', 'Community access', 'Email support'], 'cta' => 'Get Started'],
                            ['name' => 'Pro', 'price' => '$49/mo', 'features' => ['All courses', 'Certificates', 'Priority support', 'Live sessions', 'Downloadable resources'], 'cta' => 'Start Free Trial', 'featured' => true],
                            ['name' => 'Team', 'price' => '$199/mo', 'features' => ['Everything in Pro', 'Up to 20 seats', 'Admin dashboard', 'Custom learning paths', 'Dedicated manager'], 'cta' => 'Contact Us'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About {site_name}'],
                        ['type' => 'text-block', 'content' => 'We believe education should be accessible, practical, and transformative. Our expert instructors bring real-world experience to every course, ensuring you learn skills that matter.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Contact Us'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'modern'],
                    ],
                ],
                [
                    'slug'     => 'faq',
                    'title'    => 'FAQ',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Frequently Asked Questions'],
                        ['type' => 'text-block', 'content' => '<strong>How do I enroll?</strong><br>Click any course and select your plan. You will get instant access.<br><br><strong>Can I cancel anytime?</strong><br>Yes — no lock-in, cancel with one click.<br><br><strong>Do I get a certificate?</strong><br>Pro and Team plans include certificates upon completion.<br><br><strong>Is there a refund policy?</strong><br>Full refund within 14 days if the course is not for you.'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Courses', 'url' => '/courses'],
                ['title' => 'About',   'url' => '/about'],
                ['title' => 'FAQ',     'url' => '/faq'],
                ['title' => 'Contact', 'url' => '/contact'],
            ],
        ],

        'nonprofit-charity' => [
            'name'           => 'Nonprofit / Charity',
            'description'    => 'Välgörenhetssajt med mission, donation, impact-statistik och volontär-CTA',
            'keywords'       => ['nonprofit','charity','donate','volunteer','ngo','foundation','ideell','förening','donation','välgörenhet','volontär','bidrag','hjälp'],
            'blueprint'      => 'warm-organic',
            'header_style'   => 'minimal',
            'footer_style'   => 'rich',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => 'Together We Make a Difference', 'subheading' => '{site_name} — empowering communities, changing lives', 'cta_primary' => 'Donate Now', 'cta_link' => '/contact', 'cta_secondary' => 'Get Involved', 'cta_link2' => '/get-involved', 'style' => 'warm inspiring'],
                        ['type' => 'split',    'heading' => 'Our Mission', 'text' => 'We believe everyone deserves access to opportunity. Through education, community programs, and direct support, we are building a more equitable world — one person at a time.', 'image' => 'community', 'style' => 'image-right'],
                        ['type' => 'stats', 'items' => [
                            ['number' => '10,000+', 'label' => 'Lives Impacted'],
                            ['number' => '25+', 'label' => 'Active Programs'],
                            ['number' => '98%', 'label' => 'Funds to Mission'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Your Support Matters', 'text' => 'Every contribution — big or small — helps us reach more people in need.', 'cta' => 'Donate Today', 'style' => 'warm organic'],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About Us',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About {site_name}', 'subheading' => 'Our story, our values, our team'],
                        ['type' => 'text-block', 'content' => 'Founded with the belief that compassion and action can transform communities, we work tirelessly to create lasting change. Our dedicated team of staff and volunteers brings heart and expertise to everything we do.'],
                        ['type' => 'features', 'items' => [
                            ['icon' => '❤', 'title' => 'Compassion', 'desc' => 'Every decision starts with empathy and understanding'],
                            ['icon' => '🤝', 'title' => 'Transparency', 'desc' => '98% of funds go directly to programs and people'],
                            ['icon' => '🌍', 'title' => 'Impact', 'desc' => 'Measurable results in every community we serve'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'get-involved',
                    'title'    => 'Get Involved',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Get Involved', 'subheading' => 'There are many ways to help'],
                        ['type' => 'features', 'columns' => 3, 'items' => [
                            ['icon' => '💝', 'title' => 'Donate', 'desc' => 'One-time or monthly — every gift makes a difference'],
                            ['icon' => '🙋', 'title' => 'Volunteer', 'desc' => 'Join our team of dedicated volunteers in your area'],
                            ['icon' => '📢', 'title' => 'Spread the Word', 'desc' => 'Share our mission with your network and community'],
                        ]],
                        ['type' => 'cta',      'heading' => 'Ready to Help?', 'text' => 'Contact us to find the best way for you to contribute.', 'cta' => 'Contact Us', 'style' => 'warm'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Contact Us'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'style' => 'warm'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'About',        'url' => '/about'],
                ['title' => 'Get Involved', 'url' => '/get-involved'],
                ['title' => 'Donate',       'url' => '/contact'],
                ['title' => 'Contact',      'url' => '/contact'],
            ],
        ],

        'salon-barber' => [
            'name'           => 'Salon / Barber',
            'description'    => 'Snygg salong/frisörsajt med tjänster, team, bokning och prislista — mörk lyxig känsla',
            'keywords'       => ['salon','barber','hairdresser','frisör','salong','barbershop','hårsalong','klippning','hår','styling','skägg','beauty'],
            'blueprint'      => 'dark-luxury',
            'header_style'   => 'glass',
            'footer_style'   => 'minimal',
            'woo_required'   => false,
            'pages'          => [
                [
                    'slug'     => 'home',
                    'title'    => 'Home',
                    'set_home' => true,
                    'sections' => [
                        ['type' => 'hero',     'heading' => '{site_name}', 'subheading' => 'Premium cuts, sharp style, effortless confidence', 'cta_primary' => 'Book Now', 'cta_link' => '/contact', 'cta_secondary' => 'Our Services', 'cta_link2' => '/services', 'style' => 'fullscreen dark overlay'],
                        ['type' => 'features', 'heading' => 'What We Offer', 'items' => [
                            ['icon' => '✂', 'title' => 'Haircuts', 'desc' => 'Classic and modern styles tailored to you'],
                            ['icon' => '🪒', 'title' => 'Beard Grooming', 'desc' => 'Precision trims, hot towel shaves, beard care'],
                            ['icon' => '💈', 'title' => 'Styling', 'desc' => 'Color, highlights, treatments, and special occasion looks'],
                        ]],
                        ['type' => 'split',    'heading' => 'Our Team', 'text' => 'Our skilled stylists bring years of experience and a passion for craft. Every visit is a premium experience — from the first consultation to the final touch.', 'image' => 'salon', 'style' => 'image-right'],
                        ['type' => 'cta',      'heading' => 'Book Your Appointment', 'text' => 'Walk-ins welcome, but booking guarantees your spot.', 'cta' => 'Book Now', 'style' => 'dark gradient'],
                    ],
                ],
                [
                    'slug'     => 'services',
                    'title'    => 'Services & Prices',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Services & Prices', 'subheading' => 'Quality grooming at fair prices'],
                        ['type' => 'price-list', 'items' => [
                            ['name' => 'Haircut', 'price' => '350 kr'],
                            ['name' => 'Haircut + Beard Trim', 'price' => '450 kr'],
                            ['name' => 'Beard Grooming', 'price' => '200 kr'],
                            ['name' => 'Hot Towel Shave', 'price' => '300 kr'],
                            ['name' => 'Color / Highlights', 'price' => 'from 600 kr'],
                            ['name' => 'Kids Haircut (under 12)', 'price' => '250 kr'],
                        ]],
                    ],
                ],
                [
                    'slug'     => 'about',
                    'title'    => 'About',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'About Us'],
                        ['type' => 'text-block', 'content' => 'We are more than just a salon — we are a destination for those who value craftsmanship and attention to detail. From classic barbering to modern styling, our team delivers results that make you feel your best.'],
                    ],
                ],
                [
                    'slug'     => 'contact',
                    'title'    => 'Contact & Booking',
                    'sections' => [
                        ['type' => 'hero-small', 'heading' => 'Book Your Visit'],
                        ['type' => 'contact-info', 'email' => '{admin_email}', 'show_hours' => true, 'hours' => 'Mon-Fri: 9-19 | Sat: 9-17 | Sun: Closed', 'style' => 'dark'],
                    ],
                ],
            ],
            'menu' => [
                ['title' => 'Services', 'url' => '/services'],
                ['title' => 'About',    'url' => '/about'],
                ['title' => 'Book Now', 'url' => '/contact'],
            ],
        ],
    ];
}

// ── Match description to best recipe ──────────────────────────
function wpilot_match_recipe( $description ) {
    $desc_lower = strtolower( $description );
    $recipes    = wpilot_get_site_recipes();
    $best       = null;
    $best_score = 0;

    foreach ( $recipes as $id => $r ) {
        $score = 0;
        foreach ( $r['keywords'] as $kw ) {
            if ( strpos( $desc_lower, strtolower( $kw ) ) !== false ) {
                $score += strlen( $kw );
            }
        }
        if ( stripos( $desc_lower, strtolower( $r['name'] ) ) !== false ) $score += 25;
        if ( $score > $best_score ) {
            $best_score = $score;
            $best       = $id;
        }
    }
    return $best;
}

// ── Generate HTML for a section ───────────────────────────────
// Uses wpilot-* CSS classes from the blueprint system
function wpilot_render_section( $section, $site_vars = [] ) {
    $type = $section['type'] ?? 'text-block';

    // Replace {site_name}, {business_type}, {admin_email} placeholders
    $section = wpilot_replace_vars( $section, $site_vars );

    switch ( $type ) {

        case 'hero':
            $h     = esc_html( $section['heading'] ?? '' );
            $sub   = esc_html( $section['subheading'] ?? '' );
            $cta1  = esc_html( $section['cta_primary'] ?? '' );
            $link1 = esc_url( $section['cta_link'] ?? '#' );
            $cta2  = esc_html( $section['cta_secondary'] ?? '' );
            $link2 = esc_url( $section['cta_link2'] ?? '#' );
            $html  = "<section class=\"wpilot-hero wpilot-section-full\">\n";
            $html .= "  <div class=\"wpilot-hero-content\">\n";
            $html .= "    <h1 class=\"wpilot-heading-xl\">{$h}</h1>\n";
            if ( $sub ) $html .= "    <p class=\"wpilot-text-lg\">{$sub}</p>\n";
            $html .= "    <div style=\"margin-top:32px;\">\n";
            if ( $cta1 ) $html .= "      <a href=\"{$link1}\" class=\"wpilot-btn wpilot-btn-primary wpilot-btn-lg\">{$cta1}</a>\n";
            if ( $cta2 ) $html .= "      <a href=\"{$link2}\" class=\"wpilot-btn wpilot-btn-secondary wpilot-btn-lg\" style=\"margin-left:12px;\">{$cta2}</a>\n";
            $html .= "    </div>\n";
            $html .= "  </div>\n";
            $html .= "</section>\n";
            return $html;

        case 'hero-small':
            $h   = esc_html( $section['heading'] ?? '' );
            $sub = esc_html( $section['subheading'] ?? '' );
            return "<section class=\"wpilot-hero\" style=\"padding:80px 40px;\">\n  <div class=\"wpilot-hero-content\">\n    <h1 class=\"wpilot-heading-lg\">{$h}</h1>\n" . ($sub ? "    <p class=\"wpilot-text-lg\">{$sub}</p>\n" : '') . "  </div>\n</section>\n";

        case 'features':
            $h     = esc_html( $section['heading'] ?? '' );
            $items = $section['items'] ?? [];
            $cols  = $section['columns'] ?? count( $items );
            $col_class = $cols <= 2 ? 'wpilot-col-2' : ($cols <= 3 ? 'wpilot-col-3' : 'wpilot-col-4');
            $html  = "<section class=\"wpilot-section\">\n";
            if ( $h ) $html .= "  <h2 class=\"wpilot-heading-lg\" style=\"text-align:center;margin-bottom:50px;\">{$h}</h2>\n";
            $html .= "  <div class=\"wpilot-row\">\n";
            foreach ( $items as $item ) {
                $icon  = $item['icon'] ?? '✦';
                $title = esc_html( $item['title'] ?? '' );
                $desc  = esc_html( $item['desc'] ?? '' );
                $html .= "    <div class=\"{$col_class} wpilot-feature\">\n";
                $html .= "      <div class=\"wpilot-feature-icon\">{$icon}</div>\n";
                $html .= "      <h3>{$title}</h3>\n";
                $html .= "      <p>{$desc}</p>\n";
                $html .= "    </div>\n";
            }
            $html .= "  </div>\n</section>\n";
            return $html;

        case 'stats':
            $items = $section['items'] ?? [];
            $html  = "<section class=\"wpilot-section wpilot-section-alt\" style=\"text-align:center;\">\n  <div class=\"wpilot-row\" style=\"justify-content:center;gap:60px;\">\n";
            foreach ( $items as $item ) {
                $html .= "    <div style=\"flex:0 0 auto;\">\n";
                $html .= "      <div class=\"wpilot-heading-xl\" style=\"color:var(--wp-primary);\">" . esc_html($item['number'] ?? '') . "</div>\n";
                $html .= "      <div class=\"wpilot-text-sm\" style=\"margin-top:8px;\">" . esc_html($item['label'] ?? '') . "</div>\n";
                $html .= "    </div>\n";
            }
            $html .= "  </div>\n</section>\n";
            return $html;

        case 'split':
            $h    = esc_html( $section['heading'] ?? '' );
            $text = esc_html( $section['text'] ?? '' );
            $html = "<section class=\"wpilot-section\">\n  <div class=\"wpilot-row\" style=\"align-items:center;\">\n";
            $html .= "    <div class=\"wpilot-col-2\">\n";
            $html .= "      <span class=\"wpilot-label\">About</span>\n";
            $html .= "      <h2 class=\"wpilot-heading-lg\" style=\"margin:16px 0;\">{$h}</h2>\n";
            $html .= "      <p class=\"wpilot-text-lg\">{$text}</p>\n";
            $html .= "    </div>\n";
            $html .= "    <div class=\"wpilot-col-2\" style=\"background:var(--wp-bg-alt);border-radius:var(--wp-radius);min-height:300px;display:flex;align-items:center;justify-content:center;\">\n";
            $html .= "      <span class=\"wpilot-text-sm\" style=\"opacity:0.5;\">Add image here</span>\n";
            $html .= "    </div>\n";
            $html .= "  </div>\n</section>\n";
            return $html;

        case 'testimonials':
            $items = $section['items'] ?? [];
            $html  = "<section class=\"wpilot-section wpilot-section-alt\">\n  <h2 class=\"wpilot-heading-lg\" style=\"text-align:center;margin-bottom:40px;\">What People Say</h2>\n  <div class=\"wpilot-row\">\n";
            foreach ( $items as $item ) {
                $html .= "    <div class=\"wpilot-col-2\">\n      <div class=\"wpilot-testimonial\">\n";
                $html .= "        <p>\"" . esc_html($item['quote'] ?? '') . "\"</p>\n";
                $html .= "        <cite>— " . esc_html($item['author'] ?? '') . "</cite>\n";
                $html .= "      </div>\n    </div>\n";
            }
            $html .= "  </div>\n</section>\n";
            return $html;

        case 'cta':
            $h    = esc_html( $section['heading'] ?? '' );
            $text = esc_html( $section['text'] ?? '' );
            $cta  = esc_html( $section['cta'] ?? 'Get Started' );
            return "<section class=\"wpilot-cta wpilot-section-full\">\n  <h2 class=\"wpilot-heading-lg\">{$h}</h2>\n  <p class=\"wpilot-text-lg\" style=\"color:inherit;opacity:0.8;margin:16px auto 32px;max-width:500px;\">{$text}</p>\n  <a href=\"#\" class=\"wpilot-btn wpilot-btn-lg\" style=\"background:var(--wp-bg);color:var(--wp-primary);\">{$cta}</a>\n</section>\n";

        case 'text-block':
            $content = wp_kses_post( $section['content'] ?? '' );
            return "<section class=\"wpilot-section\" style=\"max-width:700px;\">\n  <div class=\"wpilot-text-lg\" style=\"line-height:1.8;\">{$content}</div>\n</section>\n";

        case 'products':
            // WooCommerce shortcode
            $count   = intval( $section['count'] ?? 4 );
            $h       = esc_html( $section['heading'] ?? '' );
            $orderby = $section['orderby'] ?? 'date';
            $on_sale = ! empty( $section['on_sale'] );
            $sc      = $on_sale ? "[sale_products limit=\"{$count}\" columns=\"4\"]" : "[products limit=\"{$count}\" columns=\"4\" orderby=\"{$orderby}\"]";
            $html    = "<section class=\"wpilot-section\">\n";
            if ( $h ) $html .= "  <h2 class=\"wpilot-heading-lg\" style=\"text-align:center;margin-bottom:40px;\">{$h}</h2>\n";
            $html .= "  {$sc}\n</section>\n";
            return $html;

        case 'contact-info':
            $email = esc_html( $section['email'] ?? '' );
            $hours = esc_html( $section['hours'] ?? '' );
            $html  = "<section class=\"wpilot-section\" style=\"text-align:center;\">\n";
            if ( $email ) $html .= "  <p class=\"wpilot-text-lg\">Email: <a href=\"mailto:{$email}\">{$email}</a></p>\n";
            if ( $hours ) $html .= "  <p class=\"wpilot-text-sm\" style=\"margin-top:16px;\">{$hours}</p>\n";
            $html .= "</section>\n";
            return $html;

        case 'menu-section':
            $cat   = esc_html( $section['category'] ?? '' );
            $items = $section['items'] ?? [];
            $html  = "<section class=\"wpilot-section\">\n";
            if ( $cat ) $html .= "  <h2 class=\"wpilot-heading-md\" style=\"margin-bottom:30px;border-bottom:2px solid var(--wp-primary);padding-bottom:12px;display:inline-block;\">{$cat}</h2>\n";
            foreach ( $items as $item ) {
                $html .= "  <div style=\"display:flex;justify-content:space-between;align-items:baseline;padding:16px 0;border-bottom:1px solid var(--wp-border);\">\n";
                $html .= "    <div>\n      <strong style=\"color:var(--wp-heading);\">" . esc_html($item['name'] ?? '') . "</strong>\n";
                $html .= "      <br><span class=\"wpilot-text-sm\">" . esc_html($item['desc'] ?? '') . "</span>\n    </div>\n";
                $html .= "    <span style=\"color:var(--wp-primary);font-weight:600;white-space:nowrap;margin-left:20px;\">" . esc_html($item['price'] ?? '') . " kr</span>\n";
                $html .= "  </div>\n";
            }
            $html .= "</section>\n";
            return $html;

        case 'price-list':
            $items = $section['items'] ?? [];
            $html  = "<section class=\"wpilot-section\">\n";
            foreach ( $items as $item ) {
                $html .= "  <div style=\"display:flex;justify-content:space-between;align-items:center;padding:20px 0;border-bottom:1px solid var(--wp-border);\">\n";
                $html .= "    <span style=\"font-size:1.1rem;\">" . esc_html($item['name'] ?? '') . "</span>\n";
                $html .= "    <span style=\"color:var(--wp-primary);font-weight:600;font-size:1.1rem;\">" . esc_html($item['price'] ?? '') . "</span>\n";
                $html .= "  </div>\n";
            }
            $html .= "</section>\n";
            return $html;

        case 'pricing':
            $plans = $section['plans'] ?? [];
            $html  = "<section class=\"wpilot-section\">\n  <div class=\"wpilot-row\" style=\"justify-content:center;\">\n";
            foreach ( $plans as $plan ) {
                $featured = ! empty( $plan['featured'] );
                $border   = $featured ? 'border:2px solid var(--wp-primary);' : 'border:1px solid var(--wp-border);';
                $html .= "    <div class=\"wpilot-col-3 wpilot-card\" style=\"text-align:center;{$border}\">\n";
                if ( $featured ) $html .= "      <span class=\"wpilot-label\" style=\"margin-bottom:16px;display:block;\">Most Popular</span>\n";
                $html .= "      <h3 class=\"wpilot-heading-md\">" . esc_html($plan['name'] ?? '') . "</h3>\n";
                $html .= "      <div class=\"wpilot-heading-lg\" style=\"color:var(--wp-primary);margin:16px 0;\">" . esc_html($plan['price'] ?? '') . "</div>\n";
                $html .= "      <ul style=\"list-style:none;padding:0;margin:24px 0;text-align:left;\">\n";
                foreach ( ($plan['features'] ?? []) as $feat ) {
                    $html .= "        <li style=\"padding:8px 0;border-bottom:1px solid var(--wp-border);\">✓ " . esc_html($feat) . "</li>\n";
                }
                $html .= "      </ul>\n";
                $btn_class = $featured ? 'wpilot-btn-primary' : 'wpilot-btn-secondary';
                $html .= "      <a href=\"#\" class=\"wpilot-btn {$btn_class}\" style=\"width:100%;\">" . esc_html($plan['cta'] ?? 'Get Started') . "</a>\n";
                $html .= "    </div>\n";
            }
            $html .= "  </div>\n</section>\n";
            return $html;

        default:
            return "<!-- Unknown section type: {$type} -->\n";
    }
}

// ── Replace template variables ────────────────────────────────
function wpilot_replace_vars( $data, $vars = [] ) {
    if ( empty( $vars ) ) {
        $vars = [
            '{site_name}'     => get_bloginfo( 'name' ),
            '{admin_email}'   => get_option( 'admin_email' ),
            '{business_type}' => $vars['{business_type}'] ?? 'business',
        ];
    }
    array_walk_recursive( $data, function( &$val ) use ( $vars ) {
        if ( is_string( $val ) ) {
            $val = str_replace( array_keys( $vars ), array_values( $vars ), $val );
        }
    });
    return $data;
}

// ── Build a complete site from a recipe ───────────────────────
function wpilot_build_site_from_recipe( $params ) {
    $recipe_id   = sanitize_text_field( $params['recipe'] ?? $params['id'] ?? '' );
    $description = sanitize_text_field( $params['description'] ?? $params['business'] ?? '' );
    $biz_type    = sanitize_text_field( $params['business_type'] ?? $description );

    // Auto-match if no ID given
    if ( empty( $recipe_id ) && ! empty( $description ) ) {
        $recipe_id = wpilot_match_recipe( $description );
    }

    $recipes = wpilot_get_site_recipes();
    if ( ! $recipe_id || ! isset( $recipes[$recipe_id] ) ) {
        $available = array_map( fn($id, $r) => "{$id}: {$r['name']}", array_keys($recipes), $recipes );
        return wpilot_err( "No matching recipe. Available: " . implode( ', ', $available ) );
    }

    $recipe = $recipes[$recipe_id];
    $results = [];

    // Template variables
    $site_vars = [
        '{site_name}'     => get_bloginfo( 'name' ),
        '{admin_email}'   => get_option( 'admin_email' ),
        '{business_type}' => $biz_type ?: $recipe['name'],
    ];

    // 1. Apply blueprint (design system)
    if ( function_exists( 'wpilot_apply_blueprint' ) ) {
        $bp_result = wpilot_apply_blueprint( ['blueprint' => $recipe['blueprint']] );
        $results[] = "Blueprint '{$recipe['blueprint']}' applied";
    }

    // 2. Create all pages — personalized with business profile
    $biz = function_exists( 'wpilot_get_business_profile' ) ? wpilot_get_business_profile() : [];
    foreach ( $recipe['pages'] as $page_def ) {
        $html = '';
        foreach ( $page_def['sections'] as $section ) {
            // Personalize section content with business info
            if ( ! empty( $biz ) && function_exists( 'wpilot_personalize_section' ) ) {
                $section = wpilot_personalize_section( $section, $biz );
            }
            $html .= wpilot_render_section( $section, $site_vars );
        }

        // Convert to builder-native format based on active builder
        $builder = function_exists('wpilot_detect_builder') ? wpilot_detect_builder() : 'gutenberg';
        $wrapped = wpilot_wrap_for_builder( $html, $builder, $page_def['sections'] ?? [] );

        $page_params = [
            'title'   => $page_def['title'],
            'slug'    => $page_def['slug'],
            'html'    => $wrapped,
            'status'  => 'publish',
        ];
        if ( ! empty( $page_def['set_home'] ) ) {
            $page_params['set_as_homepage'] = true;
        }

        if ( function_exists( 'wpilot_create_html_page' ) ) {
            $r = wpilot_create_html_page( $page_params );
            $results[] = "Page '{$page_def['title']}' " . ($r['success'] ? 'created' : 'failed: ' . ($r['message'] ?? ''));
        }
    }

    // 3. Create menu
    if ( ! empty( $recipe['menu'] ) && function_exists( 'wpilot_run_tool' ) ) {
        $menu_items = array_map( function($m) {
            return ['title' => $m['title'], 'url' => home_url( $m['url'] )];
        }, $recipe['menu'] );
        $menu_result = wpilot_run_tool( 'create_menu', [
            'name'     => 'Main Menu',
            'location' => 'primary',
            'items'    => $menu_items,
        ]);
        $results[] = "Menu created with " . count($recipe['menu']) . " items";
    }

    // 3b. Apply header blueprint
    if ( ! empty( $recipe['header_style'] ) && function_exists( 'wpilot_apply_header_blueprint' ) ) {
        $header_params = [ 'style' => $recipe['header_style'] ];
        if ( ! empty( $recipe['menu'] ) ) {
            // Pass CTA from last menu item if it looks like a CTA
            $last = end( $recipe['menu'] );
            if ( stripos( $last['title'], 'book' ) !== false || stripos( $last['title'], 'contact' ) !== false || stripos( $last['title'], 'boka' ) !== false ) {
                $header_params['cta_text'] = $last['title'];
                $header_params['cta_url']  = home_url( $last['url'] );
            }
        }
        wpilot_apply_header_blueprint( $header_params );
        $results[] = "Header blueprint '{$recipe['header_style']}' applied";
    }

    // 3c. Apply footer blueprint
    if ( ! empty( $recipe['footer_style'] ) && function_exists( 'wpilot_apply_footer_blueprint' ) ) {
        wpilot_apply_footer_blueprint( [ 'style' => $recipe['footer_style'] ] );
        $results[] = "Footer blueprint '{$recipe['footer_style']}' applied";
    }

    // 4. WooCommerce config
    if ( ! empty( $recipe['woo_config'] ) && class_exists( 'WooCommerce' ) ) {
        $woo = $recipe['woo_config'];
        if ( ($woo['currency'] ?? '') === 'auto' ) {
            // Detect from locale
            $locale = get_locale();
            $currency = 'USD';
            if ( strpos($locale, 'sv') === 0 ) $currency = 'SEK';
            elseif ( strpos($locale, 'da') === 0 ) $currency = 'DKK';
            elseif ( strpos($locale, 'nb') === 0 || strpos($locale, 'nn') === 0 ) $currency = 'NOK';
            elseif ( strpos($locale, 'de') === 0 || strpos($locale, 'fr') === 0 ) $currency = 'EUR';
            elseif ( strpos($locale, 'en_GB') === 0 ) $currency = 'GBP';
            update_option( 'woocommerce_currency', $currency );
            $results[] = "WooCommerce currency set to {$currency}";
        }
        if ( ! empty( $woo['guest_checkout'] ) ) {
            update_option( 'woocommerce_enable_guest_checkout', 'yes' );
        }
    }

    // 5. Bust cache
    if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();
    if ( function_exists( 'wpilot_invalidate_blueprint' ) ) wpilot_invalidate_blueprint();

    $summary = "Site built with recipe \"{$recipe['name']}\"!\n";
    $summary .= implode( "\n", array_map( fn($r) => "• {$r}", $results ) );
    $summary .= "\n\nPages created: " . count($recipe['pages']);
    $summary .= "\nBlueprint: {$recipe['blueprint']}";
    if ( $recipe['woo_required'] && ! class_exists('WooCommerce') ) {
        $summary .= "\n⚠️ This recipe works best with WooCommerce — install it for full features.";
    }

    return wpilot_ok( $summary, [
        'recipe'  => $recipe_id,
        'name'    => $recipe['name'],
        'pages'   => count($recipe['pages']),
        'results' => $results,
    ]);
}

// ── List available recipes ────────────────────────────────────
function wpilot_list_site_recipes( $params = [] ) {
    $recipes = wpilot_get_site_recipes();
    $desc    = strtolower( $params['description'] ?? $params['query'] ?? '' );

    $list = [];
    foreach ( $recipes as $id => $r ) {
        $score = 0;
        if ( $desc ) {
            foreach ( $r['keywords'] as $kw ) {
                if ( strpos( $desc, strtolower($kw) ) !== false ) $score += strlen($kw);
            }
        }
        $list[] = [
            'id'          => $id,
            'name'        => $r['name'],
            'description' => $r['description'],
            'blueprint'   => $r['blueprint'],
            'pages'       => count($r['pages']),
            'woo'         => $r['woo_required'],
            'score'       => $score,
        ];
    }

    // Sort by relevance if query given
    if ( $desc ) usort( $list, fn($a, $b) => $b['score'] - $a['score'] );

    $summary = count($list) . " site recipes available:\n";
    foreach ( $list as $r ) {
        $woo = $r['woo'] ? ' [WooCommerce]' : '';
        $summary .= "• **{$r['name']}** (`{$r['id']}`) — {$r['description']}{$woo} ({$r['pages']} pages)\n";
    }

    return wpilot_ok( $summary, ['recipes' => $list] );
}

// ── Convert HTML to builder-native format ─────────────────────
// Gutenberg: wp:group + wp:heading + wp:paragraph + wp:buttons (editable)
// Elementor: stores as wp:html but clears _elementor_data so Elementor can adopt it
// Divi: keeps as HTML (Divi reads HTML fine in its visual builder)
function wpilot_wrap_for_builder( $html, $builder = 'gutenberg', $sections = [] ) {

    switch ( $builder ) {

        case 'elementor':
            // Elementor can import raw HTML — wrap each section as a separate wp:html
            // so Elementor's "Convert to Elementor" button can process them individually
            $parts = preg_split( '/(?=<section\s)/', $html, -1, PREG_SPLIT_NO_EMPTY );
            $wrapped = '';
            foreach ( $parts as $part ) {
                $part = trim( $part );
                if ( $part ) $wrapped .= "<!-- wp:html -->\n{$part}\n<!-- /wp:html -->\n\n";
            }
            return $wrapped ?: "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";

        case 'divi':
            // Divi can use its Code Module to render HTML — wrap in a single block
            return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";

        case 'gutenberg':
        case 'block':
        default:
            // Convert sections to native Gutenberg blocks where possible
            $blocks = '';
            foreach ( $sections as $section ) {
                $type = $section['type'] ?? '';
                switch ( $type ) {

                    case 'hero':
                    case 'hero-small':
                        $h   = esc_html( $section['heading'] ?? '' );
                        $sub = esc_html( $section['subheading'] ?? '' );
                        $cta1 = $section['cta_primary'] ?? '';
                        $link1 = $section['cta_link'] ?? '#';
                        $cta2 = $section['cta_secondary'] ?? '';
                        $link2 = $section['cta_link2'] ?? '#';
                        $pad = $type === 'hero' ? '120px' : '80px';

                        $blocks .= "<!-- wp:group {\"style\":{\"spacing\":{\"padding\":{\"top\":\"{$pad}\",\"bottom\":\"{$pad}\",\"left\":\"40px\",\"right\":\"40px\"}},\"color\":{\"background\":\"var(--wp-bg)\"}},\"layout\":{\"type\":\"constrained\",\"contentSize\":\"800px\"}} -->\n";
                        $blocks .= "<div class=\"wp-block-group\" style=\"background:var(--wp-bg);padding-top:{$pad};padding-bottom:{$pad};padding-left:40px;padding-right:40px;text-align:center\">\n";
                        $blocks .= "<!-- wp:heading {\"textAlign\":\"center\",\"level\":1,\"style\":{\"typography\":{\"fontFamily\":\"var(--wp-heading-font)\"}}} -->\n";
                        $blocks .= "<h1 class=\"wp-block-heading has-text-align-center\" style=\"font-family:var(--wp-heading-font)\">{$h}</h1>\n";
                        $blocks .= "<!-- /wp:heading -->\n\n";
                        if ( $sub ) {
                            $blocks .= "<!-- wp:paragraph {\"align\":\"center\"} -->\n";
                            $blocks .= "<p class=\"has-text-align-center\" style=\"color:var(--wp-text-muted);font-size:1.25rem\">{$sub}</p>\n";
                            $blocks .= "<!-- /wp:paragraph -->\n\n";
                        }
                        if ( $cta1 ) {
                            $blocks .= "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n";
                            $blocks .= "<div class=\"wp-block-buttons\">\n";
                            $blocks .= "<!-- wp:button {\"style\":{\"border\":{\"radius\":\"var(--wp-radius)\"}}} -->\n";
                            $blocks .= "<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"" . esc_url($link1) . "\" style=\"border-radius:var(--wp-radius);background:var(--wp-primary);color:var(--wp-bg)\">" . esc_html($cta1) . "</a></div>\n";
                            $blocks .= "<!-- /wp:button -->\n";
                            if ( $cta2 ) {
                                $blocks .= "<!-- wp:button {\"className\":\"is-style-outline\"} -->\n";
                                $blocks .= "<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link wp-element-button\" href=\"" . esc_url($link2) . "\" style=\"border-radius:var(--wp-radius);color:var(--wp-primary)\">" . esc_html($cta2) . "</a></div>\n";
                                $blocks .= "<!-- /wp:button -->\n";
                            }
                            $blocks .= "</div>\n<!-- /wp:buttons -->\n\n";
                        }
                        $blocks .= "</div>\n<!-- /wp:group -->\n\n";
                        break;

                    case 'text-block':
                        $content = wp_kses_post( $section['content'] ?? '' );
                        $blocks .= "<!-- wp:group {\"style\":{\"spacing\":{\"padding\":{\"top\":\"60px\",\"bottom\":\"60px\"}}},\"layout\":{\"type\":\"constrained\",\"contentSize\":\"700px\"}} -->\n";
                        $blocks .= "<div class=\"wp-block-group\" style=\"padding-top:60px;padding-bottom:60px\">\n";
                        $blocks .= "<!-- wp:paragraph -->\n<p style=\"line-height:1.8;font-size:1.1rem\">{$content}</p>\n<!-- /wp:paragraph -->\n";
                        $blocks .= "</div>\n<!-- /wp:group -->\n\n";
                        break;

                    case 'features':
                        $h = esc_html( $section['heading'] ?? '' );
                        $items = $section['items'] ?? [];
                        $cols = min( count($items), 4 );
                        $blocks .= "<!-- wp:group {\"style\":{\"spacing\":{\"padding\":{\"top\":\"80px\",\"bottom\":\"80px\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n";
                        $blocks .= "<div class=\"wp-block-group\" style=\"padding-top:80px;padding-bottom:80px\">\n";
                        if ( $h ) {
                            $blocks .= "<!-- wp:heading {\"textAlign\":\"center\",\"level\":2} -->\n";
                            $blocks .= "<h2 class=\"wp-block-heading has-text-align-center\">{$h}</h2>\n";
                            $blocks .= "<!-- /wp:heading -->\n\n";
                            $blocks .= "<!-- wp:spacer {\"height\":\"40px\"} -->\n<div style=\"height:40px\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->\n\n";
                        }
                        $blocks .= "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n";
                        foreach ( $items as $item ) {
                            $blocks .= "<!-- wp:column -->\n<div class=\"wp-block-column\" style=\"text-align:center;padding:24px\">\n";
                            $blocks .= "<!-- wp:paragraph {\"align\":\"center\",\"style\":{\"typography\":{\"fontSize\":\"2.5rem\"}}} -->\n";
                            $blocks .= "<p class=\"has-text-align-center\" style=\"font-size:2.5rem\">" . ($item['icon'] ?? '✦') . "</p>\n<!-- /wp:paragraph -->\n";
                            $blocks .= "<!-- wp:heading {\"textAlign\":\"center\",\"level\":3} -->\n";
                            $blocks .= "<h3 class=\"wp-block-heading has-text-align-center\">" . esc_html($item['title'] ?? '') . "</h3>\n<!-- /wp:heading -->\n";
                            $blocks .= "<!-- wp:paragraph {\"align\":\"center\"} -->\n";
                            $blocks .= "<p class=\"has-text-align-center\" style=\"color:var(--wp-text-muted)\">" . esc_html($item['desc'] ?? '') . "</p>\n<!-- /wp:paragraph -->\n";
                            $blocks .= "</div>\n<!-- /wp:column -->\n";
                        }
                        $blocks .= "</div>\n<!-- /wp:columns -->\n";
                        $blocks .= "</div>\n<!-- /wp:group -->\n\n";
                        break;

                    case 'cta':
                        $h   = esc_html( $section['heading'] ?? '' );
                        $txt = esc_html( $section['text'] ?? '' );
                        $cta = esc_html( $section['cta'] ?? 'Get Started' );
                        $blocks .= "<!-- wp:group {\"style\":{\"spacing\":{\"padding\":{\"top\":\"80px\",\"bottom\":\"80px\"}},\"color\":{\"background\":\"var(--wp-primary)\"}},\"layout\":{\"type\":\"constrained\",\"contentSize\":\"600px\"}} -->\n";
                        $blocks .= "<div class=\"wp-block-group\" style=\"background:var(--wp-primary);padding:80px 40px;text-align:center;border-radius:var(--wp-radius)\">\n";
                        $blocks .= "<!-- wp:heading {\"textAlign\":\"center\",\"level\":2} -->\n";
                        $blocks .= "<h2 class=\"wp-block-heading has-text-align-center\" style=\"color:var(--wp-bg)\">{$h}</h2>\n<!-- /wp:heading -->\n";
                        if ( $txt ) {
                            $blocks .= "<!-- wp:paragraph {\"align\":\"center\"} -->\n";
                            $blocks .= "<p class=\"has-text-align-center\" style=\"color:var(--wp-bg);opacity:0.8\">{$txt}</p>\n<!-- /wp:paragraph -->\n";
                        }
                        $blocks .= "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n";
                        $blocks .= "<div class=\"wp-block-buttons\"><div class=\"wp-block-button\"><a class=\"wp-block-button__link\" href=\"#\" style=\"background:var(--wp-bg);color:var(--wp-primary);border-radius:var(--wp-radius)\">{$cta}</a></div></div>\n";
                        $blocks .= "<!-- /wp:buttons -->\n";
                        $blocks .= "</div>\n<!-- /wp:group -->\n\n";
                        break;

                    default:
                        // Fallback: wrap unknown sections in wp:html (still works, just not visually editable)
                        $section_html = wpilot_render_section( $section, [] );
                        $blocks .= "<!-- wp:html -->\n{$section_html}\n<!-- /wp:html -->\n\n";
                        break;
                }
            }
            return $blocks;
    }
}
