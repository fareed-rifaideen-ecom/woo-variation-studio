<?php
/**
 * Core Loader / Service Container
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Loader {

    public function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        $plugin_dir = plugin_dir_path( dirname( __FILE__ ) );

        // Define all blueprint files
        $files = [
            'core/class-wvs-cache-manager.php',
            'admin/class-wvs-admin-menu.php',
            'admin/class-wvs-attribute-fields.php',
            'admin/class-wvs-native-wc-fields.php',
            'admin/class-wvs-taxonomy-fields.php',
            'rules/class-wvs-inheritance-engine.php',
            'sync/class-wvs-stock-calculator.php',
            'sync/class-wvs-queue-dispatcher.php',
            'frontend/class-wvs-pdp-renderer.php',
            'frontend/class-wvs-catalog-cards.php',
            'frontend/class-wvs-cart-thumbnails.php',
            'seo/class-wvs-schema-renderer.php'
        ];

        // Safe Load: Only require files if they actually exist in the folder
        foreach ( $files as $file ) {
            if ( file_exists( $plugin_dir . $file ) ) {
                require_once $plugin_dir . $file;
            }
        }
    }

    private function init_hooks() {
        // Initialize Admin Menu if class exists
        if ( class_exists( 'WVS_Admin_Menu' ) ) {
            new WVS_Admin_Menu();
        }
        
        // Initialize Inheritance Engine if class exists
        if ( class_exists( 'WVS_Inheritance_Engine' ) ) {
            new WVS_Inheritance_Engine();
        }

        // Initialize Frontend Renderer if class exists
        if ( class_exists( 'WVS_PDP_Renderer' ) ) {
            new WVS_PDP_Renderer();
        }
    }
}
