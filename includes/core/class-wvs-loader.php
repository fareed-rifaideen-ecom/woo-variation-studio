<?php
/**
 * Core Service Loader and Safe Module Initializer
 *
 * File Path: includes/core/class-wvs-loader.php
 *
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVS_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var WVS_Loader|null
	 */
	private static $instance = null;

	/**
	 * Collected boot errors for admin display.
	 *
	 * @var array<int, string>
	 */
	private static $boot_errors = array();

	/**
	 * Initialize the loader (supports WVS_Loader::init()).
	 *
	 * @return WVS_Loader
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Alias for singleton access.
	 *
	 * @return WVS_Loader
	 */
	public static function instance() {
		return self::init();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->init_modules();

		if ( ! empty( self::$boot_errors ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_boot_errors' ) );
		}
	}

	/**
	 * Safely load all module files in dependency order.
	 */
	private function load_dependencies() {
		$includes_dir = WVS_PLUGIN_DIR . 'includes/';

		$files = array(
			'rules/class-wvs-inheritance-engine.php',
			'core/class-wvs-cache-manager.php',
			'sync/class-wvs-stock-calculator.php',
			'sync/class-wvs-queue-dispatcher.php',
			'admin/class-wvs-admin-menu.php',
			'admin/class-wvs-attribute-fields.php',
			'admin/class-wvs-native-wc-fields.php',
			'admin/class-wvs-taxonomy-fields.php',
			'frontend/class-wvs-pdp-renderer.php',
			'frontend/class-wvs-catalog-cards.php',
			'frontend/class-wvs-cart-thumbnails.php',
			'seo/class-wvs-schema-renderer.php',
		);

		foreach ( $files as $relative_path ) {
			$full_path = $includes_dir . $relative_path;
			if ( file_exists( $full_path ) ) {
				try {
					require_once $full_path;
				} catch ( \Throwable $e ) {
					self::$boot_errors[] = sprintf(
						'File <code>%s</code> error: %s (Line %d)',
						esc_html( $relative_path ),
						esc_html( $e->getMessage() ),
						intval( $e->getLine() )
					);
				}
			}
		}
	}

	/**
	 * Safely initialize loaded classes regardless of whether they use ::init(), ::instance(), or __construct().
	 */
	private function init_modules() {
		$classes = array(
			'WVS_Inheritance_Engine',
			'WVS_Cache_Manager',
			'WVS_Stock_Calculator',
			'WVS_Queue_Dispatcher',
			'WVS_Admin_Menu',
			'WVS_Attribute_Fields',
			'WVS_Native_WC_Fields',
			'WVS_Taxonomy_Fields',
			'WVS_PDP_Renderer',
			'WVS_Catalog_Cards',
			'WVS_Cart_Thumbnails',
			'WVS_Schema_Renderer',
		);

		foreach ( $classes as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			try {
				if ( method_exists( $class_name, 'init' ) ) {
					$ref_method = new \ReflectionMethod( $class_name, 'init' );
					if ( $ref_method->isStatic() && $ref_method->isPublic() ) {
						$class_name::init();
						continue;
					}
				}

				if ( method_exists( $class_name, 'instance' ) ) {
					$ref_method = new \ReflectionMethod( $class_name, 'instance' );
					if ( $ref_method->isStatic() && $ref_method->isPublic() ) {
						$class_name::instance();
						continue;
					}
				}

				$reflection  = new \ReflectionClass( $class_name );
				$constructor = $reflection->getConstructor();
				if ( null === $constructor || $constructor->isPublic() ) {
					new $class_name();
				}
			} catch ( \Throwable $e ) {
				self::$boot_errors[] = sprintf(
					'Module <code>%s</code> error: %s in <code>%s</code> (Line %d)',
					esc_html( $class_name ),
					esc_html( $e->getMessage() ),
					esc_html( basename( $e->getFile() ) ),
					intval( $e->getLine() )
				);
			}
		}
	}

	/**
	 * Render any module error notices in WP Admin instead of crashing the site.
	 */
	public static function render_boot_errors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( self::$boot_errors as $error_msg ) {
			echo '<div class="notice notice-error"><p><strong>Woo Variation Studio Diagnostic:</strong> ' . wp_kses_post( $error_msg ) . '</p></div>';
		}
	}
}
