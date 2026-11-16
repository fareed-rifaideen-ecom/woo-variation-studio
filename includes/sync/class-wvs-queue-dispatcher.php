<?php
/**
 * Shutdown Queue Dispatcher & Action Scheduler Batcher
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Queue_Dispatcher {

    /**
     * Array of parent IDs queued for sync during current request.
     *
     * @var array<int, bool>
     */
    private static $queued_parents = array();

    /**
     * Initialize hooks.
     */
    public function __construct() {
        add_action( 'wvs_queue_parent_sync', array( $this, 'process_parent_sync' ), 10, 1 );
        add_action( 'shutdown', array( $this, 'dispatch_shutdown_queue' ) );
    }

    /**
     * Queue a parent product ID for background sync.
     *
     * @param int $parent_id Parent product ID.
     */
    public static function queue_parent( $parent_id ) {
        $parent_id = absint( $parent_id );
        if ( $parent_id > 0 ) {
            self::$queued_parents[ $parent_id ] = true;
        }
    }

    /**
     * Process queued parents during the PHP shutdown hook or Action Scheduler.
     */
    public function dispatch_shutdown_queue() {
        if ( empty( self::$queued_parents ) ) {
            return;
        }

        $parent_ids = array_keys( self::$queued_parents );
        
        // If bulk update exceeds 5 parent products, offload to Action Scheduler if available.
        if ( count( $parent_ids ) > 5 && function_exists( 'as_enqueue_async_action' ) ) {
            foreach ( $parent_ids as $pid ) {
                as_enqueue_async_action( 'wvs_queue_parent_sync', array( 'parent_id' => $pid ), 'woo-variation-studio' );
            }
        } else {
            foreach ( $parent_ids as $pid ) {
                self::process_parent_sync( $pid );
            }
        }

        self::$queued_parents = array();
    }

    /**
     * Run synchronization for a given parent ID.
     *
     * @param int $parent_id Parent product ID.
     */
    public function process_parent_sync( $parent_id ) {
        $parent_id = absint( $parent_id );
        if ( $parent_id > 0 && class_exists( 'WVS_Stock_Calculator' ) ) {
            WVS_Stock_Calculator::sync_parent_stock_and_images( $parent_id );
        }
    }
}
