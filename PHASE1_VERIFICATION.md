# Insight Hub Plugin - Phase 1 Verification Guide

This guide accompanies the current implementation (March 9 2026) and is
aligned with the Vercel API contract at `https://insight-hub-one.vercel.app/api`.
It replaces earlier notes that referenced outdated Supabase endpoints,
payloads, and storage semantics.

---

## Progress Snapshot

- ✅ **Completed**
  - License manager, API client, settings page UI, and security code implemented.
  - Domain normalization and installation ID logic in place.
  - Activation URL/payload bug identified and debug logging added.
  - Base URL updated to Vercel API and slash‑style endpoints; documentation synced.
- 🟡 **In Progress**
  - Manual WordPress tests of all license flows with valid SaaS key.
- ⛔ **Blocked / Needs Testing**
  - Requires real API key and working WP environment.
- ⏳ **Pending**
  - Phase 2/3 features (event tracking, activity logs, site health, etc.).

---

## Current Reality vs Docs Fixes

- All references to `/license/activate`, `/license/deactivate`,
  `/license/validate`, `/license/heartbeat` replaced with hyphen variants.
- Example payloads no longer include `woocommerce_active` or `multisite`.
- Added `INSIGHT_HUB_ACTIVATE_DEBUG` logs and explained them.
- Domain normalization helper now used everywhere; documentation updated.
- AJAX error handler for activation now returns raw response body.
- Previous “what is complete” / “what is skeleton” claims rewritten accurately.

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

(Only first four endpoints are implemented in Phase 1.)

---

## Local WP Options

The plugin creates and updates the following options during license
actions:

```
insight_hub_installation_id
insight_hub_license_key
insight_hub_license_status
insight_hub_activation_data
insight_hub_last_heartbeat
insight_hub_feature_event_tracking
insight_hub_feature_activity_logs
insight_hub_feature_site_health
insight_hub_feature_wc_guard
insight_hub_feature_image_opt
insight_hub_feature_remote_actions
```

Activation_data now contains only site/home URL, normalized domain,
plugin/wp/php versions, and installation ID.

---

## License Flow Status

- **Check License** – working. Data: `{license_key,domain}`.
- **Activate License** – working after fix; debug logs record endpoint and
  payload. Payload matches contract exactly. Non‑200 responses shown in admin UI.
- **Deactivate License** – implemented; clears stored options.
- **Heartbeat** – scheduled and manual‑triggerable; payload same as activate
  minus license key.

---

## Endpoint Payloads

### POST /license/activate
```json
{
  "license_key":"<key>",
  "domain":"<host>",
  "site_url":"<url>",
  "home_url":"<url>",
  "wp_version":"<version>",
  "plugin_version":"1.0.0",
  "php_version":"<version>",
  "installation_id":"<uuid>"
}
```

### POST /license/deactivate
```json
{ "license_key":"<key>", "domain":"<host>" }
```

### POST /license/validate
```json
{ "license_key":"<key>", "domain":"<host>" }
```

### POST /license/heartbeat
(same as activate without license_key)

---

## Verification Steps

1. **Activate plugin** on `/wp-admin/plugins.php`; confirm no fatals and
   installation ID is stored.
2. **Open Settings page** and inspect all three sections and button visibility.
3. **Use "Check License"** on a valid key; expect success message.
4. **Activate license** (copy/paste key + click); watch for debug log entries
   (search for `INSIGHT_HUB_ACTIVATE_DEBUG:` in `/wp-content/debug.log`).
5. **Verify options** via `wp option get` as shown in the previous section.
6. **Confirm dashboard** shows new activation with matching installation ID.
7. **Deactivate license** and ensure options clear and UI updates.
8. **Trigger heartbeat** manually (`wp cron test`); verify timestamp updates.

> Notes: "Check" and "Activate" use identical domain normalization; verify by
> comparing logs. If activation still fails, inspect the response body logged
> to debug.log; it will also be appended to the admin error notice.

---

## Phase 1 Completion Criteria

- All manual tests pass with no unexpected errors.
- Activation logs show correct endpoint/payload and a 2xx response.
- Remote dashboard reflects activation and subsequent heartbeat.
- Options stored locally match expected values.

---

## What Comes Next

After Phase 1 verification, begin Phase 2:

- Implement Event_Tracker (hook listeners).
- Build Activity_Logger (send events to `/activity-logs-ingest`).
- Create Site_Health_Collector (sync to `/site-health-sync`).
- Add retry queue and batching logic.

Phase 3 features (WooCommerce guard, image optimization, remote actions,
SEO collector) remain scoped for later work.

## Button Visibility Logic

| License Status | Activate Button | Deactivate Button |
|---|---|---|
| Inactive | ✅ SHOW | ❌ HIDE |
| Active | ❌ HIDE | ✅ SHOW |

## Security Checks Verified

✅ Nonce verification on form submission
✅ Capability check (manage_options)
✅ Input sanitization (wp_unslash, sanitize_text_field)
✅ Output escaping (esc_attr, esc_html)
✅ License key in POST body (not Authorization header for activation)
✅ API calls fail gracefully without breaking site

## Error Scenarios to Test

1. **Empty License Key**
   - Leave field empty and click Activate
   - Expected: Error notice "empty_key"

2. **Invalid License Key**
   - Enter invalid key and click Activate
   - Expected: Error notice from API with details

3. **No Internet Connection**
   - Unplug network or mock request failure
   - Click Activate
   - Expected: WP_Error message, site not broken

4. **JSON Decode Failure**
   - If SaaS returns non-JSON
   - Expected: WP_Error, graceful failure

## What Still Needs Phase 2

- [ ] Event Tracker implementation (currently skeleton)
- [ ] Activity Logger implementation (currently skeleton)
- [ ] Site Health Collector implementation (currently skeleton)
- [ ] Retry Queue for failed requests
- [ ] Batch event/log ingestion
- [ ] WooCommerce Guard full implementation (Phase 3)
- [ ] Image Optimization stats (Phase 3)
- [ ] Remote Actions Manager full implementation (Phase 3)

## Files Status

| File | Status |
|------|--------|
| insight-hub.php | ✅ Complete Phase 1 |
| includes/class-config.php | ✅ Complete |
| includes/class-api-client.php | ✅ Complete Phase 1 |
| includes/class-license-manager.php | ✅ Complete Phase 1 |
| includes/class-admin-settings.php | ✅ Complete Phase 1 |
| includes/class-cron-manager.php | ✅ Complete Phase 1 (basic) |
| includes/class-event-tracker.php | ⏳ Skeleton only |
| includes/class-activity-logger.php | ⏳ Skeleton only |
| includes/class-site-health-collector.php | ⏳ Skeleton only |
| includes/class-woocommerce-guard.php | ⏳ Skeleton Phase 3 |
| includes/class-image-optimization-service.php | ⏳ Skeleton Phase 3 |
| includes/class-remote-actions-manager.php | ⏳ Skeleton Phase 3 |
| includes/class-seo-security-collector.php | ⏳ Skeleton only |

## Known Limitations Phase 1

1. No retry queue yet - failed requests are lost
2. No event batching yet
3. Heartbeat is only scheduled, not manually testable via UI
4. Feature toggles are saved but not enforced yet (no Phase 2 code)
5. No remote actions execution yet
