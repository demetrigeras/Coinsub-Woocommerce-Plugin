<?php
/**
 * CoinSub API Client
 *
 * Handles communication with the CoinSub API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoinSub_API_Client {

	/**
	 * API base URL
	 */
	private $api_base_url;

	/**
	 * Merchant ID
	 */
	private $merchant_id;

	/**
	 * API key (if required)
	 */
	private $api_key;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->load_settings();
	}

	/**
	 * Load settings from payment gateway or global options
	 */
	private function load_settings() {
		// Try to get settings from payment gateway first, then fallback to global options
		$gateway_settings = get_option( 'woocommerce_coinsub_settings', array() );

		// API URL is centralized - ALL merchants use the same API endpoint
		// The API determines the merchant based on Merchant ID, not domain
		$this->api_base_url = 'https://api.coinsub.io/v1'; // Production
		// $this->api_base_url = 'https://test-api.coinsub.io/v1'; // Test environment

		// Get merchant credentials from settings
		$this->merchant_id = isset( $gateway_settings['merchant_id'] ) ? $gateway_settings['merchant_id'] : '';
		$this->api_key     = isset( $gateway_settings['refunds_api_key'] ) && ! empty( $gateway_settings['refunds_api_key'] ) ? $gateway_settings['refunds_api_key'] : ( isset( $gateway_settings['api_key'] ) ? $gateway_settings['api_key'] : '' );
	}

	/**
	 * Update settings (called when gateway settings change)
	 */
	public function update_settings( $api_base_url, $merchant_id, $api_key ) {
		$this->api_base_url = $api_base_url;
		$this->merchant_id  = $merchant_id;
		$this->api_key      = $api_key;
	}

	/**
	 * Create a purchase session
	 */
	public function create_purchase_session( $order_data ) {
		$endpoint = rtrim( $this->api_base_url, '/' ) . '/purchase/session/start';

		$payload = array(
			'name'        => $order_data['name'],
			'details'     => $order_data['details'],
			'currency'    => $order_data['currency'],
			'amount'      => $order_data['amount'],
			'recurring'   => $order_data['recurring'] ?? false,
			'metadata'    => $order_data['metadata'],
			'success_url' => $order_data['success_url'],
			'cancel_url'  => $order_data['cancel_url'],
			'failure_url' => $order_data['failure_url'] ?? $order_data['cancel_url'], // Use cancel_url as fallback if failure_url not provided
		);

		// Add subscription fields if recurring
		if ( ! empty( $order_data['recurring'] ) && $order_data['recurring'] === true ) {
			if ( isset( $order_data['frequency'] ) ) {
				$payload['frequency'] = $order_data['frequency'];
			}
			if ( isset( $order_data['interval'] ) ) {
				$payload['interval'] = $order_data['interval'];
			}
			if ( isset( $order_data['duration'] ) ) {
				$payload['duration'] = $order_data['duration'];
			}
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,
		);

		if ( ! empty( $this->api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_error', isset( $data['error'] ) ? $data['error'] : 'API request failed' );
		}

		// Extract purchase session ID and URL from response
		$purchase_session_id = $data['data']['purchase_session_id'] ?? null;
		$checkout_url        = $data['data']['url'] ?? null;

		// Remove 'sess_' prefix if present (CoinSub returns sess_UUID but checkout needs just UUID)
		if ( $purchase_session_id && strpos( $purchase_session_id, 'sess_' ) === 0 ) {
			$purchase_session_id = substr( $purchase_session_id, 5 ); // Remove 'sess_' prefix
		}

		return array(
			'purchase_session_id' => $purchase_session_id,
			'checkout_url'        => $checkout_url,
			'raw_data'            => $data,
		);
	}

	/**
	 * Get purchase session status
	 */
	public function get_purchase_session_status( $purchase_session_id ) {
		$endpoint = $this->api_base_url . '/purchase/status/' . $purchase_session_id;

		$headers = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,

		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_error', isset( $data['message'] ) ? $data['message'] : 'API request failed' );
		}

		return $data;
	}


	// REMOVED: update_order_status - using WooCommerce-only approach

	/**
	 * Cancel a subscription agreement
	 */
	public function cancel_agreement( $agreement_id ) {
		// Agreements endpoint is at /v1/agreements, not /v1/commerce
		$endpoint = rtrim( $this->api_base_url, '/' ) . '/agreements/cancel/' . $agreement_id;

		$headers = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_error', isset( $data['error'] ) ? $data['error'] : 'Failed to cancel subscription' );
		}

		return $data;
	}

	/**
	 * Retrieve agreement data
	 */
	public function retrieve_agreement( $agreement_id ) {
		$endpoint = rtrim( $this->api_base_url, '/' ) . '/agreements/' . $agreement_id . '/retrieve_agreement';
		$headers  = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,
		);
		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_error', isset( $data['error'] ) ? $data['error'] : 'API request failed' );
		}
		return $data;
	}

	/**
	 * Initiate a refund transfer request
	 */
	public function refund_transfer_request( $to_address, $amount, $chain_id, $token_symbol ) {
		$endpoint = rtrim( $this->api_base_url, '/' ) . '/merchants/transfer/request';

		$headers  = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,
		);
		$payload  = array(
			'to_address' => $to_address,
			'amount'     => (float) $amount,
			'chainId'    => (int) $chain_id,
			'token'      => $token_symbol,
		);
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => json_encode( $payload ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_error', isset( $data['error'] ) ? $data['error'] : 'API request failed' );
		}
		return $data;
	}

	/**
	 * Get all payments for a merchant
	 */
	public function get_all_payments() {
		$endpoint = rtrim( $this->api_base_url, '/' ) . '/payments/all';

		$headers = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		if ( $response_code !== 200 ) {
			$error_message = isset( $data['error'] ) ? $data['error'] : 'API request failed';
			return new WP_Error( 'api_error', $error_message );
		}

		return $data;
	}

	/**
	 * Get payment details for a specific payment
	 */
	public function get_payment_details( $payment_id ) {
		$endpoint = rtrim( $this->api_base_url, '/' ) . '/payments/' . $payment_id;

		$headers = array(
			'Content-Type' => 'application/json',
			'Merchant-ID'  => $this->merchant_id,
			'API-Key'      => $this->api_key,
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_error', isset( $data['error'] ) ? $data['error'] : 'API request failed' );
		}

		return $data;
	}
}
