<?php
namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Guard — Order fraud prevention.
 * 
 * Pattern: Follows shop-manager-bd repeat-order-block exactly.
 * Settings stored as WordPress options.
 * Server-side validation via woocommerce_checkout_process (always works).
 * AJAX pre-validation for popup (enhancement, not required).
 */
class WooCommerce_Guard {

    private $api_client;

    /* ── Option keys ─────────────────────────────────────── */
    const OPT_ENABLED        = 'BIZGROWHUB_wg_enabled';
    const OPT_CHECK_PHONE    = 'BIZGROWHUB_wg_check_phone';
    const OPT_CHECK_IP       = 'BIZGROWHUB_wg_check_ip';
    const OPT_CHECK_EMAIL    = 'BIZGROWHUB_wg_check_email';
    const OPT_TIME_HOURS     = 'BIZGROWHUB_wg_time_hours';
    const OPT_ORDER_LIMIT    = 'BIZGROWHUB_wg_order_limit';
    const OPT_ERROR_MESSAGE  = 'BIZGROWHUB_wg_error_message';
    const OPT_BLOCKED_EMAILS = 'BIZGROWHUB_wg_blocked_emails';
    const OPT_BLOCKED_IPS    = 'BIZGROWHUB_wg_blocked_ips';
    const OPT_BLOCKED_PHONES = 'BIZGROWHUB_wg_blocked_phones';

    /* ── Popup customization ───────────────────────────── */
    const OPT_POPUP_TITLE    = 'BIZGROWHUB_wg_popup_title';
    const OPT_POPUP_BG       = 'BIZGROWHUB_wg_popup_bg_color';
    const OPT_POPUP_TEXT     = 'BIZGROWHUB_wg_popup_text_color';
    const OPT_POPUP_ACCENT   = 'BIZGROWHUB_wg_popup_accent_color';
    const OPT_POPUP_BTN_RETRY = 'BIZGROWHUB_wg_popup_btn_retry';
    const OPT_POPUP_BTN_CLOSE = 'BIZGROWHUB_wg_popup_btn_close';

    /* ── Per-reason popup text ─────────────────────────── */
    const OPT_RATE_LIMIT_TITLE   = 'BIZGROWHUB_wg_rate_limit_title';
    const OPT_RATE_LIMIT_MESSAGE = 'BIZGROWHUB_wg_rate_limit_message';
    const OPT_BLACKLIST_TITLE    = 'BIZGROWHUB_wg_blacklist_title';
    const OPT_BLACKLIST_MESSAGE  = 'BIZGROWHUB_wg_blacklist_message';
    const OPT_RL_SHOW_CANCEL    = 'BIZGROWHUB_wg_rl_show_cancel';
    const OPT_RL_SHOW_RETRY     = 'BIZGROWHUB_wg_rl_show_retry';
    const OPT_BL_SHOW_CANCEL    = 'BIZGROWHUB_wg_bl_show_cancel';
    const OPT_BL_SHOW_RETRY     = 'BIZGROWHUB_wg_bl_show_retry';
    const OPT_POPUP_BRAND    = 'BIZGROWHUB_wg_popup_brand_text';
    const OPT_POPUP_HTML     = 'BIZGROWHUB_wg_popup_html';

    public function __construct() {
        error_log( 'bizgrowhub WooGuard: Constructor called' );

        // Check if woo-guard addon is installed via dashboard
        $installed = get_option( 'BIZGROWHUB_installed_addons', array() );
        if ( is_array( $installed ) && ! empty( $installed ) && ! in_array( 'woo-guard', $installed, true ) ) {
            error_log( 'bizgrowhub WooGuard: Addon not installed in dashboard, skipping' );
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log( 'bizgrowhub WooGuard: WooCommerce NOT active, skipping' );
            return;
        }

        $feature_toggle = get_option( BIZGROWHUB_OPTION_FEATURE_WC_GUARD, '1' );
        error_log( 'bizgrowhub WooGuard: Feature toggle = ' . var_export( $feature_toggle, true ) );
        if ( $feature_toggle !== '1' ) {
            error_log( 'bizgrowhub WooGuard: Feature disabled via toggle, skipping' );
            return;
        }

        $this->api_client = new API_Client();

        $enabled = self::get_settings()['enabled'];
        error_log( 'bizgrowhub WooGuard: Settings enabled = ' . var_export( $enabled, true ) );

        // Always register REST routes
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Always register checkout hook — check enabled inside the function (like reference plugin)
        add_action( 'woocommerce_checkout_process', [ $this, 'check_order_limits' ] );
        error_log( 'bizgrowhub WooGuard: Registered woocommerce_checkout_process hook' );

        // AJAX pre-validation for popup
        add_action( 'wp_ajax_woo_guard_validate', [ $this, 'ajax_validate_order' ] );
        add_action( 'wp_ajax_nopriv_woo_guard_validate', [ $this, 'ajax_validate_order' ] );

        // Enqueue popup assets on checkout
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );

