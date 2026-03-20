<?php
/**
 * Plugin Name: BizGrowHub
 * Plugin URI: https://bizgrowhub.shop
 * Description: Connects your WordPress/WooCommerce store to BizGrowHub dashboard for license management, event tracking, activity logs, site health monitoring, and remote actions.
 * Version: 1.0.0
 * Author: BizGrowHub
 * License: GPL v2 or later
 * Text Domain: bizgrowhub
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'BIZGROWHUB_VERSION', '1.1.0' );
define( 'BIZGROWHUB_PLUGIN_FILE', __FILE__ );
define( 'BIZGROWHUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZGROWHUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BIZGROWHUB_API_BASE_URL', 'https://bizgrowhub.shop/api' );

// API Endpoints (Vercel routes)
define( 'BIZGROWHUB_ENDPOINT_LICENSE_ACTIVATE', '/license/activate' );
define( 'BIZGROWHUB_ENDPOINT_LICENSE_DEACTIVATE', '/license/deactivate' );
define( 'BIZGROWHUB_ENDPOINT_LICENSE_VALIDATE', '/license/validate' );
define( 'BIZGROWHUB_ENDPOINT_LICENSE_HEARTBEAT', '/license/heartbeat' );
define( 'BIZGROWHUB_ENDPOINT_EVENTS_INGEST', '/events/ingest' );
define( 'BIZGROWHUB_ENDPOINT_ACTIVITY_LOGS', '/activity-logs/ingest' );
define( 'BIZGROWHUB_ENDPOINT_SITE_HEALTH_SYNC', '/site-health/sync' );
define( 'BIZGROWHUB_ENDPOINT_AUDIT_LIGHTHOUSE', '/audit/lighthouse-result' );
define( 'BIZGROWHUB_ENDPOINT_REMOTE_ACTIONS_PULL', '/remote-actions/pull' );
define( 'BIZGROWHUB_ENDPOINT_REMOTE_ACTIONS_REPORT', '/remote-actions/report' );
define( 'BIZGROWHUB_ENDPOINT_WOO_GUARD_REPORT', '/woo-guard/report' );
define( 'BIZGROWHUB_ENDPOINT_ORDER_WEBHOOK', '/orders/webhook' );

// Option keys
define( 'BIZGROWHUB_OPTION_LICENSE_KEY', 'BIZGROWHUB_license_key' );
define( 'BIZGROWHUB_OPTION_LICENSE_STATUS', 'BIZGROWHUB_license_status' );
define( 'BIZGROWHUB_OPTION_LAST_HEARTBEAT', 'BIZGROWHUB_last_heartbeat' );
define( 'BIZGROWHUB_OPTION_ACTIVATION_DATA', 'BIZGROWHUB_activation_data' );
define( 'BIZGROWHUB_OPTION_INSTALLATION_ID', 'BIZGROWHUB_installation_id' );
define( 'BIZGROWHUB_OPTION_FEATURE_EVENT_TRACKING', 'BIZGROWHUB_feature_event_tracking' );
define( 'BIZGROWHUB_OPTION_FEATURE_ACTIVITY_LOGS', 'BIZGROWHUB_feature_activity_logs' );
define( 'BIZGROWHUB_OPTION_FEATURE_SITE_HEALTH', 'BIZGROWHUB_feature_site_health' );
define( 'BIZGROWHUB_OPTION_FEATURE_WC_GUARD', 'BIZGROWHUB_feature_wc_guard' );
define( 'BIZGROWHUB_OPTION_FEATURE_IMAGE_OPT', 'BIZGROWHUB_feature_image_opt' );
define( 'BIZGROWHUB_OPTION_FEATURE_REMOTE_ACTIONS', 'BIZGROWHUB_feature_remote_actions' );
define( 'BIZGROWHUB_OPTION_GA4_MEASUREMENT_ID', 'BIZGROWHUB_ga4_measurement_id' );
define( 'BIZGROWHUB_OPTION_GA4_PROPERTY_ID', 'BIZGROWHUB_ga4_property_id' );

// Cron hooks
define( 'BIZGROWHUB_CRON_HEARTBEAT', 'BIZGROWHUB_heartbeat' );

// Capabilities
define( 'BIZGROWHUB_CAPABILITY_MANAGE', 'manage_options' );

// Include classes in order
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-api-client.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-license-manager.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-cron-manager.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-event-tracker.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-activity-logger.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-site-health-collector.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-woocommerce-guard.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-image-optimization-service.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-seo-security-collector.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-remote-actions-manager.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-ga4-tracker.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-data-sync.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-order-sync.php';
require_once BIZGROWHUB_PLUGIN_DIR . 'includes/class-incomplete-checkout.php';

// Bootstrap the plugin
class BizGrowHub_Plugin {

    /**
     * Initialize the plugin
     */
    public static function init() {
        // Load textdomain
        load_plugin_textdomain( 'bizgrowhub', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Initialize components
        new \BizGrowHub\Admin_Settings();
        new \BizGrowHub\Cron_Manager();
        new \BizGrowHub\Event_Tracker();
        new \BizGrowHub\Activity_Logger();
        new \BizGrowHub\Site_Health_Collector();
        error_log('bizgrowhub: About to init WooCommerce_Guard...');
        new \BizGrowHub\WooCommerce_Guard();
        error_log('bizgrowhub: WooCommerce_Guard initialized');
        new \BizGrowHub\Image_Optimization_Service();
        new \BizGrowHub\SEO_Security_Collector();
        new \BizGrowHub\Remote_Actions_Manager();
        new \BizGrowHub\GA4_Tracker();
        new \BizGrowHub\Data_Sync();
        new \BizGrowHub\Order_Sync();
        new \BizGrowHub\Incomplete_Checkout();

        // Hook for activation
        register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );

        // Hook for deactivation
        register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Generate installation ID if not exists
        if ( ! get_option( BIZGROWHUB_OPTION_INSTALLATION_ID ) ) {
            update_option( BIZGROWHUB_OPTION_INSTALLATION_ID, wp_generate_uuid4() );
        }

        // Schedule heartbeat
        if ( ! wp_next_scheduled( BIZGROWHUB_CRON_HEARTBEAT ) ) {
            wp_schedule_event( time(), 'hourly', BIZGROWHUB_CRON_HEARTBEAT );
        }
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( BIZGROWHUB_CRON_HEARTBEAT );
    }

}

// Initialize the plugin on plugins_loaded
add_action( 'plugins_loaded', array( 'BizGrowHub_Plugin', 'init' ) );
