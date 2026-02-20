# CoinSub Plugin – Function Breakdown & Flows

One-line description per function, organized by file; then **flows** (step-by-step) and **API calls** reference.

---

# Where to start reading – start-to-finish run-through

Use this order for a full presentation or to understand the plugin from scratch.

1. **Entry point**  
   **`coinsub.php`**  
   - Read the plugin header (Plugin Name, Version, etc.).  
   - Then **`coinsub_commerce_init()`**: WooCommerce check → webhook secret → `require_once` all includes → instantiate **CoinSub_Webhook_Handler**, **CoinSub_Order_Manager**, **WC_CoinSub_Cart_Sync** (front only), **CoinSub_Admin_Logs** (admin only) → `coinsub_force_traditional_checkout` on `template_redirect`.  
   - Then **`coinsub_add_gateway_class()`**: adds `WC_Gateway_CoinSub` to WooCommerce payment methods.  
   - Then **`coinsub_init_payment_gateway()`**: instantiates the gateway (hooked from WooCommerce).  
   - Skim the AJAX handlers: **`coinsub_ajax_process_payment`** (checkout), **`coinsub_ajax_clear_cart_after_payment`**, **`coinsub_ajax_check_webhook_status`**, **`coinsub_ajax_get_latest_order_url`**, **`coinsub_heartbeat_received`** (real-time redirect after payment).

2. **Payment gateway (checkout flow)**  
   **`includes/class-coinsub-payment-gateway.php`**  
   - **`__construct()`**: id, supports, settings, API client, hooks (settings save, HPOS, footer/head, refund UI, AJAX redirect).  
   - **`init_form_fields()`**: enabled, merchant_id, api_key, webhook_url (read-only).  
   - **`process_payment()`**: cart data → **`prepare_purchase_session_from_cart()`** → **API client `create_purchase_session()`** → store session/checkout URL and subscription/cart in order → set status on-hold → empty cart → return checkout URL.  
   - **`payment_fields()`**: outputs description + checkout modal (see **coinsub-checkout-modal.php**).  
   - **`process_refund()`**: get to_address (email/wallet), chain/token from order or API → **API client `refund_transfer_request()`** → store refund_id/transfer_id; webhook confirms later.  
   - **`is_available()`**, **`can_refund()`**, **`needs_setup()`**: simple checks.

3. **API client (outbound calls)**  
   **`includes/class-coinsub-api-client.php`**  
   - **`create_purchase_session()`**: POST `/purchase/session/start` → returns `purchase_session_id`, `checkout_url`.  
   - **`refund_transfer_request()`**: POST `/merchants/transfer/request` with to_address, amount, chainId, token.  
   - **`get_payment_details()`**, **`get_all_payments()`**: GET payments for chain/token or admin.

4. **Checkout UI (frontend)**  
   **`includes/coinsub-checkout-modal.php`**  
   - Template and script: “Place order” → AJAX **`coinsub_process_payment`** → open Coinsub checkout URL (iframe or new tab) → poll/heartbeat for **`_coinsub_redirect_to_received`** → redirect to order-received page.

5. **Webhooks (inbound)**  
   **`includes/class-coinsub-webhook-handler.php`**  
   - **`handle_webhook()`**: validate secret, JSON, optional signature → **`process_webhook()`**.  
   - **`process_webhook()`**: dispatch by `type`; find order by **origin_id** (purchase session / origin), **agreement_id**, or **transfer_id** (refunds); idempotency for transfer events.  
   - **`handle_payment_completed()`**: order to processing/completed, store payment/agreement/wallet/token/chain, set redirect flag, clear cart.  
   - **`handle_transfer_completed()`**: for refunds → set refund meta and status.  
   - **`handle_transfer_failed()`**: order note only (no status change).  
   - **`handle_payment_failed()`**, **`handle_payment_cancelled()`**: set status and notes.

6. **Orders and subscriptions**  
   - **`includes/class-coinsub-order-manager.php`**: order status changes, display Coinsub/subscription info on order, cancel subscription button, refund handling (via gateway).  
   - **`includes/class-coinsub-subscriptions.php`**: product meta (frequency/interval/duration), cart rules (subscription-only cart), renewal creation from webhook, cancel subscription (customer + admin).  
   - **`includes/class-coinsub-cart-sync.php`**: keeps **`coinsub_cart_data`** in session for checkout.

7. **Admin**  
   - **`includes/class-coinsub-admin-subscriptions.php`**: admin subscription list/management.  
   - **`includes/class-coinsub-admin-payments.php`**: admin payments list.  
   - **`includes/class-coinsub-admin-logs.php`**: admin logging UI (if present).

8. **Flows and API reference**  
   - In this doc: **Part 2** (flows: checkout, refund, subscription, webhook, admin) and **Part 3 / Part 4** (outbound API calls and inbound endpoints). Use these to trace “who calls what” and “what hits the plugin from outside”.

