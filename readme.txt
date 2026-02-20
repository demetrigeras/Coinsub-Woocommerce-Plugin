=== Coinsub for WooCommerce ===
Contributors: coinsub
Tags: cryptocurrency, woocommerce, payment gateway, stablecoins and cryptocurrencies
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in your WooCommerce store. Simple setup, automatic confirmations, secure transactions.

== Description ==

**Accept cryptocurrency payments in your WooCommerce store with zero complexity.**

Coinsub for WooCommerce enables you to accept cryptocurrency payments from customers worldwide. No blockchain knowledge required - just install, connect your Coinsub account, and start accepting crypto.

= Why Coinsub? =

* **Simple Setup** - Get started in under 5 minutes
* **Multiple Cryptocurrencies** - Accept USDC, USDT, ETH, and other major cryptocurrencies
* **Automatic Confirmations** - Orders update automatically when blockchain payment is confirmed
* **Recurring Payments** - Full support for subscription products with crypto payments
* **Easy Refunds** - Process refunds directly from your WooCommerce admin panel
* **Secure** - Your customers' crypto payments are processed securely on the blockchain
* **No Chargebacks** - Crypto payments are final and irreversible

= How It Works =

1. **Customer Shops** - Customer adds products to cart and proceeds to checkout
2. **Selects Crypto Payment** - Customer chooses "Coinsub" as payment method
3. **Pays with Crypto** - Customer is shown a secure payment page to complete payment with their wallet
4. **Instant Confirmation** - Once payment is confirmed on the blockchain, the order is automatically marked as paid
5. **Order Fulfillment** - You receive the order and can fulfill it immediately

= What You Need =

