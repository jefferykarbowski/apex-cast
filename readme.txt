=== Apex Cast for WooCommerce ===
Contributors: apexchute
Tags: woocommerce, social media, ai, postiz, automation
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate AI social posts from any WooCommerce product, review them inline, and broadcast to Postiz, Buffer, or your own scheduler.

== Description ==

Apex Cast adds a metabox to the WooCommerce product editor. From there, you can:

* **Generate** AI-drafted social media copy tailored per platform (Facebook, Instagram, Pinterest, Threads, Bluesky, X)
* **Review and edit** drafts inline with character counts and platform-specific guidance
* **Broadcast** approved drafts to your social scheduler (Postiz at v0.1; Buffer + Publer in v0.3)

Bring your own AI provider (Anthropic at v0.1) and your own scheduling backend.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/apex-cast/`, or install through the WordPress plugins screen
2. Activate the plugin through the **Plugins** screen
3. Configure under **Settings → Apex Cast**:
    * Add your Anthropic API key
    * Add your Postiz API key
    * Map each platform to your Postiz integration
4. Open any WooCommerce product and use the **Apex Cast** metabox in the side column

== Frequently Asked Questions ==

= Do I need a Postiz account? =

For v0.1, yes. Postiz is the only supported backend. Buffer and Publer adapters are coming in v0.3.

= Do I need an Anthropic API key? =

For v0.1, yes. The plugin uses Anthropic's Claude models for copy generation. OpenAI and Gemini provider implementations are planned.

= Will my API keys be safe? =

Yes. Sensitive credentials are encrypted at rest using libsodium (or OpenSSL fallback) keyed off your site's `AUTH_KEY`.

== Changelog ==

= 0.1.0 =

* Initial release
* Anthropic AI provider
* Postiz backend adapter
* Product editor metabox
* Settings page

== Upgrade Notice ==

= 0.1.0 =
Initial release.
