=== WPilot - AI Website Builder for WordPress ===
Contributors: weblease
Tags: ai, website builder, seo, woocommerce, design
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 3.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build, design, and manage your entire WordPress site with AI. Connect Claude and start building — no coding required.

== Description ==

WPilot is an AI-powered WordPress assistant that connects to Claude AI via MCP (Model Context Protocol). Use Claude Code, Claude Desktop, Cursor, or any MCP-compatible client to manage your entire site through natural conversation.

**What can WPilot do?**

* **Build pages** — Create complete pages with content, images, and layouts
* **Design** — Change colors, fonts, spacing, and apply design blueprints
* **SEO** — Full audit, fix meta titles, descriptions, schema markup, and broken links
* **WooCommerce** — Set up products, categories, shipping, payments, and checkout
* **Content** — Write, edit, and translate posts and pages
* **Plugins** — Install, activate, and configure WordPress plugins
* **Security** — Scan for vulnerabilities, add headers, configure firewalls
* **Media** — Upload, optimize, and manage images
* **Forms** — Create and configure contact forms
* **GDPR** — Privacy policy generation, cookie consent, data management
// Built by Weblease
**How it works:**

1. Install WPilot on your WordPress site
2. Connect Claude Desktop or Claude Code via MCP
3. Ask Claude to build, fix, or improve anything on your site

Every change is reversible with one-click undo. WPilot keeps a full history of all modifications.

**45+ AI tools** organized into logical groups: pages, design, CSS, SEO, WooCommerce, plugins, media, security, forms, content, marketing, and more.

== Installation ==

1. Upload the `wpilot` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to WPilot > Settings to get your MCP connection URL
4. Add the URL to Claude Desktop (Settings > Connectors > Add custom connector) or Claude Code
5. Start building by talking to Claude

**Requirements:**
* WordPress 6.0+
* PHP 7.4+
* A Claude account from [Anthropic](https://anthropic.com)

== Frequently Asked Questions ==

= Do I need coding skills? =
No. Just describe what you want in plain language and WPilot builds it.

= What AI does WPilot use? =
WPilot connects to Claude AI by Anthropic via the MCP protocol. You need your own Claude account.

= Is it safe? Can changes be undone? =
Yes. Every change WPilot makes can be reversed with one click. Full history is kept.

= Does WPilot work with my theme? =
Yes. WPilot works with any WordPress theme and detects your page builder (Gutenberg, Elementor, etc).

= Does it support WooCommerce? =
Yes. WPilot can set up products, shipping, payments, coupons, and configure your entire store.

= What is MCP? =
Model Context Protocol — an open standard that lets AI tools interact with external services. WPilot exposes your WordPress site as an MCP server.

= How much does it cost? =
WPilot has a free tier (20 prompts). Pro is $9/month, Team is $29/month for 3 sites, and Lifetime is a one-time $149 payment.

== Screenshots ==

1. WPilot dashboard in WordPress admin
2. Claude Desktop connected via MCP
3. AI building a page in real-time
4. Full site analysis report
5. WooCommerce setup through conversation

== Changelog ==

= 3.0.0 =
* MCP Server v3 with 45+ grouped tools
* Security hardening (capability checks, input validation, rate limiting)
* Undo system with full site snapshots
* Design blueprints and site recipes
* WooCommerce advanced tools
* GDPR compliance tools
* Marketing and engagement tools
* API key management for MCP clients

= 2.6.0 =
* Plugin activation tracking
* Training data collection
* Stripe integration for licensing

= 2.0.0 =
* Complete rewrite with Claude AI integration
* Chat bubble interface
* SEO analysis tools
* WooCommerce tools

== Upgrade Notice ==

= 3.0.0 =
Major update: MCP v3 with 45+ tools, security improvements, undo system, and design blueprints. Recommended for all users.
