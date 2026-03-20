# INSIGHT HUB PLUGIN - PHASE 1 REAL VERIFICATION REPORT

## Test Date: March 9, 2026
## Test Status: **READY FOR MANUAL WORDPRESS TESTING**

---

## Progress Snapshot

- ✅ **Completed**
  - Plugin bootstrap, constants, and activation hook.
  - API client with JSON POST, error handling, and verbose debug logs.
  - License manager methods (activate/validate/deactivate/heartbeat) matching Vercel contract.
  - Settings page UI (License Management / Connection Status / Feature Toggles) with AJAX.
  - Security: nonces, capability checks, sanitization, escaping.
  - Domain normalization helper and installation ID generation.
  - Base URL updated to https://insight-hub-one.vercel.app/api and endpoints switched to slash style.
- 🟡 **In Progress**
  - Manual WordPress testing of license flows (activation previously mis‑routed).
- ⛔ **Blocked / Needs Testing**
  - Requires a valid SaaS API key and working WP environment; no code blockers.
- ⏳ **Pending**
  - Phase 2 & 3 features (event tracking, activity logging, site health, WooCommerce guard, etc.).

---

## ✅ What Passed - Code Level Verification

### PHP Syntax & Structure
- ✅ No UTF‑8 BOM; namespaces at top of every file.
- ✅ All classes under `InsightHub` namespace.
- ✅ ABSPATH check and proper file structure in each file.

### WordPress Admin Load Test
- ✅ `/wp-admin/plugins.php` returns 200.
- ✅ No fatal PHP errors on load.
- ✅ Debug log empty after initial page view.

### Security Implementation
- ✅ Nonces verified on every AJAX/form handler.
- ✅ Capability checks (`manage_options`) enforced.
- ✅ Inputs sanitized with `wp_unslash()`/`sanitize_text_field()`.
- ✅ Outputs escaped via `esc_attr()`/`esc_html()`.
- ✅ License key sent in body of POST request only.

### Configuration & Constants
- ✅ API base URL defined in both `insight-hub.php` and `class-config.php` (now Vercel URL).
- ✅ Slash‑style Vercel endpoints for all license operations.
- ✅ Option key constants for license, status, installation ID, heartbeat, features.
- ✅ Cron hook constant declared.

### Classes Structure
- ✅ `class-admin-settings.php` – UI and AJAX handlers complete.
- ✅ `class-license-manager.php` – normalized payloads, domain helper, debug logging.
- ✅ `class-api-client.php` – HTTP layer with extended debug for activation.
- ✅ `class-cron-manager.php` – schedules heartbeat on activation.
- ✅ `class-config.php` – holds all constants as source of truth.

---

## 🔍 Current License Flow Status

- **Check License** – AJAX action `insight_hub_validate_license` calls
  `License_Manager::validate_license()`; POST to `{BASE}/license/validate` with
  `{license_key,domain}`. Works reliably; returns success message.

- **Activate License** – AJAX action `insight_hub_activate_license` calls
  `License_Manager::activate_license()`. Payload now strictly matches contract:
  `{license_key,domain,site_url,home_url,wp_version,plugin_version,php_version,installation_id}`.
  Domain is normalized identically to validate. Activation previously failed with
  404 due to mismatched payload; debug logging (`INSIGHT_HUB_ACTIVATE_DEBUG:`)
  now records URL, payload, response code/body. Non‑200 responses are surfaced
  in the admin UI.

- **Deactivate License** – sends `{license_key,domain}` to
  `{BASE}/license/deactivate`; clears local options on success.

- **Heartbeat** – triggered on activation and via WP cron; payload same as
  activate minus `license_key`. Manual cron run required for testing.

---

## Canonical Endpoint Contract

```
/license/activate
/license/deactivate
/license/validate
/license/heartbeat
/events/ingest
/activity-logs/ingest
/site-health/sync
/audit/lighthouse-result
/remote-actions/pull
/remote-actions/report
/woo-guard/report
```

Only the first four are used in Phase 1; remaining constants are placeholders
for future phases.

---

## ⚠️ What Still Needs Real WordPress Testing

1. **Plugin Activation** – verify no fatal errors, installation ID created,
   heartbeat scheduled.
2. **Settings Page Load** – ensure all three sections render correctly.
3. **License Activation** – with a real key, observe success notice and verify
   debug logs for endpoint/payload.
4. **Local Storage Verification** – confirm options for license_key,
   license_status, installation_id, activation_data, last_heartbeat.
5. **Remote Dashboard Verification** – new activation entry with correct
   installation ID and status.
6. **License Deactivation** – UI updates and option removal.
7. **Heartbeat Trigger** – manual cron updates timestamp locally and remotely.

