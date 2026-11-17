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
	 * Add Color, Image, Button, and Radio types to WooCommerce Attributes screen.
	 *
	 * @param array<string, string> $types Existing attribute types.
	 * @return array<string, string>
	 */
	public function register_attribute_types( $types ) {
		$types['color']  = __( 'Color', 'woo-variation-studio' );
		$types['image']  = __( 'Image', 'woo-variation-studio' );
		$types['button'] = __( 'Button / Label', 'woo-variation-studio' );
		$types['radio']  = __( 'Radio', 'woo-variation-studio' );

		return $types;
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
	 * Render inline JavaScript for WP Color Picker, Auto Color Name Matching, and Media Uploader.
	 */
	public function render_admin_inline_script() {
		$screen = get_current_screen();
		if ( ! $screen || 0 !== strpos( (string) $screen->taxonomy, 'pa_' ) ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if ($.fn.wpColorPicker) {
				$('.wvs-color-picker').wpColorPicker();
			}

			// Toggle Dual Color row
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
