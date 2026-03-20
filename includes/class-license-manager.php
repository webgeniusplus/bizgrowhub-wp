<?php
/**
 * License Manager for Insight Hub
 *
 * @package BizGrowHub
 */

namespace BizGrowHub;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * License Manager Class
 */
class License_Manager {

    /**
     * API Client instance
     *
     * @var API_Client
     */
    private $api_client;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new API_Client();
    }

    /**
     * Activate license
     *
     * @param string $license_key License key
     * @return array|WP_Error Response or error
     */
    public function activate_license( $license_key ) {
        $license_key = sanitize_text_field( $license_key );

        // assemble payload exactly as the remote expects
        $data = array(
            'license_key'     => $license_key,
            'domain'          => $this->get_domain(),
            'site_url'        => get_site_url(),
            'home_url'        => get_home_url(),
            'wp_version'      => get_bloginfo( 'version' ),
            'plugin_version'  => BIZGROWHUB_VERSION,
            'php_version'     => phpversion(),
            'installation_id' => get_option( BIZGROWHUB_OPTION_INSTALLATION_ID, '' ),
        );

        // debug logging before request
        error_log( 'BIZGROWHUB_ACTIVATE_DEBUG: endpoint ' . BIZGROWHUB_API_BASE_URL . BIZGROWHUB_ENDPOINT_LICENSE_ACTIVATE );
        error_log( 'BIZGROWHUB_ACTIVATE_DEBUG: request payload ' . wp_json_encode( $data ) );

        $response = $this->api_client->make_request( BIZGROWHUB_ENDPOINT_LICENSE_ACTIVATE, $data );

        // additional debug info after request comes from API_Client

        if ( ! is_wp_error( $response ) ) {
            update_option( BIZGROWHUB_OPTION_LICENSE_KEY, $license_key );
            update_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'active' );
            update_option( BIZGROWHUB_OPTION_ACTIVATION_DATA, $data );
            update_option( BIZGROWHUB_OPTION_LAST_HEARTBEAT, current_time( 'timestamp' ) );

            // Store activation response data
            if ( ! empty( $response['project_id'] ) ) {
                update_option( 'BIZGROWHUB_project_id', $response['project_id'] );
            }
            if ( ! empty( $response['project_name'] ) ) {
                update_option( 'BIZGROWHUB_project_name', $response['project_name'] );
            }
            if ( ! empty( $response['activation_id'] ) ) {
                update_option( 'BIZGROWHUB_activation_id', $response['activation_id'] );
            }
            if ( isset( $response['features'] ) ) {
                update_option( 'BIZGROWHUB_features', $response['features'] );
            }
            if ( isset( $response['heartbeat_interval'] ) ) {
                update_option( 'BIZGROWHUB_heartbeat_interval', $response['heartbeat_interval'] );
            }
            if ( isset( $response['remote_config'] ) ) {
                update_option( 'BIZGROWHUB_remote_config', $response['remote_config'] );
            }
        }

        return $response;
    }

    /**
     * Deactivate license
     *
     * @return array|WP_Error Response or error
     */
    public function deactivate_license() {
        $license_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
        $domain      = $this->get_domain();
        
        $data = array(
            'license_key' => $license_key,
            'domain'      => $domain,
        );

        $response = $this->api_client->make_request( BIZGROWHUB_ENDPOINT_LICENSE_DEACTIVATE, $data );

        if ( ! is_wp_error( $response ) ) {
            delete_option( BIZGROWHUB_OPTION_LICENSE_KEY );
            update_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' );
            delete_option( BIZGROWHUB_OPTION_ACTIVATION_DATA );
            delete_option( 'BIZGROWHUB_project_id' );
            delete_option( 'BIZGROWHUB_project_name' );
            delete_option( 'BIZGROWHUB_activation_id' );
            delete_option( 'BIZGROWHUB_features' );
            delete_option( 'BIZGROWHUB_heartbeat_interval' );
            delete_option( 'BIZGROWHUB_remote_config' );
        }

        return $response;
    }

    /**
     * Validate license
     *
     * @param string $license_key License key to validate
     * @return array|WP_Error Response or error
     */
    public function validate_license( $license_key ) {
        $license_key = sanitize_text_field( $license_key );

        $data = array(
            'license_key' => $license_key,
            'domain'      => $this->get_domain(),
        );

        return $this->api_client->make_request( BIZGROWHUB_ENDPOINT_LICENSE_VALIDATE, $data );
    }

    /**
     * Send heartbeat
     *
     * @return array|WP_Error Response or error
     */
    public function send_heartbeat() {
        $license_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
        $data = $this->get_activation_data();
        $data['license_key'] = $license_key;
        
        $response = $this->api_client->make_request( BIZGROWHUB_ENDPOINT_LICENSE_HEARTBEAT, $data );

        if ( ! is_wp_error( $response ) ) {
            update_option( BIZGROWHUB_OPTION_LAST_HEARTBEAT, current_time( 'timestamp' ) );

            // Store installed addons list from dashboard
            if ( isset( $response['installed_addons'] ) && is_array( $response['installed_addons'] ) ) {
                update_option( 'BIZGROWHUB_installed_addons', $response['installed_addons'] );
            }

            // Sync remote_config (woo_guard settings, etc.)
            if ( isset( $response['remote_config'] ) && is_array( $response['remote_config'] ) ) {
                error_log( 'bizgrowhub: Heartbeat received remote_config: ' . print_r( $response['remote_config'], true ) );
                update_option( 'BIZGROWHUB_remote_config', $response['remote_config'] );

                // Directly sync woo_guard if present (don't wait for update_option hook)
                if ( isset( $response['remote_config']['woo_guard'] ) ) {
                    \BizGrowHub\WooCommerce_Guard::sync_from_remote_config( $response['remote_config']['woo_guard'] );
                    error_log( 'bizgrowhub: WooGuard synced from heartbeat. Enabled=' . var_export( get_option('BIZGROWHUB_wg_enabled'), true ) );
                }
            }
        }

        return $response;
    }

    /**
     * Get activation data
     *
     * @return array
     */
    /**
     * Build a normalized payload for activation / heartbeat.
     * the remote currently expects a very small set of fields.
     *
     * @return array
     */
    private function get_activation_data() {
        return array(
            'site_url'        => get_site_url(),
            'home_url'        => get_home_url(),
            'domain'          => $this->get_domain(),
            'plugin_version'  => BIZGROWHUB_VERSION,
            'wp_version'      => get_bloginfo( 'version' ),
            'php_version'     => phpversion(),
            'installation_id' => get_option( BIZGROWHUB_OPTION_INSTALLATION_ID, '' ),
        );
    }

    /**
     * Get license status
     *
     * @return string
     */
    public function get_license_status() {
        return get_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' );
    }

    /**
     * Get last heartbeat
     *
     * @return int
     */
    public function get_last_heartbeat() {
        return get_option( BIZGROWHUB_OPTION_LAST_HEARTBEAT, 0 );
    }

    /**
     * Normalize and return current site host (strip protocol and www)
     *
     * @return string
     */
    private function get_domain() {
        $host = wp_parse_url( get_site_url(), PHP_URL_HOST );
        if ( ! $host ) {
            return '';
        }
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return $host;
    }

}