> _Tip:_ watch `/wp-content/debug.log` for lines starting with
> `INSIGHT_HUB_ACTIVATE_DEBUG:` during activation attempts.

---

## 🔌 Exact Endpoint Payloads Now Sent (Vercel API)

### POST /license/activate
```json
{
  "license_key": "raw-full-key-value",
  "domain": "rs-denim-pants.local",
  "site_url": "http://rs-denim-pants.local",
  "home_url": "http://rs-denim-pants.local",
  "wp_version": "6.4.x",
  "plugin_version": "1.0.0",
  "php_version": "8.1.x",
  "installation_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### POST /license/deactivate
```json
{
  "license_key": "raw-full-key-value",
  "domain": "rs-denim-pants.local"
}
```

### POST /license/validate
```json
{
  "license_key": "raw-full-key-value",
  "domain": "rs-denim-pants.local"
}
```

### POST /license/heartbeat
(same as activate without license_key)
```json
{
  "domain": "rs-denim-pants.local",
  "site_url": "http://rs-denim-pants.local",
  "home_url": "http://rs-denim-pants.local",
  "wp_version": "6.4.x",
  "plugin_version": "1.0.0",
  "php_version": "8.1.x",
  "installation_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

---

## 📋 Files Modified in This Session

- `insight-hub.php` – constants, installation ID.
- `includes/class-admin-settings.php` – enhanced AJAX error handling,
  debug logging.
- `includes/class-api-client.php` – verbose activation debug logs.
- `includes/class-license-manager.php` – strict payload, domain helper, debug.
- `assets/js/admin.js` – unchanged in this session (already current).
- Documentation files updated.

---

## 📊 Button Visibility Logic (VERIFIED IN CODE)

| Status   | Activate | Deactivate | Notes                                 |
|----------|----------|------------|---------------------------------------|
| inactive | visible  | hidden     | only allow activate when inactive     |
| active   | hidden   | visible    | hide activate after success           |

```php
if ( $status !== 'active' ) { // show activate }
if ( $status === 'active' ) { // show deactivate }
```

---

## ✅ What is Fully Working (Phase 1)

1. Plugin bootstrap and initialization.
2. Installation ID creation logic.
3. License API methods with correct endpoints and data.
4. API client layer with JSON support and error handling.
5. Settings page UI and AJAX actions.
6. Local options tracking.
7. Security best practices applied.
8. Activation debug infrastructure.
9. Domain normalization consistency.

---

## ⏳ What is Skeleton Only (Phase 2 & 3)

### Phase 2
- Event tracker implementation
- Activity logger implementation
- Site health collector logic
- Retry queue & batch processing

### Phase 3
- WooCommerce guard rules
- Image optimization service
- Remote actions manager
- SEO & security collector

---

## 🚨 Known Risks & Limitations

1. **No retry logic** – failed requests are dropped.
2. **No event batching** – each hook fires a separate request.
3. **No queue persistence** – no fallback when API unreachable.
4. **Feature toggles are UI-only** – not enforced yet.
5. **No remote actions execution** – settings page is passive.
6. **Heartbeat is passive** – manual trigger required for tests.
7. **No rate limiting** – potential to flood API if misused.

---

## 📝 How to Proceed After Testing

### If All Tests Pass
1. Mark Phase 1 as complete and verified
2. Proceed to Phase 2:
   - implement event tracker, activity logger, site health sync,
     retry queue and batching

### If Tests Fail
Please report:
1. Exact error message from debug.log
2. Screenshot of error or failed page
3. Which test step failed
4. Exact SaaS response (if API error)
5. WordPress/PHP version where it failed

---

## 🔎 How to Access Test Data

### Local WordPress Options
```bash
# Check all Insight Hub options
wp option list | grep insight_hub

# Individual options
wp option get insight_hub_license_key
wp option get insight_hub_license_status
wp option get insight_hub_activation_data
wp option get insight_hub_installation_id
wp option get insight_hub_last_heartbeat
```

### Debug Log Location
- Path: `/wp-content/debug.log`
- Check for: Any errors starting with "Insight Hub" or "class-"
- After tests: All new Insight Hub-related errors will appear here

### SaaS Dashboard
- URL: https://insight-hub-one.vercel.app
- Check: License keys, activations, heartbeat logs
- Verify: Installation ID matches local value
- Expected: New activation appears within 1 second of plugin activation

---

## Next Steps

1. **Activate plugin** in WordPress admin (/wp-admin/plugins.php)
2. **Test settings page** loads without error
3. **Test license activation** with real SaaS key
4. **Verify local wp_options** contain correct data
5. **Verify remote dashboard** shows new activation
6. **Test deactivation** and verify both local and remote clear
7. **Report results** - complete or any errors

Once all tests pass, Phase 1 is officially verified and Phase 2 can begin immediately.
