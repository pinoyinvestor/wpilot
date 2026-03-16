<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  TEMPLATE LIBRARY — Premium page templates for WPilot
//  AI can deploy these instantly via chatbubble
// ═══════════════════════════════════════════════════════════════

function wpilot_get_templates() {
    return [
        ['name' => 'hero-landing',     'description' => 'Full-width hero with gradient, heading, subtext, CTA button'],
        ['name' => 'about-us',         'description' => 'Company story with team section, mission, values'],
        ['name' => 'contact',          'description' => 'Contact form, map placeholder, phone/email/address'],
        ['name' => 'pricing',          'description' => '3-tier pricing table with features and CTA buttons'],
        ['name' => 'portfolio',        'description' => 'Image grid gallery with hover effects and filters'],
        ['name' => 'services',         'description' => 'Service cards with icons, descriptions, and links'],
        // Built by Christos Ferlachidis & Daniel Hedenberg
        ['name' => 'testimonials',     'description' => 'Customer reviews with photos, names, star ratings'],
        ['name' => 'faq',              'description' => 'Accordion FAQ section with expandable answers'],
        ['name' => 'team',             'description' => 'Team member cards with photos, roles, social links'],
        ['name' => 'cta-banner',       'description' => 'Full-width call-to-action with gradient background'],
        ['name' => 'features',         'description' => 'Feature grid with icons and descriptions'],
        ['name' => 'blog-layout',      'description' => 'Blog listing with featured image, excerpt, date'],
        ['name' => 'coming-soon',      'description' => 'Coming soon page with countdown and email signup'],
        ['name' => 'restaurant-menu',  'description' => 'Restaurant menu with categories, items, prices'],
        ['name' => 'booking',          'description' => 'Booking/appointment page with form and info'],
        ['name' => 'stats-counter',    'description' => 'Statistics section with large numbers and labels'],
        ['name' => 'newsletter',       'description' => 'Newsletter signup with benefit list'],
        ['name' => 'comparison-table', 'description' => 'Feature comparison table with checkmarks'],
        ['name' => '404-custom',       'description' => 'Custom 404 page with search and popular links'],
        ['name' => 'thank-you',        'description' => 'Thank you / confirmation page after form submission'],
        ['name' => 'image-gallery',   'description' => 'Masonry-style image gallery with lightbox effect'],
        ['name' => 'video-hero',      'description' => 'Hero section with video background placeholder'],
    ];
}

