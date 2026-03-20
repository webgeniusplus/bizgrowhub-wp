<?php
/**
 * Admin Settings for Insight Hub
 *
 * @package BizGrowHub
 */

namespace BizGrowHub;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Settings Class
 */
class Admin_Settings {

    /**
     * License Manager instance
     *
     * @var License_Manager
     */
    private $license_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->license_manager = new License_Manager();
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_post_BIZGROWHUB_settings', array( $this, 'handle_form' ) );
        // Feature toggles & GA4 now managed from dashboard — no local form handlers needed
        add_action( 'rest_api_init', array( $this, 'register_settings_rest_routes' ) );
        
        // AJAX handlers
        add_action( 'wp_ajax_BIZGROWHUB_validate_license', array( $this, 'ajax_validate_license' ) );
        add_action( 'wp_ajax_BIZGROWHUB_activate_license', array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_BIZGROWHUB_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
        
        // Enqueue scripts
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'BizGrowHub',
            'BizGrowHub',
            BIZGROWHUB_CAPABILITY_MANAGE,
            'bizgrowhub',
            array( $this, 'settings_page' ),
            'dashicons-store',
            30
        );
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if ( ! current_user_can( BIZGROWHUB_CAPABILITY_MANAGE ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        $license_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
        $status = $this->license_manager->get_license_status();
        $last_heartbeat = $this->license_manager->get_last_heartbeat();
        $is_active = ( $status === 'active' );

        $active_tab = 'connection';

        // Handle notices
        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Features saved.</p></div>';
        }
        if ( isset( $_GET['activated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>License activated successfully.</p></div>';
        }
        if ( isset( $_GET['deactivated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>License deactivated successfully.</p></div>';
        }
        if ( isset( $_GET['error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</p></div>';
        }

        ?>
        <div class="wrap mp-wrap">

            <!-- Banner -->
            <div class="mp-banner">
                <div>
                    <div class="mp-banner-title">&#x1F4CA; BizGrowHub</div>
                    <div class="mp-banner-sub">WooCommerce Intelligence Dashboard</div>
                </div>
                <a href="https://bizgrowhub.shop/dashboard" target="_blank">Open Dashboard &rarr;</a>
            </div>

            <?php if ( $is_active ) : ?>
            <!-- Single tab — features managed from dashboard -->
            <div class="mp-tabs">
                <button class="mp-tab active" data-tab="connection">🌐 Connection</button>
            </div>

            <?php else : ?>
            <!-- License activation card (only when NOT active) -->
            <div class="mp-card">
                <div class="mp-card-header">🔑 Activate Your Site</div>
                <p style="font-size: 13px; color: var(--mp-text-muted); margin: 0 0 16px 0;">
                    Enter your license key to connect this WordPress site to your BizGrowHub dashboard.
                    <br>Generate from <a href="https://bizgrowhub.shop/settings" target="_blank" style="color: var(--mp-accent);">bizgrowhub.shop &rarr; Settings &rarr; Integrations &rarr; License Keys</a>.
                </p>
                <div style="margin-bottom: 16px;">
                    <a href="https://bizgrowhub.shop/settings" target="_blank" class="mp-btn mp-btn-secondary" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;">
                        &#x1F4CA; Open Dashboard to get your key &rarr;
                    </a>
                </div>
                <div class="mp-key-group">
                    <input type="text" id="license_key" name="license_key"
                           class="mp-key-input"
                           placeholder="mk_live_xxxx-xxxx-xxxx-xxxx"
                           value="<?php echo esc_attr( $license_key ); ?>" />
                    <button type="button" id="check-license-btn" class="mp-btn mp-btn-secondary">Check</button>
                </div>
                <div id="license-status-message" class="mp-alert" style="display:none;"></div>
                <div style="margin-top: 16px;">
                    <button type="button" id="activate-license-btn" class="mp-btn mp-btn-primary">Activate License</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $is_active ) : ?>
            <!-- TAB 2: Connection -->
            <div id="tab-connection" class="mp-tab-content active">

                <div class="mp-card">
                    <div class="mp-card-header">&#x1f310; Site Information</div>
                    <div class="mp-info-grid">
                        <span class="mp-info-label">Site URL</span>
                        <span class="mp-info-value"><code><?php echo esc_html( get_site_url() ); ?></code></span>

                        <span class="mp-info-label">Plugin Version</span>
                        <span class="mp-info-value"><code><?php echo esc_html( BIZGROWHUB_VERSION ); ?></code></span>

                        <span class="mp-info-label">WordPress</span>
                        <span class="mp-info-value"><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></span>

                        <span class="mp-info-label">PHP</span>
                        <span class="mp-info-value"><code><?php echo esc_html( phpversion() ); ?></code></span>

                        <span class="mp-info-label">Connection</span>
                        <span class="mp-info-value">
                            <span class="mp-dot mp-dot-green"></span> Connected
                        </span>
                    </div>
                </div>

                <div class="mp-card">
                    <div class="mp-card-header">&#x1f517; Activation Details</div>
                    <div class="mp-info-grid">
                        <?php
                            $project_name  = get_option( 'BIZGROWHUB_project_name', '' );
                            $project_id    = get_option( 'BIZGROWHUB_project_id', '' );
                            $activation_id = get_option( 'BIZGROWHUB_activation_id', '' );
                        ?>
                        <?php if ( $project_name ) : ?>
                            <span class="mp-info-label">Site Name</span>
                            <span class="mp-info-value"><code><?php echo esc_html( $project_name ); ?></code></span>
                        <?php endif; ?>
                        <?php if ( $project_id ) : ?>
                            <span class="mp-info-label">Project ID</span>
                            <span class="mp-info-value"><code><?php echo esc_html( $project_id ); ?></code></span>
                        <?php endif; ?>
                        <?php if ( $activation_id ) : ?>
                            <span class="mp-info-label">Activation ID</span>
                            <span class="mp-info-value"><code><?php echo esc_html( $activation_id ); ?></code></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mp-card">
                    <div class="mp-card-header">🔑 License</div>
                    <div class="mp-info-grid">
                        <span class="mp-info-label">License Key</span>
                        <span class="mp-info-value"><code><?php echo esc_html( substr( $license_key, 0, 12 ) . '••••••••' ); ?></code></span>
                        <span class="mp-info-label">Last Heartbeat</span>
                        <span class="mp-info-value">
                            <?php if ( $last_heartbeat ) : ?>
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_heartbeat ) ); ?>
                            <?php else : ?>
                                Never
                            <?php endif; ?>
                        </span>
                        <span class="mp-info-label"></span>
                        <span class="mp-info-value">
                            <button type="button" id="deactivate-license-btn" class="mp-btn mp-btn-danger">Deactivate License</button>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Features & GA4 managed from BizGrowHub dashboard -->
            <?php endif; ?>


            <!-- WhatsApp Support -->
            <div class="mp-support-card">
                <div class="mp-support-icon"><svg viewBox="0 0 24 24" fill="#25D366" width="22" height="22"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></div>
                <div class="mp-support-text"><span class="mp-support-title">সাপোর্ট দরকার?</span><span class="mp-support-sub">কোনো সমস্যা হলে WhatsApp-এ নক করুন</span></div>
                <a href="https://wa.me/8801743632972" target="_blank" class="mp-btn mp-btn-wa">+880 1743-632972</a>
            </div>
        </div>
        <?php
    }

    /* ================================================================
       REST API — Dashboard pushes feature toggles + GA4 settings
       ================================================================ */

    public function register_settings_rest_routes() {
        $namespace = 'bizgrowhub/v1';

        register_rest_route( $namespace, '/settings/features', [
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'rest_get_features' ],
                'permission_callback' => [ $this, 'check_license_auth' ],
            ],
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'rest_save_features' ],
                'permission_callback' => [ $this, 'check_license_auth' ],
            ],
        ] );

