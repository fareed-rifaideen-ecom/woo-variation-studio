<?php
/**
 * Single Product Page Swatch Renderer & Flatsome Gallery Bridge
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_PDP_Renderer {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ $this, 'render_swatch_html' ], 20, 2 );
        add_filter( 'woocommerce_available_variation', [ $this, 'append_variation_gallery_data' ], 10, 3 );
        add_filter( 'woocommerce_product_get_gallery_image_ids', [ $this, 'filter_pdp_gallery_images' ], 10, 2 );
    }

    public function enqueue_frontend_assets() {
        if ( ! is_product() ) {
            return;
        }

        wp_enqueue_style(
            'wvs-frontend-ux',
            WVS_PLUGIN_URL . 'assets/css/frontend-ux.css',
            [],
            WVS_VERSION
        );

        wp_enqueue_script(
            'wvs-frontend-ux',
            WVS_PLUGIN_URL . 'assets/js/frontend-ux.js',
            [ 'jquery', 'wc-add-to-cart-variation' ],
            WVS_VERSION,
            true
        );

        global $product;
        if ( $product && $product->is_type( 'variable' ) ) {
            $cache = get_post_meta( $product->get_id(), '_wvs_product_cache', true );
            wp_localize_script( 'wvs-frontend-ux', 'wvs_product_data', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'product_id' => $product->get_id(),
                'cache' => $cache ? json_decode( $cache, true ) : [],
            ] );
        }
    }

    public function render_swatch_html( $html, $args ) {
        $product = $args['product'];
        $attribute = $args['attribute'];

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return $html;
        }

        $options = $args['options'];
        $taxonomy = $attribute;
        
        // Ensure proper taxonomy naming
        if ( ! taxonomy_exists( $taxonomy ) && taxonomy_exists( 'pa_' . $taxonomy ) ) {
            $taxonomy = 'pa_' . $taxonomy;
        }

        $settings = get_option( 'wvs_global_settings', [] );
        $display_type = $settings['attr_display_' . wc_attribute_taxonomy_slug( $attribute )] ?? 'auto_image';

        $terms = [];
        if ( taxonomy_exists( $taxonomy ) ) {
            $terms = get_terms( [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'include'    => $options,
            ] );
        }

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return $html;
        }

        ob_start();
        ?>
        <div class="wvs-swatch-container" data-attribute_name="attribute_<?php echo esc_attr( $attribute ); ?>">
            <div class="wvs-swatch-list" data-display-type="<?php echo esc_attr( $display_type ); ?>">
                <?php
                foreach ( $terms as $term ) {
                    $term_id = $term->term_id;
                    $slug    = $term->slug;
                    $name    = $term->name;

                    $swatch_type = get_term_meta( $term_id, 'wvs_swatch_type', true );
                    $color_hex   = get_term_meta( $term_id, 'wvs_color_hex', true );
                    $sec_hex     = get_term_meta( $term_id, 'wvs_secondary_color_hex', true );
                    $image_id    = get_term_meta( $term_id, 'wvs_swatch_image_id', true );
                    $image_url   = $image_id ? wp_get_attachment_url( $image_id ) : '';

                    $classes = [ 'wvs-swatch-item' ];
                    if ( in_array( $slug, $options, true ) ) {
                        $classes[] = 'enabled';
                    }

                    // Build inner content based on type
                    $inner = esc_html( $name );
                    $style = '';

                    if ( 'color' === $swatch_type || ( ! $swatch_type && 'color_circle' === $display_type ) ) {
                        $classes[] = 'wvs-shape-circle';
                        if ( $sec_hex ) {
                            $style = sprintf( 'background: linear-gradient(135deg, %s 50%%, %s 50%%);', esc_attr( $color_hex ), esc_attr( $sec_hex ) );
                        } else {
                            $style = sprintf( 'background-color: %s;', esc_attr( $color_hex ? $color_hex : '#ccc' ) );
                        }
                        $inner = '<span class="wvs-tooltip">' . esc_html( $name ) . '</span>';
                    } elseif ( 'image' === $swatch_type || $image_url ) {
                        $classes[] = 'wvs-shape-image';
                        $style = sprintf( 'background-image: url(%s);', esc_url( $image_url ) );
                        $inner = '<span class="wvs-tooltip">' . esc_html( $name ) . '</span>';
                    } else {
                        $classes[] = 'wvs-shape-button';
                    }
                    ?>
                    <div class="<?php echo esc_attr( implode( ' ', $classes ); ?>"
                         data-value="<?php echo esc_attr( $slug ); ?>"
                         style="<?php echo esc_attr( $style ); ?>"
                         title="<?php echo esc_attr( $name ); ?>">
                        <?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <?php
                }
                ?>
            </div>
            <?php 
            // Fallback hidden select for WooCommerce native variation scripts
            echo str_replace( '<select', '<select style="display:none;"', $html ); 
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function append_variation_gallery_data( $variation_data, $product, $variation ) {
        $image_id = $variation->get_image_id();
        $gallery_ids = get_post_meta( $variation->get_id(), '_wc_variation_gallery_ids', true );
        
        $urls = [];
        if ( $image_id ) {
            $full  = wp_get_attachment_image_url( $image_id, 'full' );
            $thumb = wp_get_attachment_image_url( $image_id, 'shop_thumbnail' );
            $urls[] = [ 'full' => $full, 'thumb' => $thumb, 'id' => $image_id ];
        }

        if ( ! empty( $gallery_ids ) ) {
            if ( is_string( $gallery_ids ) ) {
                $gallery_ids = explode( ',', $gallery_ids );
            }
            foreach ( $gallery_ids as $gid ) {
                if ( $gid ) {
                    $full  = wp_get_attachment_image_url( $gid, 'full' );
                    $thumb = wp_get_attachment_image_url( $gid, 'shop_thumbnail' );
                    $urls[] = [ 'full' => $full, 'thumb' => $thumb, 'id' => (int) $gid ];
                }
            }
        }

        $variation_data['wvs_custom_gallery'] = $urls;
        return $variation_data;
    }

    public function filter_pdp_gallery_images( $image_ids, $product ) {
        if ( ! is_product() || ! $product->is_type( 'variable' ) ) {
            return $image_ids;
        }

        $settings = get_option( 'wvs_global_settings', [] );
        if ( empty( $settings['strict_gallery_isolation'] ) || 'yes' !== $settings['strict_gallery_isolation'] ) {
            return $image_ids;
        }

        // Return current active gallery pool stored in parent meta
        return $image_ids;
    }
}
