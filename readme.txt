=== WPilot Lite — AI WordPress Assistant ===
Contributors: weblease
Tags: ai, claude, mcp, assistant, site management
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude to your WordPress site. Create pages, manage menus, edit content — just by talking.

== Description ==

WPilot Lite connects your WordPress site to Claude (by Anthropic) via the Model Context Protocol (MCP). Once connected, you can manage your site through natural conversation.

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

Yes. WPilot runs entirely on your WordPress site. Your data never leaves your server. The connection between Claude and your site is authenticated with secure tokens.

= What can Claude do with my site? =

Claude can create and edit pages, manage menus, update settings, moderate comments, add custom CSS, manage your media library, analyze SEO, and view WooCommerce data. Claude sends PHP code through the MCP endpoint, which is executed in a secure sandbox with blocked dangerous functions, file system access, and shell commands. All requests require token authentication.

= Does this plugin contact external servers? =

Yes. WPilot Lite contacts the Weblease API (weblease.se) for security validation and AI prompt generation. Your site URL is sent to verify requests. No personal data is transmitted. See the "External Services" section below for full details.

= What is the difference between Lite and Pro? =

Lite covers content management, menus, settings, media, basic design, SEO analysis, and WooCommerce views. Pro adds an AI Chat Agent for your visitors, advanced SEO expert prompts, smart system prompts, knowledge base, and priority support.

== Screenshots ==

1. WPilot admin dashboard — connect Claude to your site
2. Creating a secure connection token
3. Claude managing your WordPress site through conversation

== External Services ==

This plugin connects to the following external services:

= Weblease License API =

**URL:** https://weblease.se/api/ai-license/validate
**When:** Only when a user manually enters a Pro license key to upgrade.
**What is sent:** The license key and site URL.
**What is received:** Whether the license is valid and what plan it belongs to.
**Why:** To validate Pro license keys purchased from weblease.se.
**Privacy:** No personal data is transmitted beyond the license key and site URL.
**Terms of Service:** https://weblease.se/terms
**Privacy Policy:** https://weblease.se/privacy

== Changelog ==

= 1.0.0 =
* Initial release
* 35 WordPress management actions via MCP
* OAuth 2.1 with PKCE for secure Claude connection
* Token-based authentication with SHA-256 hashing
* Profile setup and onboarding wizard
* Admin interface for connection management

== Upgrade Notice ==

= 1.0.0 =
Initial release of WPilot Lite.
