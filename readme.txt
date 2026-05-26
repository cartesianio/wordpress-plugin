=== Cartesian Agent ===
Contributors: cartesian
Tags: analytics, tracking, ai, agent, personalization
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Integrates Cartesian's AI-powered agent into WordPress admin pages for enhanced user experience.

== Description ==

**Important: This plugin requires an active Cartesian account and connects to external Cartesian services.**

Cartesian Agent integrates with your WordPress admin dashboard to provide AI-powered personalization and assistance. The plugin connects to Cartesian's service at [https://cartesian.io](https://cartesian.io), enabling intelligent recommendations and user authentication through secure JWT tokens.

**Features:**

* Easy enable/disable control with explicit opt-in
* Secure JWT authentication with automatic key generation
* Configurable agent ID
* Managed mode for centrally-managed deployments
* White-label support with custom branding
* MainWP integration for multi-site management
* Automatic plugin updates via GitHub Releases
* Plugin installation and activation from agent recommendations
* Per-site UI visibility override
* Advanced environment settings

**External Service:**

This plugin connects to Cartesian's servers to provide its functionality. Please review Cartesian's Privacy Policy before enabling:

* Privacy Policy: [https://cartesian.io/privacy-policy](https://cartesian.io/privacy-policy)

== Installation ==

**Prerequisites:**
* An active Cartesian account (sign up at [https://home.cartesian.io](https://home.cartesian.io))
* An API Key from your Cartesian dashboard

**Installation Steps:**

1. Upload the plugin files to the `/wp-content/plugins/cartesian-agent` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Settings > Cartesian Agent to configure the plugin.
4. Enable the agent and enter your API Key.
5. Use the Test Connection button to verify your credentials.

== Privacy & Data Collection ==

This plugin connects to Cartesian's external service ([https://cartesian.io](https://cartesian.io)) to provide AI-powered functionality.

**What data is collected:**

When the plugin is enabled, the following data is sent to Cartesian's servers:
* Admin user activity and page views within WordPress admin dashboard
* Site URL and WordPress configuration information

**User consent:**

* The plugin is disabled by default and requires explicit opt-in
* Users must manually enable the plugin and configure an API Key
* Data collection only occurs for logged-in WordPress admin users
* The plugin can be disabled at any time through the settings page

**External service:**

This plugin communicates with Cartesian's servers at agent.cartesian.io to load necessary JavaScript and transmit user data for processing. Cartesian processes admin activity data to power AI-driven assistance and personalization within the WordPress dashboard.

* Privacy Policy: [https://cartesian.io/privacy-policy](https://cartesian.io/privacy-policy)

For more information about how Cartesian handles your data, please review their privacy policy at the link above.

== Frequently Asked Questions ==

= Does this plugin connect to external services? =

Yes, this plugin is an interface to Cartesian's AI service ([https://cartesian.io](https://cartesian.io)). When enabled, it loads JavaScript from agent.cartesian.io and sends admin user activity data to Cartesian's servers for processing. You must review and agree to Cartesian's Privacy Policy before enabling this plugin.

= Where do I get an API Key? =

You can obtain an API Key by signing up at [https://home.cartesian.io](https://home.cartesian.io) and creating a new agent in your Cartesian dashboard.

= What data is sent to Cartesian? =

When enabled, the plugin sends admin user activity, authentication data (username, display name, roles), and site information to Cartesian's servers. See the "Privacy & Data Collection" section for full details.

= Is my data secure? =

Yes! The plugin uses industry-standard RSA encryption for JWT tokens. Private keys are stored securely in your WordPress database and are never transmitted. All communication with Cartesian's servers occurs over secure HTTPS connections.

= Does this work on the frontend? =

No, the Cartesian Agent currently only works on WordPress admin pages.

= Can I disable data collection? =

Yes, simply disable the plugin in the Settings > Cartesian Agent page. You can also deactivate the plugin entirely through the WordPress Plugins screen.