<?php
/**
 * Plugin Name:       Worknoon Chat
 * Plugin URI:        https://github.com/yourname/worknoon-chat
 * Description:       Real-time chat widget for WooCommerce — connects customers to support agents, designers, and merchants via your Node.js chat backend.
 * Version:           1.0.0
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       worknoon-chat
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Constants
define( 'ECC_VERSION',     '1.0.0' );
define( 'ECC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ECC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ECC_PLUGIN_FILE', __FILE__ );

// Load core classes 
require_once ECC_PLUGIN_DIR . 'includes/class-ecc-post-types.php';
require_once ECC_PLUGIN_DIR . 'includes/class-ecc-settings.php';
require_once ECC_PLUGIN_DIR . 'includes/class-ecc-widget.php';
require_once ECC_PLUGIN_DIR . 'includes/class-ecc-rest-api.php';
require_once ECC_PLUGIN_DIR . 'includes/class-ecc-shortcode.php';
require_once ECC_PLUGIN_DIR . 'includes/class-ecc-woocommerce.php';

// Boot 
function ecc_boot() {
    ECC_Post_Types::init();
    ECC_Settings::init();
    ECC_Widget::init();
    ECC_REST_API::init();
    ECC_Shortcode::init();

    if ( class_exists( 'WooCommerce' ) ) {
        ECC_WooCommerce::init();
    }
}
add_action( 'plugins_loaded', 'ecc_boot' );

// Activation / Deactivation
register_activation_hook( __FILE__, 'ecc_activate' );
function ecc_activate() {
    ECC_Post_Types::register();
    flush_rewrite_rules();

    // Set default options
    add_option( 'ecc_api_url',        'http://localhost:5000' );
    add_option( 'ecc_widget_title',   'Chat with us' );
    add_option( 'ecc_widget_enabled', '1' );
    add_option( 'ecc_theme_color',    '#6366f1' );
    add_option( 'ecc_position',       'bottom-right' );
}

register_deactivation_hook( __FILE__, 'ecc_deactivate' );
function ecc_deactivate() {
    flush_rewrite_rules();
}