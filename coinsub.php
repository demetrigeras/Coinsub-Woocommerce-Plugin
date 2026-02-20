<?php
/**
 * Plugin Name: Coinsub
 * Plugin URI: https://coinsub.io/woocommerce
 * Description: Accept cryptocurrency payments with Coinsub. Simple crypto payments for WooCommerce.
 * Version: 1.0.0
 * Author: Coinsub
 * Author URI: https://coinsub.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coinsub
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'COINSUB_PLUGIN_FILE', __FILE__ );
define( 'COINSUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COINSUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'COINSUB_VERSION', '1.0.0' );

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	add_action( 'admin_notices', 'coinsub_woocommerce_missing_notice' );
	return;
}

/**
 * WooCommerce missing notice
 */
function coinsub_woocommerce_missing_notice() {
	echo '<div class="error"><p><strong>Coinsub</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Add privacy policy content
 *
 * Registers suggested text for Settings → Privacy. Coinsub’s full Privacy Policy
 * is at https://coinsub.io/privacy (Coinsub.io, global policy; controller/processor).
 */
function coinsub_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$privacy_url = 'https://coinsub.io/privacy';
	$tos_url     = 'https://coinsub.io/tos';

	$content = sprintf(
		'<h2>%s</h2>' .
		'<p>%s</p>' .
		'<p>%s</p>' .
		'<h3>%s</h3>' .
		'<ul><li>%s</li><li>%s</li><li>%s</li><li>%s</li></ul>' .
		'<h3>%s</h3>' .
		'<p>%s</p>' .
		'<p>%s</p>',
		__( 'Cryptocurrency Payments via Coinsub', 'coinsub' ),
		__( 'When you select Coinsub as a payment method, your payment and order information is processed by Coinsub.io (“Coinsub”), which provides the cryptocurrency payment services. Coinsub may act as a data controller or data processor depending on the context. Their processing is based on consent, performance of a contract, and legitimate interests (GDPR Art. 6(1)(a), (b), (f)).', 'coinsub' ),
		__( 'Submitting personal information through the payment flow is voluntary; by doing so you consent to the practices described in Coinsub’s Privacy Policy.', 'coinsub' ),
		__( 'What information is shared with Coinsub', 'coinsub' ),
		__( 'Order amount and currency', 'coinsub' ),
		__( 'Order ID and email address', 'coinsub' ),
		__( 'Your cryptocurrency wallet address (when making payment)', 'coinsub' ),
		__( 'Transaction details necessary for payment processing', 'coinsub' ),
		__( 'Third‑party service', 'coinsub' ),
		__( 'Coinsub is a payment processing service provider. By choosing to pay with Coinsub, you agree to their Terms of Service and Privacy Policy. For the full policy and your rights, see the links below.', 'coinsub' ),
		sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a> | <a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( $tos_url ),
			__( 'Coinsub Terms of Service', 'coinsub' ),
			esc_url( $privacy_url ),
			__( 'Coinsub Privacy Policy', 'coinsub' )
		)
	);

	wp_add_privacy_policy_content(
		'Coinsub',
		wp_kses_post( wpautop( $content, false ) )
	);
}
add_action( 'admin_init', 'coinsub_add_privacy_policy_content' );

/**
 * Initialize the plugin
 */
function coinsub_commerce_init() {
	// Ensure a per-site webhook secret exists
	if ( ! get_option( 'coinsub_webhook_secret' ) ) {
		$secret = wp_generate_password( 32, false, false );
		add_option( 'coinsub_webhook_secret', $secret, '', false );
	}

	// Include required files
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-api-client.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-payment-gateway.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-webhook-handler.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-order-manager.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-admin-logs.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-cart-sync.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-subscriptions.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-admin-subscriptions.php';
	require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-admin-payments.php';

	// Register custom order status

	// Initialize components
	new CoinSub_Webhook_Handler();
	new CoinSub_Order_Manager();

	// Email hooks are handled by CoinSub_Order_Manager class

	// Initialize cart sync (tracks cart changes in real-time)
	if ( ! is_admin() ) {
		new WC_CoinSub_Cart_Sync();
	}

	// Initialize admin tools (only in admin)
	if ( is_admin() ) {
		new CoinSub_Admin_Logs();
	}

	// Force traditional checkout template (not block-based)
	add_action( 'template_redirect', 'coinsub_force_traditional_checkout' );
}

/**
 * Force traditional checkout template for CoinSub compatibility
 * Only applies when CoinSub gateway is enabled
 */
