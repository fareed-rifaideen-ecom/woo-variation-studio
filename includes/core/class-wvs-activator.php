<?php
/**
 * Fills Activation & Deactivation Routines
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Activator {

    public static function activate() {
        self::create_custom_tables();
        self::seed_default_options();
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    private static function create_custom_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_rules    = $wpdb->prefix . 'wvs_rules_presets';
        $table_audit_log = $wpdb->prefix . 'wvs_sync_audit_log';

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

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
    }

    private static function seed_default_options() {
        $existing = get_option( 'wvs_global_settings', null );
        if ( is_null( $existing ) ) {
            $defaults = [
                'card_style'              => 'style_a',
                'cross_attr_mode'         => 'smart_switch',
                'strict_gallery_isolation' => 'yes',
                'auto_stock_winner'       => 'yes',
                'auto_copy_galleries'     => 'yes',
                'enable_delivery_block'   => 'yes',
            ];
            update_option( 'wvs_global_settings', $defaults );
        }
    }
}