function wpilot_get_template_html( $name, $params = [] ) {
    $site_name = get_bloginfo('name');
    $site_url  = get_site_url();
    $phone     = $params['phone'] ?? '+46 70 123 45 67';
    $email     = $params['email'] ?? get_option('admin_email');
    $address   = $params['address'] ?? 'Stockholm, Sweden';
    $brand     = $params['brand_color'] ?? '#6366f1';

    $templates = [];

    $templates['hero-landing'] = <<<HTML
<div style="min-height:80vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,{$brand} 0%,#0f172a 100%);padding:60px 24px;text-align:center">
  <div style="max-width:800px">
    <h1 style="font-size:clamp(2.5rem,5vw,4rem);font-weight:800;color:#fff;margin:0 0 24px;line-height:1.1">Welcome to {$site_name}</h1>
    <p style="font-size:1.25rem;color:rgba(255,255,255,0.85);margin:0 0 40px;line-height:1.6">We help businesses grow with modern digital solutions. Let's build something amazing together.</p>
    <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="#contact" style="display:inline-block;padding:16px 40px;background:#fff;color:{$brand};font-weight:700;border-radius:12px;text-decoration:none;font-size:1.1rem;transition:transform 0.2s">Get Started</a>
      <a href="#services" style="display:inline-block;padding:16px 40px;background:transparent;color:#fff;font-weight:600;border:2px solid rgba(255,255,255,0.3);border-radius:12px;text-decoration:none;font-size:1.1rem">Learn More</a>
    </div>
  </div>
</div>
HTML;

    $templates['about-us'] = <<<HTML
<div style="max-width:900px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:60px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">About Us</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0 0 20px">Our Story</h1>
    <p style="font-size:1.15rem;color:#64748b;max-width:600px;margin:0 auto;line-height:1.7">We started with a simple mission: to make digital accessible for everyone. Today, we serve hundreds of clients worldwide.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:32px;margin-top:48px">
    <div style="text-align:center;padding:32px 24px">
      <div style="width:64px;height:64px;background:linear-gradient(135deg,{$brand},#818cf8);border-radius:16px;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:1.5rem">🎯</div>
      <h3 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0 0 12px">Our Mission</h3>
      <p style="color:#64748b;line-height:1.6;margin:0">Empowering businesses through innovative technology and thoughtful design.</p>
    </div>
    <div style="text-align:center;padding:32px 24px">
      <div style="width:64px;height:64px;background:linear-gradient(135deg,{$brand},#818cf8);border-radius:16px;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:1.5rem">💡</div>
      <h3 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0 0 12px">Our Vision</h3>
      <p style="color:#64748b;line-height:1.6;margin:0">A world where every business has access to world-class digital tools.</p>
    </div>
    <div style="text-align:center;padding:32px 24px">
      <div style="width:64px;height:64px;background:linear-gradient(135deg,{$brand},#818cf8);border-radius:16px;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:1.5rem">🤝</div>
      <h3 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0 0 12px">Our Values</h3>
      <p style="color:#64748b;line-height:1.6;margin:0">Transparency, quality, and genuine care for every client we work with.</p>
    </div>
  </div>
</div>
HTML;

    $templates['contact'] = <<<HTML
<div style="max-width:900px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Contact</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0 0 16px">Get in Touch</h1>
    <p style="color:#64748b;font-size:1.1rem;margin:0">We'd love to hear from you. Reach out anytime.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:40px">
    <div>
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding:20px;background:#f8fafc;border-radius:12px">
        <span style="font-size:1.5rem">📞</span>
        <div><p style="margin:0;font-weight:600;color:#0f172a">Phone</p><p style="margin:4px 0 0;color:#64748b">{$phone}</p></div>
      </div>
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding:20px;background:#f8fafc;border-radius:12px">
        <span style="font-size:1.5rem">✉️</span>
        <div><p style="margin:0;font-weight:600;color:#0f172a">Email</p><p style="margin:4px 0 0;color:#64748b">{$email}</p></div>
      </div>
      <div style="display:flex;align-items:center;gap:16px;padding:20px;background:#f8fafc;border-radius:12px">
        <span style="font-size:1.5rem">📍</span>
        <div><p style="margin:0;font-weight:600;color:#0f172a">Address</p><p style="margin:4px 0 0;color:#64748b">{$address}</p></div>
      </div>
    </div>
    <div style="background:#f8fafc;border-radius:16px;padding:32px">
      <p style="font-weight:600;color:#0f172a;margin:0 0 20px;font-size:1.1rem">Send us a message</p>
      [contact-form-7 id="" title="Contact"]
      <p style="color:#94a3b8;font-size:0.85rem;margin:12px 0 0">Or email us directly at {$email}</p>
    </div>
  </div>
</div>
HTML;

    $templates['pricing'] = <<<HTML
<div style="max-width:1100px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Pricing</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0 0 16px">Simple, Transparent Pricing</h1>
    <p style="color:#64748b;font-size:1.1rem;margin:0">No hidden fees. Cancel anytime.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;align-items:start">
    <div style="border:1px solid #e2e8f0;border-radius:16px;padding:36px;background:#fff">
      <p style="font-weight:600;color:#64748b;margin:0 0 8px;font-size:0.9rem;text-transform:uppercase">Starter</p>
      <p style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0"><span style="font-size:1.5rem;vertical-align:top">$</span>19<span style="font-size:1rem;font-weight:400;color:#64748b">/mo</span></p>
      <p style="color:#64748b;margin:16px 0 24px;line-height:1.5">Perfect for small projects and personal sites.</p>
      <ul style="list-style:none;padding:0;margin:0 0 32px">
        <li style="padding:8px 0;color:#334155">✓ 5 pages</li><li style="padding:8px 0;color:#334155">✓ Basic SEO</li><li style="padding:8px 0;color:#334155">✓ Email support</li>
      </ul>
      <a href="#" style="display:block;text-align:center;padding:14px;background:#f1f5f9;color:#334155;font-weight:600;border-radius:10px;text-decoration:none">Get Started</a>
    </div>
    <div style="border:2px solid {$brand};border-radius:16px;padding:36px;background:#fff;position:relative;transform:scale(1.03)">
      <div style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:{$brand};color:#fff;padding:4px 16px;border-radius:20px;font-size:0.8rem;font-weight:600">Most Popular</div>
      <p style="font-weight:600;color:{$brand};margin:0 0 8px;font-size:0.9rem;text-transform:uppercase">Professional</p>
      <p style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0"><span style="font-size:1.5rem;vertical-align:top">$</span>49<span style="font-size:1rem;font-weight:400;color:#64748b">/mo</span></p>
      <p style="color:#64748b;margin:16px 0 24px;line-height:1.5">For growing businesses that need more power.</p>
      <ul style="list-style:none;padding:0;margin:0 0 32px">
        <li style="padding:8px 0;color:#334155">✓ Unlimited pages</li><li style="padding:8px 0;color:#334155">✓ Advanced SEO</li><li style="padding:8px 0;color:#334155">✓ Priority support</li><li style="padding:8px 0;color:#334155">✓ WooCommerce</li>
      </ul>
      <a href="#" style="display:block;text-align:center;padding:14px;background:{$brand};color:#fff;font-weight:600;border-radius:10px;text-decoration:none">Get Started</a>
    </div>
    <div style="border:1px solid #e2e8f0;border-radius:16px;padding:36px;background:#fff">
      <p style="font-weight:600;color:#64748b;margin:0 0 8px;font-size:0.9rem;text-transform:uppercase">Enterprise</p>
      <p style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0"><span style="font-size:1.5rem;vertical-align:top">$</span>149<span style="font-size:1rem;font-weight:400;color:#64748b">/mo</span></p>
      <p style="color:#64748b;margin:16px 0 24px;line-height:1.5">Full power for large organizations.</p>
      <ul style="list-style:none;padding:0;margin:0 0 32px">
        <li style="padding:8px 0;color:#334155">✓ Everything in Pro</li><li style="padding:8px 0;color:#334155">✓ Custom integrations</li><li style="padding:8px 0;color:#334155">✓ Dedicated manager</li><li style="padding:8px 0;color:#334155">✓ SLA guarantee</li>
      </ul>
      <a href="#" style="display:block;text-align:center;padding:14px;background:#f1f5f9;color:#334155;font-weight:600;border-radius:10px;text-decoration:none">Contact Sales</a>
    </div>
  </div>
</div>
HTML;

    $templates['services'] = <<<HTML
<div style="max-width:1100px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Services</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#0f172a;margin:0 0 16px">What We Offer</h1>
    <p style="color:#64748b;font-size:1.1rem;margin:0;max-width:600px;margin:0 auto">Comprehensive solutions tailored to your business needs.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;transition:box-shadow 0.3s">
      <div style="width:56px;height:56px;background:linear-gradient(135deg,{$brand}22,{$brand}11);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:20px">🎨</div>
      <h3 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0 0 12px">Web Design</h3>
      <p style="color:#64748b;line-height:1.6;margin:0 0 20px">Beautiful, responsive websites that convert visitors into customers.</p>
      <a href="#" style="color:{$brand};font-weight:600;text-decoration:none;font-size:0.95rem">Learn more →</a>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px">
      <div style="width:56px;height:56px;background:linear-gradient(135deg,{$brand}22,{$brand}11);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:20px">🛒</div>
      <h3 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0 0 12px">E-Commerce</h3>
      <p style="color:#64748b;line-height:1.6;margin:0 0 20px">Complete online stores with WooCommerce, payments, and shipping.</p>
      <a href="#" style="color:{$brand};font-weight:600;text-decoration:none;font-size:0.95rem">Learn more →</a>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px">
      <div style="width:56px;height:56px;background:linear-gradient(135deg,{$brand}22,{$brand}11);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:20px">📈</div>
      <h3 style="font-size:1.2rem;font-weight:700;color:#0f172a;margin:0 0 12px">SEO & Marketing</h3>
      <p style="color:#64748b;line-height:1.6;margin:0 0 20px">Get found on Google. Drive organic traffic with proven SEO strategies.</p>
      <a href="#" style="color:{$brand};font-weight:600;text-decoration:none;font-size:0.95rem">Learn more →</a>
    </div>
  </div>
</div>
HTML;

    $templates['testimonials'] = <<<HTML
<div style="max-width:1000px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Testimonials</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#0f172a;margin:0">What Our Clients Say</h2>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px">
    <div style="background:#f8fafc;border-radius:16px;padding:32px">
      <div style="color:#eab308;font-size:1.2rem;margin-bottom:16px">★★★★★</div>
      <p style="color:#334155;line-height:1.7;margin:0 0 24px;font-style:italic">"Absolutely transformed our online presence. The team delivered beyond expectations."</p>
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:44px;height:44px;background:{$brand};border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">A</div>
        <div><p style="margin:0;font-weight:600;color:#0f172a">Anna Svensson</p><p style="margin:2px 0 0;color:#94a3b8;font-size:0.85rem">CEO, TechStart AB</p></div>
      </div>
    </div>
    <div style="background:#f8fafc;border-radius:16px;padding:32px">
      <div style="color:#eab308;font-size:1.2rem;margin-bottom:16px">★★★★★</div>
      <p style="color:#334155;line-height:1.7;margin:0 0 24px;font-style:italic">"Our sales increased 40% after the new website launch. Best investment we've made."</p>
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:44px;height:44px;background:#10b981;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">E</div>
        <div><p style="margin:0;font-weight:600;color:#0f172a">Erik Johansson</p><p style="margin:2px 0 0;color:#94a3b8;font-size:0.85rem">Owner, Nordic Craft</p></div>
      </div>
    </div>
    <div style="background:#f8fafc;border-radius:16px;padding:32px">
      <div style="color:#eab308;font-size:1.2rem;margin-bottom:16px">★★★★★</div>
      <p style="color:#334155;line-height:1.7;margin:0 0 24px;font-style:italic">"Fast, professional, and great communication throughout the entire project."</p>
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:44px;height:44px;background:#f59e0b;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">M</div>
        <div><p style="margin:0;font-weight:600;color:#0f172a">Maria Lindgren</p><p style="margin:2px 0 0;color:#94a3b8;font-size:0.85rem">Marketing Director</p></div>
      </div>
    </div>
  </div>
</div>
HTML;

    $templates['faq'] = <<<HTML
<div style="max-width:700px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">FAQ</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#0f172a;margin:0">Frequently Asked Questions</h2>
  </div>
  <div style="display:flex;flex-direction:column;gap:12px">
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;background:#fff">
      <summary style="font-weight:600;color:#0f172a;cursor:pointer;font-size:1.05rem">How do I get started?</summary>
      <p style="color:#64748b;line-height:1.6;margin:12px 0 0">Simply reach out to us through the contact form or give us a call. We'll schedule a free consultation to discuss your needs.</p>
    </details>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;background:#fff">
      <summary style="font-weight:600;color:#0f172a;cursor:pointer;font-size:1.05rem">How long does a project take?</summary>
      <p style="color:#64748b;line-height:1.6;margin:12px 0 0">Most projects are completed within 2-4 weeks. Complex e-commerce sites may take 4-8 weeks.</p>
    </details>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;background:#fff">
      <summary style="font-weight:600;color:#0f172a;cursor:pointer;font-size:1.05rem">Do you offer support after launch?</summary>
      <p style="color:#64748b;line-height:1.6;margin:12px 0 0">Yes! All plans include ongoing support. We're here to help you succeed.</p>
    </details>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;background:#fff">
      <summary style="font-weight:600;color:#0f172a;cursor:pointer;font-size:1.05rem">Can I update the website myself?</summary>
      <p style="color:#64748b;line-height:1.6;margin:12px 0 0">Absolutely. We build on WordPress so you can easily update content, add products, and manage your site.</p>
    </details>
  </div>
</div>
HTML;

    $templates['stats-counter'] = <<<HTML
<div style="background:linear-gradient(135deg,{$brand} 0%,#0f172a 100%);padding:80px 24px">
  <div style="max-width:1000px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:32px;text-align:center">
    <div>
      <p style="font-size:3rem;font-weight:800;color:#fff;margin:0">500+</p>
      <p style="color:rgba(255,255,255,0.7);margin:8px 0 0;font-size:1rem">Happy Clients</p>
    </div>
    <div>
      <p style="font-size:3rem;font-weight:800;color:#fff;margin:0">1,200+</p>
      <p style="color:rgba(255,255,255,0.7);margin:8px 0 0;font-size:1rem">Projects Delivered</p>
    </div>
    <div>
      <p style="font-size:3rem;font-weight:800;color:#fff;margin:0">99%</p>
      <p style="color:rgba(255,255,255,0.7);margin:8px 0 0;font-size:1rem">Client Satisfaction</p>
    </div>
    <div>
      <p style="font-size:3rem;font-weight:800;color:#fff;margin:0">24/7</p>
      <p style="color:rgba(255,255,255,0.7);margin:8px 0 0;font-size:1rem">Support Available</p>
    </div>
  </div>
</div>
HTML;

    $templates['newsletter'] = <<<HTML
<div style="max-width:600px;margin:80px auto;padding:48px 36px;background:linear-gradient(135deg,#f8fafc,#eef2ff);border-radius:20px;text-align:center;font-family:system-ui,sans-serif">
  <p style="font-size:2.5rem;margin:0 0 12px">📬</p>
  <h2 style="font-size:1.8rem;font-weight:800;color:#0f172a;margin:0 0 12px">Stay Updated</h2>
  <p style="color:#64748b;line-height:1.6;margin:0 0 32px">Get the latest news, tips, and exclusive offers straight to your inbox. No spam, ever.</p>
  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
    <input type="email" placeholder="Enter your email" style="flex:1;min-width:200px;padding:14px 20px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none">
    <button style="padding:14px 28px;background:{$brand};color:#fff;border:none;border-radius:10px;font-weight:600;font-size:1rem;cursor:pointer">Subscribe</button>
  </div>
  <p style="color:#94a3b8;font-size:0.8rem;margin:16px 0 0">Join 1,000+ subscribers. Unsubscribe anytime.</p>
</div>
HTML;

    $templates['team'] = <<<HTML
<div style="max-width:1000px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Team</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#0f172a;margin:0">Meet the Team</h2>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px">
    <div style="text-align:center;padding:32px 20px;background:#f8fafc;border-radius:16px">
      <div style="width:80px;height:80px;background:linear-gradient(135deg,{$brand},#818cf8);border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;font-weight:700">C</div>
      <h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 4px">Christos</h3>
      <p style="color:{$brand};font-size:0.85rem;margin:0 0 12px;font-weight:500">Founder & Developer</p>
      <p style="color:#64748b;font-size:0.9rem;line-height:1.5;margin:0">Full-stack developer with a passion for clean code.</p>
    </div>
    <div style="text-align:center;padding:32px 20px;background:#f8fafc;border-radius:16px">
      <div style="width:80px;height:80px;background:linear-gradient(135deg,#10b981,#34d399);border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;font-weight:700">D</div>
      <h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 4px">Daniel</h3>
      <p style="color:#10b981;font-size:0.85rem;margin:0 0 12px;font-weight:500">Co-Founder & Designer</p>
      <p style="color:#64748b;font-size:0.9rem;line-height:1.5;margin:0">Creative designer with an eye for details.</p>
    </div>
    <div style="text-align:center;padding:32px 20px;background:#f8fafc;border-radius:16px">
      <div style="width:80px;height:80px;background:linear-gradient(135deg,#f59e0b,#fbbf24);border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;font-weight:700">AI</div>
      <h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 4px">WPilot AI</h3>
      <p style="color:#f59e0b;font-size:0.85rem;margin:0 0 12px;font-weight:500">AI Assistant</p>
      <p style="color:#64748b;font-size:0.9rem;line-height:1.5;margin:0">Powered by Claude. Available 24/7.</p>
    </div>
  </div>
</div>
HTML;

    $templates['features'] = <<<HTML
<div style="max-width:1100px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Features</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#0f172a;margin:0 0 16px">Everything You Need</h2>
    <p style="color:#64748b;font-size:1.1rem;margin:0 auto;max-width:550px">Powerful features designed to help your business grow online.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px">
    <div style="padding:24px;border-radius:12px;border:1px solid #f1f5f9"><p style="font-size:1.4rem;margin:0 0 12px">⚡</p><h4 style="margin:0 0 8px;color:#0f172a">Lightning Fast</h4><p style="color:#64748b;margin:0;font-size:0.9rem;line-height:1.5">Optimized for speed with caching and lazy loading.</p></div>
    <div style="padding:24px;border-radius:12px;border:1px solid #f1f5f9"><p style="font-size:1.4rem;margin:0 0 12px">🔒</p><h4 style="margin:0 0 8px;color:#0f172a">Secure</h4><p style="color:#64748b;margin:0;font-size:0.9rem;line-height:1.5">Enterprise-grade security with SSL and firewalls.</p></div>
    <div style="padding:24px;border-radius:12px;border:1px solid #f1f5f9"><p style="font-size:1.4rem;margin:0 0 12px">📱</p><h4 style="margin:0 0 8px;color:#0f172a">Responsive</h4><p style="color:#64748b;margin:0;font-size:0.9rem;line-height:1.5">Looks great on every device and screen size.</p></div>
    <div style="padding:24px;border-radius:12px;border:1px solid #f1f5f9"><p style="font-size:1.4rem;margin:0 0 12px">🎯</p><h4 style="margin:0 0 8px;color:#0f172a">SEO Ready</h4><p style="color:#64748b;margin:0;font-size:0.9rem;line-height:1.5">Built-in SEO optimization for Google rankings.</p></div>
    <div style="padding:24px;border-radius:12px;border:1px solid #f1f5f9"><p style="font-size:1.4rem;margin:0 0 12px">🛒</p><h4 style="margin:0 0 8px;color:#0f172a">E-Commerce</h4><p style="color:#64748b;margin:0;font-size:0.9rem;line-height:1.5">Sell products with WooCommerce integration.</p></div>
    <div style="padding:24px;border-radius:12px;border:1px solid #f1f5f9"><p style="font-size:1.4rem;margin:0 0 12px">🤖</p><h4 style="margin:0 0 8px;color:#0f172a">AI Powered</h4><p style="color:#64748b;margin:0;font-size:0.9rem;line-height:1.5">WPilot AI helps manage everything via chat.</p></div>
  </div>
</div>
HTML;

    $templates['comparison-table'] = <<<HTML
<div style="max-width:1000px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Compare Plans</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#1e293b;margin:0 0 16px">Feature Comparison</h2>
    <p style="color:#64748b;font-size:1.1rem;margin:0">See exactly what each plan includes.</p>
  </div>
  <div style="overflow-x:auto;border-radius:16px;border:1px solid #e2e8f0">
    <table style="width:100%;border-collapse:collapse;background:#fff;font-size:0.95rem">
      <thead>
        <tr style="background:#f8fafc">
          <th style="text-align:left;padding:20px 24px;font-weight:700;color:#1e293b;border-bottom:2px solid #e2e8f0;min-width:200px">Feature</th>
          <th style="text-align:center;padding:20px 24px;font-weight:700;color:#64748b;border-bottom:2px solid #e2e8f0;min-width:120px">Starter</th>
          <th style="text-align:center;padding:20px 24px;font-weight:700;color:{$brand};border-bottom:2px solid {$brand};min-width:120px">Professional</th>
          <th style="text-align:center;padding:20px 24px;font-weight:700;color:#1e293b;border-bottom:2px solid #e2e8f0;min-width:120px">Enterprise</th>
        </tr>
      </thead>
      <tbody>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">Custom Domain</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem;background:#f8faff">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">SSL Certificate</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem;background:#f8faff">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">WooCommerce Store</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem;background:#f8faff">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">Advanced SEO Tools</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem;background:#f8faff">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">Priority Support</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem;background:#f8faff">&#10003;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">Custom Integrations</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem;background:#f8faff">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;color:#334155">Dedicated Manager</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#ef4444;font-size:1.2rem;background:#f8faff">&#10007;</td><td style="padding:16px 24px;border-bottom:1px solid #f1f5f9;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
        <tr><td style="padding:16px 24px;color:#334155">SLA Guarantee</td><td style="padding:16px 24px;text-align:center;color:#ef4444;font-size:1.2rem">&#10007;</td><td style="padding:16px 24px;text-align:center;color:#ef4444;font-size:1.2rem;background:#f8faff">&#10007;</td><td style="padding:16px 24px;text-align:center;color:#22c55e;font-size:1.2rem">&#10003;</td></tr>
      </tbody>
      <tfoot>
        <tr style="background:#f8fafc">
          <td style="padding:20px 24px;font-weight:700;color:#1e293b;border-top:2px solid #e2e8f0">Price</td>
          <td style="padding:20px 24px;text-align:center;font-weight:700;color:#1e293b;border-top:2px solid #e2e8f0">$19/mo</td>
          <td style="padding:20px 24px;text-align:center;font-weight:700;color:{$brand};border-top:2px solid {$brand};background:#f8faff">$49/mo</td>
          <td style="padding:20px 24px;text-align:center;font-weight:700;color:#1e293b;border-top:2px solid #e2e8f0">$149/mo</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
HTML;

    $templates['portfolio'] = <<<HTML
<div style="max-width:1100px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Portfolio</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#1e293b;margin:0 0 16px">Our Latest Work</h2>
    <p style="color:#64748b;font-size:1.1rem;margin:0">A selection of projects we're proud of.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px">
    <div style="position:relative;border-radius:16px;overflow:hidden;aspect-ratio:4/3;background:linear-gradient(135deg,#1e293b 0%,#334155 100%);cursor:pointer">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128247;</span>
      </div>
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,transparent 60%);display:flex;flex-direction:column;justify-content:flex-end;padding:28px;opacity:0;transition:opacity 0.3s" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
        <h3 style="color:#fff;font-size:1.2rem;font-weight:700;margin:0 0 6px">E-Commerce Redesign</h3>
        <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.9rem">Web Design &middot; WooCommerce</p>
      </div>
    </div>
    <div style="position:relative;border-radius:16px;overflow:hidden;aspect-ratio:4/3;background:linear-gradient(135deg,{$brand}33 0%,{$brand}11 100%);cursor:pointer">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128247;</span>
      </div>
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,transparent 60%);display:flex;flex-direction:column;justify-content:flex-end;padding:28px;opacity:0;transition:opacity 0.3s" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
        <h3 style="color:#fff;font-size:1.2rem;font-weight:700;margin:0 0 6px">Brand Identity</h3>
        <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.9rem">Branding &middot; Design</p>
      </div>
    </div>
    <!-- Built by Christos Ferlachidis & Daniel Hedenberg -->
    <div style="position:relative;border-radius:16px;overflow:hidden;aspect-ratio:4/3;background:linear-gradient(135deg,#0f766e22 0%,#14b8a611 100%);cursor:pointer">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128247;</span>
      </div>
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,transparent 60%);display:flex;flex-direction:column;justify-content:flex-end;padding:28px;opacity:0;transition:opacity 0.3s" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
        <h3 style="color:#fff;font-size:1.2rem;font-weight:700;margin:0 0 6px">SaaS Dashboard</h3>
        <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.9rem">UI/UX &middot; Development</p>
      </div>
    </div>
    <div style="position:relative;border-radius:16px;overflow:hidden;aspect-ratio:4/3;background:linear-gradient(135deg,#92400e22 0%,#f59e0b11 100%);cursor:pointer">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128247;</span>
      </div>
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,transparent 60%);display:flex;flex-direction:column;justify-content:flex-end;padding:28px;opacity:0;transition:opacity 0.3s" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
        <h3 style="color:#fff;font-size:1.2rem;font-weight:700;margin:0 0 6px">Mobile App Launch</h3>
        <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.9rem">iOS &middot; Android</p>
      </div>
    </div>
    <div style="position:relative;border-radius:16px;overflow:hidden;aspect-ratio:4/3;background:linear-gradient(135deg,#7c3aed22 0%,#a78bfa11 100%);cursor:pointer">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128247;</span>
      </div>
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,transparent 60%);display:flex;flex-direction:column;justify-content:flex-end;padding:28px;opacity:0;transition:opacity 0.3s" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
        <h3 style="color:#fff;font-size:1.2rem;font-weight:700;margin:0 0 6px">Restaurant Website</h3>
        <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.9rem">Web Design &middot; Booking</p>
      </div>
    </div>
    <div style="position:relative;border-radius:16px;overflow:hidden;aspect-ratio:4/3;background:linear-gradient(135deg,#be123c22 0%,#fb718511 100%);cursor:pointer">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128247;</span>
      </div>
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,transparent 60%);display:flex;flex-direction:column;justify-content:flex-end;padding:28px;opacity:0;transition:opacity 0.3s" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
        <h3 style="color:#fff;font-size:1.2rem;font-weight:700;margin:0 0 6px">Marketing Campaign</h3>
        <p style="color:rgba(255,255,255,0.7);margin:0;font-size:0.9rem">Marketing &middot; SEO</p>
      </div>
    </div>
  </div>
