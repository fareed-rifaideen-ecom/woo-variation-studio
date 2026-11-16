<?php
/**
 * Shop Catalog Cards & Presets (Specialized, Pinarello & Zara Styles)
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Catalog_Cards {

    public function __construct() {
        add_action( 'woocommerce_before_shop_loop_item_title', [ $this, 'render_catalog_card_badges' ], 10 );
        add_filter( 'woocommerce_product_get_gallery_image_ids', [ $this, 'filter_archive_hover_images' ], 10, 2 );
        add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'render_catalog_swatches_and_quick_add' ], 15 );
    }

    public function render_catalog_card_badges() {
        global $product;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        $badge = get_post_meta( $product->get_id(), '_wvs_card_badge', true );
        if ( ! $badge ) {
            $badge = get_post_meta( $product->get_id(), '_cux_card_badge', true );
        }

        if ( $badge ) {
            echo '<div class="wvs-card-badge-top-left" style="position:absolute; top:12px; left:12px; z-index:3; background:#0f172a; color:#fff; padding:3px 8px; font-size:11px; font-weight:700; border-radius:3px; text-transform:uppercase;">' . esc_html( $badge ) . '</div>';
        }
    }

    public function filter_archive_hover_images( $image_ids, $product ) {
        if ( is_admin() || is_product() || ! $product || ! $product->is_type( 'variable' ) ) {
            return $image_ids;
        }

        $settings = get_option( 'wvs_global_settings', [] );
        $hover_mode = $settings['catalog_hover_mode'] ?? 'angle_2';

        $cache = get_post_meta( $product->get_id(), '_wvs_product_cache', true );
        if ( $cache ) {
            $data = json_decode( $cache, true );
            if ( ! empty( $data['gallery_ids'] ) ) {
                return $data['gallery_ids'];
            }
        }

        return $image_ids;
    }

    public function render_catalog_swatches_and_quick_add() {
        global $product;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        $settings = get_option( 'wvs_global_settings', [] );
        $preset = $settings['card_style'] ?? 'style_a';

        $available_variations = $product->get_available_variations();
        $colours = [];

        foreach ( $available_variations as $var ) {
            $attrs = $var['attributes'];
            foreach ( $attrs as $attr_key => $attr_val ) {
                if ( strpos( $attr_key, 'color' ) !== false || strpos( $attr_key, 'colour' ) !== false ) {
                    if ( $attr_val && ! in_array( $attr_val, $colours, true ) ) {
                        $colours[] = $attr_val;
                    }
                }
            }
        }

        if ( empty( $colours ) ) {
            return;
        }

        echo '<div class="wvs-archive-swatches-wrap" style="margin-top:8px; display:flex; gap:5px; align-items:center;">';
        $max_show = (int) ( $settings['catalog_max_swatches'] ?? 4 );
        $count = 0;

        foreach ( $colours as $c_slug ) {
            if ( $count >= $max_show ) {
                $rem = count( $colours ) - $max_show;
                echo '<span class="wvs-more-swatches" style="font-size:11px; font-weight:600; color:#64748b;">+' . esc_html( $rem ) . '</span>';
                break;
            }

            // Find term if taxonomy exists
            $term = get_term_by( 'slug', $c_slug, 'pa_color' );
            if ( ! $term ) {
                $term = get_term_by( 'slug', $c_slug, 'pa_colour' );
            }

            $hex = $term ? get_term_meta( $term->term_id, 'wvs_color_hex', true ) : '#ccc';
            echo '<span class="wvs-archive-dot" style="width:14px; height:14px; border-radius:50%; background:' . esc_attr( $hex ? $hex : '#ccc' ) . '; display:inline-block; border:1px solid #cbd5e1;" title="' . esc_attr( $c_slug ) . '"></span>';
            $count++;
        }
        echo '</div>';

        if ( 'style_c' === $preset ) {
            // Zara-style Quick-Add size bar placeholder on archive cards
            echo '<div class="wvs-archive-quick-add" style="margin-top:6px; font-size:11px; font-weight:600; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px;">Quick Add Available</div>';
        }
    }
}