**Summary:** Start with **coinsub.php** (bootstrap and gateway registration), then **payment gateway** (checkout + refund), then **API client**, then **checkout modal**, then **webhook handler**, then **order manager** and **subscriptions**. Use **FUNCTION-BREAKDOWN.md** tables and flow sections to jump to specific functions and flows.

---

 # APIs used by the plugin (Coinsub + WooCommerce / WordPress)

Quick reference for “what external/plugin APIs does this code use?”

## 1. Coinsub API (external HTTP – `https://api.coinsub.io/v1`)

All calls are in **`includes/class-coinsub-api-client.php`** using **`wp_remote_post()`** / **`wp_remote_get()`**. Headers: `Content-Type: application/json`, `Merchant-ID`, `API-Key` (and optional `Authorization: Bearer ...`).

| Method | Endpoint | API client method | Used for |
|--------|----------|--------------------|----------|
| POST | `/purchase/session/start` | `create_purchase_session()` | Checkout: create session, get `purchase_session_id` and `checkout_url`. |
| GET | `/purchase/status/{id}` | `get_purchase_session_status()` | Poll session status (e.g. order manager). |
| POST | `/agreements/cancel/{agreement_id}` | `cancel_agreement()` | Cancel subscription (customer or admin). |
| GET | `/agreements/{id}/retrieve_agreement` | `retrieve_agreement()` | Get agreement/next payment (view-order, admin). |
| POST | `/merchants/transfer/request` | `refund_transfer_request()` | Refund: send to_address, amount, chainId, token. |
| GET | `/payments/all` | `get_all_payments()` | List payments (admin subscription section). |
| GET | `/payments/{payment_id}` | `get_payment_details()` | Single payment (e.g. chain/token for refund). |

**Inbound from Coinsub:** Webhooks POST to your site at **`/wp-json/coinsub/v1/webhook`** (payment, failed_payment, cancellation, transfer, failed_transfer). No “Coinsub API” call from your side for that; they call you.

---

## 2. WooCommerce API (classes, functions, hooks)

**Classes / objects**

| API | Where used | Purpose |
|-----|------------|---------|
| `WC_Gateway_CoinSub` extends `WC_Payment_Gateway` | Gateway class | Payment method (checkout, refund, settings). |
| `WC_Order` / `wc_get_order()` | Everywhere | Load order by ID; get meta, status, items, totals. |
| `wc_create_order()` | coinsub.php (AJAX), webhook renewal | Create new order. |
| `WC()->cart` | Gateway, cart-sync, coinsub.php, subscriptions | Cart contents, totals, empty_cart(), get_cart(). |
| `WC()->session` | coinsub.php, gateway, cart-sync, webhook | Session data (e.g. `coinsub_order_id`, `coinsub_cart_data`). |
| `WC_Coupon` | Gateway, cart-sync | Coupon/discount data. |
| `WC_Order_Item_Shipping`, `WC_Order_Item_Fee` | Webhook (renewal order) | Add shipping/fee line items to renewal order. |

**Functions**

| Function | Typical use |
|----------|-------------|
| `wc_get_order( $id )` | Get order object. |
| `wc_get_orders( $args )` | Query orders (meta_key, payment_method, status, etc.). |
| `wc_create_order()` | Create new order. |
| `wc_add_notice( $msg, $type )` | Checkout/cart notices (error, notice). |
| `wc_price( $amount )` | Format price for display. |
| `wc_clear_notices()` | Clear WooCommerce notices. |
| `wc_date_format()` | Date format for display. |
| `get_woocommerce_currency()` | Store currency. |
| `wc_get_checkout_url()` | Checkout page URL. |
| `wc_get_page_id( 'terms' )` | Terms page (e.g. gateway availability). |
| `is_checkout()`, `is_wc_endpoint_url( 'order-received' )` | Page context. |

**Options**

| Option | Meaning |
|--------|---------|
| `woocommerce_coinsub_settings` | Gateway settings (enabled, merchant_id, api_key, webhook_url). |

**Actions (add_action)**

| Hook | Handler / purpose |
|------|--------------------|
| `plugins_loaded` | Bootstrap plugin (`coinsub_commerce_init`). |
| `before_woocommerce_init` | Declare HPOS compatibility. |
| `woocommerce_payment_gateways` | Register gateway class. |
| `woocommerce_update_options_payment_gateways_coinsub` | Save gateway settings, update API client. |
| `woocommerce_can_refund_order` | Allow refund for CoinSub orders. |
| `woocommerce_order_status_changed` | Order manager: react to status changes. |
| `woocommerce_admin_order_data_after_billing_address` | Order manager: show Coinsub info + subscription. |
| `woocommerce_order_item_add_action_buttons` | Order manager: cancel subscription button. |
| `woocommerce_add_to_cart_validation` | Subscriptions: validate cart (subscription rules). |
| `woocommerce_check_cart_items` | Subscriptions: enforce subscription quantities. |
| `woocommerce_order_details_after_order_table` | Subscriptions: view-order subscription block. |
| `woocommerce_product_options_general_product_data` | Subscriptions: product subscription fields. |
| `woocommerce_process_product_meta` | Subscriptions: save subscription meta. |
| `woocommerce_add_to_cart`, `woocommerce_cart_item_removed`, etc. | Cart sync: recalc cart data. |
| `woocommerce_checkout_update_order_review` | Cart sync: update on checkout. |
| `woocommerce_feature_enabled` | Disable checkout block when Coinsub enabled. |
| `woocommerce_is_checkout_block` | Force classic checkout when Coinsub enabled. |

