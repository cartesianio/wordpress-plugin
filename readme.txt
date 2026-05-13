=== Cartesian Agent ===
Contributors: cartesian
Tags: analytics, tracking, ai, agent
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Integrates Cartesian's AI-powered agent into WordPress admin pages for enhanced user experience.

== Description ==

**Important: This plugin requires an active Cartesian account and connects to external Cartesian services.**

Cartesian Agent integrates seamlessly with your WordPress admin dashboard to provide AI-powered personalization and assistance. The plugin acts as an interface to Cartesian's service at https://cartesian.io, enabling intelligent tracking and user authentication through JWT tokens.

**Features:**

* Easy enable/disable control
* Secure JWT authentication with automatic key generation
* Configurable agent ID
* Advanced environment settings
* Privacy-focused: only tracks admin users with explicit opt-in

**External Service:**

This plugin connects to Cartesian's servers to provide its functionality. Please review Cartesian's Terms of Service and Privacy Policy before enabling:

* Privacy Policy: https://cartesian.io/privacy-policy
* Terms of Service: [TODO: Add Terms of Service URL]

== Installation ==

**Prerequisites:**
* An active Cartesian account (sign up at https://cartesian.io)
* A configured Agent ID from your Cartesian dashboard

**Installation Steps:**

1. Upload the plugin files to the `/wp-content/plugins/cartesian-agent` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Settings → Cartesian Agent to configure the plugin.
4. Enable the agent and enter your Agent ID.
5. Copy the public key from the JWT Authentication section and add it to your Cartesian configuration.

**Note:** By enabling this plugin, you consent to sending admin user data to Cartesian's external service as described in the Privacy & Data Collection section.

== Privacy & Data Collection ==

This plugin connects to Cartesian's external service (https://cartesian.io) to provide AI-powered functionality.

**What data is collected:**

When the plugin is enabled, the following data is sent to Cartesian's servers:
* Admin user activity and page views within WordPress admin dashboard
* User authentication data (username, email, display name, user roles)
* Site URL and WordPress configuration information
* [TODO: Add any additional data points collected]

**User consent:**

* The plugin is disabled by default and requires explicit opt-in
* Users must manually enable the plugin and configure an Agent ID
* Data collection only occurs for logged-in WordPress admin users
* The plugin can be disabled at any time through the settings page

**External service:**

This plugin communicates with Cartesian's servers at agent.cartesian.io to load necessary JavaScript and transmit user data for processing.

* Privacy Policy: https://cartesian.io/privacy-policy
* Terms of Service: [TODO: Add Terms of Service URL]
* Service Description: [TODO: Add brief description of what Cartesian service does with the data]

For more information about how Cartesian handles your data, please review their privacy policy at the link above.

== Frequently Asked Questions ==

= Does this plugin connect to external services? =

Yes, this plugin is an interface to Cartesian's AI service (https://cartesian.io). When enabled, it loads JavaScript from agent.cartesian.io and sends admin user activity data to Cartesian's servers for processing. You must review and agree to Cartesian's Terms of Service and Privacy Policy before enabling this plugin.

= Where do I get an Agent ID? =

You can obtain an Agent ID by signing up at https://cartesian.io and creating a new agent in your Cartesian dashboard.

= What data is sent to Cartesian? =

When enabled, the plugin sends admin user activity, authentication data (username, email, display name, roles), and site information to Cartesian's servers. See the "Privacy & Data Collection" section for full details.

= Is my data secure? =

Yes! The plugin uses industry-standard RSA encryption for JWT tokens. Private keys are stored securely in your WordPress database and are never transmitted. All communication with Cartesian's servers occurs over secure HTTPS connections.

= Does this work on the frontend? =

No, the Cartesian Agent currently only works on WordPress admin pages. Frontend integration may be added in a future release.

= Can I regenerate my JWT keys? =

Yes! In the JWT Authentication section of the settings page, you can regenerate your key pair at any time. Note that this will invalidate your current public key and you'll need to update it in your Cartesian configuration.

= Can I disable data collection? =

Yes, simply disable the plugin in the Settings → Cartesian Agent page. You can also deactivate the plugin entirely through the WordPress Plugins screen.

== Screenshots ==

1. General settings page with enable/disable toggle and agent ID configuration
2. JWT Authentication section showing public key and regenerate option
3. Advanced settings for environment configuration

== Changelog ==

= 1.0.0 =
* Initial release
* Enable/disable control with agent ID validation
* Automatic JWT key generation
* Public key display and regeneration
* Environment configuration
* REST API endpoint for JWT token generation

== Upgrade Notice ==

= 1.0.0 =
Initial release of Cartesian Agent plugin.

