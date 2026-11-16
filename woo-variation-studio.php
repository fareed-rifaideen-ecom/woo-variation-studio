<?php
/**
 * Plugin Name: Woo Variation Studio
 * Plugin URI:  https://github.com/fareed-rifaideen-ecom/woo-variation-studio
 * Description: Advanced multi-attribute swatches, strict gallery isolation, and automated highest-stock image autopilot for WooCommerce and Flatsome.
 * Version:     2.5.0
 * Author:      Woo Variation Studio Developer
 * Text Domain: woo-variation-studio
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Plugin Constants
define( 'WVS_VERSION', '2.5.0' );
define( 'WVS_PLUGIN_FILE', __FILE__ );
define( 'WVS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WVS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WVS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare High-Performance Order Storage (HPOS) Compatibility
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Main Plugin Bootstrap Class
 */
final class Woo_Variation_Studio {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook( WVS_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( WVS_PLUGIN_FILE, [ $this, 'deactivate' ] );
        
        add_action( 'plugins_loaded', [ $this, 'load_dependencies' ] );
    }

    public function activate() {
        require_once WVS_PLUGIN_DIR . 'includes/core/class-wvs-activator.php';
        WVS_Activator::activate();
    }

    public function deactivate() {
        require_once WVS_PLUGIN_DIR . 'includes/core/class-wvs-activator.php';
        WVS_Activator::deactivate();
    }

    public function load_dependencies() {
        // Load Core Loader
        require_once WVS_PLUGIN_DIR . 'includes/core/class-wvs-loader.php';
        
        $loader = new WVS_Loader();
        $loader->init();
    }
}

/**
     * Initialize Woo Variation Studio
     */
function wvs_init() {
    return Woo_Variation_Studio::instance();
}
wvs_init();
