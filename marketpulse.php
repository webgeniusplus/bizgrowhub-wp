<?php
/**
 * Plugin Name: MarketPulse
 * Plugin URI:  https://github.com/webgeniusplus/marketpulse-wp
 * Description: Connects your WooCommerce store to MarketPulse dashboard. Real-time order sync with offline queue, event tracking, WooGuard fraud protection, incomplete checkout capture, and activity logs.
 * Version:     2.1.0
 * Author:      MarketPulse
 * Author URI:  https://marketpulse.com.bd
 * License:     GPL v2 or later
 * Text Domain: marketpulse
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'MARKETPULSE_VERSION', '2.1.0' );
define( 'MARKETPULSE_PLUGIN_FILE', __FILE__ );
define( 'MARKETPULSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARKETPULSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MARKETPULSE_API_BASE_URL', 'https://marketpulse.com.bd/api' );

// API Endpoints (Vercel routes)
define( 'MARKETPULSE_ENDPOINT_LICENSE_ACTIVATE', '/license/activate' );
define( 'MARKETPULSE_ENDPOINT_LICENSE_DEACTIVATE', '/license/deactivate' );
define( 'MARKETPULSE_ENDPOINT_LICENSE_VALIDATE', '/license/validate' );
define( 'MARKETPULSE_ENDPOINT_LICENSE_HEARTBEAT', '/license/heartbeat' );
define( 'MARKETPULSE_ENDPOINT_EVENTS_INGEST', '/events/ingest' );
define( 'MARKETPULSE_ENDPOINT_ACTIVITY_LOGS', '/activity-logs/ingest' );
define( 'MARKETPULSE_ENDPOINT_SITE_HEALTH_SYNC', '/site-health/sync' );
define( 'MARKETPULSE_ENDPOINT_AUDIT_LIGHTHOUSE', '/audit/lighthouse-result' );
define( 'MARKETPULSE_ENDPOINT_REMOTE_ACTIONS_PULL', '/remote-actions/pull' );
define( 'MARKETPULSE_ENDPOINT_REMOTE_ACTIONS_REPORT', '/remote-actions/report' );
define( 'MARKETPULSE_ENDPOINT_WOO_GUARD_REPORT', '/woo-guard/report' );
define( 'MARKETPULSE_ENDPOINT_ORDER_WEBHOOK', '/orders/webhook' );

// Option keys
define( 'MARKETPULSE_OPTION_LICENSE_KEY', 'marketpulse_license_key' );
define( 'MARKETPULSE_OPTION_LICENSE_STATUS', 'marketpulse_license_status' );
define( 'MARKETPULSE_OPTION_LAST_HEARTBEAT', 'marketpulse_last_heartbeat' );
define( 'MARKETPULSE_OPTION_ACTIVATION_DATA', 'marketpulse_activation_data' );
define( 'MARKETPULSE_OPTION_INSTALLATION_ID', 'marketpulse_installation_id' );
define( 'MARKETPULSE_OPTION_FEATURE_EVENT_TRACKING', 'marketpulse_feature_event_tracking' );
define( 'MARKETPULSE_OPTION_FEATURE_ACTIVITY_LOGS', 'marketpulse_feature_activity_logs' );
define( 'MARKETPULSE_OPTION_FEATURE_SITE_HEALTH', 'marketpulse_feature_site_health' );
define( 'MARKETPULSE_OPTION_FEATURE_WC_GUARD', 'marketpulse_feature_wc_guard' );
define( 'MARKETPULSE_OPTION_FEATURE_IMAGE_OPT', 'marketpulse_feature_image_opt' );
define( 'MARKETPULSE_OPTION_FEATURE_REMOTE_ACTIONS', 'marketpulse_feature_remote_actions' );
define( 'MARKETPULSE_OPTION_GA4_MEASUREMENT_ID', 'marketpulse_ga4_measurement_id' );
define( 'MARKETPULSE_OPTION_GA4_PROPERTY_ID', 'marketpulse_ga4_property_id' );

// Cron hooks
define( 'MARKETPULSE_CRON_HEARTBEAT', 'marketpulse_heartbeat' );

// Capabilities
define( 'MARKETPULSE_CAPABILITY_MANAGE', 'manage_options' );

// Include classes in order
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-sync-queue.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-license-manager.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-cron-manager.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-event-tracker.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-activity-logger.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-site-health-collector.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-woocommerce-guard.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-image-optimization-service.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-seo-security-collector.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-remote-actions-manager.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-ga4-tracker.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-data-sync.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-order-sync.php';
require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-incomplete-checkout.php';

// Deactivation hook for sync queue cleanup
register_deactivation_hook( MARKETPULSE_PLUGIN_FILE, [ 'MarketPulse_Sync_Queue', 'deactivate' ] );

// Bootstrap the plugin
class MarketPulse_Plugin {

    /**
     * Initialize the plugin
     */
    public static function init() {
        // Initialize sync queue (registers cron, hooks)
        MarketPulse_Sync_Queue::init();

        // Load textdomain
        load_plugin_textdomain( 'marketpulse', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Initialize components
        new \MarketPulse\Admin_Settings();
        new \MarketPulse\Cron_Manager();
        new \MarketPulse\Event_Tracker();
        new \MarketPulse\Activity_Logger();
        new \MarketPulse\Site_Health_Collector();
        new \MarketPulse\WooCommerce_Guard();
        new \MarketPulse\Image_Optimization_Service();
        new \MarketPulse\SEO_Security_Collector();
        new \MarketPulse\Remote_Actions_Manager();
        new \MarketPulse\GA4_Tracker();
        new \MarketPulse\Data_Sync();
        new \MarketPulse\Order_Sync();
        new \MarketPulse\Incomplete_Checkout();

        // Hook for activation
        register_activation_hook( MARKETPULSE_PLUGIN_FILE, array( __CLASS__, 'activate' ) );

        // Hook for deactivation
        register_deactivation_hook( MARKETPULSE_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Generate installation ID if not exists
        if ( ! get_option( MARKETPULSE_OPTION_INSTALLATION_ID ) ) {
            update_option( MARKETPULSE_OPTION_INSTALLATION_ID, wp_generate_uuid4() );
        }

        // Schedule heartbeat
        if ( ! wp_next_scheduled( MARKETPULSE_CRON_HEARTBEAT ) ) {
            wp_schedule_event( time(), 'hourly', MARKETPULSE_CRON_HEARTBEAT );
        }
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( MARKETPULSE_CRON_HEARTBEAT );
    }

}

// Initialize the plugin on plugins_loaded
add_action( 'plugins_loaded', array( 'MarketPulse_Plugin', 'init' ) );
