<?php
/**
 * Core Service Loader & Hook Registry
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Loader {

    public function init() {
        // Include core service components
        $this->include_files();
        $this->register_services();
    }

    private function include_files() {
        $files = [
            'includes/core/class-wvs-cache-manager.php',
            'includes/rules/class-wvs-inheritance-engine.php',
            'includes/admin/class-wvs-admin-menu.php',
            'includes/admin/class-wvs-attribute-fields.php',
            'includes/admin/class-wvs-native-wc-fields.php',
            'includes/admin/class-wvs-taxonomy-fields.php',
            'includes/sync/class-wvs-stock-calculator.php',
            'includes/sync/class-wvs-queue-dispatcher.php',
            'includes/frontend/class-wvs-pdp-renderer.php',
            'includes/frontend/class-wvs-catalog-cards.php',
            'includes/frontend/class-wvs-cart-thumbnails.php',
            'includes/seo/class-wvs-schema-renderer.php',
        ];

        foreach ( $files as $file ) {
            $filepath = WVS_PLUGIN_DIR . $file;
            if ( file_exists( $filepath ) ) {
                require_once $filepath;
            }
        }
    }

    private function register_services() {
        // Instantiate core controllers if classes exist
        if ( class_exists( 'WVS_Admin_Menu' ) ) {
            new WVS_Admin_Menu();
        }
        if ( class_exists( 'WVS_Attribute_Fields' ) ) {
            new WVS_Attribute_Fields();
        }
        if ( class_exists( 'WVS_Native_WC_Fields' ) ) {
            new WVS_Native_WC_Fields();
        }
        if ( class_exists( 'WVS_Stock_Calculator' ) ) {
            new WVS_Stock_Calculator();
        }
        if ( class_exists( 'WVS_Queue_Dispatcher' ) ) {
            new WVS_Queue_Dispatcher();
        }
        if ( class_exists( 'WVS_PDP_Renderer' ) ) {
            new WVS_PDP_Renderer();
        }
        if ( class_exists( 'WVS_Catalog_Cards' ) ) {
            new WVS_Catalog_Cards();
        }
        if ( class_exists( 'WVS_Schema_Renderer' ) ) {
            new WVS_Schema_Renderer();
        }
    }
}
