<?php
/**
 * WooCommerce integration.
 * - Adds "Chat about this order" button in My Account > Orders
 * - Adds "Ask about this product" button on product pages
 * - Syncs order status context into chat sessions
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_WooCommerce {

    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        // My Account — Orders table
        add_action( 'woocommerce_my_account_my_orders_actions', [ __CLASS__, 'add_order_chat_button' ], 10, 2 );

        // Single product page — after add to cart
        add_action( 'woocommerce_after_add_to_cart_button', [ __CLASS__, 'add_product_chat_button' ] );

        // Order status change → update CPT meta
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'sync_order_status' ], 10, 3 );
    }

    // ── "Chat about this order" in My Account ───────

    public static function add_order_chat_button( $actions, $order ) {
        if ( ! get_option( 'ecc_enable_widget', '1' ) ) return $actions;

        $color = get_option( 'ecc_widget_color', '#6366f1' );

        $actions['ecc_chat'] = [
            'url'  => '#',
            'name' => '💬 ' . __( 'Chat about this order', 'ecommerce-chat' ),
            'class' => 'ecc-order-chat-btn',
            // Pass order ID to JS via data attribute — we hook into output below
        ];

        // We override the rendered <a> with a data attribute via output buffer hack or custom template
        // Instead, use a simpler approach: add a shortcode-like inline trigger
        add_filter( 'woocommerce_my_account_my_orders_columns', function( $cols ) { return $cols; } );

        return $actions;
    }

    // ── "Ask about this product" on product page ────

    public static function add_product_chat_button() {
        if ( ! get_option( 'ecc_enable_widget', '1' ) ) return;
        if ( ! is_user_logged_in() ) return;

        $product_id   = get_the_ID();
        $product_name = get_the_title();
        $color        = get_option( 'ecc_widget_color', '#6366f1' );
        ?>
        <div class="ecc-product-chat" style="margin-top: 12px;">
            <button
                type="button"
                class="ecc-product-chat-btn button"
                style="background: <?php echo esc_attr( $color ); ?>; color: #fff; border: none; border-radius: 8px; padding: 10px 20px; cursor: pointer; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;"
                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                data-product-name="<?php echo esc_attr( $product_name ); ?>"
                onclick="eccOpenProductChat(<?php echo esc_js( $product_id ); ?>, <?php echo esc_js( $product_name ); ?>)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                <?php esc_html_e( 'Ask about this product', 'ecommerce-chat' ); ?>
            </button>
        </div>
        <?php
    }

    // ── Sync order status to chat session CPT ───────

    public static function sync_order_status( $order_id, $old_status, $new_status ) {
        // Find a CPT session linked to this order
        $posts = get_posts( [
            'post_type'  => 'ecc_chat_session',
            'meta_query' => [ [
                'key'   => '_ecc_order_id',
                'value' => (string) $order_id,
            ] ],
            'numberposts' => -1,
        ] );

        foreach ( $posts as $post ) {
            update_post_meta( $post->ID, '_ecc_order_status', $new_status );
            update_post_meta( $post->ID, '_ecc_order_status_updated', current_time( 'mysql' ) );
        }
    }

    /**
     * Get a formatted order context string for injecting into a chat message.
     * Called by JS indirectly via /ecc/v1/context.
     */
    public static function get_order_context( $order_id ) {
        if ( ! $order_id ) return '';
        $order = wc_get_order( $order_id );
        if ( ! $order ) return '';

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' ×' . $item->get_quantity();
        }

        return sprintf(
            "Order #%d (%s) — %s — Items: %s",
            $order->get_id(),
            $order->get_status(),
            wc_price( $order->get_total() ),
            implode( ', ', $items )
        );
    }
}
