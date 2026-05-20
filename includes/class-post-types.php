<?php
/**
 * Registers the "Chat Session" Custom Post Type.
 * Each CPT entry maps to a conversation in the Node.js backend.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_Post_Types {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_post_type( 'ecc_chat_session', [
            'labels' => [
                'name'               => __( 'Chat Sessions',        'ecommerce-chat' ),
                'singular_name'      => __( 'Chat Session',         'ecommerce-chat' ),
                'add_new'            => __( 'Add New',              'ecommerce-chat' ),
                'add_new_item'       => __( 'Add New Chat Session', 'ecommerce-chat' ),
                'edit_item'          => __( 'Edit Chat Session',    'ecommerce-chat' ),
                'view_item'          => __( 'View Chat Session',    'ecommerce-chat' ),
                'search_items'       => __( 'Search Sessions',      'ecommerce-chat' ),
                'not_found'          => __( 'No sessions found.',   'ecommerce-chat' ),
                'menu_name'          => __( 'Chat Sessions',        'ecommerce-chat' ),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'ecommerce-chat',   // nested under our admin menu
            'show_in_rest'        => true,
            'supports'            => [ 'title', 'custom-fields' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'menu_icon'           => 'dashicons-format-chat',
        ] );
    }

    /**
     * Create or retrieve a chat session CPT for a given conversation.
     *
     * @param string $conversation_id  MongoDB conversation _id
     * @param int    $user_id          WordPress user ID
     * @param array  $meta             Extra meta: order_id, product_id, type
     * @return int   WP Post ID
     */
    public static function get_or_create_session( $conversation_id, $user_id, $meta = [] ) {
        // Check if already exists
        $existing = get_posts( [
            'post_type'   => 'ecc_chat_session',
            'post_status' => 'publish',
            'meta_query'  => [ [
                'key'   => '_ecc_conversation_id',
                'value' => $conversation_id,
            ] ],
            'numberposts' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            return $existing[0]->ID;
        }

        $user = get_userdata( $user_id );
        $post_id = wp_insert_post( [
            'post_type'   => 'ecc_chat_session',
            'post_title'  => sprintf(
                'Session: %s — %s',
                $user ? $user->display_name : "User $user_id",
                date( 'Y-m-d H:i' )
            ),
            'post_status' => 'publish',
            'post_author' => $user_id,
        ] );

        if ( is_wp_error( $post_id ) ) return 0;

        // Store meta
        update_post_meta( $post_id, '_ecc_conversation_id', sanitize_text_field( $conversation_id ) );
        update_post_meta( $post_id, '_ecc_wp_user_id',      absint( $user_id ) );
        update_post_meta( $post_id, '_ecc_chat_type',       sanitize_text_field( $meta['type']       ?? 'support' ) );
        update_post_meta( $post_id, '_ecc_order_id',        sanitize_text_field( $meta['order_id']   ?? '' ) );
        update_post_meta( $post_id, '_ecc_product_id',      sanitize_text_field( $meta['product_id'] ?? '' ) );
        update_post_meta( $post_id, '_ecc_created_at',      current_time( 'mysql' ) );

        return $post_id;
    }
}
