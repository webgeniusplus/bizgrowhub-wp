# PageSpeed API Integration - Changelog

## Date: March 30, 2026

## Summary
WordPress plugin থেকে PageSpeed Insights API key frontend theke remove kore backend environment variable এ move করা হয়েছে। Security improve করার জন্য GET method POST method এ convert করা হয়েছে।

---

## Changes Made

### 1. **REST API Method Changed: GET → POST**
**File:** `includes/class-audit-data.php`

**Before:**
```php
register_rest_route( $namespace, '/audit-data', array(
    'methods' => 'GET',
    // ...
) );
```

**After:**
```php
register_rest_route( $namespace, '/audit-data', array(
    'methods' => 'POST',
    // ...
) );
```

**Why:** 
- API keys query parameters এ pass করা unsafe
- POST method sensitive data র জন্য better practice
- Prevents API key exposure in server logs and browser history

---

### 2. **API Key Source Changed: Request Parameter → WordPress Options**
**File:** `includes/class-audit-data.php`

**Before:**
```php
public function get_audit_data( $request ) {
    $pskey = $request->get_param( 'pskey' );
    if ( ! $pskey ) {
        $pskey = $request->get_header( 'X-PageSpeed-Key' );
    }
    return $this->get_audit_data_internal( $pskey );
}
```

**After:**
```php
public function get_audit_data( $request ) {
    // Get PageSpeed API key from WordPress options
    $pskey = get_option( 'bizgrowhub_pagespeed_api_key', '' );
    
    return $this->get_audit_data_internal( $pskey );
}
```

**Why:**
- Frontend থেকে API key send করতে হবে না
- Key backend environment এ securely store থাকবে
- Reduces risk of key exposure in network requests

---

### 3. **Settings Page: API Key Input Field Added**
**File:** `includes/class-admin-settings.php`

**New Section Added:**
```php
<div class="mp-card">
    <div class="mp-card-header">🚀 PageSpeed Insights API</div>
    <p style="font-size: 13px; color: var(--mp-text-muted); margin: 0 0 16px 0;">
        Google PageSpeed Insights API key for site performance audits.
        <br>Get your free API key from <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank">Google Cloud Console</a>.
    </p>
    <form method="post" id="pagespeed-settings-form">
        <?php wp_nonce_field( 'BIZGROWHUB_pagespeed_settings', '_wpnonce_pagespeed' ); ?>
        <div style="margin-bottom: 16px;">
            <input type="text" 
                   name="pagespeed_api_key" 
                   id="pagespeed_api_key"
                   class="mp-key-input"
                   placeholder="AIzaSyBv8iEJbytxn2gj7qIsxGAPOen8T2sCF8"
                   value="<?php echo esc_attr( get_option( 'bizgrowhub_pagespeed_api_key', '' ) ); ?>" 
                   style="width: 100%; max-width: 500px;" />
        </div>
        <button type="submit" name="save_pagespeed" class="mp-btn mp-btn-primary">Save API Key</button>
    </form>
</div>
```

**Features:**
- WordPress admin settings page এ নতুন section
- PageSpeed API key securely save করার option
- User-friendly interface with instructions
- Link to Google Cloud Console for API key generation

---

### 4. **Form Handler Added**
**File:** `includes/class-admin-settings.php`

**Added in `handle_form()` method:**
```php
// Handle PageSpeed API key form
if ( isset( $_POST['save_pagespeed'] ) && isset( $_POST['_wpnonce_pagespeed'] ) ) {
    if ( ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce_pagespeed'] ), 'BIZGROWHUB_pagespeed_settings' ) ) {
        wp_die( __( 'Security check failed.', 'bizgrowhub' ) );
    }

    if ( ! current_user_can( BIZGROWHUB_CAPABILITY_MANAGE ) ) {
        wp_die( __( 'You do not have permission to do this.', 'bizgrowhub' ) );
    }

    $api_key = isset( $_POST['pagespeed_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pagespeed_api_key'] ) ) : '';
    update_option( 'bizgrowhub_pagespeed_api_key', $api_key );

    wp_redirect( admin_url( 'admin.php?page=bizgrowhub&saved=1' ) );
    exit;
}
```

