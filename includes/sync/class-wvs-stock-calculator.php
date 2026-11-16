<?php
/**
 * Stock Calculator & Auto-Copy Gallery Autopilot Engine
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
        
        // Hook into product import & update hooks
        add_action( 'woocommerce_product_import_inserted_product_object', [ $this, 'handle_imported_product' ], 20, 2 );
    }

    public function handle_variation_save( $variation_id, $i ) {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) {
            return;
        }
        
        $parent_id = $variation->get_parent_id();
        if ( ! $parent_id ) {
            return;
        }

        // 1. Auto-copy variation gallery across sibling sizes of the same colourway if enabled
        $settings = get_option( 'wvs_global_settings', [] );
        if ( ! empty( $settings['auto_copy_galleries'] ) && 'yes' === $settings['auto_copy_galleries'] ) {
            $this->auto_copy_sibling_galleries( $variation );
        }

        // 2. Run stock winner autopilot
        if ( ! empty( $settings['auto_stock_winner'] ) && 'yes' === $settings['auto_stock_winner'] ) {
            self::sync_parent_stock_and_images( $parent_id );
        }
    }

    public function handle_stock_change( $product ) {
        if ( ! $product ) {
            return;
        }

        $parent_id = 0;
        if ( $product->is_type( 'variation' ) ) {
            $parent_id = $product->get_parent_id();
        } elseif ( $product->is_type( 'variable' ) ) {
            $parent_id = $product->get_id();
        }

        if ( $parent_id ) {
            self::sync_parent_stock_and_images( $parent_id );
        }
    }

    public function handle_imported_product( $product, $row ) {
        if ( $product && $product->is_type( 'variable' ) ) {
            self::sync_parent_stock_and_images( $product->get_id() );
        }
    }

    private function auto_copy_sibling_galleries( $target_variation ) {
        $parent_id = $target_variation->get_parent_id();
        $parent = wc_get_product( $parent_id );
        if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
            return;
        }

        $target_image_id = $target_variation->get_image_id();
        $target_gallery  = get_post_meta( $target_variation->get_id(), '_wc_variation_gallery_ids', true );

        if ( ! $target_image_id && empty( $target_gallery ) ) {
            return;
        }

        // Detect primary colour attribute of target variation
        $attributes = $target_variation->get_variation_attributes( false );
        $colour_key = '';
        $colour_val = '';

        foreach ( $attributes as $attr_key => $attr_val ) {
            if ( strpos( $attr_key, 'color' ) !== false || strpos( $attr_key, 'colour' ) !== false ) {
                $colour_key = $attr_key;
                $colour_val = $attr_val;
                break;
            }
        }

        if ( ! $colour_key ) {
            return;
        }

        // Sibling sync across same colour
        $children = $parent->get_children();
        foreach ( $children as $child_id ) {
            if ( $child_id === $target_variation->get_id() ) {
                continue;
            }
            $child_var = wc_get_product( $child_id );
            if ( ! $child_var ) {
                continue;
            }

            $child_attrs = $child_var->get_variation_attributes( false );
            if ( isset( $child_attrs[ $colour_key ] ) && $child_attrs[ $colour_key ] === $colour_val ) {
                if ( $target_image_id && ! $child_var->get_image_id() ) {
                    $child_var->set_image_id( $target_image_id );
                }
                if ( ! empty( $target_gallery ) && ! get_post_meta( $child_id, '_wc_variation_gallery_ids', true ) ) {
                    update_post_meta( $child_id, '_wc_variation_gallery_ids', $target_gallery );
                }
                $child_var->save();
            }
        }
    }

    public static function sync_parent_stock_and_images( $parent_id ) {
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

        // Sort descending by stock quantity
        usort( $in_stock_variations, function( $a, $b ) {
            return $b['stock'] <=> $a['stock'];
        });

        // 1. Set highest stock variation image as parent main image
        $winning_image_id = $in_stock_variations[0]['image_id'];
        $parent->set_image_id( $winning_image_id );

        // 2. Build strict gallery pool from in-stock variation images
        $gallery_image_ids = [];
        foreach ( $in_stock_variations as $var_data ) {
            if ( $var_data['image_id'] != $winning_image_id ) {
                $gallery_image_ids[] = $var_data['image_id'];
            }
            // Include custom variation gallery IDs if present
            $extra_gallery = get_post_meta( $var_data['id'], '_wc_variation_gallery_ids', true );
            if ( ! empty( $extra_gallery ) ) {
                if ( is_string( $extra_gallery ) ) {
                    $extra_gallery = explode( ',', $extra_gallery );
                }
                foreach ( $extra_gallery as $ext_id ) {
                    if ( $ext_id && $ext_id != $winning_image_id ) {
                        $gallery_image_ids[] = (int) $ext_id;
                    }
                }
            }
        }
        $gallery_image_ids = array_values( array_unique( $gallery_image_ids ) );
        $parent->set_gallery_image_ids( $gallery_image_ids );

        // 3. Set default selected variation to match winning stock item
        $parent->set_default_attributes( $in_stock_variations[0]['attributes'] );

        $parent->save();

        // 4. Save precomputed cache for frontend
        if ( class_exists( 'WVS_Cache_Manager' ) ) {
            WVS_Cache_Manager::rebuild_product_cache( $parent_id );
        }
    }
}
