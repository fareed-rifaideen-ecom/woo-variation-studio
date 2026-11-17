<?php
/**
 * Attribute Type Selector & Term Swatch Fields
 *
 * File Path: includes/admin/class-wvs-attribute-fields.php
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVS_Attribute_Fields {

	/**
	 * Singleton instance.
	 *
	 * @var WVS_Attribute_Fields|null
	 */
	private static $instance = null;

	/**
	 * Initialize class.
	 *
	 * @return WVS_Attribute_Fields
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// 1. Add Color, Image, Button, and Radio to Products > Attributes > Type dropdown.
		add_filter( 'product_attributes_type_selector', array( $this, 'register_attribute_types' ), 10, 1 );

		// 2. Render term selection multiselect inside Product Data > Attributes for custom swatch types.
		add_action( 'woocommerce_product_option_terms', array( $this, 'render_product_attribute_terms_selector' ), 10, 3 );

		// 2B. Render Per-Product Attribute Swatch Type & Custom Term Configurator inside Product Data > Attributes.
		add_action( 'woocommerce_after_product_attribute_settings', array( $this, 'render_per_product_attribute_swatch_settings' ), 10, 2 );
		add_action( 'wp_ajax_woocommerce_save_attributes', array( $this, 'ajax_save_per_product_attribute_swatch_settings' ), 5 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_per_product_attribute_swatch_settings_on_post_save' ), 20, 1 );

		// 3. Register term fields and preview columns for all global product attributes.
		add_action( 'admin_init', array( $this, 'register_taxonomy_hooks' ) );

		// 4. Save term metadata on create and edit.
		add_action( 'created_term', array( $this, 'save_term_meta' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term_meta' ), 10, 3 );

		// 5. Enqueue WP Color Picker and Media Uploader on attribute term screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_admin_inline_script' ) );
	}

	/**
	 * Enqueue color picker and media uploader on attribute term pages and product edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_term_screen    = ( 'edit-tags.php' === $hook || 'term.php' === $hook ) && 0 === strpos( (string) $screen->taxonomy, 'pa_' );
		$is_product_screen = ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'product' === $screen->post_type;

		if ( ! $is_term_screen && ! $is_product_screen ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Render "Swatch Display Type" and per-option Color/Image pickers directly inside Product Data > Attributes.
	 *
	 * @param WC_Product_Attribute|object $attribute Attribute object.
	 * @param int                         $i         Attribute row index.
	 */
	public function render_per_product_attribute_swatch_settings( $attribute, $i ) {
		global $post;

		$product_id = 0;
		if ( $post instanceof WP_Post ) {
			$product_id = (int) $post->ID;
		} elseif ( isset( $_POST['post_id'] ) ) {
			$product_id = absint( $_POST['post_id'] );
		}

		$attr_name = '';
		$options   = array();
		$is_tax    = false;

		if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
			$attr_name = (string) $attribute->get_name();
			$options   = is_array( $attribute->get_options() ) ? $attribute->get_options() : array();
			$is_tax    = (bool) $attribute->is_taxonomy();
		}

		$attr_key     = sanitize_title( $attr_name );
		$saved_config = $product_id > 0 ? get_post_meta( $product_id, '_wvs_product_attr_settings', true ) : array();
		if ( ! is_array( $saved_config ) ) {
			$saved_config = array();
		}

		$attr_settings = isset( $saved_config[ $attr_key ] ) && is_array( $saved_config[ $attr_key ] ) ? $saved_config[ $attr_key ] : array();
		$selected_type = isset( $attr_settings['type'] ) ? (string) $attr_settings['type'] : 'auto';
		$terms_config  = isset( $attr_settings['terms'] ) && is_array( $attr_settings['terms'] ) ? $attr_settings['terms'] : array();
		?>
		<tr>
			<td class="wvs-product-attr-settings-cell" colspan="2" style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:12px 14px;">
				<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
					<div>
						<label style="font-weight:600; color:#0f172a; display:block; margin-bottom:4px;">
							<?php esc_html_e( 'Variation Studio — Swatch Display Type:', 'woo-variation-studio' ); ?>
						</label>
						<select name="wvs_attr_type[<?php echo esc_attr( (string) $i ); ?>]" class="wvs-product-attr-type-select" data-row-index="<?php echo esc_attr( (string) $i ); ?>" style="min-width:280px;">
							<option value="auto" <?php selected( $selected_type, 'auto' ); ?>><?php esc_html_e( 'Auto-Detect (Smart Image for Colour / Button for Size)', 'woo-variation-studio' ); ?></option>
							<option value="auto_image" <?php selected( $selected_type, 'auto_image' ); ?>><?php esc_html_e( 'Auto Variation Thumbnail Image (Uses Variation Photo)', 'woo-variation-studio' ); ?></option>
							<option value="color_circle" <?php selected( $selected_type, 'color_circle' ); ?>><?php esc_html_e( 'Color / Dual-Color Swatch Circle', 'woo-variation-studio' ); ?></option>
							<option value="custom_image" <?php selected( $selected_type, 'custom_image' ); ?>><?php esc_html_e( 'Custom Swatch Image per Option', 'woo-variation-studio' ); ?></option>
							<option value="text_box" <?php selected( $selected_type, 'text_box' ); ?>><?php esc_html_e( 'Button / Label Box', 'woo-variation-studio' ); ?></option>
							<option value="radio" <?php selected( $selected_type, 'radio' ); ?>><?php esc_html_e( 'Radio Button List', 'woo-variation-studio' ); ?></option>
							<option value="dropdown" <?php selected( $selected_type, 'dropdown' ); ?>><?php esc_html_e( 'Default WooCommerce Dropdown', 'woo-variation-studio' ); ?></option>
						</select>
					</div>
					<span class="description" style="font-size:12px; color:#475569;">
						<?php esc_html_e( 'Tip: Click "Save attributes" after adding new values to configure individual colors below.', 'woo-variation-studio' ); ?>
					</span>
				</div>

				<?php
				if ( ! empty( $options ) ) :
					$show_color_rows = ( 'color_circle' === $selected_type );
					$show_image_rows = ( 'custom_image' === $selected_type );
					?>
					<div class="wvs-per-product-terms-box" id="wvs-terms-box-<?php echo esc_attr( (string) $i ); ?>" style="margin-top:12px; <?php echo ( $show_color_rows || $show_image_rows ) ? '' : 'display:none;'; ?>">
						<table class="widefat striped" style="background:#fff; border:1px solid #cbd5e1;">
							<thead>
								<tr>
									<th style="padding:8px 10px; font-size:12px;"><?php esc_html_e( 'Option Value', 'woo-variation-studio' ); ?></th>
									<th class="wvs-col-color-hdr" style="padding:8px 10px; font-size:12px; <?php echo $show_color_rows ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Primary Color Hex', 'woo-variation-studio' ); ?></th>
									<th class="wvs-col-color-hdr" style="padding:8px 10px; font-size:12px; <?php echo $show_color_rows ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Secondary Color (Optional Split)', 'woo-variation-studio' ); ?></th>
									<th class="wvs-col-img-hdr" style="padding:8px 10px; font-size:12px; <?php echo $show_image_rows ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Custom Swatch Image ID / Upload', 'woo-variation-studio' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $options as $opt ) :
									$opt_label = '';
									$opt_slug  = '';
									if ( $is_tax ) {
										$term_obj = get_term( (int) $opt, $attr_name );
										if ( $term_obj && ! is_wp_error( $term_obj ) ) {
											$opt_label = $term_obj->name;
											$opt_slug  = $term_obj->slug;
										}
									} else {
										$opt_label = trim( (string) $opt );
										$opt_slug  = sanitize_title( $opt_label );
									}

									if ( '' === $opt_slug ) {
										continue;
									}

									$t_data  = isset( $terms_config[ $opt_slug ] ) && is_array( $terms_config[ $opt_slug ] ) ? $terms_config[ $opt_slug ] : array();
									$p_hex   = isset( $t_data['primary_hex'] ) ? (string) $t_data['primary_hex'] : '#0f172a';
									$s_hex   = isset( $t_data['secondary_hex'] ) ? (string) $t_data['secondary_hex'] : '';
									$img_id  = isset( $t_data['image_id'] ) ? absint( $t_data['image_id'] ) : 0;
									$img_url = $img_id > 0 ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
									?>
									<tr>
										<td style="padding:8px 10px; vertical-align:middle;">
											<strong><?php echo esc_html( $opt_label ); ?></strong>
										</td>
										<td class="wvs-col-color-cell" style="padding:8px 10px; vertical-align:middle; <?php echo $show_color_rows ? '' : 'display:none;'; ?>">
											<input type="color" name="wvs_attr_term_primary_hex[<?php echo esc_attr( (string) $i ); ?>][<?php echo esc_attr( $opt_slug ); ?>]" value="<?php echo esc_attr( '' !== $p_hex ? $p_hex : '#0f172a' ); ?>" style="width:44px; height:30px; padding:0; border:1px solid #cbd5e1; vertical-align:middle; cursor:pointer;" />
										</td>
										<td class="wvs-col-color-cell" style="padding:8px 10px; vertical-align:middle; <?php echo $show_color_rows ? '' : 'display:none;'; ?>">
											<input type="text" name="wvs_attr_term_secondary_hex[<?php echo esc_attr( (string) $i ); ?>][<?php echo esc_attr( $opt_slug ); ?>]" value="<?php echo esc_attr( $s_hex ); ?>" placeholder="#ffffff (Optional)" style="width:140px; font-size:12px;" />
										</td>
										<td class="wvs-col-img-cell" style="padding:8px 10px; vertical-align:middle; <?php echo $show_image_rows ? '' : 'display:none;'; ?>">
											<div style="display:flex; align-items:center; gap:8px;">
												<input type="hidden" class="wvs-inline-img-id" name="wvs_attr_term_image_id[<?php echo esc_attr( (string) $i ); ?>][<?php echo esc_attr( $opt_slug ); ?>]" value="<?php echo esc_attr( (string) $img_id ); ?>" />
												<span class="wvs-inline-img-preview" style="width:32px; height:32px; border:1px solid #cbd5e1; border-radius:4px; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background:#f8fafc;">
													<?php if ( $img_url ) : ?>
														<img src="<?php echo esc_url( $img_url ); ?>" style="width:100%; height:100%; object-fit:cover;" alt="" />
													<?php else : ?>
														<span style="font-size:9px; color:#94a3b8;">None</span>
													<?php endif; ?>
												</span>
												<button type="button" class="button button-small wvs-inline-upload-btn"><?php esc_html_e( 'Select Image', 'woo-variation-studio' ); ?></button>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save Per-Product Attribute Swatch settings when clicking "Save attributes" via AJAX.
	 */
	public function ajax_save_per_product_attribute_swatch_settings() {
		check_ajax_referer( 'save-attributes', 'security' );

		if ( ! current_user_can( 'edit_products' ) || empty( $_POST['post_id'] ) || empty( $_POST['data'] ) ) {
			return;
		}

		$post_id = absint( $_POST['post_id'] );
		$raw     = wp_unslash( $_POST['data'] );
		$parsed  = array();
		parse_str( $raw, $parsed );

		$this->persist_per_product_attribute_settings( $post_id, $parsed );
	}

	/**
	 * Save Per-Product Attribute Swatch settings when clicking the main "Update / Publish" product button.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_per_product_attribute_swatch_settings_on_post_save( $post_id ) {
		if ( ! isset( $_POST['wvs_attr_type'] ) ) {
			return;
		}
		$this->persist_per_product_attribute_settings( (int) $post_id, wp_unslash( $_POST ) );
	}

	/**
	 * Parse and save per-product attribute swatch settings to `_wvs_product_attr_settings` post meta.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $data       Parsed form array.
	 */
	private function persist_per_product_attribute_settings( $product_id, $data ) {
		if ( $product_id <= 0 || empty( $data['attribute_names'] ) || ! is_array( $data['attribute_names'] ) ) {
			return;
		}

		$saved = get_post_meta( $product_id, '_wvs_product_attr_settings', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$attr_names = $data['attribute_names'];
		$attr_types = isset( $data['wvs_attr_type'] ) && is_array( $data['wvs_attr_type'] ) ? $data['wvs_attr_type'] : array();
		$p_hexes    = isset( $data['wvs_attr_term_primary_hex'] ) && is_array( $data['wvs_attr_term_primary_hex'] ) ? $data['wvs_attr_term_primary_hex'] : array();
		$s_hexes    = isset( $data['wvs_attr_term_secondary_hex'] ) && is_array( $data['wvs_attr_term_secondary_hex'] ) ? $data['wvs_attr_term_secondary_hex'] : array();
		$img_ids    = isset( $data['wvs_attr_term_image_id'] ) && is_array( $data['wvs_attr_term_image_id'] ) ? $data['wvs_attr_term_image_id'] : array();

		foreach ( $attr_names as $idx => $raw_name ) {
			$attr_key = sanitize_title( (string) $raw_name );
			if ( '' === $attr_key ) {
				continue;
			}

			$type  = isset( $attr_types[ $idx ] ) ? sanitize_key( (string) $attr_types[ $idx ] ) : 'auto';
			$terms = isset( $saved[ $attr_key ]['terms'] ) && is_array( $saved[ $attr_key ]['terms'] ) ? $saved[ $attr_key ]['terms'] : array();

			if ( isset( $p_hexes[ $idx ] ) && is_array( $p_hexes[ $idx ] ) ) {
				foreach ( $p_hexes[ $idx ] as $t_slug => $hex_val ) {
					$clean_slug = sanitize_title( (string) $t_slug );
					$clean_hex  = sanitize_hex_color( (string) $hex_val );
					if ( ! isset( $terms[ $clean_slug ] ) ) {
						$terms[ $clean_slug ] = array();
					}
					$terms[ $clean_slug ]['primary_hex'] = $clean_hex ? $clean_hex : '#0f172a';
				}
			}

			if ( isset( $s_hexes[ $idx ] ) && is_array( $s_hexes[ $idx ] ) ) {
				foreach ( $s_hexes[ $idx ] as $t_slug => $hex_val ) {
					$clean_slug = sanitize_title( (string) $t_slug );
					$clean_hex  = sanitize_hex_color( trim( (string) $hex_val ) );
					if ( ! isset( $terms[ $clean_slug ] ) ) {
						$terms[ $clean_slug ] = array();
					}
					$terms[ $clean_slug ]['secondary_hex'] = $clean_hex ? $clean_hex : '';
				}
			}

			if ( isset( $img_ids[ $idx ] ) && is_array( $img_ids[ $idx ] ) ) {
				foreach ( $img_ids[ $idx ] as $t_slug => $id_val ) {
					$clean_slug = sanitize_title( (string) $t_slug );
					if ( ! isset( $terms[ $clean_slug ] ) ) {
						$terms[ $clean_slug ] = array();
					}
					$terms[ $clean_slug ]['image_id'] = absint( $id_val );
				}
			}

			$saved[ $attr_key ] = array(
				'type'  => $type,
				'terms' => $terms,
			);
		}

		update_post_meta( $product_id, '_wvs_product_attr_settings', $saved );
	}

	/**
	 * Ensure term multiselect box appears in Product Data > Attributes when type is color, image, button, or radio.
	 *
	 * @param object                    $attribute_taxonomy Attribute taxonomy object.
	 * @param int                       $i                  Loop index.
	 * @param WC_Product_Attribute|null $attribute          Product attribute object.
	 */
	public function render_product_attribute_terms_selector( $attribute_taxonomy, $i, $attribute = null ) {
		if ( ! is_object( $attribute_taxonomy ) || empty( $attribute_taxonomy->attribute_type ) ) {
			return;
		}

		$custom_types = array( 'color', 'image', 'button', 'radio' );
		if ( ! in_array( $attribute_taxonomy->attribute_type, $custom_types, true ) ) {
			return;
		}

		$taxonomy_name = wc_attribute_taxonomy_name( $attribute_taxonomy->attribute_name );
		$options       = array();

		if ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
			$options = $attribute->get_options();
			$options = ! empty( $options ) ? $options : array();
		}
		?>
		<select multiple="multiple"
			data-placeholder="<?php esc_attr_e( 'Select terms', 'woo-variation-studio' ); ?>"
			class="multiselect attribute_values wc-enhanced-select"
			name="attribute_values[<?php echo esc_attr( (string) $i ); ?>][]">
			<?php
			$all_terms = get_terms(
				array(
					'taxonomy'   => $taxonomy_name,
					'orderby'    => ! empty( $attribute_taxonomy->attribute_orderby ) ? $attribute_taxonomy->attribute_orderby : 'name',
					'hide_empty' => false,
				)
			);

			if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
				foreach ( $all_terms as $term ) {
					$selected = in_array( $term->term_id, $options, true ) || in_array( (string) $term->term_id, $options, true );
					echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( $selected, true, false ) . '>' . esc_html( apply_filters( 'woocommerce_product_attribute_term_name', $term->name, $term ) ) . '</option>';
				}
			}
			?>
		</select>
		<button class="button plus select_all_attributes"><?php esc_html_e( 'Select all', 'woo-variation-studio' ); ?></button>
		<button class="button minus select_none_attributes"><?php esc_html_e( 'Select none', 'woo-variation-studio' ); ?></button>
		<button class="button fr plus add_new_attribute"><?php esc_html_e( 'Add new', 'woo-variation-studio' ); ?></button>
		<?php
	}

	/**
	 * Hook into all WooCommerce attribute taxonomies for term fields and preview columns.
	 */
	public function register_taxonomy_hooks() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( empty( $attribute_taxonomies ) ) {
			return;
		}

		foreach ( $attribute_taxonomies as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'add_term_fields' ), 10, 1 );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'edit_term_fields' ), 10, 2 );

			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_preview_column' ), 10, 1 );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_preview_column' ), 10, 3 );
		}
	}

	/**
	 * Get the configured attribute type (select, color, image, button, radio) for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug (e.g. pa_color).
	 * @return string
	 */
	public static function get_taxonomy_attribute_type( $taxonomy ) {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return 'select';
		}

		$clean_name = preg_replace( '/^pa_/', '', (string) $taxonomy );
		$taxonomies = wc_get_attribute_taxonomies();

		if ( ! empty( $taxonomies ) ) {
			foreach ( $taxonomies as $tax ) {
				if ( $tax->attribute_name === $clean_name ) {
					return ! empty( $tax->attribute_type ) ? (string) $tax->attribute_type : 'select';
				}
			}
		}

		return 'select';
	}

	/**
	 * Enqueue color picker and media uploader on attribute term pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'edit-tags.php' !== $hook && 'term.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 0 !== strpos( (string) $screen->taxonomy, 'pa_' ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Render fields on the "Add New Term" screen (Products > Attributes > Configure terms).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function add_term_fields( $taxonomy ) {
		$attr_type = self::get_taxonomy_attribute_type( $taxonomy );
		?>
		<div class="form-field wvs-term-field-wrap">
			<label for="wvs_swatch_type"><?php esc_html_e( 'Swatch Type Override', 'woo-variation-studio' ); ?></label>
			<select name="wvs_swatch_type" id="wvs_swatch_type" class="wvs-swatch-type-select">
				<option value="" <?php selected( $attr_type, 'select' ); ?>><?php esc_html_e( 'Use Attribute Default', 'woo-variation-studio' ); ?> (<?php echo esc_html( ucfirst( $attr_type ) ); ?>)</option>
				<option value="color" <?php selected( $attr_type, 'color' ); ?>><?php esc_html_e( 'Color Swatch', 'woo-variation-studio' ); ?></option>
				<option value="image" <?php selected( $attr_type, 'image' ); ?>><?php esc_html_e( 'Image Swatch', 'woo-variation-studio' ); ?></option>
				<option value="button" <?php selected( $attr_type, 'button' ); ?>><?php esc_html_e( 'Button / Label', 'woo-variation-studio' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Inherits the Attribute Type automatically or lets you override this specific term.', 'woo-variation-studio' ); ?></p>
		</div>

		<div class="form-field wvs-field-group wvs-group-color">
			<label for="wvs_color_hex"><?php esc_html_e( 'Primary Swatch Color', 'woo-variation-studio' ); ?></label>
			<input type="text" name="wvs_color_hex" id="wvs_color_hex" value="#0f172a" class="wvs-color-picker" data-default-color="#0f172a" />
			<button type="button" class="button button-small wvs-auto-match-color" style="margin-top:4px;"><?php esc_html_e( 'Auto-Match from Term Name', 'woo-variation-studio' ); ?></button>
		</div>

		<div class="form-field wvs-field-group wvs-group-color">
			<label for="wvs_is_dual_color">
				<input type="checkbox" name="wvs_is_dual_color" id="wvs_is_dual_color" value="yes" />
				<?php esc_html_e( 'Enable Dual Color / Split Swatch', 'woo-variation-studio' ); ?>
			</label>
		</div>

		<div class="form-field wvs-field-group wvs-group-dual-color" style="display:none;">
			<label for="wvs_secondary_color_hex"><?php esc_html_e( 'Secondary Swatch Color', 'woo-variation-studio' ); ?></label>
			<input type="text" name="wvs_secondary_color_hex" id="wvs_secondary_color_hex" value="" class="wvs-color-picker" />
			<label for="wvs_split_angle" style="margin-top:8px; display:block;"><?php esc_html_e( 'Dual Color Split Direction', 'woo-variation-studio' ); ?></label>
			<select name="wvs_split_angle" id="wvs_split_angle">
				<option value="135deg"><?php esc_html_e( 'Angled 45° Split', 'woo-variation-studio' ); ?></option>
				<option value="180deg"><?php esc_html_e( 'Horizontal 50/50 Split', 'woo-variation-studio' ); ?></option>
				<option value="90deg"><?php esc_html_e( 'Vertical 50/50 Split', 'woo-variation-studio' ); ?></option>
			</select>
		</div>

		<div class="form-field wvs-field-group wvs-group-image">
			<label><?php esc_html_e( 'Swatch Image', 'woo-variation-studio' ); ?></label>
			<input type="hidden" name="wvs_swatch_image_id" id="wvs_swatch_image_id" value="" />
			<div id="wvs-swatch-image-preview" style="margin-bottom:8px; width:60px; height:60px; border:1px dashed #cbd5e1; border-radius:4px; display:flex; align-items:center; justify-content:center; background:#f8fafc; overflow:hidden;">
				<span style="font-size:11px; color:#94a3b8;"><?php esc_html_e( 'No image', 'woo-variation-studio' ); ?></span>
			</div>
			<button type="button" class="button wvs-upload-image-btn"><?php esc_html_e( 'Upload / Add Image', 'woo-variation-studio' ); ?></button>
			<button type="button" class="button wvs-remove-image-btn" style="display:none; color:#b91c1c;"><?php esc_html_e( 'Remove', 'woo-variation-studio' ); ?></button>
		</div>

		<div class="form-field">
			<label for="wvs_term_tooltip"><?php esc_html_e( 'Custom Hover Tooltip (Optional)', 'woo-variation-studio' ); ?></label>
			<input type="text" name="wvs_term_tooltip" id="wvs_term_tooltip" value="" placeholder="<?php esc_attr_e( 'Defaults to term name', 'woo-variation-studio' ); ?>" />
		</div>
		<?php
	}

	/**
	 * Render fields on the "Edit Term" screen.
	 *
	 * @param WP_Term $term     Term object.
	 * @param string  $taxonomy Taxonomy slug.
	 */
	public function edit_term_fields( $term, $taxonomy ) {
		$attr_type     = self::get_taxonomy_attribute_type( $taxonomy );
		$swatch_type   = (string) get_term_meta( $term->term_id, 'wvs_swatch_type', true );
		$color_hex     = (string) get_term_meta( $term->term_id, 'wvs_color_hex', true );
		if ( '' === $color_hex ) {
			$color_hex = (string) get_term_meta( $term->term_id, 'product_attribute_color', true );
		}
		if ( '' === $color_hex ) {
			$color_hex = (string) get_term_meta( $term->term_id, '_wvs_primary_hex', true );
		}

		$is_dual       = (string) get_term_meta( $term->term_id, 'wvs_is_dual_color', true );
		$secondary_hex = (string) get_term_meta( $term->term_id, 'wvs_secondary_color_hex', true );
		if ( '' === $secondary_hex ) {
			$secondary_hex = (string) get_term_meta( $term->term_id, '_wvs_secondary_hex', true );
		}
		if ( '' !== $secondary_hex && '' === $is_dual ) {
			$is_dual = 'yes';
		}

		$split_angle = (string) get_term_meta( $term->term_id, 'wvs_split_angle', true );
		if ( '' === $split_angle ) {
			$split_angle = '135deg';
		}

		$image_id = (int) get_term_meta( $term->term_id, 'wvs_swatch_image_id', true );
		if ( ! $image_id ) {
			$image_id = (int) get_term_meta( $term->term_id, 'product_attribute_image', true );
		}
		if ( ! $image_id ) {
			$image_id = (int) get_term_meta( $term->term_id, '_wvs_swatch_image_id', true );
		}
		$image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		$tooltip = (string) get_term_meta( $term->term_id, 'wvs_term_tooltip', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="wvs_swatch_type"><?php esc_html_e( 'Swatch Type Override', 'woo-variation-studio' ); ?></label></th>
			<td>
				<select name="wvs_swatch_type" id="wvs_swatch_type" class="wvs-swatch-type-select">
					<option value="" <?php selected( $swatch_type, '' ); ?>><?php esc_html_e( 'Use Attribute Default', 'woo-variation-studio' ); ?> (<?php echo esc_html( ucfirst( $attr_type ) ); ?>)</option>
					<option value="color" <?php selected( $swatch_type, 'color' ); ?>><?php esc_html_e( 'Color Swatch', 'woo-variation-studio' ); ?></option>
					<option value="image" <?php selected( $swatch_type, 'image' ); ?>><?php esc_html_e( 'Image Swatch', 'woo-variation-studio' ); ?></option>
					<option value="button" <?php selected( $swatch_type, 'button' ); ?>><?php esc_html_e( 'Button / Label', 'woo-variation-studio' ); ?></option>
				</select>
			</td>
		</tr>

		<tr class="form-field wvs-field-group wvs-group-color">
			<th scope="row"><label for="wvs_color_hex"><?php esc_html_e( 'Primary Swatch Color', 'woo-variation-studio' ); ?></label></th>
			<td>
				<input type="text" name="wvs_color_hex" id="wvs_color_hex" value="<?php echo esc_attr( $color_hex ? $color_hex : '#0f172a' ); ?>" class="wvs-color-picker" />
				<button type="button" class="button button-small wvs-auto-match-color" style="margin-left:8px;"><?php esc_html_e( 'Auto-Match from Term Name', 'woo-variation-studio' ); ?></button>
			</td>
		</tr>

		<tr class="form-field wvs-field-group wvs-group-color">
			<th scope="row"><?php esc_html_e( 'Dual Color Swatch', 'woo-variation-studio' ); ?></th>
			<td>
				<label for="wvs_is_dual_color">
					<input type="checkbox" name="wvs_is_dual_color" id="wvs_is_dual_color" value="yes" <?php checked( $is_dual, 'yes' ); ?> />
					<?php esc_html_e( 'Enable Secondary Split Color', 'woo-variation-studio' ); ?>
				</label>
			</td>
		</tr>

		<tr class="form-field wvs-field-group wvs-group-dual-color" style="<?php echo 'yes' === $is_dual ? '' : 'display:none;'; ?>">
			<th scope="row"><label for="wvs_secondary_color_hex"><?php esc_html_e( 'Secondary Swatch Color', 'woo-variation-studio' ); ?></label></th>
			<td>
				<input type="text" name="wvs_secondary_color_hex" id="wvs_secondary_color_hex" value="<?php echo esc_attr( $secondary_hex ); ?>" class="wvs-color-picker" />
				<div style="margin-top:8px;">
					<label for="wvs_split_angle" style="display:block; margin-bottom:4px;"><?php esc_html_e( 'Split Direction', 'woo-variation-studio' ); ?></label>
					<select name="wvs_split_angle" id="wvs_split_angle">
						<option value="135deg" <?php selected( $split_angle, '135deg' ); ?>><?php esc_html_e( 'Angled 45° Split', 'woo-variation-studio' ); ?></option>
						<option value="180deg" <?php selected( $split_angle, '180deg' ); ?>><?php esc_html_e( 'Horizontal 50/50 Split', 'woo-variation-studio' ); ?></option>
						<option value="90deg" <?php selected( $split_angle, '90deg' ); ?>><?php esc_html_e( 'Vertical 50/50 Split', 'woo-variation-studio' ); ?></option>
					</select>
				</div>
			</td>
		</tr>

		<tr class="form-field wvs-field-group wvs-group-image">
			<th scope="row"><label><?php esc_html_e( 'Swatch Image', 'woo-variation-studio' ); ?></label></th>
			<td>
				<input type="hidden" name="wvs_swatch_image_id" id="wvs_swatch_image_id" value="<?php echo esc_attr( (string) $image_id ); ?>" />
				<div id="wvs-swatch-image-preview" style="margin-bottom:8px; width:60px; height:60px; border:1px dashed #cbd5e1; border-radius:4px; display:flex; align-items:center; justify-content:center; background:#f8fafc; overflow:hidden;">
					<?php if ( $image_url ) : ?>
						<img src="<?php echo esc_url( $image_url ); ?>" style="width:100%; height:100%; object-fit:cover;" alt="" />
					<?php else : ?>
						<span style="font-size:11px; color:#94a3b8;"><?php esc_html_e( 'No image', 'woo-variation-studio' ); ?></span>
					<?php endif; ?>
				</div>
				<button type="button" class="button wvs-upload-image-btn"><?php esc_html_e( 'Upload / Change Image', 'woo-variation-studio' ); ?></button>
				<button type="button" class="button wvs-remove-image-btn" style="<?php echo $image_url ? '' : 'display:none;'; ?> color:#b91c1c;"><?php esc_html_e( 'Remove', 'woo-variation-studio' ); ?></button>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="wvs_term_tooltip"><?php esc_html_e( 'Custom Hover Tooltip', 'woo-variation-studio' ); ?></label></th>
			<td>
				<input type="text" name="wvs_term_tooltip" id="wvs_term_tooltip" value="<?php echo esc_attr( $tooltip ); ?>" placeholder="<?php esc_attr_e( 'Defaults to term name', 'woo-variation-studio' ); ?>" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Save term metadata for colors, split gradients, images, and tooltips.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function save_term_meta( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( isset( $_POST['wvs_swatch_type'] ) ) {
			$type = sanitize_key( wp_unslash( $_POST['wvs_swatch_type'] ) );
			update_term_meta( $term_id, 'wvs_swatch_type', $type );
		}

		if ( isset( $_POST['wvs_color_hex'] ) ) {
			$primary_hex = sanitize_hex_color( wp_unslash( $_POST['wvs_color_hex'] ) );
			$primary_hex = $primary_hex ? $primary_hex : '';
			update_term_meta( $term_id, 'wvs_color_hex', $primary_hex );
			update_term_meta( $term_id, '_wvs_primary_hex', $primary_hex );
			update_term_meta( $term_id, 'product_attribute_color', $primary_hex );
		}

		$is_dual = isset( $_POST['wvs_is_dual_color'] ) ? 'yes' : 'no';
		update_term_meta( $term_id, 'wvs_is_dual_color', $is_dual );

		if ( isset( $_POST['wvs_secondary_color_hex'] ) ) {
			$sec_hex = ( 'yes' === $is_dual ) ? sanitize_hex_color( wp_unslash( $_POST['wvs_secondary_color_hex'] ) ) : '';
			$sec_hex = $sec_hex ? $sec_hex : '';
			update_term_meta( $term_id, 'wvs_secondary_color_hex', $sec_hex );
			update_term_meta( $term_id, '_wvs_secondary_hex', $sec_hex );
		}

		if ( isset( $_POST['wvs_split_angle'] ) ) {
			$allowed_angles = array( '135deg', '180deg', '90deg' );
			$angle          = sanitize_text_field( wp_unslash( $_POST['wvs_split_angle'] ) );
			if ( ! in_array( $angle, $allowed_angles, true ) ) {
				$angle = '135deg';
			}
			update_term_meta( $term_id, 'wvs_split_angle', $angle );
		}

		if ( isset( $_POST['wvs_swatch_image_id'] ) ) {
			$img_id = absint( $_POST['wvs_swatch_image_id'] );
			update_term_meta( $term_id, 'wvs_swatch_image_id', $img_id );
			update_term_meta( $term_id, '_wvs_swatch_image_id', $img_id );
			update_term_meta( $term_id, 'product_attribute_image', $img_id );
		}

		if ( isset( $_POST['wvs_term_tooltip'] ) ) {
			update_term_meta( $term_id, 'wvs_term_tooltip', sanitize_text_field( wp_unslash( $_POST['wvs_term_tooltip'] ) ) );
		}
	}

	/**
	 * Add "Preview" column to the attribute terms list table.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_preview_column( $columns ) {
		$new_columns = array();
		if ( isset( $columns['cb'] ) ) {
			$new_columns['cb'] = $columns['cb'];
			unset( $columns['cb'] );
		}
		$new_columns['wvs_preview'] = __( 'Preview', 'woo-variation-studio' );

		return array_merge( $new_columns, $columns );
	}

	/**
	 * Render the live swatch preview box inside the attribute terms list table.
	 *
	 * @param string $content     Column HTML content.
	 * @param string $column_name Column key.
	 * @param int    $term_id     Term ID.
	 * @return string
	 */
	public function render_preview_column( $content, $column_name, $term_id ) {
		if ( 'wvs_preview' !== $column_name ) {
			return $content;
		}

		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return $content;
		}

		$attr_type   = self::get_taxonomy_attribute_type( $term->taxonomy );
		$override    = (string) get_term_meta( $term_id, 'wvs_swatch_type', true );
		$active_type = '' !== $override ? $override : $attr_type;

		$image_id = (int) get_term_meta( $term_id, 'wvs_swatch_image_id', true );
		if ( ! $image_id ) {
			$image_id = (int) get_term_meta( $term_id, 'product_attribute_image', true );
		}
		$image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		$primary_hex = (string) get_term_meta( $term_id, 'wvs_color_hex', true );
		if ( '' === $primary_hex ) {
			$primary_hex = (string) get_term_meta( $term_id, 'product_attribute_color', true );
		}
		$is_dual   = (string) get_term_meta( $term_id, 'wvs_is_dual_color', true );
		$sec_hex   = (string) get_term_meta( $term_id, 'wvs_secondary_color_hex', true );
		$angle     = (string) get_term_meta( $term_id, 'wvs_split_angle', true );
		$angle     = '' !== $angle ? $angle : '135deg';

		if ( ( 'image' === $active_type || '' !== $image_url ) && $image_url ) {
			return sprintf(
				'<span style="display:inline-block;width:34px;height:34px;border-radius:4px;border:1px solid #cbd5e1;overflow:hidden;vertical-align:middle;"><img src="%s" style="width:100%%;height:100%%;object-fit:cover;" alt="" /></span>',
				esc_url( $image_url )
			);
		}

		if ( 'color' === $active_type || '' !== $primary_hex ) {
			$hex = '' !== $primary_hex ? $primary_hex : '#cbd5e1';
			$bg  = ( 'yes' === $is_dual && '' !== $sec_hex )
				? sprintf( 'background:linear-gradient(%s, %s 50%%, %s 50%%);', esc_attr( $angle ), esc_attr( $hex ), esc_attr( $sec_hex ) )
				: sprintf( 'background-color:%s;', esc_attr( $hex ) );

			return sprintf(
				'<span style="display:inline-block;width:30px;height:30px;border-radius:50%%;border:1px solid #94a3b8;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.4);vertical-align:middle;%s" title="%s"></span>',
				$bg,
				esc_attr( $hex )
			);
		}

		return sprintf(
			'<span style="display:inline-block;padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;font-size:11px;font-weight:600;color:#0f172a;">%s</span>',
			esc_html( $term->name )
		);
	}

	/**
	 * Render inline JavaScript for WP Color Picker, Auto Color Name Matching, Media Uploader, and Product Attribute Tab toggles.
	 */
	public function render_admin_inline_script() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_term_screen    = 0 === strpos( (string) $screen->taxonomy, 'pa_' );
		$is_product_screen = 'product' === $screen->post_type;

		if ( ! $is_term_screen && ! $is_product_screen ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if ($.fn.wpColorPicker) {
				$('.wvs-color-picker').wpColorPicker();
			}

			// Toggle Per-Product Attribute Swatch Options table inside Product Data > Attributes
			$(document).on('change', '.wvs-product-attr-type-select', function() {
				var $select = $(this);
				var mode = $select.val();
				var $cell = $select.closest('.wvs-product-attr-settings-cell');
				var $box = $cell.find('.wvs-per-product-terms-box');

				if (mode === 'color_circle') {
					$box.show();
					$box.find('.wvs-col-color-hdr, .wvs-col-color-cell').show();
					$box.find('.wvs-col-img-hdr, .wvs-col-img-cell').hide();
				} else if (mode === 'custom_image') {
					$box.show();
					$box.find('.wvs-col-color-hdr, .wvs-col-color-cell').hide();
					$box.find('.wvs-col-img-hdr, .wvs-col-img-cell').show();
				} else {
					$box.hide();
				}
			});

			// Inline Media Uploader inside Product Data > Attributes
			$(document).on('click', '.wvs-inline-upload-btn', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var $wrap = $btn.closest('div');
				var frame = wp.media({
					title: 'Select Option Swatch Image',
					button: { text: 'Use Image' },
					multiple: false
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
					$wrap.find('.wvs-inline-img-id').val(attachment.id);
					$wrap.find('.wvs-inline-img-preview').html('<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;" />');
				});
				frame.open();
			});

			// Toggle Dual Color row on global term screen
			$('#wvs_is_dual_color').on('change', function() {
				if ($(this).is(':checked')) {
					$('.wvs-group-dual-color').show();
				} else {
					$('.wvs-group-dual-color').hide();
				}
			});

			// Auto-Match Color Hex from Term Name
			var colorPaletteMap = {
				'black': '#111827',
				'white': '#ffffff',
				'red': '#dc2626',
				'blue': '#2563eb',
				'navy': '#1e3a8a',
				'sky': '#38bdf8',
				'green': '#16a34a',
				'forest': '#14532d',
				'olive': '#4d7c0f',
				'yellow': '#eab308',
				'gold': '#d97706',
				'orange': '#ea580c',
				'purple': '#7c3aed',
				'pink': '#db2777',
				'grey': '#6b7280',
				'gray': '#6b7280',
				'silver': '#cbd5e1',
				'chrome': '#94a3b8',
				'carbon': '#1f2937',
				'charcoal': '#374151',
				'brown': '#78350f',
				'sand': '#d6d3d1',
				'beige': '#e7e5e4',
				'teal': '#0d9488'
			};

			$('.wvs-auto-match-color').on('click', function(e) {
				e.preventDefault();
				var termName = ($('#tag-name').val() || $('#name').val() || '').toLowerCase();
				if (!termName) {
					return;
				}
				var matched = [];
				$.each(colorPaletteMap, function(word, hex) {
					if (termName.indexOf(word) !== -1 && matched.indexOf(hex) === -1) {
						matched.push(hex);
					}
				});
				if (matched.length > 0) {
					$('#wvs_color_hex').wpColorPicker('color', matched[0]);
				}
				if (matched.length > 1) {
					$('#wvs_is_dual_color').prop('checked', true).trigger('change');
					$('#wvs_secondary_color_hex').wpColorPicker('color', matched[1]);
				}
			});

			// Media Library Uploader for Swatch Image
			var mediaFrame;
			$(document).on('click', '.wvs-upload-image-btn', function(e) {
				e.preventDefault();
				if (mediaFrame) {
					mediaFrame.open();
					return;
				}
				mediaFrame = wp.media({
					title: 'Select or Upload Swatch Image',
					button: { text: 'Use as Swatch' },
					multiple: false
				});
				mediaFrame.on('select', function() {
					var attachment = mediaFrame.state().get('selection').first().toJSON();
					var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
					$('#wvs_swatch_image_id').val(attachment.id);
					$('#wvs-swatch-image-preview').html('<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;" />');
					$('.wvs-remove-image-btn').show();
				});
				mediaFrame.open();
			});

			$(document).on('click', '.wvs-remove-image-btn', function(e) {
				e.preventDefault();
				$('#wvs_swatch_image_id').val('');
				$('#wvs-swatch-image-preview').html('<span style="font-size:11px;color:#94a3b8;">No image</span>');
				$(this).hide();
			});
		});
		</script>
		<?php
	}
}
