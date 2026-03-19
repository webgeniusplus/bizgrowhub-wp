<?php
/**
 * Plugin Name: MarketPulse
 * Plugin URI:  https://github.com/webgeniusplus/marketpulse-wp
 * Description: Connects your WooCommerce store to MarketPulse dashboard. Real-time order sync with offline queue, event tracking, WooGuard fraud protection, incomplete checkout capture, and activity logs.
 * Version:     2.0.0
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
define( 'INSIGHT_HUB_VERSION', '2.0.0' );
define( 'INSIGHT_HUB_PLUGIN_FILE', __FILE__ );
define( 'INSIGHT_HUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INSIGHT_HUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'INSIGHT_HUB_API_BASE_URL', 'https://marketpulse.com.bd/api' );

// API Endpoints (Vercel routes)
define( 'INSIGHT_HUB_ENDPOINT_LICENSE_ACTIVATE', '/license/activate' );
define( 'INSIGHT_HUB_ENDPOINT_LICENSE_DEACTIVATE', '/license/deactivate' );
define( 'INSIGHT_HUB_ENDPOINT_LICENSE_VALIDATE', '/license/validate' );
define( 'INSIGHT_HUB_ENDPOINT_LICENSE_HEARTBEAT', '/license/heartbeat' );
define( 'INSIGHT_HUB_ENDPOINT_EVENTS_INGEST', '/events/ingest' );
define( 'INSIGHT_HUB_ENDPOINT_ACTIVITY_LOGS', '/activity-logs/ingest' );
define( 'INSIGHT_HUB_ENDPOINT_SITE_HEALTH_SYNC', '/site-health/sync' );
define( 'INSIGHT_HUB_ENDPOINT_AUDIT_LIGHTHOUSE', '/audit/lighthouse-result' );
define( 'INSIGHT_HUB_ENDPOINT_REMOTE_ACTIONS_PULL', '/remote-actions/pull' );
define( 'INSIGHT_HUB_ENDPOINT_REMOTE_ACTIONS_REPORT', '/remote-actions/report' );
define( 'INSIGHT_HUB_ENDPOINT_WOO_GUARD_REPORT', '/woo-guard/report' );
define( 'INSIGHT_HUB_ENDPOINT_ORDER_WEBHOOK', '/orders/webhook' );

// Option keys
define( 'INSIGHT_HUB_OPTION_LICENSE_KEY', 'insight_hub_license_key' );
define( 'INSIGHT_HUB_OPTION_LICENSE_STATUS', 'insight_hub_license_status' );
define( 'INSIGHT_HUB_OPTION_LAST_HEARTBEAT', 'insight_hub_last_heartbeat' );
define( 'INSIGHT_HUB_OPTION_ACTIVATION_DATA', 'insight_hub_activation_data' );
define( 'INSIGHT_HUB_OPTION_INSTALLATION_ID', 'insight_hub_installation_id' );
define( 'INSIGHT_HUB_OPTION_FEATURE_EVENT_TRACKING', 'insight_hub_feature_event_tracking' );
define( 'INSIGHT_HUB_OPTION_FEATURE_ACTIVITY_LOGS', 'insight_hub_feature_activity_logs' );
define( 'INSIGHT_HUB_OPTION_FEATURE_SITE_HEALTH', 'insight_hub_feature_site_health' );
define( 'INSIGHT_HUB_OPTION_FEATURE_WC_GUARD', 'insight_hub_feature_wc_guard' );
define( 'INSIGHT_HUB_OPTION_FEATURE_IMAGE_OPT', 'insight_hub_feature_image_opt' );
define( 'INSIGHT_HUB_OPTION_FEATURE_REMOTE_ACTIONS', 'insight_hub_feature_remote_actions' );
define( 'INSIGHT_HUB_OPTION_GA4_MEASUREMENT_ID', 'insight_hub_ga4_measurement_id' );
define( 'INSIGHT_HUB_OPTION_GA4_PROPERTY_ID', 'insight_hub_ga4_property_id' );

// Cron hooks
define( 'INSIGHT_HUB_CRON_HEARTBEAT', 'insight_hub_heartbeat' );

// Capabilities
define( 'INSIGHT_HUB_CAPABILITY_MANAGE', 'manage_options' );

// Include classes in order
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-sync-queue.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-api-client.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-license-manager.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-cron-manager.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-event-tracker.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-activity-logger.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-site-health-collector.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-woocommerce-guard.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-image-optimization-service.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-seo-security-collector.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-remote-actions-manager.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-ga4-tracker.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-data-sync.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-order-sync.php';
require_once INSIGHT_HUB_PLUGIN_DIR . 'includes/class-incomplete-checkout.php';

// Deactivation hook for sync queue cleanup
register_deactivation_hook( INSIGHT_HUB_PLUGIN_FILE, [ 'MarketPulse_Sync_Queue', 'deactivate' ] );

// Bootstrap the plugin
class InsightHub_Plugin {

    /**
     * Initialize the plugin
     */
    public static function init() {
        // Initialize sync queue (registers cron, hooks)
        MarketPulse_Sync_Queue::init();

        // Load textdomain
        load_plugin_textdomain( 'marketpulse', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Initialize components
        new \InsightHub\Admin_Settings();
        new \InsightHub\Cron_Manager();
        new \InsightHub\Event_Tracker();
        new \InsightHub\Activity_Logger();
        new \InsightHub\Site_Health_Collector();
        new \InsightHub\WooCommerce_Guard();
        new \InsightHub\Image_Optimization_Service();
        new \InsightHub\SEO_Security_Collector();
        new \InsightHub\Remote_Actions_Manager();
        new \InsightHub\GA4_Tracker();
        new \InsightHub\Data_Sync();
        new \InsightHub\Order_Sync();
        new \InsightHub\Incomplete_Checkout();

        // Hook for activation
        register_activation_hook( INSIGHT_HUB_PLUGIN_FILE, array( __CLASS__, 'activate' ) );

        // Hook for deactivation
        register_deactivation_hook( INSIGHT_HUB_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Generate installation ID if not exists
        if ( ! get_option( INSIGHT_HUB_OPTION_INSTALLATION_ID ) ) {
            update_option( INSIGHT_HUB_OPTION_INSTALLATION_ID, wp_generate_uuid4() );
        }

        // Schedule heartbeat
        if ( ! wp_next_scheduled( INSIGHT_HUB_CRON_HEARTBEAT ) ) {
            wp_schedule_event( time(), 'hourly', INSIGHT_HUB_CRON_HEARTBEAT );
        }
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( INSIGHT_HUB_CRON_HEARTBEAT );
    }

}

// Initialize the plugin on plugins_loaded
add_action( 'plugins_loaded', array( 'InsightHub_Plugin', 'init' ) );