</div>
HTML;

    $templates['coming-soon'] = <<<HTML
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,{$brand} 100%);padding:40px 24px;font-family:system-ui,sans-serif">
  <div style="max-width:600px;text-align:center">
    <div style="width:80px;height:80px;background:rgba(255,255,255,0.1);border-radius:20px;margin:0 auto 32px;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(10px)">
      <span style="font-size:2rem">&#9889;</span>
    </div>
    <h1 style="font-size:clamp(2rem,5vw,3.5rem);font-weight:800;color:#fff;margin:0 0 16px;line-height:1.1">Coming Soon</h1>
    <p style="font-size:1.15rem;color:rgba(255,255,255,0.7);margin:0 0 48px;line-height:1.7">{$site_name} is launching something incredible. Be the first to know when we go live.</p>
    <div style="display:flex;gap:24px;justify-content:center;flex-wrap:wrap;margin-bottom:48px">
      <div style="background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);border-radius:16px;padding:20px 24px;min-width:80px">
        <p style="font-size:2rem;font-weight:800;color:#fff;margin:0" id="cs-days">14</p>
        <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;margin:4px 0 0;text-transform:uppercase;letter-spacing:1px">Days</p>
      </div>
      <div style="background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);border-radius:16px;padding:20px 24px;min-width:80px">
        <p style="font-size:2rem;font-weight:800;color:#fff;margin:0" id="cs-hours">08</p>
        <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;margin:4px 0 0;text-transform:uppercase;letter-spacing:1px">Hours</p>
      </div>
      <div style="background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);border-radius:16px;padding:20px 24px;min-width:80px">
        <p style="font-size:2rem;font-weight:800;color:#fff;margin:0" id="cs-mins">42</p>
        <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;margin:4px 0 0;text-transform:uppercase;letter-spacing:1px">Minutes</p>
      </div>
      <div style="background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);border-radius:16px;padding:20px 24px;min-width:80px">
        <p style="font-size:2rem;font-weight:800;color:#fff;margin:0" id="cs-secs">17</p>
        <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;margin:4px 0 0;text-transform:uppercase;letter-spacing:1px">Seconds</p>
      </div>
    </div>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;max-width:440px;margin:0 auto">
      <input type="email" placeholder="Enter your email" style="flex:1;min-width:200px;padding:16px 20px;border:1px solid rgba(255,255,255,0.2);border-radius:12px;font-size:1rem;background:rgba(255,255,255,0.1);color:#fff;outline:none;backdrop-filter:blur(10px)">
      <button style="padding:16px 32px;background:#fff;color:#0f172a;border:none;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer">Notify Me</button>
    </div>
    <p style="color:rgba(255,255,255,0.4);font-size:0.85rem;margin:24px 0 0">Contact us at {$email}</p>
  </div>
