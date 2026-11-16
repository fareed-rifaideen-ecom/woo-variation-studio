<?php
/**
 * Stock Winner Calculator & Auto-Copy Gallery Engine
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Stock_Calculator {

    public function __construct() {
        // Trigger when variation is saved or stock changes
        add_action( 'woocommerce_save_product_variation', [ $this, 'handle_variation_save' ], 20, 2 );
        add_action( 'woocommerce_variation_set_stock', [ $this, 'handle_stock_change' ], 20, 1 );
        add_action( 'woocommerce_product_set_stock', [ $this, 'handle_stock_change' ], 20, 1 );
    }

    public function handle_variation_save( $variation_id, $i ) {
        $variation = wc_get_product( $variation_id );
        if ( $variation && $variation->get_parent_id() ) {
            $parent_id = $variation->get_parent_id();
            $this->maybe_copy_sibling_galleries( $variation, $parent_id );
            $this->recalculate_parent_stock_and_images( $parent_id );
        }
    }

    public function handle_stock_change( $product ) {
        if ( ! $product ) {
            return;
        }
        if ( $product->is_type( 'variation' ) ) {
            $this->recalculate_parent_stock_and_images( $product->get_parent_id() );
        } elseif ( $product->is_type( 'variable' ) ) {
            $this->recalculate_parent_stock_and_images( $product->get_id() );
        }
    }

    /**
     * Automatically copy variation gallery images across sibling sizes of the same colorway
     */
    private function maybe_copy_sibling_galleries( $saved_variation, $parent_id ) {
        $settings = get_option( 'wvs_global_settings', [] );
        if ( empty( $settings['auto_copy_galleries'] ) || 'yes' !== $settings['auto_copy_galleries'] ) {
            return;
        }

        $saved_gallery = get_post_meta( $saved_variation->get_id(), '_wc_variation_gallery_ids', true );
        if ( empty( $saved_gallery ) ) {
            return;
        }

        // Identify the color attribute of this variation
        $attributes = $saved_variation->get_variation_attributes( false );
        $color_key  = '';
        $color_val  = '';
        
        foreach ( $attributes as $key => $val ) {
            if ( false !== strpos( strtolower( $key ), 'color' ) || false !== strpos( strtolower( $key ), 'colour' ) ) {
                $color_key = $key;
                $color_val = $val;
                break;
            }
        }

        if ( ! $color_key || ! $color_val ) {
            return;
        }

        $parent = wc_get_product( $parent_id );
        if ( ! $parent ) {
            return;
        }

        foreach ( $parent->get_children() as $child_id ) {
            if ( $child_id === $saved_variation->get_id() ) {
                continue;
            }
            $child_var = wc_get_product( $child_id );
            if ( ! $child_var ) {
                continue;
            }
            $child_attrs = $child_var->get_variation_attributes( false );
            if ( isset( $child_attrs[ $color_key ] ) && $child_attrs[ $color_key ] === $color_val ) {
                // If sibling has no gallery yet, copy it
                $child_gallery = get_post_meta( $child_id, '_wc_variation_gallery_ids', true );
                if ( empty( $child_gallery ) ) {
                    update_post_meta( $child_id, '_wc_variation_gallery_ids', $saved_gallery );
                }
            }
        }
    }

    /**
     * Recalculate parent product main image and gallery based on highest-stock winner
     */
    public function recalculate_parent_stock_and_images( $parent_id ) {
        $parent = wc_get_product( $parent_id );
        if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
            return;
        }

        $variation_ids = $parent->get_children();
        $in_stock_variations = [];

        foreach ( $variation_ids as $var_id ) {
            $variation = wc_get_product( $var_id );
            if ( ! $variation || ! $variation->is_in_stock() ) {
                continue;
            }

            $image_id = $variation->get_image_id();
            if ( ! $image_id ) {
                continue;
            }

            $stock_qty = $variation->get_manage_stock() ? (int) $variation->get_stock_quantity() : 1;

            $in_stock_variations[] = [
                'id'         => $var_id,
                'stock'      => $stock_qty,
                'image_id'   => $image_id,
                'attributes' => $variation->get_variation_attributes( false ),
            ];
        }

        if ( empty( $in_stock_variations ) ) {
            return;
        }

        // Sort by highest stock descending
        usort( $in_stock_variations, function( $a, $b ) {
            return $b['stock'] <=> $a['stock'];
        } );

        // 1. Set Winner Image as Parent Main Image
        $winning_image_id = $in_stock_variations[0]['image_id'];
        $parent->set_image_id( $winning_image_id );

        // 2. Build In-Stock Gallery IDs
        $gallery_ids = [];
        foreach ( $in_stock_variations as $var_data ) {
            if ( $var_data['image_id'] != $winning_image_id ) {
                $gallery_ids[] = $var_data['image_id'];
            }
            // Also include extra gallery images assigned to this variation
            $extra_gallery = get_post_meta( $var_data['id'], '_wc_variation_gallery_ids', true );
            if ( ! empty( $extra_gallery ) ) {
                $extra_ids = explode( ',', $extra_gallery );
                foreach ( $extra_ids as $eid ) {
                    if ( $eid && $eid != $winning_image_id ) {
                        $gallery_ids[] = (int) $eid;
                    }
                }
            }
        }

        $gallery_ids = array_values( array_unique( $gallery_ids ) );
        $parent->set_gallery_image_ids( $gallery_ids );

        // 3. Set Default Pre-selected Attributes
        $parent->set_default_attributes( $in_stock_variations[0]['attributes'] );

        $parent->save();

        // Log audit record
        self::log_audit( $parent_id, 'stock_recalculation', 0, $in_stock_variations[0]['id'], 0, $winning_image_id, $in_stock_variations[0]['stock'] );
    }

    private static function log_audit( $parent_id, $source, $old_var, $new_var, $old_img, $new_img, $stock ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wvs_sync_audit_log';
        $wpdb->insert(
            $table,
            [
                'parent_id'         => $parent_id,
                'trigger_source'    => $source,
                'old_winner_var_id' => $old_var,
                'new_winner_var_id' => $new_var,
                'old_image_id'      => $old_img,
                'new_image_id'      => $new_img,
                'stock_snapshot'    => $stock,
            ],
            [ '%d', '%s', '%d', '%d', '%d', '%d', '%d' ]
        );
    }
}