**Filters (add_filter)**

| Hook | Purpose |
|------|---------|
| `woocommerce_gateway_icon` | Gateway icon HTML. |
| `woocommerce_order_item_display_meta_key` | Customize meta key display (refund). |

---

## 3. WordPress API (used by the plugin)

**Core**

| API | Use |
|-----|-----|
| `get_option()` / `add_option()` | `coinsub_webhook_secret`, `woocommerce_coinsub_settings`. |
| `add_action()` / `add_filter()` | Hooks. |
| `apply_filters( 'active_plugins', ... )` | Check if WooCommerce is active. |
| `wp_remote_post()` / `wp_remote_get()` | Coinsub API HTTP calls. |
| `wp_remote_retrieve_body()` / `wp_remote_retrieve_response_code()` | Parse API response. |
| `is_wp_error()` | Check API/order errors. |
| `wp_send_json_success()` / `wp_send_json_error()` | AJAX responses. |
| `wp_verify_nonce()` | AJAX security. |
| `wp_die()` | AJAX failure. |
| `rest_url()`, `register_rest_route()` | Webhook REST route. |
| `add_rewrite_rule()`, `flush_rewrite_rules()` | Webhook URL (activation). |
| `wp_add_privacy_policy_content()` | Privacy policy text. |
| `load_plugin_textdomain()` | Translations. |
| `plugin_dir_path()` / `plugin_dir_url()` | Paths (constants). |
| `heartbeat_received` / `heartbeat_nopriv_received` | Real-time webhook redirect. |

**Admin**

| API | Use |
|-----|-----|
| `get_current_screen()` | Order screen (refund UI). |
| `admin_url()` | Links to settings. |

---

# Part 1: Function breakdowns by file

## coinsub.php

| Function | Description |
|----------|-------------|
| `coinsub_woocommerce_missing_notice()` | Outputs admin notice when WooCommerce is not active. |
| `coinsub_add_privacy_policy_content()` | Registers Coinsub privacy policy text with WordPress. |
| `coinsub_commerce_init()` | Bootstraps plugin: webhook secret, includes, inits Webhook Handler, Order Manager, Cart Sync, Admin Logs; forces traditional checkout when Coinsub is enabled. |
| `coinsub_force_traditional_checkout()` | When on checkout and Coinsub enabled, removes block checkout wrapper and forces shortcode checkout. |
| `coinsub_commerce_declare_hpos_compatibility()` | Declares HPOS (custom order tables) compatibility. |
| `coinsub_add_payment_gateway()` | Adds `WC_Gateway_CoinSub` to the gateways array. |
| `coinsub_init_payment_gateway()` | Instantiates the CoinSub payment gateway class. |
| `coinsub_add_gateway_class()` | Registers `WC_Gateway_CoinSub` in WooCommerce payment methods. |
| `coinsub_commerce_activate()` | On activation: adds webhook rewrite rule and flushes rewrite rules. |
| `coinsub_commerce_deactivate()` | On deactivation: flushes rewrite rules. |
| `coinsub_plugin_activate_secret()` | On plugin activation: creates `coinsub_webhook_secret` option if missing. |
| `coinsub_always_show_refund_button()` | Filter: allows refund for CoinSub orders in processing/completed/on-hold. |
| `coinsub_add_settings_link()` | Adds “Settings” link to plugin row on Plugins page. |
| `coinsub_remove_plugin_meta_links()` | Removes default plugin row meta (e.g. “Visit plugin site”) for this plugin. |
| `coinsub_ajax_process_payment()` | AJAX: verifies nonce, reuses or creates order, sets billing/cart, calls gateway `process_payment`, returns redirect URL or error. |
| `coinsub_ajax_clear_cart_after_payment()` | AJAX: clears CoinSub session keys and cart-related data after successful payment. |
| `coinsub_ajax_check_webhook_status()` | AJAX: checks latest CoinSub order for `_coinsub_redirect_to_received`; returns order-received URL or error. |
| `coinsub_ajax_get_latest_order_url()` | AJAX: returns checkout order-received URL for most recent completed/processing CoinSub order. |
| `coinsub_heartbeat_received()` | Heartbeat filter: if frontend sends `coinsub_check_webhook`, checks for webhook completion and returns redirect URL. |

---

## class-coinsub-webhook-handler.php

