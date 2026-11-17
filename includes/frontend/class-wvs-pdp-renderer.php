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

		// Replace standard WooCommerce dropdowns with Emran-style Swatches while keeping native <select> for WC JS.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'render_swatch_selector_html' ), 20, 2 );

		// Bypass 30-variation AJAX threshold so two-way Size <-> Colour availability always works instantly.
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'increase_variation_threshold' ), 20, 2 );

		// Attach variation-specific gallery array for Flatsome Flickity slider isolation.
		add_filter( 'woocommerce_available_variation', array( $this, 'inject_variation_gallery_data' ), 20, 3 );

		// Output variation availability matrix & delivery/cross-sell blocks below Add to Cart.
		add_action( 'woocommerce_after_variations_table', array( $this, 'render_variation_matrix_json' ), 10 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render_fulfilment_and_cross_sells' ), 15 );
	}

	/**
	 * Enqueue frontend CSS and JS assets.
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

		$cross_mode = isset( $settings['cross_attr_mode'] ) ? (string) $settings['cross_attr_mode'] : 'smart_switch';
		$strict_iso = isset( $settings['strict_gallery_isolation'] ) ? (string) $settings['strict_gallery_isolation'] : 'yes';
		$active_sty = isset( $settings['swatch_active_style'] ) ? (string) $settings['swatch_active_style'] : 'option_4a';

		wp_localize_script(
			'wvs-frontend-ux',
			'wvsFrontendConfig',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'crossAttrMode'          => $cross_mode,
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
	 * Add helper classes to body for sticky desktop gallery and active swatch contrast style.
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

		$active_style = isset( $settings['swatch_active_style'] ) ? sanitize_html_class( (string) $settings['swatch_active_style'] ) : 'option_4a';
		$classes[]    = 'wvs-active-style-' . $active_style;

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
	}

	/**
	 * Increase variation threshold so multi-attribute bikes (2-4 attributes) preload stock status cleanly.
	 *
	 * @param int        $threshold Default threshold (30).
	 * @param WC_Product $product   Product object.
	 * @return int
	 */
	public function increase_variation_threshold( $threshold, $product ) {
		return 120;
	}

	/**
	 * Render interactive Swatches (Image, Colour/Split Circle, or Button Pill) while keeping native <select> hidden.
	 *
	 * @param string              $htmlSelect Native select dropdown HTML.
	 * @param array<string,mixed> $args       Dropdown arguments.
	 * @return string
	 */
	public function render_swatch_selector_html( $htmlSelect, $args ) {
		$product   = isset( $args['product'] ) ? $args['product'] : null;
		$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
		$options   = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		$selected  = isset( $args['selected'] ) ? (string) $args['selected'] : '';

		if ( ! $product instanceof WC_Product || empty( $attribute ) || empty( $options ) ) {
			return $htmlSelect;
		}

		$display_mode = $this->resolve_attribute_display_mode( $attribute );
		$is_visual    = in_array( $display_mode, array( 'auto_image', 'color_circle', 'custom_image' ), true );

		// Map variation thumbnails per attribute term value for Auto Variation Thumbnail mode.
		$term_variation_Thumbnails = $this->get_variation_thumbnails_for_attribute( $product, $attribute );

		// Hide native <select> visually so WooCommerce variation script remains synced.
		$hidden_select = '<div class="wvs-hidden-select-wrap" style="display:none !important;">' . $htmlSelect . '</div>';

		ob_start();
		?>
		<div class="wvs-swatches-container"
			data-attribute_name="attribute_<?php echo esc_attr( sanitize_title( $attribute ) ); ?>"
			data-raw-attribute="<?php echo esc_attr( $attribute ); ?>"
			data-display-mode="<?php echo esc_attr( $display_mode ); ?>"
			data-is-visual="<?php echo $is_visual ? 'yes' : 'no'; ?>">

			<div class="wvs-selected-label-row">
				<span class="wvs-selected-term-value"><?php echo esc_html( $this->get_Readable_term_label( $attribute, $selected ) ); ?></span>
			</div>

			<div class="wvs-swatches-list wvs-mode-<?php echo esc_attr( $display_mode ); ?>" role="radiogroup">
				<?php
				foreach ( $options as $option_slug ) {
					$option_slug = (string) $option_slug;
					$is_selected = ( sanitize_title( $selected ) === sanitize_title( $option_slug ) || $selected === $option_slug );
					$term_label  = $this->get_Readable_term_label( $attribute, $option_slug );
					$swatch_meta = $this->get_term_swatch_metadata( $attribute, $option_slug, $term_variation_Thumbnails );

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

						<?php if ( 'image' === $swatch_meta['render_type'] && ! empty( $swatch_meta['image_url'] ) ) : ?>
							<span class="wvs-swatch-thumb">
								<img src="<?php echo esc_url( $swatch_meta['image_url'] ); ?>" alt="<?php echo esc_attr( $term_label ); ?>" loading="lazy" />
							</span>
						<?php elseif ( 'color' === $swatch_meta['render_type'] ) : ?>
							<span class="wvs-swatch-circle" style="<?php echo esc_attr( $swatch_meta['css_background'] ); ?>"></span>
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
	 * Determine display mode for a given attribute (Auto Variation Image, Colour Circle, or Text Box).
	 *
	 * @param string $attribute Attribute taxonomy or name.
	 * @return string
	 */
	private function resolve_attribute_display_mode( $attribute ) {
		$settings   = class_exists( 'WVS_Inheritance_Engine' ) ? WVS_Inheritance_Engine::get_global_settings() : get_option( 'wvs_global_settings', array() );
		$clean_name = str_replace( 'pa_', '', strtolower( trim( $attribute ) ) );

		// 1. Check direct setting saved from Admin Menu (`attr_display_{name}`).
		if ( ! empty( $settings[ 'attr_display_' . $clean_name ] ) ) {
			return sanitize_key( (string) $settings[ 'attr_display_' . $clean_name ] );
		}

		// 2. Check structured `attribute_display_map` array if present.
		if ( isset( $settings['attribute_display_map'] ) && is_array( $settings['attribute_display_map'] ) ) {
			if ( ! empty( $settings['attribute_display_map'][ $attribute ] ) ) {
				return sanitize_key( (string) $settings['attribute_display_map'][ $attribute ] );
			}
			if ( ! empty( $settings['attribute_display_map'][ $clean_name ] ) ) {
				return sanitize_key( (string) $settings['attribute_display_map'][ $clean_name ] );
			}
		}

		// 3. Smart auto-detection fallback for Color vs Size/Groupset/Wheelset.
		if ( false !== strpos( $clean_name, 'color' ) || false !== strpos( $clean_name, 'colour' ) ) {
			return 'auto_image';
		}

		return 'text_box';
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
	private function get_Readable_term_label( $attribute, $option_slug ) {
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
	 * Resolve visual metadata (Image URL, Primary/Secondary Hex, Split Gradient, or Text) for a single swatch button.
	 *
	 * @param string                $attribute        Attribute name.
	 * @param string                $option_slug      Term slug.
	 * @param array<string, string> $variation_thumbs Map of variation thumbnail URLs.
	 * @return array<string, string>
	 */
	private function get_term_swatch_metadata( $attribute, $option_slug, $variation_thumbs ) {
		$mode = $this->resolve_attribute_display_mode( $attribute );

		$result = array(
			'render_type'    => 'text',
			'image_url'      => '',
			'css_background' => 'background-color:#cccccc;',
		);

		if ( 'text_box' === $mode ) {
			return $result;
		}

		$term_img_url  = '';
		$primary_hex   = '';
		$secondary_hex = '';
		$is_dual       = false;

		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $option_slug, $attribute );
			if ( $term && ! is_wp_error( $term ) ) {
				$custom_img_id = (int) get_term_meta( $term->term_id, '_wvs_swatch_image_id', true );
				if ( ! $custom_img_id ) {
					$custom_img_id = (int) get_term_meta( $term->term_id, 'wvs_swatch_image', true );
				}
				if ( $custom_img_id > 0 ) {
					$url = wp_get_attachment_image_url( $custom_img_id, 'thumbnail' );
					if ( $url ) {
						$term_img_url = (string) $url;
					}
				}

				$primary_hex = (string) get_term_meta( $term->term_id, '_wvs_primary_hex', true );
				if ( '' === $primary_hex ) {
					$primary_hex = (string) get_term_meta( $term->term_id, 'wvs_color', true );
				}
				$secondary_hex = (string) get_term_meta( $term->term_id, '_wvs_secondary_hex', true );
				$is_dual       = ( '' !== $secondary_hex );
			}
		}

		// Mode 1: Auto Variation Thumbnail Image (with fallback to term image or color circle).
		if ( 'auto_image' === $mode ) {
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

		// Mode 2: Colour / Dual-Color Circle.
		if ( 'color_circle' === $mode || 'auto_image' === $mode ) {
			if ( '' !== $term_img_url && 'color_circle' !== $mode ) {
				$result['render_type'] = 'image';
				$result['image_url']   = $term_img_url;
				return $result;
			}
			if ( '' !== $primary_hex ) {
				$result['render_type'] = 'color';
				if ( $is_dual ) {
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

		// Read Native WooCommerce 11.1+ / custom variation gallery IDs.
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
	 * Render Ship to Home vs In-Store Pickup block and Complete Your Ride cross-sells when enabled.
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
