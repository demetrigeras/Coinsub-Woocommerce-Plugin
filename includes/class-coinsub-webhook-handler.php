<?php
/**
 * CoinSub Webhook Handler
 *
 * Handles webhook notifications from CoinSub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoinSub_Webhook_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
		add_action( 'wp_ajax_coinsub_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_nopriv_coinsub_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_coinsub_check_payment_status', array( $this, 'check_payment_status' ) );
		add_action( 'wp_ajax_nopriv_coinsub_check_payment_status', array( $this, 'check_payment_status' ) );
	}

	/**
	 * Register webhook endpoint with WordPress REST API
	 */
	public function register_webhook_endpoint() {
		// Main webhook endpoint
		register_rest_route(
			'coinsub/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true', // Allow public access
			)
		);
	}

	/**
	 * Handle webhook requests
	 */
	public function handle_webhook( $request ) {
		// Verify per-site webhook secret
		$expected = get_option( 'coinsub_webhook_secret' );
		$provided = $request->get_param( 'secret' );
		if ( ! $provided ) {
			// Try header fallback
			$headers  = $request->get_headers();
			$provided = $headers['x-coinsub-secret'][0] ?? null;
		}
		if ( ! empty( $expected ) && hash_equals( $expected, (string) $provided ) === false ) {
			return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		// Get the request body
		$data = $request->get_json_params();

		if ( ! $data ) {
			return new WP_REST_Response( array( 'error' => 'Invalid JSON data' ), 400 );
		}

		$raw_data = $request->get_body();
		if ( ! $this->verify_webhook_signature( $raw_data ) ) {
			// Optional: return 401 when signature verification is enforced
			// return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
		}

		$this->process_webhook( $data, $request );

		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}

	/**
	 * Process webhook data.
	 * Transfer API: we only handle type "transfer" (confirmed) and "failed_transfer" (failed). Other types (payment, embed_transfer, etc.) are handled separately or ignored.
	 */
	/**
	 * @param array|null $data    Parsed JSON body.
	 * @param WP_REST_Request|null $request Request (for X-Event-ID header).
	 */
	private function process_webhook( $data, $request = null ) {
		$event_type  = $data['type'] ?? 'unknown';
		$origin_id   = $data['origin_id'] ?? null;
		$merchant_id = $data['merchant_id'] ?? null;

		$is_transfer_event = in_array( $event_type, array( 'transfer', 'failed_transfer' ), true );
		if ( ! $origin_id && ! $is_transfer_event ) {
			return;
		}

		if ( $is_transfer_event ) {
			$transfer_id = $data['transfer_id'] ?? null;
			$event_id    = $request && method_exists( $request, 'get_header' ) ? $request->get_header( 'X-Event-ID' ) : null;
			$event_id    = $event_id ? sanitize_text_field( $event_id ) : null;
			if ( $this->transfer_event_already_processed( $transfer_id, $event_id ) ) {
				return;
			}
		}

		$order = null;
		if ( $origin_id ) {
			$order = $this->find_order_by_purchase_session_id( $origin_id );
			if ( ! $order ) {
				$order = $this->find_order_by_origin_id( $origin_id );
			}
		}

		if ( ! $order && isset( $data['agreement_id'] ) ) {
			$agreement_id        = $data['agreement_id'];
			$orders_by_agreement = wc_get_orders(
				array(
					'meta_key'     => '_coinsub_agreement_id',
					'meta_value'   => $agreement_id,
					'meta_compare' => '=',
					'limit'        => 1,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);
			if ( ! empty( $orders_by_agreement ) ) {
				$order = $orders_by_agreement[0];
			}
		}

		if ( ! $order && $is_transfer_event ) {
			$transfer_id = $data['transfer_id'] ?? null;
			if ( $transfer_id ) {
				$order = $this->find_order_by_transfer_id( $transfer_id );
			}
		}

		// Fallback: find by payment_id if present in payload
		if ( ! $order && $is_transfer_event && ! empty( $data['payment_id'] ) ) {
			$orders_by_payment = wc_get_orders(
				array(
					'meta_key'     => '_coinsub_payment_id',
					'meta_value'   => $data['payment_id'],
					'meta_compare' => '=',
					'limit'        => 1,
					'orderby'      => 'date',
					'order'        => 'DESC',
				)
			);
			if ( ! empty( $orders_by_payment ) ) {
				$order = $orders_by_payment[0];
				error_log( '✅ CoinSub Webhook: Found order #' . $order->get_id() . ' by payment_id' );
			}
		}

		if ( ! $order ) {
			error_log( '❌ CoinSub Webhook: Order not found for origin ID: ' . $origin_id );
			error_log( 'CoinSub Webhook: Event type: ' . $event_type );
			error_log( 'CoinSub Webhook: Merchant ID: ' . $merchant_id );

			// Debug: List all orders with CoinSub metadata
			$all_orders = wc_get_orders(
				array(
					'limit'   => 10,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			error_log( 'CoinSub Webhook: Recent orders:' );
			foreach ( $all_orders as $test_order ) {
				$test_session_id = $test_order->get_meta( '_coinsub_purchase_session_id' );
				$test_coinsub_id = $test_order->get_meta( '_coinsub_order_id' );
				if ( $test_session_id || $test_coinsub_id ) {
					error_log( '  Order #' . $test_order->get_id() . ' - Session: ' . $test_session_id . ' - CoinSub ID: ' . $test_coinsub_id );
				}
			}

			// Try to find by WooCommerce order ID in metadata
			if ( isset( $data['metadata']['woocommerce_order_id'] ) ) {
				$wc_order_id = $data['metadata']['woocommerce_order_id'];
				error_log( 'CoinSub Webhook: Trying to find order by WooCommerce ID: ' . $wc_order_id );
				$order = wc_get_order( $wc_order_id );
				if ( $order ) {
					error_log( '✅ CoinSub Webhook: Found order by WooCommerce ID: ' . $wc_order_id );
				} else {
					error_log( '❌ CoinSub Webhook: Order not found by WooCommerce ID: ' . $wc_order_id );
				}
			}

			if ( ! $order ) {
				return;
			}
		}

		error_log( '✅ CoinSub Webhook: Found order ID: ' . $order->get_id() . ' for origin ID: ' . $origin_id );
		error_log( 'CoinSub Webhook: Order status before update: ' . $order->get_status() );

		// Ensure order is associated with a customer if possible
		if ( ! $order->get_customer_id() && $order->get_billing_email() ) {
			$user = get_user_by( 'email', $order->get_billing_email() );
			if ( $user ) {
				$order->set_customer_id( $user->ID );
				$order->save();
				error_log( '✅ CoinSub Webhook: Associated order with user ID: ' . $user->ID . ' by email: ' . $order->get_billing_email() );
			}
		}

		// Verify merchant ID matches
		$order_merchant_id = $order->get_meta( '_coinsub_merchant_id' );
		if ( $order_merchant_id && $merchant_id ) {
			// Remove mrch_ prefix if present for comparison
			$clean_webhook_merchant_id = str_replace( 'mrch_', '', $merchant_id );
			$clean_order_merchant_id   = str_replace( 'mrch_', '', $order_merchant_id );

			if ( $clean_order_merchant_id !== $clean_webhook_merchant_id ) {
				error_log( 'CoinSub Webhook: Merchant ID mismatch for order: ' . $order->get_id() );
				error_log( 'CoinSub Webhook: Order merchant ID: ' . $clean_order_merchant_id );
				error_log( 'CoinSub Webhook: Webhook merchant ID: ' . $clean_webhook_merchant_id );
				return;
			}
		}

		switch ( $event_type ) {
			case 'payment':
				$this->handle_payment_completed( $order, $data );
				break;

			case 'failed_payment':
				// Check if this failed_payment is for THIS order's payment
				$webhook_payment_id = $data['payment_id'] ?? null;
				$order_payment_id   = $order->get_meta( '_coinsub_payment_id' );

				// Only process failed_payment if:
				// 1. Order is still pending/failed (not already successful)
				// 2. AND the payment ID matches (this is the current order's payment, not a future one)

				$current_status = $order->get_status();

				// If order is already successful, always ignore (payment succeeded)
				if ( in_array( $current_status, array( 'processing', 'completed', 'on-hold' ) ) ) {
					error_log( '⚠️ CoinSub Webhook: Ignoring failed_payment webhook - order #' . $order->get_id() . ' is already ' . $current_status );
					error_log( '⚠️ CoinSub Webhook: Payment was successful on-chain, ignoring failed_payment' );

					$order->add_order_note(
						__( 'CoinSub: Ignored failed_payment webhook - payment already successful (order status: ' . $current_status . ')', 'coinsub' )
					);
					$order->save();
				}
				// If order is pending but payment IDs don't match, ignore (this is a future payment failure)
				elseif ( $order_payment_id && $webhook_payment_id && $order_payment_id !== $webhook_payment_id ) {
					error_log( '⚠️ CoinSub Webhook: Ignoring failed_payment webhook - payment ID mismatch' );
					error_log( '⚠️ CoinSub Webhook: Order payment ID: ' . $order_payment_id . ', Webhook payment ID: ' . $webhook_payment_id );
					error_log( '⚠️ CoinSub Webhook: This failure is for a different payment (likely future subscription payment)' );

					$order->add_order_note(
						__( 'CoinSub: Ignored failed_payment webhook - failure is for different payment ID (likely future subscription payment)', 'coinsub' )
					);
					$order->save();
				}
				// Otherwise, this is a real failure for this order's payment
				else {
					error_log( '❌ CoinSub Webhook: Processing failed_payment for order #' . $order->get_id() );
					$this->handle_payment_failed( $order, $data );
				}
				break;

			case 'cancellation':
				$this->handle_payment_cancelled( $order, $data );
				break;

			case 'transfer':
				$this->handle_transfer_completed( $order, $data );
				$this->mark_transfer_event_processed( $data['transfer_id'] ?? null, $request );
				break;

			case 'failed_transfer':
				$this->handle_transfer_failed( $order, $data );
				$this->mark_transfer_event_processed( $data['transfer_id'] ?? null, $request );
				break;

			default:
				error_log( 'CoinSub Webhook: Unknown event type: ' . $event_type );
		}
	}

	/**
	 * Handle payment completed
	 */
	private function handle_payment_completed( $order, $data ) {
		error_log( '🎉 CoinSub Webhook: Processing payment completion for order #' . $order->get_id() );
		error_log( 'CoinSub Webhook: Current order status: ' . $order->get_status() );

		// Check if this is a recurring payment for a subscription
		$agreement_id    = $data['agreement_id'] ?? null;
		$is_subscription = $order->get_meta( '_coinsub_is_subscription' ) === 'yes';

		// If this is a subscription payment and the order is already processed, it's a recurring payment
		// We need to create a renewal order instead of updating the original
		if ( $is_subscription && $agreement_id ) {
			$existing_agreement_id = $order->get_meta( '_coinsub_agreement_id' );
			$order_status          = $order->get_status();

			// Check if this order is already completed/processing (meaning it's the original subscription order)
			// and we have a matching agreement_id, then this is a recurring payment
			if ( $existing_agreement_id === $agreement_id &&
				in_array( $order_status, array( 'processing', 'completed', 'on-hold' ) ) ) {

				error_log( '🔄 CoinSub Webhook: This is a recurring payment for subscription order #' . $order->get_id() );
				error_log( '🔄 CoinSub Webhook: Creating renewal order...' );

				// Create renewal order
				$renewal_order = $this->create_renewal_order( $order, $data );

				if ( $renewal_order ) {
					error_log( '✅ CoinSub Webhook: Renewal order #' . $renewal_order->get_id() . ' created successfully' );
					// Process the renewal order instead of the original
					$order = $renewal_order;
				} else {
					error_log( '❌ CoinSub Webhook: Failed to create renewal order, processing original order instead' );
				}
			}
		}

		// CRITICAL: Update WooCommerce order status based on shipping requirement
		// Use wp_update_post directly as fallback if update_status fails
		$current_status = $order->get_status();
		error_log( 'CoinSub Webhook: Current order status BEFORE update: ' . $current_status );
		error_log( 'CoinSub Webhook: Order needs shipping: ' . ( method_exists( $order, 'needs_shipping' ) && $order->needs_shipping() ? 'YES' : 'NO' ) );

		// Determine target status
		$target_status = 'processing';
		if ( method_exists( $order, 'needs_shipping' ) && ! $order->needs_shipping() ) {
			$target_status = 'completed';
		}

		error_log( 'CoinSub Webhook: Target status: ' . $target_status );

		// Update status with error handling
		try {
			$status_updated = $order->update_status( $target_status, __( 'Payment received via CoinSub', 'coinsub' ) );
			error_log( 'CoinSub Webhook: update_status() returned: ' . ( $status_updated ? 'TRUE' : 'FALSE' ) );

			// Verify status was actually updated
			$order->save(); // Ensure changes are persisted
			$new_status = $order->get_status();
			error_log( 'CoinSub Webhook: Order status AFTER update: ' . $new_status );

			if ( $new_status !== $target_status ) {
				error_log( '⚠️ CoinSub Webhook: WARNING - Status update may have failed! Expected: ' . $target_status . ', Got: ' . $new_status );

				// Try direct database update as fallback
				wp_update_post(
					array(
						'ID'          => $order->get_id(),
						'post_status' => 'wc-' . $target_status,
					)
				);

				// Reload order and verify
				$order        = wc_get_order( $order->get_id() );
				$final_status = $order->get_status();
				error_log( 'CoinSub Webhook: Final status after fallback update: ' . $final_status );

				if ( $final_status === $target_status ) {
					error_log( '✅ CoinSub Webhook: Status updated successfully via fallback method' );
				} else {
					error_log( '❌ CoinSub Webhook: CRITICAL - Status update failed even with fallback!' );
					error_log( '❌ CoinSub Webhook: This may be caused by another plugin blocking status updates' );
				}
			} else {
				error_log( '✅ CoinSub Webhook: Status updated successfully to: ' . $target_status );
			}
		} catch ( Exception $e ) {
			error_log( '❌ CoinSub Webhook: Exception during status update: ' . $e->getMessage() );
			error_log( '❌ CoinSub Webhook: Stack trace: ' . $e->getTraceAsString() );
		} catch ( Error $e ) {
			error_log( '❌ CoinSub Webhook: Fatal error during status update: ' . $e->getMessage() );
			error_log( '❌ CoinSub Webhook: Stack trace: ' . $e->getTraceAsString() );
		}

		// Debug: Check payment method
		$payment_method = $order->get_payment_method();
		error_log( 'CoinSub Webhook: Order payment method: ' . $payment_method );

		// Ensure payment method is set to coinsub
		if ( $payment_method !== 'coinsub' ) {
			$order->set_payment_method( 'coinsub' );
			$order->set_payment_method_title( 'Pay with Coinsub' );
			$order->save();
			error_log( 'CoinSub Webhook: Set payment method to coinsub' );
		}

		// Payment webhook: read from transaction_details first, then fallback to top-level data (same as whitelabel).
		// Expected shape: data.transaction_details.transaction_hash, .chain_id, .network, .explorer_url, .currency, etc.
		$transaction_details = $data['transaction_details'] ?? array();

		// Transaction hash: preferred transaction_details.transaction_hash, fallback data.transaction_hash
		$transaction_hash = isset( $transaction_details['transaction_hash'] ) ? $transaction_details['transaction_hash'] : ( $data['transaction_hash'] ?? 'N/A' );
		$transaction_id   = isset( $transaction_details['transaction_id'] ) ? $transaction_details['transaction_id'] : ( $data['transaction_id'] ?? 'N/A' );

		$order->add_order_note(
			sprintf(
				__( 'CoinSub Payment Complete - Transaction Hash: %s', 'coinsub' ),
				$transaction_hash
			)
		);

		// Store payment/agreement IDs (top-level)
		if ( isset( $data['payment_id'] ) ) {
			$order->update_meta_data( '_coinsub_payment_id', $data['payment_id'] );
		}
		if ( isset( $data['agreement_id'] ) ) {
			$order->update_meta_data( '_coinsub_agreement_id', $data['agreement_id'] );
		}

		// Transaction ID: transaction_details first, then data
		if ( ! empty( $transaction_id ) && $transaction_id !== 'N/A' ) {
			$order->update_meta_data( '_coinsub_transaction_id', $transaction_id );
		}

		// Transaction hash: transaction_details first, then data
		if ( ! empty( $transaction_hash ) && $transaction_hash !== 'N/A' ) {
			$order->update_meta_data( '_coinsub_transaction_hash', $transaction_hash );
		}

		// Chain ID: transaction_details first, then data; store as string for consistency with refund flow
		$chain_id = isset( $transaction_details['chain_id'] ) ? $transaction_details['chain_id'] : ( $data['chain_id'] ?? null );
		if ( $chain_id !== null && $chain_id !== '' ) {
			$order->update_meta_data( '_coinsub_chain_id', (string) $chain_id );
		}

		// Network name (display): transaction_details.network first, fallback data.network
		$network_name = isset( $transaction_details['network'] ) ? $transaction_details['network'] : ( $data['network'] ?? null );
		if ( ! empty( $network_name ) ) {
			$order->update_meta_data( '_coinsub_network_name', $network_name );
		}

		// Explorer URL: transaction_details first, fallback data.explorer_url
		$explorer_url = isset( $transaction_details['explorer_url'] ) ? $transaction_details['explorer_url'] : ( $data['explorer_url'] ?? null );
		if ( ! empty( $explorer_url ) ) {
			$order->update_meta_data( '_coinsub_explorer_url', $explorer_url );
		}

		// Customer wallet: transaction_details.customer_wallet_address first
		$customer_wallet = isset( $transaction_details['customer_wallet_address'] ) ? $transaction_details['customer_wallet_address'] : null;
		if ( ! empty( $customer_wallet ) ) {
			$order->update_meta_data( '_customer_wallet_address', $customer_wallet );
		}

		// Store signing address from agreement message if available
		if ( isset( $data['agreement']['message']['signing_address'] ) ) {
			$order->update_meta_data( '_customer_wallet_address', $data['agreement']['message']['signing_address'] );
			error_log( '🔑 CoinSub Webhook - Stored signing address as customer wallet: ' . $data['agreement']['message']['signing_address'] );
		}

		// Store complete agreement message data for refunds
		if ( isset( $data['agreement']['message'] ) ) {
			$agreement_message = $data['agreement']['message'];
			$order->update_meta_data( '_coinsub_agreement_message', json_encode( $agreement_message ) );
			error_log( '🔑 CoinSub Webhook - Stored agreement message: ' . json_encode( $agreement_message ) );

			// Extract specific fields for easy access
			if ( isset( $agreement_message['signing_address'] ) ) {
				$order->update_meta_data( '_coinsub_signing_address', $agreement_message['signing_address'] );
			}
			if ( isset( $agreement_message['permitId'] ) ) {
				$order->update_meta_data( '_coinsub_permit_id', $agreement_message['permitId'] );
			}
		}

		// Token/currency: transaction_details.currency first, then data.currency / token_symbol (for refunds)
		$token_symbol = isset( $transaction_details['currency'] ) ? $transaction_details['currency'] : ( isset( $transaction_details['token_symbol'] ) ? $transaction_details['token_symbol'] : null );
		if ( empty( $token_symbol ) ) {
			$token_symbol = isset( $data['currency'] ) ? $data['currency'] : ( $data['token_symbol'] ?? null );
		}
		if ( ! empty( $token_symbol ) ) {
			$order->update_meta_data( '_coinsub_token_symbol', $token_symbol );
			error_log( '💎 CoinSub Webhook - Stored token_symbol: ' . $token_symbol );
		} else {
			error_log( '⚠️ CoinSub Webhook - Token symbol not found in webhook payload' );
		}

		// Store user email from webhook if available
		if ( isset( $data['user']['email'] ) ) {
			$webhook_email = $data['user']['email'];
			$order->update_meta_data( '_coinsub_user_email', $webhook_email );
			error_log( '📧 CoinSub Webhook - Stored user email from webhook: ' . $webhook_email );

			// Update billing email if not set
			if ( empty( $order->get_billing_email() ) ) {
				$order->set_billing_email( $webhook_email );
				error_log( '📧 CoinSub Webhook - Set billing email to: ' . $webhook_email );
			}
		}

		// Store user ID from webhook if available
		if ( isset( $data['user']['subscriber_id'] ) ) {
			$order->update_meta_data( '_coinsub_subscriber_id', $data['user']['subscriber_id'] );
			error_log( '🆔 CoinSub Webhook - Stored subscriber_id: ' . $data['user']['subscriber_id'] );
		}

		// Store user name from webhook if available
		if ( isset( $data['user']['first_name'] ) && isset( $data['user']['last_name'] ) ) {
			$first_name = $data['user']['first_name'];
			$last_name  = $data['user']['last_name'];
			$order->update_meta_data( '_coinsub_user_first_name', $first_name );
			$order->update_meta_data( '_coinsub_user_last_name', $last_name );
			error_log( '👤 CoinSub Webhook - Stored user name: ' . $first_name . ' ' . $last_name );

			// Update billing name if not set
			if ( empty( $order->get_billing_first_name() ) ) {
				$order->set_billing_first_name( $first_name );
				$order->set_billing_last_name( $last_name );
				error_log( '👤 CoinSub Webhook - Set billing name' );
			}
		}

		$order->save();

		// Emails are now handled by WooCommerce order status hooks

		// Clear cart and session data since payment is now complete (only if available)
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'coinsub_order_id', null );
			WC()->session->set( 'coinsub_purchase_session_id', null );
		}
		error_log( '✅ CoinSub Webhook - Cleared cart/session if available after successful payment' );

		// Set a flag to trigger redirect to order-received page
		$order->update_meta_data( '_coinsub_redirect_to_received', 'yes' );
		$order->save();

		// Emails are handled by WooCommerce order status hooks, not webhook

		// Log payment confirmation
		error_log( 'CoinSub Webhook: PAYMENT COMPLETE for order #' . $order->get_id() . ' | Transaction Hash: ' . ( $transaction_hash ?? 'N/A' ) );
	}

	/**
	 * Handle payment failed
	 * Only called if order is NOT already in a successful state
	 */
	private function handle_payment_failed( $order, $data ) {
		error_log( '❌ CoinSub Webhook: Processing payment failure for order #' . $order->get_id() );

		$failure_reason = $data['failure_reason'] ?? 'Unknown';
		error_log( '❌ CoinSub Webhook: Failure reason: ' . $failure_reason );

		// Only mark as failed if order is still pending
		// If it's already processing/completed, don't change it
		$current_status = $order->get_status();
		if ( ! in_array( $current_status, array( 'processing', 'completed', 'on-hold' ) ) ) {
			$order->update_status( 'failed', __( 'Payment Failed', 'coinsub' ) );
		} else {
			error_log( '⚠️ CoinSub Webhook: Order #' . $order->get_id() . ' already ' . $current_status . ' - not changing to failed' );
		}

		// Add order note
		$order->add_order_note(
			sprintf(
				__( 'CoinSub Payment Failed - Reason: %s', 'coinsub' ),
				$failure_reason
			)
		);

		// Store failure reason
		if ( isset( $data['failure_reason'] ) ) {
			$order->update_meta_data( '_coinsub_failure_reason', $failure_reason );
		}

		$order->save();
	}

	/**
	 * Handle payment cancelled
	 */
	private function handle_payment_cancelled( $order, $data ) {
		$order->update_status( 'cancelled', __( 'Payment Cancelled', 'coinsub' ) );

		// Add order note
		$order->add_order_note( __( 'CoinSub Payment Cancelled - Customer cancelled the payment', 'coinsub' ) );

		$order->save();
	}

	/**
	 * Handle transfer completed (Transfer API: type = "transfer").
	 * Payload: type, merchant_id, amount_in_usd, network, to_address, from_address, hash, transfer_id, wallet_id, status, status_confirmed_at.
	 * No destination_email or token in payload; match order by transfer_id (stored when initiating refund).
	 */
	private function handle_transfer_completed( $order, $data ) {
		error_log( '🔄 CoinSub Webhook: Processing transfer completed for order #' . $order->get_id() );

		// Transfer API payload: snake_case with optional camelCase fallback
		$transfer_id = isset( $data['transfer_id'] ) ? $data['transfer_id'] : ( $data['transferId'] ?? null );
		$hash        = isset( $data['hash'] ) ? $data['hash'] : 'N/A';
		$network     = isset( $data['network'] ) ? $data['network'] : null;
		$wallet_id   = isset( $data['wallet_id'] ) ? $data['wallet_id'] : ( $data['walletId'] ?? null );
		$status      = isset( $data['status'] ) ? $data['status'] : null;
		$amount      = isset( $data['amount_in_usd'] ) ? $data['amount_in_usd'] : ( $data['amount'] ?? null );

		// Check if this order has a pending refund
		$refund_id      = $order->get_meta( '_coinsub_refund_id' );
		$refund_pending = $order->get_meta( '_coinsub_refund_pending' );

		// If this is a refund transfer (has refund_id or refund_pending flag)
		if ( $refund_pending === 'yes' || ! empty( $refund_id ) ) {
			error_log( '💰 CoinSub Webhook: This is a refund transfer - refund ID: ' . ( $refund_id ?: 'N/A' ) );

			// Mark refund as successful
			$order->update_meta_data( '_coinsub_refund_status', 'completed' );
			$order->update_meta_data( '_coinsub_refund_pending', 'no' );
			if ( ! empty( $hash ) && $hash !== 'N/A' ) {
				$order->update_meta_data( '_coinsub_refund_transaction_hash', $hash );
			}

			if ( $transfer_id ) {
				$order->update_meta_data( '_coinsub_refund_transfer_id', $transfer_id );
			}

			// Add order note
			$refund_note = sprintf(
				__( '✅ CoinSub Refund Completed: Transfer ID: %1$s, Transaction Hash: %2$s. Refund has been successfully sent to customer.', 'coinsub' ),
				$transfer_id ?: 'N/A',
				$hash
			);
			$order->add_order_note( $refund_note );

			// Update order status to refunded (if not already)
			if ( $order->get_status() !== 'refunded' ) {
				$order->update_status( 'refunded', __( 'Refund completed via CoinSub', 'coinsub' ) );
			}

			error_log( '✅ CoinSub Webhook: Refund marked as successful for order #' . $order->get_id() );
		} else {
			// Regular transfer (not a refund) - update order status
			$order->update_status( 'processing', __( 'Transfer completed via CoinSub', 'coinsub' ) );

			// Add order note
			$order->add_order_note(
				sprintf(
					__( 'CoinSub transfer completed. Transfer ID: %1$s, Hash: %2$s', 'coinsub' ),
					$transfer_id ?: 'N/A',
					$hash
				)
			);
		}

		// Store transfer details (from TransferPayload and/or EmbedTransferPayload)
		if ( $transfer_id ) {
			$order->update_meta_data( '_coinsub_transfer_id', $transfer_id );
		}
		if ( ! empty( $hash ) && $hash !== 'N/A' ) {
			$order->update_meta_data( '_coinsub_transfer_hash', $hash );
		}
		if ( $wallet_id ) {
			$order->update_meta_data( '_coinsub_wallet_id', $wallet_id );
		}
		if ( $network ) {
			$order->update_meta_data( '_coinsub_network', $network );
		}

		$order->save();
	}

	/**
	 * Suppress customer order emails when the status change was due to transfer failed webhook.
	 * No email should be sent; we only show the error on the order.
	 *
	 * @param bool     $enabled Whether the email is enabled.
	 * @param WC_Order $order   Order object (may be null for some email types).
	 * @return bool
	 */
	/**
	 * Handle transfer failed (Transfer API: type = "failed_transfer").
	 * We do not change order status or refund state. We only add an order note with the error
	 * so the merchant can see that the transfer failed. Order stays e.g. completed.
	 */
	private function handle_transfer_failed( $order, $data ) {
		error_log( '❌ CoinSub Webhook: Transfer failed for order #' . $order->get_id() . ' (error note only, no status change)' );

		$failure_reason = $data['failure_reason'] ?? $data['error'] ?? $data['status'] ?? __( 'Unknown error', 'coinsub' );

		$order->add_order_note(
			sprintf(
				/* translators: %s: failure reason from API */
				__( 'Transfer failed. %s', 'coinsub' ),
				$failure_reason
			)
		);
		$order->save();
	}

	/**
	 * Find order by origin_id meta (e.g. _coinsub_origin_id = origin_id from webhook).
	 * Used when backend sends origin_id that was stored as _coinsub_origin_id (whitelabel-style).
	 */
	private function find_order_by_origin_id( $origin_id ) {
		if ( empty( $origin_id ) ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_coinsub_origin_id',
				'meta_value' => $origin_id,
				'limit'      => 1,
			)
		);
		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Find order by purchase session ID
	 */
	private function find_order_by_purchase_session_id( $purchase_session_id ) {
		// Search for order with matching purchase session ID
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_coinsub_purchase_session_id',
				'meta_value' => $purchase_session_id,
				'limit'      => 1,
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		// If not found, try with different prefix variations
		$variations = array(
			'sess_' . $purchase_session_id,
			'wc_' . $purchase_session_id,
			$purchase_session_id,
		);

		// Also try removing sess_ prefix if it exists
		if ( strpos( $purchase_session_id, 'sess_' ) === 0 ) {
			$variations[] = substr( $purchase_session_id, 5 ); // Remove 'sess_' prefix
		}

		foreach ( $variations as $variation ) {
			$orders = wc_get_orders(
				array(
					'meta_key'   => '_coinsub_purchase_session_id',
					'meta_value' => $variation,
					'limit'      => 1,
				)
			);

			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		return null;
	}

	/**
	 * Find order by Transfer API transfer_id (stored when initiating refund).
	 * Matches _coinsub_pending_transfer_id or _coinsub_refund_id to webhook transfer_id.
	 *
	 * @param string|null $transfer_id From webhook body.
	 * @return WC_Order|null
	 */
	private function find_order_by_transfer_id( $transfer_id ) {
		if ( empty( $transfer_id ) ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => '_coinsub_pending_transfer_id',
						'value'   => $transfer_id,
						'compare' => '=',
					),
					array(
						'key'     => '_coinsub_refund_id',
						'value'   => $transfer_id,
						'compare' => '=',
					),
				),
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);
		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Idempotency: whether we already processed this transfer event (by X-Event-ID or transfer_id).
	 *
	 * @param string|null $transfer_id From webhook body.
	 * @param string|null $event_id    From X-Event-ID request header.
	 * @return bool
	 */
	private function transfer_event_already_processed( $transfer_id, $event_id ) {
		if ( $event_id ) {
			$key = 'coinsub_webhook_event_' . md5( $event_id );
			if ( get_transient( $key ) ) {
				return true;
			}
		}
		if ( $transfer_id ) {
			$key = 'coinsub_webhook_transfer_' . md5( $transfer_id );
			if ( get_transient( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark transfer event as processed for idempotency (X-Event-ID and transfer_id).
	 *
	 * @param string|null           $transfer_id From webhook body.
	 * @param WP_REST_Request|null $request     For X-Event-ID header.
	 */
	private function mark_transfer_event_processed( $transfer_id, $request = null ) {
		$event_id = $request && method_exists( $request, 'get_header' ) ? $request->get_header( 'X-Event-ID' ) : null;
		$event_id = $event_id ? sanitize_text_field( $event_id ) : null;
		$ttl      = 7 * DAY_IN_SECONDS;
		if ( $event_id ) {
			set_transient( 'coinsub_webhook_event_' . md5( $event_id ), true, $ttl );
		}
		if ( $transfer_id ) {
			set_transient( 'coinsub_webhook_transfer_' . md5( $transfer_id ), true, $ttl );
		}
	}

	/**
	 * Verify webhook signature
	 */
	private function verify_webhook_signature( $raw_data ) {
		$webhook_secret = get_option( 'coinsub_webhook_secret', '' );

		if ( empty( $webhook_secret ) ) {
			// If no secret is configured, allow all webhooks (not recommended for production)
			error_log( 'CoinSub Webhook: No webhook secret configured - allowing all webhooks' );
			return true;
		}

		$signature = $_SERVER['HTTP_X_COINSUB_SIGNATURE'] ?? '';

		if ( empty( $signature ) ) {
			error_log( 'CoinSub Webhook: No signature header provided' );
			return false;
		}

		$expected_signature = hash_hmac( 'sha256', $raw_data, $webhook_secret );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			error_log( 'CoinSub Webhook: Signature verification failed' );
			return false;
		}

		return true;
	}

	/**
	 * Test API connection
	 */
	public function test_connection() {
		if ( ! wp_verify_nonce( $_POST['nonce'], 'coinsub_test_connection' ) ) {
			wp_die( 'Security check failed' );
		}

		$api_client = new CoinSub_API_Client();
		$result     = $api_client->test_connection();

		if ( $result ) {
			wp_send_json_success( 'Connection successful' );
		} else {
			wp_send_json_error( 'Connection failed' );
		}
	}

	/**
	 * Check payment status for frontend polling
	 */
	public function check_payment_status() {
		error_log( '🔍 CoinSub - Checking payment status...' );

		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['security'], 'coinsub_check_payment' ) ) {
			error_log( '❌ CoinSub - Invalid nonce' );
			wp_send_json_error( 'Invalid nonce' );
			return;
		}

		error_log( '✅ CoinSub - Nonce verified' );

		// Get the most recent order for this user
		$user_id = get_current_user_id();
		$orders  = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'pending', 'processing', 'completed' ),
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			wp_send_json_success(
				array(
					'payment_completed' => false,
					'message'           => 'No recent orders found',
				)
			);
			return;
		}

		$order = $orders[0];

		// Check if order is completed
		if ( $order->get_status() === 'completed' ) {
			wp_send_json_success(
				array(
					'payment_completed' => true,
					'redirect_url'      => $order->get_checkout_order_received_url(),
					'order_id'          => $order->get_id(),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'payment_completed' => false,
					'order_status'      => $order->get_status(),
					'order_id'          => $order->get_id(),
				)
			);
		}
	}

	// Email functions removed - now handled by WooCommerce order status hooks

	// Merchant notification function removed - now handled by WooCommerce order status hooks


	// Customer email function removed - now handled by WooCommerce order status hooks

	// WooCommerce fallback email function removed - now handled by WooCommerce order status hooks

	/**
	 * Get network name for chain ID
	 */

	/**
	 * Create a renewal order for recurring subscription payments
	 *
	 * @param WC_Order $parent_order The original subscription order
	 * @param array $payment_data Webhook payment data
	 * @return WC_Order|false The renewal order or false on failure
	 */
	private function create_renewal_order( $parent_order, $payment_data ) {
		try {
			error_log( '🔄 CoinSub: Creating renewal order from parent order #' . $parent_order->get_id() );

			// Create new order
			$renewal_order = wc_create_order();

			if ( is_wp_error( $renewal_order ) || ! $renewal_order ) {
				error_log( '❌ CoinSub: Failed to create renewal order' );
				return false;
			}

			error_log( '✅ CoinSub: Renewal order #' . $renewal_order->get_id() . ' created' );

			// Copy customer information
			$renewal_order->set_customer_id( $parent_order->get_customer_id() );
			$renewal_order->set_billing_first_name( $parent_order->get_billing_first_name() );
			$renewal_order->set_billing_last_name( $parent_order->get_billing_last_name() );
			$renewal_order->set_billing_company( $parent_order->get_billing_company() );
			$renewal_order->set_billing_address_1( $parent_order->get_billing_address_1() );
			$renewal_order->set_billing_address_2( $parent_order->get_billing_address_2() );
			$renewal_order->set_billing_city( $parent_order->get_billing_city() );
			$renewal_order->set_billing_state( $parent_order->get_billing_state() );
			$renewal_order->set_billing_postcode( $parent_order->get_billing_postcode() );
			$renewal_order->set_billing_country( $parent_order->get_billing_country() );
			$renewal_order->set_billing_email( $parent_order->get_billing_email() );
			$renewal_order->set_billing_phone( $parent_order->get_billing_phone() );

			// Copy shipping information
			if ( $parent_order->has_shipping_address() ) {
				$renewal_order->set_shipping_first_name( $parent_order->get_shipping_first_name() );
				$renewal_order->set_shipping_last_name( $parent_order->get_shipping_last_name() );
				$renewal_order->set_shipping_company( $parent_order->get_shipping_company() );
				$renewal_order->set_shipping_address_1( $parent_order->get_shipping_address_1() );
				$renewal_order->set_shipping_address_2( $parent_order->get_shipping_address_2() );
				$renewal_order->set_shipping_city( $parent_order->get_shipping_city() );
				$renewal_order->set_shipping_state( $parent_order->get_shipping_state() );
				$renewal_order->set_shipping_postcode( $parent_order->get_shipping_postcode() );
				$renewal_order->set_shipping_country( $parent_order->get_shipping_country() );
			}

			// Copy order items
			foreach ( $parent_order->get_items() as $item_id => $item ) {
				$product = $item->get_product();

				if ( ! $product ) {
					error_log( '⚠️ CoinSub: Product not found for item #' . $item_id . ', skipping' );
					continue;
				}

				$item_data = array(
					'name'         => $item->get_name(),
					'quantity'     => $item->get_quantity(),
					'total'        => $item->get_total(),
					'subtotal'     => $item->get_subtotal(),
					'tax_class'    => $item->get_tax_class(),
					'product_id'   => $product->get_id(),
					'variation_id' => $item->get_variation_id(),
				);

				// Add variation attributes if it's a variation
				if ( $item->get_variation_id() ) {
					foreach ( $item->get_meta_data() as $meta ) {
						if ( strpos( $meta->key, 'pa_' ) === 0 || strpos( $meta->key, 'attribute_' ) === 0 ) {
							$item_data['variation'][ $meta->key ] = $meta->value;
						}
					}
				}

				$renewal_order->add_product( $product, $item->get_quantity(), $item_data );
			}

			// Copy shipping methods
			foreach ( $parent_order->get_items( 'shipping' ) as $item_id => $shipping_item ) {
				$item = new WC_Order_Item_Shipping();
				$item->set_method_id( $shipping_item->get_method_id() );
				$item->set_method_title( $shipping_item->get_method_title() );
				$item->set_instance_id( $shipping_item->get_instance_id() );
				$item->set_total( $shipping_item->get_total() );
				$item->set_total_tax( $shipping_item->get_total_tax() );

				// Copy shipping item meta
				foreach ( $shipping_item->get_meta_data() as $meta ) {
					$item->add_meta_data( $meta->key, $meta->value );
				}

				$renewal_order->add_item( $item );
			}

			// Copy fees
			foreach ( $parent_order->get_items( 'fee' ) as $item_id => $fee_item ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( $fee_item->get_name() );
				$fee->set_total( $fee_item->get_total() );
				$fee->set_tax_class( $fee_item->get_tax_class() );
				$fee->set_tax_status( $fee_item->get_tax_status() );
				$fee->set_total_tax( $fee_item->get_total_tax() );
				$renewal_order->add_item( $fee );
			}

			// Set payment method
			$renewal_order->set_payment_method( 'coinsub' );
			$renewal_order->set_payment_method_title( 'Pay with Coinsub' );

			// Set currency
			$renewal_order->set_currency( $parent_order->get_currency() );

			// Calculate totals
			$renewal_order->calculate_totals();

			// Set parent/child relationship
			$renewal_order->update_meta_data( '_coinsub_parent_subscription_order', $parent_order->get_id() );
			$renewal_order->update_meta_data( '_coinsub_is_renewal_order', 'yes' );
			$renewal_order->update_meta_data( '_coinsub_agreement_id', $parent_order->get_meta( '_coinsub_agreement_id' ) );

			// Track renewal orders in parent order
			$renewal_orders = $parent_order->get_meta( '_coinsub_renewal_orders' );
			if ( ! is_array( $renewal_orders ) ) {
				$renewal_orders = array();
			}
			$renewal_orders[] = $renewal_order->get_id();
			$parent_order->update_meta_data( '_coinsub_renewal_orders', $renewal_orders );
			$parent_order->save();

			// Add order note
			$renewal_order->add_order_note(
				sprintf(
					__( 'Renewal order for subscription order #%s. Recurring payment received via CoinSub.', 'coinsub' ),
					$parent_order->get_order_number()
				)
			);

			// Add note to parent order
			$parent_order->add_order_note(
				sprintf(
					__( 'Renewal order #%s created for recurring payment.', 'coinsub' ),
					$renewal_order->get_order_number()
				)
			);

			// Store transaction details from webhook
			$transaction_details = $payment_data['transaction_details'] ?? array();
			if ( isset( $payment_data['payment_id'] ) ) {
				$renewal_order->update_meta_data( '_coinsub_payment_id', $payment_data['payment_id'] );
			}
			if ( isset( $transaction_details['transaction_id'] ) ) {
				$renewal_order->update_meta_data( '_coinsub_transaction_id', $transaction_details['transaction_id'] );
			}
			if ( isset( $transaction_details['transaction_hash'] ) ) {
				$renewal_order->update_meta_data( '_coinsub_transaction_hash', $transaction_details['transaction_hash'] );
			}
			if ( isset( $transaction_details['chain_id'] ) ) {
				$renewal_order->update_meta_data( '_coinsub_chain_id', $transaction_details['chain_id'] );
			}
			if ( isset( $transaction_details['network'] ) ) {
				$renewal_order->update_meta_data( '_coinsub_network_name', $transaction_details['network'] );
			}
			if ( isset( $transaction_details['explorer_url'] ) ) {
				$renewal_order->update_meta_data( '_coinsub_explorer_url', $transaction_details['explorer_url'] );
			}

			$renewal_order->save();

			error_log( '✅ CoinSub: Renewal order #' . $renewal_order->get_id() . ' created and linked to parent #' . $parent_order->get_id() );

			return $renewal_order;

		} catch ( Exception $e ) {
			error_log( '❌ CoinSub: Error creating renewal order: ' . $e->getMessage() );
			return false;
		}
	}

	// Note: We intentionally leave any other orders in on-hold state; no auto-cancel
}