| Method | Description |
|--------|-------------|
| `__construct()` | Registers REST webhook route and AJAX actions for test/check status. |
| `register_webhook_endpoint()` | Registers POST `/coinsub/v1/webhook` REST route. |
| `handle_webhook()` | Validates secret, parses JSON, optionally verifies signature, calls `process_webhook()`, returns 200. |
| `process_webhook()` | Dispatches by `type`: finds order (origin_id, agreement_id, transfer_id, payment_id, metadata), checks idempotency for transfer events, then calls the right handler. |
| `handle_payment_completed()` | Updates order to processing/completed, stores payment/agreement/transaction/chain/network/wallet/token/user data, sets redirect flag, clears cart/session. |
| `handle_payment_failed()` | If order not already successful, sets status to failed and adds note with failure reason. |
| `handle_payment_cancelled()` | Sets order status to cancelled and adds cancellation note. |
| `handle_transfer_completed()` | For refund: sets refund meta, hash, transfer_id, note, status refunded; for other transfers: sets processing and transfer meta. |
| `handle_transfer_failed()` | Adds a single order note with failure reason; does not change status or refund state. |
| `find_order_by_origin_id()` | Returns order where `_coinsub_origin_id` equals given origin_id. |
| `find_order_by_purchase_session_id()` | Finds order by `_coinsub_purchase_session_id`, with prefix variations (sess_, wc_, etc.). |
| `find_order_by_transfer_id()` | Finds order by `_coinsub_pending_transfer_id` or `_coinsub_refund_id` equal to webhook transfer_id. |
| `transfer_event_already_processed()` | Returns true if transient exists for X-Event-ID or transfer_id (idempotency). |
| `mark_transfer_event_processed()` | Sets transients for X-Event-ID and transfer_id (7-day TTL) after handling transfer/failed_transfer. |
| `verify_webhook_signature()` | Verifies `X-CoinSub-Signature` HMAC-SHA256 against webhook secret; returns true if no secret configured. |
| `test_connection()` | AJAX: runs API client `test_connection()` and returns JSON success/error. |
| `check_payment_status()` | AJAX: for current user’s latest order, returns completed flag and redirect URL or status. |
| `create_renewal_order()` | Creates a new order from a subscription parent, copies address/items/shipping/fees, links via meta, stores payment/transaction data from webhook. |

---

## class-coinsub-payment-gateway.php

