<?php
/**
 * Admin settings page and meta boxes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_Admin {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
        add_action( 'add_meta_boxes',        [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
    }

    // ── Admin menu ─────────────────────────────────

    public static function register_menu() {
        add_menu_page(
            __( 'eCommerce Chat', 'ecommerce-chat' ),
            __( 'eComm Chat', 'ecommerce-chat' ),
            'manage_options',
            'ecommerce-chat',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'ecommerce-chat',
            __( 'Settings', 'ecommerce-chat' ),
            __( 'Settings', 'ecommerce-chat' ),
            'manage_options',
            'ecommerce-chat',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    // ── Settings registration ──────────────────────

    public static function register_settings() {
        $settings = [
            'ecc_api_url'       => [ 'sanitize_callback' => 'esc_url_raw' ],
            'ecc_widget_title'  => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'ecc_widget_color'  => [ 'sanitize_callback' => 'sanitize_hex_color' ],
            'ecc_enable_widget' => [ 'sanitize_callback' => 'absint' ],
            'ecc_show_on'       => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'ecc_default_role'  => [ 'sanitize_callback' => 'sanitize_text_field' ],
        ];

        foreach ( $settings as $key => $args ) {
            register_setting( 'ecc_settings', $key, $args );
        }

        // Section
        add_settings_section(
            'ecc_main_section',
            __( 'Backend Connection', 'ecommerce-chat' ),
            null,
            'ecommerce-chat'
        );
        add_settings_section(
            'ecc_widget_section',
            __( 'Widget Appearance', 'ecommerce-chat' ),
            null,
            'ecommerce-chat'
        );

        // Fields
        $fields = [
            [ 'ecc_api_url',       'ecc_main_section',   __( 'Node.js API URL', 'ecommerce-chat' ),    'text',     'http://localhost:5000', __( 'URL of your Node.js backend, e.g. https://api.yourdomain.com', 'ecommerce-chat' ) ],
            [ 'ecc_enable_widget', 'ecc_widget_section',  __( 'Enable Chat Widget', 'ecommerce-chat' ), 'checkbox', '1',  '' ],
            [ 'ecc_widget_title',  'ecc_widget_section',  __( 'Widget Title', 'ecommerce-chat' ),       'text',     'Chat with us', '' ],
            [ 'ecc_widget_color',  'ecc_widget_section',  __( 'Brand Color', 'ecommerce-chat' ),        'color',    '#6366f1', '' ],
            [ 'ecc_show_on',       'ecc_widget_section',  __( 'Show Widget On', 'ecommerce-chat' ),     'select',   'all', '' ],
            [ 'ecc_default_role',  'ecc_widget_section',  __( 'Default Chat Type', 'ecommerce-chat' ),  'select2',  'support', '' ],
        ];

        foreach ( $fields as [$key, $section, $label, $type, $default, $desc] ) {
            add_settings_field(
                $key, $label,
                [ __CLASS__, 'render_field' ],
                'ecommerce-chat',
                $section,
                [ 'key' => $key, 'type' => $type, 'default' => $default, 'desc' => $desc ]
            );
        }
    }

    public static function render_field( $args ) {
        $key   = $args['key'];
        $type  = $args['type'];
        $value = get_option( $key, $args['default'] );
        $desc  = $args['desc'] ?? '';

        switch ( $type ) {
            case 'text':
                echo "<input type='text' name='" . esc_attr( $key ) . "' value='" . esc_attr( $value ) . "' class='regular-text' />";
                break;
            case 'color':
                echo "<input type='color' name='" . esc_attr( $key ) . "' value='" . esc_attr( $value ) . "' />";
                break;
            case 'checkbox':
                echo "<input type='checkbox' name='" . esc_attr( $key ) . "' value='1' " . checked( $value, '1', false ) . " />";
                break;
            case 'select':
                $options = [ 'all' => 'All pages', 'shop' => 'Shop page', 'product' => 'Product pages', 'cart' => 'Cart page' ];
                echo "<select name='" . esc_attr( $key ) . "'>";
                foreach ( $options as $v => $l ) {
                    echo "<option value='" . esc_attr( $v ) . "' " . selected( $value, $v, false ) . ">" . esc_html( $l ) . "</option>";
                }
                echo "</select>";
                break;
            case 'select2':
                $options = [ 'support' => 'Customer Support', 'designer' => 'Designer', 'merchant' => 'Merchant' ];
                echo "<select name='" . esc_attr( $key ) . "'>";
                foreach ( $options as $v => $l ) {
                    echo "<option value='" . esc_attr( $v ) . "' " . selected( $value, $v, false ) . ">" . esc_html( $l ) . "</option>";
                }
                echo "</select>";
                break;
        }

        if ( $desc ) echo "<p class='description'>" . esc_html( $desc ) . "</p>";
    }

    // ── Settings page render ───────────────────────

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $saved = isset( $_GET['settings-updated'] );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:28px;">💬</span>
                <?php esc_html_e( 'eCommerce Chat Settings', 'ecommerce-chat' ); ?>
            </h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ecommerce-chat' ); ?></p></div>
            <?php endif; ?>

            <?php self::render_status_card(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'ecc_settings' );
                do_settings_sections( 'ecommerce-chat' );
                submit_button( __( 'Save Settings', 'ecommerce-chat' ) );
                ?>
            </form>

            <?php self::render_shortcode_docs(); ?>
        </div>
        <?php
    }

    private static function render_status_card() {
        $api_url = get_option( 'ecc_api_url', 'http://localhost:5000' );
        $health  = wp_remote_get( "$api_url/api/health", [ 'timeout' => 4 ] );
        $ok      = ! is_wp_error( $health ) && wp_remote_retrieve_response_code( $health ) === 200;
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin:16px 0;display:flex;align-items:center;gap:12px;">
            <span style="font-size:22px;"><?php echo $ok ? '🟢' : '🔴'; ?></span>
            <div>
                <strong><?php esc_html_e( 'Backend Status', 'ecommerce-chat' ); ?></strong>
                <p style="margin:2px 0 0;color:#666;font-size:13px;">
                    <?php echo $ok
                        ? esc_html__( 'Connected to chat backend.', 'ecommerce-chat' )
                        : sprintf( esc_html__( 'Cannot reach %s — check your API URL and that the server is running.', 'ecommerce-chat' ), esc_html( $api_url ) ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private static function render_shortcode_docs() {
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-top:24px;">
            <h2><?php esc_html_e( 'Shortcode Usage', 'ecommerce-chat' ); ?></h2>
            <table class="widefat" style="max-width:700px;">
                <thead><tr><th>Shortcode</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>[ecc_chat]</code></td><td>Default chat widget (uses settings above)</td></tr>
                    <tr><td><code>[ecc_chat type="support"]</code></td><td>Connect to a support agent</td></tr>
                    <tr><td><code>[ecc_chat type="designer" button_text="Hire a Designer"]</code></td><td>Connect to a designer</td></tr>
                    <tr><td><code>[ecc_chat type="merchant" order_id="1234"]</code></td><td>Chat about a specific order</td></tr>
                    <tr><td><code>[ecc_chat color="#10b981" title="Need help?"]</code></td><td>Custom colour and title</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Meta boxes for Chat Session CPT ───────────────

    public static function add_meta_boxes() {
        add_meta_box(
            'ecc_session_details',
            __( 'Chat Session Details', 'ecommerce-chat' ),
            [ __CLASS__, 'render_meta_box' ],
            'ecc_chat_session',
            'normal',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        $fields = [
            '_ecc_conversation_id' => 'MongoDB Conversation ID',
            '_ecc_wp_user_id'      => 'WordPress User ID',
            '_ecc_chat_type'       => 'Chat Type',
            '_ecc_order_id'        => 'WooCommerce Order ID',
            '_ecc_product_id'      => 'Product ID',
            '_ecc_created_at'      => 'Created At',
        ];
        echo '<table class="form-table" style="font-size:13px;">';
        foreach ( $fields as $key => $label ) {
            $value = get_post_meta( $post->ID, $key, true );
            if ( ! $value ) continue;
            echo "<tr><th style='width:180px;'>" . esc_html( $label ) . "</th><td><code>" . esc_html( $value ) . "</code></td></tr>";
        }

        // Link to open in the React app
        $conv_id = get_post_meta( $post->ID, '_ecc_conversation_id', true );
        $api_url = get_option( 'ecc_api_url', 'http://localhost:5000' );
        if ( $conv_id ) {
            $chat_url = str_replace( ':5000', ':3000', $api_url ) . "/inbox/$conv_id";
            echo "<tr><th>Open in Chat App</th><td><a href='" . esc_url( $chat_url ) . "' target='_blank'>View conversation →</a></td></tr>";
        }
        echo '</table>';
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'ecommerce-chat' ) === false && get_post_type() !== 'ecc_chat_session' ) return;
        // Could load admin-specific CSS here
    }
}
