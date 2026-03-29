<?php
/**
 * Test Script for Audit Data Endpoint
 * 
 * Usage: 
 * 1. Upload this file to your WordPress root
 * 2. Visit: https://your-site.local/test-audit-endpoint.php
 * 3. Enter your license key
 */

// WordPress bootstrap
require_once __DIR__ . '/wp-load.php';

// Get license key from query param or show form
$license_key = $_GET['license_key'] ?? '';

if ( empty( $license_key ) ) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>BizGrowHub Audit Endpoint Test</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
            input[type="text"] { width: 100%; padding: 10px; font-size: 16px; margin: 10px 0; }
            button { padding: 10px 20px; font-size: 16px; background: #0073aa; color: white; border: none; cursor: pointer; }
            button:hover { background: #005177; }
            pre { background: #f5f5f5; padding: 15px; overflow-x: auto; }
        </style>
    </head>
    <body>
        <h1>🧪 BizGrowHub Audit Endpoint Test</h1>
        <p>Enter your BizGrowHub license key to test the audit data endpoint:</p>
        <form method="get">
            <input type="text" name="license_key" placeholder="Enter license key" required>
            <button type="submit">Test Endpoint</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Make REST API request (POST method)
$url = rest_url( 'bizgrowhub/v1/audit-data' );
$args = array(
    'method' => 'POST',
    'headers' => array(
        'X-License-Key' => $license_key,
        'Content-Type' => 'application/json',
    ),
    'body' => wp_json_encode( array() ),
);

$response = wp_remote_post( $url, $args );

?>
<!DOCTYPE html>
<html>
<head>
    <title>BizGrowHub Audit Endpoint Test Results</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 50px auto; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 15px; overflow-x: auto; font-size: 12px; }
        button { padding: 10px 20px; font-size: 16px; background: #0073aa; color: white; border: none; cursor: pointer; margin: 10px 0; }
        button:hover { background: #005177; }
    </style>
</head>
<body>
    <h1>🧪 BizGrowHub Audit Endpoint Test Results</h1>
    
    <?php if ( is_wp_error( $response ) ): ?>
        <p class="error">❌ Error: <?php echo esc_html( $response->get_error_message() ); ?></p>
    <?php else: ?>
        <?php
        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( $body, true );
        ?>
        
        <h2>Response Code: <span class="<?php echo $code === 200 ? 'success' : 'error'; ?>"><?php echo esc_html( $code ); ?></span></h2>
        
        <?php if ( $code === 200 && isset( $data['success'] ) && $data['success'] ): ?>
            <p class="success">✅ Endpoint working successfully!</p>
            
            <h3>Summary:</h3>
            <ul>
                <li><strong>WordPress:</strong> <?php echo esc_html( $data['data']['wordpress']['version'] ?? 'N/A' ); ?></li>
                <li><strong>PHP:</strong> <?php echo esc_html( $data['data']['php']['version'] ?? 'N/A' ); ?></li>
                <li><strong>Theme:</strong> <?php echo esc_html( $data['data']['active_theme']['name'] ?? 'N/A' ); ?></li>
                <li><strong>WooCommerce:</strong> <?php echo $data['data']['woocommerce']['is_active'] ? 'Active (' . esc_html( $data['data']['woocommerce']['version'] ) . ')' : 'Not Active'; ?></li>
                <li><strong>Active Plugins:</strong> <?php echo esc_html( $data['data']['plugins']['active'] ?? 0 ); ?></li>
                <li><strong>SSL:</strong> <?php echo $data['data']['security']['ssl_installed'] ? 'Enabled' : 'Disabled'; ?></li>
            </ul>
            
            <h3>Full Response:</h3>
            <pre><?php echo esc_html( json_encode( $data, JSON_PRETTY_PRINT ) ); ?></pre>
        <?php else: ?>
            <p class="error">❌ API Error</p>
            <pre><?php echo esc_html( $body ); ?></pre>
        <?php endif; ?>
        
        <button onclick="window.location.href='?'">Test Again</button>
    <?php endif; ?>
</body>
</html>
