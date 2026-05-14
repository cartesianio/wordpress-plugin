<?php
/**
 * Plugin Name: Cartesian Agent
 * Plugin URI: https://cartesian.io
 * Description: Integrates Cartesian AI-powered agent into WordPress admin pages.
 * Version: 0.6.0
 * Author: Cartesian
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: cartesian-agent
 *
 * @package CartesianAgent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CARTESIAN_AGENT_VERSION', '0.6.0' );
define( 'CARTESIAN_AGENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CARTESIAN_AGENT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Main plugin class for Cartesian Agent
 */
class CartesianAgent {

	/**
	 * Option name for plugin settings
	 *
	 * @var string
	 */
	private $option_name = 'cartesian_agent_settings';

	/**
	 * Constructor - registers WordPress hooks
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
		add_action( 'admin_post_cartesian_save_settings', array( $this, 'handle_save_settings' ) );

		// Add Settings link on plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );

		// Add error notification on plugins page.
		add_action( 'after_plugin_row_' . plugin_basename( __FILE__ ), array( $this, 'display_plugin_row_notice' ), 10, 2 );

		// Add notification bubble to plugins menu when there's an error.
		add_filter( 'wp_get_update_data', array( $this, 'add_jwt_error_to_update_data' ), 10, 2 );

		// Register activation/deactivation hooks.
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

		// Sync installed offerings when any plugin is installed or uninstalled.
		add_action( 'upgrader_process_complete', array( $this, 'on_plugin_installed' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'send_installed_offerings' ) );

		// MainWP Child integration for centralized management.
		add_filter( 'mainwp_child_extra_execution', array( $this, 'mainwp_child_extra_execution' ), 10, 2 );
	}

	/**
	 * Initialize plugin hooks for admin
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'admin_head', array( $this, 'inject_tracking_script' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}
	}

	/**
	 * Get the enabled setting (database value or environment variable)
	 *
	 * @return bool Whether the agent is enabled.
	 */
	private function is_enabled() {
		$settings = get_option( $this->option_name, array() );

		if ( array_key_exists( 'enabled', $settings ) ) {
			return (bool) $settings['enabled'];
		}

		// Fall back to environment variable.
		$env_enabled = getenv( 'CARTESIAN_AGENT_ENABLED' );
		return in_array( strtolower( $env_enabled ), array( '1', 'true', 'yes', 'on' ), true );
	}


