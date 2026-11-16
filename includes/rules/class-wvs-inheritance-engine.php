<?php
/**
 * 4-Tier Inheritance Resolver Engine
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Inheritance_Engine {

    private static $settings = null;

    public function __construct() {
        // Init cache on boot
        self::init_settings();
    }

    public static function init_settings() {
        if ( is_null( self::$settings ) ) {
            self::$settings = get_option( 'wvs_global_settings', [] );
        }
    }

    /**
     * Get a global setting with fallback default
     */
    public static function get_global_setting( $key, $default = '' ) {
        self::init_settings();
        return isset( self::$settings[ $key ] ) ? self::$settings[ $key ] : $default;
    }

    /**
     * Resolve setting cascading from Product > Cat > Global
     */
    public static function resolve_setting( $product_id, $setting_key, $default = '' ) {
        // 1. Check Product Level Override
        $product_val = get_post_meta( $product_id, '_wvs_' . $setting_key, true );
        if ( ! empty( $product_val ) && $product_val !== 'inherit' ) {
            return $product_val;
        }

        // 2. Check Primary Category Level Override
        $terms = wc_get_product_terms( $product_id, 'product_cat', [ 'orderby' => 'parent', 'order' => 'DESC' ] );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $cat_val = get_term_meta( $terms[0]->term_id, '_wvs_' . $setting_key, true );
            if ( ! empty( $cat_val ) && $cat_val !== 'inherit' ) {
                return $cat_val;
            }
        }

        // 3. Fallback to Global Store Setting
        return self::get_global_setting( $setting_key, $default );
    }
}
