<?php
namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Real-time order sync to BizGrowHub dashboard.
 * Fires on new orders, status changes, and order updates.
 */
class Order_Sync {

    private $api_base;

    public function __construct() {
        $this->api_base = BIZGROWHUB_API_BASE_URL;

        // New order created (works with both classic & block checkout)
        add_action( 'woocommerce_new_order',           [ $this, 'sync_order' ], 20, 1 );

        // Order status changed
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 20, 4 );

        // Order updated (meta, items, etc.)
        add_action( 'woocommerce_update_order',         [ $this, 'sync_order' ], 20, 1 );

        // Payment complete
        add_action( 'woocommerce_payment_complete',     [ $this, 'sync_order' ], 20, 1 );
    }

    /**
     * On status change — sync the order
     */
    public function on_status_changed( $order_id, $old_status, $new_status, $order ) {
        $this->sync_order( $order_id );
    }

    /**
     * Sync a single order to the dashboard
     */
    public function sync_order( $order_id ) {
        // Prevent recursion from update hooks
        static $syncing = [];
        if ( isset( $syncing[ $order_id ] ) ) return;
        $syncing[ $order_id ] = true;

        $license_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY );
        if ( empty( $license_key ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $data = $this->format_order( $order );

        // Non-blocking: use wp_remote_post with short timeout
        $response = wp_remote_post( $this->api_base . '/orders/webhook', [
            'timeout'   => 10,
            'blocking'  => false, // Fire and forget — don't slow down checkout
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( [
                'license_key' => $license_key,
                'domain'      => home_url(),
                'orders'      => [ $data ],
            ] ),
        ] );

        unset( $syncing[ $order_id ] );
    }

    /**
     * Format WC_Order into the sync payload
     */
    private function format_order( $order ) {
        // Line items
        $line_items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $line_items[] = [
                'name'         => $item->get_name(),
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'sku'          => $product ? $product->get_sku() : '',
                'price'        => $item->get_total() / max( $item->get_quantity(), 1 ),
            ];
        }

        // Shipping lines
        $shipping_lines = [];
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            $shipping_lines[] = [
                'method_id'    => $item->get_method_id(),
                'method_title' => $item->get_method_title(),
                'total'        => $item->get_total(),
            ];
        }

        // Fee lines
        $fee_lines = [];
        foreach ( $order->get_items( 'fee' ) as $item ) {
            $fee_lines[] = [
                'name'  => $item->get_name(),
                'total' => $item->get_total(),
            ];
        }

        // Coupon lines
        $coupon_lines = [];
        foreach ( $order->get_items( 'coupon' ) as $item ) {
            $coupon_lines[] = [
                'code'     => $item->get_code(),
                'discount' => $item->get_discount(),
            ];
        }

        return [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'currency'             => $order->get_currency(),
            'total'                => $order->get_total(),
            'subtotal'             => $order->get_subtotal(),
            'discount_total'       => $order->get_discount_total(),
            'shipping_total'       => $order->get_shipping_total(),
            'total_tax'            => $order->get_total_tax(),
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id'       => $order->get_transaction_id(),
            'customer_id'          => $order->get_customer_id(),
            'customer_note'        => $order->get_customer_note(),
            'customer_ip_address'  => $order->get_customer_ip_address(),
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'phone'      => $order->get_shipping_phone(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ],
            'line_items'      => $line_items,
            'shipping_lines'  => $shipping_lines,
            'fee_lines'       => $fee_lines,
            'coupon_lines'    => $coupon_lines,
            'date_created'    => $order->get_date_created()   ? $order->get_date_created()->format( 'c' ) : null,
            'date_modified'   => $order->get_date_modified()  ? $order->get_date_modified()->format( 'c' ) : null,
            'date_completed'  => $order->get_date_completed() ? $order->get_date_completed()->format( 'c' ) : null,
            'date_paid'       => $order->get_date_paid()      ? $order->get_date_paid()->format( 'c' ) : null,
        ];
    }
}