	/**
	 * Get the hide managed options setting (database value or environment variable)
	 *
	 * When enabled, only the Enable Agent Tracking checkbox and Test Connection button
	 * are shown in the settings UI. All other settings (Agent Key, Environment,
	 * Agent ID) are hidden.
	 *
	 * @return bool Whether to hide managed options.
	 */
	private function is_hide_managed_options() {
		$settings = get_option( $this->option_name, array() );

		if ( array_key_exists( 'hide_managed_options', $settings ) ) {
			return (bool) $settings['hide_managed_options'];
		}

		// Fall back to environment variable.
		$env_hide_managed_options = getenv( 'CARTESIAN_HIDE_MANAGED_OPTIONS' );
		return in_array( strtolower( $env_hide_managed_options ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Get the whitelabel name setting (database value or environment variable)
	 *
	 * This is a hidden setting that can only be configured via database, environment
	 * variable, or MainWP remote configuration.
	 *
	 * @return string The whitelabel name, or empty string if not set.
	 */
	private function get_whitelabel_name() {
		$settings = get_option( $this->option_name, array() );

		if ( array_key_exists( 'whitelabel_name', $settings ) && ! empty( $settings['whitelabel_name'] ) ) {
			return (string) $settings['whitelabel_name'];
		}

		// Fall back to environment variable.
		$env_whitelabel_name = getenv( 'CARTESIAN_WHITELABEL_NAME' );
		return $env_whitelabel_name ? (string) $env_whitelabel_name : '';
	}

	/**
	 * Get the brand name to display in user-facing text
	 *
	 * Returns the whitelabel name if set, otherwise returns "Cartesian".
	 *
	 * @return string The brand name to display.
	 */
	private function get_brand_name() {
		$whitelabel_name = $this->get_whitelabel_name();
		return ! empty( $whitelabel_name ) ? $whitelabel_name : 'Cartesian';
	}

	/**
	 * Get the organization name for API requests
	 *
	 * Uses the site URL hostname if available, otherwise falls back to the site name.
	 *
	 * @return string The organization name.
	 */
	private function get_organization_name() {
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		return wp_parse_url( get_bloginfo( 'url' ), PHP_URL_HOST ) ?: get_bloginfo( 'name' );
	}

	// Production Agent ID for the "WordPress" agent in the Cartesian tenant.
	const DEFAULT_AGENT_ID = 'b74d17c6-d57d-4334-99a0-bfefca5c3515';

	/**
	 * Get the agent ID setting (database value or environment variable)
	 *
	 * @return string The agent ID.
	 */
	private function get_agent_id() {
		$settings = get_option( $this->option_name, array() );

		if ( ! empty( $settings['agent_id'] ) ) {
			return (string) $settings['agent_id'];
		}

		// Fall back to environment variable, then the default.
		$env_agent_id = getenv( 'CARTESIAN_AGENT_ID' );
		return $env_agent_id ? sanitize_text_field( (string) $env_agent_id ) : self::DEFAULT_AGENT_ID;
	}

	/**
	 * Get the environment setting (database value or environment variable)
	 *
	 * @return string The environment identifier.
	 */
	private function get_environment() {
		$settings = get_option( $this->option_name, array() );

		if ( array_key_exists( 'environment', $settings ) ) {
			return (string) $settings['environment'];
		}

		// Fall back to environment variable.
		$env_environment = getenv( 'CARTESIAN_ENVIRONMENT' );
		return $env_environment ? (string) $env_environment : '';
	}

	/**
	 * Get the UI visibility override setting (database value or environment variable)
	 *
	 * Controls whether the agent UI is visible on this site, overriding the server-side setting.
	 * Valid values: '' (default/use server setting), 'enable' (force on), 'disable' (force off).
	 *
	 * @return string The UI visibility override value.
	 */
	private function get_ui_visibility_override() {
		$settings = get_option( $this->option_name, array() );

		// Only use DB value if explicitly set to a non-default value.
		// An empty string in the DB means "use default", which allows the env var to act as
		// a site-wide fallback (e.g., set by MainWP for all managed sites).
		if ( array_key_exists( 'ui_visibility_override', $settings ) && '' !== $settings['ui_visibility_override'] ) {
			return (string) $settings['ui_visibility_override'];
		}

		// Fall back to environment variable.
		$env_ui_visibility = getenv( 'CARTESIAN_UI_VISIBILITY' );
		if ( $env_ui_visibility && in_array( $env_ui_visibility, array( 'enable', 'disable' ), true ) ) {
			return (string) $env_ui_visibility;
		}

		return '';
	}

	/**
	 * Get the API key setting for the current environment
	 *
	 * @return string The API key.
	 */
	private function get_api_key() {
		$environment = $this->get_environment();
		$settings    = get_option( $this->option_name, array() );

		// Default to 'prod' if no environment is set.
		$env_key = empty( $environment ) ? 'prod' : $environment;
		$key     = 'api_key_' . $env_key;

		if ( array_key_exists( $key, $settings ) && ! empty( $settings[ $key ] ) ) {
			return (string) $settings[ $key ];
		}

		// Fall back to environment variable.
		$env_api_key = getenv( 'CARTESIAN_API_KEY' );
		return $env_api_key ? (string) $env_api_key : '';
	}

	/**
	 * Get the Cloud Backend URL based on environment
	 *
	 * @param string $environment Optional environment override. If not provided, uses the saved environment setting.
	 * @return string The Cloud Backend URL.
	 */
	private function get_cloud_backend_url( $environment = null ) {
		if ( null === $environment ) {
			$environment = $this->get_environment();
		}

		switch ( $environment ) {
			case 'local':
				// Use CARTESIAN_CLOUD_BACKEND_LOCAL_HOST env var to connect to cloud-backend on host machine.
				// Docker-compose sets this to 'host.docker.internal' which is provided by
				// Docker Desktop and works reliably regardless of host IP changes.
				$local_host = getenv( 'CARTESIAN_CLOUD_BACKEND_LOCAL_HOST' );
				$local_host = $local_host ? $local_host : 'host.docker.internal';
				return 'http://' . $local_host . ':3000';
			case 'dev':
				return 'https://api.cartesian-dev.click';
			case 'staging':
				return 'https://api.cartesian-staging.click';
			case 'prod':
				return 'https://api.cartesian.io';
			default:
				return 'https://api.cartesian.io';
		}
	}

	/**
	 * Get the bootloader URL based on environment
	 *
	 * Returns the URL to the agent bootloader script for the current environment.
	 * Note: Unlike other URLs, this is loaded by the browser (not server-side PHP),
	 * so it must use localhost instead of host.docker.internal.
	 *
	 * @param string $environment Optional environment override. If not provided, uses the saved environment setting.
	 * @return string The bootloader URL.
	 */
	private function get_bootloader_url( $environment = null ) {
		if ( null === $environment ) {
			$environment = $this->get_environment();
		}

		switch ( $environment ) {
			case 'local':
				// Use localhost since this URL is loaded by the browser, not by PHP inside the container.
				return 'http://localhost:8081/dist/bootloader.js';
			case 'dev':
				return 'https://agent.cartesian-dev.click/bootloader.js';
			case 'staging':
				return 'https://agent.cartesian-staging.click/bootloader.js';
			case 'prod':
			default:
				return 'https://agent.cartesian.io/bootloader.js';
		}
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our settings page.
		if ( 'settings_page_cartesian-agent' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cartesian-agent-admin',
			CARTESIAN_AGENT_PLUGIN_URL . 'assets/admin.css',
			array(),
			CARTESIAN_AGENT_VERSION
		);
	}

	/**
	 * Add settings page to admin menu
	 */
	public function add_admin_menu() {
		$brand_name = $this->get_brand_name();
		add_options_page(
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			sprintf( __( '%s Agent Settings', 'cartesian-agent' ), $brand_name ),
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			sprintf( __( '%s Agent', 'cartesian-agent' ), $brand_name ),
			'manage_options',
			'cartesian-agent',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Add settings link to plugin row
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=cartesian-agent' ) ) . '">' . __( 'Settings', 'cartesian-agent' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register settings and fields
	 */
	public function admin_init() {
		register_setting(
			'cartesian_agent_settings_group',
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'enabled'                => false,
					'hide_managed_options'   => false,
					'agent_id'               => '',
					'api_key_local'          => '',
					'api_key_dev'            => '',
					'api_key_staging'        => '',
					'api_key_prod'           => '',
					'environment'            => '',
					'ui_visibility_override' => '',
				),
			)
		);

		$hide_managed = $this->is_hide_managed_options();

		// General Settings Section.
		add_settings_section(
			'cartesian_agent_general',
			'General Settings',
			array( $this, 'general_section_callback' ),
			'cartesian-agent'
		);

		add_settings_field(
			'enabled',
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			sprintf( __( 'Enable %s Agent', 'cartesian-agent' ), $this->get_brand_name() ),
			array( $this, 'enabled_callback' ),
			'cartesian-agent',
			'cartesian_agent_general'
		);

		// Only show Agent Key field when hide_managed_options is false.
		if ( ! $hide_managed ) {
			add_settings_field(
				'api_key',
				'Agent Key',
				array( $this, 'api_key_callback' ),
				'cartesian-agent',
				'cartesian_agent_general'
			);

			// Advanced Settings Section - only shown when hide_managed_options is false.
			add_settings_section(
				'cartesian_agent_advanced',
				'Advanced Settings',
				array( $this, 'advanced_section_callback' ),
				'cartesian-agent'
			);

			add_settings_field(
				'environment',
				'Environment',
				array( $this, 'environment_callback' ),
				'cartesian-agent',
				'cartesian_agent_advanced'
			);

			add_settings_field(
				'agent_id',
				'Agent ID',
				array( $this, 'agent_id_callback' ),
				'cartesian-agent',
				'cartesian_agent_advanced'
			);

			add_settings_field(
				'ui_visibility_override',
				'UI Visibility',
				array( $this, 'ui_visibility_override_callback' ),
				'cartesian-agent',
				'cartesian_agent_advanced'
			);
		}
	}

	/**
	 * Render general section description
	 */
	public function general_section_callback() {
		$brand_name = $this->get_brand_name();
		/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
		echo '<p>' . sprintf( esc_html__( 'Configure your %s agent. The agent must be enabled and have a valid Agent ID to function.', 'cartesian-agent' ), esc_html( $brand_name ) ) . '</p>';
	}

	/**
	 * Render enabled checkbox field
	 */
	public function enabled_callback() {
		$enabled     = $this->is_enabled();
		$env_enabled = getenv( 'CARTESIAN_AGENT_ENABLED' );
		$brand_name  = $this->get_brand_name();

		echo '<label><input type="checkbox" name="' . esc_attr( $this->option_name ) . '[enabled]" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo ' ' . esc_html__( 'Enable agent tracking', 'cartesian-agent' ) . '</label>';

		if ( $env_enabled ) {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( 'When enabled, the %s agent will be loaded on all admin pages.', 'cartesian-agent' ), esc_html( $brand_name ) ) . ' <em>' . esc_html__( 'Default from environment:', 'cartesian-agent' ) . ' <code>' . esc_html( $env_enabled ) . '</code></em></p>';
		} else {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( 'When enabled, the %s agent will be loaded on all admin pages.', 'cartesian-agent' ), esc_html( $brand_name ) ) . '</p>';
		}
	}

	/**
	 * Render agent ID field
	 */
	public function agent_id_callback() {
		$settings     = get_option( $this->option_name, array() );
		$agent_id     = ! empty( $settings['agent_id'] ) ? (string) $settings['agent_id'] : '';
		$env_agent_id = getenv( 'CARTESIAN_AGENT_ID' );
		$brand_name   = $this->get_brand_name();

		echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[agent_id]" value="' . esc_attr( $agent_id ) . '" class="regular-text" />';

		if ( $env_agent_id ) {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( 'Override the default agent ID. This should only be set if guided by the %s team.', 'cartesian-agent' ), esc_html( $brand_name ) ) . ' ' . esc_html__( 'Default from environment:', 'cartesian-agent' ) . ' <code>' . esc_html( $env_agent_id ) . '</code></p>';
		} else {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( 'Override the default agent ID. This should only be set if guided by the %s team.', 'cartesian-agent' ), esc_html( $brand_name ) ) . '</p>';
		}
	}

	/**
	 * Render UI visibility override field
	 */
	public function ui_visibility_override_callback() {
		$ui_visibility     = $this->get_ui_visibility_override();
		$env_ui_visibility = getenv( 'CARTESIAN_UI_VISIBILITY' );

		$options = array(
			''        => '-- Default --',
			'enable'  => 'Enable (Override)',
			'disable' => 'Disable (Override)',
		);

		echo '<select name="' . esc_attr( $this->option_name ) . '[ui_visibility_override]">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $ui_visibility, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		$brand_name = $this->get_brand_name();
		if ( $env_ui_visibility ) {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( 'Override the %s agent UI visibility. When set to Default, the central agent setting is used.', 'cartesian-agent' ), esc_html( $brand_name ) ) . ' <em>' . esc_html__( 'Default from environment:', 'cartesian-agent' ) . ' <code>' . esc_html( $env_ui_visibility ) . '</code></em></p>';
		} else {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( 'Override the %s agent UI visibility. When set to Default, the central agent setting is used.', 'cartesian-agent' ), esc_html( $brand_name ) ) . '</p>';
		}
	}

	/**
	 * Render Agent Key field
	 */
	public function api_key_callback() {
		$settings    = get_option( $this->option_name, array() );
		$environment = isset( $settings['environment'] ) ? $settings['environment'] : '';
		$env_key     = empty( $environment ) ? 'prod' : $environment;

		$env_labels = array(
			'local'   => 'Local',
			'dev'     => 'Development',
			'staging' => 'Staging',
			'prod'    => 'Production',
		);

		// Get environment variable as fallback for all environments.
		$env_api_key = getenv( 'CARTESIAN_API_KEY' );
		$env_api_key = $env_api_key ? (string) $env_api_key : '';

		// Get all Agent Keys, falling back to environment variable if not set in database.
		$api_keys = array(
			'local'   => ! empty( $settings['api_key_local'] ) ? $settings['api_key_local'] : $env_api_key,
			'dev'     => ! empty( $settings['api_key_dev'] ) ? $settings['api_key_dev'] : $env_api_key,
			'staging' => ! empty( $settings['api_key_staging'] ) ? $settings['api_key_staging'] : $env_api_key,
			'prod'    => ! empty( $settings['api_key_prod'] ) ? $settings['api_key_prod'] : $env_api_key,
		);

		// Track which keys are from environment variable.
		$keys_from_env = array(
			'local'   => empty( $settings['api_key_local'] ) && ! empty( $env_api_key ),
			'dev'     => empty( $settings['api_key_dev'] ) && ! empty( $env_api_key ),
			'staging' => empty( $settings['api_key_staging'] ) && ! empty( $env_api_key ),
			'prod'    => empty( $settings['api_key_prod'] ) && ! empty( $env_api_key ),
		);

		// Hidden inputs for all environment Agent Keys.
		foreach ( $api_keys as $env => $key ) {
			echo '<input type="hidden" id="cartesian-api-key-' . esc_attr( $env ) . '" name="' . esc_attr( $this->option_name ) . '[api_key_' . esc_attr( $env ) . ']" value="' . esc_attr( $key ) . '" />';
		}

		// Visible input field that will be synced with the selected environment.
		echo '<div class="cartesian-api-key-field-wrapper">';
		echo '<input type="password" id="cartesian-api-key-display" value="' . esc_attr( $api_keys[ $env_key ] ) . '" class="large-text code" />';
		echo '<button type="button" id="cartesian-api-key-reveal" class="button cartesian-reveal-btn" title="Show/Hide Agent Key">';
		echo '<span class="dashicons dashicons-visibility"></span>';
		echo '</button>';
		echo '</div>';
		echo '<p class="description" id="cartesian-api-key-description">Agent Key for <span id="cartesian-current-env-label">' . esc_html( $env_labels[ $env_key ] ) . '</span> environment.</p>';

		// Show message if current environment is using environment variable.
		if ( $keys_from_env[ $env_key ] ) {
			echo '<p class="description" id="cartesian-api-key-env-notice" style="color: #2271b1;">Currently using value from CARTESIAN_API_KEY environment variable. You can override it by entering a different value and saving.</p>';
		} else {
			echo '<p class="description" id="cartesian-api-key-env-notice" style="display: none; color: #2271b1;">Currently using value from CARTESIAN_API_KEY environment variable. You can override it by entering a different value and saving.</p>';
		}

		echo '<p class="description" style="color: #dc3232; font-weight: bold;">Required when agent is enabled.</p>';

		// Test Connection button.
		echo '<p><button type="button" id="cartesian-test-connection" class="button button-secondary">Test Connection</button></p>';

		// Status message container.
		echo '<div id="cartesian-test-status" style="margin-top: 10px;"></div>';

		// Add JavaScript to sync the visible field with the appropriate hidden field.
		?>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
	var envSelect = document.querySelector('select[name="<?php echo esc_js( $this->option_name ); ?>[environment]"]');
	var apiKeyDisplay = document.getElementById('cartesian-api-key-display');
	var envLabel = document.getElementById('cartesian-current-env-label');
	var envNotice = document.getElementById('cartesian-api-key-env-notice');
	var testButton = document.getElementById('cartesian-test-connection');
	var testStatus = document.getElementById('cartesian-test-status');
	var form = testButton ? testButton.closest('form') : null;

	var envLabels = {
		'': 'Production',
		'local': 'Local',
		'dev': 'Development',
		'staging': 'Staging',
		'prod': 'Production'
	};

	// Track which environments are using environment variable
	var keysFromEnv = <?php echo wp_json_encode( $keys_from_env ); ?>;

	function getCurrentEnv() {
		return envSelect ? (envSelect.value || 'prod') : 'prod';
	}


function syncApiKey() {
	var env = getCurrentEnv();
	var hiddenInput = document.getElementById('cartesian-api-key-' + env);
	
	if (hiddenInput && apiKeyDisplay) {
		apiKeyDisplay.value = hiddenInput.value;
	}
	
	if (envLabel) {
		envLabel.textContent = envLabels[env] || envLabels['prod'];
	}
	
	// Show/hide environment variable notice
	if (envNotice) {
		if (keysFromEnv[env]) {
			envNotice.style.display = 'block';
		} else {
			envNotice.style.display = 'none';
		}
	}
}

		function showStatus(message, isSuccess) {
			if (!testStatus) return;
			
			var color = isSuccess ? '#46b450' : '#dc3232';
			var icon = isSuccess ? '✓' : '✗';
			
			testStatus.innerHTML = '<p style="color: ' + color + '; font-weight: bold;">' + icon + ' ' + message + '</p>';
		}

		function testConnection() {
			if (!testButton || !apiKeyDisplay) return;

			// Get current Agent Key value and environment.
			var apiKey = apiKeyDisplay.value.trim();
			var environment = getCurrentEnv();

			if (!apiKey) {
				showStatus('Please enter an Agent Key', false);
				return;
			}
			
			// Update button state.
			var originalText = testButton.textContent;
			testButton.textContent = 'Testing...';
			testButton.disabled = true;
			testStatus.innerHTML = '';
			
			// Make AJAX request with Agent Key and environment in body.
			fetch('<?php echo esc_js( rest_url( 'cartesian/v1/test-connection' ) ); ?>', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
				},
				body: JSON.stringify({
					api_key: apiKey,
					environment: environment
				})
			})
			.then(function(response) {
				return response.json().then(function(data) {
					return { status: response.status, data: data };
				});
			})
			.then(function(result) {
				if (result.status === 200 && result.data.success) {
					showStatus(result.data.message, true);
				} else if (result.data.message) {
					showStatus(result.data.message, false);
				} else {
					showStatus('Connection test failed', false);
				}
		})
		.catch(function(error) {
			showStatus('Connection failed: ' + error.message, false);
		})
		.finally(function() {
			testButton.textContent = originalText;
			testButton.disabled = false;
		});
		}

	// Sync when environment changes.
	if (envSelect) {
		envSelect.addEventListener('change', function() {
			syncApiKey();
			// Clear test status when environment changes.
			if (testStatus) {
				testStatus.innerHTML = '';
			}
		});
	}

