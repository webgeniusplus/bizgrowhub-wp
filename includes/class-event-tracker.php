<?php
namespace InsightHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Event_Tracker {

    private $api_client;
    private $event_queue = array();

    public function __construct() {
        $this->api_client = new API_Client();

        if ( get_option( INSIGHT_HUB_OPTION_FEATURE_EVENT_TRACKING, '1' ) !== '1' ) {
            return;
        }

        $this->register_hooks();

        add_action( 'shutdown', array( $this, 'flush_events' ) );

        // Frontend tracker script — served from dashboard, injected via wp_footer
        if ( ! is_admin() ) {
            add_action( 'wp_footer', array( $this, 'inject_tracker_script' ), 99 );
        }
    }

    private function register_hooks() {
        // Post events
        add_action( 'publish_post', array( $this, 'track_post_publish' ), 10, 2 );
        add_action( 'post_updated', array( $this, 'track_post_update' ), 10, 3 );
        add_action( 'before_delete_post', array( $this, 'track_post_delete' ) );

        // Media events
        add_action( 'add_attachment', array( $this, 'track_media_upload' ) );
        add_action( 'delete_attachment', array( $this, 'track_media_delete' ) );

        // User events
        add_action( 'wp_login', array( $this, 'track_user_login' ) );
        add_action( 'wp_logout', array( $this, 'track_user_logout' ) );

        // Plugin/Theme events
        add_action( 'activated_plugin', array( $this, 'track_plugin_activate' ) );
        add_action( 'deactivated_plugin', array( $this, 'track_plugin_deactivate' ) );
        add_action( 'switch_theme', array( $this, 'track_theme_switch' ) );

        // WooCommerce events
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_new_order', array( $this, 'track_wc_order_created' ) );
            add_action( 'woocommerce_order_status_changed', array( $this, 'track_wc_order_updated' ), 10, 3 );
        }
    }

    /**
     * Queue an event (will be flushed on shutdown)
     */
    private function queue_event( $event_name, $category, $data = array() ) {
        if ( ! $this->api_client->is_active() ) {
            return;
        }

        $this->event_queue[] = array(
            'event_name' => $event_name,
            'category'   => $category,
            'data'       => $data,
            'timestamp'  => gmdate( 'c' ),
            'ip_hash'    => md5( $this->get_user_ip() ),
            'page_url'   => isset( $_SERVER['REQUEST_URI'] ) ? home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '',
        );
    }

    /**
     * Flush all queued events in one API call
     */
    public function flush_events() {
        if ( empty( $this->event_queue ) ) {
            return;
        }

        $this->api_client->make_request( INSIGHT_HUB_ENDPOINT_EVENTS_INGEST, array(
            'events' => $this->event_queue,
        ) );

        $this->event_queue = array();
    }

    // --- Event handlers ---

    public function track_post_publish( $post_id, $post ) {
        $this->queue_event( 'post_publish', 'content', array(
            'post_id'    => $post_id,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
        ) );
    }

    public function track_post_update( $post_id, $post_after, $post_before ) {
        if ( $post_after->post_modified !== $post_before->post_modified ) {
            $this->queue_event( 'post_update', 'content', array(
                'post_id'    => $post_id,
                'post_type'  => $post_after->post_type,
                'post_title' => $post_after->post_title,
            ) );
        }
    }

    public function track_post_delete( $post_id ) {
        $post = get_post( $post_id );
        if ( $post ) {
            $this->queue_event( 'post_delete', 'content', array(
                'post_id'    => $post_id,
                'post_type'  => $post->post_type,
                'post_title' => $post->post_title,
            ) );
        }
    }

    public function track_media_upload( $attachment_id ) {
        $this->queue_event( 'media_upload', 'media', array(
            'attachment_id' => $attachment_id,
            'file_name'     => basename( get_attached_file( $attachment_id ) ),
        ) );
    }

    public function track_media_delete( $attachment_id ) {
        $this->queue_event( 'media_delete', 'media', array( 'attachment_id' => $attachment_id ) );
    }

    public function track_user_login( $user_login ) {
        $this->queue_event( 'user_login', 'auth', array( 'user_login' => $user_login ) );
    }

    public function track_user_logout() {
        $this->queue_event( 'user_logout', 'auth', array() );
    }

    public function track_plugin_activate( $plugin ) {
        $this->queue_event( 'plugin_activate', 'system', array( 'plugin' => $plugin ) );
    }

    public function track_plugin_deactivate( $plugin ) {
        $this->queue_event( 'plugin_deactivate', 'system', array( 'plugin' => $plugin ) );
    }

    public function track_theme_switch( $new_theme ) {
        $this->queue_event( 'theme_switch', 'system', array( 'new_theme' => $new_theme ) );
    }

    public function track_wc_order_created( $order_id ) {
        $this->queue_event( 'wc_order_created', 'woocommerce', array( 'order_id' => $order_id ) );
    }

    public function track_wc_order_updated( $order_id, $old_status, $new_status ) {
        $this->queue_event( 'wc_order_status_changed', 'woocommerce', array(
            'order_id'   => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ) );
    }

    /**
     * Inject tracker — local tracker.js + config injected via PHP (key never in URL)
     */
    public function inject_tracker_script() {
        if ( ! $this->api_client->is_active() ) {
            return;
        }

        $license_key = get_option( INSIGHT_HUB_OPTION_LICENSE_KEY, '' );
        if ( empty( $license_key ) ) {
            return;
        }

        // Config inline (public — key only allows event writes, no read access)
        echo '<script>window.__MP={k:"' . esc_js( $license_key ) . '",api:"' . esc_js( INSIGHT_HUB_API_BASE_URL ) . '/events/ingest"};</script>' . "\n";
        echo '<script src="' . esc_url( INSIGHT_HUB_PLUGIN_URL . 'assets/js/tracker.js' ) . '?v=' . INSIGHT_HUB_VERSION . '" defer></script>' . "\n";
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
