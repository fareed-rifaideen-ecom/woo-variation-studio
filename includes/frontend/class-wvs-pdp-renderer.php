<?php
/**
 * Single Product Page (PDP) Swatch, Gallery Isolation & Fulfilment Renderer
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

		// Convert dropdowns to Swatches while keeping hidden <select> for WooCommerce variation scripts.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'render_swatch_selector_html' ), 20, 2 );

		// Increase variation threshold so multi-attribute products evaluate stock combinations without AJAX lag.
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'increase_variation_threshold' ), 20, 2 );

		// Inject variation-specific gallery images for Flatsome Flickity slider isolation.
		add_filter( 'woocommerce_available_variation', array( $this, 'inject_variation_gallery_data' ), 20, 3 );

		// Output variation availability matrix JSON & Delivery Switcher block.
		add_action( 'woocommerce_after_variations_table', array( $this, 'render_variation_matrix_json' ), 10 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render_fulfilment_and_cross_sells' ), 15 );
	}

	/**
	 * Enqueue frontend CSS and JS assets safely.
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

		wp_enqueue_script(
			'wvs-frontend-ux',
			WVS_PLUGIN_URL . 'assets/js/frontend-ux.js',
			array( 'jquery', 'wc-add-to-cart-variation' ),
			$js_ver,
			true
		);

		$settings = class_exists( 'WVS_Inheritance_Engine' )
			? WVS_Inheritance_Engine::get_global_settings()
			: get_option( 'wvs_global_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$cross_mode = isset( $settings['cross_attr_mode'] ) ? (string) $settings['cross_attr_mode'] : 'smart_switch';
		$strict_iso = isset( $settings['strict_gallery_isolation'] ) ? (string) $settings['strict_gallery_isolation'] : 'yes';
		$active_sty = isset( $settings['swatch_active_style'] ) ? (string) $settings['swatch_active_style'] : 'option_4a';
		$oos_style  = isset( $settings['oos_swatch_behavior'] ) ? (string) $settings['oos_swatch_behavior'] : 'cross_out';
		$show_label = ! isset( $settings['show_selected_label'] ) || 'yes' === $settings['show_selected_label'];

		wp_localize_script(
			'wvs-frontend-ux',
			'wvsFrontendConfig',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'crossAttrMode'          => $cross_mode,
				'oosBehavior'            => $oos_style,
				'showSelectedLabel'      => $show_label,
				'strictGalleryIsolation' => ( 'yes' === $strict_iso || 'strict_variation' === $strict_iso ),
				'activeSwatchStyle'      => $active_sty,
				'i18n'                   => array(
					'outOfStock' => __( 'Out of stock in this combination', 'woo-variation-studio' ),
					'selectSize' => __( 'Select an option', 'woo-variation-studio' ),
				),
			)
		);
	}

	/**
	 * Add body classes for sticky gallery and swatch shape/style.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function add_body_classes( $classes ) {
		if ( ! is_array( $classes ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $classes;
		}

		$settings = class_exists( 'WVS_Inheritance_Engine' )
			? WVS_Inheritance_Engine::get_global_settings()
			: get_option( 'wvs_global_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! isset( $settings['sticky_desktop_gallery'] ) || 'yes' === $settings['sticky_desktop_gallery'] ) {
			$classes[] = 'wvs-sticky-desktop-gallery';
		}

		$shape        = isset( $settings['swatch_shape'] ) ? sanitize_html_class( (string) $settings['swatch_shape'] ) : 'rounded';
		$active_style = isset( $settings['swatch_active_style'] ) ? sanitize_html_class( (string) $settings['swatch_active_style'] ) : 'option_4a';

		$classes[] = 'wvs-shape-' . $shape;
		$classes[] = 'wvs-active-style-' . $active_style;

		return $classes;
	}

	/**
	 * Render clickable Brand Eyebrow above product title on PDP.
	 */
	public function render_brand_eyebrow() {
		try {
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
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Render 1-line technical spec subtitle below product title on PDP.
	 */
	public function render_pdp_subtitle() {
		try {
			global $product;
			if ( ! $product instanceof WC_Product ) {
				return;
			}

			$settings  = class_exists( 'WVS_Inheritance_Engine' ) ? WVS_Inheritance_Engine::get_global_settings() : array();
			$meta_mode = isset( $settings['pdp_header_meta_mode'] ) ? (string) $settings['pdp_header_meta_mode'] : 'option_2a';

			if ( 'option_2b' === $meta_mode ) {
				return;
			}

			$subtitle = get_post_meta( $product->get_id(), '_wvs_spec_subtitle', true );
			if ( empty( $subtitle ) ) {
				$subtitle = get_post_meta( $product->get_id(), '_cux_spec_subtitle', true );
			}

			if ( ! empty( $subtitle ) ) {
				printf(
					'<p class="wvs-pdp-spec-subtitle">%s</p>',
					esc_html( (string) $subtitle )
				);
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Increase variation threshold so multi-attribute products preload stock status cleanly.
	 *
	 * @param int        $threshold Default threshold.
	 * @param WC_Product $product   Product object.
	 * @return int
	 */
	public function increase_variation_threshold( $threshold, $product ) {
		return 120;
	}

	/**
	 * Render interactive Swatches while keeping native <select> hidden for WooCommerce compatibility.
	 *
	 * @param string               $htmlSelect Native select dropdown HTML.
	 * @param array<string, mixed> $args       Dropdown arguments.
	 * @return string
	 */
	public function render_swatch_selector_html( $htmlSelect, $args ) {
		try {
			$product   = isset( $args['product'] ) ? $args['product'] : null;
			$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
			$options   = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
			$selected  = isset( $args['selected'] ) ? (string) $args['selected'] : '';

			if ( ! $product instanceof WC_Product || '' === $attribute || empty( $options ) ) {
				return $htmlSelect;
			}

			$display_mode = $this->resolve_attribute_display_mode( $product, $attribute );
			if ( 'select' === $display_mode ) {
				return $htmlSelect;
			}

			$is_visual = in_array( $display_mode, array( 'variation_image', 'auto_image', 'image', 'color', 'color_circle' ), true );

			$term_variation_thumbs = $this->get_variation_thumbnails_for_attribute( $product, $attribute );
			$hidden_select         = '<div class="wvs-hidden-select-wrap" style="display:none !important;">' . $htmlSelect . '</div>';

			$settings    = class_exists( 'WVS_Inheritance_Engine' ) ? WVS_Inheritance_Engine::get_global_settings() : get_option( 'wvs_global_settings', array() );
			$swatch_size = isset( $settings['swatch_size_px'] ) ? max( 24, min( 90, absint( $settings['swatch_size_px'] ) ) ) : 42;
			$enable_tip  = ! isset( $settings['enable_tooltip'] ) || 'yes' === $settings['enable_tooltip'];

			ob_start();
			?>
			<div class="wvs-swatches-container"
				data-attribute_name="attribute_<?php echo esc_attr( sanitize_title( $attribute ) ); ?>"
				data-raw-attribute="<?php echo esc_attr( $attribute ); ?>"
				data-display-mode="<?php echo esc_attr( $display_mode ); ?>"
				data-is-visual="<?php echo $is_visual ? 'yes' : 'no'; ?>"
				style="--wvs-swatch-size: <?php echo esc_attr( (string) $swatch_size ); ?>px;">

				<div class="wvs-selected-label-row">
					<span class="wvs-selected-term-value"><?php echo esc_html( $this->get_readable_term_label( $attribute, $selected ) ); ?></span>
				</div>

				<div class="wvs-swatches-list wvs-mode-<?php echo esc_attr( $display_mode ); ?>" role="radiogroup">
					<?php
					foreach ( $options as $option_slug ) {
						if ( ! is_scalar( $option_slug ) ) {
							continue;
						}
						$option_slug = (string) $option_slug;
						$is_selected = ( sanitize_title( $selected ) === sanitize_title( $option_slug ) || $selected === $option_slug );
						$term_label  = $this->get_readable_term_label( $attribute, $option_slug );
						$swatch_meta = $this->get_term_swatch_metadata( $product, $attribute, $option_slug, $display_mode, $term_variation_thumbs );

						$item_classes = array(
							'wvs-swatch-item',
							'wvs-swatch-type-' . esc_attr( $swatch_meta['render_type'] ),
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
							title="<?php echo esc_attr( $term_label ); ?>"
							aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">

							<?php if ( $enable_tip ) : ?>
								<span class="wvs-swatch-tooltip" aria-hidden="true"><?php echo esc_html( $term_label ); ?></span>
							<?php endif; ?>

							<?php if ( 'image' === $swatch_meta['render_type'] && ! empty( $swatch_meta['image_url'] ) ) : ?>
								<span class="wvs-swatch-thumb">
									<img src="<?php echo esc_url( $swatch_meta['image_url'] ); ?>" alt="<?php echo esc_attr( $term_label ); ?>" loading="lazy" />
								</span>
							<?php elseif ( 'color' === $swatch_meta['render_type'] ) : ?>
								<span class="wvs-swatch-circle" style="<?php echo esc_attr( $swatch_meta['css_background'] ); ?>"></span>
							<?php elseif ( 'radio' === $swatch_meta['render_type'] ) : ?>
								<span class="wvs-swatch-radio-dot"></span>
								<span class="wvs-swatch-text"><?php echo esc_html( $term_label ); ?></span>
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
		} catch ( \Throwable $e ) {
			return $htmlSelect;
		}
	}

	/**
	 * Resolve attribute display mode cascading from:
	 * 1) Product Data > Attributes tab override (`_wvs_product_attr_settings`)
	 * 2) Global Attribute Type (`Products > Attributes` Type selector)
	 * 3) Variation Studio Global Settings (`attr_display_{name}` or Auto-Convert rules)
	 *
	 * @param WC_Product $product   Product object.
	 * @param string     $attribute Attribute name or taxonomy.
	 * @return string
	 */
	private function resolve_attribute_display_mode( $product, $attribute ) {
		$attr_key   = sanitize_title( $attribute );
		$clean_name = str_replace( 'pa_', '', strtolower( trim( $attribute ) ) );

		// 1. Check Product-Level Attribute Override saved in Product Data > Attributes.
		$prod_attr_settings = get_post_meta( $product->get_id(), '_wvs_product_attr_settings', true );
		if ( is_array( $prod_attr_settings ) && ! empty( $prod_attr_settings[ $attr_key ]['type'] ) ) {
			$prod_type = sanitize_key( (string) $prod_attr_settings[ $attr_key ]['type'] );
			if ( 'auto' !== $prod_type ) {
				return $prod_type;
			}
		}

		// 2. Check Native Global Attribute Type (`Products > Attributes`).
		if ( taxonomy_exists( $attribute ) && function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
			$tax_id = wc_attribute_taxonomy_id_by_name( $attribute );
			if ( $tax_id > 0 && function_exists( 'wc_get_attribute' ) ) {
				$wc_attr = wc_get_attribute( $tax_id );
				if ( is_object( $wc_attr ) && ! empty( $wc_attr->type ) && 'select' !== $wc_attr->type ) {
					return sanitize_key( (string) $wc_attr->type );
				}
			}
		}

		// 3. Check Variation Studio Global Settings.
		$settings = class_exists( 'WVS_Inheritance_Engine' ) ? WVS_Inheritance_Engine::get_global_settings() : get_option( 'wvs_global_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings[ 'attr_display_' . $clean_name ] ) ) {
			return sanitize_key( (string) $settings[ 'attr_display_' . $clean_name ] );
		}

		// 4. Smart Auto-Convert: Color attributes use Variation Featured Image automatically; others use Button.
		if ( false !== strpos( $clean_name, 'color' ) || false !== strpos( $clean_name, 'colour' ) ) {
			return 'variation_image';
		}

		return 'button';
	}

	/**
	 * Collect variation main thumbnail URLs indexed by both raw option value and sanitized slug.
	 *
	 * @param WC_Product $product   Variable product.
	 * @param string     $attribute Attribute slug or name.
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
			if ( '' === $term_val ) {
				continue;
			}

			$slug_key = sanitize_title( $term_val );
			if ( isset( $map[ $term_val ] ) && isset( $map[ $slug_key ] ) ) {
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
					$map[ $slug_key ] = (string) $img_url;
				}
			}
		}

		return $map;
	}

	/**
	 * Get readable term label for an attribute option slug or custom attribute string.
	 *
	 * @param string $attribute   Taxonomy or custom attribute name.
	 * @param string $option_slug Term slug or raw option text.
	 * @return string
	 */
	private function get_readable_term_label( $attribute, $option_slug ) {
		if ( '' === $option_slug ) {
			return '';
		}
		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $option_slug, $attribute );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $option_slug;
	}

	/**
	 * Resolve visual metadata (Image URL, Hex Color, Radio, or Button Text) for a single swatch button.
	 *
	 * @param WC_Product            $product          Product object.
	 * @param string                $attribute        Attribute name.
	 * @param string                $option_slug      Term slug or custom option value.
	 * @param string                $mode             Resolved display mode.
	 * @param array<string, string> $variation_thumbs Map of variation thumbnail URLs.
	 * @return array<string, string>
	 */
	private function get_term_swatch_metadata( $product, $attribute, $option_slug, $mode, $variation_thumbs ) {
		$result = array(
			'render_type'    => 'text',
			'image_url'      => '',
			'css_background' => 'background-color:#cccccc;',
		);

		if ( 'radio' === $mode ) {
			$result['render_type'] = 'radio';
			return $result;
		}

		if ( 'button' === $mode || 'text_box' === $mode ) {
			return $result;
		}

		$slug_key      = sanitize_title( $option_slug );
		$attr_key      = sanitize_title( $attribute );
		$term_img_url  = '';
		$primary_hex   = '';
		$secondary_hex = '';

		// Check Product-Level Custom Attribute Hex Color (`_wvs_product_attr_settings`).
		$prod_attr_settings = get_post_meta( $product->get_id(), '_wvs_product_attr_settings', true );
		if ( is_array( $prod_attr_settings ) && ! empty( $prod_attr_settings[ $attr_key ]['colors'][ $slug_key ] ) ) {
			$primary_hex = (string) $prod_attr_settings[ $attr_key ]['colors'][ $slug_key ];
		}

		// Check Global Taxonomy Term Meta if applicable.
		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $option_slug, $attribute );
			if ( $term && ! is_wp_error( $term ) ) {
				$custom_img_id = (int) get_term_meta( $term->term_id, '_wvs_swatch_image_id', true );
				if ( $custom_img_id > 0 ) {
					$url = wp_get_attachment_image_url( $custom_img_id, 'thumbnail' );
					if ( $url ) {
						$term_img_url = (string) $url;
					}
				}
				if ( '' === $primary_hex ) {
					$primary_hex = (string) get_term_meta( $term->term_id, '_wvs_primary_hex', true );
				}
				$secondary_hex = (string) get_term_meta( $term->term_id, '_wvs_secondary_hex', true );
			}
		}

		$var_thumb = '';
		if ( ! empty( $variation_thumbs[ $option_slug ] ) ) {
			$var_thumb = $variation_thumbs[ $option_slug ];
		} elseif ( ! empty( $variation_thumbs[ $slug_key ] ) ) {
			$var_thumb = $variation_thumbs[ $slug_key ];
		}

		// Mode A: Variation Featured Image Swatch (`variation_image` or `auto_image`).
		if ( in_array( $mode, array( 'variation_image', 'auto_image' ), true ) ) {
			if ( '' !== $var_thumb ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $var_thumb;
				return $result;
			}
			if ( '' !== $term_img_url ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $term_img_url;
				return $result;
			}
		}

		// Mode B: Dedicated Term Image Swatch (`image`).
		if ( 'image' === $mode ) {
			if ( '' !== $term_img_url ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $term_img_url;
				return $result;
			}
			if ( '' !== $var_thumb ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $var_thumb;
				return $result;
			}
		}

		// Mode C: Color / Dual-Color Circle (`color` or `color_circle`, or fallback if no image exists).
		if ( in_array( $mode, array( 'color', 'color_circle', 'variation_image', 'auto_image' ), true ) && '' !== $primary_hex ) {
			$result['render_type'] = 'color';
			if ( '' !== $secondary_hex ) {
				$result['css_background'] = sprintf(
					'background: linear-gradient(135deg, %1$s 50%%, %2$s 50%%);',
					esc_attr( $primary_hex ),
					esc_attr( $secondary_hex )
				);
			} else {
				$result['css_background'] = sprintf( 'background-color: %s;', esc_attr( $primary_hex ) );
			}
			return $result;
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
		try {
			if ( ! is_array( $variation_data ) || ! is_object( $variation ) ) {
				return $variation_data;
			}

			$image_ids = array();
			$main_id   = (int) $variation->get_image_id();
			if ( $main_id > 0 ) {
				$image_ids[] = $main_id;
			}

			$raw_gallery = $variation->get_meta( '_wc_additional_variation_images', true );
			if ( empty( $raw_gallery ) ) {
				$raw_gallery = $variation->get_meta( '_wvs_variation_gallery_ids', true );
			}
			if ( empty( $raw_gallery ) ) {
				$raw_gallery = $variation->get_meta( 'woo_variation_gallery_images', true );
			}

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
		} catch ( \Throwable $e ) {
			return $variation_data;
		}
	}

	/**
	 * Output lightweight JSON matrix of all child variations for instant two-way Size <-> Colour filtering.
	 */
	public function render_variation_matrix_json() {
		try {
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
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Render Ship to Home vs In-Store Pickup block when enabled.
	 */
	public function render_fulfilment_and_cross_sells() {
		try {
			global $product;
			if ( ! $product instanceof WC_Product ) {
				return;
			}

			$settings = class_exists( 'WVS_Inheritance_Engine' )
				? WVS_Inheritance_Engine::get_global_settings()
				: get_option( 'wvs_global_settings', array() );

			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

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
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
