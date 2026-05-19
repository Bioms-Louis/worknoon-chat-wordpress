<?php
/**
 * Registers the Chat Session custom post type.
 * Each CPT entry stores metadata about a WP-initiated chat session
 * so admins can review history from the WP dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_Post_Types {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_chat_session', [ __CLASS__, 'save_meta' ] );
        add_filter( 'manage_chat_session_posts_columns',       [ __CLASS__, 'add_columns' ] );
        add_action( 'manage_chat_session_posts_custom_column', [ __CLASS__, 'render_columns' ], 10, 2 );
    }

    public static function register() {
        $labels = [
            'name'  => __( 'Chat Sessions', 'worknoon-chat' ),
            'singular_name'      => __( 'Chat Session',         'worknoon-chat' ),
            'add_new'            => __( 'Add New',              'worknoon-chat' ),
            'add_new_item'       => __( 'Add New Chat Session', 'worknoon-chat' ),
            'edit_item'          => __( 'Edit Chat Session',    'worknoon-chat' ),
            'view_item'          => __( 'View Chat Session',    'worknoon-chat' ),
            'search_items'       => __( 'Search Chat Sessions', 'worknoon-chat' ),
            'not_found'          => __( 'No chat sessions found.', 'worknoon-chat' ),
            'menu_name'          => __( 'Chat Sessions',        'worknoon-chat' ),
        ];

        register_post_type( 'chat_session', [
            'labels' => $labels,
            'public'=> false,
            'show_ui' => true,
            'show_in_menu' => 'ecc-settings',  
            'show_in_rest' => true,
            'supports'  => [ 'title', 'custom-fields' ],
            'capability_type' => 'post',
            'capabilities' => [ 'create_posts' => 'do_not_allow' ],             'map_meta_cap'        => true,
            'menu_icon' => 'dashicons-format-chat',
        ] );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'ecc_session_details',
            __( 'Session Details', 'worknoon-chat' ),
            [ __CLASS__, 'render_meta_box' ],
            'chat_session',
            'normal',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        $fields = [
            '_ecc_conversation_id' => 'Conversation ID (backend)',
            '_ecc_wp_user_id' => 'WordPress User ID',
            '_ecc_user_email' => 'User Email',
            '_ecc_order_id' => 'WooCommerce Order ID',
            '_ecc_product_id' => 'Product ID',
            '_ecc_chat_type' => 'Chat Type (support / designer / merchant)',
            '_ecc_status' => 'Status',
            '_ecc_started_at'=> 'Started At',
        ];

        echo '<table class="form-table" style="font-size:13px">';
        foreach ( $fields as $key => $label ) {
            $value = get_post_meta( $post->ID, $key, true );
            echo "<tr><th style='width:200px'>{$label}</th><td><code>" . esc_html( $value ?: '—' ) . '</code></td></tr>';
        }
        echo '</table>';
    }

    public static function save_meta( $post_id ) {
        // Sessions are created programmatically — no manual save needed
    }

    // Custom admin columns 
    public static function add_columns( $columns ) {
        unset( $columns['date'] );
        return array_merge( $columns, [
            'ecc_type' => __( 'Type', 'worknoon-chat' ),
            'ecc_order' => __( 'Order', 'worknoon-chat' ),
            'ecc_status' => __( 'Status', 'worknoon-chat' ),
            'ecc_started' => __( 'Started','worknoon-chat' ),
        ] );
    }

    public static function render_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'ecc_type':
                echo esc_html( get_post_meta( $post_id, '_ecc_chat_type', true ) ?: '—' );
                break;
            case 'ecc_order':
                $order_id = get_post_meta( $post_id, '_ecc_order_id', true );
                if ( $order_id && class_exists( 'WooCommerce' ) ) {
                    $url = admin_url( "post.php?post={$order_id}&action=edit" );
                    echo "<a href='" . esc_url( $url ) . "'>#" . esc_html( $order_id ) . '</a>';
                } else {
                    echo esc_html( $order_id ?: '—' );
                }
                break;
            case 'ecc_status':
                $status = get_post_meta( $post_id, '_ecc_status', true ) ?: 'open';
                $color  = $status === 'closed' ? '#888' : '#22c55e';
                echo "<span style='color:{$color};font-weight:600'>" . esc_html( ucfirst( $status ) ) . '</span>';
                break;
            case 'ecc_started':
                echo esc_html( get_post_meta( $post_id, '_ecc_started_at', true ) ?: '—' );
                break;
        }
    }

    //  Create a session record programmatically 
    public static function create_session( $args = [] ) {
        $defaults = [
            'conversation_id' => '',
            'wp_user_id' => get_current_user_id(),
            'user_email' => wp_get_current_user()->user_email,
            'order_id' => '',
            'product_id' => '',
            'chat_type' => 'support',
            'status' => 'open',
        ];
        $args = wp_parse_args( $args, $defaults );

        $post_id = wp_insert_post( [
            'post_type' => 'chat_session',
            'post_title' => sprintf(
                'Session — %s — %s',
                esc_html( $args['user_email'] ),
                current_time( 'mysql' )
            ),
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) return $post_id;

        update_post_meta( $post_id, '_ecc_conversation_id', $args['conversation_id'] );
        update_post_meta( $post_id, '_ecc_wp_user_id', $args['wp_user_id'] );
        update_post_meta( $post_id, '_ecc_user_email', $args['user_email'] );
        update_post_meta( $post_id, '_ecc_order_id', $args['order_id'] );
        update_post_meta( $post_id, '_ecc_product_id', $args['product_id'] );
        update_post_meta( $post_id, '_ecc_chat_type', $args['chat_type'] );
        update_post_meta( $post_id, '_ecc_status', $args['status'] );
        update_post_meta( $post_id, '_ecc_started_at',  current_time( 'mysql' ) );

        return $post_id;
    }
}