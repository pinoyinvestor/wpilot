=== WPilot Lite — AI Site Assistant ===
Contributors: weblease
Tags: ai, claude, mcp, assistant, site management
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude to your WordPress site. Create pages, manage menus, edit content — just by talking to Claude.

== Description ==

WPilot Lite connects your WordPress site to Claude AI. Once connected, you can manage your site through natural conversation — just type what you want and Claude does it.

**What you can do:**

* Create, edit, and delete pages, posts, and custom post types
* Build and manage navigation menus
* Organize content with categories and tags
* Update site title, tagline, and reading settings
* Browse and update your media library
* Add custom CSS for design changes
* Moderate comments
* Analyze your site's SEO health
* View WooCommerce products and orders
* Check database health and plugin status

**How it works:**

1. Install WPilot Lite on your WordPress site
2. Go to the WPilot Lite settings page
3. Complete the quick profile setup
4. Connect Claude Desktop or Claude CLI using the provided URL
5. Start talking — Claude manages your site for you

**Requirements:**

* A Claude subscription (Pro, Max, or Team) from [claude.ai](https://claude.ai)
* WordPress 6.0 or higher
* PHP 8.0 or higher

**Want more?**

WPilot Pro adds full WooCommerce management, automated SEO fixes, page builder support (Elementor, Divi), database cleanup, bulk operations, and an AI chat agent for your visitors. Visit [weblease.se/wpilot](https://weblease.se/wpilot) for details.

== Installation ==

1. Upload the `wpilot-lite` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to WPilot Lite in the admin sidebar
4. Complete the profile setup
5. Connect Claude using the URL shown on the settings page

== Frequently Asked Questions ==

= Do I need a Claude subscription? =

Yes. WPilot connects Claude to your site — you need a Claude subscription from [claude.ai](https://claude.ai) to use it.

= Is my site data safe? =

Yes. Yes. Your data stays on your own server — nothing is stored on our servers. The connection between Claude and your site is protected with encrypted tokens that only you control.

= What can Claude do with my site? =

Claude can create and edit pages, manage menus, update settings, moderate comments, add custom CSS, manage your media library, analyze SEO, and view WooCommerce data. Claude makes changes through secure WordPress functions. Dangerous operations are automatically blocked. Your passwords, files, and server are always protected.

= Does this plugin contact external servers? =

Yes, for essential functionality only:

1. **weblease.se/api/wpilot/sandbox-check** — validates code safety before execution (security layer)
2. **weblease.se/ai-license/auto-match** — checks if a Pro license exists for your site

No site content, user data, or personal information is sent. Only technical validation data (code patterns), your site URL, and a hashed (non-reversible) admin email for license matching. See our [Privacy Policy](https://weblease.se/privacy) for details.

= What is the difference between Lite and Pro? =

Lite covers content management, menus, settings, media, basic design, SEO analysis, and read-only WooCommerce views. Pro adds full WooCommerce management, automated SEO fixes, page builder support, database cleanup, bulk operations, and an AI chat agent.

== External Services ==

This plugin connects to **weblease.se** (operated by Weblease, the plugin developer) for the following purposes:

1. **Security validation** — Before executing any WordPress action, the plugin sends the action code to `weblease.se/api/wpilot/sandbox-check` for server-side security validation. This is a critical safety layer that blocks dangerous operations. Only the action code and site URL are sent; no personal data, site content, or credentials are transmitted.

2. **License matching** — On first connection, the plugin checks `weblease.se/ai-license/auto-match` to determine if a Pro license exists for the site. Only the site URL and a hashed (non-reversible) admin email are sent.

* Service provider: Weblease (weblease.se)
* Privacy policy: [https://weblease.se/privacy](https://weblease.se/privacy)
* Terms of service: [https://weblease.se/terms](https://weblease.se/terms)

== Screenshots ==

1. WPilot admin dashboard — connect Claude to your site
2. Creating a secure connection token
3. Claude managing your WordPress site through conversation

== Changelog ==

= 1.0.0 =
* Initial release
* Full WordPress management through conversation
* OAuth 2.1 with PKCE for secure Claude connection
* Token-based authentication with SHA-256 hashing
* Profile setup and onboarding wizard
* Admin interface for connection management

== Upgrade Notice ==

= 1.0.0 =
Initial release of WPilot Lite.
