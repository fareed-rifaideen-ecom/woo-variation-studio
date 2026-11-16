<?php
/**
 * Plugin Name: Woo Variation Studio
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/woo-variation-studio
 * Description: Automated highest-stock variation gallery synchronization, multi-attribute swatches, and strict gallery isolation for WooCommerce and Flatsome.
 * Version: 2.5.0
 * Author: Fareed M. Rifaideen
 * Text Domain: woo-variation-studio
 * WC requires at least: 8.0
 * WC tested up to: 11.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

/**
 * Main Plugin Bootstrap Class
 */
class Woo_Variation_Studio {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_constants();
        $this->init();
    }

    private function define_constants() {
        define( 'WVS_VERSION', '2.5.0' );
        define( 'WVS_PLUGIN_FILE', __FILE__ );
        define( 'WVS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        define( 'WVS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }

    private function init() {
        // Check if WooCommerce is active before loading core files
        add_action( 'plugins_loaded', [ $this, 'load_plugin' ] );

        // Activation / Deactivation hooks
        register_activation_hook( __FILE__, [ 'WVS_Activator', 'activate' ] );
        register_deactivation_hook( __FILE__, [ 'WVS_Activator', 'deactivate' ] );
    }

    public function load_plugin() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="error"><p><strong>Woo Variation Studio</strong> requires WooCommerce to be installed and active.</p></div>';
            } );
            return;
        }

        // Load Loader class safely
        $loader_file = WVS_PLUGIN_DIR . 'includes/core/class-wvs-loader.php';
        if ( file_exists( $loader_file ) ) {
            require_once $loader_file;
            if ( class_exists( 'WVS_Loader' ) ) {
                new WVS_Loader();
            }
        }
    }
}

// Kick off the plugin
function wvs_init() {
    return Woo_Variation_Studio::instance();
}
wvs_init();
```[cite: 11]

---

### How to Apply & Verify
1. Commit the updated `woo-variation-studio.php` file to your GitHub repository.
2. If your site was locked out, deactivate and reactivate **Woo Variation Studio** from your WordPress Plugins dashboard.
3. If any minor warning or notice appears, enabling `WP_DEBUG` in your `wp-config.php` will display the exact line number so we can fine-tune it immediately. 

Let me know once you have pushed this update!