</div>
HTML;

    $templates['restaurant-menu'] = <<<HTML
<div style="max-width:900px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:56px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:3px;margin:0 0 12px">Our Menu</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#1e293b;margin:0 0 16px">{$site_name}</h1>
    <div style="width:60px;height:3px;background:{$brand};margin:0 auto;border-radius:2px"></div>
  </div>
  <div style="margin-bottom:48px">
    <h2 style="font-size:1.4rem;font-weight:700;color:{$brand};margin:0 0 24px;padding-bottom:12px;border-bottom:1px solid #e2e8f0">&#127860; Starters</h2>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Bruschetta</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Toasted ciabatta with fresh tomatoes, basil, garlic, and extra virgin olive oil</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$12</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Caesar Salad</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Crisp romaine, aged parmesan, croutons, and house-made dressing</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$14</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Soup of the Day</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Chef's seasonal selection served with artisan bread</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$10</span>
    </div>
  </div>
  <div style="margin-bottom:48px">
    <h2 style="font-size:1.4rem;font-weight:700;color:{$brand};margin:0 0 24px;padding-bottom:12px;border-bottom:1px solid #e2e8f0">&#127861; Main Courses</h2>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Grilled Salmon</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Atlantic salmon with lemon butter sauce, asparagus, and roasted potatoes</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$28</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Filet Mignon</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">200g grass-fed beef, truffle mash, seasonal vegetables, red wine jus</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$42</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Wild Mushroom Risotto</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Arborio rice with porcini, shiitake, and parmesan, finished with truffle oil</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$24</span>
    </div>
  </div>
  <div style="margin-bottom:48px">
    <h2 style="font-size:1.4rem;font-weight:700;color:{$brand};margin:0 0 24px;padding-bottom:12px;border-bottom:1px solid #e2e8f0">&#127856; Desserts</h2>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Tiramisu</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Classic Italian dessert with espresso-soaked ladyfingers and mascarpone</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$14</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;flex-wrap:wrap;gap:8px">
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.1rem;font-weight:600;color:#1e293b;margin:0">Cr&egrave;me Br&ucirc;l&eacute;e</h3><p style="color:#64748b;margin:4px 0 0;font-size:0.9rem;line-height:1.5">Vanilla bean custard with caramelized sugar crust</p></div>
      <span style="font-weight:700;color:#1e293b;font-size:1.1rem;white-space:nowrap">$12</span>
    </div>
  </div>
  <div style="text-align:center;padding:32px;background:#f8fafc;border-radius:16px">
    <p style="color:#64748b;margin:0;font-size:0.95rem">Reservations: <strong style="color:#1e293b">{$phone}</strong> &middot; {$email}</p>
  </div>