        register_rest_route( $namespace, '/settings/ga4', [
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'rest_get_ga4' ],
                'permission_callback' => [ $this, 'check_license_auth' ],
            ],
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'rest_save_ga4' ],
                'permission_callback' => [ $this, 'check_license_auth' ],
            ],
        ] );
    }

    /**
     * Permission: WP admin OR valid license key
     */
    public function check_license_auth( $request ) {
        if ( current_user_can( 'manage_options' ) ) return true;

        $stored_key = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );

        // License key auth (raw key)
        $license_key = $request->get_header( 'X-License-Key' );
        if ( ! $license_key ) {
            $params = $request->get_json_params();
            $license_key = $params['license_key'] ?? null;
        }
        if ( $license_key && ! empty( $stored_key ) && $license_key === $stored_key ) {
            $status = get_option( BIZGROWHUB_OPTION_LICENSE_STATUS, 'inactive' );
            if ( $status === 'active' ) return true;
        }

        // Key hash auth (dashboard server-to-server)
        $key_hash = $request->get_header( 'X-Dashboard-Key-Hash' );
        if ( ! $key_hash ) {
            $params = $request->get_json_params();
            $key_hash = $params['key_hash'] ?? null;
        }
        if ( $key_hash && ! empty( $stored_key ) ) {
            $stored_hash = hash( 'sha256', $stored_key );
            if ( hash_equals( $stored_hash, $key_hash ) ) return true;
        }

        return new \WP_Error( 'unauthorized', 'Invalid license key or insufficient permissions', [ 'status' => 403 ] );
    }

    /**
     * GET feature toggles
     */
    public function rest_get_features() {
        return new \WP_REST_Response( [ 'success' => true, 'data' => [
            'event_tracking' => get_option( BIZGROWHUB_OPTION_FEATURE_EVENT_TRACKING, '1' ) === '1',
            'activity_logs'  => get_option( BIZGROWHUB_OPTION_FEATURE_ACTIVITY_LOGS, '1' ) === '1',
            'site_health'    => get_option( BIZGROWHUB_OPTION_FEATURE_SITE_HEALTH, '1' ) === '1',
            'wc_guard'       => get_option( BIZGROWHUB_OPTION_FEATURE_WC_GUARD, '1' ) === '1',
            'image_opt'      => get_option( BIZGROWHUB_OPTION_FEATURE_IMAGE_OPT, '1' ) === '1',
            'remote_actions' => get_option( BIZGROWHUB_OPTION_FEATURE_REMOTE_ACTIONS, '1' ) === '1',
        ] ] );
    }

    /**
     * POST feature toggles from dashboard
     */
    public function rest_save_features( $request ) {
        $params = $request->get_json_params();
        $features = $params['features'] ?? $params;

        $map = [
            'event_tracking' => BIZGROWHUB_OPTION_FEATURE_EVENT_TRACKING,
            'activity_logs'  => BIZGROWHUB_OPTION_FEATURE_ACTIVITY_LOGS,
            'site_health'    => BIZGROWHUB_OPTION_FEATURE_SITE_HEALTH,
            'wc_guard'       => BIZGROWHUB_OPTION_FEATURE_WC_GUARD,
            'image_opt'      => BIZGROWHUB_OPTION_FEATURE_IMAGE_OPT,
            'remote_actions' => BIZGROWHUB_OPTION_FEATURE_REMOTE_ACTIONS,
        ];

        foreach ( $map as $key => $option ) {
            if ( isset( $features[ $key ] ) ) {
                update_option( $option, $features[ $key ] ? '1' : '0' );
            }
        }

        error_log( 'bizgrowhub: Feature toggles updated via REST API: ' . print_r( $features, true ) );
        return $this->rest_get_features();
    }

    /**
     * GET GA4 settings
     */
    public function rest_get_ga4() {
        return new \WP_REST_Response( [ 'success' => true, 'data' => [
            'measurement_id' => get_option( BIZGROWHUB_OPTION_GA4_MEASUREMENT_ID, '' ),
            'property_id'    => get_option( BIZGROWHUB_OPTION_GA4_PROPERTY_ID, '' ),
        ] ] );
    }

    /**
     * POST GA4 settings from dashboard
     */
    public function rest_save_ga4( $request ) {
        $params = $request->get_json_params();

        if ( isset( $params['measurement_id'] ) ) {
            update_option( BIZGROWHUB_OPTION_GA4_MEASUREMENT_ID, sanitize_text_field( $params['measurement_id'] ) );
        }
        if ( isset( $params['property_id'] ) ) {
            update_option( BIZGROWHUB_OPTION_GA4_PROPERTY_ID, sanitize_text_field( $params['property_id'] ) );
        }

        error_log( 'bizgrowhub: GA4 settings updated via REST API' );
        return $this->rest_get_ga4();
    }

    /**
     * Handle form submission
     */
    public function handle_form() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'BIZGROWHUB_settings' ) ) {
            wp_die( __( 'Security check failed.', 'bizgrowhub' ) );
        }

        if ( ! current_user_can( BIZGROWHUB_CAPABILITY_MANAGE ) ) {
            wp_die( __( 'You do not have permission to do this.', 'bizgrowhub' ) );
        }

        if ( isset( $_POST['activate'] ) ) {
            $this->handle_activate();
        } elseif ( isset( $_POST['deactivate'] ) ) {
            $this->handle_deactivate();
        }
    }

    /**
     * Handle activate
     */
    private function handle_activate() {
        if ( ! isset( $_POST['license_key'] ) ) {
            wp_redirect( add_query_arg( 'error', 'empty_key', admin_url( 'admin.php?page=bizgrowhub' ) ) );
            exit;
        }
        $license_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ) );

        if ( empty( $license_key ) ) {
            wp_redirect( add_query_arg( 'error', 'empty_key', admin_url( 'admin.php?page=bizgrowhub' ) ) );
            exit;
        }

        $result = $this->license_manager->activate_license( $license_key );

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( 'error', urlencode( $result->get_error_message() ), admin_url( 'admin.php?page=bizgrowhub' ) ) );
            exit;
        } else {
            wp_redirect( admin_url( 'admin.php?page=bizgrowhub&activated=1' ) );
            exit;
        }
    }

    /**
     * Handle deactivate
     */
    private function handle_deactivate() {
        $result = $this->license_manager->deactivate_license();

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( 'error', urlencode( $result->get_error_message() ), admin_url( 'admin.php?page=bizgrowhub' ) ) );
            exit;
        } else {
            wp_redirect( admin_url( 'admin.php?page=bizgrowhub&deactivated=1' ) );
            exit;
        }
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts( $hook ) {
        if ( 'toplevel_page_bizgrowhub' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'BizGrowHub-admin',
            BIZGROWHUB_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BIZGROWHUB_VERSION
        );

        wp_enqueue_script(
            'bizgrowhub-admin',
            BIZGROWHUB_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            BIZGROWHUB_VERSION,
            true
        );

        wp_localize_script( 'bizgrowhub-admin', 'BizGrowHubAjax', array(
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'BIZGROWHUB_ajax' ),
            'site_url'       => get_site_url(),
            'domain'         => wp_parse_url( get_site_url(), PHP_URL_HOST ),
            'plugin_version' => BIZGROWHUB_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => phpversion(),
            'strings'        => array(
                'checking'     => __( 'Checking...', 'bizgrowhub' ),
                'activating'   => __( 'Activating...', 'bizgrowhub' ),
                'deactivating' => __( 'Deactivating...', 'bizgrowhub' ),
                'activate'     => __( 'Activate License', 'bizgrowhub' ),
                'deactivate'   => __( 'Deactivate License', 'bizgrowhub' ),
                'check'        => __( 'Check License', 'bizgrowhub' ),
            ),
        ) );
    }

    /**
     * AJAX handler for license validation
     */
    public function ajax_validate_license() {
        check_ajax_referer( 'BIZGROWHUB_ajax', 'nonce' );

        if ( ! current_user_can( BIZGROWHUB_CAPABILITY_MANAGE ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bizgrowhub' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        if ( empty( $license_key ) ) {
            wp_send_json_error( array( 'message' => __( 'License key is required.', 'bizgrowhub' ) ) );
        }

        $license_manager = new License_Manager();
        $result = $license_manager->validate_license( $license_key );

        if ( is_wp_error( $result ) ) {
            error_log( 'Insight Hub: License validation failed - ' . $result->get_error_message() );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'License is valid.', 'bizgrowhub' ),
            'data'    => $result,
        ) );
    }

    /**
     * AJAX handler for license activation
     */
    public function ajax_activate_license() {
        check_ajax_referer( 'BIZGROWHUB_ajax', 'nonce' );

        if ( ! current_user_can( BIZGROWHUB_CAPABILITY_MANAGE ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bizgrowhub' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        if ( empty( $license_key ) ) {
            wp_send_json_error( array( 'message' => __( 'License key is required.', 'bizgrowhub' ) ) );
        }

        $license_manager = new License_Manager();
        $result = $license_manager->activate_license( $license_key );

        if ( is_wp_error( $result ) ) {
            $msg = $result->get_error_message();
            $body = $result->get_error_data( 'body' );
            if ( $body ) {
                $msg .= ' | response body: ' . $body;
            }
            error_log( 'Insight Hub: License activation failed - ' . $msg );
            wp_send_json_error( array( 'message' => $msg ) );
        }

        wp_send_json_success( array(
            'message' => __( 'License activated successfully.', 'bizgrowhub' ),
            'data'    => $result,
        ) );
    }

    /**
     * AJAX handler for license deactivation
     */
    public function ajax_deactivate_license() {
        check_ajax_referer( 'BIZGROWHUB_ajax', 'nonce' );

        if ( ! current_user_can( BIZGROWHUB_CAPABILITY_MANAGE ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bizgrowhub' ) ) );
        }

        $license_manager = new License_Manager();
        $result = $license_manager->deactivate_license();

        if ( is_wp_error( $result ) ) {
            error_log( 'Insight Hub: License deactivation failed - ' . $result->get_error_message() );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'License deactivated successfully.', 'bizgrowhub' ),
            'data'    => $result,
        ) );
    }

}
