# Insight Hub WordPress Plugin

A comprehensive WordPress plugin that connects sites to the Insight Hub SaaS dashboard for license activation, event tracking, activity logging, site health monitoring, and more.

## Features

### Core Features
- **License Activation**: Secure license management with domain validation
- **Event Tracking**: Real-time tracking of WordPress events (posts, media, users, plugins, themes)
- **Activity Logging**: Detailed admin and site activity monitoring
- **Site Health Sync**: Automatic collection and sync of site health data
- **Heartbeat System**: Regular connectivity checks with the dashboard

### Advanced Features (In Development)
- **WooCommerce Integration**: Order monitoring and restrictions
- **Image Optimization**: Auto WebP/AVIF conversion and bulk optimization
- **SEO/Security Monitoring**: Collection of optimization and security signals
- **Remote Actions**: Safe allowlisted actions from the dashboard

## Architecture

The plugin follows a modular, OOP architecture with namespaced classes:

- `API_Client`: Handles all communication with the SaaS dashboard
- `License_Manager`: Manages license activation/deactivation/validation
- `Admin_Settings`: Provides the admin interface for configuration
- `Cron_Manager`: Handles scheduled tasks like heartbeats
- `Event_Tracker`: Tracks WordPress events
- `Activity_Logger`: Logs admin and site activities
- `Site_Health_Collector`: Collects and syncs site health data
- `WooCommerce_Guard`: Handles WooCommerce-specific features (placeholder)
- `Image_Optimization_Service`: Manages image optimization (placeholder)
- `SEO_Security_Collector`: Collects SEO and security signals (placeholder)
- `Remote_Actions_Manager`: Handles remote actions (placeholder)

## Installation

1. Upload the `insight-hub` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Go to **Insight Hub** in the admin menu
4. Enter your license key and activate

## Configuration

The API base URL is configured in `includes/class-config.php`. Currently set to:
```
https://insight-hub-one.vercel.app
```

## Security

- All API requests include license key authentication
- Nonce verification for admin actions
- Capability checks for user permissions
- Sanitization and validation of all inputs
- No arbitrary code execution in remote actions

## Development Status

- ✅ Phase 1: Plugin scaffold, settings, API client, license activation
- ✅ Phase 2: Event tracker, activity logs, site health sync
- 🟡 Phase 3: WooCommerce guard (placeholder)
- 🟡 Phase 4: Image optimization module (placeholder)
- 🟡 Phase 5: SEO/security signals (placeholder)
- 🟡 Phase 6: Remote actions (placeholder)

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Active license from Insight Hub

## Changelog

### 1.0.0
- Initial release with core license and monitoring features
- Modular architecture implementation
- Basic event tracking and activity logging
- Site health data collection