        // Report new orders to dashboard
        add_action( 'woocommerce_new_order', [ $this, 'report_order' ] );

        error_log( 'bizgrowhub WooGuard: All hooks registered successfully' );
    }

    /* ================================================================
       Settings — WordPress options (like reference plugin)
       ================================================================ */

    public static function get_settings() {
        return [
            'enabled'       => (bool) get_option( self::OPT_ENABLED, false ),
            'check_phone'   => (bool) get_option( self::OPT_CHECK_PHONE, true ),
            'check_ip'      => (bool) get_option( self::OPT_CHECK_IP, true ),
            'check_email'   => (bool) get_option( self::OPT_CHECK_EMAIL, true ),
            'time_hours'    => (int) get_option( self::OPT_TIME_HOURS, 24 ),
            'order_limit'   => (int) get_option( self::OPT_ORDER_LIMIT, 1 ),
            'error_message' => get_option( self::OPT_ERROR_MESSAGE, 'আপনার অর্ডারটি করা হয়ে গিয়েছে। আমাদের প্রতিনিধি শীঘ্রই আপনাকে কল করবেন।' ),
            'blocked_emails' => get_option( self::OPT_BLOCKED_EMAILS, [] ),
            'blocked_ips'    => get_option( self::OPT_BLOCKED_IPS, [] ),
            'blocked_phones' => get_option( self::OPT_BLOCKED_PHONES, [] ),
            // Per-reason popup text
            'rate_limit_title'   => get_option( self::OPT_RATE_LIMIT_TITLE, 'Too Many Orders' ),
            'rate_limit_message' => get_option( self::OPT_RATE_LIMIT_MESSAGE, 'আপনি সর্বোচ্চ অর্ডার সীমা অতিক্রম করেছেন।' ),
            'blacklist_title'    => get_option( self::OPT_BLACKLIST_TITLE, 'Account Restricted' ),
            'blacklist_message'  => get_option( self::OPT_BLACKLIST_MESSAGE, 'এই অ্যাকাউন্ট থেকে অর্ডার করা সীমিত করা হয়েছে।' ),
            'rl_show_cancel'     => (bool) get_option( self::OPT_RL_SHOW_CANCEL, true ),
            'rl_show_retry'      => (bool) get_option( self::OPT_RL_SHOW_RETRY, true ),
            'bl_show_cancel'     => (bool) get_option( self::OPT_BL_SHOW_CANCEL, true ),
            'bl_show_retry'      => (bool) get_option( self::OPT_BL_SHOW_RETRY, false ),
            // Popup customization
            'popup_title'        => get_option( self::OPT_POPUP_TITLE, '' ),
            'popup_bg_color'     => get_option( self::OPT_POPUP_BG, '#1a1a2e' ),
            'popup_text_color'   => get_option( self::OPT_POPUP_TEXT, '#f1f5f9' ),
            'popup_accent_color' => get_option( self::OPT_POPUP_ACCENT, '#8b5cf6' ),
            'popup_btn_retry'    => get_option( self::OPT_POPUP_BTN_RETRY, '' ),
            'popup_btn_close'    => get_option( self::OPT_POPUP_BTN_CLOSE, '' ),
            'popup_brand_text'   => get_option( self::OPT_POPUP_BRAND, '' ),
            'popup_html'         => get_option( self::OPT_POPUP_HTML, '' ),
        ];
    }

    public static function save_settings( $settings ) {
        if ( isset( $settings['enabled'] ) )        update_option( self::OPT_ENABLED, (bool) $settings['enabled'] );
        if ( isset( $settings['check_phone'] ) )    update_option( self::OPT_CHECK_PHONE, (bool) $settings['check_phone'] );
        if ( isset( $settings['check_ip'] ) )       update_option( self::OPT_CHECK_IP, (bool) $settings['check_ip'] );
        if ( isset( $settings['check_email'] ) )    update_option( self::OPT_CHECK_EMAIL, (bool) $settings['check_email'] );
        if ( isset( $settings['time_hours'] ) )     update_option( self::OPT_TIME_HOURS, (int) $settings['time_hours'] );
        if ( isset( $settings['order_limit'] ) )    update_option( self::OPT_ORDER_LIMIT, (int) $settings['order_limit'] );
        if ( isset( $settings['error_message'] ) )  update_option( self::OPT_ERROR_MESSAGE, sanitize_textarea_field( $settings['error_message'] ) );
        if ( isset( $settings['blocked_emails'] ) ) update_option( self::OPT_BLOCKED_EMAILS, (array) $settings['blocked_emails'] );
        if ( isset( $settings['blocked_ips'] ) )    update_option( self::OPT_BLOCKED_IPS, (array) $settings['blocked_ips'] );
        if ( isset( $settings['blocked_phones'] ) ) update_option( self::OPT_BLOCKED_PHONES, (array) $settings['blocked_phones'] );
        // Per-reason popup text
        if ( isset( $settings['rate_limit_title'] ) )   update_option( self::OPT_RATE_LIMIT_TITLE, sanitize_text_field( $settings['rate_limit_title'] ) );
        if ( isset( $settings['rate_limit_message'] ) ) update_option( self::OPT_RATE_LIMIT_MESSAGE, wp_kses_post( $settings['rate_limit_message'] ) );
        if ( isset( $settings['blacklist_title'] ) )    update_option( self::OPT_BLACKLIST_TITLE, sanitize_text_field( $settings['blacklist_title'] ) );
        if ( isset( $settings['blacklist_message'] ) )  update_option( self::OPT_BLACKLIST_MESSAGE, wp_kses_post( $settings['blacklist_message'] ) );
        if ( isset( $settings['rate_limit_show_cancel'] ) ) update_option( self::OPT_RL_SHOW_CANCEL, (bool) $settings['rate_limit_show_cancel'] ? '1' : '' );
        if ( isset( $settings['rate_limit_show_retry'] ) )  update_option( self::OPT_RL_SHOW_RETRY, (bool) $settings['rate_limit_show_retry'] ? '1' : '' );
        if ( isset( $settings['blacklist_show_cancel'] ) )  update_option( self::OPT_BL_SHOW_CANCEL, (bool) $settings['blacklist_show_cancel'] ? '1' : '' );
        if ( isset( $settings['blacklist_show_retry'] ) )   update_option( self::OPT_BL_SHOW_RETRY, (bool) $settings['blacklist_show_retry'] ? '1' : '' );
        // Popup customization
        if ( isset( $settings['popup_title'] ) )        update_option( self::OPT_POPUP_TITLE, sanitize_text_field( $settings['popup_title'] ) );
        if ( isset( $settings['popup_bg_color'] ) )     update_option( self::OPT_POPUP_BG, sanitize_hex_color( $settings['popup_bg_color'] ) ?: '#1a1a2e' );
        if ( isset( $settings['popup_text_color'] ) )   update_option( self::OPT_POPUP_TEXT, sanitize_hex_color( $settings['popup_text_color'] ) ?: '#f1f5f9' );
        if ( isset( $settings['popup_accent_color'] ) ) update_option( self::OPT_POPUP_ACCENT, sanitize_hex_color( $settings['popup_accent_color'] ) ?: '#8b5cf6' );
        if ( isset( $settings['popup_btn_retry'] ) )    update_option( self::OPT_POPUP_BTN_RETRY, sanitize_text_field( $settings['popup_btn_retry'] ) );
        if ( isset( $settings['popup_btn_close'] ) )    update_option( self::OPT_POPUP_BTN_CLOSE, sanitize_text_field( $settings['popup_btn_close'] ) );
        if ( isset( $settings['popup_brand_text'] ) )   update_option( self::OPT_POPUP_BRAND, sanitize_text_field( $settings['popup_brand_text'] ) );
        // Store popup HTML as-is (generated by trusted dashboard, not user input)
        if ( isset( $settings['popup_html'] ) )         update_option( self::OPT_POPUP_HTML, $settings['popup_html'] );
        return true;
    }

    /* ================================================================
       Core: woocommerce_checkout_process — EXACT reference pattern
       ================================================================ */

    /**
     * Main checkout validation — mirrors smartflow_bd_check_repeat_orders() exactly.
     */
    public function check_order_limits() {
        error_log( 'bizgrowhub WooGuard: checkout_process hook triggered' );

        $settings = self::get_settings();
        error_log( 'bizgrowhub WooGuard: Settings = ' . print_r( $settings, true ) );

        if ( ! $settings['enabled'] ) {
            error_log( 'bizgrowhub WooGuard: Feature disabled, skipping' );
            return;
        }

        $phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( $_POST['billing_phone'] ) : '';
        $email = isset( $_POST['billing_email'] ) ? sanitize_email( $_POST['billing_email'] ) : '';
        $ip    = \WC_Geolocation::get_ip_address();

        error_log( "bizgrowhub WooGuard: phone=$phone, email=$email, ip=$ip" );

        $rl_msg = $settings['rate_limit_message'];
        $bl_msg = $settings['blacklist_message'];
        $time_hours  = $settings['time_hours'];
        $order_limit = $settings['order_limit'];

        // ── Chain: 1) Order Limit → 2) Blacklist ──

        // 1. Order limit checks (first)
        if ( $settings['check_phone'] && ! empty( $phone ) ) {
            if ( $this->check_order_limit( 'billing_phone', $phone, $time_hours, $order_limit ) ) {
                error_log( 'bizgrowhub WooGuard: Blocking — phone limit exceeded' );
                $this->report_block_to_api( 'rate_limit', 'phone', $email, $phone, $ip );
                wc_add_notice( $rl_msg, 'error' );
                return;
            }
        }
        if ( $settings['check_email'] && ! empty( $email ) ) {
            if ( $this->check_order_limit( 'billing_email', $email, $time_hours, $order_limit ) ) {
                error_log( 'bizgrowhub WooGuard: Blocking — email limit exceeded' );
                $this->report_block_to_api( 'rate_limit', 'email', $email, $phone, $ip );
                wc_add_notice( $rl_msg, 'error' );
                return;
            }
        }
        if ( $settings['check_ip'] && ! empty( $ip ) ) {
            if ( $this->check_order_limit( 'customer_ip_address', $ip, $time_hours, $order_limit ) ) {
                error_log( 'bizgrowhub WooGuard: Blocking — IP limit exceeded' );
                $this->report_block_to_api( 'rate_limit', 'ip', $email, $phone, $ip );
                wc_add_notice( $rl_msg, 'error' );
                return;
            }
        }

        // 2. Blacklist checks (second)
        $blocked_emails = (array) $settings['blocked_emails'];
        $blocked_ips    = (array) $settings['blocked_ips'];
        $blocked_phones = (array) $settings['blocked_phones'];

        if ( $email && in_array( strtolower( $email ), array_map( 'strtolower', $blocked_emails ), true ) ) {
            error_log( 'bizgrowhub WooGuard: Blocked — email in blacklist' );
            $this->report_block_to_api( 'blacklist', 'email', $email, $phone, $ip );
            wc_add_notice( $bl_msg, 'error' );
            return;
        }
        if ( $ip && in_array( $ip, $blocked_ips, true ) ) {
            error_log( 'bizgrowhub WooGuard: Blocked — IP in blacklist' );
            $this->report_block_to_api( 'blacklist', 'ip', $email, $phone, $ip );
            wc_add_notice( $bl_msg, 'error' );
            return;
        }
        if ( $phone && in_array( $phone, $blocked_phones, true ) ) {
            error_log( 'bizgrowhub WooGuard: Blocked — phone in blacklist' );
            $this->report_block_to_api( 'blacklist', 'phone', $email, $phone, $ip );
            wc_add_notice( $bl_msg, 'error' );
            return;
        }

        error_log( 'bizgrowhub WooGuard: All checks passed, allowing order' );
    }

    /**
     * Check if order limit exceeded — EXACT copy of smartflow_bd_check_order_limit().
     */
    private function check_order_limit( $field_type, $field_value, $time_hours, $order_limit ) {
        error_log( sprintf(
            'bizgrowhub WooGuard: check_order_limit %s=%s, hours=%d, limit=%d',
            $field_type, $field_value, $time_hours, $order_limit
        ) );

        $excluded_statuses = [ 'wc-pending', 'wc-auto-draft', 'wc-trash' ];
        $allowed_statuses  = array_diff( array_keys( wc_get_order_statuses() ), $excluded_statuses );

        $time_from = time() - ( $time_hours * 3600 );

        error_log( 'bizgrowhub WooGuard: Time from = ' . gmdate( 'Y-m-d H:i:s', $time_from ) );

        $args = [
            'date_created' => '>=' . $time_from,
            'status'       => $allowed_statuses,
            $field_type    => $field_value,
            'limit'        => -1,
            'paginate'     => true,
        ];

        error_log( 'bizgrowhub WooGuard: Query args = ' . print_r( $args, true ) );

        $orders = wc_get_orders( $args );
        error_log( 'bizgrowhub WooGuard: Found ' . $orders->total . ' orders' );

        $should_block = $orders->total >= $order_limit;
        error_log( 'bizgrowhub WooGuard: Block? ' . ( $should_block ? 'YES' : 'NO' ) . " (total={$orders->total}, limit=$order_limit)" );

        return $should_block;
    }

    /* ================================================================
       AJAX Pre-Validation (popup enhancement)
       ================================================================ */

    public function ajax_validate_order() {
        check_ajax_referer( 'woo_guard_validate', 'nonce' );

        $settings = self::get_settings();

        if ( ! $settings['enabled'] ) {
            wp_send_json_success( [ 'allowed' => true ] );
        }

        $phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
        $ip    = \WC_Geolocation::get_ip_address();

        $time_hours  = $settings['time_hours'];
        $order_limit = $settings['order_limit'];
        $error_msg   = $settings['error_message'];

        // Blocklist checks
        $blocked_emails = (array) $settings['blocked_emails'];
        $blocked_ips    = (array) $settings['blocked_ips'];
        $blocked_phones = (array) $settings['blocked_phones'];

        // Dynamic per-reason config
        $rl_title = $settings['rate_limit_title'];
        $rl_msg   = $settings['rate_limit_message'];
        $bl_title = $settings['blacklist_title'];
        $bl_msg   = $settings['blacklist_message'];

        // Button config per reason type — only include label if checkbox is on
        $btn_close = $settings['popup_btn_close'] ?: 'Cancel';
        $btn_retry = $settings['popup_btn_retry'] ?: 'Try Again';

        $rl_response = [
            'blocked' => true, 'reason_type' => 'rate_limit',
            'title' => $rl_title, 'message' => $rl_msg,
        ];
        if ( $settings['rl_show_cancel'] ) { $rl_response['btn1_label'] = $btn_close; $rl_response['btn1_action'] = 'close'; }
        if ( $settings['rl_show_retry'] )  { $rl_response['btn2_label'] = $btn_retry; $rl_response['btn2_action'] = 'retry'; }

        $bl_response = [
            'blocked' => true, 'reason_type' => 'blacklist',
            'title' => $bl_title, 'message' => $bl_msg,
        ];
        if ( $settings['bl_show_cancel'] ) { $bl_response['btn1_label'] = $btn_close; $bl_response['btn1_action'] = 'close'; }
        if ( $settings['bl_show_retry'] )  { $bl_response['btn2_label'] = $btn_retry; $bl_response['btn2_action'] = 'retry'; }

        // ── Chain: 1) Order Limit → 2) Blacklist ──

        // 1. Order limit checks (first)
        if ( $settings['check_phone'] && ! empty( $phone ) ) {
            if ( $this->check_order_limit( 'billing_phone', $phone, $time_hours, $order_limit ) ) {
                $this->report_block_to_api( 'rate_limit', 'phone', $email, $phone, $ip );
                wp_send_json_error( array_merge( $rl_response, [ 'target' => 'phone' ] ) );
            }
        }
        if ( $settings['check_email'] && ! empty( $email ) ) {
            if ( $this->check_order_limit( 'billing_email', $email, $time_hours, $order_limit ) ) {
                $this->report_block_to_api( 'rate_limit', 'email', $email, $phone, $ip );
                wp_send_json_error( array_merge( $rl_response, [ 'target' => 'email' ] ) );
            }
        }
        if ( $settings['check_ip'] && ! empty( $ip ) ) {
            if ( $this->check_order_limit( 'customer_ip_address', $ip, $time_hours, $order_limit ) ) {
                $this->report_block_to_api( 'rate_limit', 'ip', $email, $phone, $ip );
                wp_send_json_error( array_merge( $rl_response, [ 'target' => 'ip' ] ) );
            }
        }

        // 2. Blacklist checks (second)
        if ( $email && in_array( strtolower( $email ), array_map( 'strtolower', $blocked_emails ), true ) ) {
            $this->report_block_to_api( 'blacklist', 'email', $email, $phone, $ip );
            wp_send_json_error( array_merge( $bl_response, [ 'target' => 'email' ] ) );
        }
        if ( $ip && in_array( $ip, $blocked_ips, true ) ) {
            $this->report_block_to_api( 'blacklist', 'ip', $email, $phone, $ip );
            wp_send_json_error( array_merge( $bl_response, [ 'target' => 'ip' ] ) );
        }
        if ( $phone && in_array( $phone, $blocked_phones, true ) ) {
            $this->report_block_to_api( 'blacklist', 'phone', $email, $phone, $ip );
            wp_send_json_error( array_merge( $bl_response, [ 'target' => 'phone' ] ) );
        }

        wp_send_json_success( [ 'allowed' => true ] );
    }

    /* ================================================================
       Enqueue Popup Assets
       ================================================================ */

    public function enqueue_checkout_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        $ver = BIZGROWHUB_VERSION . '.' . time(); // Cache bust during dev

        wp_enqueue_style(
            'woo-guard-popup',
            BIZGROWHUB_PLUGIN_URL . 'assets/css/woo-guard-popup.css',
            [],
            $ver
        );

        wp_enqueue_script(
            'woo-guard-popup',
            BIZGROWHUB_PLUGIN_URL . 'assets/js/woo-guard-popup.js',
            [ 'jquery' ],
            $ver,
            true
        );

        $settings = self::get_settings();
        wp_localize_script( 'woo-guard-popup', 'wooGuardParams', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'woo_guard_validate' ),
            'popupHtml' => $settings['popup_html'] ?: '',
            'btnClose'  => $settings['popup_btn_close'] ?: 'Close',
            'btnRetry'  => $settings['popup_btn_retry'] ?: 'Try Again',
        ] );
    }

    /* ================================================================
       REST API — Settings CRUD
       ================================================================ */

    public function register_rest_routes() {
        $namespace = 'bizgrowhub/v1';

        register_rest_route( $namespace, '/woo-guard/settings', [
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'rest_get_settings' ],
                'permission_callback' => [ $this, 'check_rest_permission' ],
            ],
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'rest_save_settings' ],
                'permission_callback' => [ $this, 'check_rest_permission' ],
            ],
        ] );
    }

    /**
     * Permission check: WP admin OR valid license key.
     * License key auth allows dashboard to push settings remotely.
     */
    public function check_rest_permission( $request ) {
        // WP admin — always allowed
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // License key auth — dashboard pushing settings (raw key)
        $license_key = $request->get_header( 'X-License-Key' );
        if ( ! $license_key ) {
            $params = $request->get_json_params();
            $license_key = $params['license_key'] ?? null;
        }

        if ( $license_key ) {
            $stored_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
            if ( ! empty( $stored_key ) && $license_key === $stored_key ) {
                $status = get_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' );
                if ( $status === 'active' ) {
                    return true;
                }
            }
        }

        // Key hash auth — dashboard server-to-server push
        $key_hash = $request->get_header( 'X-Dashboard-Key-Hash' );
        if ( ! $key_hash ) {
            $params = $request->get_json_params();
            $key_hash = $params['key_hash'] ?? null;
        }

        if ( $key_hash ) {
            // Verify: hash the stored license key and compare
            $stored_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
            if ( ! empty( $stored_key ) ) {
                $stored_hash = hash( 'sha256', $stored_key );
                if ( hash_equals( $stored_hash, $key_hash ) ) {
                    return true;
                }
            }
        }

        return new \WP_Error( 'unauthorized', 'Invalid license key or insufficient permissions', [ 'status' => 403 ] );
    }

    public function rest_get_settings() {
        return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'settings' => self::get_settings() ] ] );
    }

    public function rest_save_settings( $request ) {
        $params   = $request->get_json_params();
        $settings = $params['settings'] ?? [];
        if ( empty( $settings ) ) {
            return new \WP_Error( 'invalid_data', 'Settings data required', [ 'status' => 400 ] );
        }
        self::save_settings( $settings );
        error_log( 'bizgrowhub WooGuard: Settings saved via REST API: ' . print_r( self::get_settings(), true ) );
        return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'settings' => self::get_settings() ] ] );
    }

    /* ================================================================
       Reporting (optional — to dashboard API)
       ================================================================ */

    private function report_block_to_api( $reason_type, $target, $email, $phone, $ip ) {
        if ( ! $this->api_client || ! $this->api_client->is_active() ) {
            return;
        }

        $this->api_client->make_request( BIZGROWHUB_ENDPOINT_WOO_GUARD_REPORT, [
            'report_type' => $reason_type === 'duplicate' ? 'duplicate_attempt' : 'blocked_attempt',
            'email'       => $email,
            'phone'       => $phone,
            'ip_address'  => $ip,
            'reason'      => "$reason_type:$target",
            'details'     => [ 'target' => $target, 'reason_type' => $reason_type ],
        ] );
    }

    public function report_order( $order_id ) {
        if ( ! $this->api_client || ! $this->api_client->is_active() ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->api_client->make_request( BIZGROWHUB_ENDPOINT_WOO_GUARD_REPORT, [
            'order_id'    => $order_id,
            'order_total' => $order->get_total(),
            'currency'    => $order->get_currency(),
            'customer_ip' => $order->get_customer_ip_address(),
            'email'       => $order->get_billing_email(),
            'phone'       => $order->get_billing_phone(),
            'status'      => $order->get_status(),
            'item_count'  => $order->get_item_count(),
        ] );
    }

    /* ================================================================
       Sync from remote_config → WP options
       ================================================================ */

    public static function sync_from_remote_config( $config ) {
        if ( ! is_array( $config ) ) return;

        $map = [];
        if ( isset( $config['is_enabled'] ) ) $map['enabled'] = $config['is_enabled'];

        $ol = $config['order_limit'] ?? $config['suspicious_flags']['order_limit'] ?? null;
        if ( is_array( $ol ) ) {
            if ( isset( $ol['check_phone'] ) )  $map['check_phone'] = $ol['check_phone'];
            if ( isset( $ol['check_ip'] ) )     $map['check_ip'] = $ol['check_ip'];
            if ( isset( $ol['check_email'] ) )  $map['check_email'] = $ol['check_email'];
            if ( isset( $ol['max_orders'] ) )   $map['order_limit'] = $ol['max_orders'];
            // Convert window string to hours
            if ( isset( $ol['window'] ) ) {
                $window_to_hours = [
                    '5m' => 1, '15m' => 1, '30m' => 1, '1h' => 1,
                    '6h' => 6, '12h' => 12, '24h' => 24, '3d' => 72, '7d' => 168,
                ];
                $map['time_hours'] = $window_to_hours[ $ol['window'] ] ?? 24;
            }
        }

        // Old flat format fallback
        if ( empty( $ol ) && isset( $config['max_orders_per_ip_hour'] ) ) {
            $map['time_hours'] = 1;
            $map['order_limit'] = $config['max_orders_per_ip_hour'];
            $map['check_phone'] = true;
            $map['check_ip'] = true;
            $map['check_email'] = true;
        }

        if ( isset( $config['blocked_emails'] ) )  $map['blocked_emails'] = $config['blocked_emails'];
        if ( isset( $config['blocked_ips'] ) )      $map['blocked_ips'] = $config['blocked_ips'];
        if ( isset( $config['blocked_phones'] ) )   $map['blocked_phones'] = $config['blocked_phones'];

        if ( ! empty( $map ) ) {
            self::save_settings( $map );
        }
    }
}

/* Hook: Sync remote_config → WP options on heartbeat */
add_action( 'update_option_BIZGROWHUB_remote_config', function( $old, $new ) {
    if ( isset( $new['woo_guard'] ) ) {
        WooCommerce_Guard::sync_from_remote_config( $new['woo_guard'] );
    }
}, 10, 2 );
