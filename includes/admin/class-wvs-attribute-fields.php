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

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// 1. Add Color, Image, Button, and Radio to Products > Attributes Type dropdown.
		add_filter( 'product_attributes_type_selector', array( $this, 'register_attribute_types' ), 10, 1 );

		// 2. Register term fields for global attribute taxonomies.
		add_action( 'admin_init', array( $this, 'register_taxonomy_term_hooks' ), 20 );

		// 3. Enqueue Color Picker & Media scripts on Attribute and Product edit screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ), 20 );
		add_action( 'admin_footer', array( $this, 'render_admin_inline_js' ) ); // Moved to construct for reliability

		// 4. Add Swatch Type selector inside Product Data > Attributes tab.
		add_action( 'woocommerce_after_product_attribute_settings', array( $this, 'render_product_attribute_swatch_settings' ), 10, 2 );

		// 5. Save Product Data > Attributes swatch settings via AJAX and main Product Update.
		add_action( 'wp_ajax_woocommerce_save_attributes', array( $this, 'ajax_save_product_attribute_settings' ), 5 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_attribute_settings_on_update' ), 20, 1 );
	}

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
	}

	public function render_admin_inline_js() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'product' !== $screen->post_type && false === strpos( (string) $screen->taxonomy, 'pa_' ) && false === strpos( (string) $screen->id, 'product_attribute' ) ) ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(function($) {
			'use strict';
			
			function initWvsPickers() {
				try {
					if (typeof $.fn.wpColorPicker !== 'undefined') {
						$('.wvs-color-picker').not('.wp-color-picker-added').each(function() {
							$(this).addClass('wp-color-picker-added').wpColorPicker();
						});
					}
				} catch (err) {
					console.warn('Woo Variation Studio: Could not init color picker.', err);
				}
			}
			
			// Init immediately for global term pages
			initWvsPickers();

			// Safely re-initialize when WooCommerce adds a new attribute row via Backbone
			$(document).on('woocommerce_added_attribute wc-enhanced-select-init', function() {
				initWvsPickers();
			});

			// Protect WooCommerce AJAX events from halting if our JS has an issue
			$(document).ajaxComplete(function(event, xhr, settings) {
				if (settings && settings.data && typeof settings.data === 'string' && settings.data.indexOf('action=woocommerce_add_attribute') !== -1) {
					setTimeout(initWvsPickers, 200);
				}
			});

			// Toggle custom color rows inside Product Data > Attributes
			$(document).on('change', '.wvs-product-attr-type-select', function() {
				var val = $(this).val();
				var $wrap = $(this).closest('.wvs-product-attr-settings-box');
				if (val === 'color_circle') {
					$wrap.find('.wvs-product-attr-custom-colors').stop(true,true).slideDown(150);
				} else {
					$wrap.find('.wvs-product-attr-custom-colors').stop(true,true).slideUp(150);
				}
			});

			// Media uploader for global term swatch image
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

	public function render_product_attribute_swatch_settings( $attribute, $i = 0 ) {
		try {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				return;
			}

			$attr_name = (string) $attribute->get_name();
			if ( '' === $attr_name ) {
				return;
			}

			// SAFETY GUARD: Do not render complex UI on unsaved newly added attributes to prevent Backbone.js crash
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['action'] ) && 'woocommerce_add_attribute' === $_POST['action'] ) {
				echo '<tr><td colspan="2"><p style="font-size:12px; color:#d63638; margin:8px 0;"><em>' . esc_html__( 'Please click "Save attributes" below first, then you can configure Swatches for this new attribute.', 'woo-variation-studio' ) . '</em></p></td></tr>';
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

			// Extract readable option strings safely
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
						<select name="wvs_product_attr_type[<?php echo esc_attr( $attr_key ); ?>]" class="wvs-product-attr-type-select" style="min-width:280px; max-width:100%;">
							<option value="auto" <?php selected( $current_type, 'auto' ); ?>><?php esc_html_e( 'Auto-Detect (Smart Image or Button)', 'woo-variation-studio' ); ?></option>
							<option value="variation_image" <?php selected( $current_type, 'variation_image' ); ?>><?php esc_html_e( 'Auto Variation Thumbnail Image', 'woo-variation-studio' ); ?></option>
							<option value="color_circle" <?php selected( $current_type, 'color_circle' ); ?>><?php esc_html_e( 'Color Swatch Circle', 'woo-variation-studio' ); ?></option>
							<option value="button" <?php selected( $current_type, 'button' ); ?>><?php esc_html_e( 'Button / Label Box', 'woo-variation-studio' ); ?></option>
							<option value="radio" <?php selected( $current_type, 'radio' ); ?>><?php esc_html_e( 'Radio Button List', 'woo-variation-studio' ); ?></option>
							<option value="select" <?php selected( $current_type, 'select' ); ?>><?php esc_html_e( 'Standard Dropdown (Select)', 'woo-variation-studio' ); ?></option>
						</select>

						<?php if ( ! empty( $option_labels ) ) : ?>
							<div class="wvs-product-attr-custom-colors" style="<?php echo 'color_circle' === $current_type ? '' : 'display:none;'; ?>margin-top:12px;padding-top:10px;border-top:1px dashed #cbd5e1;">
								<p style="margin:0 0 8px;font-size:12px;color:#475569;">
									<?php esc_html_e( 'Assign Hex Colors for each option (e.g. #000000):', 'woo-variation-studio' ); ?>
								</p>
								<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:10px;">
									<?php foreach ( $option_labels as $opt_key => $opt_label ) : 
										$saved_hex = isset( $option_hex[ $opt_key ] ) ? (string) $option_hex[ $opt_key ] : '';
									?>
										<div style="display:flex;align-items:center;gap:8px; background:#fff; padding:6px; border:1px solid #e2e8f0; border-radius:4px;">
											<input type="color" name="wvs_product_attr_colors[<?php echo esc_attr( $attr_key ); ?>][<?php echo esc_attr( $opt_key ); ?>]" value="<?php echo esc_attr( $saved_hex ? $saved_hex : '#0f172a' ); ?>" style="width:30px;height:30px;padding:0;border:none;cursor:pointer;flex-shrink:0;" />
											<span style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo esc_attr( $opt_label ); ?>"><?php echo esc_html( $opt_label ); ?></span>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<?php
		} catch ( \Throwable $e ) {
			// Guard against breaking Product Data > Attributes metabox completely
		}
	}

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
			// Prevent AJAX fatal error
		}
	}

	public function save_product_attribute_settings_on_update( $product ) {
		try {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				return;
			}
			$this->persist_product_attribute_settings( (int) $product->get_id(), $_POST );
		} catch ( \Throwable $e ) {
			// Prevent product save error
		}
	}

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
