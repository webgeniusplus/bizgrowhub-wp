<?php
/**
 * MarketPulse — Order Sync Queue
 *
 * Every frontend order is queued immediately.
 * A WP Cron job retries every 2 minutes until confirmed pushed.
 * Works even if internet is down — will push when connection restores.
 *
 * Queue stored in WP option `marketpulse_sync_queue`:
 *   [ { order_id, retries, last_attempt, queued_at }, ... ]
 */

defined( 'ABSPATH' ) || exit;

class MarketPulse_Sync_Queue {

    const OPTION_KEY    = 'marketpulse_sync_queue';
    const CRON_HOOK     = 'marketpulse_process_queue';
    const MAX_RETRIES   = 48;      // ~4 hours of 2-min retries then give up
    const CRON_INTERVAL = 'marketpulse_2min';

    public static function init() {
        // Register custom cron interval
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );

        // Schedule recurring cron if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }

        // Hook cron to our processor
        add_action( self::CRON_HOOK, [ __CLASS__, 'process_queue' ] );
    }

    public static function add_cron_interval( $schedules ) {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => 120,  // 2 minutes in seconds
            'display'  => 'Every 2 Minutes (MarketPulse)',
        ];
        return $schedules;
    }

    /**
     * Add an order to the sync queue.
     * Called by class-order-sync.php on every frontend order event.
     */
    public static function enqueue( int $order_id ) {
        $queue = self::get_queue();

        // Avoid duplicates — update existing entry or add new
        $found = false;
        foreach ( $queue as &$item ) {
            if ( (int) $item['order_id'] === $order_id ) {
                $item['updated_at'] = time();  // Re-trigger on status change
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            $queue[] = [
                'order_id'     => $order_id,
                'retries'      => 0,
                'queued_at'    => time(),
                'last_attempt' => 0,
                'last_error'   => '',
            ];
        }

        self::save_queue( $queue );

        // Also fire an immediate async attempt (non-blocking, best effort)
        wp_schedule_single_event( time(), self::CRON_HOOK );
        spawn_cron();
    }

    /**
     * Process the queue — push unsent orders to dashboard.
     * Called by WP Cron every 2 minutes.
     */
    public static function process_queue() {
        $queue = self::get_queue();
        if ( empty( $queue ) ) return;

        // Use plugin constants for API URL and credentials
        $api_base    = defined( 'MARKETPULSE_API_BASE_URL' ) ? MARKETPULSE_API_BASE_URL : '';
        $license_key = get_option( MARKETPULSE_OPTION_LICENSE_KEY, '' );
        $domain      = parse_url( get_site_url(), PHP_URL_HOST );

        if ( empty( $api_base ) || empty( $license_key ) ) return;

        // Build full orders webhook endpoint
        $endpoint = trailingslashit( $api_base ) . ltrim( MARKETPULSE_ENDPOINT_ORDER_WEBHOOK, '/' );

        $updated_queue = [];
        $batch_orders  = [];
        $batch_indices = [];

        // Collect up to 20 orders for batch push
        foreach ( $queue as $i => $item ) {
            if ( count( $batch_orders ) >= 20 ) break;
            if ( $item['retries'] >= self::MAX_RETRIES ) continue;  // Skip permanently failed

            $order = wc_get_order( $item['order_id'] );
            if ( ! $order || ! ( $order instanceof WC_Order ) ) continue;

            $batch_orders[]  = \MarketPulse\Order_Sync::format_order( $order );
            $batch_indices[] = $i;
        }

        if ( ! empty( $batch_orders ) ) {
            // BLOCKING request — we need to know if it succeeded
            $response = wp_remote_post(
                $endpoint,
                [
                    'method'   => 'POST',
                    'timeout'  => 15,
                    'blocking' => true,
                    'headers'  => [ 'Content-Type' => 'application/json' ],
                    'body'     => wp_json_encode([
                        'license_key' => $license_key,
                        'domain'      => $domain,
                        'orders'      => $batch_orders,
                    ]),
                ]
            );

            $success = false;
            if ( ! is_wp_error( $response ) ) {
                $code    = wp_remote_retrieve_response_code( $response );
                $body    = json_decode( wp_remote_retrieve_body( $response ), true );
                $success = ( $code === 200 && ! empty( $body['ok'] ) );
            }

            $error_msg = '';
            if ( is_wp_error( $response ) ) {
                $error_msg = $response->get_error_message();
            } elseif ( ! $success ) {
                $error_msg = wp_remote_retrieve_body( $response );
            }

            // Update queue: remove successful items, increment retries for failed
            $sent_indices = $success ? array_flip( $batch_indices ) : [];
        } else {
            $success      = false;
            $sent_indices = [];
            $error_msg    = 'No valid orders';
        }

        // Rebuild queue: keep items that haven't been sent successfully
        foreach ( $queue as $i => $item ) {
            if ( isset( $sent_indices[ $i ] ) ) {
                // Successfully pushed — remove from queue
                continue;
            }

            if ( in_array( $i, $batch_indices ) ) {
                // Was in batch but failed
                $item['retries']++;
                $item['last_attempt'] = time();
                $item['last_error']   = $error_msg ?: 'Push failed';
            }

            // Keep items with retries under max
            if ( $item['retries'] < self::MAX_RETRIES ) {
                $updated_queue[] = $item;
            }
            // else: permanently drop after MAX_RETRIES
        }

        self::save_queue( $updated_queue );
    }

    public static function get_queue(): array {
        $q = get_option( self::OPTION_KEY, [] );
        return is_array( $q ) ? $q : [];
    }

    private static function save_queue( array $queue ) {
        update_option( self::OPTION_KEY, $queue, false );  // false = don't autoload
    }

    /** Get queue status for admin display */
    public static function queue_status(): array {
        $queue = self::get_queue();
        return [
            'pending' => count( $queue ),
            'items'   => array_map( function( $item ) {
                return [
                    'order_id'     => $item['order_id'],
                    'retries'      => $item['retries'],
                    'queued_at'    => date( 'Y-m-d H:i:s', $item['queued_at'] ),
                    'last_attempt' => $item['last_attempt'] ? date( 'Y-m-d H:i:s', $item['last_attempt'] ) : 'Never',
                    'last_error'   => $item['last_error'] ?? '',
                ];
            }, $queue ),
        ];
    }

    /** Clear the queue (for admin use) */
    public static function clear_queue() {
        delete_option( self::OPTION_KEY );
    }

    /** Cleanup cron on plugin deactivate */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }
}
