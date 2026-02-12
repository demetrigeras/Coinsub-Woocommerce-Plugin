<?php
/**
 * CoinSub Subscriptions Manager
 *
 * Handles subscription products and customer subscription management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoinSub_Subscriptions {

	private $api_client;

	public function __construct() {
		// Cart validation
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_cart_items' ), 10, 3 );
		// Enforce subscription quantity limits during cart checks/updates
		add_action( 'woocommerce_check_cart_items', array( $this, 'enforce_subscription_quantities' ) );

		// Subscription details and cancel on single order view (My Account → View order)
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'view_order_subscription_section' ), 10, 1 );

		// Handle subscription cancellation (used by Cancel button on view-order page)
		add_action( 'wp_ajax_coinsub_cancel_subscription', array( $this, 'ajax_cancel_subscription' ) );

		// Cancel button script + hide CoinSub on-hold rows on Orders list
		add_action( 'wp_footer', array( $this, 'my_account_orders_footer_script' ) );

		// Add subscription fields to product
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_subscription_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_subscription_fields' ) );
	}

	/**
	 * Get API client instance
	 */
	private function get_api_client() {
		if ( $this->api_client === null ) {
			if ( ! class_exists( 'CoinSub_API_Client' ) ) {
				return null;
			}
			$this->api_client = new CoinSub_API_Client();
		}
		return $this->api_client;
	}

	/**
	 * Validate cart items - enforce subscription rules
	 */
	public function validate_cart_items( $passed, $product_id, $quantity ) {
		$product         = wc_get_product( $product_id );
		$is_subscription = $product->get_meta( '_coinsub_subscription' ) === 'yes';

		// Check what's already in cart
		$cart                  = WC()->cart->get_cart();
		$has_subscription      = false;
		$has_regular           = false;
		$has_same_subscription = false;

		foreach ( $cart as $cart_item ) {
			$cart_product = $cart_item['data'];
			if ( $cart_product->get_meta( '_coinsub_subscription' ) === 'yes' ) {
				$has_subscription = true;
				if ( (int) $cart_product->get_id() === (int) $product_id ) {
					$has_same_subscription = true;
				}
			} else {
				$has_regular = true;
			}
		}

		// Subscriptions limited to quantity 1
		if ( $is_subscription && (int) $quantity > 1 ) {
			wc_add_notice( __( 'You can only purchase one of a subscription at a time.', 'coinsub' ), 'error' );
			return false;
		}

		// Prevent adding the same subscription product twice
		if ( $is_subscription && $has_same_subscription ) {
			wc_add_notice( __( 'This subscription is already in your cart.', 'coinsub' ), 'error' );
			return false;
		}

		// Enforce rules - prevent mixing subscriptions and regular products
		if ( $is_subscription && $has_regular ) {
			wc_add_notice( __( 'Subscriptions must be purchased separately. Regular products have been removed from your cart.', 'coinsub' ), 'notice' );
			// Remove regular products from cart
			$this->remove_regular_products_from_cart();
			return true; // Allow the subscription to be added
		}

		if ( ! $is_subscription && $has_subscription ) {
			wc_add_notice( __( 'You have a subscription in your cart. Subscriptions must be purchased separately. Please checkout the subscription first.', 'coinsub' ), 'error' );
			return false;
		}

		if ( $is_subscription && $has_subscription ) {
			wc_add_notice( __( 'You can only have one subscription in your cart at a time. Please checkout your current subscription first.', 'coinsub' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Ensure any subscription line items are clamped to quantity 1
	 */
	public function enforce_subscription_quantities() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( $product && $product->get_meta( '_coinsub_subscription' ) === 'yes' ) {
				if ( (int) $cart_item['quantity'] !== 1 ) {
					$cart->set_quantity( $cart_item_key, 1, true );
					wc_add_notice( __( 'Subscription quantity has been set to 1.', 'coinsub' ), 'notice' );
				}
			}
		}
	}

	/**
	 * Remove regular products from cart when subscription is present
	 */
	private function remove_regular_products_from_cart() {
		$cart       = WC()->cart;
		$cart_items = $cart->get_cart();

		foreach ( $cart_items as $cart_item_key => $cart_item ) {
			$product         = $cart_item['data'];
			$is_subscription = $product->get_meta( '_coinsub_subscription' ) === 'yes';

			if ( ! $is_subscription ) {
				$cart->remove_cart_item( $cart_item_key );
				error_log( '🛒 Removed regular product from cart: ' . $product->get_name() );
			}
		}
	}

	/**
	 * Add subscription fields to product edit page
	 */
	public function add_subscription_fields() {
		global $post;

		echo '<div class="options_group show_if_simple">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_coinsub_subscription',
				'label'       => __( 'Coinsub Subscription', 'coinsub' ),
				'description' => __( 'Enable this to make this a recurring subscription product', 'coinsub' ),
				'value'       => get_post_meta( $post->ID, '_coinsub_subscription', true ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => '_coinsub_frequency',
				'label'       => __( 'Frequency', 'coinsub' ),
				'options'     => array(
					'1' => 'Every',
					'2' => 'Every Other',
					'3' => 'Every Third',
					'4' => 'Every Fourth',
					'5' => 'Every Fifth',
					'6' => 'Every Sixth',
					'7' => 'Every Seventh',
				),
				'value'       => get_post_meta( $post->ID, '_coinsub_frequency', true ),
				'desc_tip'    => true,
				'description' => __( 'How often the subscription renews', 'coinsub' ),
			)
		);

		$stored_interval = get_post_meta( $post->ID, '_coinsub_interval', true );
		woocommerce_wp_select(
			array(
				'id'                => '_coinsub_interval',
				'label'             => __( 'Interval', 'coinsub' ),
				'options'           => array(
					'day'   => 'Day',
					'week'  => 'Week',
					'month' => 'Month',
					'year'  => 'Year',
				),
				'value'             => $stored_interval,
				'desc_tip'          => true,
				'description'       => __( 'Time period for the subscription', 'coinsub' ),
				'custom_attributes' => array( 'required' => 'required' ),
			)
		);

		$duration_value   = get_post_meta( $post->ID, '_coinsub_duration', true );
		$duration_display = ( $duration_value === '0' || empty( $duration_value ) ) ? '' : $duration_value;

		echo '<p class="form-field _coinsub_duration_field">';
		echo '<label for="_coinsub_duration">' . __( 'Duration', 'coinsub' ) . '</label>';
		echo '<input type="text" id="_coinsub_duration" name="_coinsub_duration" value="' . esc_attr( $duration_display ) . '" placeholder="Until Cancelled" style="width: 50%;" />';
		echo '<span class="description" style="display: block; margin-top: 5px;">';
		echo __( 'Leave blank for <strong>"Until Cancelled"</strong> (subscription continues forever)<br>Or enter a number for limited payments (e.g., <strong>12</strong> = stops after 12 payments)', 'coinsub' );
		echo '</span>';
		echo '</p>';

		echo '</div>';
	}
	//
	/**
	 * Save subscription fields
	 */
	public function save_subscription_fields( $post_id ) {
		$is_subscription = isset( $_POST['_coinsub_subscription'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_coinsub_subscription', $is_subscription );

		if ( $is_subscription === 'yes' ) {
			$frequency = isset( $_POST['_coinsub_frequency'] ) ? sanitize_text_field( $_POST['_coinsub_frequency'] ) : '1';
			$interval  = isset( $_POST['_coinsub_interval'] ) ? sanitize_text_field( $_POST['_coinsub_interval'] ) : '';
			$duration  = isset( $_POST['_coinsub_duration'] ) ? sanitize_text_field( $_POST['_coinsub_duration'] ) : '';

			// Normalize interval to allowed label values
			$allowed_intervals = array( 'day', 'week', 'month', 'year' );
			$interval          = strtolower( trim( $interval ) );
			// Map accidental numeric submissions to labels
			$num_to_label = array(
				'0' => 'day',
				'1' => 'week',
				'2' => 'month',
				'3' => 'year',
			);
			if ( isset( $num_to_label[ $interval ] ) ) {
				$interval = $num_to_label[ $interval ];
			}
			if ( ! in_array( $interval, $allowed_intervals, true ) ) {
				// Require a valid selection; leave as empty and rely on required attribute in UI
				$interval = '';
			}

			// Convert empty duration to "0" (Until Cancelled)
			if ( empty( $duration ) || $duration === 'Until Cancelled' ) {
				$duration = '0';
			}

			error_log( '💾 Saving subscription product #' . $post_id );
			error_log( '  Frequency: ' . $frequency );
			error_log( '  Interval: ' . $interval );
			error_log( '  Duration: ' . $duration );

			update_post_meta( $post_id, '_coinsub_frequency', $frequency );
			update_post_meta( $post_id, '_coinsub_interval', $interval );
			update_post_meta( $post_id, '_coinsub_duration', $duration );
		}
	}

	/**
	 * Output subscription details and cancel button on My Account view-order page.
	 * Shown after the order table for CoinSub subscription orders only.
	 *
	 * @param int|WC_Order $order Order ID or order object (WooCommerce passes order_id).
	 */
	public function view_order_subscription_section( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order || ! $order instanceof WC_Order || $order->get_payment_method() !== 'coinsub' ) {
			return;
		}
		$is_subscription = $order->get_meta( '_coinsub_is_subscription' );
		$agreement_id    = $order->get_meta( '_coinsub_agreement_id' );
		if ( $is_subscription !== 'yes' || empty( $agreement_id ) ) {
			return;
		}
		$status         = $order->get_meta( '_coinsub_subscription_status' );
		$frequency_text = $this->get_subscription_frequency_text( $order );
		$duration_text  = $this->get_subscription_duration_text( $order );
		$duration_raw   = $this->get_subscription_duration_raw( $order );
		$start_date   = $order->get_date_created() ? $order->get_date_created()->date_i18n( wc_date_format() ) : '—';
		$next_payment = $this->get_next_payment_for_display( $order, $agreement_id );
		if ( empty( $duration_raw ) || $duration_raw === '0' ) {
			$regularity_text = $frequency_text;
		} else {
			$regularity_text = $frequency_text . ' ' . sprintf( __( 'for %s', 'coinsub' ), $duration_text );
		}
		?>
		<section class="coinsub-subscription-details" style="margin: 1.5em 0; padding: 1em 1.25em; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
			<h3 style="margin: 0 0 1em; font-size: 1em;"><?php esc_html_e( 'Subscription', 'coinsub' ); ?></h3>
			<div class="coinsub-subscription-fields" style="display: flex; flex-wrap: wrap; gap: 1.5em 2em;">
				<div>
					<div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e( 'Start date', 'coinsub' ); ?></div>
					<div><?php echo esc_html( $start_date ); ?></div>
				</div>
				<div>
					<div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e( 'Next payment', 'coinsub' ); ?></div>
					<div><?php echo esc_html( $next_payment ); ?></div>
				</div>
				<div>
					<div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e( 'Regularity', 'coinsub' ); ?></div>
					<div><?php echo esc_html( $regularity_text ); ?></div>
				</div>
				<?php if ( $status !== 'cancelled' ) : ?>
					<div style="align-self: flex-end; margin-left: auto;">
						<button type="button" class="button coinsub-cancel-subscription" data-agreement-id="<?php echo esc_attr( $agreement_id ); ?>" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Cancel subscription', 'coinsub' ); ?></button>
					</div>
				<?php else : ?>
					<div style="align-self: flex-end; margin-left: auto; color: #6c757d;"><em><?php esc_html_e( 'Cancelled', 'coinsub' ); ?></em></div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Get next payment date for display (customer view-order block).
	 * Uses order meta if set; otherwise fetches from agreement API (same source as merchant).
	 *
	 * @param WC_Order $order        Order object.
	 * @param string   $agreement_id Agreement ID.
	 * @return string Formatted date or "—".
	 */
	private function get_next_payment_for_display( $order, $agreement_id ) {
		$stored = $order->get_meta( '_coinsub_next_payment' );
		if ( ! empty( $stored ) ) {
			return $this->format_date_display( $stored );
		}
		$api_client = $this->get_api_client();
		if ( ! $api_client || empty( $agreement_id ) ) {
			return '—';
		}
		$agreement_response = $api_client->retrieve_agreement( $agreement_id );
		if ( is_wp_error( $agreement_response ) ) {
			return '—';
		}
		$agreement_data = isset( $agreement_response['data'] ) ? $agreement_response['data'] : $agreement_response;
		$raw            = null;
		if ( isset( $agreement_data['next_process_date'] ) ) {
			$raw = $agreement_data['next_process_date'];
		} elseif ( isset( $agreement_data['next_processing'] ) ) {
			$raw = $agreement_data['next_processing'];
		} elseif ( isset( $agreement_data['nextProcessDate'] ) ) {
			$raw = $agreement_data['nextProcessDate'];
		} elseif ( isset( $agreement_data['nextProcess'] ) ) {
			$raw = $agreement_data['nextProcess'];
		}
		if ( empty( $raw ) ) {
			return '—';
		}
		return $this->format_date_display( $raw );
	}

	/**
	 * Get raw duration value from order (e.g. "0", "12"). Empty or "0" = until cancelled.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_subscription_duration_raw( $order ) {
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->get_meta( '_coinsub_subscription' ) === 'yes' ) {
				$duration = $product->get_meta( '_coinsub_duration' );
				return $duration === '' ? '0' : $duration;
			}
		}
		return '0';
	}

	/**
	 * Get subscription duration display text (e.g. "12 payments" or "Until cancelled").
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_subscription_duration_text( $order ) {
		$raw = $this->get_subscription_duration_raw( $order );
		if ( empty( $raw ) || $raw === '0' ) {
			return __( 'Until cancelled', 'coinsub' );
		}
		return sprintf( _n( '%s payment', '%s payments', (int) $raw, 'coinsub' ), (int) $raw );
	}

	/**
	 * On My Account Orders list: hide CoinSub on-hold rows (client-side). On Orders and View-order: bind Cancel button.
	 */
	public function my_account_orders_footer_script() {
		if ( ! is_account_page() || ! function_exists( 'is_wc_endpoint_url' ) ) {
			return;
		}
		$on_orders = is_wc_endpoint_url( 'orders' );
		$on_view   = is_wc_endpoint_url( 'view-order' );
		if ( ! $on_orders && ! $on_view ) {
			return;
		}
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return;
		}

		$on_hold_ids = array();
		if ( $on_orders ) {
			$on_hold_ids = wc_get_orders(
				array(
					'customer_id'    => $customer_id,
					'status'         => 'on-hold',
					'payment_method' => 'coinsub',
					'return'         => 'ids',
				)
			);
			$on_hold_ids = is_array( $on_hold_ids ) ? array_map( 'intval', $on_hold_ids ) : array();
		}

		$cancel_nonce = wp_create_nonce( 'coinsub_cancel_subscription' );
		$ajax_url     = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function() {
			var coinsubOnHoldOrderIds = <?php echo wp_json_encode( $on_hold_ids ); ?>;

			function hideCoinsubOnHoldRows() {
				if ( coinsubOnHoldOrderIds.length === 0 ) return;
				var table = document.querySelector( '.woocommerce-orders-table, .woocommerce-MyAccount-orders' );
				if ( ! table ) return;
				var rows = table.querySelectorAll( 'tbody tr' );
				rows.forEach( function( row ) {
					var link = row.querySelector( 'a[href*="view-order"]' );
					if ( ! link ) return;
					var href = link.getAttribute( 'href' ) || '';
					var match = href.match( /view-order[\/=](\d+)/i );
					if ( match && coinsubOnHoldOrderIds.indexOf( parseInt( match[1], 10 ) ) !== -1 ) {
						row.style.display = 'none';
					}
				});
			}

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', hideCoinsubOnHoldRows );
			} else {
				hideCoinsubOnHoldRows();
			}

			jQuery( document ).ready( function( $ ) {
				$( document.body ).on( 'click', '.coinsub-cancel-subscription', function( e ) {
					e.preventDefault();
					if ( ! confirm( '<?php echo esc_js( __( 'Are you sure you want to cancel this subscription?', 'coinsub' ) ); ?>' ) ) return;
					var btn = $( this );
					var agreementId = btn.data( 'agreement-id' );
					var orderId = btn.data( 'order-id' );
					btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Cancelling...', 'coinsub' ) ); ?>' );
					$.ajax( {
						url: <?php echo wp_json_encode( $ajax_url ); ?>,
						type: 'POST',
						data: {
							action: 'coinsub_cancel_subscription',
							agreement_id: agreementId,
							order_id: orderId,
							nonce: <?php echo wp_json_encode( $cancel_nonce ); ?>
						},
						success: function( response ) {
							if ( response.success ) {
								alert( '<?php echo esc_js( __( 'Subscription cancelled successfully', 'coinsub' ) ); ?>' );
								location.reload();
							} else {
								alert( response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error cancelling subscription', 'coinsub' ) ); ?>' );
								btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Cancel subscription', 'coinsub' ) ); ?>' );
							}
						},
						error: function() {
							alert( '<?php echo esc_js( __( 'Error cancelling subscription', 'coinsub' ) ); ?>' );
							btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Cancel subscription', 'coinsub' ) ); ?>' );
						}
					} );
				} );
			} );
		})();
		</script>
		<?php
	}

	/**
	 * Format date for display (e.g. Next payment). Supports numeric timestamp or date string.
	 * Uses wc_date_format() so it matches Start date on the view-order block.
	 *
	 * @param int|string $date_value Timestamp or date string.
	 * @return string
	 */
	private function format_date_display( $date_value ) {
		if ( empty( $date_value ) ) {
			return '';
		}
		$format = wc_date_format();
		if ( is_numeric( $date_value ) ) {
			return date_i18n( $format, (int) $date_value );
		}
		$timestamp = strtotime( $date_value );
		if ( $timestamp !== false ) {
			return date_i18n( $format, $timestamp );
		}
		return (string) $date_value;
	}

	/**
	 * Get subscription frequency text from order
	 */
	private function get_subscription_frequency_text( $order ) {
		$frequency_map = array(
			'1' => 'Every',
			'2' => 'Every Other',
			'3' => 'Every Third',
			'4' => 'Every Fourth',
			'5' => 'Every Fifth',
			'6' => 'Every Sixth',
			'7' => 'Every Seventh',
		);

		$interval_map = array(
			'0'     => 'Day',
			'day'   => 'Day',
			'1'     => 'Week',
			'week'  => 'Week',
			'2'     => 'Month',
			'month' => 'Month',
			'3'     => 'Year',
			'year'  => 'Year',
		);

		// Get subscription data from order items
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->get_meta( '_coinsub_subscription' ) === 'yes' ) {
				$frequency = $product->get_meta( '_coinsub_frequency' );
				$interval  = $product->get_meta( '_coinsub_interval' );

				$frequency_text = isset( $frequency_map[ $frequency ] ) ? $frequency_map[ $frequency ] : 'Every';
				$interval_text  = isset( $interval_map[ $interval ] ) ? $interval_map[ $interval ] : 'Month';

				return $frequency_text . ' ' . $interval_text;
			}
		}

		return __( 'N/A', 'coinsub' );
	}

	/**
	 * AJAX handler for subscription cancellation
	 */
	public function ajax_cancel_subscription() {
		check_ajax_referer( 'coinsub_cancel_subscription', 'nonce' );

		$agreement_id = sanitize_text_field( $_POST['agreement_id'] );
		$order_id     = absint( $_POST['order_id'] );

		// Verify order belongs to current user
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_customer_id() != get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order', 'coinsub' ) ) );
		}

		// Call Coinsub API to cancel
		$api_client = $this->get_api_client();
		if ( ! $api_client ) {
			wp_send_json_error( array( 'message' => __( 'API client not available', 'coinsub' ) ) );
		}

		$result = $api_client->cancel_agreement( $agreement_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Update order meta
		$order->update_meta_data( '_coinsub_subscription_status', 'cancelled' );
		// Stamp local cancelled_at timestamp
		$order->update_meta_data( '_coinsub_cancelled_at', current_time( 'mysql' ) );
		$order->add_order_note( __( 'Subscription cancelled by customer', 'coinsub' ) );
		$order->save();

		wp_send_json_success( array( 'message' => __( 'Subscription cancelled successfully', 'coinsub' ) ) );
	}
}

// Initialize
new CoinSub_Subscriptions();

