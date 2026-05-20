<?php
/**
 * Plugin Name:       eCommerce Chat
 * Plugin URI:        https://github.com/Bioms-Louis/worknoon-chat-wordpress.git
 * Description:       Real-time chat widget connecting WooCommerce customers to agents, designers, and merchants. Powered by Socket.IO.
 * Version:           1.0.0
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       worknoon-chat
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ECC_VERSION',     '1.0.0' );
define( 'ECC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ECC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ECC_PLUGIN_FILE', __FILE__ );

require_once ECC_PLUGIN_DIR . 'includes/class-post-types.php';
require_once ECC_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once ECC_PLUGIN_DIR . 'includes/class-widget.php';
require_once ECC_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once ECC_PLUGIN_DIR . 'includes/class-admin.php';
require_once ECC_PLUGIN_DIR . 'includes/class-woocommerce.php';

function ecc_init() {
    ECC_Post_Types::init();
    ECC_REST_API::init();
    ECC_Widget::init();
    ECC_Shortcode::init();
    ECC_Admin::init();
    ECC_WooCommerce::init();
}
add_action( 'plugins_loaded', 'ecc_init' );

register_activation_hook( __FILE__, 'ecc_activate' );
function ecc_activate() {
    ECC_Post_Types::register();
    flush_rewrite_rules();
    add_option( 'ecc_api_url',       'http://localhost:5000' );
    add_option( 'ecc_widget_title',  'Chat with us' );
    add_option( 'ecc_widget_color',  '#6366f1' );
    add_option( 'ecc_enable_widget', '1' );
    add_option( 'ecc_show_on',       'all' );
    add_option( 'ecc_default_role',  'support' );
}

register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );

register_uninstall_hook( __FILE__, 'ecc_uninstall' );
function ecc_uninstall() {
    foreach ( ['ecc_api_url','ecc_widget_title','ecc_widget_color','ecc_enable_widget','ecc_show_on','ecc_default_role'] as $opt ) {
        delete_option( $opt );
    }
}