</div>
HTML;

    $templates['booking'] = <<<HTML
<div style="max-width:1000px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Book Now</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#1e293b;margin:0 0 16px">Schedule an Appointment</h1>
    <p style="color:#64748b;font-size:1.1rem;margin:0">Choose a service and pick a time that works for you.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:40px">
    <div>
      <div style="background:#f8fafc;border-radius:16px;padding:32px;margin-bottom:24px">
        <h3 style="font-size:1.1rem;font-weight:700;color:#1e293b;margin:0 0 20px">Select Service</h3>
        <label style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:2px solid {$brand};border-radius:12px;cursor:pointer;margin-bottom:12px">
          <input type="radio" name="service" checked style="accent-color:{$brand};width:18px;height:18px">
          <div style="flex:1"><span style="font-weight:600;color:#1e293b">Consultation</span><p style="margin:2px 0 0;color:#64748b;font-size:0.85rem">30 min &middot; Free</p></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;cursor:pointer;margin-bottom:12px">
          <input type="radio" name="service" style="accent-color:{$brand};width:18px;height:18px">
          <div style="flex:1"><span style="font-weight:600;color:#1e293b">Standard Service</span><p style="margin:2px 0 0;color:#64748b;font-size:0.85rem">60 min &middot; $75</p></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;cursor:pointer;margin-bottom:12px">
          <input type="radio" name="service" style="accent-color:{$brand};width:18px;height:18px">
          <div style="flex:1"><span style="font-weight:600;color:#1e293b">Premium Package</span><p style="margin:2px 0 0;color:#64748b;font-size:0.85rem">90 min &middot; $120</p></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;cursor:pointer">
          <input type="radio" name="service" style="accent-color:{$brand};width:18px;height:18px">
          <div style="flex:1"><span style="font-weight:600;color:#1e293b">VIP Experience</span><p style="margin:2px 0 0;color:#64748b;font-size:0.85rem">120 min &middot; $200</p></div>
        </label>
      </div>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div style="flex:1;text-align:center;padding:20px;background:#f8fafc;border-radius:12px">
          <span style="font-size:1.5rem">&#128222;</span>
          <p style="margin:8px 0 0;font-weight:600;color:#1e293b;font-size:0.9rem">{$phone}</p>
        </div>
        <div style="flex:1;text-align:center;padding:20px;background:#f8fafc;border-radius:12px">
          <span style="font-size:1.5rem">&#128205;</span>
          <p style="margin:8px 0 0;font-weight:600;color:#1e293b;font-size:0.9rem">{$address}</p>
        </div>
      </div>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px">
      <h3 style="font-size:1.1rem;font-weight:700;color:#1e293b;margin:0 0 24px">Your Details</h3>
      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:500;color:#334155;margin-bottom:6px;font-size:0.9rem">Full Name</label>
        <input type="text" placeholder="John Doe" style="width:100%;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;box-sizing:border-box">
      </div>
      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:500;color:#334155;margin-bottom:6px;font-size:0.9rem">Email</label>
        <input type="email" placeholder="john@example.com" style="width:100%;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;box-sizing:border-box">
      </div>
      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:500;color:#334155;margin-bottom:6px;font-size:0.9rem">Phone</label>
        <input type="tel" placeholder="+46 70 123 45 67" style="width:100%;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;box-sizing:border-box">
      </div>
      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:500;color:#334155;margin-bottom:6px;font-size:0.9rem">Preferred Date</label>
        <input type="date" style="width:100%;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;box-sizing:border-box">
      </div>
      <div style="margin-bottom:24px">
        <label style="display:block;font-weight:500;color:#334155;margin-bottom:6px;font-size:0.9rem">Preferred Time</label>
        <select style="width:100%;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;box-sizing:border-box;background:#fff">
          <option>09:00</option><option>10:00</option><option>11:00</option><option>13:00</option><option>14:00</option><option>15:00</option><option>16:00</option>
        </select>
      </div>
      <div style="margin-bottom:24px">
        <label style="display:block;font-weight:500;color:#334155;margin-bottom:6px;font-size:0.9rem">Notes (optional)</label>
        <textarea rows="3" placeholder="Anything we should know..." style="width:100%;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;box-sizing:border-box;resize:vertical"></textarea>
      </div>
      <button style="width:100%;padding:16px;background:{$brand};color:#fff;border:none;border-radius:12px;font-weight:700;font-size:1.05rem;cursor:pointer">Book Appointment</button>
    </div>
  </div>
