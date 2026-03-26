=== WPilot Lite — AI WordPress Assistant ===
Contributors: weblease
Tags: ai, claude, mcp, wordpress assistant, site management
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude AI to your WordPress site. Manage content, design, SEO, WooCommerce, and more — just by chatting.

== Description ==

WPilot Lite connects [Claude AI](https://claude.ai) to your WordPress site using the Model Context Protocol (MCP). Once connected, you can manage your entire website by chatting naturally with Claude.

**What you can do:**

* **Content & Pages** — Create pages, write blog posts, update content
* **Design & Styling** — Change colors, add sections, customize layouts
* **SEO** — Optimize meta descriptions, analyze keywords, improve rankings
* **WooCommerce** — Add products, create coupons, check orders
* **Security** — Check for outdated plugins, review admin users
* **Performance** — Analyze load times, clean up revisions, optimize images

**How it works:**

1. Install the plugin
2. Create a connection token in the admin panel
3. Connect Claude Desktop or Claude Code using the provided instructions
4. Start chatting about your website — Claude does the rest

**Free tier includes:**

* 20 requests per day
* 3 connection tokens
* Full WordPress API access
* Works with Claude Desktop and Claude Code

**Want more?** [WPilot Pro](https://weblease.se/wpilot) offers unlimited requests, unlimited connections, priority support, and the Chat Agent — an AI-powered customer service widget for your visitors.

== Installation ==

1. Upload the `wpilot-lite` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **WPilot > Connect** in the admin menu
4. Follow the setup guide to connect Claude

**Requirements:**

* WordPress 6.0 or higher
* PHP 8.0 or higher
* An Anthropic account (free at [claude.ai](https://claude.ai))
* Claude Desktop app or Claude Code CLI

== Frequently Asked Questions ==

= Do I need a paid Claude subscription? =

No. WPilot works with any Anthropic account, including the free tier.

= What is MCP? =

MCP (Model Context Protocol) is an open protocol that lets AI assistants connect to external tools. WPilot uses MCP to give Claude access to your WordPress API.

= Is my data safe? =

Yes. WPilot runs on your server. Claude connects through authenticated tokens you create and control. Revoke any token at any time.

= What can Claude access? =

All WordPress functions through a sandboxed PHP environment. Shell commands, credential access, filesystem operations, and destructive SQL are blocked by comprehensive security checks.

= Can I use this with WooCommerce? =

Yes. Claude can manage products, orders, coupons, categories, and more.

= Simple vs Technical mode? =

Simple: plain language, no code. Technical: includes IDs, function names, code refs.

= How do I upgrade to Pro? =

Visit [weblease.se/wpilot-checkout](https://weblease.se/wpilot-checkout), get a key, enter it on the Plan page.

= How does code execution work? =

WPilot executes WordPress PHP code sent by Claude AI through the MCP protocol. This is the core functionality — it allows Claude to manage your WordPress site. All code passes through a security sandbox that blocks dangerous operations (shell commands, file system access, credential reading). Only authenticated admin users with valid MCP tokens can trigger code execution.

= Translations =

WPilot Lite is translation-ready. A `/languages` directory is included. Translators are welcome to contribute .po/.mo files.

== Screenshots ==

1. Connect page — Setup instructions for Claude Desktop and Claude Code
2. Settings page — Site profile configuration
3. Plan page — Usage overview and pricing
4. Help page — Examples and feedback form

== Changelog ==

= 1.0.0 =
* Initial release
* MCP endpoint (JSON-RPC 2.0) for Claude communication
* Token authentication with Simple and Technical modes
* PHP sandbox with comprehensive security checks
* Site profile settings (owner, business, tone, language)
* License activation for Pro upgrade
* Admin UI: Connect, Plan, Settings, Help pages
* Full i18n support with wpilot-lite text domain

== Upgrade Notice ==

= 1.0.0 =
Initial release. Connect Claude AI to your WordPress site.

== External Services ==

This plugin connects to external services operated by Weblease (weblease.se):

**1. Usage API** — `https://weblease.se/api/wpilot/usage`
* When: Each tool execution (cached 60 seconds)
* Sends: license key (if any), site URL, action type
* Receives: allowed (bool), remaining quota, plan type
* Purpose: Enforce daily limits (20/day free, unlimited Pro)

**2. License Validation** — `https://weblease.se/ai-license/validate`
* When: Checking license status (cached 1 hour)
* Sends: license key, site URL, plugin ID, version
* Receives: valid (bool)
* Purpose: Verify Pro license keys

Privacy policy: [https://weblease.se/privacy](https://weblease.se/privacy)
Terms of service: [https://weblease.se/terms](https://weblease.se/terms)
