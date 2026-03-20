<?php
/**
 * Cron Manager for Insight Hub
 *
 * @package BizGrowHub
 */

namespace BizGrowHub;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cron Manager Class
 */
class Cron_Manager {

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
        add_action( 'init', array( $this, 'schedule_heartbeat' ) );
        add_action( BIZGROWHUB_CRON_HEARTBEAT, array( $this, 'send_heartbeat' ) );
    }

    /**
     * Schedule heartbeat
     */
    public function schedule_heartbeat() {
        if ( ! wp_next_scheduled( BIZGROWHUB_CRON_HEARTBEAT ) ) {
            wp_schedule_event( time(), 'hourly', BIZGROWHUB_CRON_HEARTBEAT );
        }
    }

    /**
     * Send heartbeat
     */
    public function send_heartbeat() {
        if ( 'active' === $this->license_manager->get_license_status() ) {
            $this->license_manager->send_heartbeat();
        }
    }

}