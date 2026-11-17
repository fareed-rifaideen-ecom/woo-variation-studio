<?php
/**
 * Plugin Name: Woo Variation Studio
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/woo-variation-studio
 * Description: Automated highest-stock variation gallery synchronization, multi-attribute swatches, and strict gallery isolation for WooCommerce and Flatsome.
 * Version: 2.5.2
 * Author: Fareed M. Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app/
 * Text Domain: woo-variation-studio
 * WC requires at least: 8.0
 * WC tested up to: 11.3
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WVS_VERSION' ) ) {
	define( 'WVS_VERSION', '2.5.2' );
}
if ( ! defined( 'WVS_STUDIO_VERSION' ) ) {
	define( 'WVS_STUDIO_VERSION', '2.5.2' );
}
if ( ! defined( 'WVS_PLUGIN_FILE' ) ) {
	define( 'WVS_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WVS_PLUGIN_DIR' ) ) {
	define( 'WVS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WVS_PATH' ) ) {
	define( 'WVS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WVS_PLUGIN_URL' ) ) {
	define( 'WVS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WVS_URL' ) ) {
	define( 'WVS_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Capture any runtime or compile-time fatal error originating from this plugin
 * so it is visible in WP Admin instead of showing a generic white screen.
 */
register_shutdown_function(
	function () {
		$error = error_get_last();
		if ( ! is_array( $error ) || empty( $error['file'] ) ) {
			return;
		}
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( in_array( (int) $error['type'], $fatal_types, true ) && false !== strpos( wp_normalize_path( $error['file'] ), 'woo-variation-studio' ) ) {
			set_transient(
				'wvs_last_fatal_error',
				array(
					'message' => (string) $error['message'],
					'file'    => basename( (string) $error['file'] ),
					'line'    => (int) $error['line'],
					'time'    => current_time( 'mysql' ),
				),
				HOUR_IN_SECONDS
			);
		}
	}
);

add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$last_error = get_transient( 'wvs_last_fatal_error' );
		if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>Woo Variation Studio Fatal Error Caught:</strong> %s in <code>%s</code> on line <strong>%d</strong> (%s)</p></div>',
				esc_html( $last_error['message'] ),
				esc_html( $last_error['file'] ),
				intval( $last_error['line'] ),
				esc_html( $last_error['time'] )
			);
			delete_transient( 'wvs_last_fatal_error' );
		}
	}
);

// Load the Activator file BEFORE registering activation hooks.
$wvs_activator_file = WVS_PLUGIN_DIR . 'includes/core/class-wvs-activator.php';
if ( file_exists( $wvs_activator_file ) ) {
	require_once $wvs_activator_file;
	register_activation_hook( __FILE__, array( 'WVS_Activator', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'WVS_Activator', 'deactivate' ) );
}

// Declare WooCommerce High-Performance Order Storage (HPOS) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin safely after all plugins have loaded.
 */
function wvs_boot_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p><strong>Woo Variation Studio</strong> requires WooCommerce to be installed and active.</p></div>';
			}
		);
		return;
	}

	$loader_file = WVS_PLUGIN_DIR . 'includes/core/class-wvs-loader.php';
	if ( file_exists( $loader_file ) ) {
		try {
			require_once $loader_file;
			if ( class_exists( 'WVS_Loader' ) ) {
				WVS_Loader::init();
			}
		} catch ( \Throwable $e ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					printf(
						'<div class="notice notice-error"><p><strong>Woo Variation Studio Boot Error:</strong> %s in <code>%s</code> on line <strong>%d</strong></p></div>',
						esc_html( $e->getMessage() ),
						esc_html( basename( $e->getFile() ) ),
						intval( $e->getLine() )
					);
				}
			);
		}
	}
}
add_action( 'plugins_loaded', 'wvs_boot_plugin', 20 );
