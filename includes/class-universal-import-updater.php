<?php

/**
 * Update-notification mechanism for Universal Import.
 *
 * Injects the latest GitHub release into WordPress's native update UI so
 * the site admin sees "update now" on the Plugins screen. Updates install
 * automatically only when the admin enables WordPress auto-updates for the
 * plugin (the toggle is injected below); otherwise they apply on click.
 *
 * The "latest version" source of truth is a version.json file committed to
 * the repository's `main` branch, which a GitHub Action rewrites on every
 * release. This keeps existing installs in sync without a wordpress.org
 * listing.
 *
 * @package Universal_Import
 */
class Universal_Import_Updater {

	/**
	 * URL of the version.json file in the GitHub repository (main branch).
	 *
	 * @var string
	 */
	const VERSION_URL = 'https://raw.githubusercontent.com/jodukkan-max/universal-import/main/version.json';

	/**
	 * The plugin slug (folder name).
	 *
	 * @var string
	 */
	const PLUGIN_SLUG = 'universal-import-main';

	/**
	 * The plugin basename (universal-import-main/rey-swatches-import.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Full filesystem path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * The current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Initialize the updater and register hooks.
	 *
	 * @param string $plugin_file Full path to the main plugin file.
	 * @param string $version     Current plugin version.
	 */
	public function __construct( $plugin_file, $version ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_file     = $plugin_file;
		$this->current_version = $version;

		// Inject into WP's native update UI.
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ), 10, 1 );

		// Show the "Enable auto-updates" toggle (WordPress only shows it for
		// wordpress.org-hosted plugins by default).
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_toggle_html' ), 10, 3 );
	}

	// -----------------------------------------------------------------
	// WP native update UI injection.
	// -----------------------------------------------------------------

	/**
	 * Inject the latest release into WordPress's update transient.
	 *
	 * @param  object $transient WordPress update transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$data = $this->fetch_version_data();
		if ( ! $data ) {
			return $transient;
		}

		if ( version_compare( $data['version'], $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => self::PLUGIN_SLUG,
				'plugin'      => $this->plugin_basename,
				'new_version' => $data['version'],
				'package'     => $data['package'],
				'url'         => 'https://github.com/jodukkan-max/universal-import',
				'requires'    => isset( $data['requires'] ) ? $data['requires'] : '5.0',
				'tested'      => isset( $data['tested'] ) ? $data['tested'] : '',
			);
		}

		return $transient;
	}

	// -----------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------

	/**
	 * Fetch the latest version.json from GitHub (live, no caching).
	 *
	 * @return array|null Decoded version data, or null on failure.
	 */
	private function fetch_version_data() {
		$response = wp_remote_get( self::VERSION_URL, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['version'] ) || empty( $body['package'] ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Show the "Enable auto-updates" / "Disable auto-updates" toggle for this
	 * plugin in the Plugins list screen.
	 *
	 * WordPress only renders this toggle for wordpress.org-hosted plugins.
	 * Since this plugin is self-hosted on GitHub, we inject the HTML manually
	 * so the admin can opt it into WordPress's native auto-update system.
	 *
	 * @param  string $html         Existing HTML (empty for self-hosted plugins).
	 * @param  string $plugin_file  Plugin basename (e.g. rey-swatches-import/rey-swatches-import.php).
	 * @param  array  $plugin_data  Plugin header data from get_plugins().
	 * @return string Modified HTML.
	 */
	public function auto_update_toggle_html( $html, $plugin_file, $plugin_data ) {
		// Only affect this plugin.
		if ( $plugin_file !== $this->plugin_basename ) {
			return $html;
		}

		// Bail if site-wide auto-updates are completely disabled.
		if ( wp_is_auto_update_forced_for_item( 'plugin', false, 'disabled' ) ) {
			return $html;
		}

		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$enabled      = in_array( $plugin_file, $auto_updates, true );

		if ( $enabled ) {
			$action = 'disable-auto-update';
			$label  = __( 'Disable auto-updates' );
			$css    = 'auto-update-disabled';
		} else {
			$action = 'enable-auto-update';
			$label  = __( 'Enable auto-updates' );
			$css    = 'auto-update-enabled';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'plugin' => $plugin_file,
				),
				'plugins.php'
			),
			'updates'
		);

		return sprintf(
			'<a href="%s" class="%s" data-wp-action="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			$css,
			$enabled ? 'disable' : 'enable',
			esc_attr( $label ),
			$label
		);
	}
}
