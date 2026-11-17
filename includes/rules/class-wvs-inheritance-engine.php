<?php
/**
 * 4-Tier Settings Inheritance Engine & Brand / Size Guide Resolver
 *
 * File Path: includes/rules/class-wvs-inheritance-engine.php
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVS_Inheritance_Engine {

	/**
	 * In-memory runtime cache.
	 *
	 * @var array<string, mixed>
	 */
	private static $runtime_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		self::init();
	}

	/**
	 * Initialize settings cache.
	 */
	public static function init() {
		self::get_global_settings();
	}

	/**
	 * Backward-compatible alias for init_settings().
	 */
	public static function init_settings() {
		return self::get_global_settings();
	}

	/**
	 * Return merged global settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_global_settings() {
		if ( isset( self::$runtime_cache['global_settings'] ) && is_array( self::$runtime_cache['global_settings'] ) ) {
			return self::$runtime_cache['global_settings'];
		}

		$defaults = array(
			'card_style'                  => 'style_a',
			'catalog_card_preset'         => 'specialized_spec',
			'catalog_hover_mode'          => 'angle_2',
			'oos_swatch_behavior'         => 'clickable_preview',
			'catalog_max_swatches'        => 4,
			'mobile_gallery_layout'       => 'floating_right',
			'cross_attr_mode'             => 'smart_switch',
			'cross_attr_oos_mode'         => 'smart_switch',
			'strict_gallery_isolation'    => 'yes',
			'pdp_gallery_isolation'       => 'strict_variation',
			'visual_swatch_mode'          => 'smart_hybrid',
			'swatch_active_style'         => 'option_4a',
			'brand_sync_display'          => 'option_1a',
			'pdp_header_meta_mode'        => 'option_2a',
			'sticky_desktop_gallery'      => 'yes',
			'auto_stock_winner'           => 'yes',
			'stock_calc_mode'             => 'grouped_color',
			'auto_copy_galleries'         => 'yes',
			'auto_copy_color_gallery'     => 'yes',
			'parent_gallery_mode'         => 'winner_only',
			'tie_breaker'                 => 'price_desc',
			'enable_delivery_block'       => 'yes',
			'delivery_layout_style'       => 'option_6a',
			'wc_shipping_sync_mode'       => 'auto_wc',
			'delivery_heading_label'      => 'Delivery Method',
			'default_home_lead'           => '4-7 days',
			'default_pickup_lead'         => '6-10 days',
			'home_card_title'             => 'Standard Delivery',
			'pickup_card_title'           => 'In-Store Pickup',
			'default_size_guide'          => '',
			'enable_complete_ride'        => 'yes',
			'enable_product_group_schema' => 'yes',
			'enable_deep_link_ssr'        => 'yes',
			'attribute_display_map'       => array(),
			'shipping_method_meta'        => array(),
		);

		$saved = get_option( 'wvs_global_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = array_merge( $defaults, $saved );
		self::$runtime_cache['global_settings'] = $merged;

		return $merged;
	}

	/**
	 * Get a single global setting key with fallback default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get_global_setting( $key, $default = '' ) {
		$settings = self::get_global_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Resolve a single setting cascading from Product > Category > Global.
	 *
	 * @param int    $product_id  Product ID.
	 * @param string $setting_key Setting key.
	 * @param mixed  $default     Fallback value.
	 * @return mixed
	 */
	public static function resolve_setting( $product_id, $setting_key, $default = '' ) {
		$config = self::resolve_product_config( (int) $product_id );
		return isset( $config[ $setting_key ] ) ? $config[ $setting_key ] : $default;
	}

	/**
	 * Resolve effective configuration array for a specific product (Product -> Category -> Global).
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	public static function resolve_product_config( $product_id ) {
		$product_id = (int) $product_id;
		$cache_key  = 'prod_' . $product_id;

		if ( isset( self::$runtime_cache[ $cache_key ] ) && is_array( self::$runtime_cache[ $cache_key ] ) ) {
			return self::$runtime_cache[ $cache_key ];
		}

		$config = self::get_global_settings();

		if ( $product_id > 0 ) {
			$terms = get_the_terms( $product_id, 'product_cat' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$cat_preset = get_term_meta( $term->term_id, '_wvs_cat_card_preset', true );
					if ( ! empty( $cat_preset ) && 'inherit' !== $cat_preset ) {
						$config['catalog_card_preset'] = sanitize_key( (string) $cat_preset );
					}
					$cat_hover = get_term_meta( $term->term_id, '_wvs_cat_hover_mode', true );
					if ( ! empty( $cat_hover ) && 'inherit' !== $cat_hover ) {
						$config['catalog_hover_mode'] = sanitize_key( (string) $cat_hover );
					}
				}
			}

			$prod_swatch_mode = get_post_meta( $product_id, '_wvs_product_swatch_mode', true );
			if ( ! empty( $prod_swatch_mode ) && 'inherit' !== $prod_swatch_mode ) {
				$config['visual_swatch_mode'] = sanitize_key( (string) $prod_swatch_mode );
			}

			$prod_gal_iso = get_post_meta( $product_id, '_wvs_pdp_gallery_isolation', true );
			if ( ! empty( $prod_gal_iso ) && 'inherit' !== $prod_gal_iso ) {
				$config['pdp_gallery_isolation'] = sanitize_key( (string) $prod_gal_iso );
			}
		}

		self::$runtime_cache[ $cache_key ] = $config;
		return $config;
	}

	/**
	 * Resolve native WooCommerce Brand (`product_brand`) or fallback tagline for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, string>
	 */
	public static function resolve_product_brand( $product_id ) {
		$product_id = (int) $product_id;
		$result     = array(
			'name'     => '',
			'url'      => '',
			'logo_url' => '',
			'tagline'  => '',
		);

		if ( $product_id <= 0 ) {
			return $result;
		}

		$custom_tagline    = (string) get_post_meta( $product_id, '_wvs_brand_tagline', true );
		$result['tagline'] = trim( $custom_tagline );

		$taxonomies = array( 'product_brand', 'pwb-brand', 'yith_product_brand' );
		foreach ( $taxonomies as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = wp_get_post_terms( $product_id, $tax );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) && is_object( $terms[0] ) ) {
					$brand_term     = $terms[0];
					$result['name'] = (string) $brand_term->name;
					$term_link      = get_term_link( $brand_term );
					if ( ! is_wp_error( $term_link ) ) {
						$result['url'] = (string) $term_link;
					}
					$thumb_id = (int) get_term_meta( $brand_term->term_id, 'thumbnail_id', true );
					if ( $thumb_id > 0 ) {
						$logo = wp_get_attachment_image_url( $thumb_id, 'medium' );
						if ( $logo ) {
							$result['logo_url'] = (string) $logo;
						}
					}
					break;
				}
			}
		}

		if ( '' === $result['tagline'] && '' !== $result['name'] ) {
			$result['tagline'] = $result['name'];
		}

		return $result;
	}

	/**
	 * Resolve Size Guide UX Block shortcode or slug from Product -> Category -> Global setting.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, string>
	 */
	public static function resolve_size_guide_block( $product_id ) {
		$product_id = (int) $product_id;
		$raw        = '';

		if ( $product_id > 0 ) {
			$raw = trim( (string) get_post_meta( $product_id, '_wvs_size_guide_block', true ) );
		}

		if ( '' === $raw && $product_id > 0 ) {
			$terms = get_the_terms( $product_id, 'product_cat' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$cat_guide = trim( (string) get_term_meta( $term->term_id, '_wvs_cat_size_guide', true ) );
					if ( '' !== $cat_guide ) {
						$raw = $cat_guide;
						break;
					}
				}
			}
		}

		if ( '' === $raw ) {
			$global = self::get_global_settings();
			$raw    = trim( (string) ( $global['default_size_guide'] ?? '' ) );
		}

		if ( '' === $raw ) {
			return array(
				'id_or_slug' => '',
				'shortcode'  => '',
			);
		}

		$slug = $raw;
		if ( preg_match( '/id=["\']([^"\']+)["\']/i', $raw, $matches ) ) {
			$slug = trim( $matches[1] );
		} else {
			$slug = trim( str_replace( array( '[block', ']', '"', "'" ), '', $raw ) );
		}

		return array(
			'id_or_slug' => $slug,
			'shortcode'  => '[block id="' . esc_attr( $slug ) . '"]',
		);
	}
}
