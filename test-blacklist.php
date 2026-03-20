<?php
require_once dirname( __DIR__, 3 ) . '/wp-load.php';
header('Content-Type: text/plain');

$blocked_emails = (array) get_option('BIZGROWHUB_wg_blocked_emails', []);
$test_email = 'arifcpam@gmail.com';

echo "Blocked emails: " . print_r($blocked_emails, true) . "\n";
echo "Test email: $test_email\n";
echo "strtolower match: " . (in_array(strtolower($test_email), array_map('strtolower', $blocked_emails), true) ? 'YES BLOCKED' : 'NOT BLOCKED') . "\n";

// Simulate AJAX validation
$_POST['billing_email'] = $test_email;
$_POST['billing_phone'] = '01712345678';
$_POST['action'] = 'woo_guard_validate';

echo "\n--- Simulating validate_order ---\n";
$settings = \BizGrowHub\WooCommerce_Guard::get_settings();
echo "Settings enabled: " . ($settings['enabled'] ? 'yes' : 'no') . "\n";
echo "Blocked emails from settings: " . print_r($settings['blocked_emails'], true) . "\n";

$email = sanitize_email($test_email);
$blocked = (array) $settings['blocked_emails'];
echo "Sanitized email: $email\n";
echo "In array check: " . (in_array(strtolower($email), array_map('strtolower', $blocked), true) ? 'BLOCKED' : 'NOT BLOCKED') . "\n";
