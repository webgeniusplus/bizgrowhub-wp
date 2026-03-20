<?php
namespace InsightHub;

/**
 * Data Sync — REST endpoints for fetching WooCommerce products, orders, customers
 * from the WordPress site to the BizGrowHub dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Data_Sync {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        $namespace = 'insight-hub/v1';

        register_rest_route( $namespace, '/sync/products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_products' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( $namespace, '/sync/orders', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_orders' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( $namespace, '/sync/customers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_customers' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
    }

    /**
     * Auth: license key via header or param
     */
    public function check_auth( $request ) {
        if ( current_user_can( 'manage_options' ) ) return true;

        $stored_key = get_option( INSIGHT_HUB_OPTION_LICENSE_KEY, '' );
        if ( empty( $stored_key ) ) return false;

        // Raw key
        $key = $request->get_header( 'X-License-Key' );
        if ( ! $key ) $key = $request->get_param( 'license_key' );
        if ( $key && $key === $stored_key ) {
            return get_option( INSIGHT_HUB_OPTION_LICENSE_STATUS, 'inactive' ) === 'active';
        }

        // Hash auth
        $hash = $request->get_header( 'X-Dashboard-Key-Hash' );
        if ( $hash && hash( 'sha256', $stored_key ) === $hash ) {
            return get_option( INSIGHT_HUB_OPTION_LICENSE_STATUS, 'inactive' ) === 'active';
        }

        return false;
    }

    /**
     * GET /insight-hub/v1/sync/products
     * Params: page, per_page, status, search
     */
    public function get_products( $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new \WP_REST_Response( [ 'error' => 'WooCommerce not active' ], 400 );
        }

        $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
        $status   = $request->get_param( 'status' ) ?: 'any';
        $search   = $request->get_param( 'search' ) ?: '';

        $args = [
            'limit'   => $per_page,
            'page'    => $page,
            'status'  => $status,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];
        if ( $search ) $args['s'] = $search;

        $products = wc_get_products( $args );

        // Total count
        $count_args = $args;
        $count_args['limit'] = -1;
        $count_args['return'] = 'ids';
        $total = count( wc_get_products( $count_args ) );

        $items = [];
        foreach ( $products as $product ) {
            $images = [];
            foreach ( $product->get_gallery_image_ids() as $img_id ) {
                $src = wp_get_attachment_url( $img_id );
                if ( $src ) $images[] = [ 'src' => $src, 'alt' => get_post_meta( $img_id, '_wp_attachment_image_alt', true ) ];
            }
            // Main image first
            $main_img_id = $product->get_image_id();
            if ( $main_img_id ) {
                $src = wp_get_attachment_url( $main_img_id );
                if ( $src ) array_unshift( $images, [ 'src' => $src, 'alt' => get_post_meta( $main_img_id, '_wp_attachment_image_alt', true ) ] );
            }

            $cats = [];
            foreach ( $product->get_category_ids() as $cat_id ) {
                $term = get_term( $cat_id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) $cats[] = [ 'id' => $cat_id, 'name' => $term->name, 'slug' => $term->slug ];
            }

            $tags = [];
            foreach ( $product->get_tag_ids() as $tag_id ) {
                $term = get_term( $tag_id, 'product_tag' );
                if ( $term && ! is_wp_error( $term ) ) $tags[] = [ 'id' => $tag_id, 'name' => $term->name ];
            }

            $items[] = [
                'id'                => $product->get_id(),
                'name'              => $product->get_name(),
                'slug'              => $product->get_slug(),
                'sku'               => $product->get_sku(),
                'type'              => $product->get_type(),
                'status'            => $product->get_status(),
                'price'             => $product->get_price(),
                'regular_price'     => $product->get_regular_price(),
                'sale_price'        => $product->get_sale_price(),
                'stock_quantity'    => $product->get_stock_quantity(),
                'stock_status'      => $product->get_stock_status(),
                'manage_stock'      => $product->get_manage_stock(),
                'categories'        => $cats,
                'tags'              => $tags,
                'images'            => $images,
                'short_description' => $product->get_short_description(),
                'description'       => $product->get_description(),
                'weight'            => $product->get_weight(),
                'dimensions'        => [
                    'length' => $product->get_length(),
                    'width'  => $product->get_width(),
                    'height' => $product->get_height(),
                ],
                'total_sales'       => (int) $product->get_total_sales(),
                'average_rating'    => (float) $product->get_average_rating(),
                'rating_count'      => (int) $product->get_rating_count(),
                'featured'          => $product->get_featured(),
                'on_sale'           => $product->is_on_sale(),
                'permalink'         => $product->get_permalink(),
                'attributes'        => $this->get_product_attributes( $product ),
                'variations'        => $this->get_product_variations( $product ),
                'date_created'      => $product->get_date_created() ? $product->get_date_created()->format( 'c' ) : null,
                'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->format( 'c' ) : null,
            ];
        }

        return new \WP_REST_Response( [
            'products' => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => ceil( $total / $per_page ),
        ] );
    }

    /**
     * GET /insight-hub/v1/sync/orders
     */
    public function get_orders( $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new \WP_REST_Response( [ 'error' => 'WooCommerce not active' ], 400 );
        }

        $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
        $status   = $request->get_param( 'status' ) ?: 'any';

        $args = [
            'limit'   => $per_page,
            'page'    => $page,
            'status'  => $status,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        $orders = wc_get_orders( $args );

        $count_args = $args;
        $count_args['limit'] = -1;
        $count_args['return'] = 'ids';
        $total = count( wc_get_orders( $count_args ) );

        $items = [];
        foreach ( $orders as $order ) {
            $line_items = [];
            foreach ( $order->get_items() as $item ) {
                $line_items[] = [
                    'product_id' => $item->get_product_id(),
                    'name'       => $item->get_name(),
                    'quantity'   => $item->get_quantity(),
                    'total'      => $item->get_total(),
                    'sku'        => $item->get_product() ? $item->get_product()->get_sku() : '',
                ];
            }

            $items[] = [
                'id'                    => $order->get_id(),
                'number'                => $order->get_order_number(),
                'status'                => $order->get_status(),
                'currency'              => $order->get_currency(),
                'total'                 => $order->get_total(),
                'subtotal'              => $order->get_subtotal(),
                'discount_total'        => $order->get_discount_total(),
                'shipping_total'        => $order->get_shipping_total(),
                'total_tax'             => $order->get_total_tax(),
                'payment_method'        => $order->get_payment_method(),
                'payment_method_title'  => $order->get_payment_method_title(),
                'transaction_id'        => $order->get_transaction_id(),
                'customer_id'           => $order->get_customer_id(),
                'billing'               => $order->get_address( 'billing' ),
                'shipping'              => $order->get_address( 'shipping' ),
                'line_items'            => $line_items,
                'customer_note'         => $order->get_customer_note(),
                'customer_ip_address'   => $order->get_customer_ip_address(),
                'date_created'          => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
                'date_modified'         => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
                'date_completed'        => $order->get_date_completed() ? $order->get_date_completed()->format( 'c' ) : null,
                'date_paid'             => $order->get_date_paid() ? $order->get_date_paid()->format( 'c' ) : null,
            ];
        }

        return new \WP_REST_Response( [
            'orders' => $items,
            'total'  => $total,
            'page'   => $page,
            'pages'  => ceil( $total / $per_page ),
        ] );
    }

    /**
     * GET /insight-hub/v1/sync/customers
     */
    public function get_customers( $request ) {
        $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );

        $args = [
            'role'    => 'customer',
            'number'  => $per_page,
            'paged'   => $page,
            'orderby' => 'registered',
            'order'   => 'DESC',
        ];

        $query = new \WP_User_Query( $args );
        $total = $query->get_total();

        $items = [];
        foreach ( $query->get_results() as $user ) {
            $customer = new \WC_Customer( $user->ID );

            $items[] = [
                'id'            => $user->ID,
                'email'         => $user->user_email,
                'first_name'    => $customer->get_first_name(),
                'last_name'     => $customer->get_last_name(),
                'username'      => $user->user_login,
                'role'          => 'customer',
                'phone'         => $customer->get_billing_phone(),
                'company'       => $customer->get_billing_company(),
                'avatar_url'    => get_avatar_url( $user->ID ),
                'billing'       => $customer->get_billing(),
                'shipping'      => $customer->get_shipping(),
                'total_spent'   => (float) $customer->get_total_spent(),
                'orders_count'  => (int) $customer->get_order_count(),
                'date_created'  => $user->user_registered,
            ];
        }

        return new \WP_REST_Response( [
            'customers' => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => ceil( $total / $per_page ),
        ] );
    }

    /* ================================================================
       Helpers — Attributes & Variations
       ================================================================ */

    /**
     * Get product attributes
     */
    private function get_product_attributes( $product ) {
        $attrs = [];
        foreach ( $product->get_attributes() as $attr ) {
            if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
                $attrs[] = [
                    'id'        => $attr->get_id(),
                    'name'      => $attr->get_name(),
                    'label'     => wc_attribute_label( $attr->get_name(), $product ),
                    'position'  => $attr->get_position(),
                    'visible'   => $attr->get_visible(),
                    'variation' => $attr->get_variation(),
                    'options'   => $attr->get_options(),
                    // Get human-readable option names for taxonomy attributes
                    'option_names' => $attr->is_taxonomy()
                        ? array_map( function( $term_id ) use ( $attr ) {
                            $term = get_term( $term_id, $attr->get_name() );
                            return $term && ! is_wp_error( $term ) ? $term->name : $term_id;
                          }, $attr->get_options() )
                        : $attr->get_options(),
                ];
            }
        }
        return $attrs;
    }

    /**
     * Get variations for variable products
     */
    private function get_product_variations( $product ) {
        if ( $product->get_type() !== 'variable' ) return [];

        $variations = [];
        $children = $product->get_children();

        foreach ( $children as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->exists() ) continue;

            // Get variation image
            $img_id = $variation->get_image_id();
            $image = null;
            if ( $img_id ) {
                $src = wp_get_attachment_url( $img_id );
                if ( $src ) $image = [
                    'src' => $src,
                    'alt' => get_post_meta( $img_id, '_wp_attachment_image_alt', true ),
                ];
            }

            // Build attributes map (attribute_name => value)
            $var_attrs = [];
            foreach ( $variation->get_attributes() as $attr_key => $attr_value ) {
                $label = wc_attribute_label( $attr_key, $product );
                $var_attrs[] = [
                    'name'   => $attr_key,
                    'label'  => $label,
                    'option' => $attr_value,
                    'option_name' => $this->get_attribute_option_name( $attr_key, $attr_value ),
                ];
            }

            $variations[] = [
                'id'             => $variation->get_id(),
                'sku'            => $variation->get_sku(),
                'status'         => $variation->get_status(),
                'price'          => $variation->get_price(),
                'regular_price'  => $variation->get_regular_price(),
                'sale_price'     => $variation->get_sale_price(),
                'on_sale'        => $variation->is_on_sale(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'stock_status'   => $variation->get_stock_status(),
                'manage_stock'   => $variation->get_manage_stock(),
                'weight'         => $variation->get_weight(),
                'dimensions'     => [
                    'length' => $variation->get_length(),
                    'width'  => $variation->get_width(),
                    'height' => $variation->get_height(),
                ],
                'image'          => $image,
                'attributes'     => $var_attrs,
                'description'    => $variation->get_description(),
                'date_created'   => $variation->get_date_created() ? $variation->get_date_created()->format( 'c' ) : null,
            ];
        }

        return $variations;
    }

    /**
     * Get human-readable option name for taxonomy attributes
     */
    private function get_attribute_option_name( $attribute_name, $slug ) {
        if ( empty( $slug ) ) return $slug;

        // Taxonomy attribute (pa_color, pa_size, etc.)
        if ( taxonomy_exists( $attribute_name ) ) {
            $term = get_term_by( 'slug', $slug, $attribute_name );
            if ( $term && ! is_wp_error( $term ) ) return $term->name;
        }

        return $slug;
    }
}
