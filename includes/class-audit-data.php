<?php
/**
 * Audit Data Collector
 * Provides comprehensive internal WordPress data for BizGrowHub Site Audit
 * Returns data that cannot be detected from public HTML (plugins, WooCommerce internals, security settings)
 * 
 * @package BizGrowHub
 * @version 1.0.0
 */

namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Audit_Data {

    private $api_client;

    public function __construct() {
        $this->api_client = new API_Client();
        
        // Register REST API endpoint
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'bizgrowhub/v1';

        register_rest_route( $namespace, '/audit-data', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_audit_data' ),
            'permission_callback' => array( $this, 'check_auth' ),
        ) );
    }

    /**
     * Auth: license key via header or param
     */
    public function check_auth( $request ) {
        // Allow admin users
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        $stored_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
        if ( empty( $stored_key ) ) {
            return false;
        }

        // Check raw license key
        $key = $request->get_header( 'X-License-Key' );
        if ( ! $key ) {
            $key = $request->get_param( 'license_key' );
        }
        if ( $key && $key === $stored_key ) {
            return get_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' ) === 'active';
        }

        // Check hash auth
        $hash = $request->get_header( 'X-Dashboard-Key-Hash' );
        if ( $hash && hash( 'sha256', $stored_key ) === $hash ) {
            return get_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' ) === 'active';
        }

        return false;
    }

    /**
     * GET /bizgrowhub/v1/audit-data
     * Returns comprehensive audit data
     */
    public function get_audit_data( $request ) {
        try {
            $data = array(
                'active_theme'       => $this->get_theme_info(),
                'wordpress'          => $this->get_wordpress_info(),
                'php'                => $this->get_php_info(),
                'woocommerce'        => $this->get_woocommerce_info(),
                'plugins'            => $this->get_plugins_info(),
                'security'           => $this->get_security_info(),
                'performance'        => $this->get_performance_info(),
                'database'           => $this->get_database_info(),
                'site_health'        => $this->get_site_health_info(),
                'bangladesh_context' => $this->get_bangladesh_context(),
            );

            return new \WP_REST_Response(
                array(
                    'success' => true,
                    'data'    => $data,
                ),
                200
            );
        } catch ( \Exception $e ) {
            return new \WP_REST_Response(
                array(
                    'success' => false,
                    'error'   => $e->getMessage(),
                ),
                500
            );
        }
    }

    /**
     * Get active theme information
     */
    private function get_theme_info() {
        $theme = wp_get_theme();

        return array(
            'name'     => $theme->get( 'Name' ),
            'slug'     => $theme->get_stylesheet(),
            'version'  => $theme->get( 'Version' ),
            'author'   => $theme->get( 'Author' ),
            'is_child' => is_child_theme(),
        );
    }

    /**
     * Get WordPress core information
     */
    private function get_wordpress_info() {
        return array(
            'version'  => get_bloginfo( 'version' ),
            'language' => get_locale(),
            'timezone' => get_option( 'timezone_string' ) ?: 'UTC',
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
        );
    }

    /**
     * Get PHP environment information
     */
    private function get_php_info() {
        return array(
            'version'          => phpversion(),
            'memory_limit'     => ini_get( 'memory_limit' ),
            'max_upload_size'  => size_format( wp_max_upload_size() ),
            'execution_time'   => ini_get( 'max_execution_time' ),
            'extensions'       => get_loaded_extensions(),
        );
    }

    /**
     * Get WooCommerce information (if active)
     */
    private function get_woocommerce_info() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return array(
                'is_active' => false,
            );
        }

        global $wpdb;

        // Get payment gateways
        $payment_gateways = array();
        if ( function_exists( 'WC' ) ) {
            $available_gateways = WC()->payment_gateways->payment_gateways();
            foreach ( $available_gateways as $gateway ) {
                $payment_gateways[] = array(
                    'id'      => $gateway->id,
                    'title'   => $gateway->title,
                    'enabled' => $gateway->enabled === 'yes',
                );
            }
        }

        // Get shipping methods
        $shipping_methods = array();
        if ( function_exists( 'WC' ) ) {
            $shipping_zones = \WC_Shipping_Zones::get_zones();
            foreach ( $shipping_zones as $zone ) {
                foreach ( $zone['shipping_methods'] as $method ) {
                    $shipping_methods[] = array(
                        'id'      => $method->id,
                        'title'   => $method->title,
                        'enabled' => $method->enabled === 'yes',
                    );
                }
            }
        }

        // Get counts
        $product_count = wp_count_posts( 'product' );
        $order_count   = wp_count_posts( 'shop_order' );

        return array(
            'is_active'        => true,
            'version'          => defined( 'WC_VERSION' ) ? WC_VERSION : '0.0.0',
            'store_currency'   => get_woocommerce_currency(),
            'product_count'    => $product_count->publish ?? 0,
            'order_count'      => $order_count->{'wc-completed'} ?? 0,
            'payment_gateways' => $payment_gateways,
            'shipping_methods' => $shipping_methods,
        );
    }

    /**
     * Get plugins information
     */
    private function get_plugins_info() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $updates        = get_site_transient( 'update_plugins' );

        $plugin_list = array();
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_list[] = array(
                'name'             => $plugin_data['Name'],
                'slug'             => dirname( $plugin_file ),
                'version'          => $plugin_data['Version'],
                'author'           => $plugin_data['Author'],
                'active'           => in_array( $plugin_file, $active_plugins, true ),
                'update_available' => isset( $updates->response[ $plugin_file ] ),
            );
        }

        return array(
            'total'  => count( $all_plugins ),
            'active' => count( $active_plugins ),
            'list'   => $plugin_list,
        );
    }

    /**
     * Get security information
     */
    private function get_security_info() {
        return array(
            'ssl_installed'           => is_ssl(),
            'debug_mode_enabled'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'wp_debug_log_enabled'    => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
            'wp_auto_updates_enabled' => get_option( 'auto_update_core_major' ) === 'enabled',
        );
    }

    /**
     * Get performance information
     */
    private function get_performance_info() {
        $object_cache = wp_using_ext_object_cache();

        // Try to detect cache plugin
        $cache_plugin = null;
        if ( is_plugin_active( 'wp-super-cache/wp-cache.php' ) ) {
            $cache_plugin = 'WP Super Cache';
        } elseif ( is_plugin_active( 'w3-total-cache/w3-total-cache.php' ) ) {
            $cache_plugin = 'W3 Total Cache';
        } elseif ( is_plugin_active( 'wp-fastest-cache/wpFastestCache.php' ) ) {
            $cache_plugin = 'WP Fastest Cache';
        } elseif ( is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) ) {
            $cache_plugin = 'LiteSpeed Cache';
        }

        return array(
            'page_cache_enabled'   => ! empty( $cache_plugin ),
            'cache_plugin'         => $cache_plugin,
            'object_cache_enabled' => $object_cache,
            'gzip_compression'     => extension_loaded( 'zlib' ),
        );
    }

    /**
     * Get database information
     */
    private function get_database_info() {
        global $wpdb;

        // Get database version
        $db_version = $wpdb->get_var( 'SELECT VERSION()' );

        // Count posts
        $posts_count = wp_count_posts();
        $total_posts = 0;
        foreach ( $posts_count as $status => $count ) {
            $total_posts += $count;
        }

        // Count comments
        $total_comments = wp_count_comments();

        // Get database size
        $db_size  = 0;
        $tables   = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
        $db_count = count( $tables );
        foreach ( $tables as $table ) {
            $db_size += $table['Data_length'] + $table['Index_length'];
        }

        return array(
            'type'           => 'MySQL',
            'version'        => $db_version,
            'posts_count'    => $total_posts,
            'total_comments' => $total_comments->total_comments ?? 0,
            'tables_count'   => $db_count,
            'database_size_mb' => round( $db_size / 1024 / 1024, 2 ),
        );
    }

    /**
     * Get site health information
     */
    private function get_site_health_info() {
        if ( ! class_exists( 'WP_Site_Health' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        $health = \WP_Site_Health::get_instance();
        $tests  = $health->get_tests();

        $critical = 0;
        $recommended = 0;

        // This is simplified; actual implementation would run all tests
        // For now, return basic info
        return array(
            'site_health_status'       => 'good', // or 'critical', 'recommended'
            'critical_issues'          => $critical,
            'recommended_improvements' => $recommended,
            'last_checked'             => current_time( 'mysql' ),
        );
    }

    /**
     * Get Bangladesh-specific context
     */
    private function get_bangladesh_context() {
        $local_payments = array();
        $local_couriers = array();

        // Check WooCommerce payment methods for local options
        if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) ) {
            $available_gateways = WC()->payment_gateways->payment_gateways();
            foreach ( $available_gateways as $gateway ) {
                $gateway_id = strtolower( $gateway->id );
                if ( strpos( $gateway_id, 'bkash' ) !== false ) {
                    $local_payments[] = 'bkash';
                } elseif ( strpos( $gateway_id, 'nagad' ) !== false ) {
                    $local_payments[] = 'nagad';
                } elseif ( strpos( $gateway_id, 'rocket' ) !== false ) {
                    $local_payments[] = 'rocket';
                }
            }

            // Check shipping methods for local couriers
            $shipping_zones = \WC_Shipping_Zones::get_zones();
            foreach ( $shipping_zones as $zone ) {
                foreach ( $zone['shipping_methods'] as $method ) {
                    $method_id = strtolower( $method->id );
                    if ( strpos( $method_id, 'steadfast' ) !== false ) {
                        $local_couriers[] = 'steadfast';
                    } elseif ( strpos( $method_id, 'pathao' ) !== false ) {
                        $local_couriers[] = 'pathao';
                    } elseif ( strpos( $method_id, 'redx' ) !== false ) {
                        $local_couriers[] = 'redx';
                    }
                }
            }
        }

        // Check for Bangla language support
        $locale = get_locale();
        $bangla_support = ( $locale === 'bn_BD' || strpos( $locale, 'bn' ) !== false );

        return array(
            'local_payment_methods' => array_unique( $local_payments ),
            'local_couriers'        => array_unique( $local_couriers ),
            'bangla_support'        => $bangla_support,
            'mobile_optimized'      => true, // Could check theme capabilities
        );
    }
}
