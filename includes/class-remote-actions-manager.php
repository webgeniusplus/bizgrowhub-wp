<?php
namespace MarketPulse;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Remote_Actions_Manager {

    private $api_client;

    private $allowlisted_actions = array(
        'clear_transients',
        'clear_cache',
        'cleanup_revisions',
        'refresh_site_health',
        'update_plugins',
        'disable_xmlrpc',
        'fix_file_permissions',
        'remove_inactive_plugins',
        'fix_alt_text',
        'flush_rewrite_rules',
        'regenerate_image_sizes',
        'run_image_optimization',
        'update_seo_defaults',
    );

    public function __construct() {
        $this->api_client = new API_Client();

        if ( get_option( MARKETPULSE_OPTION_FEATURE_REMOTE_ACTIONS, '1' ) !== '1' ) {
            return;
        }

        add_action( MARKETPULSE_CRON_HEARTBEAT, array( $this, 'poll_and_execute' ) );
    }

    /**
     * Poll for pending actions and execute them
     */
    public function poll_and_execute() {
        if ( ! $this->api_client->is_active() ) {
            return;
        }

        $response = $this->api_client->make_request( MARKETPULSE_ENDPOINT_REMOTE_ACTIONS_PULL );

        if ( is_wp_error( $response ) || empty( $response['actions'] ) ) {
            return;
        }

        $results = array();

        foreach ( $response['actions'] as $action ) {
            $action_name = $action['action_type'] ?? $action['action'] ?? '';
            $action_id   = $action['id'] ?? '';
            $params      = $action['action_params'] ?? $action['params'] ?? array();
            if ( is_string( $params ) ) {
                $params = json_decode( $params, true ) ?: array();
            }

            if ( ! in_array( $action_name, $this->allowlisted_actions, true ) ) {
                $results[] = array(
                    'action_id' => $action_id,
                    'action'    => $action_name,
                    'status'    => 'rejected',
                    'message'   => 'Action not allowlisted',
                );
                continue;
            }

            try {
                $result = $this->execute_action( $action_name, $params );
                $results[] = array(
                    'action_id' => $action_id,
                    'action'    => $action_name,
                    'status'    => 'success',
                    'message'   => $result,
                );
            } catch ( \Exception $e ) {
                $results[] = array(
                    'action_id' => $action_id,
                    'action'    => $action_name,
                    'status'    => 'error',
                    'message'   => $e->getMessage(),
                );
            }
        }

        if ( ! empty( $results ) ) {
            $this->api_client->make_request( MARKETPULSE_ENDPOINT_REMOTE_ACTIONS_REPORT, array(
                'results' => $results,
            ) );
        }
    }

    private function execute_action( $action_name, $params = array() ) {
        switch ( $action_name ) {
            case 'clear_transients':
                return $this->clear_transients();
            case 'clear_cache':
                return $this->clear_cache();
            case 'cleanup_revisions':
                return $this->cleanup_revisions();
            case 'refresh_site_health':
                return $this->refresh_site_health();
            case 'update_plugins':
                return $this->update_plugins();
            case 'disable_xmlrpc':
                return $this->disable_xmlrpc();
            case 'fix_file_permissions':
                return $this->fix_file_permissions();
            case 'remove_inactive_plugins':
                return $this->remove_inactive_plugins();
            case 'fix_alt_text':
                return $this->fix_alt_text( $params );
            case 'flush_rewrite_rules':
                return $this->flush_rewrite_rules();
            case 'regenerate_image_sizes':
                return $this->regenerate_image_sizes();
            case 'run_image_optimization':
                return $this->run_image_optimization();
            case 'update_seo_defaults':
                return $this->update_seo_defaults();
            default:
                throw new \Exception( 'Unknown action: ' . $action_name );
        }
    }

    private function clear_transients() {
        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%'" );
        $deleted += $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_%'" );
        return "Cleared {$deleted} transient rows";
    }

