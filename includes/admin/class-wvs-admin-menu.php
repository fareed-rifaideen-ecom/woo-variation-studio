<?php
/**
 * Admin Menu & 3-Tab Visual Studio Dashboard
 *
 * File Path: includes/admin/class-wvs-admin-menu.php
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVS_Admin_Menu {

	/**
	 * Singleton instance.
	 *
	 * @var WVS_Admin_Menu|null
	 */
	private static $instance = null;

	/**
	 * Initialize class.
	 *
	 * @return WVS_Admin_Menu
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
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wvs_sync_all_products', array( $this, 'handle_manual_sync_all' ) );
	}

	/**
	 * Register top-level Variation Studio menu.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Woo Variation Studio', 'woo-variation-studio' ),
			__( 'Variation Studio', 'woo-variation-studio' ),
			'manage_woocommerce',
			'wvs-studio',
			array( $this, 'render_admin_page' ),
			'dashicons-art',
			56
		);
	}

	/**
	 * Register plugin settings group.
	 */
	public function register_settings() {
		register_setting(
			'wvs_studio_options_group',
			'wvs_global_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Merge and sanitize submitted settings across tabs so saving one tab preserves settings on other tabs.
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( 'wvs_global_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$active_tab = isset( $input['_active_tab'] ) ? sanitize_key( (string) $input['_active_tab'] ) : 'look_feel';

		// Handle unchecked checkboxes on the active tab.
		if ( 'look_feel' === $active_tab ) {
			$look_feel_checkboxes = array(
				'auto_dropdown_to_button',
				'auto_dropdown_to_image',
				'show_selected_label',
				'enable_tooltip',
				'clear_on_reselect',
				'strict_gallery_isolation',
				'sticky_desktop_gallery',
				'enable_archive_swatches',
			);
			foreach ( $look_feel_checkboxes as $cb_key ) {
				$existing[ $cb_key ] = isset( $input[ $cb_key ] ) ? 'yes' : 'no';
			}
		} elseif ( 'stock_gallery' === $active_tab ) {
			$stock_checkboxes = array(
				'auto_stock_winner',
				'auto_copy_galleries',
				'enable_delivery_block',
			);
			foreach ( $stock_checkboxes as $cb_key ) {
				$existing[ $cb_key ] = isset( $input[ $cb_key ] ) ? 'yes' : 'no';
			}
		} elseif ( 'developer' === $active_tab ) {
			$dev_checkboxes = array(
				'enable_product_group_schema',
				'enable_deep_link_ssr',
			);
			foreach ( $dev_checkboxes as $cb_key ) {
				$existing[ $cb_key ] = isset( $input[ $cb_key ] ) ? 'yes' : 'no';
			}
		}

		foreach ( $input as $key => $val ) {
			if ( '_active_tab' === $key ) {
				continue;
			}
			$clean_key = sanitize_key( (string) $key );
			if ( is_array( $val ) ) {
				$existing[ $clean_key ] = array_map( 'sanitize_text_field', $val );
			} else {
				$existing[ $clean_key ] = sanitize_text_field( (string) $val );
			}
		}

		return $existing;
	}

	/**
	 * Handle 1-Click Manual Sync of all Variable Products.
	 */
	public function handle_manual_sync_all() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'woo-variation-studio' ) );
		}

		check_admin_referer( 'wvs_sync_all_nonce' );

		$Synced_count = 0;
		if ( class_exists( 'WVS_Stock_Calculator' ) ) {
			$product_ids = wc_get_products(
				array(
					'type'   => 'variable',
					'limit'  => -1,
					'return' => 'ids',
					'status' => 'publish',
				)
			);

			if ( is_array( $product_ids ) ) {
				foreach ( $product_ids as $pid ) {
					WVS_Stock_Calculator::sync_parent_stock_and_images( (int) $pid );
					$Synced_count++;
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'wvs-studio',
					'tab'    => 'stock_gallery',
					'synced' => $Synced_count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the 3-Tab Visual Studio Admin Interface.
	 */
	public function render_admin_page() {
		$settings   = class_exists( 'WVS_Inheritance_Engine' )
			? WVS_Inheritance_Engine::get_global_settings()
			: get_option( 'wvs_global_settings', array() );
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'look_feel';
		$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		?>
		<div class="wrap wvs-admin-wrap">
			<h1><?php esc_html_e( 'Woo Variation Studio', 'woo-variation-studio' ); ?></h1>
			<p class="description" style="margin-bottom:15px;">
				<?php esc_html_e( 'Visual variation swatches, cross-attribute stock filtering, strict gallery isolation, and automated highest-stock catalog image autopilot.', 'woo-variation-studio' ); ?>
			</p>

			<?php if ( isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of variable products synced */
							esc_html__( 'Successfully recalculated highest-stock main images and variation galleries across %d variable products.', 'woo-variation-studio' ),
							absint( $_GET['synced'] )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="?page=wvs-studio&tab=look_feel" class="nav-tab <?php echo 'look_feel' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '1. Swatches & Look & Feel', 'woo-variation-studio' ); ?>
				</a>
				<a href="?page=wvs-studio&tab=stock_gallery" class="nav-tab <?php echo 'stock_gallery' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '2. Smart Stock & Gallery Autopilot', 'woo-variation-studio' ); ?>
				</a>
				<a href="?page=wvs-studio&tab=developer" class="nav-tab <?php echo 'developer' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '3. System & SEO Tools', 'woo-variation-studio' ); ?>
				</a>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( 'wvs_studio_options_group' ); ?>
				<input type="hidden" name="wvs_global_settings[_active_tab]" value="<?php echo esc_attr( $active_tab ); ?>" />

				<div class="wvs-tab-content" style="background:#fff; padding:24px; border:1px solid #ccd0d4; border-top:none; max-width:1080px;">
					<?php if ( 'look_feel' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Variation Swatch Display & Styling', 'woo-variation-studio' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Control how variation dropdowns convert into Color, Image, Button, and Radio swatches across product and shop pages.', 'woo-variation-studio' ); ?>
						</p>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-Convert Dropdowns to Button', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[auto_dropdown_to_button]" value="yes" <?php checked( $settings['auto_dropdown_to_button'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Automatically convert default select dropdowns into clickable button/label swatches.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-Convert Dropdowns to Variation Image', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[auto_dropdown_to_image]" value="yes" <?php checked( $settings['auto_dropdown_to_image'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Automatically use the variation featured image as an image swatch when a color/visual attribute has variation photos assigned.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Swatch Shape Style', 'woo-variation-studio' ); ?></th>
								<td>
									<select name="wvs_global_settings[swatch_shape]">
										<option value="rounded" <?php selected( $settings['swatch_shape'] ?? 'rounded', 'rounded' ); ?>><?php esc_html_e( 'Rounded (Circle for Colors / Rounded Corners for Buttons)', 'woo-variation-studio' ); ?></option>
										<option value="squared" <?php selected( $settings['swatch_shape'] ?? 'rounded', 'squared' ); ?>><?php esc_html_e( 'Squared (Crisp Square Swatches & Buttons)', 'woo-variation-studio' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Product Page Swatch Dimensions (px)', 'woo-variation-studio' ); ?></th>
								<td>
									<label style="margin-right:16px;">
										<?php esc_html_e( 'Width:', 'woo-variation-studio' ); ?>
										<input type="number" name="wvs_global_settings[swatch_width]" value="<?php echo esc_attr( (string) ( $settings['swatch_width'] ?? '42' ) ); ?>" min="20" max="120" style="width:70px;" /> px
									</label>
									<label>
										<?php esc_html_e( 'Height:', 'woo-variation-studio' ); ?>
										<input type="number" name="wvs_global_settings[swatch_height]" value="<?php echo esc_attr( (string) ( $settings['swatch_height'] ?? '42' ) ); ?>" min="20" max="120" style="width:70px;" /> px
									</label>
									<p class="description"><?php esc_html_e( 'Controls the size of Color and Image swatches on Single Product pages (Button swatches expand automatically to fit text).', 'woo-variation-studio' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Show Selected Attribute Name', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[show_selected_label]" value="yes" <?php checked( $settings['show_selected_label'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Display the selected variation name beside the attribute label (e.g., "Colour: Gloss Black").', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Hover Tooltip', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[enable_tooltip]" value="yes" <?php checked( $settings['enable_tooltip'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Show floating text tooltip on hover above swatches.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Out-of-Stock / Unavailable Visual Behavior', 'woo-variation-studio' ); ?></th>
								<td>
									<select name="wvs_global_settings[disabled_attribute_effect]">
										<option value="cross" <?php selected( $settings['disabled_attribute_effect'] ?? 'cross', 'cross' ); ?>><?php esc_html_e( 'Cross Out (Diagonal Red Strike-Through + Faded)', 'woo-variation-studio' ); ?></option>
										<option value="blur" <?php selected( $settings['disabled_attribute_effect'] ?? 'cross', 'blur' ); ?>><?php esc_html_e( 'Blur / Fade Out (No Strike-Through Line)', 'woo-variation-studio' ); ?></option>
										<option value="hide" <?php selected( $settings['disabled_attribute_effect'] ?? 'cross', 'hide' ); ?>><?php esc_html_e( 'Hide Unavailable Swatch Options Completely', 'woo-variation-studio' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Cross-Attribute Click Behavior (Size <-> Colour)', 'woo-variation-studio' ); ?></th>
								<td>
									<select name="wvs_global_settings[cross_attr_mode]">
										<option value="smart_switch" <?php selected( $settings['cross_attr_mode'] ?? 'smart_switch', 'smart_switch' ); ?>><?php esc_html_e( 'Smart Switch (Allow clicking crossed-out colour and auto-switch to first in-stock size)', 'woo-variation-studio' ); ?></option>
										<option value="strict_lock" <?php selected( $settings['cross_attr_mode'] ?? 'smart_switch', 'strict_lock' ); ?>><?php esc_html_e( 'Strict Lock (Prevent clicking out-of-stock combinations until selection is cleared)', 'woo-variation-studio' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Strict Single Product Gallery Isolation', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[strict_gallery_isolation]" value="yes" <?php checked( $settings['strict_gallery_isolation'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Show STRICTLY ONLY the selected variation\'s images in the Flatsome product gallery (never mix other colors).', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Sticky Desktop Product Gallery', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[sticky_desktop_gallery]" value="yes" <?php checked( $settings['sticky_desktop_gallery'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Keep the left product image gallery sticky on desktop while scrolling through options on the right.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Shop Archive Swatches & Card Preset', 'woo-variation-studio' ); ?></th>
								<td>
									<label style="display:block; margin-bottom:8px;">
										<input type="checkbox" name="wvs_global_settings[enable_archive_swatches]" value="yes" <?php checked( $settings['enable_archive_swatches'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Display interactive color/image swatches on Shop and Category archive cards.', 'woo-variation-studio' ); ?>
									</label>
									<select name="wvs_global_settings[card_style]">
										<option value="style_a" <?php selected( $settings['card_style'] ?? 'style_a', 'style_a' ); ?>><?php esc_html_e( 'Style A: Technical Spec Card', 'woo-variation-studio' ); ?></option>
										<option value="style_b" <?php selected( $settings['card_style'] ?? 'style_a', 'style_b' ); ?>><?php esc_html_e( 'Style B: Minimalist Showroom Card', 'woo-variation-studio' ); ?></option>
										<option value="style_c" <?php selected( $settings['card_style'] ?? 'style_a', 'style_c' ); ?>><?php esc_html_e( 'Style C: Quick-Add Size Bar Card', 'woo-variation-studio' ); ?></option>
									</select>
								</td>
							</tr>
						</table>

						<hr style="margin:24px 0;" />

						<h3><?php esc_html_e( 'Global Attribute Display Mapping Override', 'woo-variation-studio' ); ?></h3>
						<p class="description" style="margin-bottom:12px;">
							<?php esc_html_e( 'You can set each attribute\'s Type under Products > Attributes, or override how each attribute renders directly from this table:', 'woo-variation-studio' ); ?>
						</p>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Attribute Name', 'woo-variation-studio' ); ?></th>
									<th><?php esc_html_e( 'Configured WC Type', 'woo-variation-studio' ); ?></th>
									<th><?php esc_html_e( 'Display Mode Override', 'woo-variation-studio' ); ?></th>
									<th><?php esc_html_e( 'Configure Terms', 'woo-variation-studio' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								if ( ! empty( $attributes ) ) :
									foreach ( $attributes as $attr ) :
										$current_mode = $settings[ 'attr_display_' . $attr->attribute_name ] ?? 'inherit_wc';
										$tax_slug     = wc_attribute_taxonomy_name( $attr->attribute_name );
										$terms_url    = admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $tax_slug ) . '&post_type=product' );
										?>
										<tr>
											<td><strong><?php echo esc_html( $attr->attribute_label ); ?></strong> (<code><?php echo esc_html( $tax_slug ); ?></code>)</td>
											<td><code><?php echo esc_html( ucfirst( (string) $attr->attribute_type ) ); ?></code></td>
											<td>
												<select name="wvs_global_settings[attr_display_<?php echo esc_attr( $attr->attribute_name ); ?>]">
													<option value="inherit_wc" <?php selected( $current_mode, 'inherit_wc' ); ?>><?php esc_html_e( 'Use Attribute Type (Products > Attributes)', 'woo-variation-studio' ); ?></option>
													<option value="auto_image" <?php selected( $current_mode, 'auto_image' ); ?>><?php esc_html_e( 'Auto Variation Thumbnail Image', 'woo-variation-studio' ); ?></option>
													<option value="color_circle" <?php selected( $current_mode, 'color_circle' ); ?>><?php esc_html_e( 'Color / Dual-Color Swatch', 'woo-variation-studio' ); ?></option>
													<option value="text_box" <?php selected( $current_mode, 'text_box' ); ?>><?php esc_html_e( 'Button / Label Box', 'woo-variation-studio' ); ?></option>
													<option value="radio" <?php selected( $current_mode, 'radio' ); ?>><?php esc_html_e( 'Radio Button List', 'woo-variation-studio' ); ?></option>
													<option value="dropdown" <?php selected( $current_mode, 'dropdown' ); ?>><?php esc_html_e( 'Default WooCommerce Dropdown', 'woo-variation-studio' ); ?></option>
												</select>
											</td>
											<td>
												<a href="<?php echo esc_url( $terms_url ); ?>" class="button button-small"><?php esc_html_e( 'Configure Colors / Images', 'woo-variation-studio' ); ?></a>
											</td>
										</tr>
										<?php
									endforeach;
								else :
									?>
									<tr><td colspan="4"><?php esc_html_e( 'No global WooCommerce attributes found. Create attributes under Products > Attributes.', 'woo-variation-studio' ); ?></td></tr>
								<?php endif; ?>
							</tbody>
						</table>

					<?php elseif ( 'stock_gallery' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Smart Stock & Gallery Autopilot', 'woo-variation-studio' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Automatically promote the highest-stock variation or colorway to the Parent Main Image, remove out-of-stock colors from the Parent Gallery, and copy galleries across sizes.', 'woo-variation-studio' ); ?>
						</p>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Dynamic Highest-Stock Main Image', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[auto_stock_winner]" value="yes" <?php checked( $settings['auto_stock_winner'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Automatically set the Parent Main Image and Default Selected Variation to the in-stock variation/colorway with the highest stock quantity.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Stock Winner Calculation Method', 'woo-variation-studio' ); ?></th>
								<td>
									<select name="wvs_global_settings[stock_calc_mode]">
										<option value="grouped_color" <?php selected( $settings['stock_calc_mode'] ?? 'grouped_color', 'grouped_color' ); ?>><?php esc_html_e( 'Best-Stocked Colorway (Sum stock across all sizes of each color)', 'woo-variation-studio' ); ?></option>
										<option value="single_sku" <?php selected( $settings['stock_calc_mode'] ?? 'grouped_color', 'single_sku' ); ?>><?php esc_html_e( 'Single Highest-Stock Variation (Compare individual variations directly)', 'woo-variation-studio' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-Copy Variation Galleries Across Sizes', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[auto_copy_galleries]" value="yes" <?php checked( $settings['auto_copy_galleries'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'When you upload a main image or gallery images to one size of a color, automatically copy them to all other sizes of that same color.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Parent Gallery Build Mode', 'woo-variation-studio' ); ?></th>
								<td>
									<select name="wvs_global_settings[parent_gallery_mode]">
										<option value="winner_only" <?php selected( $settings['parent_gallery_mode'] ?? 'winner_only', 'winner_only' ); ?>><?php esc_html_e( 'Winning Variation Gallery Angles Only (Recommended for Strict Isolation)', 'woo-variation-studio' ); ?></option>
										<option value="winner_plus_instock" <?php selected( $settings['parent_gallery_mode'] ?? 'winner_only', 'winner_plus_instock' ); ?>><?php esc_html_e( 'Winning Variation Angles + Main Image of Other In-Stock Colors', 'woo-variation-studio' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Out-of-stock variations are automatically excluded so shoppers never see unavailable colors in the gallery.', 'woo-variation-studio' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Delivery Switcher Block', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[enable_delivery_block]" value="yes" <?php checked( $settings['enable_delivery_block'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Display the Standard Delivery vs. In-Store Pickup block on Single Product pages.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>
						</table>

						<div style="margin-top:20px; padding:16px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px;">
							<h3 style="margin-top:0;"><?php esc_html_e( '1-Click Storewide Stock & Gallery Sync', 'woo-variation-studio' ); ?></h3>
							<p class="description" style="margin-bottom:12px;">
								<?php esc_html_e( 'Recalculate highest-stock winners, update Parent Main Images, and clean out-of-stock gallery photos across all published variable products right now.', 'woo-variation-studio' ); ?>
							</p>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wvs_sync_all_products' ), 'wvs_sync_all_nonce' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Sync All Variable Products Now', 'woo-variation-studio' ); ?>
							</a>
						</div>

					<?php elseif ( 'developer' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'System Status & Google ProductGroup Schema', 'woo-variation-studio' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Google ProductGroup JSON-LD Schema', 'woo-variation-studio' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="wvs_global_settings[enable_product_group_schema]" value="yes" <?php checked( $settings['enable_product_group_schema'] ?? 'yes', 'yes' ); ?> />
										<?php esc_html_e( 'Output structured ProductGroup & hasVariant JSON-LD schema for Google Merchant Center and rich search results.', 'woo-variation-studio' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Plugin & Database Version', 'woo-variation-studio' ); ?></th>
								<td>
									<code>v<?php echo esc_html( WVS_VERSION ); ?></code> (DB: <code><?php echo esc_html( (string) get_option( 'wvs_db_version', '2.5.1' ) ); ?></code>)
								</td>
							</tr>
						</table>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Save Studio Settings', 'woo-variation-studio' ) ); ?>
			</form>
		</div>
		<?php
	}
}
