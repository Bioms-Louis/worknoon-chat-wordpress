<?php
/**
 * Injects the floating chat widget into the frontend.
 * The widget is a React app (built from the main frontend) embedded via an iframe,
 * or driven by a lightweight vanilla JS bubble that calls the WP REST API.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_Widget {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'render' ] );
    }

    public static function enqueue() {
        if ( ! get_option( 'ecc_enable_widget', '1' ) ) return;
        if ( ! self::should_show() ) return;

        wp_enqueue_style(
            'ecc-widget',
            ECC_PLUGIN_URL . 'assets/css/widget.css',
            [],
            ECC_VERSION
        );

        wp_enqueue_script(
            'ecc-widget',
            ECC_PLUGIN_URL . 'assets/js/widget.js',
            [],
            ECC_VERSION,
            true   // load in footer
        );

        // Pass config to JS
        wp_localize_script( 'ecc-widget', 'eccConfig', [
            'apiUrl'        => get_option( 'ecc_api_url',      'http://localhost:5000' ),
            'restUrl'       => rest_url( 'ecc/v1' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'isLoggedIn'    => is_user_logged_in(),
            'loginUrl'      => wp_login_url( get_permalink() ),
            'widgetTitle'   => get_option( 'ecc_widget_title', 'Chat with us' ),
            'widgetColor'   => get_option( 'ecc_widget_color', '#6366f1' ),
            'defaultType'   => get_option( 'ecc_default_role', 'support' ),
            'userId'        => get_current_user_id(),
            'userName'      => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'isWooCommerce' => class_exists( 'WooCommerce' ),
            'isProduct'     => is_product(),
            'productId'     => is_product() ? get_the_ID() : null,
            'productName'   => is_product() ? get_the_title() : null,
            'orderId'       => self::get_latest_order_id(),
        ] );
    }

    public static function render() {
        if ( ! get_option( 'ecc_enable_widget', '1' ) ) return;
        if ( ! self::should_show() ) return;
        echo '<div id="ecc-chat-root"></div>';
    }

    private static function should_show() {
        $show_on = get_option( 'ecc_show_on', 'all' );
        if ( $show_on === 'all' ) return true;
        if ( $show_on === 'shop'    && function_exists( 'is_shop' )    && is_shop() )    return true;
        if ( $show_on === 'product' && function_exists( 'is_product' ) && is_product() ) return true;
        if ( $show_on === 'cart'    && function_exists( 'is_cart' )    && is_cart() )    return true;
        return false;
    }

    private static function get_latest_order_id() {
        if ( ! is_user_logged_in() || ! class_exists( 'WooCommerce' ) ) return null;
        $orders = wc_get_orders( [ 'customer' => get_current_user_id(), 'limit' => 1 ] );
        return ! empty( $orders ) ? $orders[0]->get_id() : null;
    }
}