</div>
HTML;

    $templates['404-custom'] = <<<HTML
<div style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:40px 24px;font-family:system-ui,sans-serif">
  <div style="max-width:600px;text-align:center">
    <p style="font-size:8rem;font-weight:900;color:{$brand};margin:0;line-height:1;opacity:0.15">404</p>
    <h1 style="font-size:2rem;font-weight:800;color:#1e293b;margin:-20px 0 16px">Page Not Found</h1>
    <p style="color:#64748b;font-size:1.1rem;line-height:1.7;margin:0 0 36px">Sorry, the page you're looking for doesn't exist or has been moved. Let's get you back on track.</p>
    <div style="max-width:400px;margin:0 auto 40px">
      <div style="display:flex;gap:8px">
        <input type="text" placeholder="Search {$site_name}..." style="flex:1;padding:14px 20px;border:1px solid #e2e8f0;border-radius:12px;font-size:1rem;outline:none">
        <button style="padding:14px 24px;background:{$brand};color:#fff;border:none;border-radius:12px;font-weight:600;cursor:pointer">Search</button>
      </div>
    </div>
    <div style="text-align:left;background:#f8fafc;border-radius:16px;padding:28px 32px">
      <p style="font-weight:700;color:#1e293b;margin:0 0 16px;font-size:0.95rem">Popular Pages</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <a href="/" style="color:{$brand};text-decoration:none;font-weight:500;display:flex;align-items:center;gap:8px">&#8594; Home</a>
        <a href="/about" style="color:{$brand};text-decoration:none;font-weight:500;display:flex;align-items:center;gap:8px">&#8594; About Us</a>
        <a href="/services" style="color:{$brand};text-decoration:none;font-weight:500;display:flex;align-items:center;gap:8px">&#8594; Services</a>
        <a href="/contact" style="color:{$brand};text-decoration:none;font-weight:500;display:flex;align-items:center;gap:8px">&#8594; Contact</a>
        <a href="/blog" style="color:{$brand};text-decoration:none;font-weight:500;display:flex;align-items:center;gap:8px">&#8594; Blog</a>
      </div>
    </div>
    <p style="color:#94a3b8;margin:32px 0 0;font-size:0.9rem">Need help? Contact us at <a href="mailto:{$email}" style="color:{$brand};text-decoration:none">{$email}</a></p>
  </div>