    private function clear_cache() {
        // WP Super Cache
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
            return 'WP Super Cache cleared';
        }
        // W3 Total Cache
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
            return 'W3 Total Cache cleared';
        }
        // LiteSpeed Cache
        if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
            \LiteSpeed_Cache_API::purge_all();
            return 'LiteSpeed Cache cleared';
        }
        // WP object cache
        wp_cache_flush();
        return 'Object cache flushed';
    }

    private function cleanup_revisions() {
        global $wpdb;
        $count = $wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'revision'" );
        return "Deleted {$count} revisions";
    }

    private function refresh_site_health() {
        $collector = new Site_Health_Collector();
        $collector->sync_site_health();
        return 'Site health refreshed';
    }

    private function update_plugins() {
        if ( ! function_exists( 'wp_update_plugins' ) ) {
            require_once ABSPATH . 'wp-includes/update.php';
        }
        wp_update_plugins();
        return 'Plugin update check triggered';
    }

    private function disable_xmlrpc() {
        add_filter( 'xmlrpc_enabled', '__return_false' );
        update_option( 'marketpulse_xmlrpc_disabled', true );
        return 'XML-RPC disabled';
    }

    private function fix_file_permissions() {
        $htaccess = ABSPATH . '.htaccess';
        if ( file_exists( $htaccess ) ) {
            chmod( $htaccess, 0644 );
        }
        $wp_config = ABSPATH . 'wp-config.php';
        if ( file_exists( $wp_config ) ) {
            chmod( $wp_config, 0644 );
        }
        return 'File permissions updated';
    }

    /**
     * Fix missing alt text on images
     * Finds attachments by filename and sets alt text from suggested value
     */
    private function fix_alt_text( $params ) {
        $images = $params['images'] ?? array();
        if ( empty( $images ) ) {
            return 'No images to fix';
        }

        $fixed = 0;
        $skipped = 0;

        foreach ( $images as $image ) {
            $src           = $image['src'] ?? '';
            $suggested_alt = $image['suggestedAlt'] ?? '';

            if ( empty( $src ) || empty( $suggested_alt ) ) {
                $skipped++;
                continue;
            }

            // Extract filename from URL
            $filename = basename( wp_parse_url( $src, PHP_URL_PATH ) );
            if ( empty( $filename ) ) {
                $skipped++;
                continue;
            }

            // Find attachment by filename
            global $wpdb;
            $attachment_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like( $filename )
            ) );

            if ( ! $attachment_id ) {
                // Try by post_name (slug)
                $slug = pathinfo( $filename, PATHINFO_FILENAME );
                $attachment_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_name = %s LIMIT 1",
                    sanitize_title( $slug )
                ) );
            }

            if ( $attachment_id ) {
                // Only update if alt is currently empty
                $current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
                if ( empty( $current_alt ) ) {
                    update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $suggested_alt ) );
                    $fixed++;
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }

        return "Fixed alt text on {$fixed} images, skipped {$skipped}";
    }

    private function remove_inactive_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! function_exists( 'delete_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $to_delete      = array();

        foreach ( array_keys( $all_plugins ) as $plugin_file ) {
            if ( ! in_array( $plugin_file, $active_plugins, true ) ) {
                $to_delete[] = $plugin_file;
            }
        }

        if ( empty( $to_delete ) ) {
            return 'No inactive plugins to remove';
        }

        $result = delete_plugins( $to_delete );
        if ( is_wp_error( $result ) ) {
            throw new \Exception( $result->get_error_message() );
        }

        return 'Removed ' . count( $to_delete ) . ' inactive plugins';
    }

    private function flush_rewrite_rules() {
        flush_rewrite_rules();
        return 'Rewrite rules flushed successfully';
    }

    private function regenerate_image_sizes() {
        global $wpdb;
        $attachments = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' LIMIT 100" );
        $count = 0;
        foreach ( $attachments as $attachment_id ) {
            $file = get_attached_file( $attachment_id );
            if ( $file && file_exists( $file ) ) {
                wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
                $count++;
            }
        }
        return "Regenerated thumbnails for {$count} images";
    }

    private function run_image_optimization() {
        // Check for common image optimization plugins
        if ( function_exists( 'ewww_image_optimizer_bulk_handler' ) ) {
            ewww_image_optimizer_bulk_handler();
            return 'EWWW Image Optimizer bulk run triggered';
        }
        if ( class_exists( 'Smush\\Core\\Modules\\Bulk' ) ) {
            return 'Smush plugin detected — use Smush dashboard for bulk optimization';
        }
        return 'No image optimization plugin found. Install EWWW or Smush for this feature';
    }

    private function update_seo_defaults() {
        global $wpdb;
        // Find posts without meta descriptions
        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_excerpt
             FROM $wpdb->posts p
             LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_yoast_wpseo_metadesc'
             WHERE p.post_type IN ('post', 'page')
             AND p.post_status = 'publish'
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             LIMIT 50"
        );
        $updated = 0;
        foreach ( $posts as $post ) {
            $desc = ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( get_post_field( 'post_content', $post->ID ), 25 );
            if ( ! empty( $desc ) ) {
                update_post_meta( $post->ID, '_yoast_wpseo_metadesc', sanitize_text_field( $desc ) );
                $updated++;
            }
        }
        return "Updated SEO meta descriptions for {$updated} posts";
    }
}