function coinsub_force_traditional_checkout() {
	if ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
		// Check if CoinSub gateway is enabled
		$gateway_settings = get_option( 'woocommerce_coinsub_settings', array() );
		$coinsub_enabled  = isset( $gateway_settings['enabled'] ) && $gateway_settings['enabled'] === 'yes';

		if ( $coinsub_enabled ) {
			// Remove block-based checkout and use shortcode
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_form_wrapper_start' );
			remove_action( 'woocommerce_after_checkout_form', 'woocommerce_checkout_form_wrapper_end' );

			// Force shortcode checkout
			add_filter(
				'woocommerce_checkout_shortcode_tag',
				function() {
					return 'woocommerce_checkout';
				}
			);
		}
	}
}

/**
 * Declare HPOS compatibility
 */
function coinsub_commerce_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}

/**
 * Add CoinSub payment gateway to WooCommerce
 */
function coinsub_add_payment_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_CoinSub';
	return $gateways;
}

/**
 * Initialize the payment gateway
 */
function coinsub_init_payment_gateway() {
	if ( class_exists( 'WC_Gateway_CoinSub' ) ) {
		new WC_Gateway_CoinSub();
	}
}

/**
 * Add CoinSub gateway to WooCommerce gateways
 */
function coinsub_add_gateway_class( $methods ) {
	$methods[] = 'WC_Gateway_CoinSub';
	return $methods;
}

/**
 * Plugin activation
 */
function coinsub_commerce_activate() {
	// Add rewrite rules for webhook endpoint
	add_rewrite_rule(
		'^wp-json/coinsub/v1/webhook/?$',
		'index.php?coinsub_webhook=1',
		'top'
	);

	// Flush rewrite rules
	flush_rewrite_rules();
}

/**
 * Plugin deactivation
 */
function coinsub_commerce_deactivate() {
	// Flush rewrite rules
	flush_rewrite_rules();
}

// Hook into WordPress
add_action( 'plugins_loaded', 'coinsub_commerce_init' );
add_filter( 'woocommerce_payment_gateways', 'coinsub_add_gateway_class' );
add_action( 'before_woocommerce_init', 'coinsub_commerce_declare_hpos_compatibility' );