</div>
HTML;

    $templates['thank-you'] = <<<HTML
<div style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:40px 24px;font-family:system-ui,sans-serif">
  <div style="max-width:550px;text-align:center">
    <div style="width:80px;height:80px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;margin:0 auto 28px;display:flex;align-items:center;justify-content:center">
      <span style="color:#fff;font-size:2.5rem;line-height:1">&#10003;</span>
    </div>
    <h1 style="font-size:2.2rem;font-weight:800;color:#1e293b;margin:0 0 16px">Thank You!</h1>
    <p style="color:#64748b;font-size:1.1rem;line-height:1.7;margin:0 0 12px">Your submission has been received successfully. We appreciate you reaching out to {$site_name}.</p>
    <p style="color:#64748b;font-size:1rem;line-height:1.7;margin:0 0 40px">We'll get back to you within 24 hours at the email address you provided.</p>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:16px;padding:24px 28px;margin-bottom:36px;text-align:left">
      <p style="font-weight:600;color:#166534;margin:0 0 12px;font-size:0.95rem">What happens next?</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;align-items:center;gap:12px"><div style="width:28px;height:28px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.8rem;flex-shrink:0">1</div><span style="color:#334155;font-size:0.95rem">We review your request</span></div>
        <div style="display:flex;align-items:center;gap:12px"><div style="width:28px;height:28px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.8rem;flex-shrink:0">2</div><span style="color:#334155;font-size:0.95rem">A team member contacts you</span></div>
        <div style="display:flex;align-items:center;gap:12px"><div style="width:28px;height:28px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.8rem;flex-shrink:0">3</div><span style="color:#334155;font-size:0.95rem">We start working together</span></div>
      </div>
    </div>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a href="/" style="display:inline-block;padding:14px 32px;background:{$brand};color:#fff;font-weight:600;border-radius:12px;text-decoration:none">Back to Home</a>
      <a href="/services" style="display:inline-block;padding:14px 32px;background:#f1f5f9;color:#334155;font-weight:600;border-radius:12px;text-decoration:none">View Services</a>
    </div>
  </div>
</div>
HTML;

    $templates['blog-layout'] = <<<HTML
<div style="max-width:1100px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Blog</p>
    <h1 style="font-size:2.5rem;font-weight:800;color:#1e293b;margin:0 0 16px">Latest Articles</h1>
    <p style="color:#64748b;font-size:1.1rem;margin:0">Insights, tips, and stories from our team.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:28px">
    <article style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;transition:box-shadow 0.3s">
      <div style="height:200px;background:linear-gradient(135deg,{$brand}22 0%,{$brand}08 100%);display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128240;</span>
      </div>
      <div style="padding:28px">
        <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap">
          <span style="background:{$brand}15;color:{$brand};padding:4px 12px;border-radius:20px;font-size:0.8rem;font-weight:600">Design</span>
          <span style="color:#94a3b8;font-size:0.85rem">March 15, 2026</span>
        </div>
        <h3 style="font-size:1.2rem;font-weight:700;color:#1e293b;margin:0 0 10px;line-height:1.4">10 Web Design Trends Dominating 2026</h3>
        <p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0 0 20px">Discover the latest design trends that are shaping the future of the web and how to implement them.</p>
        <a href="#" style="color:{$brand};font-weight:600;text-decoration:none;font-size:0.95rem">Read More &#8594;</a>
      </div>
    </article>
    <article style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;transition:box-shadow 0.3s">
      <div style="height:200px;background:linear-gradient(135deg,#0f766e22 0%,#14b8a611 100%);display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128240;</span>
      </div>
      <div style="padding:28px">
        <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap">
          <span style="background:#14b8a615;color:#0f766e;padding:4px 12px;border-radius:20px;font-size:0.8rem;font-weight:600">SEO</span>
          <span style="color:#94a3b8;font-size:0.85rem">March 10, 2026</span>
        </div>
        <h3 style="font-size:1.2rem;font-weight:700;color:#1e293b;margin:0 0 10px;line-height:1.4">The Complete SEO Guide for Small Businesses</h3>
        <p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0 0 20px">Everything you need to know about ranking higher on Google without hiring an expensive agency.</p>
        <a href="#" style="color:{$brand};font-weight:600;text-decoration:none;font-size:0.95rem">Read More &#8594;</a>
      </div>
    </article>
    <article style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;transition:box-shadow 0.3s">
      <div style="height:200px;background:linear-gradient(135deg,#7c3aed22 0%,#a78bfa11 100%);display:flex;align-items:center;justify-content:center">
        <span style="font-size:3rem;opacity:0.3">&#128240;</span>
      </div>
      <div style="padding:28px">
        <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap">
          <span style="background:#7c3aed15;color:#7c3aed;padding:4px 12px;border-radius:20px;font-size:0.8rem;font-weight:600">Business</span>
          <span style="color:#94a3b8;font-size:0.85rem">March 5, 2026</span>
        </div>
        <h3 style="font-size:1.2rem;font-weight:700;color:#1e293b;margin:0 0 10px;line-height:1.4">Why Every Business Needs a Professional Website</h3>
        <p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0 0 20px">Studies show 75% of consumers judge credibility based on web design. Here's what that means for you.</p>
        <a href="#" style="color:{$brand};font-weight:600;text-decoration:none;font-size:0.95rem">Read More &#8594;</a>
      </div>
    </article>
  </div>
  <div style="text-align:center;margin-top:48px">
    <a href="/blog" style="display:inline-block;padding:14px 36px;border:2px solid {$brand};color:{$brand};font-weight:600;border-radius:12px;text-decoration:none;font-size:1rem">View All Articles</a>
  </div>