**Features:**
- Nonce verification for security
- Permission check (only admins can save)
- Sanitization of API key input
- Success redirect with feedback message

---

### 5. **Test Script Updated**
**File:** `test-audit-endpoint.php`

**Before:**
```php
$response = wp_remote_get( $url, $args );
```

**After:**
```php
$args = array(
    'method' => 'POST',
    'headers' => array(
        'X-License-Key' => $license_key,
        'Content-Type' => 'application/json',
    ),
    'body' => wp_json_encode( array() ),
);

$response = wp_remote_post( $url, $args );
```

---

## Usage Instructions

### For WordPress Admin:

1. **Navigate to Settings:**
   - Go to: `WordPress Admin → BizGrowHub → Connection`
   
2. **Add PageSpeed API Key:**
   - Scroll to "🚀 PageSpeed Insights API" section
   - Get your free API key from: https://developers.google.com/speed/docs/insights/v5/get-started
   - Enter the key in the input field
   - Click "Save API Key"

3. **Verify:**
   - Key will be stored in WordPress options as `bizgrowhub_pagespeed_api_key`
   - Dashboard will now automatically fetch PageSpeed data during audits

---

### For Dashboard/API Integration:

**Endpoint:**
```
POST https://your-site.com/wp-json/bizgrowhub/v1/audit-data
```

**Headers:**
```
X-License-Key: your-license-key
Content-Type: application/json
```

**Body:**
```json
{}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "wordpress": {...},
    "woocommerce": {...},
    "plugins": {...},
    "pagespeed": {
      "mobile": {
        "scores": {
          "performance": 85,
          "seo": 95,
          "accessibility": 90,
          "best_practices": 88
        },
        "metrics": {
          "FCP": 1200,
          "LCP": 2400,
          "CLS": 0.05,
          "TBT": 150,
          "SI": 1800,
          "TTFB": 200
        }
      },
      "desktop": {...}
    }
  }
}
```

---

## Security Improvements

1. ✅ **API Key Not Exposed in URLs**
   - Query parameters removed (`?pskey=xxx`)
   - No browser history/log exposure

2. ✅ **POST Method for Sensitive Data**
   - Industry standard for sensitive operations
   - Better CSRF protection

3. ✅ **Backend Storage**
   - Key stored in WordPress options table
   - Only admin users can modify
   - Not accessible via frontend

4. ✅ **Nonce Verification**
   - CSRF protection on form submission
   - Prevents unauthorized API key changes

---

## Testing

### Local Testing:
```bash
# Upload test-audit-endpoint.php to WordPress root
# Visit: https://your-site.local/test-audit-endpoint.php
# Enter license key and test
```

### Production Testing:
```bash
curl -X POST https://rsdenimpants.shop/wp-json/bizgrowhub/v1/audit-data \
  -H "X-License-Key: your-license-key" \
  -H "Content-Type: application/json" \
  -d '{}'
```

---

## Migration Notes

### For Dashboard Integration:
1. Update API calls from `GET` to `POST`
2. Remove `?pskey=xxx` from URLs
3. No need to send API key in request anymore
4. Key will be automatically fetched from WordPress options

### For Users:
1. Go to BizGrowHub settings in WordPress admin
2. Add PageSpeed API key once
3. All future audits will use that key automatically

---

## WordPress Options Created

| Option Name | Description | Type |
|------------|-------------|------|
| `bizgrowhub_pagespeed_api_key` | Google PageSpeed Insights API key | string |

---

## Files Modified

1. ✅ `includes/class-audit-data.php` — API endpoint method changed + key source updated
2. ✅ `includes/class-admin-settings.php` — Settings page UI + form handler added
3. ✅ `test-audit-endpoint.php` — Test script updated for POST method

---

## Next Steps

- [ ] Test on live site (rsdenimpants.shop)
- [ ] Update dashboard integration code
- [ ] Add API key validation on save (optional)
- [ ] Add cron job to refresh PageSpeed data periodically (optional)

---

**Updated by:** Agent  
**Date:** March 30, 2026 04:15 AM (Asia/Dhaka)
