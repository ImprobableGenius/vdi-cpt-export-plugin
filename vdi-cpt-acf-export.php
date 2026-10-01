<?php
/**
 * Plugin Name:       VDI CPT + ACF Export
 * Description:       Export custom post type posts and Advanced Custom Fields to CSV from Tools.
 * Version:           0.1.0
 * Author:            Vincent Design
 * Text Domain:       vdi-cpt-acf-export
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package VDI_CPT_ACF_Export
 */

defined( 'ABSPATH' ) || exit;

define( 'VDI_CPT_ACF_EXPORT_FILE', __FILE__ );
define( 'VDI_CPT_ACF_EXPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'VDI_CPT_ACF_EXPORT_VERSION', '0.1.0' );

require_once VDI_CPT_ACF_EXPORT_DIR . 'includes/class-field-discovery.php';
require_once VDI_CPT_ACF_EXPORT_DIR . 'includes/class-admin-page.php';
require_once VDI_CPT_ACF_EXPORT_DIR . 'includes/class-exporter.php';

/**
 * Bootstrap the plugin: admin UI + export handler.
 *
 * ACF-missing / no-field-group notices live on the Tools page (Admin_Page)
 * so core-column export remains available without blocking the UI.
 */
final class VDI_CPT_ACF_Export_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		$admin = new VDI_CPT_ACF_Export_Admin_Page();
		$admin->hooks();

		$exporter = new VDI_CPT_ACF_Export_Exporter();
		$exporter->hooks();
	}
}

/**
 * Boot on plugins_loaded so ACF (if present) is available for discovery.
 */
function vdi_cpt_acf_export_boot() {
	VDI_CPT_ACF_Export_Plugin::instance();
}
add_action( 'plugins_loaded', 'vdi_cpt_acf_export_boot' );