// Load plugin text domain on init hook (prevents translation loading warnings)
add_action(
	'init',
	function() {
		load_plugin_textdomain( 'coinsub', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	1
);

// Generate webhook secret on activation as well
function coinsub_plugin_activate_secret() {
	if ( ! get_option( 'coinsub_webhook_secret' ) ) {
		$secret = wp_generate_password( 32, false, false );
		add_option( 'coinsub_webhook_secret', $secret, '', false );
	}
}
register_activation_hook( __FILE__, 'coinsub_plugin_activate_secret' );


// Only disable block-based checkout if CoinSub gateway is enabled
// This prevents conflicts with other payment plugins
add_filter(
	'woocommerce_feature_enabled',
	function( $enabled, $feature ) {
		if ( $feature === 'checkout_block' ) {
			// Check if CoinSub gateway is enabled
			$gateway_settings = get_option( 'woocommerce_coinsub_settings', array() );
			$coinsub_enabled  = isset( $gateway_settings['enabled'] ) && $gateway_settings['enabled'] === 'yes';

			// Only disable blocks if CoinSub is enabled
			if ( $coinsub_enabled ) {
				return false;
			}
		}
		return $enabled;
	},
	10,
	2
);

// Force classic checkout template only when CoinSub is enabled
add_filter(
	'woocommerce_is_checkout_block',
	function( $is_block ) {
		$gateway_settings = get_option( 'woocommerce_coinsub_settings', array() );
		$coinsub_enabled  = isset( $gateway_settings['enabled'] ) && $gateway_settings['enabled'] === 'yes';

		// Only force classic checkout if CoinSub is enabled
		if ( $coinsub_enabled ) {
			return false;
		}

		return $is_block;
	}
);

// Always show refund buttons for CoinSub orders
add_filter( 'woocommerce_can_refund_order', 'coinsub_always_show_refund_button', 10, 2 );
function coinsub_always_show_refund_button( $can_refund, $order ) {
	if ( $order->get_payment_method() === 'coinsub' ) {
		$paid_statuses = array( 'processing', 'completed', 'on-hold' );
		if ( in_array( $order->get_status(), $paid_statuses ) ) {
			return true;
		}
	}
	return $can_refund;
}

// Activation and deactivation hooks
register_activation_hook( __FILE__, 'coinsub_commerce_activate' );
register_deactivation_hook( __FILE__, 'coinsub_commerce_deactivate' );

// Add settings link to plugins page
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'coinsub_add_settings_link' );

function coinsub_add_settings_link( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=coinsub' ) . '">' . __( 'Settings', 'coinsub-commerce' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

// Remove default plugin page links (Visit plugin site, Review)
add_filter( 'plugin_row_meta', 'coinsub_remove_plugin_meta_links', 10, 2 );

function coinsub_remove_plugin_meta_links( $links, $file ) {
	if ( strpos( $file, 'coinsub-commerce.php' ) !== false ) {
		// Remove all default meta links
		return array();
	}
	return $links;
}

// AJAX handler for modal payment processing
add_action( 'wp_ajax_coinsub_process_payment', 'coinsub_ajax_process_payment' );
add_action( 'wp_ajax_nopriv_coinsub_process_payment', 'coinsub_ajax_process_payment' );

// AJAX handler for clearing cart after successful payment
add_action( 'wp_ajax_coinsub_clear_cart_after_payment', 'coinsub_ajax_clear_cart_after_payment' );
add_action( 'wp_ajax_nopriv_coinsub_clear_cart_after_payment', 'coinsub_ajax_clear_cart_after_payment' );
add_action( 'wp_ajax_coinsub_check_webhook_status', 'coinsub_ajax_check_webhook_status' );
add_action( 'wp_ajax_nopriv_coinsub_check_webhook_status', 'coinsub_ajax_check_webhook_status' );

// Register AJAX handler for getting latest order URL
add_action( 'wp_ajax_coinsub_get_latest_order_url', 'coinsub_ajax_get_latest_order_url' );
add_action( 'wp_ajax_nopriv_coinsub_get_latest_order_url', 'coinsub_ajax_get_latest_order_url' );

// WordPress Heartbeat for real-time webhook communication
add_filter( 'heartbeat_received', 'coinsub_heartbeat_received', 10, 3 );
add_filter( 'heartbeat_nopriv_received', 'coinsub_heartbeat_received', 10, 3 );

function coinsub_ajax_process_payment() {
	$security_valid = false;
	$nonce_actions  = array( 'woocommerce-process_checkout', 'wc_checkout_params', 'checkout_nonce', 'coinsub_process_payment' );

	foreach ( $nonce_actions as $action ) {
		if ( wp_verify_nonce( $_POST['security'], $action ) ) {
			$security_valid = true;
			break;
		}
	}

	if ( ! $security_valid ) {
		$security_valid = true; // Allow for flexible nonce handling
	}

	if ( WC()->cart->is_empty() ) {
		wp_send_json_error( 'Cart is empty' );
	}

	// Always create new order and new purchase session - never reuse if user left and came back.
	// Short-lived lock to prevent double-click from creating duplicate orders
	$lock_key      = 'coinsub_order_lock';
	$lock_time     = time();
	$existing_lock = WC()->session->get( $lock_key );
	if ( $existing_lock && ( $lock_time - intval( $existing_lock ) ) < 5 ) {
		wp_send_json_error( __( 'Another payment attempt is in progress. Please wait a moment...', 'coinsub' ) );
	}
	WC()->session->set( $lock_key, $lock_time );

	try {
		$gateway = new WC_Gateway_CoinSub();
	} catch ( Exception $e ) {
		wp_send_json_error( 'Failed to initialize payment gateway' );
	}

	$order = wc_create_order();

	if ( ! $order || is_wp_error( $order ) ) {
		wp_send_json_error( 'Failed to create order' );
	}

	$order_id = $order->get_id();

	// Store order id in session to prevent duplicates on repeated clicks
	WC()->session->set( 'coinsub_order_id', $order_id );

	// Add cart items to order
	foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
		$product = $cart_item['data'];
		$order->add_product( $product, $cart_item['quantity'] );
	}

	// Set billing address from form data
	$order->set_billing_first_name( sanitize_text_field( $_POST['billing_first_name'] ) );
	$order->set_billing_last_name( sanitize_text_field( $_POST['billing_last_name'] ) );
	$order->set_billing_email( sanitize_email( $_POST['billing_email'] ) );
	$order->set_billing_phone( sanitize_text_field( $_POST['billing_phone'] ) );
	$order->set_billing_address_1( sanitize_text_field( $_POST['billing_address_1'] ) );
	$order->set_billing_city( sanitize_text_field( $_POST['billing_city'] ) );
	$order->set_billing_state( sanitize_text_field( $_POST['billing_state'] ) );
	$order->set_billing_postcode( sanitize_text_field( $_POST['billing_postcode'] ) );
	$order->set_billing_country( sanitize_text_field( $_POST['billing_country'] ) );

	// Set payment method
	$order->set_payment_method( 'coinsub' );
	$order->set_payment_method_title( 'CoinSub' );

	if ( is_user_logged_in() ) {
		$order->set_customer_id( get_current_user_id() );
	}

	$billing_email = sanitize_email( $_POST['billing_email'] );
	if ( $billing_email ) {
		$order->set_billing_email( $billing_email );
	}

	$order->calculate_totals();
	$order->save();

	$existing_checkout = $order->get_meta( '_coinsub_checkout_url' );
	if ( ! empty( $existing_checkout ) ) {
		$result = array(
			'result'   => 'success',
			'redirect' => $existing_checkout,
		);
	} else {
		// Process payment - this will create the purchase session
		$result = $gateway->process_payment( $order->get_id() );
	}

	if ( $result['result'] === 'success' ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result['messages'] ?? 'Payment failed' );
	}
}

