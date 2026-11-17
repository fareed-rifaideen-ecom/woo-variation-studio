<?php
/**
 * Single Product Page Swatch Renderer & Flatsome Gallery Bridge
 *
 * File Path: includes/frontend/class-wvs-pdp-renderer.php
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVS_PDP_Renderer {

	/**
	 * Singleton instance.
	 *
	 * @var WVS_PDP_Renderer|null
	 */
	private static $instance = null;

	/**
	 * Initialize class.
	 *
	 * @return WVS_PDP_Renderer
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 20 );
		add_filter( 'body_class', array( $this, 'add_body_classes' ) );

		// Render Brand Eyebrow & Subtitle on Single Product Page.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_brand_eyebrow' ), 4 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_pdp_subtitle' ), 6 );

		// Replace standard WooCommerce dropdowns with interactive swatches while keeping native <select> synced.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'render_swatch_selector_html' ), 20, 2 );

		// Increase variation threshold so multi-attribute products (2-4 attributes) preload stock status cleanly.
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'increase_variation_threshold' ), 20, 2 );

		// Attach variation-specific gallery array for Flatsome Flickity slider isolation.
		add_filter( 'woocommerce_available_variation', array( $this, 'inject_variation_gallery_data' ), 20, 3 );

		// Output variation availability matrix & delivery/cross-sell blocks below Add to Cart.
		add_action( 'woocommerce_after_variations_table', array( $this, 'render_variation_matrix_json' ), 10 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render_fulfilment_and_cross_sells' ), 15 );
	}

	/**
	 * Enqueue frontend CSS and JS assets and inject dynamic CSS custom properties.
	 */
	public function enqueue_frontend_assets() {
		$css_path = WVS_PLUGIN_DIR . 'assets/css/frontend-ux.css';
		$js_path  = WVS_PLUGIN_DIR . 'assets/js/frontend-ux.js';

		$css_ver = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WVS_VERSION;
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WVS_VERSION;

		wp_enqueue_style(
			'wvs-frontend-ux',
			WVS_PLUGIN_URL . 'assets/css/frontend-ux.css',
			array(),
			$css_ver
		);

		$settings = class_exists( 'WVS_Inheritance_Engine' )
			? WVS_Inheritance_Engine::get_global_settings()
			: get_option( 'wvs_global_settings', array() );

		$swatch_width  = isset( $settings['swatch_width'] ) ? max( 20, absint( $settings['swatch_width'] ) ) : 42;
		$swatch_height = isset( $settings['swatch_height'] ) ? max( 20, absint( $settings['swatch_height'] ) ) : 42;

		$inline_css = sprintf(
			':root { --wvs-swatch-width: %dpx; --wvs-swatch-height: %dpx; }',
			$swatch_width,
			$swatch_height
		);
		wp_add_inline_style( 'wvs-frontend-ux', $inline_css );

		wp_enqueue_script(
			'wvs-frontend-ux',
			WVS_PLUGIN_URL . 'assets/js/frontend-ux.js',
			array( 'jquery', 'wc-add-to-cart-variation' ),
			$js_ver,
			true
		);

		$cross_mode       = isset( $settings['cross_attr_mode'] ) ? (string) $settings['cross_attr_mode'] : 'smart_switch';
		$disabled_effect  = isset( $settings['disabled_attribute_effect'] ) ? (string) $settings['disabled_attribute_effect'] : 'cross';
		$strict_iso       = isset( $settings['strict_gallery_isolation'] ) ? (string) $settings['strict_gallery_isolation'] : 'yes';
		$show_sel_label   = isset( $settings['show_selected_label'] ) ? (string) $settings['show_selected_label'] : 'yes';
		$enable_tooltip   = isset( $settings['enable_tooltip'] ) ? (string) $settings['enable_tooltip'] : 'yes';

		wp_localize_script(
			'wvs-frontend-ux',
			'wvsFrontendConfig',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'crossAttrMode'          => $cross_mode,
				'disabledEffect'         => $disabled_effect,
				'strictGalleryIsolation' => ( 'yes' === $strict_iso || 'strict_variation' === $strict_iso ),
				'showSelectedLabel'      => ( 'yes' === $show_sel_label ),
				'enableTooltip'          => ( 'yes' === $enable_tooltip ),
				'i18n'                   => array(
					'outOfStock' => __( 'Out of stock in this combination', 'woo-variation-studio' ),
					'selectSize' => __( 'Select an option', 'woo-variation-studio' ),
				),
			)
		);
	}

	/**
	 * Add helper classes to body for sticky desktop gallery, swatch shape, and out-of-stock effect.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function add_body_classes( $classes ) {
		if ( ! is_product() ) {
			return $classes;
		}

		$settings = class_exists( 'WVS_Inheritance_Engine' )
			? WVS_Inheritance_Engine::get_global_settings()
			: get_option( 'wvs_global_settings', array() );

		if ( ! isset( $settings['sticky_desktop_gallery'] ) || 'yes' === $settings['sticky_desktop_gallery'] ) {
			$classes[] = 'wvs-sticky-desktop-gallery';
		}

		$shape            = isset( $settings['swatch_shape'] ) ? sanitize_html_class( (string) $settings['swatch_shape'] ) : 'rounded';
		$disabled_effect  = isset( $settings['disabled_attribute_effect'] ) ? sanitize_html_class( (string) $settings['disabled_attribute_effect'] ) : 'cross';
		$classes[]        = 'wvs-shape-style-' . $shape;
		$classes[]        = 'wvs-oos-effect-' . $disabled_effect;

		return $classes;
	}

	/**
	 * Render clickable Brand Eyebrow above product title on PDP.
	 */
	public function render_brand_eyebrow() {
		global $product;
		if ( ! $product instanceof WC_Product || ! class_exists( 'WVS_Inheritance_Engine' ) ) {
			return;
		}

		$settings   = WVS_Inheritance_Engine::get_global_settings();
		$brand_mode = isset( $settings['brand_sync_display'] ) ? (string) $settings['brand_sync_display'] : 'option_1a';

		if ( 'option_1c' === $brand_mode ) {
			return;
		}

		$brand = WVS_Inheritance_Engine::resolve_product_brand( $product->get_id() );
		if ( empty( $brand['name'] ) ) {
			return;
		}

		echo '<div class="wvs-pdp-brand-eyebrow">';
		if ( ! empty( $brand['url'] ) ) {
			printf(
				'<a href="%s" class="wvs-brand-link">%s</a>',
				esc_url( $brand['url'] ),
				esc_html( $brand['name'] )
			);
		} else {
			printf(
				'<span class="wvs-brand-link">%s</span>',
				esc_html( $brand['name'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Render 1-line technical spec subtitle below product title on PDP.
	 */
	public function render_pdp_subtitle() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$subtitle = get_post_meta( $product->get_id(), '_wvs_spec_subtitle', true );
		if ( ! empty( $subtitle ) ) {
			printf(
				'<p class="wvs-pdp-spec-subtitle">%s</p>',
				esc_html( (string) $subtitle )
			);
		}
	}

	/**
	 * Increase variation threshold so multi-attribute products preload stock status cleanly.
	 *
	 * @param int        $threshold Default threshold (30).
	 * @param WC_Product $product   Product object.
	 * @return int
	 */
	public function increase_variation_threshold( $threshold, $product ) {
		return 150;
	}

	/**
	 * Render interactive Swatches (Color, Image, Button, Radio, or Dropdown) while keeping native <select> synced.
	 *
	 * @param string              $html_select Native select dropdown HTML.
	 * @param array<string,mixed> $args        Dropdown arguments.
	 * @return string
	 */
	public function render_swatch_selector_html( $html_select, $args ) {
		$product   = isset( $args['product'] ) ? $args['product'] : null;
		$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
		$options   = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		$selected  = isset( $args['selected'] ) ? (string) $args['selected'] : '';

		if ( ! $product instanceof WC_Product || empty( $attribute ) || empty( $options ) ) {
			return $html_select;
		}

		$settings            = class_exists( 'WVS_Inheritance_Engine' ) ? WVS_Inheritance_Engine::get_global_settings() : get_option( 'wvs_global_settings', array() );
		$variation_thumbs    = $this->get_variation_thumbnails_for_attribute( $product, $attribute );
		$display_mode        = $this->resolve_attribute_display_mode( $attribute, $variation_thumbs );

		// If explicitly configured to remain a standard WooCommerce dropdown, return unmodified.
		if ( 'dropdown' === $display_mode ) {
			return $html_select;
		}

		$is_visual       = in_array( $display_mode, array( 'auto_image', 'image', 'color_circle', 'color' ), true );
		$shape           = isset( $settings['swatch_shape'] ) ? (string) $settings['swatch_shape'] : 'rounded';
		$enable_tooltip  = ! isset( $settings['enable_tooltip'] ) || 'yes' === $settings['enable_tooltip'];
		$selected_label  = $this->get_readable_term_label( $attribute, $selected );

		// Hide native <select> visually so WooCommerce variation script remains synced.
		$hidden_select = '<div class="wvs-hidden-select-wrap" style="display:none !important;">' . $html_select . '</div>';

		ob_start();
		?>
		<div class="wvs-swatches-container wvs-shape-<?php echo esc_attr( $shape ); ?>"
			data-attribute_name="attribute_<?php echo esc_attr( sanitize_title( $attribute ) ); ?>"
			data-raw-attribute="<?php echo esc_attr( $attribute ); ?>"
			data-display-mode="<?php echo esc_attr( $display_mode ); ?>"
			data-selected-label="<?php echo esc_attr( $selected_label ); ?>"
			data-is-visual="<?php echo $is_visual ? 'yes' : 'no'; ?>">

			<div class="wvs-swatches-list wvs-mode-<?php echo esc_attr( $display_mode ); ?>" role="radiogroup">
				<?php
				foreach ( $options as $option_slug ) {
					$option_slug = (string) $option_slug;
					$is_selected = ( sanitize_title( $selected ) === sanitize_title( $option_slug ) || $selected === $option_slug );
					$term_label  = $this->get_readable_term_label( $attribute, $option_slug );
					$swatch_meta = $this->get_term_swatch_metadata( $attribute, $option_slug, $display_mode, $variation_thumbs );
					$tooltip_txt = ! empty( $swatch_meta['tooltip'] ) ? $swatch_meta['tooltip'] : $term_label;

					$item_classes = array(
						'wvs-swatch-item',
						'wvs-swatch-type-' . esc_attr( $swatch_meta['render_type'] ),
						'wvs-shape-' . esc_attr( $shape ),
					);
					if ( $is_selected ) {
						$item_classes[] = 'selected';
						$item_classes[] = 'is-active';
					}
					?>
					<button type="button"
						class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>"
						data-value="<?php echo esc_attr( $option_slug ); ?>"
						data-label="<?php echo esc_attr( $term_label ); ?>"
						aria-label="<?php echo esc_attr( $term_label ); ?>"
						aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">

						<?php if ( $enable_tooltip && 'radio' !== $swatch_meta['render_type'] ) : ?>
							<span class="wvs-swatch-tooltip" role="tooltip"><?php echo esc_html( $tooltip_txt ); ?></span>
						<?php endif; ?>

						<?php if ( 'image' === $swatch_meta['render_type'] && ! empty( $swatch_meta['image_url'] ) ) : ?>
							<span class="wvs-swatch-thumb">
								<img src="<?php echo esc_url( $swatch_meta['image_url'] ); ?>" alt="<?php echo esc_attr( $term_label ); ?>" loading="lazy" />
							</span>
						<?php elseif ( 'color' === $swatch_meta['render_type'] ) : ?>
							<span class="wvs-swatch-circle" style="<?php echo esc_attr( $swatch_meta['css_background'] ); ?>"></span>
						<?php elseif ( 'radio' === $swatch_meta['render_type'] ) : ?>
							<span class="wvs-radio-indicator"></span>
							<span class="wvs-radio-label"><?php echo esc_html( $term_label ); ?></span>
						<?php else : ?>
							<span class="wvs-swatch-text"><?php echo esc_html( $term_label ); ?></span>
						<?php endif; ?>

						<span class="wvs-swatch-check" aria-hidden="true">&#10003;</span>
						<span class="wvs-swatch-oos-slash" aria-hidden="true"></span>
					</button>
					<?php
				}
				?>
			</div>
		</div>
		<?php
		$swatches_html = (string) ob_get_clean();

		return $hidden_select . $swatches_html;
	}

	/**
	 * Determine display mode for an attribute based on Studio override, Products > Attributes Type, and Auto-Convert toggles.
	 *
	 * @param string                $attribute        Attribute taxonomy or name.
	 * @param array<string, string> $variation_thumbs Variation featured images indexed by term slug.
	 * @return string
	 */
	private function resolve_attribute_display_mode( $attribute, $variation_thumbs = array() ) {
		$settings   = class_exists( 'WVS_Inheritance_Engine' ) ? WVS_Inheritance_Engine::get_global_settings() : get_option( 'wvs_global_settings', array() );
		$clean_name = str_replace( 'pa_', '', strtolower( trim( $attribute ) ) );

		// 1. Check explicit Studio table override (`attr_display_{name}`).
		$override = isset( $settings[ 'attr_display_' . $clean_name ] ) ? sanitize_key( (string) $settings[ 'attr_display_' . $clean_name ] ) : 'inherit_wc';
		if ( ! empty( $override ) && 'inherit_wc' !== $override ) {
			return $override;
		}

		// 2. Check WooCommerce Attribute Type configured under Products > Attributes.
		$taxonomy = taxonomy_exists( $attribute ) ? $attribute : 'pa_' . $clean_name;
		if ( class_exists( 'WVS_Attribute_Fields' ) && taxonomy_exists( $taxonomy ) ) {
			$wc_type = WVS_Attribute_Fields::get_taxonomy_attribute_type( $taxonomy );
			if ( 'color' === $wc_type ) {
				return 'color_circle';
			}
			if ( 'image' === $wc_type ) {
				return 'auto_image';
			}
			if ( 'button' === $wc_type ) {
				return 'text_box';
			}
			if ( 'radio' === $wc_type ) {
				return 'radio';
			}
		}

		// 3. Auto-Convert Dropdown to Variation Image if enabled and variations have featured images.
		$auto_to_image = ! isset( $settings['auto_dropdown_to_image'] ) || 'yes' === $settings['auto_dropdown_to_image'];
		$is_color_attr = ( false !== strpos( $clean_name, 'color' ) || false !== strpos( $clean_name, 'colour' ) );
		if ( $auto_to_image && $is_color_attr && ! empty( $variation_thumbs ) ) {
			return 'auto_image';
		}

		// 4. Auto-Convert Default Dropdowns to Button Swatches if enabled.
		$auto_to_button = ! isset( $settings['auto_dropdown_to_button'] ) || 'yes' === $settings['auto_dropdown_to_button'];
		if ( $auto_to_button ) {
			return 'text_box';
		}

		return 'dropdown';
	}

	/**
	 * Collect variation main thumbnail URLs indexed by attribute value for a product.
	 *
	 * @param WC_Product $product   Variable product.
	 * @param string     $attribute Attribute slug.
	 * @return array<string, string>
	 */
	private function get_variation_thumbnails_for_attribute( $product, $attribute ) {
		$map = array();
		if ( ! $product->is_type( 'variable' ) ) {
			return $map;
		}

		$attr_key = 'attribute_' . sanitize_title( $attribute );
		$children = $product->get_children();

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				continue;
			}

			$var_attrs = $variation->get_variation_attributes();
			$term_val  = isset( $var_attrs[ $attr_key ] ) ? (string) $var_attrs[ $attr_key ] : '';
			if ( '' === $term_val || isset( $map[ $term_val ] ) ) {
				continue;
			}

			$image_id = (int) $variation->get_image_id();
			if ( $image_id > 0 ) {
				$img_url = wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' );
				if ( ! $img_url ) {
					$img_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
				}
				if ( $img_url ) {
					$map[ $term_val ] = (string) $img_url;
				}
			}
		}

		return $map;
	}

	/**
	 * Get readable term label for an attribute option slug.
	 *
	 * @param string $attribute   Taxonomy or custom attribute name.
	 * @param string $option_slug Term slug or raw option text.
	 * @return string
	 */
	private function get_readable_term_label( $attribute, $option_slug ) {
		if ( '' === $option_slug ) {
			return '';
		}
		$taxonomy = taxonomy_exists( $attribute ) ? $attribute : 'pa_' . str_replace( 'pa_', '', strtolower( trim( $attribute ) ) );
		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $option_slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $option_slug;
	}

	/**
	 * Resolve visual metadata (Image URL, Primary/Secondary Hex, Split Angle, Radio, or Button Text) for a single swatch.
	 *
	 * @param string                $attribute        Attribute name.
	 * @param string                $option_slug      Term slug.
	 * @param string                $display_mode     Resolved attribute display mode.
	 * @param array<string, string> $variation_thumbs Map of variation thumbnail URLs.
	 * @return array<string, string>
	 */
	private function get_term_swatch_metadata( $attribute, $option_slug, $display_mode, $variation_thumbs ) {
		$result = array(
			'render_type'    => ( 'radio' === $display_mode ) ? 'radio' : 'text',
			'image_url'      => '',
			'css_background' => 'background-color:#cbd5e1;',
			'tooltip'        => '',
		);

		$term_override = '';
		$term_img_url  = '';
		$primary_hex   = '';
		$secondary_hex = '';
		$is_dual       = false;
		$split_angle   = '135deg';

		$taxonomy = taxonomy_exists( $attribute ) ? $attribute : 'pa_' . str_replace( 'pa_', '', strtolower( trim( $attribute ) ) );
		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $option_slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_override     = (string) get_term_meta( $term->term_id, 'wvs_swatch_type', true );
				$result['tooltip'] = (string) get_term_meta( $term->term_id, 'wvs_term_tooltip', true );

				$custom_img_id = (int) get_term_meta( $term->term_id, 'wvs_swatch_image_id', true );
				if ( ! $custom_img_id ) {
					$custom_img_id = (int) get_term_meta( $term->term_id, '_wvs_swatch_image_id', true );
				}
				if ( ! $custom_img_id ) {
					$custom_img_id = (int) get_term_meta( $term->term_id, 'product_attribute_image', true );
				}
				if ( $custom_img_id > 0 ) {
					$url = wp_get_attachment_image_url( $custom_img_id, 'thumbnail' );
					if ( $url ) {
						$term_img_url = (string) $url;
					}
				}

				$primary_hex = (string) get_term_meta( $term->term_id, 'wvs_color_hex', true );
				if ( '' === $primary_hex ) {
					$primary_hex = (string) get_term_meta( $term->term_id, '_wvs_primary_hex', true );
				}
				if ( '' === $primary_hex ) {
					$primary_hex = (string) get_term_meta( $term->term_id, 'product_attribute_color', true );
				}

				$is_dual_meta  = (string) get_term_meta( $term->term_id, 'wvs_is_dual_color', true );
				$secondary_hex = (string) get_term_meta( $term->term_id, 'wvs_secondary_color_hex', true );
				if ( '' === $secondary_hex ) {
					$secondary_hex = (string) get_term_meta( $term->term_id, '_wvs_secondary_hex', true );
				}
				$is_dual     = ( 'yes' === $is_dual_meta && '' !== $secondary_hex ) || ( '' === $is_dual_meta && '' !== $secondary_hex );
				$angle_meta  = (string) get_term_meta( $term->term_id, 'wvs_split_angle', true );
				$split_angle = '' !== $angle_meta ? $angle_meta : '135deg';
			}
		}

		$effective_mode = '' !== $term_override ? $term_override : $display_mode;

		if ( 'button' === $effective_mode || 'text_box' === $effective_mode ) {
			$result['render_type'] = 'text';
			return $result;
		}

		if ( 'radio' === $effective_mode ) {
			$result['render_type'] = 'radio';
			return $result;
		}

		// Image Swatch or Auto Variation Thumbnail Image.
		if ( 'image' === $effective_mode || 'auto_image' === $effective_mode ) {
			if ( 'image' === $term_override && '' !== $term_img_url ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $term_img_url;
				return $result;
			}
			if ( ! empty( $variation_thumbs[ $option_slug ] ) ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $variation_thumbs[ $option_slug ];
				return $result;
			}
			if ( '' !== $term_img_url ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $term_img_url;
				return $result;
			}
		}

		// Color / Dual-Color Swatch (or fallback when image swatch has a color hex).
		if ( 'color' === $effective_mode || 'color_circle' === $effective_mode || 'auto_image' === $effective_mode || 'image' === $effective_mode ) {
			if ( '' !== $primary_hex ) {
				$result['render_type'] = 'color';
				if ( $is_dual ) {
					$result['css_background'] = sprintf(
						'background: linear-gradient(%1$s, %2$s 50%%, %3$s 50%%);',
						esc_attr( $split_angle ),
						esc_attr( $primary_hex ),
						esc_attr( $secondary_hex )
					);
				} else {
					$result['css_background'] = sprintf( 'background-color: %s;', esc_attr( $primary_hex ) );
				}
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Inject full gallery image metadata per variation for strict Flatsome Flickity gallery isolation.
	 *
	 * @param array<string, mixed> $variation_data Variation array passed to frontend JS.
	 * @param WC_Product           $product        Parent variable product.
	 * @param WC_Product_Variation $variation      Child variation object.
	 * @return array<string, mixed>
	 */
	public function inject_variation_gallery_data( $variation_data, $product, $variation ) {
		$image_ids = array();

		$main_id = (int) $variation->get_image_id();
		if ( $main_id > 0 ) {
			$image_ids[] = $main_id;
		}

		// Check all supported variation gallery meta keys.
		$meta_keys = array(
			'_wc_additional_variation_images',
			'_wc_variation_gallery_ids',
			'_wvs_variation_gallery_ids',
			'woo_variation_gallery_images',
		);

		foreach ( $meta_keys as $mkey ) {
			$raw_gallery = $variation->get_meta( $mkey, true );
			if ( ! empty( $raw_gallery ) ) {
				if ( is_array( $raw_gallery ) ) {
					foreach ( $raw_gallery as $gid ) {
						$image_ids[] = (int) $gid;
					}
				} elseif ( is_string( $raw_gallery ) && '' !== trim( $raw_gallery ) ) {
					$parts = array_map( 'intval', explode( ',', $raw_gallery ) );
					foreach ( $parts as $gid ) {
						if ( $gid > 0 ) {
							$image_ids[] = $gid;
						}
					}
				}
				break;
			}
		}

		$image_ids = array_values( array_unique( array_filter( $image_ids ) ) );
		$slides    = array();

		foreach ( $image_ids as $attachment_id ) {
			$full_src   = wp_get_attachment_image_src( $attachment_id, 'full' );
			$single_src = wp_get_attachment_image_src( $attachment_id, 'woocommerce_single' );
			$thumb_src  = wp_get_attachment_image_src( $attachment_id, 'woocommerce_gallery_thumbnail' );
			$alt_text   = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			if ( $single_src ) {
				$slides[] = array(
					'id'         => $attachment_id,
					'full_src'   => $full_src ? $full_src[0] : $single_src[0],
					'full_w'     => $full_src ? (int) $full_src[1] : (int) $single_src[1],
					'full_h'     => $full_src ? (int) $full_src[2] : (int) $single_src[2],
					'single_src' => $single_src[0],
					'thumb_src'  => $thumb_src ? $thumb_src[0] : $single_src[0],
					'alt'        => '' !== $alt_text ? $alt_text : $variation->get_name(),
					'html'       => function_exists( 'wc_get_gallery_image_html' ) ? wc_get_gallery_image_html( $attachment_id, $attachment_id === $main_id ) : '',
				);
			}
		}

		$variation_data['wvs_gallery_images'] = $slides;
		$variation_data['wvs_stock_qty']      = $variation->get_manage_stock() ? (int) $variation->get_stock_quantity() : ( $variation->is_in_stock() ? 1 : 0 );

		return $variation_data;
	}

	/**
	 * Output lightweight JSON matrix of all child variations for instant two-way Size <-> Colour filtering.
	 */
	public function render_variation_matrix_json() {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$matrix   = array();
		$children = $product->get_children();

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$matrix[] = array(
				'variation_id' => (int) $child_id,
				'attributes'   => $variation->get_variation_attributes(),
				'is_in_stock'  => (bool) $variation->is_in_stock(),
				'stock_qty'    => $variation->get_manage_stock() ? (int) $variation->get_stock_quantity() : ( $variation->is_in_stock() ? 1 : 0 ),
				'image_id'     => (int) $variation->get_image_id(),
			);
		}

		printf(
			'<script type="application/json" id="wvs-variation-availability-matrix">%s</script>',
			wp_json_encode( $matrix )
		);
	}

	/**
	 * Render Ship to Home vs In-Store Pickup block below Add to Cart form when enabled.
	 */
	public function render_fulfilment_and_cross_sells() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$settings = class_exists( 'WVS_Inheritance_Engine' )
			? WVS_Inheritance_Engine::get_global_settings()
			: get_option( 'wvs_global_settings', array() );

		if ( ! isset( $settings['enable_delivery_block'] ) || 'yes' === $settings['enable_delivery_block'] ) {
			$home_title   = ! empty( $settings['home_card_title'] ) ? $settings['home_card_title'] : __( 'Standard Delivery', 'woo-variation-studio' );
			$pickup_title = ! empty( $settings['pickup_card_title'] ) ? $settings['pickup_card_title'] : __( 'In-Store Pickup', 'woo-variation-studio' );
			$home_lead    = ! empty( $settings['default_home_lead'] ) ? $settings['default_home_lead'] : __( '2-4 business days', 'woo-variation-studio' );
			$pickup_lead  = ! empty( $settings['default_pickup_lead'] ) ? $settings['default_pickup_lead'] : __( 'Ready in 24 hours', 'woo-variation-studio' );
			?>
			<div class="wvs-delivery-switcher-block">
				<div class="wvs-delivery-option is-selected" data-method="home">
					<span class="wvs-delivery-radio"></span>
					<div class="wvs-delivery-info">
						<strong><?php echo esc_html( (string) $home_title ); ?></strong>
						<span><?php echo esc_html( (string) $home_lead ); ?></span>
					</div>
				</div>
				<div class="wvs-delivery-option" data-method="pickup">
					<span class="wvs-delivery-radio"></span>
					<div class="wvs-delivery-info">
						<strong><?php echo esc_html( (string) $pickup_title ); ?></strong>
						<span><?php echo esc_html( (string) $pickup_lead ); ?></span>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
