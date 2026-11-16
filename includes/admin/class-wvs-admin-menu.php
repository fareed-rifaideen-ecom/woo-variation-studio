<?php
/**
 * Admin Menu & 3-Tab Visual Studio Dashboard
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_admin_menu() {
        add_menu_page(
            __( 'Woo Variation Studio', 'woo-variation-studio' ),
            __( 'Variation Studio', 'woo-variation-studio' ),
            'manage_woocommerce',
            'wvs-studio',
            [ $this, 'render_admin_page' ],
            'dashicons-art',
            56
        );
    }

    public function register_settings() {
        register_setting( 'wvs_studio_options_group', 'wvs_global_settings', [ $this, 'sanitize_settings' ] );
    }

    public function sanitize_settings( $input ) {
        $clean = [];
        if ( is_array( $input ) ) {
            foreach ( $input as $key => $val ) {
                if ( is_array( $val ) ) {
                    $clean[ sanitize_key( $key ) ] = array_map( 'sanitize_text_field', $val );
                } else {
                    $clean[ sanitize_key( $key ) ] = sanitize_text_field( $val );
                }
            }
        }
        return $clean;
    }

    public function render_admin_page() {
        $settings   = get_option( 'wvs_global_settings', [] );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'look_feel';
        $attributes = wc_get_attribute_taxonomies();
        ?>
        <div class="wrap wvs-admin-wrap">
            <h1><?php esc_html_e( 'Woo Variation Studio — Visual Studio Dashboard', 'woo-variation-studio' ); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wvs-studio&tab=look_feel" class="nav-tab <?php echo $active_tab === 'look_feel' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( '1. Store Look & Feel', 'woo-variation-studio' ); ?></a>
                <a href="?page=wvs-studio&tab=stock_gallery" class="nav-tab <?php echo $active_tab === 'stock_gallery' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( '2. Stock Autopilot', 'woo-variation-studio' ); ?></a>
                <a href="?page=wvs-studio&tab=developer" class="nav-tab <?php echo $active_tab === 'developer' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Developer & System Tools', 'woo-variation-studio' ); ?></a>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields( 'wvs_studio_options_group' ); ?>

                <div class="wvs-tab-content" style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-top:0; border-top:none;">
                    <?php if ( $active_tab === 'look_feel' ) : ?>
                        <h2><?php esc_html_e( 'Store Look & Feel & Attribute Mapping', 'woo-variation-studio' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Configure shop archive cards, cross-attribute availability modes, gallery isolation, and default attribute display types.', 'woo-variation-studio' ); ?></p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Shop Card Style Preset', 'woo-variation-studio' ); ?></th>
                                <td>
                                    <select name="wvs_global_settings[card_style]">
                                        <option value="style_a" <?php selected( $settings['card_style'] ?? 'style_a', 'style_a' ); ?>><?php esc_html_e( 'Style A: Pro Technical Card (Specialized)', 'woo-variation-studio' ); ?></option>
                                        <option value="style_b" <?php selected( $settings['card_style'] ?? 'style_a', 'style_b' ); ?>><?php esc_html_e( 'Style B: Minimalist Showroom (Pinarello)', 'woo-variation-studio' ); ?></option>
                                        <option value="style_c" <?php selected( $settings['card_style'] ?? 'style_a', 'style_c' ); ?>><?php esc_html_e( 'Style C: Quick-Add Size Bar (Zara)', 'woo-variation-studio' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Cross-Availability Mode', 'woo-variation-studio' ); ?></th>
                                <td>
                                    <select name="wvs_global_settings[cross_attr_mode]">
                                        <option value="smart_switch" <?php selected( $settings['cross_attr_mode'] ?? 'smart_switch', 'smart_switch' ); ?>><?php esc_html_e( 'Smart Switch (Cross out unavailable; auto-switch available combination)', 'woo-variation-studio' ); ?></option>
                                        <option value="strict_lock" <?php selected( $settings['cross_attr_mode'] ?? 'smart_switch', 'strict_lock' ); ?>><?php esc_html_e( 'Strict Lock (Cross out and disable unavailable options)', 'woo-variation-studio' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Strict Gallery Isolation', 'woo-variation-studio' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wvs_global_settings[strict_gallery_isolation]" value="yes" <?php checked( $settings['strict_gallery_isolation'] ?? 'yes', 'yes' ); ?>>
                                        <?php esc_html_e( 'Show STRICTLY ONLY the selected variation\'s images in the Flatsome product gallery', 'woo-variation-studio' ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <h3><?php esc_html_e( 'Global Attribute Display Mapping', 'woo-variation-studio' ); ?></h3>
                        <table class="widefat striped" style="margin-top:10px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Attribute Name', 'woo-variation-studio' ); ?></th>
                                    <th><?php esc_html_e( 'Default Display Type', 'woo-variation-studio' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( ! empty( $attributes ) ) : foreach ( $attributes as $attr ) : 
                                    $current_mode = $settings['attr_display_' . $attr->attribute_name] ?? 'auto_image';
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $attr->attribute_label ); ?></strong> (<?php echo esc_html( $attr->attribute_name ); ?>)</td>
                                        <td>
                                            <select name="wvs_global_settings[attr_display_<?php echo esc_attr( $attr->attribute_name ); ?>]">
                                                <option value="auto_image" <?php selected( $current_mode, 'auto_image' ); ?>><?php esc_html_e( 'Auto Variation Thumbnail Image', 'woo-variation-studio' ); ?></option>
                                                <option value="color_circle" <?php selected( $current_mode, 'color_circle' ); ?>><?php esc_html_e( 'Colour / Gradient Circle', 'woo-variation-studio' ); ?></option>
                                                <option value="text_box" <?php selected( $current_mode, 'text_box' ); ?>><?php esc_html_e( 'Text Button Box', 'woo-variation-studio' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; else : ?>
                                    <tr><td colspan="2"><?php esc_html_e( 'No global WooCommerce attributes found.', 'woo-variation-studio' ); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif ( $active_tab === 'stock_gallery' ) : ?>
                        <h2><?php esc_html_e( 'Smart Stock & Gallery Autopilot', 'woo-variation-studio' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Auto-Set Main Image by Stock', 'woo-variation-studio' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="wvs_global_settings[auto_stock_winner]" value="yes" <?php checked( $settings['auto_stock_winner'] ?? 'yes', 'yes' ); ?>> <?php esc_html_e( 'Automatically promote the highest-stocked colorway as the parent catalog image.', 'woo-variation-studio' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Auto-Copy Variation Galleries', 'woo-variation-studio' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="wvs_global_settings[auto_copy_galleries]" value="yes" <?php checked( $settings['auto_copy_galleries'] ?? 'yes', 'yes' ); ?>> <?php esc_html_e( 'Automatically copy uploaded variation photos across all sibling sizes of the same colorway.', 'woo-variation-studio' ); ?></label>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ( $active_tab === 'developer' ) : ?>
                        <h2><?php esc_html_e( 'Developer & System Tools', 'woo-variation-studio' ); ?></h2>
                        <p><?php esc_html_e( 'Module diagnostics and database configuration state.', 'woo-variation-studio' ); ?></p>
                        <p><strong><?php esc_html_e( 'Plugin Version:', 'woo-variation-studio' ); ?></strong> <?php echo esc_html( WVS_VERSION ); ?></p>
                        <p><strong><?php esc_html_e( 'Database Version:', 'woo-variation-studio' ); ?></strong> <?php echo esc_html( get_option( 'wvs_db_version', '1.0.0' ) ); ?></p>
                    <?php endif; ?>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