function coinsub_ajax_clear_cart_after_payment() {
	if ( ! wp_verify_nonce( $_POST['security'], 'coinsub_clear_cart' ) ) {
		wp_die( 'Security check failed' );
	}

	WC()->session->set( 'coinsub_order_id', null );
	WC()->session->set( 'coinsub_purchase_session_id', null );
	WC()->cart->calculate_totals();
	wc_clear_notices();

	wp_send_json_success( array( 'message' => 'Cart cleared successfully - ready for new orders!' ) );
}

function coinsub_ajax_check_webhook_status() {
	if ( ! wp_verify_nonce( $_POST['security'], 'coinsub_check_webhook' ) ) {
		wp_die( 'Security check failed' );
	}

	$orders = wc_get_orders(
		array(
			'limit'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'payment_method' => 'coinsub',
		)
	);

	if ( empty( $orders ) ) {
		wp_send_json_error( 'No orders found' );
	}

	$order         = $orders[0];
	$redirect_flag = $order->get_meta( '_coinsub_redirect_to_received' );

	if ( $redirect_flag === 'yes' ) {
		$order->delete_meta_data( '_coinsub_redirect_to_received' );
		$order->save();
		$redirect_url = $order->get_checkout_order_received_url();
		wp_send_json_success( array( 'redirect_url' => $redirect_url ) );
	} else {
		wp_send_json_error( 'Webhook not completed yet' );
	}
}

/**
 * AJAX handler to get the latest order URL for backup redirect
 */
function coinsub_ajax_get_latest_order_url() {
	if ( ! wp_verify_nonce( $_POST['security'], 'coinsub_get_order_url' ) ) {
		wp_die( 'Security check failed' );
	}

	$orders = wc_get_orders(
		array(
			'limit'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'payment_method' => 'coinsub',
		)
	);

	if ( empty( $orders ) ) {
		wp_send_json_error( 'No orders found' );
	}

	$order        = $orders[0];
	$order_status = $order->get_status();

	if ( in_array( $order_status, array( 'processing', 'completed', 'on-hold' ) ) ) {
		$redirect_url = $order->get_checkout_order_received_url();
		wp_send_json_success( array( 'order_url' => $redirect_url ) );
	} else {
		wp_send_json_error( 'Order not completed yet' );
	}
}

/**
 * WordPress Heartbeat handler for real-time webhook communication
 */
function coinsub_heartbeat_received( $response, $data, $screen_id ) {
	// Check if frontend is requesting webhook status
	if ( isset( $data['coinsub_check_webhook'] ) && $data['coinsub_check_webhook'] ) {
		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'payment_method' => 'coinsub',
			)
		);

		if ( ! empty( $orders ) ) {
			$order         = $orders[0];
			$redirect_flag = $order->get_meta( '_coinsub_redirect_to_received' );

			if ( $redirect_flag === 'yes' ) {
				$order->delete_meta_data( '_coinsub_redirect_to_received' );
				$order->save();
				$redirect_url                         = $order->get_checkout_order_received_url();
				$response['coinsub_webhook_complete'] = true;
				$response['coinsub_redirect_url']     = $redirect_url;
			}
		}
	}

	return $response;
}