</div>
HTML;

    $templates['image-gallery'] = <<<HTML
<div style="max-width:1200px;margin:80px auto;padding:0 24px;font-family:system-ui,sans-serif">
  <div style="text-align:center;margin-bottom:48px">
    <p style="color:{$brand};font-weight:600;font-size:0.9rem;text-transform:uppercase;letter-spacing:2px;margin:0 0 12px">Gallery</p>
    <h2 style="font-size:2.2rem;font-weight:800;color:#1e293b;margin:0 0 16px">Photo Gallery</h2>
    <p style="color:#64748b;font-size:1.1rem;margin:0">A visual showcase of our work and moments.</p>
  </div>
  <style>
    .wpilot-gallery{columns:3;column-gap:16px}
    @media(max-width:768px){.wpilot-gallery{columns:2}}
    @media(max-width:480px){.wpilot-gallery{columns:1}}
    .wpilot-gallery-item{break-inside:avoid;margin-bottom:16px;border-radius:12px;overflow:hidden;position:relative;cursor:pointer}
    .wpilot-gallery-item:hover .wpilot-gallery-overlay{opacity:1}
    .wpilot-gallery-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.3s}
    .wpilot-gallery-overlay span{color:#fff;font-size:2rem}
    .wpilot-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:99999;align-items:center;justify-content:center;cursor:pointer}
    .wpilot-lightbox.active{display:flex}
    .wpilot-lightbox img{max-width:90%;max-height:90vh;border-radius:8px}
  </style>
  <div class="wpilot-gallery">
    <div class="wpilot-gallery-item" style="height:280px;background:linear-gradient(135deg,{$brand}33,{$brand}11)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:200px;background:linear-gradient(135deg,#0f766e33,#14b8a611)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:320px;background:linear-gradient(135deg,#7c3aed33,#a78bfa11)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:240px;background:linear-gradient(135deg,#f59e0b33,#fbbf2411)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:300px;background:linear-gradient(135deg,#be123c33,#fb718511)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:220px;background:linear-gradient(135deg,#1e293b,#334155)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:260px;background:linear-gradient(135deg,{$brand}22,#818cf822)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:340px;background:linear-gradient(135deg,#05966933,#34d39911)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
    <div class="wpilot-gallery-item" style="height:200px;background:linear-gradient(135deg,#dc262633,#f8717111)"><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><span style="font-size:2.5rem;opacity:0.3">&#128247;</span></div><div class="wpilot-gallery-overlay"><span>&#128269;</span></div></div>
  </div>
</div>
HTML;

    $templates['video-hero'] = <<<HTML
<div style="position:relative;min-height:90vh;display:flex;align-items:center;justify-content:center;overflow:hidden;font-family:system-ui,sans-serif">
  <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0f172a 0%,#1e293b 40%,{$brand}88 100%);z-index:1"></div>
  <div style="position:absolute;inset:0;background:url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><defs><pattern id=%22g%22 width=%2210%22 height=%2210%22 patternUnits=%22userSpaceOnUse%22><circle cx=%225%22 cy=%225%22 r=%220.5%22 fill=%22rgba(255,255,255,0.05)%22/></pattern></defs><rect fill=%22url(%23g)%22 width=%22100%22 height=%22100%22/></svg>');z-index:2"></div>
  <!-- Video placeholder: replace src with your video URL -->
  <div style="position:absolute;inset:0;z-index:0;background:#0f172a">
    <!-- <video autoplay muted loop playsinline style="width:100%;height:100%;object-fit:cover;opacity:0.4"><source src="your-video.mp4" type="video/mp4"></video> -->
  </div>
  <div style="position:relative;z-index:3;text-align:center;max-width:800px;padding:40px 24px">
    <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);padding:8px 20px;border-radius:30px;margin-bottom:28px">
      <div style="width:8px;height:8px;background:#22c55e;border-radius:50%;animation:pulse 2s infinite"></div>
      <span style="color:rgba(255,255,255,0.8);font-size:0.85rem;font-weight:500">Now Accepting New Clients</span>
    </div>
    <h1 style="font-size:clamp(2.5rem,6vw,4.5rem);font-weight:900;color:#fff;margin:0 0 24px;line-height:1.05;letter-spacing:-0.02em">{$site_name}</h1>
    <p style="font-size:1.25rem;color:rgba(255,255,255,0.75);margin:0 0 44px;line-height:1.7;max-width:600px;margin-left:auto;margin-right:auto">Premium digital experiences crafted with precision. We transform visions into stunning, high-performing websites.</p>
    <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="#contact" style="display:inline-flex;align-items:center;gap:8px;padding:18px 40px;background:#fff;color:#0f172a;font-weight:700;border-radius:14px;text-decoration:none;font-size:1.1rem">Get Started <span>&#8594;</span></a>
      <a href="#" style="display:inline-flex;align-items:center;gap:10px;padding:18px 40px;background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);color:#fff;font-weight:600;border:1px solid rgba(255,255,255,0.2);border-radius:14px;text-decoration:none;font-size:1.1rem"><span style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.9rem">&#9654;</span> Watch Reel</a>
    </div>
    <div style="display:flex;gap:40px;justify-content:center;margin-top:64px;flex-wrap:wrap">
      <div><p style="font-size:1.8rem;font-weight:800;color:#fff;margin:0">500+</p><p style="color:rgba(255,255,255,0.5);margin:4px 0 0;font-size:0.85rem">Projects</p></div>
      <div><p style="font-size:1.8rem;font-weight:800;color:#fff;margin:0">99%</p><p style="color:rgba(255,255,255,0.5);margin:4px 0 0;font-size:0.85rem">Satisfaction</p></div>
      <div><p style="font-size:1.8rem;font-weight:800;color:#fff;margin:0">12+</p><p style="color:rgba(255,255,255,0.5);margin:4px 0 0;font-size:0.85rem">Years</p></div>
    </div>
  </div>
  <style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}</style>
</div>
HTML;

    // Return requested template or empty
    $name = strtolower(str_replace([' ', '_'], '-', $name));
    return $templates[$name] ?? '';
}
