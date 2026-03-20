<?php
/**
 * Test script — manually trigger remote actions poll
 * URL: http://rs-denim-pants.local/wp-content/plugins/insight-hub/test-remote-actions.php
 */
require_once dirname( __DIR__, 3 ) . '/wp-load.php';

header( 'Content-Type: application/json' );

if ( ! current_user_can( 'manage_options' ) && ! defined( 'DOING_CRON' ) ) {
    // Allow for testing — remove in production
}

echo "<pre>\n";
echo "=== Remote Actions Test ===\n\n";

// Check license status
$license_key = get_option( 'insight_hub_license_key', '' );
$license_status = get_option( 'insight_hub_license_status', 'inactive' );
echo "License key: " . ( $license_key ? substr( $license_key, 0, 15 ) . '...' : 'NOT SET' ) . "\n";
echo "License status: $license_status\n\n";

// Check API base URL  
echo "API Base: " . INSIGHT_HUB_API_BASE_URL . "\n";
echo "Pull endpoint: " . INSIGHT_HUB_ENDPOINT_REMOTE_ACTIONS_PULL . "\n";
echo "Report endpoint: " . INSIGHT_HUB_ENDPOINT_REMOTE_ACTIONS_REPORT . "\n\n";

// Manually trigger poll_and_execute
echo "--- Triggering poll_and_execute ---\n";
$manager = new \InsightHub\Remote_Actions_Manager();
$manager->poll_and_execute();
echo "\nDone! Check debug.log for details.\n";
echo "</pre>";
