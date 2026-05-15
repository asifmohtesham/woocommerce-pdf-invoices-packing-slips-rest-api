<?php
/**
 * Plugin Name:       WooCommerce PDF Invoices & Packing Slips REST API
 * Plugin URI:        https://github.com/asifmohtesham/woocommerce-pdf-invoices-packing-slips-rest-api
 * Description:       Exposes WP Overnight "PDF Invoices & Packing Slips for WooCommerce" document access keys via the WooCommerce REST API, enabling mobile and third-party clients to fetch PDF documents without a WordPress session.
 * Version:           1.0.0
 * Author:            Asif Mohtesham
 * Author URI:        https://github.com/asifmohtesham
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wcpdf-rest-api
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.9
 */

defined( 'ABSPATH' ) || exit;

define( 'WCPDF_REST_API_VERSION', '1.0.0' );
define( 'WCPDF_REST_API_FILE',    __FILE__ );
define( 'WCPDF_REST_API_DIR',     plugin_dir_path( __FILE__ ) );

/**
 * Boot the plugin after all plugins are loaded so we can safely check
 * for WooCommerce and WP Overnight.
 */
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WooCommerce PDF Invoices REST API requires WooCommerce to be installed and active.', 'wcpdf-rest-api' )
			);
		} );
		return;
	}

	require_once WCPDF_REST_API_DIR . 'includes/class-wcpdf-rest-controller.php';

	add_action( 'rest_api_init', static function () {
		$controller = new WCPDF_REST_Controller();
		$controller->register_routes();
	} );
} );
