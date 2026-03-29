# 🧪 BizGrowHub Audit Data Endpoint - Testing Guide

## 📍 Endpoint Information

**URL:** `GET /wp-json/bizgrowhub/v1/audit-data`

**Authentication:** License key (via header or query param)

**Purpose:** Returns comprehensive WordPress internal data for site auditing

---

## 🔧 Testing Methods

### Method 1: Browser Test (Easiest)

1. **Upload test script:**
   ```bash
   # Copy test-audit-endpoint.php to your WordPress root
   cp test-audit-endpoint.php /path/to/wordpress/root/
   ```

2. **Visit in browser:**
   ```
   https://your-site.local/test-audit-endpoint.php
   ```

3. **Enter license key and click "Test Endpoint"**

---

### Method 2: cURL Test

#### Basic cURL (with license key in header):

```bash
curl -X GET "https://your-site.local/wp-json/bizgrowhub/v1/audit-data" \
  -H "Content-Type: application/json" \
  -H "X-License-Key: YOUR_LICENSE_KEY_HERE"
```

#### cURL with license key as query param:

```bash
curl -X GET "https://your-site.local/wp-json/bizgrowhub/v1/audit-data?license_key=YOUR_LICENSE_KEY_HERE" \
  -H "Content-Type: application/json"
```

#### cURL with hash authentication:

```bash
# Generate hash first
LICENSE_KEY="your-license-key"
HASH=$(echo -n "$LICENSE_KEY" | sha256sum | awk '{print $1}')

curl -X GET "https://your-site.local/wp-json/bizgrowhub/v1/audit-data" \
  -H "Content-Type: application/json" \
  -H "X-Dashboard-Key-Hash: $HASH"
```

#### Pretty-print JSON response:

```bash
curl -s -X GET "https://your-site.local/wp-json/bizgrowhub/v1/audit-data" \
  -H "X-License-Key: YOUR_LICENSE_KEY_HERE" | jq .
```

---

### Method 3: Postman / Insomnia

**Request Setup:**
- Method: `GET`
- URL: `https://your-site.local/wp-json/bizgrowhub/v1/audit-data`
- Headers:
  - `Content-Type: application/json`
  - `X-License-Key: YOUR_LICENSE_KEY_HERE`

---

### Method 4: JavaScript/Fetch (from BizGrowHub dashboard)

```javascript
const siteUrl = 'https://your-site.local';
const licenseKey = 'your-license-key';

const response = await fetch(`${siteUrl}/wp-json/bizgrowhub/v1/audit-data`, {
  method: 'GET',
  headers: {
    'Content-Type': 'application/json',
    'X-License-Key': licenseKey,
  },
});

const data = await response.json();
console.log('Audit data:', data);
```

---

## ✅ Expected Response

### Success (200 OK):

```json
{
  "success": true,
  "data": {
    "active_theme": {
      "name": "Astra",
      "slug": "astra",
      "version": "4.2.1",
      "author": "Brainstorm Force",
      "is_child": false
    },
    "wordpress": {
      "version": "6.4.1",
      "language": "en_US",
      "timezone": "Asia/Dhaka",
      "site_url": "https://example.com",
      "home_url": "https://example.com"
    },
    "php": {
      "version": "8.1.2",
      "memory_limit": "256M",
      "max_upload_size": "100 MB",
      "execution_time": "300",
      "extensions": ["mysqli", "curl", "gd", "openssl", ...]
    },
    "woocommerce": {
      "is_active": true,
      "version": "8.3.1",
      "store_currency": "BDT",
      "product_count": 245,
      "order_count": 1342,
      "payment_gateways": [
        {
          "id": "bkash",
          "title": "bKash",
          "enabled": true
        },
        ...
      ],
      "shipping_methods": [...]
    },
    "plugins": {
      "total": 15,
      "active": 12,
      "list": [...]
    },
    "security": {
      "ssl_installed": true,
      "debug_mode_enabled": false,
      "wp_debug_log_enabled": false,
      "wp_auto_updates_enabled": true
    },
    "performance": {
      "page_cache_enabled": true,
      "cache_plugin": "WP Super Cache",
      "object_cache_enabled": true,
      "gzip_compression": true
    },
    "database": {
      "type": "MySQL",
      "version": "5.7.35",
      "posts_count": 1500,
      "total_comments": 245,
      "tables_count": 45,
      "database_size_mb": 250.45
    },
    "site_health": {
      "site_health_status": "good",
      "critical_issues": 0,
      "recommended_improvements": 2,
      "last_checked": "2026-03-30 10:30:00"
    },
    "bangladesh_context": {
      "local_payment_methods": ["bkash", "nagad", "rocket"],
      "local_couriers": ["steadfast", "pathao"],
      "bangla_support": true,
      "mobile_optimized": true
    }
  }
}
```

### Error Responses:

#### 403 Forbidden (Invalid license):
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": {
    "status": 403
  }
}
```

#### 500 Internal Server Error:
```json
{
  "success": false,
  "error": "Error message here"
}
```

---

## 🔍 Troubleshooting

### Issue: 403 Forbidden

**Possible causes:**
- Invalid license key
- License not activated
- License expired

**Solution:**
1. Check license key is correct
2. Verify license status: `get_option('BIZGROWHUB_license_status')`
3. Reactivate license from plugin settings

---

### Issue: 500 Internal Server Error

**Possible causes:**
- PHP error in endpoint code
- Missing WordPress function
- Database connection issue

**Solution:**
1. Enable WordPress debug: `define('WP_DEBUG', true);`
2. Check error logs: `wp-content/debug.log`
3. Test endpoint manually in browser

---

### Issue: 404 Not Found

**Possible causes:**
- Permalink structure issue
- REST API disabled
- Plugin not activated

**Solution:**
1. Save permalink settings: `Settings → Permalinks → Save Changes`
2. Check REST API: `GET /wp-json/` should return JSON
3. Verify plugin is active

---

## 📊 Performance Notes

- **Execution Time:** ~200-500ms (depending on site size)
- **Data Size:** ~10-50 KB JSON response
- **Rate Limiting:** None (consider adding in production)

---

## 🔒 Security Notes

- **Authentication Required:** License key must be valid and active
- **No Public Access:** Endpoint requires authentication
- **Sensitive Data:** Contains plugin list, version numbers (security risk if exposed)
- **HTTPS Recommended:** Always use HTTPS in production

---

## 🎯 Integration with BizGrowHub Dashboard

**Example usage in audit worker:**

```typescript
// In BizGrowHub audit worker
const siteUrl = project.wp_site_url;
const licenseKey = project.license_key;

// Fetch backend data from plugin
const backendData = await fetch(`${siteUrl}/wp-json/bizgrowhub/v1/audit-data`, {
  headers: { 'X-License-Key': licenseKey }
});

if (backendData.success) {
  // Combine with frontend data (Lighthouse, HTML parsing)
  const completeAudit = {
    lighthouse: lighthouseData,
    woocommerce_frontend: wcFrontendChecks,
    woocommerce_backend: backendData.data.woocommerce,
    plugins: backendData.data.plugins,
    performance_backend: backendData.data.performance,
    // ...
  };
}
```

---

## ✅ Next Steps

1. Test endpoint locally with license key
2. Integrate into BizGrowHub audit worker
3. Combine backend + frontend data for complete audit
4. Display results in dashboard UI

---

**Last Updated:** March 30, 2026  
**Version:** 1.0.0
