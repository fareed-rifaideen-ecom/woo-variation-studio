<?php
/**
 * Global & Product-Level Attribute Swatch Configuration
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
		// 1. Add Color, Image, Button, and Radio to Products > Attributes Type dropdown.
		add_filter( 'product_attributes_type_selector', array( $this, 'register_attribute_types' ), 10, 1 );

		// 2. Register term fields (Color picker, Dual color, Image upload) for global attribute taxonomies.
		add_action( 'admin_init', array( $this, 'register_taxonomy_term_hooks' ), 20 );

		// 3. Enqueue Color Picker & Media scripts on Attribute and Product edit screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ), 20 );

		// 4. Add Swatch Type selector inside Product Data > Attributes tab (works for Custom & Global attributes).
		add_action( 'woocommerce_after_product_attribute_settings', array( $this, 'render_product_attribute_swatch_settings' ), 10, 2 );

		// 5. Save Product Data > Attributes swatch settings via AJAX ("Save attributes") and main Product Update.
		add_action( 'wp_ajax_woocommerce_save_attributes', array( $this, 'ajax_save_product_attribute_settings' ), 5 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_attribute_settings_on_update' ), 20, 1 );
	}

	/**
	 * Add custom swatch types to WooCommerce's native Attribute Type selector.
	 *
	 * @param array<string, string> $types Existing attribute types.
	 * @return array<string, string>
	 */
	public function register_attribute_types( $types ) {
		if ( ! is_array( $types ) ) {
			$types = array();
		}
		$types['color']  = __( 'Color', 'woo-variation-studio' );
		$types['image']  = __( 'Image', 'woo-variation-studio' );
		$types['button'] = __( 'Button / Label', 'woo-variation-studio' );
		$types['radio']  = __( 'Radio', 'woo-variation-studio' );
		return $types;
	}

	/**
	 * Hook into all WooCommerce attribute taxonomies (`pa_*`).
	 */
	public function register_taxonomy_term_hooks() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( empty( $attribute_taxonomies ) || ! is_array( $attribute_taxonomies ) ) {
			return;
		}

		foreach ( $attribute_taxonomies as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_term_fields' ), 10, 1 );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_term_fields' ), 10, 2 );
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_swatch_preview_column' ), 10, 1 );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_swatch_preview_column' ), 10, 3 );
		}

		add_action( 'created_term', array( $this, 'save_term_fields' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term_fields' ), 10, 3 );
	}

	/**
	 * Enqueue WP Color Picker and Media Uploader on attribute and product edit screens.
	 */
	public function enqueue_admin_scripts() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_product_or_attr = (
			'product' === $screen->post_type ||
			'edit-product' === $screen->id ||
			false !== strpos( (string) $screen->taxonomy, 'pa_' ) ||
			false !== strpos( (string) $screen->id, 'product_attribute' )
		);

		if ( ! $is_product_or_attr ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();

		add_action( 'admin_footer', array( $this, 'render_admin_inline_js' ) );
	}

	/**
	 * Render inline JS for Color Picker and Media Uploader in term screens and Product Data > Attributes.
	 */
	public function render_admin_inline_js() {
		?>
		<script type="text/javascript">
		jQuery(function($) {
			function initWvsPickers() {
				if ($.fn.wpColorPicker) {
					$('.wvs-color-picker').not('.wp-color-picker').wpColorPicker();
				}
			}
			initWvsPickers();
			$(document).on('woocommerce_added_attribute wc-enhanced-select-init ajaxComplete', function() {
				initWvsPickers();
			});

			// Toggle custom color rows inside Product Data > Attributes when type changes
			$(document).on('change', '.wvs-product-attr-type-select', function() {
				var val = $(this).val();
				var $wrap = $(this).closest('.wvs-product-attr-settings-box');
				if (val === 'color') {
					$wrap.find('.wvs-product-attr-custom-colors').slideDown(150);
				} else {
					$wrap.find('.wvs-product-attr-custom-colors').slideUp(150);
				}
			});

			// Media uploader for term swatch image
			$(document).on('click', '.wvs-upload-image-btn', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var $wrap = $btn.closest('.wvs-image-field-wrap');
				var frame = wp.media({
					title: 'Select Swatch Image',
					button: { text: 'Use Image' },
					multiple: false
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$wrap.find('.wvs-image-id-input').val(attachment.id);
					var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
					$wrap.find('.wvs-image-preview').attr('src', thumbUrl).show();
					$wrap.find('.wvs-remove-image-btn').show();
				});
				frame.open();
			});

			$(document).on('click', '.wvs-remove-image-btn', function(e) {
				e.preventDefault();
				var $wrap = $(this).closest('.wvs-image-field-wrap');
				$wrap.find('.wvs-image-id-input').val('');
				$wrap.find('.wvs-image-preview').attr('src', '').hide();
				$(this).hide();
			});
		});
		</script>
		<?php
	}

	/**
	 * Render fields on Add New Term screen (`Products > Attributes > Configure terms`).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function render_add_term_fields( $taxonomy ) {
		?>
		<div class="form-field">
			<label for="wvs_primary_hex"><?php esc_html_e( 'Primary Swatch Color', 'woo-variation-studio' ); ?></label>
			<input type="text" name="wvs_primary_hex" id="wvs_primary_hex" class="wvs-color-picker" value="" />
		</div>
		<div class="form-field">
			<label for="wvs_secondary_hex"><?php esc_html_e( 'Secondary Split Color (Optional)', 'woo-variation-studio' ); ?></label>
			<input type="text" name="wvs_secondary_hex" id="wvs_secondary_hex" class="wvs-color-picker" value="" />
		</div>
		<div class="form-field wvs-image-field-wrap">
			<label><?php esc_html_e( 'Swatch Image', 'woo-variation-studio' ); ?></label>
			<input type="hidden" name="wvs_swatch_image_id" class="wvs-image-id-input" value="" />
			<div style="margin-bottom:8px;">
				<img src="" class="wvs-image-preview" style="display:none;width:44px;height:44px;object-fit:cover;border-radius:4px;border:1px solid #ccc;" alt="" />
			</div>
			<button type="button" class="button wvs-upload-image-btn"><?php esc_html_e( 'Upload / Select Image', 'woo-variation-studio' ); ?></button>
			<button type="button" class="button wvs-remove-image-btn" style="display:none;"><?php esc_html_e( 'Remove', 'woo-variation-studio' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render fields on Edit Term screen.
	 *
	 * @param WP_Term $term     Term object.
	 * @param string  $taxonomy Taxonomy slug.
	 */
	public function render_edit_term_fields( $term, $taxonomy ) {
		if ( ! is_object( $term ) || empty( $term->term_id ) ) {
			return;
		}
		$primary_hex   = (string) get_term_meta( $term->term_id, '_wvs_primary_hex', true );
		$secondary_hex = (string) get_term_meta( $term->term_id, '_wvs_secondary_hex', true );
		$image_id      = (int) get_term_meta( $term->term_id, '_wvs_swatch_image_id', true );
		$image_url     = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		?>
		<tr class="form-field">
			<th scope="row"><label for="wvs_primary_hex"><?php esc_html_e( 'Primary Swatch Color', 'woo-variation-studio' ); ?></label></th>
			<td><input type="text" name="wvs_primary_hex" id="wvs_primary_hex" class="wvs-color-picker" value="<?php echo esc_attr( $primary_hex ); ?>" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="wvs_secondary_hex"><?php esc_html_e( 'Secondary Split Color (Optional)', 'woo-variation-studio' ); ?></label></th>
			<td><input type="text" name="wvs_secondary_hex" id="wvs_secondary_hex" class="wvs-color-picker" value="<?php echo esc_attr( $secondary_hex ); ?>" /></td>
		</tr>
		<tr class="form-field wvs-image-field-wrap">
			<th scope="row"><label><?php esc_html_e( 'Swatch Image', 'woo-variation-studio' ); ?></label></th>
			<td>
				<input type="hidden" name="wvs_swatch_image_id" class="wvs-image-id-input" value="<?php echo esc_attr( (string) $image_id ); ?>" />
				<div style="margin-bottom:8px;">
					<img src="<?php echo esc_url( (string) $image_url ); ?>" class="wvs-image-preview" style="<?php echo $image_url ? '' : 'display:none;'; ?>width:44px;height:44px;object-fit:cover;border-radius:4px;border:1px solid #ccc;" alt="" />
				</div>
				<button type="button" class="button wvs-upload-image-btn"><?php esc_html_e( 'Upload / Select Image', 'woo-variation-studio' ); ?></button>
				<button type="button" class="button wvs-remove-image-btn" style="<?php echo $image_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'woo-variation-studio' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save term swatch metadata.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function save_term_fields( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( isset( $_POST['wvs_primary_hex'] ) ) {
			update_term_meta( $term_id, '_wvs_primary_hex', sanitize_hex_color( wp_unslash( $_POST['wvs_primary_hex'] ) ) );
		}
		if ( isset( $_POST['wvs_secondary_hex'] ) ) {
			update_term_meta( $term_id, '_wvs_secondary_hex', sanitize_hex_color( wp_unslash( $_POST['wvs_secondary_hex'] ) ) );
		}
		if ( isset( $_POST['wvs_swatch_image_id'] ) ) {
			update_term_meta( $term_id, '_wvs_swatch_image_id', absint( $_POST['wvs_swatch_image_id'] ) );
		}
	}

	/**
	 * Add Swatch Preview column to attribute term list table.
	 *
	 * @param array<string, string> $columns Table columns.
	 * @return array<string, string>
	 */
	public function add_swatch_preview_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$new_cols = array();
		foreach ( $columns as $key => $label ) {
			$new_cols[ $key ] = $label;
			if ( 'cb' === $key ) {
				$new_cols['wvs_preview'] = __( 'Swatch', 'woo-variation-studio' );
			}
		}
		return $new_cols;
	}

	/**
	 * Render Swatch Preview cell in attribute term table.
	 *
	 * @param string $content     Column HTML.
	 * @param string $column_name Column key.
	 * @param int    $term_id     Term ID.
	 * @return string
	 */
	public function render_swatch_preview_column( $content, $column_name, $term_id ) {
		if ( 'wvs_preview' !== $column_name ) {
			return $content;
		}

		$image_id      = (int) get_term_meta( $term_id, '_wvs_swatch_image_id', true );
		$primary_hex   = (string) get_term_meta( $term_id, '_wvs_primary_hex', true );
		$secondary_hex = (string) get_term_meta( $term_id, '_wvs_secondary_hex', true );

		if ( $image_id > 0 ) {
			$url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
			if ( $url ) {
				return sprintf( '<img src="%s" style="width:30px;height:30px;object-fit:cover;border-radius:50%%;border:1px solid #ccc;" alt="" />', esc_url( $url ) );
			}
		}

		if ( '' !== $primary_hex ) {
			$bg = '' !== $secondary_hex
				? sprintf( 'background:linear-gradient(135deg, %1$s 50%%, %2$s 50%%);', esc_attr( $primary_hex ), esc_attr( $secondary_hex ) )
				: sprintf( 'background-color:%s;', esc_attr( $primary_hex ) );
			return sprintf( '<span style="display:inline-block;width:28px;height:28px;border-radius:50%%;border:1px solid #ccc;%s"></span>', $bg );
		}

		return '&mdash;';
	}

	/**
	 * Render Swatch Display Type dropdown inside Product Data > Attributes tab.
	 *
	 * @param WC_Product_Attribute|mixed $attribute Attribute object.
	 * @param int|string                 $i         Row index.
	 */
	public function render_product_attribute_swatch_settings( $attribute, $i = 0 ) {
		try {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				return;
			}

			$attr_name = (string) $attribute->get_name();
			if ( '' === $attr_name ) {
				return;
			}

			$attr_key   = sanitize_title( $attr_name );
			$product_id = $this->resolve_current_admin_product_id();
			$saved_all  = $product_id > 0 ? get_post_meta( $product_id, '_wvs_product_attr_settings', true ) : array();
			if ( ! is_array( $saved_all ) ) {
				$saved_all = array();
			}

			$attr_config  = isset( $saved_all[ $attr_key ] ) && is_array( $saved_all[ $attr_key ] ) ? $saved_all[ $attr_key ] : array();
			$current_type = isset( $attr_config['type'] ) ? (string) $attr_config['type'] : 'auto';
			$option_hex   = isset( $attr_config['colors'] ) && is_array( $attr_config['colors'] ) ? $attr_config['colors'] : array();

			// Extract readable option strings safely whether taxonomy or custom pipe-separated values.
			$option_labels = array();
			if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
				$terms = method_exists( $attribute, 'get_terms' ) ? $attribute->get_terms() : array();
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term_obj ) {
						if ( is_object( $term_obj ) && isset( $term_obj->slug, $term_obj->name ) ) {
							$option_labels[ (string) $term_obj->slug ] = (string) $term_obj->name;
						}
					}
				}
			} elseif ( method_exists( $attribute, 'get_options' ) ) {
				$raw_options = $attribute->get_options();
				if ( is_array( $raw_options ) ) {
					foreach ( $raw_options as $opt ) {
						if ( is_scalar( $opt ) && '' !== trim( (string) $opt ) ) {
							$opt_str                                       = trim( (string) $opt );
							$option_labels[ sanitize_title( $opt_str ) ] = $opt_str;
						}
					}
				}
			}
			?>
			<tr>
				<td colspan="2">
					<div class="wvs-product-attr-settings-box" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:12px;margin-top:8px;">
						<p style="margin:0 0 8px;">
							<strong><?php esc_html_e( 'Variation Studio — Swatch Display Type:', 'woo-variation-studio' ); ?></strong>
						</p>
						<select name="wvs_product_attr_type[<?php echo esc_attr( $attr_key ); ?>]" class="wvs-product-attr-type-select" style="min-width:280px;">
							<option value="auto" <?php selected( $current_type, 'auto' ); ?>><?php esc_html_e( 'Auto-Detect (Use Global Setting / Smart Auto)', 'woo-variation-studio' ); ?></option>
							<option value="variation_image" <?php selected( $current_type, 'variation_image' ); ?>><?php esc_html_e( 'Variation Image Swatch (Auto-Uses Variation Featured Photo)', 'woo-variation-studio' ); ?></option>
							<option value="color" <?php selected( $current_type, 'color' ); ?>><?php esc_html_e( 'Color Swatch Circle', 'woo-variation-studio' ); ?></option>
							<option value="button" <?php selected( $current_type, 'button' ); ?>><?php esc_html_e( 'Button / Label Box', 'woo-variation-studio' ); ?></option>
							<option value="radio" <?php selected( $current_type, 'radio' ); ?>><?php esc_html_e( 'Radio Button List', 'woo-variation-studio' ); ?></option>
							<option value="select" <?php selected( $current_type, 'select' ); ?>><?php esc_html_e( 'Standard Dropdown (Select)', 'woo-variation-studio' ); ?></option>
						</select>

						<?php if ( ! empty( $option_labels ) ) : ?>
							<div class="wvs-product-attr-custom-colors" style="<?php echo 'color' === $current_type ? '' : 'display:none;'; ?>margin-top:12px;padding-top:10px;border-top:1px dashed #cbd5e1;">
								<p style="margin:0 0 6px;font-size:12px;color:#475569;">
									<?php esc_html_e( 'Assign Hex Colors for each option (e.g. #000000):', 'woo-variation-studio' ); ?>
								</p>
								<?php foreach ( $option_labels as $opt_key => $opt_label ) : 
									$saved_hex = isset( $option_hex[ $opt_key ] ) ? (string) $option_hex[ $opt_key ] : '';
								?>
									<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
										<span style="min-width:180px;font-size:12px;font-weight:600;"><?php echo esc_html( $opt_label ); ?></span>
										<input type="text"
											name="wvs_product_attr_colors[<?php echo esc_attr( $attr_key ); ?>][<?php echo esc_attr( $opt_key ); ?>]"
											value="<?php echo esc_attr( $saved_hex ); ?>"
											placeholder="#000000"
											style="width:110px;" />
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<?php
		} catch ( \Throwable $e ) {
			// Guard against breaking Product Data > Attributes metabox.
		}
	}

	/**
	 * Save Product Data > Attributes swatch settings when clicking "Save attributes" (AJAX).
	 */
	public function ajax_save_product_attribute_settings() {
		try {
			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$parsed = array();
			if ( isset( $_POST['data'] ) && is_string( $_POST['data'] ) ) {
				wp_parse_str( wp_unslash( $_POST['data'] ), $parsed );
			}

			$this->persist_product_attribute_settings( $post_id, $parsed );
		} catch ( \Throwable $e ) {
			// Prevent AJAX fatal error.
		}
	}

	/**
	 * Save Product Data > Attributes swatch settings when clicking main Product "Update" button.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function save_product_attribute_settings_on_update( $product ) {
		try {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				return;
			}
			$this->persist_product_attribute_settings( (int) $product->get_id(), $_POST );
		} catch ( \Throwable $e ) {
			// Prevent product save error.
		}
	}

	/**
	 * Persist `_wvs_product_attr_settings` meta cleanly.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $source     Parsed form array.
	 */
	private function persist_product_attribute_settings( $product_id, $source ) {
		if ( $product_id <= 0 || ! is_array( $source ) || empty( $source['wvs_product_attr_type'] ) || ! is_array( $source['wvs_product_attr_type'] ) ) {
			return;
		}

		$existing = get_post_meta( $product_id, '_wvs_product_attr_settings', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		foreach ( $source['wvs_product_attr_type'] as $attr_key => $type_val ) {
			$clean_key = sanitize_title( (string) $attr_key );
			$existing[ $clean_key ]['type'] = sanitize_key( (string) $type_val );

			if ( isset( $source['wvs_product_attr_colors'][ $attr_key ] ) && is_array( $source['wvs_product_attr_colors'][ $attr_key ] ) ) {
				$colors = array();
				foreach ( $source['wvs_product_attr_colors'][ $attr_key ] as $opt_key => $hex_val ) {
					$clean_hex = sanitize_hex_color( (string) $hex_val );
					if ( $clean_hex ) {
						$colors[ sanitize_title( (string) $opt_key ) ] = $clean_hex;
					}
				}
				$existing[ $clean_key ]['colors'] = $colors;
			}
		}

		update_post_meta( $product_id, '_wvs_product_attr_settings', $existing );
	}

	/**
	 * Safely determine the current product ID in WP Admin or AJAX context.
	 *
	 * @return int
	 */
	private function resolve_current_admin_product_id() {
		global $post, $thepostid;
		if ( ! empty( $thepostid ) && is_numeric( $thepostid ) ) {
			return (int) $thepostid;
		}
		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			return (int) $post->ID;
		}
		if ( ! empty( $_POST['post_id'] ) ) {
			return absint( $_POST['post_id'] );
		}
		if ( ! empty( $_GET['post'] ) ) {
			return absint( $_GET['post'] );
		}
		return 0;
	}
}