* WooCommerce 5.0 or higher installed and activated
* A free Coinsub merchant account - [Sign up at coinsub.io](https://coinsub.io)
* SSL certificate (HTTPS) on your website (required for secure payments)

= Third-Party Service Disclosure =

**This plugin uses the Coinsub payment processing service to handle cryptocurrency transactions.**

When a customer selects Coinsub as their payment method, the following data is sent to Coinsub's servers:

**Data Sent to Coinsub:**
* Customer name (first and last name)
* Order amount and currency
* Order ID (for reference)
* Customer email address
* Customer's cryptocurrency wallet address (provided by customer during payment)

**When Data is Sent:**
* When customer clicks "Place Order" at checkout
* When payment status updates are received
* When refunds are processed

**No Sensitive Data Stored:**
This plugin does NOT store or have access to:
* Cryptocurrency private keys
* Wallet seed phrases
* Customer payment credentials

**User Consent:**
* **Merchants:** By installing this plugin, you consent to using Coinsub's payment service
* **Customers:** By selecting Coinsub at checkout, customers consent to Coinsub processing their payment

**Coinsub Service Information:**
* Website: [https://coinsub.io](https://coinsub.io)
* Developer Docs: [https://docs.coinsub.io/coinsub-ecosystem/for-developers](https://docs.coinsub.io/coinsub-ecosystem/for-developers)
* Terms of Service: [https://coinsub.io/tos](https://coinsub.io/tos)
* Contact/Support: [https://coinsub.io/contact](https://coinsub.io/contact)

By using this plugin, you agree to Coinsub's Terms of Service. For questions about how Coinsub handles data, please contact them directly.

== Installation ==

= Quick Install (Recommended) =

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins → Add New**
3. Search for **"Coinsub"**
4. Click **Install Now**, then **Activate**
5. Go to **WooCommerce → Settings → Payments**
6. Click on **Coinsub** and configure your settings

= Manual Installation =

1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Choose the downloaded ZIP file
4. Click **Install Now**, then **Activate**

= Configuration Steps =

After activating the plugin:

1. Go to **WooCommerce → Settings → Payments**
2. Click **Coinsub** (or click "Set up" if it's your first time)
3. **Enable** the payment method by checking the box at the top
4. Enter your **Merchant ID** (found in your Coinsub dashboard)
5. Enter your **API Key** (found in your Coinsub dashboard)
6. Copy the **Webhook URL** shown in the settings
7. Log in to your Coinsub merchant dashboard and add the webhook URL
8. Click **Save changes**

**Done!** Coinsub will now appear as a payment option at checkout.

== Frequently Asked Questions ==

= Do I need a Coinsub account? =

Yes. You need a free Coinsub merchant account to accept cryptocurrency payments. [Sign up here](https://coinsub.io).

The specific cryptocurrencies available to your customers are configured in your Coinsub merchant dashboard.

= Are there any fees? =

Transaction fees are determined by your Coinsub merchant agreement. Please check your Coinsub dashboard or contact Coinsub support for fee information.

= How do refunds work? =

Refunds are processed in USDC on the Polygon blockchain. To issue a refund:

1. Go to the WooCommerce order
2. Click **Refund**
3. Enter the refund amount
4. Click **Refund via Coinsub**

**Note:** You must have sufficient USDC in your Coinsub merchant wallet to process refunds.

= Does this work with WooCommerce Subscriptions? =

Yes! Coinsub has full support for recurring cryptocurrency payments. When you sell subscription products, customers can pay with crypto and renewals are handled automatically.

= What if a payment fails? =

If a customer's payment fails or they cancel the payment:
* The order remains in "Pending Payment" status
* The customer can try again by visiting the order pay page
* You and the customer will be notified of the payment status

= Is this secure? =

Yes. All payments are processed securely on the blockchain through Coinsub's payment infrastructure. This plugin never handles or stores private keys or sensitive wallet information.

= Do I need coding skills to use this? =

No! The plugin is designed for non-technical users. Simply enter your API credentials in the settings - no coding required.

= Where can I get help? =

* **Documentation:** [https://docs.coinsub.io/coinsub-ecosystem/for-developers](https://docs.coinsub.io/coinsub-ecosystem/for-developers)
* **Support:** [https://coinsub.io/contact](https://coinsub.io/contact)
* **GitHub:** [https://github.com/demetrigeras/Coinsub-Woocommerce-Plugin](https://github.com/demetrigeras/Coinsub-Woocommerce-Plugin)

== Screenshots ==

1. Coinsub payment gateway settings page in WooCommerce admin
2. Coinsub payment option at checkout
3. Secure cryptocurrency payment page
4. Order details showing confirmed crypto transaction
5. Admin subscription management page
6. Payment logs for debugging

== Changelog ==

= 1.0.0 - 2024-01-15 =
**Initial Release**
* Cryptocurrency payment gateway for WooCommerce
* Support for USDC, USDT, ETH, and other major cryptocurrencies
* Automatic order updates via webhook integration
* Full support for WooCommerce Subscriptions (recurring crypto payments)
* Refund processing from WooCommerce admin
* Admin pages for payment and subscription management
* Debug logging for easy troubleshooting
* Secure API integration with Coinsub payment service

== Upgrade Notice ==

= 1.0.0 =
Welcome to Coinsub for WooCommerce! This is the initial release. Install, configure your API credentials, and start accepting cryptocurrency payments.

== Privacy & Data Handling ==

**What This Plugin Does:**

This plugin integrates WooCommerce with the Coinsub cryptocurrency payment service. When a customer chooses to pay with crypto, their order information is sent to Coinsub to process the payment.

**Data Sent to External Service:**

When you use this plugin, the following data is transmitted to Coinsub's servers:

* **Order Information:** Amount, currency, order ID
* **Customer Information:** Name, email address
* **Payment Information:** Cryptocurrency wallet address (provided by customer)

**Data Stored Locally:**

This plugin stores the following in your WordPress database:

* **Settings:** API credentials, merchant ID, webhook secret
* **Order Meta:** Transaction hashes, payment status, subscription IDs
* **Logs:** Payment events for debugging (optional, can be disabled)

**What is NOT Stored:**

* Cryptocurrency private keys (never accessed or stored)
* Wallet seed phrases (never accessed or stored)
* Customer payment credentials (never accessed or stored)

**Cookies:**

This plugin does not set any cookies.

**Third-Party Service:**

Payments are processed by Coinsub, a third-party service. By using this plugin, you acknowledge that customer payment data will be transmitted to Coinsub for processing.

For details on how Coinsub handles data:
* Terms of Service: [https://coinsub.io/tos](https://coinsub.io/tos)
* Contact: [https://coinsub.io/contact](https://coinsub.io/contact)

**GDPR Compliance:**

Merchants are responsible for adding appropriate privacy policy disclosures to their website regarding the use of cryptocurrency payment processing. This plugin provides suggested privacy policy text via WordPress → Settings → Privacy to help you comply.

== Support ==

Need help? We're here for you:

* **Plugin Documentation:** [https://docs.coinsub.io/coinsub-ecosystem/for-developers](https://docs.coinsub.io/coinsub-ecosystem/for-developers)
* **Contact Support:** [https://coinsub.io/contact](https://coinsub.io/contact)
* **Report Issues:** [https://github.com/demetrigeras/Coinsub-Woocommerce-Plugin](https://github.com/demetrigeras/Coinsub-Woocommerce-Plugin)
