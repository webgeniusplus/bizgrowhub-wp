<?php
/**
 * Set WooGuard settings. Visit in browser, then delete this file.
 * http://rs-denim-pants.local/wp-content/plugins/bizgrowhub/test-wooguard.php
 */
$wp_load = dirname(__DIR__, 3) . '/wp-load.php';
if (file_exists($wp_load)) {
    require_once $wp_load;
} else {
    die('Cannot find wp-load.php');
}

// Settings — match reference plugin pattern exactly
update_option('BIZGROWHUB_wg_enabled', true);
update_option('BIZGROWHUB_wg_check_phone', true);
update_option('BIZGROWHUB_wg_check_ip', true);
update_option('BIZGROWHUB_wg_check_email', true);
update_option('BIZGROWHUB_wg_time_hours', 24);       // 24 hours window
update_option('BIZGROWHUB_wg_order_limit', 1);        // max 1 order per target per window
update_option('BIZGROWHUB_wg_error_message', 'আপনার অর্ডারটি করা হয়ে গিয়েছে। আমাদের প্রতিনিধি শীঘ্রই আপনাকে কল করবেন।');
update_option('BIZGROWHUB_wg_blocked_emails', []);
update_option('BIZGROWHUB_wg_blocked_ips', []);
update_option('BIZGROWHUB_wg_blocked_phones', []);

echo "<h2>✅ WooGuard Settings Saved!</h2>";
echo "<pre>";
echo "Enabled:      " . (get_option('BIZGROWHUB_wg_enabled') ? 'YES' : 'NO') . "\n";
echo "Check Phone:  " . (get_option('BIZGROWHUB_wg_check_phone') ? 'YES' : 'NO') . "\n";
echo "Check IP:     " . (get_option('BIZGROWHUB_wg_check_ip') ? 'YES' : 'NO') . "\n";
echo "Check Email:  " . (get_option('BIZGROWHUB_wg_check_email') ? 'YES' : 'NO') . "\n";
echo "Time Hours:   " . get_option('BIZGROWHUB_wg_time_hours') . "\n";
echo "Order Limit:  " . get_option('BIZGROWHUB_wg_order_limit') . "\n";
echo "Error Msg:    " . get_option('BIZGROWHUB_wg_error_message') . "\n";
echo "</pre>";

echo "<p>Now try placing 2 orders with the same phone/email/IP within 24h. Second one should be blocked.</p>";
echo "<p><strong>Check WP debug log:</strong> <code>wp-content/debug.log</code> for bizgrowhub WooGuard entries.</p>";
echo "<p style='color:red'><strong>Delete this file after testing!</strong></p>";
