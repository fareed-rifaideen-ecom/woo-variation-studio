<?php
/**
 * Debounced Queue Dispatcher & Batch Sync
 * 
 * @package WooVariationStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WVS_Queue_Dispatcher {

    private static $pending_parents = [];

    public function __construct() {
        add_action( 'shutdown', [ $this, 'process_shutdown_queue' ] );
    }

    public static function queue_parent( $parent_id ) {
        if ( $parent_id ) {
            self::$pending_parents[ absint( $parent_id ) ] = true;
        }
    }

    public function process_shutdown_queue() {
        if ( empty( self::$pending_parents ) ) {
            return;
        }

        foreach ( array_keys( self::$pending_parents ) as $parent_id ) {
            if ( class_exists( 'WVS_Stock_Calculator' ) ) {
                $calculator = new WVS_Stock_Calculator();
                $calculator->recalculate_parent_stock_and_images( $parent_id );
            }
        }

        self::$pending_parents = [];
    }
}
```
---

### Step-by-Step Guidance for Phase 3

1. **Create Stock Calculator File:**
   - In your GitHub repository, create or update: `includes/sync/class-wvs-stock-calculator.php`
   - Copy the code from the first block above and commit changes.

2. **Create Queue Dispatcher File:**
   - In your GitHub repository, create or update: `includes/sync/class-wvs-queue-dispatcher.php`
   - Copy the code from the second block above and commit changes.

Once committed, let me know and we will proceed to **Phase 4 (Frontend Swatches, Two-Way Matrix & Gallery Isolation)**!
