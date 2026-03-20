<?php
/**
 * Incomplete Checkout Capture
 * Captures WooCommerce checkout form data before order completion
 * and syncs it to the Insight Hub dashboard.
 *
 * Flow:
 *  1. JS loads on checkout page → debounces field changes → AJAX → handle_capture()
 *  2. handle_capture() collects billing fields + cart → send_to_api('autosave')
 *  3. Webhook at /api/incomplete-checkout/webhook receives & stores the data
 *  4. When order completes → send_to_api('converted') → dashboard marks row converted
 */

namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Incomplete_Checkout {

    public function __construct() {
        error_log( 'bizgrowhub: Incomplete_Checkout constructor called' );

        // Require license to be active
        $license_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
        if ( empty( $license_key ) ) {
            error_log( 'bizgrowhub: Incomplete_Checkout — no license key, skipping' );
            return;
        }

        // Only hook if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log( 'bizgrowhub: Incomplete_Checkout — WooCommerce not active, skipping' );
            return;
        }

        error_log( 'bizgrowhub: Incomplete_Checkout — hooks registered' );

        add_action( 'wp_enqueue_scripts',          [ $this, 'enqueue_checkout_script' ] );
        add_action( 'wp_ajax_ih_capture_checkout',        [ $this, 'handle_capture' ] );
        add_action( 'wp_ajax_nopriv_ih_capture_checkout', [ $this, 'handle_capture' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_order_completed' ], 10, 3 );
        add_action( 'woocommerce_order_status_changed',     [ $this, 'handle_status_change' ],    10, 4 );
    }

    /* ──────────────────────────────────────────────
       Enqueue JS on checkout page only
    ────────────────────────────────────────────── */
    public function enqueue_checkout_script() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

        $js_path = BIZGROWHUB_PLUGIN_DIR . 'assets/js/incomplete-checkout.js';
        if ( ! file_exists( $js_path ) ) return;

        wp_enqueue_script(
            'ih-incomplete-checkout',
            BIZGROWHUB_PLUGIN_URL . 'assets/js/incomplete-checkout.js',
            [ 'jquery' ],
            filemtime( $js_path ),
            true
        );

        wp_localize_script( 'ih-incomplete-checkout', 'ihCheckout', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'ih_checkout_nonce' ),
            'sessionId' => $this->get_session_id(),
        ] );
    }

    /* ──────────────────────────────────────────────
       AJAX: Capture billing fields + cart
    ────────────────────────────────────────────── */
    public function handle_capture() {
        error_log( 'bizgrowhub: ih_capture_checkout AJAX fired' );
        check_ajax_referer( 'ih_checkout_nonce', 'nonce' );

        $phone = sanitize_text_field( $_POST['billing_phone'] ?? '' );
        $email = sanitize_email( $_POST['billing_email'] ?? '' );

        error_log( 'bizgrowhub: capture — phone=' . $phone . ' email=' . $email );

        if ( empty( $phone ) && empty( $email ) ) {
            wp_send_json_error( 'No contact info' );
            return;
        }

        $checkout = [
            'billing_first_name' => sanitize_text_field( $_POST['billing_first_name'] ?? '' ),
            'billing_last_name'  => sanitize_text_field( $_POST['billing_last_name'] ?? '' ),
            'billing_phone'      => $phone,
            'billing_email'      => $email,
            'billing_address'    => sanitize_text_field(
                trim( ( $_POST['billing_address_1'] ?? '' ) . ' ' . ( $_POST['billing_address_2'] ?? '' ) )
            ),
            'billing_city'       => sanitize_text_field( $_POST['billing_city'] ?? '' ),
            'ip_address'         => class_exists( 'WC_Geolocation' )
                ? \WC_Geolocation::get_ip_address()
                : ( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'session_id'         => sanitize_text_field( $_POST['session_id'] ?? '' ),
            'cart_items'         => $this->get_cart_items(),
            'cart_total'         => WC()->cart ? WC()->cart->get_total( 'edit' ) : 0,
            'custom_fields'      => $this->get_custom_fields(),
        ];

        $this->send_to_api( 'autosave', $checkout );

        wp_send_json_success( [ 'captured' => true ] );
    }

    /* ──────────────────────────────────────────────
       Order completed → mark as converted
    ────────────────────────────────────────────── */
    public function handle_order_completed( $order_id, $posted_data, $order ) {
        if ( ! $order ) return;
        $this->send_to_api( 'converted', null, [
            'wc_order_id'   => $order_id,
            'billing_phone' => $order->get_billing_phone(),
            'billing_email' => $order->get_billing_email(),
        ] );
    }

    public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
        $convert_statuses = [ 'processing', 'completed', 'on-hold' ];
        $ignore_from      = [ 'processing', 'completed', 'on-hold', 'shipped' ];

        if ( in_array( $new_status, $convert_statuses, true ) && ! in_array( $old_status, $ignore_from, true ) ) {
            $this->send_to_api( 'converted', null, [
                'wc_order_id'   => $order_id,
                'billing_phone' => $order->get_billing_phone(),
                'billing_email' => $order->get_billing_email(),
            ] );
        }
    }

    /* ──────────────────────────────────────────────
       Helpers
    ────────────────────────────────────────────── */
    private function get_cart_items() {
        $items = [];
        if ( ! WC()->cart ) return $items;

        foreach ( WC()->cart->get_cart() as $item ) {
            $product = $item['data'];
            $items[] = [
                'product_id' => $item['product_id'],
                'name'       => $product ? $product->get_name() : '',
                'quantity'   => $item['quantity'],
                'total'      => $item['line_total'],
                'sku'        => $product ? $product->get_sku() : '',
            ];
        }

        return $items;
    }

    private function get_custom_fields() {
        $standard = [
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country',
            'billing_phone', 'billing_email',
            'action', 'nonce', 'session_id',
        ];
        $custom = [];
        foreach ( $_POST as $key => $value ) {
            if ( strpos( $key, 'billing_' ) === 0 && ! in_array( $key, $standard, true ) ) {
                $custom[ $key ] = sanitize_text_field( $value );
            }
        }
        return $custom;
    }

    private function get_session_id() {
        if ( isset( $_COOKIE['ih_checkout_session'] ) ) {
            return sanitize_text_field( $_COOKIE['ih_checkout_session'] );
        }
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'sess_', true );
    }

    /**
     * Send data to dashboard API (non-blocking)
     */
    private function send_to_api( $action, $checkout = null, $extra = [] ) {
        $license_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
        $api_base    = defined( 'BIZGROWHUB_API_BASE_URL' ) ? BIZGROWHUB_API_BASE_URL : get_option( 'BIZGROWHUB_api_url', '' );

        error_log( 'bizgrowhub: send_to_api action=' . $action . ' api_base=' . $api_base . ' has_key=' . ( ! empty( $license_key ) ? 'yes' : 'no' ) );

        if ( empty( $license_key ) || empty( $api_base ) ) {
            error_log( 'bizgrowhub: send_to_api — missing license_key or api_base, aborting' );
            return;
        }

        $body = array_merge( [
            'license_key' => $license_key,
            'domain'      => wp_parse_url( home_url(), PHP_URL_HOST ),
            'action'      => $action,
        ], $extra );

        if ( $checkout ) {
            $body['checkout'] = $checkout;
        }

        $url      = rtrim( $api_base, '/' ) . '/incomplete-checkout/webhook';
        $response = wp_remote_post(
            $url,
            [
                'body'      => wp_json_encode( $body ),
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'timeout'   => 8,
                'blocking'  => true,  // blocking for debug
                'sslverify' => false,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'bizgrowhub: webhook error — ' . $response->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $rbody = wp_remote_retrieve_body( $response );
            error_log( 'bizgrowhub: webhook response ' . $code . ' — ' . $rbody );
        }
    }
}
