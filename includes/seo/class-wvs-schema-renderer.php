<?php
/**
 * Google ProductGroup & hasVariant JSON-LD Schema Generator
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Schema_Renderer {

    public function __construct() {
        add_action( 'wp_head', [ $this, 'render_product_group_schema' ], 20 );
    }

    public function render_product_group_schema() {
        if ( ! is_product() ) {
            return;
        }

        global $product;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        $settings = get_option( 'wvs_global_settings', [] );
        if ( isset( $settings['enable_product_group_schema'] ) && 'no' === $settings['enable_product_group_schema'] ) {
            return;
        }

        $variation_ids = $product->get_children();
        $variants = [];

        foreach ( $variation_ids as $var_id ) {
            $variation = wc_get_product( $var_id );
            if ( ! $variation ) {
                continue;
            }

            $sku   = $variation->get_sku();
            $price = $variation->get_price();
            $image = wp_get_attachment_image_url( $variation->get_image_id(), 'full' );
            $availability = $variation->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';

            $variants[] = [
                '@type'         => 'Product',
                'sku'           => $sku ? $sku : (string) $var_id,
                'image'         => $image ? $image : '',
                'offers'        => [
                    '@type'         => 'Offer',
                    'price'         => $price,
                    'priceCurrency' => get_woocommerce_currency(),
                    'availability'  => $availability,
                    'url'           => $variation->get_permalink(),
                ],
            ];
        }

        $schema = [
            '@context'      => 'https://schema.org/',
            '@type'         => 'ProductGroup',
            'name'          => $product->get_name(),
            'description'   => wp_strip_all_tags( $product->get_short_description() ),
            'sku'           => $product->get_sku(),
            'image'         => wp_get_attachment_image_url( $product->get_image_id(), 'full' ),
            'hasVariant'    => $variants,
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }
}