| Method | Description |
|--------|-------------|
| `__construct()` | Sets id, icon, supports; loads form fields/settings; inits API client; hooks settings save, HPOS, footer/head scripts, refund UI, AJAX redirect. |
| `admin_options()` | Outputs parent form, then injects setup instructions via JS and ensures form action/method for saving. |
| `init_form_fields()` | Defines enabled, merchant_id, api_key, webhook_url (read-only with secret). |
| `get_api_base_url()` | Returns production API base URL (e.g. https://api.coinsub.io/v1). |
| `get_setup_instructions_html()` | Returns HTML for setup steps (credentials, webhook, checkout page, enable), subscription setup, and “Add tokens for refunds” (Meld). |
| `maybe_process_admin_options()` | On gateway settings page, if save clicked, calls `process_admin_options()` as backup. |
| `update_api_client_settings()` | Reloads gateway settings and updates API client base URL, merchant_id, api_key. |
| `process_admin_options()` | Preserves existing API key if not in POST; calls parent save; then `update_api_client_settings()`. |
| `declare_hpos_compatibility()` | Declares HPOS compatibility via FeaturesUtil. |
| `process_payment()` | Gets/calculates cart data, creates purchase session **API**, stores session/checkout URL/merchant/subscription/cart in order, sets on-hold, empties cart, returns redirect URL. |
| `ensure_products_exist()` | For each order item, creates CoinSub product if not already in order meta (unused in current flow). |
| `prepare_purchase_session_data()` | Builds session payload from order (products, totals, addresses, subscription interval/frequency/duration, success/cancel/failure URLs). |
| `store_checkout_url()` | Stores checkout URL in transient by user/session (legacy). |
| `add_checkout_script()` | On order-received page, if `_coinsub_pending_redirect` set, opens CoinSub checkout in new tab and shows notice. |
| `payment_fields()` | Outputs short description and includes checkout modal template. |
| `process_refund()` | Validates order and to_address (email/wallet); gets chain/token from meta or **get_payment_details**; calls **refund_transfer_request**; stores refund_id/transfer_id and pending meta; does not set status refunded (webhook does). |
| `get_meld_onramp_url()` | Builds Meld URL for buying USDC on Polygon with optional wallet and amount. |
| `generate_uuid4()` | Returns a UUID v4 string. |
| `get_token_symbol_for_currency()` | Maps currency code (USD, EUR, etc.) to token symbol (default USDC). |
| `get_network_name()` | Maps chain_id to human-readable network name. |
| `can_refund()` | Returns true for CoinSub orders in processing/completed/on-hold; else parent. |
| `hide_manual_refund_ui_for_coinsub()` | On order screens: adds body class and CSS/JS to hide manual refund and show API refund for CoinSub. |
| `hide_manual_refund_js_for_coinsub()` | Footer JS: removes manual refund controls for CoinSub orders, runs on interval and on refund click. |
| `customize_refund_meta_key()` | Filter: placeholder to customize refund meta key display for CoinSub (no change now). |
| `add_payment_button_styles()` | Outputs CSS for gateway visibility and “Place order” styling; JS to inject logo and payment_method class. |
| `add_refund_transaction_hash()` | Adds order note and `_refund_transaction_hash` meta (e.g. for manual refunds). |
| `get_refund_instructions()` | Returns title/steps/note for manual refund process (legacy). |
| `needs_setup()` | Returns true if merchant_id is empty. |
| `is_available()` | Returns true if enabled, merchant_id and api_key set, and parent `is_available()` true; logs on checkout. |
| `redirect_after_payment()` | Finds latest completed order for current user and returns its order-received URL. |
| `redirect_after_payment_ajax()` | AJAX wrapper for `redirect_after_payment()`. |
| `calculate_cart_totals()` | Returns array: subtotal, shipping, tax, discount, fees, total, currency, has_subscription, subscription_data, items, coupons. |
| `get_cart_items_data()` | Returns array of cart line items (name, qty, price, line_subtotal, line_total, line_discount). |
| `prepare_purchase_session_from_cart()` | Builds purchase session payload from cart (totals, addresses, subscription fields) for current order. |
| `get_order_details_text()` | Builds a single-line details string from cart items, discount, fees, shipping, tax. |
| `validate_fields()` | No validation; returns true. |
| `get_title()` | Returns gateway title or “Pay with Coinsub”. |
| `get_icon()` | Returns img tag for Coinsub icon. |
| `get_order_button_text()` | Returns “Pay with Coinsub”. |

---

## class-coinsub-api-client.php

| Method | Description |
|--------|-------------|
| `__construct()` | Calls `load_settings()`. |
| `load_settings()` | Reads api_base_url (fixed production), merchant_id and api_key (with refunds_api_key fallback) from gateway options. |
| `update_settings()` | Sets api_base_url, merchant_id, api_key (used when gateway settings are saved). |
| `create_purchase_session()` | **API:** POST /purchase/session/start with order data and subscription fields; returns purchase_session_id, checkout_url, raw_data. |
| `get_purchase_session_status()` | **API:** GET /purchase/status/{id}; returns response data. |
| `test_connection()` | **API:** GET /purchase/status/test; returns true if 200. |
| `cancel_agreement()` | **API:** POST /agreements/cancel/{agreement_id}; returns response or WP_Error. |
| `retrieve_agreement()` | **API:** GET /agreements/{id}/retrieve_agreement; returns agreement data or WP_Error. |
| `refund_transfer_request()` | **API:** POST /merchants/transfer/request with to_address, amount, chainId, token; returns response or WP_Error. |
| `get_all_payments()` | **API:** GET /payments/all; returns payments data or WP_Error. |
| `get_payment_details()` | **API:** GET /payments/{payment_id}; returns payment data or WP_Error. |

---

## class-coinsub-order-manager.php

| Method | Description |
|--------|-------------|
| `__construct()` | Hooks: order status changed, display CoinSub info after billing, cancel subscription button, AJAX admin cancel, display subscription status. |
| `handle_order_status_change()` | For CoinSub orders only: on processing/cancelled/refunded calls handle_order_processing, handle_order_cancellation, or handle_order_refund. |
| `handle_order_processing()` | No-op; WooCommerce handles order emails (merchant configures in Settings > Emails). |
| `handle_order_cancellation()` | Adds order note that CoinSub session may still be active (no API cancel). |
| `handle_order_refund()` | Adds order note that refund may need manual processing (actual refund is via gateway + webhook). |
| `display_coinsub_info()` | In admin order after billing: shows “Coinsub Payment” and transaction hash link (explorer URL from get_explorer_url). |
| `add_cancel_subscription_button()` | Renders “Cancel Subscription” button + inline AJAX script for admin; only if agreement_id present and not already cancelled. |
| `ajax_admin_cancel_subscription()` | Verifies nonce and capability; calls **API cancel_agreement**; sets _coinsub_subscription_status cancelled, note; add_subscription_cancelled_message, add_subscription_payments_section; returns JSON success. |
| `add_subscription_cancelled_message()` | Stores _coinsub_cancelled_message HTML (red “Subscription cancelled” box) on order if not already set. |
| `add_subscription_payments_section()` | Calls **API get_all_payments**, filters by agreement_id, generates HTML via generate_payments_html, saves to _coinsub_payments_display. |
| `generate_payments_html()` | Builds table HTML of payment id, amount, status, date for given payments array. |
| `display_subscription_status()` | For CoinSub orders: display_renewal_order_relationship, then cancelled message and payments display; else maybe_display_subscription_payments. |
| `display_renewal_order_relationship()` | If renewal order: shows link to parent subscription order; if subscription: lists renewal orders with links and status. |
| `maybe_display_subscription_payments()` | If subscription with agreement_id: **API get_all_payments**, filter by agreement_id, output generate_payments_html (once per order per load). |
| `get_explorer_url()` | Builds blockchain explorer URL: prefers _coinsub_explorer_url, else _coinsub_network_name → build_explorer_url_from_network, else chain_id → get_network_from_chain_id → build_explorer_url_from_network. |
| `build_explorer_url_from_network()` | Returns OKLink URL: https://www.oklink.com/en/{network}/tx/{hash}. |
| `get_network_from_chain_id()` | Maps chain_id (e.g. 137) to network slug (e.g. polygon). |
| `get_coinsub_order_status()` | **API get_purchase_session_status** for order’s _coinsub_purchase_session_id; returns response or null. |
| `sync_order_status()` | Gets status via get_coinsub_order_status; if completed/failed/expired updates WooCommerce order status accordingly. |
| `add_refund_request_button()` | No-op; customer refund request removed (refunds via admin only). |
| `ajax_request_refund()` | Legacy: would set _coinsub_refund_requested and send_refund_notification (not used in current flow). |
| `send_refund_notification()` | Sends wp_mail to admin with refund request details (used only from ajax_request_refund). |
| `display_refund_info()` | No-op; custom refund section removed in favor of WooCommerce refund UI. |

---

## class-coinsub-subscriptions.php

| Method | Description |
|--------|-------------|
| `__construct()` | Hooks: add_to_cart_validation, check_cart_items (enforce quantities), view_order_subscription_section, AJAX coinsub_cancel_subscription, wp_footer my_account_orders_footer_script; product options and save. |
| `get_api_client()` | Returns singleton CoinSub_API_Client instance (or null if class missing). |
| `validate_cart_items()` | Subscription qty 1 only; no duplicate same subscription; no mix subscription + regular (removes regular or blocks add); one subscription per cart. |
| `enforce_subscription_quantities()` | Loops cart; for subscription products sets quantity to 1 and adds notice. |
| `remove_regular_products_from_cart()` | Removes all non-subscription items from cart (called when adding subscription to mixed cart). |
| `add_subscription_fields()` | Product edit: checkbox “Coinsub Subscription”, select Frequency, Interval, Duration (text; blank = until cancelled). |
| `save_subscription_fields()` | Saves _coinsub_subscription, _coinsub_frequency, _coinsub_interval, _coinsub_duration; normalizes interval/duration. |
| `view_order_subscription_section()` | After order table on view-order: for CoinSub subscription orders shows Start date, Next payment (**retrieve_agreement**), Regularity, Cancel button or “Cancelled”. |
| `get_next_payment_for_display()` | Uses _coinsub_next_payment if set; else **API retrieve_agreement** and reads next_process_date / next_processing / nextProcessDate / nextProcess; format_date_display. |
| `get_subscription_duration_raw()` | From order items, returns product _coinsub_duration or "0". |
| `get_subscription_duration_text()` | Returns “Until cancelled” or “N payments” from get_subscription_duration_raw. |
| `my_account_orders_footer_script()` | On My Account orders/view-order: outputs JS that hides CoinSub on-hold rows on orders list and binds .coinsub-cancel-subscription to **AJAX coinsub_cancel_subscription**. |
| `format_date_display()` | Formats timestamp or date string with wc_date_format(). |
| `get_subscription_frequency_text()` | From order items’ product meta: frequency + interval → e.g. “Every Month”. |
| `ajax_cancel_subscription()` | Verifies nonce; checks order belongs to current user; **API cancel_agreement**; sets _coinsub_subscription_status cancelled, _coinsub_cancelled_at, order note; returns JSON success. |

---

## coinsub-checkout-modal.php (template + inline JS)

| Function / block | Description |
|-------------------|-------------|
| (CSS) | Styles #coinsub-checkout-container, iframe height; hides place-order when body.coinsub-iframe-visible. |
| `removeCoinSubLogo()` | Removes img.coinsub-button-logo from #place_order so WooCommerce controls button text. |
| `ensurePlaceOrderButtonVisibility()` | If payment !== coinsub: hide iframe, remove coinsub-iframe-visible. If coinsub: show/hide place-order row and button based on iframe visibility. |
| `setupButtonTextWatcher()` | No-op (kept for compatibility). |
| `initializeButtonText()` | If coinsub selected: ensurePlaceOrderButtonVisibility(); else removeCoinSubLogo, hide iframe. |
| Click `#place_order` (coinsub) | preventDefault; AJAX action `coinsub_process_payment` with billing/shipping fields; on success: create iframe with checkout URL, show container, hide button, setupIframeRedirectDetection. |
| `setupIframeRedirectDetection()` | postMessage listener for event.data.type === 'redirect'; setInterval to read iframe location or body text for “order-received” / completion phrases and redirect parent. |
| `handleIframeLoad()` | Logs load and calls setupIframeRedirectDetection. |
| `checkForCoinSubCheckout()` | Referenced but not defined in snippet (likely no-op or defined elsewhere). |
| `updated_checkout` / `payment_method_selected` | Re-run initializeButtonText for coinsub or remove logo and hide iframe. |
| `window.showPaymentButton` | Debug: show place-order, hide iframe, ensurePlaceOrderButtonVisibility. |

---

# Part 2: App flows (step-by-step with functions)

## Flow 1: Checkout (customer pays with CoinSub)

1. **Page load (checkout)**  
   Gateway `is_available()` → cart sync has run → `payment_fields()` loads **coinsub-checkout-modal.php**.

2. **Customer selects CoinSub**  
   JS: `input[name="payment_method"]` change → `ensurePlaceOrderButtonVisibility()` → show Place order button.

3. **Customer clicks Place order**  
   JS: `#place_order` click (only when payment_method === 'coinsub') → AJAX `action: 'coinsub_process_payment'` (+ billing/shipping).

4. **Backend: create order + session**  
   `coinsub_ajax_process_payment()` → optional reuse of session order → `wc_create_order()`, set billing, `$gateway->process_payment($order_id)` → **WC_Gateway_CoinSub::process_payment()** → `calculate_cart_totals()` / session cart data → `prepare_purchase_session_from_cart()` → **CoinSub_API_Client::create_purchase_session()** (API).

5. **API response**  
   Returns `purchase_session_id`, `checkout_url`. Gateway stores them in order meta, sets status on-hold, empties cart; returns `redirect` / `coinsub_checkout_url` to AJAX.

6. **Frontend after AJAX success**  
   JS: create iframe with `checkout_url`, show container, hide Place order, `setupIframeRedirectDetection()` (postMessage + iframe URL/content checks).

7. **Customer pays in iframe**  
   CoinSub backend confirms payment and sends **webhook** to site.

8. **Webhook: payment confirmed**  
   `CoinSub_Webhook_Handler::handle_webhook()` → `process_webhook()` → find order by `origin_id` / `purchase_session_id` → `handle_payment_completed()` → order status processing/completed, store transaction/payment/agreement meta, set `_coinsub_redirect_to_received`, clear cart/session.

9. **Frontend detects completion**  
   Either iframe redirect to order-received, or postMessage, or polling/heartbeat (`coinsub_ajax_check_webhook_status` / `coinsub_heartbeat_received`) → redirect to order-received.

10. **Order status change (optional)**  
    `CoinSub_Order_Manager::handle_order_status_change()` → `handle_order_processing()` (logging only; emails by WooCommerce).

---

## Flow 2: Refund (admin initiates, Transfer API + webhook)

1. **Admin opens order → Refund**  
   Gateway `can_refund()` true for CoinSub paid orders; `hide_manual_refund_ui_for_coinsub()` / `hide_manual_refund_js_for_coinsub()` hide manual refund.

2. **Admin submits refund**  
   WooCommerce calls **WC_Gateway_CoinSub::process_refund()**.

3. **Gateway: resolve chain/token**  
   From order meta `_coinsub_chain_id`, `_coinsub_token_symbol`; if missing, **CoinSub_API_Client::get_payment_details($payment_id)** (API).

4. **Gateway: call Transfer API**  
   **CoinSub_API_Client::refund_transfer_request($to_address, $amount, $chain_id, $token_symbol)** (API).

5. **Gateway: store pending refund**  
   Order meta: `_coinsub_refund_pending` yes, `_coinsub_refund_id`, `_coinsub_pending_transfer_id`, etc.; do not set status to refunded.

6. **CoinSub sends webhook**  
   `type: "transfer"` or `type: "failed_transfer"` → `process_webhook()` → idempotency check → find order by **find_order_by_transfer_id()** (or payment_id).

7. **Transfer success**  
   `handle_transfer_completed()` → set refund meta, hash, status refunded, note; `mark_transfer_event_processed()`.

8. **Transfer failed**  
   `handle_transfer_failed()` → only add order note with error; no status change, no “refunded”.

---

## Flow 3: Subscription (product → cart → payment → view order → cancel)

1. **Product setup**  
   **CoinSub_Subscriptions::add_subscription_fields()** / **save_subscription_fields()** → product has _coinsub_subscription, frequency, interval, duration.

2. **Cart**  
   **validate_cart_items()**: subscription qty 1, no mix with regular; **enforce_subscription_quantities()**: force qty 1.

3. **Checkout**  
   Same as Flow 1; purchase session includes recurring/interval/frequency/duration; webhook may include `agreement_id` → stored on order.

4. **Recurring payment**  
   Webhook `type: "payment"` with `agreement_id`; `handle_payment_completed()` sees existing subscription order → **create_renewal_order()** and updates renewal order.

5. **My Account → View order**  
   **view_order_subscription_section()**: start date, next payment (**get_next_payment_for_display()** → **API retrieve_agreement**), regularity, duration, Cancel button or “Cancelled”.

6. **Customer cancel**  
   JS `.coinsub-cancel-subscription` → AJAX `coinsub_cancel_subscription` → **CoinSub_Subscriptions::ajax_cancel_subscription()** → **API cancel_agreement()** → set _coinsub_subscription_status cancelled, note.

7. **Admin cancel**  
   **CoinSub_Order_Manager::add_cancel_subscription_button()** → AJAX `coinsub_admin_cancel_subscription` → **ajax_admin_cancel_subscription()** → **API cancel_agreement()** → **add_subscription_cancelled_message()**, **add_subscription_payments_section()** (**get_all_payments**).

---

## Flow 4: Webhook (inbound from CoinSub)

1. **Request**  
   POST to `/wp-json/coinsub/v1/webhook` (body JSON, optional secret query/header).

2. **Entry**  
   `register_webhook_endpoint()` → `handle_webhook()` → validate secret → `process_webhook($data, $request)`.

3. **Order lookup**  
   By `origin_id` → find_order_by_purchase_session_id / find_order_by_origin_id; by `agreement_id` (recurring); for transfer events by **find_order_by_transfer_id** or payment_id; fallback metadata woocommerce_order_id.

4. **Dispatch by type**  
   - `payment` → `handle_payment_completed()` (and possibly `create_renewal_order()`).  
   - `failed_payment` → `handle_payment_failed()` (with payment-id checks).  
   - `cancellation` → `handle_payment_cancelled()`.  
   - `transfer` → `handle_transfer_completed()` → `mark_transfer_event_processed()`.  
   - `failed_transfer` → `handle_transfer_failed()` → `mark_transfer_event_processed()`.

---

## Flow 5: Admin order screen (CoinSub info + subscription)

1. **After billing address**  
   `display_coinsub_info()`: transaction hash + explorer link (**get_explorer_url**).  
   `display_subscription_status()`: **display_renewal_order_relationship()**, cancelled message, payments block or **maybe_display_subscription_payments()** (**get_all_payments**).

2. **Action buttons**  
   `add_cancel_subscription_button()`: “Cancel Subscription” → AJAX **ajax_admin_cancel_subscription** → **cancel_agreement** + meta + **add_subscription_payments_section**.

---

# Part 3: API calls reference

All calls go to **api_base_url** (e.g. `https://api.coinsub.io/v1`). Caller = PHP class/method that uses CoinSub_API_Client.

| Endpoint | Method | Caller | Purpose |
|----------|--------|--------|---------|
| `/purchase/session/start` | POST | **WC_Gateway_CoinSub::process_payment()** (via API client **create_purchase_session**) | Create checkout session; get purchase_session_id and checkout_url. |
| `/purchase/status/{id}` | GET | **CoinSub_Order_Manager::get_coinsub_order_status()** (via **get_purchase_session_status**) | Poll purchase session status (used by sync_order_status). |
**CoinSub_Webhook_Handler::test_connection()** (AJAX) (via **test_connection**) | Settings page “Test connection”. |
| `/agreements/cancel/{agreement_id}` | POST | **CoinSub_Subscriptions::ajax_cancel_subscription()** (customer); **CoinSub_Order_Manager::ajax_admin_cancel_subscription()** (admin) (via **cancel_agreement**) | Cancel subscription agreement. |
| `/agreements/{id}/retrieve_agreement` | GET | **CoinSub_Subscriptions::get_next_payment_for_display()** (via **retrieve_agreement**) | Get next payment date for view-order block (and admin next payment). |
| `/merchants/transfer/request` | POST | **WC_Gateway_CoinSub::process_refund()** (via **refund_transfer_request**) | Initiate refund transfer (to_address, amount, chainId, token). |
| `/payments/all` | GET | **CoinSub_Order_Manager::add_subscription_payments_section()**, **maybe_display_subscription_payments()** (via **get_all_payments**) | List payments (filter by agreement_id for subscription). |
| `/payments/{payment_id}` | GET | **WC_Gateway_CoinSub::process_refund()** (via **get_payment_details**) | Get chain/token for refund when not in order meta. |

---

# Part 4: Inbound (plugin receives)

| Endpoint / source | Method | Handler | Purpose |
|-------------------|--------|---------|---------|
| `/wp-json/coinsub/v1/webhook` | POST | **CoinSub_Webhook_Handler::handle_webhook()** | Payment, failed_payment, cancellation, transfer, failed_transfer. |
| admin-ajax.php `coinsub_process_payment` | POST | **coinsub_ajax_process_payment()** | Checkout: create order and get CoinSub checkout URL. |
| admin-ajax.php `coinsub_cancel_subscription` | POST | **CoinSub_Subscriptions::ajax_cancel_subscription()** | Customer cancel subscription. |
| admin-ajax.php `coinsub_admin_cancel_subscription` | POST | **CoinSub_Order_Manager::ajax_admin_cancel_subscription()** | Admin cancel subscription. |
| admin-ajax.php `coinsub_check_webhook_status` | POST | **coinsub_ajax_check_webhook_status()** (in coinsub.php) | Frontend poll for webhook completion. |
| admin-ajax.php `coinsub_get_latest_order_url` | POST | **coinsub_ajax_get_latest_order_url()** (in coinsub.php) | Backup redirect: latest order order-received URL. |
| Heartbeat `coinsub_check_webhook` | POST | **coinsub_heartbeat_received()** (in coinsub.php) | Real-time webhook completion + redirect URL. |

---

*Function breakdown + flows + API reference for the CoinSub WooCommerce plugin.*
