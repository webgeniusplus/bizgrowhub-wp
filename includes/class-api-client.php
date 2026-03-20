<?php
namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class API_Client {

    private $api_base_url;

    public function __construct() {
        $this->api_base_url = BIZGROWHUB_API_BASE_URL;
    }

    /**
     * Get stored license key
     */
    private function get_license_key() {
        return get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
    }

    /**
     * Get normalized site domain
     */
    private function get_domain() {
        $host = wp_parse_url( get_site_url(), PHP_URL_HOST );
        if ( ! $host ) return '';
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return strtolower( $host );
    }

    /**
     * Check if plugin is activated (has license + active status)
     */
    public function is_active() {
        return ! empty( $this->get_license_key() )
            && get_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' ) === 'active';
    }

    /**
     * Make authenticated API request
     * Automatically includes license_key and domain in body
     */
    public function make_request( $endpoint, $data = array(), $method = 'POST' ) {
        $url = $this->api_base_url . $endpoint;

        // Auto-inject auth fields for non-license endpoints
        if ( ! isset( $data['license_key'] ) ) {
            $data['license_key'] = $this->get_license_key();
        }
        if ( ! isset( $data['domain'] ) ) {
            $data['domain'] = $this->get_domain();
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $log_data = $data;
            if ( isset( $log_data['license_key'] ) ) {
                $log_data['license_key'] = substr( $log_data['license_key'], 0, 10 ) . '...';
            }
            error_log( 'Insight Hub API: ' . $method . ' ' . $endpoint . ' | ' . wp_json_encode( $log_data ) );
        }

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => $method !== 'GET' ? wp_json_encode( $data ) : '',
            'timeout' => 30,
        );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Insight Hub API: Error — ' . $response->get_error_message() );
            }
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Insight Hub API: Response ' . $code . ' from ' . $endpoint );
        }

        if ( $code >= 200 && $code < 300 ) {
            return json_decode( $body, true );
        } else {
            // Extract error message from JSON response body if available
            $decoded = json_decode( $body, true );
            $message = ( $decoded && ! empty( $decoded['error'] ) ) ? $decoded['error'] : 'API request failed with code ' . $code;
            return new \WP_Error( 'api_error', $message, array( 'body' => $body, 'code' => $code ) );
        }
    }
}
