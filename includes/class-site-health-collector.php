<?php
namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Site_Health_Collector {

    private $api_client;

    public function __construct() {
        $this->api_client = new API_Client();
        add_action( BIZGROWHUB_CRON_HEARTBEAT, array( $this, 'sync_site_health' ) );
    }

    public function sync_site_health() {
        if ( get_option( BIZGROWHUB_OPTION_FEATURE_SITE_HEALTH, '1' ) !== '1' ) {
            return;
        }

        if ( ! $this->api_client->is_active() ) {
            return;
        }

        $health_data = $this->collect_site_health();

        $this->api_client->make_request( BIZGROWHUB_ENDPOINT_SITE_HEALTH_SYNC, array(
            'health' => $health_data,
        ) );
    }

    private function collect_site_health() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );

        $plugin_list = array();
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_list[] = array(
                'name'    => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'active'  => in_array( $plugin_file, $active_plugins, true ),
            );
        }

        return array(
            'wp_version'           => get_bloginfo( 'version' ),
            'php_version'          => phpversion(),
            'active_theme'         => wp_get_theme()->get( 'Name' ),
            'active_plugins_count' => count( $active_plugins ),
            'total_plugins_count'  => count( $all_plugins ),
            'cron_status'          => $this->check_cron_status() ? 'ok' : 'disabled',
            'rest_api_status'      => $this->check_rest_api_status() ? 'ok' : 'error',
            'ssl_present'          => is_ssl(),
            'debug_mode'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'memory_limit'         => ini_get( 'memory_limit' ),
            'permalink_structure'  => get_option( 'permalink_structure' ),
            'indexing_discouraged'  => ! (bool) get_option( 'blog_public' ),
            'woocommerce_active'   => class_exists( 'WooCommerce' ),
            'plugin_list'          => $plugin_list,
        );
    }

    private function check_cron_status() {
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return false;
        }
        return true;
    }

    private function check_rest_api_status() {
        $response = wp_remote_get( rest_url() );
        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }
}
