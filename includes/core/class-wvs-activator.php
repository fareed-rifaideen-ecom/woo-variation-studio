<?php
/**
 * Plugin Activation and Deactivation Handler
 *
 * File Path: includes/core/class-wvs-activator.php
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVS_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		try {
			self::create_custom_tables();
			self::seed_default_options();
		} catch ( \Throwable $e ) {
			// Prevent activation fatal error and log details if WP_DEBUG_LOG is enabled.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Woo Variation Studio Activation Notice: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		// Clean deactivation without deleting user data.
	}

	/**
	 * Create custom tables safely using dbDelta.
	 */
	private static function create_custom_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_rules     = $wpdb->prefix . 'wvs_rules_presets';
		$table_audit_log = $wpdb->prefix . 'wvs_sync_audit_log';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_rules = "CREATE TABLE $table_rules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_type varchar(32) NOT NULL DEFAULT 'preset',
			title varchar(191) NOT NULL,
			priority int(11) NOT NULL DEFAULT 10,
			status varchar(20) NOT NULL DEFAULT 'active',
			conditions_json longtext NOT NULL,
			settings_json longtext NOT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_type_status_priority (rule_type, status, priority)
		) $charset_collate;";

		$sql_audit = "CREATE TABLE $table_audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned NOT NULL,
			trigger_source varchar(50) NOT NULL,
			old_winner_var_id bigint(20) unsigned DEFAULT 0,
			new_winner_var_id bigint(20) unsigned NOT NULL,
			old_image_id bigint(20) unsigned DEFAULT 0,
			new_image_id bigint(20) unsigned NOT NULL,
			stock_snapshot int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_parent_date (parent_id, created_at)
		) $charset_collate;";

		dbDelta( $sql_rules );
		dbDelta( $sql_audit );

		update_option( 'wvs_db_version', WVS_VERSION );
	}

	/**
	 * Seed default global settings if not already present.
	 */
	private static function seed_default_options() {
		$existing = get_option( 'wvs_global_settings', false );
		if ( false === $existing || ! is_array( $existing ) ) {
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
				'enable_complete_ride'        => 'yes',
				'enable_product_group_schema' => 'yes',
				'enable_deep_link_ssr'        => 'yes',
				'attribute_display_map'       => array(),
			);
			update_option( 'wvs_global_settings', $defaults );
		}
	}
}