// Sync visible field back to hidden field on input.
if (apiKeyDisplay) {
	apiKeyDisplay.addEventListener('input', function() {
		var env = getCurrentEnv();
		var hiddenInput = document.getElementById('cartesian-api-key-' + env);
		if (hiddenInput) {
			hiddenInput.value = apiKeyDisplay.value;
		}
		
		// If user modifies the field and enters a non-empty value, mark it as no longer from env
		if (apiKeyDisplay.value.trim() !== '') {
			keysFromEnv[env] = false;
			if (envNotice) {
				envNotice.style.display = 'none';
			}
		}
		
		// Clear test status when API key changes.
		if (testStatus) {
			testStatus.innerHTML = '';
		}
	});
}

	// Handle test connection button click.
	if (testButton) {
		testButton.addEventListener('click', testConnection);
	}

	// Handle reveal/hide API key button.
	var revealBtn = document.getElementById('cartesian-api-key-reveal');
	if (revealBtn && apiKeyDisplay) {
		revealBtn.addEventListener('click', function() {
			var icon = revealBtn.querySelector('.dashicons');
			if (apiKeyDisplay.type === 'password') {
				apiKeyDisplay.type = 'text';
				icon.classList.remove('dashicons-visibility');
				icon.classList.add('dashicons-hidden');
				revealBtn.title = 'Hide API Key';
			} else {
				apiKeyDisplay.type = 'password';
				icon.classList.remove('dashicons-hidden');
				icon.classList.add('dashicons-visibility');
				revealBtn.title = 'Show API Key';
			}
		});
	}

	// Initial setup.
	syncApiKey();
});
	</script>
		<?php
	}


	/**
	 * Render advanced section description
	 */
	public function advanced_section_callback() {
		echo '<p>Advanced configuration options for specific use cases.</p>';
	}

	/**
	 * Render environment field
	 */
	public function environment_callback() {
		$environment     = $this->get_environment();
		$env_environment = getenv( 'CARTESIAN_ENVIRONMENT' );

		$environments = array(
			''        => '-- None (Default) --',
			'local'   => 'Local',
			'dev'     => 'Development',
			'staging' => 'Staging',
			'prod'    => 'Production',
		);

		echo '<select name="' . esc_attr( $this->option_name ) . '[environment]">';
		foreach ( $environments as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $environment, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		$brand_name = $this->get_brand_name();
		if ( $env_environment ) {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( '%1$s environment identifier. Leave empty unless instructed otherwise by %2$s support.', 'cartesian-agent' ), esc_html( $brand_name ), esc_html( $brand_name ) ) . ' <em>' . esc_html__( 'Default from environment:', 'cartesian-agent' ) . ' <code>' . esc_html( $env_environment ) . '</code></em></p>';
		} else {
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			echo '<p class="description">' . sprintf( esc_html__( '%1$s environment identifier. Leave empty unless instructed otherwise by %2$s support.', 'cartesian-agent' ), esc_html( $brand_name ), esc_html( $brand_name ) ) . '</p>';
		}
	}

	/**
	 * Sanitize settings input
	 *
	 * @param array $input Raw input from form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// Get existing settings to preserve hidden fields when hide_managed_options is enabled.
		$existing_settings = get_option( $this->option_name, array() );
		$hide_managed      = $this->is_hide_managed_options();

		// Sanitize enabled checkbox (always visible).
		$sanitized['enabled'] = isset( $input['enabled'] ) && $input['enabled'] ? true : false;

		// When hide_managed_options is true, preserve hidden field values from existing settings.
		if ( $hide_managed ) {
			// Preserve hide_managed_options: only save if explicitly set in database.
			// If not in database, don't save anything so environment variable continues to work.
			if ( array_key_exists( 'hide_managed_options', $existing_settings ) ) {
				$sanitized['hide_managed_options'] = (bool) $existing_settings['hide_managed_options'];
			}

			// Preserve agent_id.
			$sanitized['agent_id'] = isset( $existing_settings['agent_id'] ) ? (string) $existing_settings['agent_id'] : '';

			// Preserve api_keys for each environment.
			$sanitized['api_key_local']   = isset( $existing_settings['api_key_local'] ) ? (string) $existing_settings['api_key_local'] : '';
			$sanitized['api_key_dev']     = isset( $existing_settings['api_key_dev'] ) ? (string) $existing_settings['api_key_dev'] : '';
			$sanitized['api_key_staging'] = isset( $existing_settings['api_key_staging'] ) ? (string) $existing_settings['api_key_staging'] : '';
			$sanitized['api_key_prod']    = isset( $existing_settings['api_key_prod'] ) ? (string) $existing_settings['api_key_prod'] : '';

			// Preserve environment.
			$sanitized['environment'] = isset( $existing_settings['environment'] ) ? (string) $existing_settings['environment'] : '';

			// Preserve ui_visibility_override.
			$sanitized['ui_visibility_override'] = isset( $existing_settings['ui_visibility_override'] ) ? (string) $existing_settings['ui_visibility_override'] : '';
		} else {
			// Normal mode: sanitize all fields from input.
			// Preserve hide_managed_options: only save if explicitly set in database.
			// If not in database, don't save anything so environment variable continues to work.
			if ( array_key_exists( 'hide_managed_options', $existing_settings ) ) {
				$sanitized['hide_managed_options'] = (bool) $existing_settings['hide_managed_options'];
			}

			// Sanitize agent_id.
			$sanitized['agent_id'] = isset( $input['agent_id'] ) ? sanitize_text_field( $input['agent_id'] ) : '';

			// Sanitize api_keys for each environment.
			$sanitized['api_key_local']   = isset( $input['api_key_local'] ) ? sanitize_text_field( $input['api_key_local'] ) : '';
			$sanitized['api_key_dev']     = isset( $input['api_key_dev'] ) ? sanitize_text_field( $input['api_key_dev'] ) : '';
			$sanitized['api_key_staging'] = isset( $input['api_key_staging'] ) ? sanitize_text_field( $input['api_key_staging'] ) : '';
			$sanitized['api_key_prod']    = isset( $input['api_key_prod'] ) ? sanitize_text_field( $input['api_key_prod'] ) : '';

			// Sanitize environment.
			$sanitized['environment'] = isset( $input['environment'] ) ? sanitize_text_field( $input['environment'] ) : '';

			// Sanitize ui_visibility_override.
			$ui_visibility_input                 = isset( $input['ui_visibility_override'] ) ? sanitize_text_field( $input['ui_visibility_override'] ) : '';
			$sanitized['ui_visibility_override'] = in_array( $ui_visibility_input, array( '', 'enable', 'disable' ), true ) ? $ui_visibility_input : '';
		}

		// Preserve whitelabel_name: only save if explicitly set in database.
		// If not in database, don't save anything so environment variable continues to work.
		// This is a hidden setting that is always preserved from existing settings, never from user input.
		if ( array_key_exists( 'whitelabel_name', $existing_settings ) ) {
			$sanitized['whitelabel_name'] = (string) $existing_settings['whitelabel_name'];
		}

		// Validate: if enabled is true, a usable agent_id (explicit, env, or default) and the current environment's Agent Key must not be empty.
		if ( $sanitized['enabled'] ) {
			$brand_name        = $this->get_brand_name();
			$resolved_agent_id = ! empty( $sanitized['agent_id'] ) ? $sanitized['agent_id'] : $this->get_agent_id();

			if ( empty( $resolved_agent_id ) ) {
				add_settings_error(
					'cartesian_agent_messages',
					'agent_id_required',
					/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
					sprintf( __( 'Agent ID is required when %s Agent is enabled.', 'cartesian-agent' ), $brand_name ),
					'error'
				);
				$sanitized['enabled'] = false;
			}

			// Check if the current environment's Agent Key is set.
			$current_env     = empty( $sanitized['environment'] ) ? 'prod' : $sanitized['environment'];
			$current_api_key = $sanitized[ 'api_key_' . $current_env ];

			if ( empty( $current_api_key ) && empty( getenv( 'CARTESIAN_API_KEY' ) ) ) {
				$env_labels = array(
					'local'   => 'Local',
					'dev'     => 'Development',
					'staging' => 'Staging',
					'prod'    => 'Production',
				);
				add_settings_error(
					'cartesian_agent_messages',
					'api_key_required',
					/* translators: %1$s: environment label, %2$s: brand name */
					sprintf( __( 'Agent Key for %1$s environment is required when %2$s Agent is enabled.', 'cartesian-agent' ), $env_labels[ $current_env ], $brand_name ),
					'error'
				);
				$sanitized['enabled'] = false;
			}
		}

		return $sanitized;
	}

	/**
	 * Add JWT error to WordPress update data counts
	 *
	 * This filter allows us to properly integrate with WordPress's native
	 * notification system for the admin menu bubble.
	 *
	 * @param array $update_data An array of update counts.
	 * @param array $_titles     An array of update titles (unused).
	 * @return array Modified update data with JWT error included.
	 */
	public function add_jwt_error_to_update_data( $update_data, $_titles ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only add to count if agent is enabled and there's an error.
		if ( ! $this->is_enabled() ) {
			return $update_data;
		}

		$jwt_error = $this->get_jwt_error_notification();
		if ( false === $jwt_error || empty( $jwt_error ) ) {
			return $update_data;
		}

		// Add 1 to the total count.
		$update_data['counts']['total'] = $update_data['counts']['total'] + 1;

		// Add 1 to the plugins count specifically.
		$update_data['counts']['plugins'] = $update_data['counts']['plugins'] + 1;

		// Update the title text to include our error.
		if ( isset( $update_data['title'] ) ) {
			// Append our error to the title.
			/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
			$update_data['title'] .= ', ' . sprintf( __( '1 %s Agent error', 'cartesian-agent' ), $this->get_brand_name() );
		}

		return $update_data;
	}

	/**
	 * Display error notification on plugins page
	 *
	 * @param string $plugin_file  Path to the plugin file relative to the plugins directory.
	 * @param array  $_plugin_data An array of plugin data (unused).
	 */
	public function display_plugin_row_notice( $plugin_file, $_plugin_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only show if agent is enabled and there's an error.
		if ( ! $this->is_enabled() ) {
			return;
		}

		$jwt_error = $this->get_jwt_error_notification();
		if ( false === $jwt_error || empty( $jwt_error ) ) {
			return;
		}

		// Get the number of columns for proper colspan.
		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
		$column_count  = $wp_list_table->get_column_count();

		?>
		<tr class="plugin-update-tr active" id="cartesian-agent-jwt-error" data-slug="cartesian-agent" data-plugin="<?php echo esc_attr( $plugin_file ); ?>">
			<td colspan="<?php echo esc_attr( $column_count ); ?>" class="plugin-update colspanchange">
				<div class="update-message notice inline notice-error notice-alt">
					<p>
						<?php
						/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
						printf( '<strong>%s</strong> ', sprintf( esc_html__( '%s Agent Error:', 'cartesian-agent' ), esc_html( $this->get_brand_name() ) ) );
						?>
						<?php echo esc_html( $jwt_error ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=cartesian-agent' ) ); ?>" class="button button-small" style="margin-left: 10px;">
							<?php esc_html_e( 'Check Settings', 'cartesian-agent' ); ?>
						</a>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Display admin notices
	 */
	public function display_admin_notices() {
		// Only show settings-specific notices on our settings page.
		$screen = get_current_screen();
		if ( $screen && 'settings_page_cartesian-agent' !== $screen->id ) {
			return;
		}

		// Show success message after save.
		// Nonce verification not required for read-only display parameter on admin page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved successfully.', 'cartesian-agent' ); ?></p>
			</div>
			<?php
		}

		// Show any error messages from sanitization.
		settings_errors( 'cartesian_agent_messages' );
	}

	/**
	 * Render settings page
	 */
	public function settings_page() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cartesian-agent' ) );
		}

		$hide_managed = $this->is_hide_managed_options();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cartesian_save_settings" />
				<?php
				wp_nonce_field( 'cartesian_save_settings', 'cartesian_save_settings_nonce' );
				do_settings_sections( 'cartesian-agent' );

				// When hide_managed_options is true, show a simplified Test Connection button.
				if ( $hide_managed ) {
					$this->render_test_connection_button();
				}

				submit_button( 'Save Settings' );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Test Connection button for hidden managed options mode.
	 *
	 * This is a simplified version that tests using the stored settings
	 * rather than form input values.
	 */
	private function render_test_connection_button() {
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Status', 'cartesian-agent' ); ?></th>
					<td>
						<p><button type="button" id="cartesian-test-connection" class="button button-secondary"><?php esc_html_e( 'Test Connection', 'cartesian-agent' ); ?></button></p>
						<div id="cartesian-test-status" style="margin-top: 10px;"></div>
						<script type="text/javascript">
						document.addEventListener('DOMContentLoaded', function() {
							var testButton = document.getElementById('cartesian-test-connection');
							var testStatus = document.getElementById('cartesian-test-status');

							function showStatus(message, isSuccess) {
								if (!testStatus) return;
								var color = isSuccess ? '#46b450' : '#dc3232';
								var icon = isSuccess ? '✓' : '✗';
								testStatus.innerHTML = '<p style="color: ' + color + '; font-weight: bold;">' + icon + ' ' + message + '</p>';
							}

							function testConnection() {
								if (!testButton) return;

								var originalText = testButton.textContent;
								testButton.textContent = '<?php echo esc_js( __( 'Testing...', 'cartesian-agent' ) ); ?>';
								testButton.disabled = true;
								testStatus.innerHTML = '';

								// Test with stored settings (no form values needed).
								fetch('<?php echo esc_js( rest_url( 'cartesian/v1/test-connection' ) ); ?>', {
									method: 'POST',
									credentials: 'same-origin',
									headers: {
										'Content-Type': 'application/json',
										'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
									},
									body: JSON.stringify({
										api_key: '<?php echo esc_js( $this->get_api_key() ); ?>',
										environment: '<?php echo esc_js( $this->get_environment() ); ?>'
									})
								})
								.then(function(response) {
									return response.json().then(function(data) {
										return { status: response.status, data: data };
									});
								})
								.then(function(result) {
									if (result.status === 200 && result.data.success) {
										showStatus(result.data.message, true);
									} else if (result.data.message) {
										showStatus(result.data.message, false);
									} else {
										showStatus('<?php echo esc_js( __( 'Connection test failed', 'cartesian-agent' ) ); ?>', false);
									}
								})
								.catch(function(error) {
									showStatus('<?php echo esc_js( __( 'Connection failed: ', 'cartesian-agent' ) ); ?>' + error.message, false);
								})
								.finally(function() {
									testButton.textContent = originalText;
									testButton.disabled = false;
								});
							}

							if (testButton) {
								testButton.addEventListener('click', testConnection);
							}
						});
						</script>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle settings save via admin-post
	 */
	public function handle_save_settings() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cartesian-agent' ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['cartesian_save_settings_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cartesian_save_settings_nonce'] ) ), 'cartesian_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'cartesian-agent' ) );
		}

		// Get and sanitize the posted data.
		// Note: We use wp_unslash() here and pass to sanitize_settings() which handles full sanitization.
		$input     = isset( $_POST[ $this->option_name ] ) ? map_deep( wp_unslash( $_POST[ $this->option_name ] ), 'sanitize_text_field' ) : array();
		$sanitized = $this->sanitize_settings( $input );

		// Save the settings.
		update_option( $this->option_name, $sanitized );

		// Clear all cached JWT tokens when settings change.
		// This ensures tokens are refreshed with the new API key or environment.
		$this->clear_all_token_caches();

		// Redirect back to the settings page with success message.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'cartesian-agent',
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Inject tracking script into admin pages
	 */
	public function inject_tracking_script() {
		// Don't load in iframes.
		if ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) {
			return;
		}

		// Check if agent is enabled.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Get agent ID.
		$agent_id = $this->get_agent_id();
		if ( empty( $agent_id ) ) {
			return;
		}

		?>
		<script data-cartesian="true">
		window.CartesianWordPress = {
			nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
			restUrl: '<?php echo esc_js( rest_url() ); ?>'
		};
		(function (c, a, r, t, e, s, ia, n) {
			c[t] =
				c[t] ||
				function (i, ...o) {
					(c[t].q = c[t].q || []).push([i, o]);
				};
			c[t]("agent-id:set", s);
			ia = a.createElement(r);
			ia.type = "text/javascript";
			ia.async = true;
			ia.dataset["cartesian"] = "true";
			ia.src = e;
			n = a.getElementsByTagName(r)[0];
			n.parentNode.insertBefore(ia, n);
		})(
			window,
			document,
			"script",
			"Cartesian",
			"<?php echo esc_js( $this->get_bootloader_url() ); ?>",
			"<?php echo esc_js( $agent_id ); ?>"
		);
		<?php
		// Add environment if set.
		$environment = $this->get_environment();
		if ( ! empty( $environment ) ) {
			echo "window.Cartesian('env:set', '" . esc_js( $environment ) . "');";
		}

		// Add UI visibility override if set.
		$ui_visibility = $this->get_ui_visibility_override();
		if ( ! empty( $ui_visibility ) ) {
			echo 'window.Cartesian(\'settings:set\', { uiEnabled: ' . wp_json_encode( 'enable' === $ui_visibility ) . ' });';
		}
		?>
		</script>
		<?php

		// Inject JWT authentication script if user is logged in.
		if ( is_user_logged_in() ) {
			$this->inject_jwt_script();
			$this->inject_platform_config_script();
		}
	}

	/**
	 * Inject platform configuration script
	 *
	 * This script provides the Agent with platform-specific implementations of plugin
	 * operations (check status, install, activate) by calling WordPress REST API endpoints.
	 */
	private function inject_platform_config_script() {
		$rest_url = rest_url( 'cartesian/v1' );
		?>
		<script data-cartesian-platform-config="true">
		(function() {
			/**
			 * Check plugin installation and activation status
			 * @param {string} id - WordPress.org plugin slug (e.g., "akismet")
			 * @param {string} token - JWT authentication token
			 * @returns {Promise<Object>} Plugin status information
			 */
			function checkPluginStatus(id, token) {
				var baseUrl = '<?php echo esc_js( $rest_url ); ?>/plugins/status';
			var url = baseUrl + (baseUrl.indexOf('?') !== -1 ? '&' : '?') + 'slug=' + encodeURIComponent(id);

				return fetch(url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer ' + token,
						'X-WP-Nonce': window.CartesianWordPress.nonce
					}
				})
				.then(function(response) {
					if (!response.ok) {
						throw new Error('Failed to check plugin status: ' + response.status);
					}
					return response.json();
				})
				.then(function(data) {
					return {
						installed: data.installed,
						active: data.active,
						canInstall: data.canInstall,
						canActivate: data.canActivate,
					};
				});
			}

			/**
			 * Install a plugin from WordPress.org repository
			 * @param {string} id - WordPress.org plugin slug (e.g., "akismet")
			 * @param {boolean} [activate=false] - Whether to activate after installation
			 * @param {string} token - JWT authentication token
			 * @returns {Promise<Object>} Installation result
			 */
			function installPlugin(id, activate, token) {
				var url = '<?php echo esc_js( $rest_url ); ?>/plugins/install';

				return fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer ' + token,
						'X-WP-Nonce': window.CartesianWordPress.nonce
					},
					body: JSON.stringify({
						slug: id,
						activate: activate || false
					})
				})
				.then(function(response) {
					if (!response.ok) {
						return response.json().then(function(errorData) {
							throw new Error(errorData.message || 'Failed to install plugin: ' + response.status);
						});
					}
					return response.json();
				})
				.then(function(data) {
					return {
						success: data.success,
						installed: data.installed,
						activated: data.activated,
						message: data.message
					};
				});
			}

			/**
			 * Activate an installed plugin
			 * @param {string} id - Plugin slug (e.g., "akismet")
			 * @param {string} token - JWT authentication token
			 * @returns {Promise<Object>} Activation result
			 */
			function activatePlugin(id, token) {
				var url = '<?php echo esc_js( $rest_url ); ?>/plugins/activate';

				return fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer ' + token,
						'X-WP-Nonce': window.CartesianWordPress.nonce
					},
					body: JSON.stringify({
						slug: id
					})
				})
				.then(function(response) {
					if (!response.ok) {
						return response.json().then(function(errorData) {
							throw new Error(errorData.message || 'Failed to activate plugin: ' + response.status);
						});
					}
					return response.json();
				})
				.then(function(data) {
					return {
						success: data.success,
						message: data.message
					};
				});
			}

			// Configure the Agent with WordPress-specific plugin operations
			window.Cartesian('platform:configure', {
				offeringOperations: {
					checkOfferingStatus: checkPluginStatus,
					installOffering: installPlugin,
					activateOffering: activatePlugin
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Inject JWT authentication script
	 */
	private function inject_jwt_script() {
		?>
		<script data-cartesian-jwt="true">
		(function() {
			// Token cache with expiration
			var tokenCache = {
				token: null,
				expiresAt: null
			};
			
			// Safety buffer: refresh token 5 minutes before expiry
			var EXPIRY_BUFFER_MS = 5 * 60 * 1000; // 5 minutes in milliseconds
			
			/**
			 * Check if cached token is still valid
			 */
			function isCachedTokenValid() {
				if (!tokenCache.token || !tokenCache.expiresAt) {
					return false;
				}
				
				// Check if token is expired (with safety buffer)
				return Date.now() < (tokenCache.expiresAt - EXPIRY_BUFFER_MS);
			}
			
			/**
			 * Clear token cache
			 */
			function clearTokenCache() {
				tokenCache.token = null;
				tokenCache.expiresAt = null;
			}
			
			/**
			 * Fetch new JWT token from backend
			 */
			function fetchNewToken() {
				return fetch('<?php echo esc_js( rest_url( 'cartesian/v1/jwt-token' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
					}
				})
				.then(function(response) {
					if (!response.ok) {
						// Clear cache on error response
						clearTokenCache();
						throw new Error('JWT fetch failed: ' + response.status);
					}
					return response.json();
				})
				.then(function(data) {
					if (!data.token) {
						clearTokenCache();
						throw new Error('No token in response');
					}
					
					// Cache the token with expiration
					tokenCache.token = data.token;
					
					// Calculate expiration timestamp (default to 1 hour if not provided)
					var expiresInSeconds = data.expires_in || 3600;
					tokenCache.expiresAt = Date.now() + (expiresInSeconds * 1000);
					
					return data.token;
				})
				.catch(function(error) {
					console.error('Cartesian JWT error:', error);
					clearTokenCache();
					return null;
				});
			}
			
			/**
			 * Main function to get JWT token (with caching)
			 */
			var fetchJwtFunction = function() {
				// Return cached token if still valid
				if (isCachedTokenValid()) {
					return Promise.resolve(tokenCache.token);
				}
				
				// Fetch new token if cache is invalid or expired
				return fetchNewToken();
			};
			
			// Initialize JWT authentication
			window.Cartesian('user:login', fetchJwtFunction);
		})();
		</script>
		<?php
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes() {
		register_rest_route(
			'cartesian/v1',
			'/jwt-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_jwt_token' ),
				'permission_callback' => array( $this, 'jwt_permission_check' ),
			)
		);

		register_rest_route(
			'cartesian/v1',
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'test_connection_permission_check' ),
			)
		);

		register_rest_route(
			'cartesian/v1',
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_plugins' ),
				'permission_callback' => array( $this, 'plugins_permission_check' ),
			)
		);

		register_rest_route(
			'cartesian/v1',
			'/plugins/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_plugin_status' ),
				'permission_callback' => array( $this, 'jwt_permission_check' ),
			)
		);

		register_rest_route(
			'cartesian/v1',
			'/plugins/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'install_plugin_handler' ),
				'permission_callback' => array( $this, 'install_plugin_permission_check' ),
			)
		);

		register_rest_route(
			'cartesian/v1',
			'/plugins/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate_plugin_handler' ),
				'permission_callback' => array( $this, 'plugins_permission_check' ),
			)
		);

		register_rest_route(
			'cartesian/v1',
			'/plugins/deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'deactivate_plugin_handler' ),
				'permission_callback' => array( $this, 'plugins_permission_check' ),
			)
		);
	}

	/**
	 * Permission callback for JWT endpoint
	 *
	 * @return bool Whether user is logged in.
	 */
	public function jwt_permission_check() {
		return is_user_logged_in();
	}

	/**
	 * Permission callback for test connection endpoint
	 *
	 * @return bool Whether user has manage_options capability.
	 */
	public function test_connection_permission_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for plugins endpoints
	 *
	 * @return bool Whether user has activate_plugins capability.
	 */
	public function plugins_permission_check() {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * Permission callback for install plugin endpoint.
	 * Requires install_plugins; if request asks for activation, also requires activate_plugins
	 * so install+activate cannot bypass the activate endpoint's capability check.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool Whether the user is allowed to perform the requested action.
	 */
	public function install_plugin_permission_check( $request ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return false;
		}
		$body     = json_decode( $request->get_body(), true );
		$activate = isset( $body['activate'] ) ? filter_var( $body['activate'], FILTER_VALIDATE_BOOLEAN ) : false;
		if ( $activate && ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Extract the WordPress.org plugin slug from a Plugin URI or Update URI.
	 *
	 * Matches URLs like https://wordpress.org/plugins/plugin-slug/ or w.org/plugin/plugin-slug.
	 *
	 * @param string $uri Plugin URI or Update URI from plugin headers.
	 * @return string|null The slug if the URI is a WordPress.org plugin URL, null otherwise.
	 */
	private function get_wp_org_slug_from_uri( $uri ) {
		if ( empty( $uri ) || ! is_string( $uri ) ) {
			return null;
		}
		$uri = trim( $uri );
		// Match wordpress.org/plugins/SLUG or w.org/plugin/SLUG (with optional trailing slash).
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Referring to the domain, not the brand.
		if ( preg_match( '#(?:wordpress\.org/plugins|w\.org/plugin)/([^/]+)/?$#i', $uri, $m ) ) {
			return strtolower( $m[1] );
		}
		return null;
	}

	/**
	 * Get the installation status and user permissions for a specific plugin
	 *
	 * Identifies the plugin by: (1) path matching slug/ or slug.php, then (2) matching
	 * WordPress.org slug from Plugin URI / Update URI so plugins whose main file name
	 * differs from the slug (e.g. single-file plugins) are still detected.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return array|WP_Error Status data or error.
	 */
	public function get_plugin_status( $request ) {
		$slug = isset( $request['slug'] ) ? sanitize_text_field( $request['slug'] ) : '';

		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', 'Plugin slug is required', array( 'status' => 400 ) );
		}

		// Ensure required functions are available.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$installed   = false;
		$active      = false;

		$slug_lower = strtolower( $slug );

		// 1) Match by path: "slug/anything.php" or "slug.php" (common convention).
		foreach ( $all_plugins as $file => $data ) {
			if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
				$installed = true;
				$active    = is_plugin_active( $file );
				break;
			}
		}

		// 2) Fallback: match by WordPress.org slug from Plugin URI / Update URI (handles main file name != slug).
		if ( ! $installed ) {
			foreach ( $all_plugins as $file => $data ) {
				$plugin_uri = isset( $data['PluginURI'] ) ? $data['PluginURI'] : '';
				$update_uri = isset( $data['UpdateURI'] ) ? $data['UpdateURI'] : '';
				$uri_slug   = $this->get_wp_org_slug_from_uri( $plugin_uri );
				if ( null === $uri_slug ) {
					$uri_slug = $this->get_wp_org_slug_from_uri( $update_uri );
				}
				if ( null !== $uri_slug && $slug_lower === $uri_slug ) {
					$installed = true;
					$active    = is_plugin_active( $file );
					break;
				}
			}
		}

		return array(
			'installed'   => $installed,
			'active'      => $active,
			'canInstall'  => current_user_can( 'install_plugins' ),
			'canActivate' => current_user_can( 'activate_plugins' ),
		);
	}

	/**
	 * Install a plugin from the WordPress.org repository
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return array|WP_Error Result or error.
	 */
	public function install_plugin_handler( $request ) {
		$body     = json_decode( $request->get_body(), true );
		$slug     = isset( $body['slug'] ) ? sanitize_text_field( $body['slug'] ) : '';
		$activate = isset( $body['activate'] ) ? filter_var( $body['activate'], FILTER_VALIDATE_BOOLEAN ) : false;

		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', 'Plugin slug is required', array( 'status' => 400 ) );
		}

		// Ensure required functions and classes are available.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Get plugin information from WordPress.org API.
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'plugin_api_error', $api->get_error_message(), array( 'status' => 500 ) );
		}

		// Install using Plugin_Upgrader with silent skin.
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'install_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( ! $result ) {
			return new WP_Error( 'install_failed', 'Plugin installation failed', array( 'status' => 500 ) );
		}

		// Get the installed plugin file path (e.g. "akismet/akismet.php").
		$plugin_file = $upgrader->plugin_info();

		$response = array(
			'success'   => true,
			'installed' => true,
			'activated' => false,
			'message'   => 'Plugin installed successfully',
		);

		// Activate if requested (permission already enforced by install_plugin_permission_check).
		if ( $activate && $plugin_file ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return new WP_Error( 'forbidden', 'You do not have permission to activate plugins', array( 'status' => 403 ) );
			}
			$activate_result = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate_result ) ) {
				$response['activated'] = false;
				$response['message']   = 'Plugin installed but activation failed: ' . $activate_result->get_error_message();
			} else {
				$response['activated'] = true;
				$response['message']   = 'Plugin installed and activated successfully';
			}
		}

		return $response;
	}

	/**
	 * Get all installed plugins with their status and update info
	 *
	 * @return array List of plugins with metadata.
	 */
	public function get_plugins() {
		// Ensure required functions are available.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$update_plugins = get_site_transient( 'update_plugins' );
		$result         = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$has_update     = false;
			$update_version = null;

			// Check if update is available (transient may be false when not set).
			if ( is_object( $update_plugins ) && isset( $update_plugins->response[ $plugin_file ] ) ) {
				$has_update     = true;
				$update_version = $update_plugins->response[ $plugin_file ]->new_version;
			}

			$result[] = array(
				'slug'          => $plugin_file,
				'name'          => $plugin_data['Name'],
				'version'       => $plugin_data['Version'],
				'description'   => $plugin_data['Description'],
				'author'        => $plugin_data['Author'],
				'authorUri'     => $plugin_data['AuthorURI'],
				'pluginUri'     => $plugin_data['PluginURI'],
				'status'        => is_plugin_active( $plugin_file ) ? 'active' : 'inactive',
				'hasUpdate'     => $has_update,
				'updateVersion' => $update_version,
			);
		}

		return $result;
	}

	/**
	 * Activate a plugin
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return array|WP_Error Result or error.
	 */
	public function activate_plugin_handler( $request ) {
		$body = json_decode( $request->get_body(), true );
		$slug = isset( $body['slug'] ) ? sanitize_text_field( $body['slug'] ) : '';

		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', 'Plugin slug is required', array( 'status' => 400 ) );
		}

		// Ensure required functions are available.
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Resolve slug to plugin file path.
		$all_plugins = get_plugins();
		$plugin_file = null;
		foreach ( $all_plugins as $file => $data ) {
			if ( strpos( $file, $slug . '/' ) === 0 ) {
				$plugin_file = $file;
				break;
			}
		}

		// Fallback: match by WordPress.org slug from Plugin URI / Update URI.
		if ( ! $plugin_file ) {
			$slug_lower = strtolower( $slug );
			foreach ( $all_plugins as $file => $data ) {
				$plugin_uri = isset( $data['PluginURI'] ) ? $data['PluginURI'] : '';
				$update_uri = isset( $data['UpdateURI'] ) ? $data['UpdateURI'] : '';
				$uri_slug   = $this->get_wp_org_slug_from_uri( $plugin_uri );
				if ( null === $uri_slug ) {
					$uri_slug = $this->get_wp_org_slug_from_uri( $update_uri );
				}
				if ( null !== $uri_slug && $slug_lower === $uri_slug ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( ! $plugin_file ) {
			return new WP_Error( 'plugin_not_found', 'Plugin not found', array( 'status' => 404 ) );
		}

		// Check if already active.
		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'success' => true,
				'message' => 'Plugin is already active',
				'status'  => 'active',
			);
		}

		// Activate the plugin.
		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'activation_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'message' => 'Plugin activated successfully',
			'status'  => 'active',
		);
	}

	/**
	 * Deactivate a plugin
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return array|WP_Error Result or error.
	 */
	public function deactivate_plugin_handler( $request ) {
		$body = json_decode( $request->get_body(), true );
		$slug = isset( $body['slug'] ) ? sanitize_text_field( $body['slug'] ) : '';

		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', 'Plugin slug is required', array( 'status' => 400 ) );
		}

		// Ensure required functions are available.
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Resolve slug to plugin file path.
		$all_plugins = get_plugins();
		$plugin_file = null;
		foreach ( $all_plugins as $file => $data ) {
			if ( strpos( $file, $slug . '/' ) === 0 ) {
				$plugin_file = $file;
				break;
			}
		}
		if ( ! $plugin_file ) {
			return new WP_Error( 'plugin_not_found', 'Plugin not found', array( 'status' => 404 ) );
		}

		// Check if already inactive.
		if ( ! is_plugin_active( $plugin_file ) ) {
			return array(
				'success' => true,
				'message' => 'Plugin is already inactive',
				'status'  => 'inactive',
			);
		}

		// Deactivate the plugin.
		deactivate_plugins( $plugin_file );

		return array(
			'success' => true,
			'message' => 'Plugin deactivated successfully',
			'status'  => 'inactive',
		);
	}

	/**
	 * Generate JWT token for the current user
	 *
	 * @return array|WP_Error Token data or error.
	 */
	public function generate_jwt_token() {
		// Get API key.
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'API key not configured', array( 'status' => 500 ) );
		}

		// Check for cached token.
		$cached_token = $this->get_cached_token();
		if ( false !== $cached_token ) {
			return $cached_token;
		}

		// Fetch new token if cache is invalid or expired.
		return $this->fetch_and_cache_token( $api_key );
	}

	/**
	 * Get cached token if still valid
	 *
	 * @return array|false Cached token data or false if not valid.
	 */
	private function get_cached_token() {
		$cache_key = 'cartesian_jwt_token_' . get_current_user_id();
		$cached    = get_transient( $cache_key );

		if ( false === $cached || ! is_array( $cached ) ) {
			return false;
		}

		// Check if token exists and has required fields.
		if ( ! isset( $cached['token'] ) || ! isset( $cached['expires_at'] ) ) {
			return false;
		}

		// Safety buffer: refresh token 5 minutes (300 seconds) before expiry.
		$buffer_seconds     = 300;
		$current_time       = time();
		$expiry_with_buffer = $cached['expires_at'] - $buffer_seconds;

		// Check if token is still valid (with safety buffer).
		if ( $current_time >= $expiry_with_buffer ) {
			// Token is expired or about to expire.
			delete_transient( $cache_key );
			return false;
		}

		// Return cached token with expires_in relative to current time.
		return array(
			'token'      => $cached['token'],
			'expires_in' => $cached['expires_at'] - $current_time,
		);
	}

	/**
	 * Fetch new token from backend and cache it
	 *
	 * @param string $api_key The Agent Key for authentication.
	 * @return array|WP_Error Token data or error.
	 */
	private function fetch_and_cache_token( $api_key ) {
		// Build user data payload.
		$current_user = wp_get_current_user();
		$user_data    = array(
			'user_id'           => (string) $current_user->ID,
			'user_login'        => $current_user->user_login,
			'organization_name' => $this->get_organization_name(),
			'display_name'      => $current_user->display_name,
			'roles'             => $current_user->roles,
			'site_url'          => get_site_url(),
		);

		$base_url     = $this->get_cloud_backend_url();
		$endpoint_url = $base_url . '/agent-integrations-api/auth/token';
		$request_body = array( 'payload' => $user_data );
		$backend_name = 'Cloud Backend';

		// Make request to Cloud Backend.
		$response = wp_remote_post(
			$endpoint_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 10,
			)
		);

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			// Store error notification for display.
			$this->set_jwt_error_notification( 'Network error connecting to ' . $backend_name . ': ' . $error_message );
			return new WP_Error(
				'sign_request_failed',
				'Failed to request JWT token from ' . $backend_name . ': ' . $error_message,
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code && 201 !== $status_code ) {
			// Handle authentication errors (401/403) with specific message.
			if ( 401 === $status_code || 403 === $status_code ) {
				/* translators: %s: brand name (e.g., "Cartesian" or custom whitelabel name) */
				$this->set_jwt_error_notification( sprintf( __( 'Invalid Agent Key. Please verify your Agent Key on the %s Agent settings page.', 'cartesian-agent' ), $this->get_brand_name() ) );
			} else {
				// Handle other HTTP errors.
				$this->set_jwt_error_notification( $backend_name . ' error (HTTP ' . $status_code . '). Please try again later or contact support.' );
			}

			return new WP_Error(
				'sign_request_error',
				'JWT token request to ' . $backend_name . ' failed with status ' . $status_code . ': ' . $body,
				array( 'status' => $status_code )
			);
		}

		// Parse response.
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! isset( $data['token'] ) ) {
			return new WP_Error(
				'invalid_response',
				'Invalid response from ' . $backend_name . ' - no token in response',
				array( 'status' => 500 )
			);
		}

		// Get expires_in value (default to 3600 seconds if not provided).
		$expires_in = isset( $data['expiresIn'] ) ? intval( $data['expiresIn'] ) : 3600;

		// Cache the token with expiration timestamp.
		$current_time = time();
		$expires_at   = $current_time + $expires_in;

		$cache_key  = 'cartesian_jwt_token_' . get_current_user_id();
		$cache_data = array(
			'token'      => $data['token'],
			'expires_at' => $expires_at,
		);

		// Set transient to expire at token expiration time.
		set_transient( $cache_key, $cache_data, $expires_in );

		// Clear any existing error notifications on successful token fetch.
		$this->clear_jwt_error_notification();

		return array(
			'token'      => $data['token'],
			'expires_in' => $expires_in,
		);
	}

	/**
	 * Clear all cached JWT tokens for all users
	 *
	 * This is called when settings are changed to ensure tokens are refreshed
	 * with the new API key or environment configuration.
	 */
	private function clear_all_token_caches() {
		global $wpdb;

		// Delete all transients that match our token cache pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional bulk delete of transients.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_cartesian_jwt_token_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cartesian_jwt_token_' ) . '%'
			)
		);
	}

	/**
	 * Store JWT error notification
	 *
	 * @param string $message Error message to display to the user.
	 */
	private function set_jwt_error_notification( $message ) {
		update_option( 'cartesian_agent_jwt_error', $message, false );
	}

	/**
	 * Clear JWT error notification
	 */
	private function clear_jwt_error_notification() {
		delete_option( 'cartesian_agent_jwt_error' );
	}

	/**
	 * Get stored JWT error notification
	 *
	 * @return string|false Error message or false if no error stored.
	 */
	private function get_jwt_error_notification() {
		return get_option( 'cartesian_agent_jwt_error', false );
	}

	/**
	 * Test connection to Cloud Backend
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return array|WP_Error Test result or error.
	 */
	public function test_connection( $request ) {
		// Get parameters from request body (allowing testing before save).
		$body        = json_decode( $request->get_body(), true );
		$api_key     = isset( $body['api_key'] ) ? sanitize_text_field( $body['api_key'] ) : '';
		$environment = isset( $body['environment'] ) ? sanitize_text_field( $body['environment'] ) : '';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Please enter an Agent Key', array( 'status' => 400 ) );
		}

		$base_url     = $this->get_cloud_backend_url( $environment );
		$endpoint_url = $base_url . '/agent-integrations-api/auth/token';
		$request_body = array( 'payload' => array() );
		$backend_name = 'Cloud Backend';

		// Make request to test the connection.
		$response = wp_remote_post(
			$endpoint_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 10,
			)
		);

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'connection_failed',
				'Connection to ' . $backend_name . ' failed: ' . $response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error(
				'authentication_failed',
				'Authentication failed: Invalid Agent Key',
				array( 'status' => $status_code )
			);
		}

		if ( 200 !== $status_code && 201 !== $status_code ) {
			return new WP_Error(
				'server_error',
				$backend_name . ' error: HTTP ' . $status_code,
				array( 'status' => $status_code )
			);
		}

		// Parse response.
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! isset( $data['token'] ) ) {
			return new WP_Error(
				'invalid_response',
				'Invalid response from ' . $backend_name,
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'message' => 'Connection successful! Agent Key is valid.',
		);
	}



	/**
	 * Handle MainWP Child extra execution requests.
	 *
	 * This allows the MainWP Dashboard (via the Cartesian Extension) to
	 * read and update Cartesian Agent settings on this site.
	 *
	 * @param array $information Response information.
	 * @param array $post_data   POST data from MainWP request.
	 *
	 * @return array Response information.
	 */
	public function mainwp_child_extra_execution( $information, $post_data = array() ) {
		// Check if this is a Cartesian-specific action.
		if ( ! isset( $post_data['action'] ) || 'cartesian_agent' !== $post_data['action'] ) {
			return $information;
		}

		$sub_action = isset( $post_data['sub_action'] ) ? sanitize_text_field( $post_data['sub_action'] ) : '';

		switch ( $sub_action ) {
			case 'get_settings':
				$information = $this->mainwp_get_settings();
				break;

			case 'update_settings':
				// Use array_key_exists to properly detect when values are explicitly set.
				// null = not provided (keep existing), value = explicitly set.
				$enabled                = array_key_exists( 'enabled', $post_data ) ? filter_var( $post_data['enabled'], FILTER_VALIDATE_BOOLEAN ) : null;
				$api_key                = array_key_exists( 'api_key', $post_data ) ? sanitize_text_field( $post_data['api_key'] ) : null;
				$agent_id               = array_key_exists( 'agent_id', $post_data ) ? sanitize_text_field( $post_data['agent_id'] ) : null;
				$environment            = array_key_exists( 'environment', $post_data ) ? sanitize_text_field( $post_data['environment'] ) : null;
				$hide_managed_options   = array_key_exists( 'hide_managed_options', $post_data ) ? filter_var( $post_data['hide_managed_options'], FILTER_VALIDATE_BOOLEAN ) : null;
				$whitelabel_name        = array_key_exists( 'whitelabel_name', $post_data ) ? sanitize_text_field( $post_data['whitelabel_name'] ) : null;
				$ui_visibility_override = array_key_exists( 'ui_visibility_override', $post_data ) ? sanitize_text_field( $post_data['ui_visibility_override'] ) : null;
				$information            = $this->mainwp_update_settings( $enabled, $api_key, $agent_id, $environment, $hide_managed_options, $whitelabel_name, $ui_visibility_override );
				break;

			default:
				$information = array( 'error' => 'Unknown sub_action: ' . $sub_action );
				break;
		}

		return $information;
	}

	/**
	 * Get Cartesian Agent settings for MainWP.
	 *
	 * @return array Settings data.
	 */
	private function mainwp_get_settings() {
		$settings = get_option( $this->option_name, array() );

		// Get the active Agent Key (check all environments).
		$api_key = '';
		if ( ! empty( $settings['api_key_prod'] ) ) {
			$api_key = $settings['api_key_prod'];
		} elseif ( ! empty( $settings['api_key_staging'] ) ) {
			$api_key = $settings['api_key_staging'];
		} elseif ( ! empty( $settings['api_key_dev'] ) ) {
			$api_key = $settings['api_key_dev'];
		} elseif ( ! empty( $settings['api_key_local'] ) ) {
			$api_key = $settings['api_key_local'];
		}

		return array(
			'success'                => true,
			'enabled'                => isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : false,
			'hide_managed_options'   => isset( $settings['hide_managed_options'] ) ? (bool) $settings['hide_managed_options'] : false,
			'api_key'                => $api_key,
			'agent_id'               => isset( $settings['agent_id'] ) ? (string) $settings['agent_id'] : '',
			'environment'            => isset( $settings['environment'] ) ? (string) $settings['environment'] : '',
			'whitelabel_name'        => isset( $settings['whitelabel_name'] ) ? (string) $settings['whitelabel_name'] : '',
			'ui_visibility_override' => isset( $settings['ui_visibility_override'] ) ? (string) $settings['ui_visibility_override'] : '',
			'capabilities'           => array( 'ui_visibility_override' ),
		);
	}

	/**
	 * Update Cartesian Agent settings from MainWP.
	 *
	 * @param bool   $enabled                Whether the agent is enabled.
	 * @param string $api_key                The Agent Key.
	 * @param string $agent_id               The agent ID (optional).
	 * @param string $environment            The environment (optional).
	 * @param bool   $hide_managed_options   Whether to hide managed options in UI (optional).
	 * @param string $whitelabel_name        The whitelabel name (optional).
	 * @param string $ui_visibility_override UI visibility override: '', 'enable', or 'disable' (optional).
	 *
	 * @return array Result data.
	 */
	private function mainwp_update_settings( $enabled, $api_key, $agent_id = null, $environment = null, $hide_managed_options = null, $whitelabel_name = null, $ui_visibility_override = null ) {
		$settings = get_option( $this->option_name, array() );

		// Update enabled status if provided.
		if ( null !== $enabled ) {
			$settings['enabled'] = $enabled;
		}

		// Update hide_managed_options if provided.
		if ( null !== $hide_managed_options ) {
			$settings['hide_managed_options'] = $hide_managed_options;
		}

		// Update Agent Key for all environments (MainWP manages a single key).
		// Use null check (not empty) to allow clearing keys with empty string.
		if ( null !== $api_key ) {
			$settings['api_key_prod']    = $api_key;
			$settings['api_key_staging'] = $api_key;
			$settings['api_key_dev']     = $api_key;
			$settings['api_key_local']   = $api_key;
		}

		// Update agent_id if provided.
		if ( null !== $agent_id ) {
			$settings['agent_id'] = $agent_id;
		}

		// Update environment if provided.
		if ( null !== $environment ) {
			$settings['environment'] = $environment;
		}

		// Update whitelabel_name if provided.
		if ( null !== $whitelabel_name ) {
			$settings['whitelabel_name'] = $whitelabel_name;
		}

		// Update ui_visibility_override if provided.
		if ( null !== $ui_visibility_override ) {
			$settings['ui_visibility_override'] = in_array( $ui_visibility_override, array( '', 'enable', 'disable' ), true ) ? $ui_visibility_override : '';
		}

		// Save settings.
		$updated = update_option( $this->option_name, $settings );

		// Clear JWT token caches when settings change.
		$this->clear_all_token_caches();

		return array(
			'success'                => true,
			'updated'                => $updated,
			'enabled'                => isset( $settings['enabled'] ) ? $settings['enabled'] : false,
			'hide_managed_options'   => isset( $settings['hide_managed_options'] ) ? $settings['hide_managed_options'] : false,
			'api_key'                => isset( $settings['api_key_prod'] ) ? $settings['api_key_prod'] : '',
			'agent_id'               => isset( $settings['agent_id'] ) ? $settings['agent_id'] : '',
			'environment'            => isset( $settings['environment'] ) ? $settings['environment'] : '',
			'whitelabel_name'        => isset( $settings['whitelabel_name'] ) ? $settings['whitelabel_name'] : '',
			'ui_visibility_override' => isset( $settings['ui_visibility_override'] ) ? $settings['ui_visibility_override'] : '',
		);
	}

	/**
	 * Plugin activation hook
	 */
	public static function activate() {
		// Initialize empty settings array if it doesn't exist.
		// We intentionally don't set default values here - they'll come from environment variables
		// until the user explicitly saves settings in WordPress.
		if ( ! get_option( 'cartesian_agent_settings' ) ) {
			add_option( 'cartesian_agent_settings', array() );
		}

		// Note: send_installed_offerings() is called via the 'upgrader_process_complete' hook,
		// which fires when plugins are installed (regardless of activation status).
	}

	/**
	 * Handle plugin installation via the upgrader
	 *
	 * Called when WordPress finishes installing or updating via the upgrader.
	 * Only triggers send_installed_offerings for plugin installations.
	 *
	 * @param WP_Upgrader $upgrader   WP_Upgrader instance.
	 * @param array       $hook_extra Extra arguments passed to hooked filters.
	 */
	public function on_plugin_installed( $upgrader, $hook_extra ) {
		// Only process plugin installations.
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		// Only process installs, not updates.
		if ( ! isset( $hook_extra['action'] ) || 'install' !== $hook_extra['action'] ) {
			return;
		}

		$this->send_installed_offerings();
	}

	/**
	 * Send installed WordPress plugins to the Cloud Backend
	 *
	 * Collects all installed plugins and sends their slugs to the installed offerings endpoint.
	 * This is called when any plugin is installed or uninstalled to sync the plugin list with the Cloud Backend.
	 */
	public function send_installed_offerings() {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			// No API key configured, silently skip.
			return;
		}

		$organization_name = $this->get_organization_name();
		if ( empty( $organization_name ) ) {
			return;
		}

		// Ensure get_plugins() function is available.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get all installed plugins.
		$plugins = get_plugins();

		// Extract plugin slugs from the plugin file paths.
		// Plugin keys are in format "plugin-folder/plugin-file.php" or "plugin-file.php".
		$offering_ids = array();
		foreach ( array_keys( $plugins ) as $plugin_file ) {
			// Get the directory name (slug) from the plugin path.
			$slug = dirname( $plugin_file );

			// For single-file plugins (no directory), use the filename without extension.
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}

			$offering_ids[] = 'wordpress/' . $slug;
		}

		// Build request body.
		$request_body = array(
			'organizationName' => $organization_name,
			'offeringIds'      => $offering_ids,
		);

		$base_url     = $this->get_cloud_backend_url();
		$endpoint_url = $base_url . '/agent-integrations-api/installed-offerings';

		// Make PUT request to the Cloud Backend.
		$response = wp_remote_request(
			$endpoint_url,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 10,
			)
		);

		// Log errors but don't block activation.
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Cartesian Agent: Failed to send installed offerings - ' . $response->get_error_message() );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code && 201 !== $status_code ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Cartesian Agent: Failed to send installed offerings - HTTP ' . $status_code );
		}
	}

	/**
	 * Plugin deactivation hook
	 */
	public static function deactivate() {
		// Optionally clean up (we keep settings for now in case of reactivation).
	}
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Initialize the plugin.
new CartesianAgent();

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				'https://github.com/cartesianio/wordpress-plugin/',
				__FILE__,
				'cartesian-agent'
			);
			$update_checker->getVcsApi()->enableReleaseAssets( '/cartesian-agent\.zip/' );
		}
	}
);
