<?php
/**
 * REST API bridge.
 * Exposes WP REST endpoints that the floating widget calls.
 * These proxy to the Node.js backend and also create CPT records.
 *
 * Endpoints:
 *   POST   /wp-json/ecc/v1/auth/sync          — exchange WP session for a chat JWT
 *   POST   /wp-json/ecc/v1/conversations       — start a conversation
 *   GET    /wp-json/ecc/v1/conversations        — list user's conversations
 *   POST   /wp-json/ecc/v1/messages/{conv_id}  — send a message (REST fallback)
 *   GET    /wp-json/ecc/v1/context             — return WooCommerce context for current user
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ECC_REST_API {

    private static $namespace = 'ecc/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        $ns = self::$namespace;

        // Sync WP user → get chat JWT
        register_rest_route( $ns, '/auth/sync', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'auth_sync' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
        ] );

        // Start / find a conversation
        register_rest_route( $ns, '/conversations', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_conversation' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
                'args'                => [
                    'type'       => [ 'type' => 'string',  'default' => 'support' ],
                    'order_id'   => [ 'type' => 'string',  'default' => '' ],
                    'product_id' => [ 'type' => 'string',  'default' => '' ],
                ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_conversations' ],
                'permission_callback' => [ __CLASS__, 'require_logged_in' ],
            ],
        ] );

        // WooCommerce context for the current page
        register_rest_route( $ns, '/context', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_context' ],
            'permission_callback' => [ __CLASS__, 'require_logged_in' ],
        ] );

        // Plugin settings (public — widget reads these to bootstrap)
        register_rest_route( $ns, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_public_settings' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Permission callbacks ───────────────────────

    public static function require_logged_in( $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'ecommerce-chat' ), [ 'status' => 401 ] );
        }
        return true;
    }

    // ── Auth sync ──────────────────────────────────
    // Logs the WP user into the chat backend and returns a JWT.
    // If the user doesn't exist in MongoDB yet, it creates them.

    public static function auth_sync( WP_REST_Request $request ) {
        $wp_user = wp_get_current_user();
        $api_url = get_option( 'ecc_api_url', 'http://localhost:5000' );

        // Check if chat token already cached (valid for 6 days to be safe)
        $cached = get_user_meta( $wp_user->ID, '_ecc_chat_token', true );
        $expiry = get_user_meta( $wp_user->ID, '_ecc_chat_token_expiry', true );

        if ( $cached && $expiry && time() < (int) $expiry ) {
            return rest_ensure_response( [ 'token' => $cached ] );
        }

        // Try login first
        $response = wp_remote_post( "$api_url/api/auth/login", [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'email'    => $wp_user->user_email,
                'password' => self::wp_chat_password( $wp_user->ID ),
            ] ),
            'timeout' => 10,
        ] );

        // If login fails (user doesn't exist), auto-register
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $response = wp_remote_post( "$api_url/api/auth/signup", [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'name'     => $wp_user->display_name ?: $wp_user->user_login,
                    'email'    => $wp_user->user_email,
                    'password' => self::wp_chat_password( $wp_user->ID ),
                    'role'     => self::wp_role_to_chat_role( $wp_user ),
                ] ),
                'timeout' => 10,
            ] );
        }

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'ecc_backend_error', $response->get_error_message(), [ 'status' => 502 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $body['token'] ) ) {
            return new WP_Error( 'ecc_auth_failed', 'Could not authenticate with chat server.', [ 'status' => $code ] );
        }

        // Cache token for 6 days
        update_user_meta( $wp_user->ID, '_ecc_chat_token',        $body['token'] );
        update_user_meta( $wp_user->ID, '_ecc_chat_token_expiry', time() + ( 6 * DAY_IN_SECONDS ) );

        return rest_ensure_response( [ 'token' => $body['token'], 'user' => $body['user'] ?? [] ] );
    }

    // ── Create conversation ────────────────────────

    public static function create_conversation( WP_REST_Request $request ) {
        $wp_user    = wp_get_current_user();
        $token      = get_user_meta( $wp_user->ID, '_ecc_chat_token', true );
        $api_url    = get_option( 'ecc_api_url', 'http://localhost:5000' );
        $type       = sanitize_text_field( $request->get_param( 'type' )       ?? 'support' );
        $order_id   = sanitize_text_field( $request->get_param( 'order_id' )   ?? '' );
        $product_id = sanitize_text_field( $request->get_param( 'product_id' ) ?? '' );

        if ( ! $token ) {
            return new WP_Error( 'ecc_no_token', 'Chat token not found. Please call /auth/sync first.', [ 'status' => 401 ] );
        }

        // Ask backend for an available agent of the right type
        $agents_response = wp_remote_get( "$api_url/api/users/agents?role=" . self::type_to_role( $type ), [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $agents_response ) ) {
            return new WP_Error( 'ecc_backend_error', $agents_response->get_error_message(), [ 'status' => 502 ] );
        }

        $agents = json_decode( wp_remote_retrieve_body( $agents_response ), true );
        if ( empty( $agents ) ) {
            return new WP_Error( 'ecc_no_agents', 'No agents available at this time.', [ 'status' => 503 ] );
        }

        // Pick first online agent, or first available
        $agent = current( array_filter( $agents, fn( $a ) => $a['isOnline'] ?? false ) ) ?: $agents[0];

        // Create conversation via backend
        $conv_response = wp_remote_post( "$api_url/api/conversations", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'participantId' => $agent['_id'],
                'type'          => $type,
                'orderId'       => $order_id   ?: null,
                'productId'     => $product_id ?: null,
            ] ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $conv_response ) ) {
            return new WP_Error( 'ecc_backend_error', $conv_response->get_error_message(), [ 'status' => 502 ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $conv_response ), true );

        if ( ! isset( $body['conversation'] ) ) {
            return new WP_Error( 'ecc_conv_failed', 'Could not create conversation.', [ 'status' => 500 ] );
        }

        // Create matching CPT record in WordPress
        ECC_Post_Types::get_or_create_session(
            $body['conversation']['_id'],
            $wp_user->ID,
            [ 'type' => $type, 'order_id' => $order_id, 'product_id' => $product_id ]
        );

        return rest_ensure_response( $body );
    }

    // ── Get conversations ──────────────────────────

    public static function get_conversations( WP_REST_Request $request ) {
        $wp_user = wp_get_current_user();
        $token   = get_user_meta( $wp_user->ID, '_ecc_chat_token', true );
        $api_url = get_option( 'ecc_api_url', 'http://localhost:5000' );

        if ( ! $token ) {
            return new WP_Error( 'ecc_no_token', 'Chat token not found.', [ 'status' => 401 ] );
        }

        $response = wp_remote_get( "$api_url/api/conversations", [
            'headers' => [ 'Authorization' => "Bearer $token" ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'ecc_backend_error', $response->get_error_message(), [ 'status' => 502 ] );
        }

        return rest_ensure_response( json_decode( wp_remote_retrieve_body( $response ), true ) );
    }

    // ── WooCommerce context ────────────────────────

    public static function get_context( WP_REST_Request $request ) {
        $wp_user = wp_get_current_user();
        $context = [
            'user_id'       => $wp_user->ID,
            'display_name'  => $wp_user->display_name,
            'email'         => $wp_user->user_email,
            'role'          => self::wp_role_to_chat_role( $wp_user ),
            'order_id'      => null,
            'order_status'  => null,
            'product_id'    => null,
            'product_name'  => null,
            'cart_total'    => null,
        ];

        // WooCommerce enrichment
        if ( class_exists( 'WooCommerce' ) ) {
            // Most recent order
            $orders = wc_get_orders( [ 'customer' => $wp_user->ID, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ] );
            if ( ! empty( $orders ) ) {
                $order                  = $orders[0];
                $context['order_id']    = $order->get_id();
                $context['order_status']= $order->get_status();
            }

            // Current product page
            if ( is_product() ) {
                global $post;
                $context['product_id']   = get_the_ID();
                $context['product_name'] = get_the_title();
            }

            // Cart total
            if ( WC()->cart ) {
                $context['cart_total'] = WC()->cart->get_cart_total();
            }
        }

        return rest_ensure_response( $context );
    }

    // ── Public settings ────────────────────────────

    public static function get_public_settings() {
        return rest_ensure_response( [
            'apiUrl'      => get_option( 'ecc_api_url',       'http://localhost:5000' ),
            'widgetTitle' => get_option( 'ecc_widget_title',  'Chat with us' ),
            'widgetColor' => get_option( 'ecc_widget_color',  '#6366f1' ),
            'defaultRole' => get_option( 'ecc_default_role',  'support' ),
            'isLoggedIn'  => is_user_logged_in(),
            'loginUrl'    => wp_login_url( get_permalink() ),
        ] );
    }

    // ── Helpers ────────────────────────────────────

    /**
     * Deterministic password for the chat backend.
     * Based on WP user ID + site URL + a secret — not the WP password.
     */
    private static function wp_chat_password( $user_id ) {
        return hash( 'sha256', $user_id . get_site_url() . wp_salt( 'auth' ) );
    }

    private static function wp_role_to_chat_role( $wp_user ) {
        $roles = (array) $wp_user->roles;
        if ( in_array( 'administrator', $roles ) )    return 'admin';
        if ( in_array( 'shop_manager', $roles ) )     return 'merchant';
        if ( in_array( 'seller', $roles ) )           return 'merchant';  // WC Vendors / Dokan
        if ( in_array( 'ecc_agent', $roles ) )        return 'agent';
        if ( in_array( 'ecc_designer', $roles ) )     return 'designer';
        return 'customer';
    }

    private static function type_to_role( $type ) {
        return [ 'support' => 'agent', 'designer' => 'designer', 'merchant' => 'merchant' ][ $type ] ?? 'agent';
    }
}
