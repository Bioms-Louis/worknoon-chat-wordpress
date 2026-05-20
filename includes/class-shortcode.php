<?php
/**
 * Shortcode: [ecc_chat]
 *
 * Usage examples:
 *   [ecc_chat]
 *   [ecc_chat type="designer" title="Talk to a Designer"]
 *   [ecc_chat type="support"  button_text="Get Help Now" color="#10b981"]
 *   [ecc_chat type="merchant" order_id="1234"]
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_Shortcode {

    public static function init() {
        add_shortcode( 'ecc_chat', [ __CLASS__, 'render' ] );
    }

    public static function render( $atts ) {
        $atts = shortcode_atts( [
            'type'        => get_option( 'ecc_default_role', 'support' ),
            'title'       => get_option( 'ecc_widget_title', 'Chat with us' ),
            'button_text' => 'Start Chat',
            'color'       => get_option( 'ecc_widget_color', '#6366f1' ),
            'order_id'    => '',
            'product_id'  => '',
            'class'       => '',
        ], $atts, 'ecc_chat' );

        // Sanitize
        $type        = sanitize_text_field( $atts['type'] );
        $title       = esc_html( $atts['title'] );
        $button_text = esc_html( $atts['button_text'] );
        $color       = sanitize_hex_color( $atts['color'] ) ?: '#6366f1';
        $order_id    = sanitize_text_field( $atts['order_id'] );
        $product_id  = sanitize_text_field( $atts['product_id'] );
        $extra_class = sanitize_html_class( $atts['class'] );

        // Unique ID for this instance
        $uid = 'ecc-inline-' . wp_unique_id();

        ob_start();
        ?>
        <div
            id="<?php echo esc_attr( $uid ); ?>"
            class="ecc-inline-widget <?php echo esc_attr( $extra_class ); ?>"
            data-type="<?php echo esc_attr( $type ); ?>"
            data-title="<?php echo esc_attr( $title ); ?>"
            data-color="<?php echo esc_attr( $color ); ?>"
            data-order-id="<?php echo esc_attr( $order_id ); ?>"
            data-product-id="<?php echo esc_attr( $product_id ); ?>"
        >
            <?php if ( ! is_user_logged_in() ) : ?>
                <div class="ecc-login-prompt" style="--ecc-color: <?php echo esc_attr( $color ); ?>">
                    <p>Please <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">log in</a> to start a chat.</p>
                </div>
            <?php else : ?>
                <button
                    class="ecc-inline-btn"
                    style="background: <?php echo esc_attr( $color ); ?>;"
                    data-widget-id="<?php echo esc_attr( $uid ); ?>"
                    onclick="eccOpenInlineChat('<?php echo esc_js( $uid ); ?>')"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                    </svg>
                    <?php echo esc_html( $button_text ); ?>
                </button>
                <div class="ecc-inline-panel" id="<?php echo esc_attr( $uid ); ?>-panel" style="display:none;"></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
