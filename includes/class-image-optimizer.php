<?php
/**
 * BizGrowHub Image Optimizer
 *
 * Auto-converts uploaded images to WebP using Imagick.
 * Controlled by BIZGROWHUB_image_optimizer_enabled WP option.
 * Dashboard pushes settings via REST: /wp-json/bizgrowhub/v1/image-optimizer/settings
 */

namespace BizGrowHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Image_Optimizer {

    const OPT_ENABLED   = 'BIZGROWHUB_image_optimizer_enabled';
    const OPT_QUALITY   = 'BIZGROWHUB_image_optimizer_quality';
    const OPT_MAX_W     = 'BIZGROWHUB_image_optimizer_max_width';
    const OPT_MAX_H     = 'BIZGROWHUB_image_optimizer_max_height';

    public function __construct() {
        // Register REST routes for dashboard control
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Auto-convert on upload if enabled
        add_filter( 'wp_handle_upload', [ $this, 'convert_on_upload' ] );

        error_log( 'bizgrowhub ImageOptimizer: constructor called, enabled=' . var_export( $this->is_enabled(), true ) );
    }

    public function is_enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, false );
    }

    public function get_settings(): array {
        return [
            'enabled'  => $this->is_enabled(),
            'quality'  => (int) get_option( self::OPT_QUALITY, 80 ),
            'max_w'    => (int) get_option( self::OPT_MAX_W, 1920 ),
            'max_h'    => (int) get_option( self::OPT_MAX_H, 1080 ),
            'imagick'  => extension_loaded( 'imagick' ),
            'gd'       => extension_loaded( 'gd' ),
        ];
    }

    /* ================================================================
       REST API — dashboard push settings
       ================================================================ */

    public function register_rest_routes() {
        // GET settings
        register_rest_route( 'bizgrowhub/v1', '/image-optimizer/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_settings' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // POST update settings
        register_rest_route( 'bizgrowhub/v1', '/image-optimizer/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_settings' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // POST bulk optimize existing images
        register_rest_route( 'bizgrowhub/v1', '/image-optimizer/bulk', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_bulk_optimize' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    public function check_permission( $request ) {
        if ( current_user_can( 'manage_options' ) ) return true;

        $key_hash = $request->get_header( 'X-Dashboard-Key-Hash' );
        if ( ! $key_hash ) {
            $params   = $request->get_json_params();
            $key_hash = $params['key_hash'] ?? null;
        }
        if ( $key_hash ) {
            $stored = get_option( BIZGROWHUB_OPTION_LICENSE_KEY, '' );
            if ( ! empty( $stored ) && hash_equals( hash( 'sha256', $stored ), $key_hash ) ) {
                return true;
            }
        }
        return new \WP_Error( 'unauthorized', 'Invalid key', [ 'status' => 403 ] );
    }

    public function rest_get_settings( $request ) {
        return new \WP_REST_Response( [ 'success' => true, 'settings' => $this->get_settings() ], 200 );
    }

    public function rest_save_settings( $request ) {
        $p = $request->get_json_params();
        $s = $p['settings'] ?? $p;

        if ( isset( $s['enabled'] ) )  update_option( self::OPT_ENABLED, (bool)  $s['enabled'] );
        if ( isset( $s['quality'] ) )  update_option( self::OPT_QUALITY, absint( $s['quality'] ) );
        if ( isset( $s['max_w'] ) )    update_option( self::OPT_MAX_W,   absint( $s['max_w'] ) );
        if ( isset( $s['max_h'] ) )    update_option( self::OPT_MAX_H,   absint( $s['max_h'] ) );

        error_log( 'bizgrowhub ImageOptimizer: settings saved via REST. enabled=' . var_export( $this->is_enabled(), true ) );

        return new \WP_REST_Response( [ 'success' => true, 'settings' => $this->get_settings() ], 200 );
    }

    public function rest_bulk_optimize( $request ) {
        if ( ! $this->is_enabled() ) {
            return new \WP_Error( 'disabled', 'Image optimizer is disabled', [ 'status' => 400 ] );
        }
        if ( ! extension_loaded( 'imagick' ) ) {
            return new \WP_Error( 'no_imagick', 'Imagick PHP extension not available', [ 'status' => 400 ] );
        }

        $limit = 20; // process 20 at a time
        $args  = [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png' ],
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
        ];

        $ids        = get_posts( $args );
        $converted  = 0;
        $errors     = [];

        foreach ( $ids as $id ) {
            $path = get_attached_file( $id );
            if ( ! $path || ! file_exists( $path ) ) continue;

            $result = $this->convert_file_to_webp( $path );
            if ( $result ) {
                $converted++;
            } else {
                $errors[] = basename( $path );
            }
        }

        return new \WP_REST_Response( [
            'success'   => true,
            'converted' => $converted,
            'total'     => count( $ids ),
            'errors'    => $errors,
        ], 200 );
    }

    /* ================================================================
       Upload hook
       ================================================================ */

    public function convert_on_upload( array $upload ): array {
        if ( ! $this->is_enabled() )          return $upload;
        if ( ! extension_loaded( 'imagick' ) ) return $upload;

        $valid = [ 'image/jpeg', 'image/png', 'image/gif', 'image/heic' ];
        if ( ! in_array( $upload['type'], $valid, true ) ) return $upload;

        $new_path = $this->convert_file_to_webp( $upload['file'] );
        if ( $new_path ) {
            $upload['file'] = $new_path;
            $upload['url']  = str_replace( basename( $upload['url'] ), basename( $new_path ), $upload['url'] );
            $upload['type'] = 'image/webp';
        }

        return $upload;
    }

    /* ================================================================
       Core conversion
       ================================================================ */

    public function convert_file_to_webp( string $file_path ): ?string {
        if ( ! file_exists( $file_path ) ) return null;

        try {
            $imagick = new \Imagick( $file_path );
            $this->fix_orientation( $imagick );

            $quality  = (int) get_option( self::OPT_QUALITY, 80 );
            $max_w    = (int) get_option( self::OPT_MAX_W, 1920 );
            $max_h    = (int) get_option( self::OPT_MAX_H, 1080 );
            $this->resize_image( $imagick, $max_w, $max_h );

            $imagick->setImageFormat( 'webp' );
            $imagick->setImageCompressionQuality( $quality );

            $new_path = pathinfo( $file_path, PATHINFO_DIRNAME )
                        . '/'
                        . pathinfo( $file_path, PATHINFO_FILENAME )
                        . '.webp';

            $imagick->writeImage( $new_path );

            // Only keep WebP if it's smaller
            if ( file_exists( $new_path ) && filesize( $new_path ) < filesize( $file_path ) ) {
                @unlink( $file_path );
                $imagick->clear();
                $imagick->destroy();
                return $new_path;
            }

            @unlink( $new_path );
            $imagick->clear();
            $imagick->destroy();
        } catch ( \Exception $e ) {
            error_log( 'bizgrowhub ImageOptimizer: conversion failed for ' . basename( $file_path ) . ' — ' . $e->getMessage() );
        }

        return null;
    }

    private function fix_orientation( \Imagick $imagick ): void {
        switch ( $imagick->getImageOrientation() ) {
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $imagick->rotateImage( '#000', 180 ); break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $imagick->rotateImage( '#000', 90 );  break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $imagick->rotateImage( '#000', -90 ); break;
        }
        $imagick->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
    }

    private function resize_image( \Imagick $imagick, int $max_w, int $max_h ): void {
        $w = $imagick->getImageWidth();
        $h = $imagick->getImageHeight();
        if ( $w <= $max_w && $h <= $max_h ) return;

        $ratio  = $w / $h;
        $new_w  = $w;
        $new_h  = $h;

        if ( $w > $max_w ) { $new_w = $max_w; $new_h = (int) ( $max_w / $ratio ); }
        if ( $new_h > $max_h ) { $new_h = $max_h; $new_w = (int) ( $max_h * $ratio ); }

        $imagick->resizeImage( $new_w, $new_h, \Imagick::FILTER_LANCZOS, 1 );
    }
}
