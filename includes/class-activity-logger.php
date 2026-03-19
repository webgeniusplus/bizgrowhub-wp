<?php
namespace InsightHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activity_Logger {

    private $api_client;
    private $log_queue = array();

    public function __construct() {
        $this->api_client = new API_Client();

        if ( get_option( INSIGHT_HUB_OPTION_FEATURE_ACTIVITY_LOGS, '1' ) !== '1' ) {
            return;
        }

        $this->register_hooks();

        add_action( 'shutdown', array( $this, 'flush_logs' ) );
    }

    private function register_hooks() {
        // User activities
        add_action( 'wp_login', array( $this, 'log_user_login' ) );
        add_action( 'user_register', array( $this, 'log_user_create' ) );
        add_action( 'profile_update', array( $this, 'log_user_update' ) );
        add_action( 'delete_user', array( $this, 'log_user_delete' ) );

        // Post activities
        add_action( 'post_updated', array( $this, 'log_post_update' ), 10, 3 );

        // Media activities
        add_action( 'add_attachment', array( $this, 'log_media_upload' ) );
        add_action( 'delete_attachment', array( $this, 'log_media_delete' ) );

        // Plugin/Theme activities
        add_action( 'activated_plugin', array( $this, 'log_plugin_activate' ) );
        add_action( 'deactivated_plugin', array( $this, 'log_plugin_deactivate' ) );
        add_action( 'switch_theme', array( $this, 'log_theme_switch' ) );

        // Settings changes
        add_action( 'update_option', array( $this, 'log_option_update' ), 10, 3 );

        // WooCommerce activities (if active)
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_new_order', array( $this, 'log_wc_order_create' ) );
            add_action( 'woocommerce_order_status_changed', array( $this, 'log_wc_order_update' ), 10, 3 );
            add_action( 'woocommerce_new_product', array( $this, 'log_wc_product_create' ) );
            add_action( 'woocommerce_update_product', array( $this, 'log_wc_product_update' ) );
        }
    }

    /**
     * Queue a log entry (will be flushed on shutdown)
     */
    private function queue_log( $action_type, $object_type, $object_id, $object_name, $details = array(), $severity = 'info' ) {
        if ( ! $this->api_client->is_active() ) {
            return;
        }

        $current_user = wp_get_current_user();

        $this->log_queue[] = array(
            'action_type' => $action_type,
            'object_type' => $object_type,
            'object_id'   => (string) $object_id,
            'object_name' => $object_name,
            'user_login'  => $current_user->user_login ?? '',
            'user_role'   => ! empty( $current_user->roles ) ? $current_user->roles[0] : '',
            'details'     => $details,
            'severity'    => $severity,
            'ip_address'  => $this->get_user_ip(),
        );
    }

    /**
     * Flush all queued logs in one API call
     */
    public function flush_logs() {
        if ( empty( $this->log_queue ) ) {
            return;
        }

        $this->api_client->make_request( INSIGHT_HUB_ENDPOINT_ACTIVITY_LOGS, array(
            'logs' => $this->log_queue,
        ) );

        $this->log_queue = array();
    }

    // --- Log handlers ---

    public function log_user_login( $user_login ) {
        $this->queue_log( 'user_login', 'user', 0, $user_login );
    }

    public function log_user_create( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            $this->queue_log( 'user_created', 'user', $user_id, $user->user_login, array(
                'user_email' => $user->user_email,
            ) );
        }
    }

    public function log_user_update( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            $this->queue_log( 'user_updated', 'user', $user_id, $user->user_login );
        }
    }

    public function log_user_delete( $user_id ) {
        $this->queue_log( 'user_deleted', 'user', $user_id, '' );
    }

    public function log_post_update( $post_id, $post_after, $post_before ) {
        if ( $post_after->post_modified !== $post_before->post_modified ) {
            $this->queue_log( 'post_updated', $post_after->post_type, $post_id, $post_after->post_title, array(
                'post_status' => $post_after->post_status,
            ) );
        }
    }

    public function log_media_upload( $attachment_id ) {
        $this->queue_log( 'media_uploaded', 'attachment', $attachment_id, basename( get_attached_file( $attachment_id ) ) );
    }

    public function log_media_delete( $attachment_id ) {
        $this->queue_log( 'media_deleted', 'attachment', $attachment_id, '' );
    }

    public function log_plugin_activate( $plugin ) {
        $this->queue_log( 'plugin_activated', 'plugin', 0, $plugin, array(), 'warning' );
    }

    public function log_plugin_deactivate( $plugin ) {
        $this->queue_log( 'plugin_deactivated', 'plugin', 0, $plugin, array(), 'warning' );
    }

    public function log_theme_switch( $new_theme ) {
        $this->queue_log( 'theme_switched', 'theme', 0, $new_theme, array(), 'warning' );
    }

    public function log_option_update( $option, $old_value, $new_value ) {
        $important_options = array( 'siteurl', 'home', 'blogname', 'admin_email' );
        if ( in_array( $option, $important_options, true ) ) {
            $this->queue_log( 'option_updated', 'option', 0, $option, array(
                'old_value' => is_scalar( $old_value ) ? $old_value : wp_json_encode( $old_value ),
                'new_value' => is_scalar( $new_value ) ? $new_value : wp_json_encode( $new_value ),
            ), 'warning' );
        }
    }

    public function log_wc_order_create( $order_id ) {
        $this->queue_log( 'wc_order_created', 'shop_order', $order_id, "Order #{$order_id}" );
    }

    public function log_wc_order_update( $order_id, $old_status, $new_status ) {
        $this->queue_log( 'wc_order_status_changed', 'shop_order', $order_id, "Order #{$order_id}", array(
            'old_status' => $old_status,
            'new_status' => $new_status,
        ) );
    }

    public function log_wc_product_create( $product_id ) {
        $product = get_post( $product_id );
        $this->queue_log( 'wc_product_created', 'product', $product_id, $product ? $product->post_title : '' );
    }

    public function log_wc_product_update( $product_id ) {
        $product = get_post( $product_id );
        $this->queue_log( 'wc_product_updated', 'product', $product_id, $product ? $product->post_title : '' );
    }

    private function get_user_ip() {
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            return sanitize_text_field( explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )[0] );
        }
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
    }
}
