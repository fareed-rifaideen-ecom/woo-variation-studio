<?php
/**
 * Attribute Term Swatch Fields (Colour Hex, Split Gradient, Image Uploader)
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Attribute_Fields {

    public function __construct() {
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if ( ! empty( $attribute_taxonomies ) ) {
            foreach ( $attribute_taxonomies as $tax ) {
                $taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
                add_action( "{$taxonomy}_add_form_fields", [ $this, 'add_term_fields' ] );
                add_action( "{$taxonomy}_edit_form_fields", [ $this, 'edit_term_fields' ], 10, 2 );
            }
        }
        add_action( 'created_term', [ $this, 'save_term_meta' ], 10, 3 );
        add_action( 'edited_term', [ $this, 'save_term_meta' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'edit-tags.php' !== $hook && 'term.php' !== $hook ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }

    public function add_term_fields( $taxonomy ) {
        ?>
        <div class="form-field">
            <label for="wvs_swatch_type"><?php esc_html_e( 'Swatch Display Type', 'woo-variation-studio' ); ?></label>
            <select name="wvs_swatch_type" id="wvs_swatch_type">
                <option value="color"><?php esc_html_e( 'Colour / Gradient Circle', 'woo-variation-studio' ); ?></option>
                <option value="image"><?php esc_html_e( 'Custom Swatch Image', 'woo-variation-studio' ); ?></option>
                <option value="text"><?php esc_html_e( 'Text Button Box', 'woo-variation-studio' ); ?></option>
            </select>
        </div>
        <div class="form-field">
            <label for="wvs_color_hex"><?php esc_html_e( 'Primary Colour Hex', 'woo-variation-studio' ); ?></label>
            <input type="text" name="wvs_color_hex" id="wvs_color_hex" value="#000000" class="wvs-color-picker" />
        </div>
        <div class="form-field">
            <label for="wvs_secondary_color_hex"><?php esc_html_e( 'Secondary Colour Hex (Optional Split Gradient)', 'woo-variation-studio' ); ?></label>
            <input type="text" name="wvs_secondary_color_hex" id="wvs_secondary_color_hex" value="" class="wvs-color-picker" />
        </div>
        <div class="form-field">
            <label for="wvs_swatch_image_id"><?php esc_html_e( 'Swatch Image ID', 'woo-variation-studio' ); ?></label>
            <input type="hidden" name="wvs_swatch_image_id" id="wvs_swatch_image_id" value="" />
            <div id="wvs-swatch-image-wrapper"></div>
            <button type="button" class="button wvs-upload-image-btn" style="margin-top:5px;"><?php esc_html_e( 'Upload Swatch Image', 'woo-variation-studio' ); ?></button>
        </div>
        <?php
    }

    public function edit_term_fields( $term, $taxonomy ) {
        $swatch_type   = get_term_meta( $term->term_id, 'wvs_swatch_type', true );
        $color_hex     = get_term_meta( $term->term_id, 'wvs_color_hex', true );
        $secondary_hex = get_term_meta( $term->term_id, 'wvs_secondary_color_hex', true );
        $image_id      = get_term_meta( $term->term_id, 'wvs_swatch_image_id', true );
        $image_url     = $image_id ? wp_get_attachment_url( $image_id ) : '';
        ?>
        <tr class="form-field">
            <th scope="row"><label for="wvs_swatch_type"><?php esc_html_e( 'Swatch Display Type', 'woo-variation-studio' ); ?></label></th>
            <td>
                <select name="wvs_swatch_type" id="wvs_swatch_type">
                    <option value="color" <?php selected( $swatch_type, 'color' ); ?>><?php esc_html_e( 'Colour / Gradient Circle', 'woo-variation-studio' ); ?></option>
                    <option value="image" <?php selected( $swatch_type, 'image' ); ?>><?php esc_html_e( 'Custom Swatch Image', 'woo-variation-studio' ); ?></option>
                    <option value="text" <?php selected( $swatch_type, 'text' ); ?>><?php esc_html_e( 'Text Button Box', 'woo-variation-studio' ); ?></option>
                </select>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="wvs_color_hex"><?php esc_html_e( 'Primary Colour Hex', 'woo-variation-studio' ); ?></label></th>
            <td><input type="text" name="wvs_color_hex" id="wvs_color_hex" value="<?php echo esc_attr( $color_hex ); ?>" class="wvs-color-picker" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="wvs_secondary_color_hex"><?php esc_html_e( 'Secondary Colour Hex', 'woo-variation-studio' ); ?></label></th>
            <td><input type="text" name="wvs_secondary_color_hex" id="wvs_secondary_color_hex" value="<?php echo esc_attr( $secondary_hex ); ?>" class="wvs-color-picker" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="wvs_swatch_image_id"><?php esc_html_e( 'Swatch Image', 'woo-variation-studio' ); ?></label></th>
            <td>
                <input type="hidden" name="wvs_swatch_image_id" id="wvs_swatch_image_id" value="<?php echo esc_attr( $image_id ); ?>" />
                <div id="wvs-swatch-image-wrapper" style="margin-bottom:10px;">
                    <?php if ( $image_url ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" style="max-width:60px; height:auto; display:block;" />
                    <?php endif; ?>
                </div>
                <button type="button" class="button wvs-upload-image-btn"><?php esc_html_e( 'Upload Swatch Image', 'woo-variation-studio' ); ?></button>
            </td>
        </tr>
        <?php
    }

    public function save_term_meta( $term_id, $tt_id = '', $taxonomy = '' ) {
        if ( isset( $_POST['wvs_swatch_type'] ) ) {
            update_term_meta( $term_id, 'wvs_swatch_type', sanitize_text_field( $_POST['wvs_swatch_type'] ) );
        }
        if ( isset( $_POST['wvs_color_hex'] ) ) {
            update_term_meta( $term_id, 'wvs_color_hex', sanitize_hex_color( $_POST['wvs_color_hex'] ) );
        }
        if ( isset( $_POST['wvs_secondary_color_hex'] ) ) {
            update_term_meta( $term_id, 'wvs_secondary_color_hex', sanitize_hex_color( $_POST['wvs_secondary_color_hex'] ) );
        }
        if ( isset( $_POST['wvs_swatch_image_id'] ) ) {
            update_term_meta( $term_id, 'wvs_swatch_image_id', absint( $_POST['wvs_swatch_image_id'] ) );
        }
    }
}
