=== Coinsub for WooCommerce ===
Contributors: coinsub
Tags: cryptocurrency, crypto payments, woocommerce, bitcoin, ethereum, stablecoin, usdc, payment gateway
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in your WooCommerce store with Coinsub. Simple, secure crypto payments.

== Description ==

Coinsub for WooCommerce allows you to accept cryptocurrency payments directly in your WooCommerce store. Customers can pay with various cryptocurrencies including USDC, USDT, ETH, and more.

= Features =

* **Easy Setup** - Configure in minutes with your Coinsub merchant credentials
* **Multiple Cryptocurrencies** - Accept USDC, USDT, ETH, BTC, and other major cryptocurrencies
* **Automatic Order Updates** - Orders update automatically when payments are confirmed
* **Secure** - All payments are processed securely through the Coinsub payment network
* **Subscription Support** - Accept recurring crypto payments for subscription products
* **Refunds** - Process refunds directly from WooCommerce admin
* **Real-time Notifications** - Webhook integration for instant payment confirmations

= How It Works =

1. Customer adds products to cart
2. Selects cryptocurrency payment at checkout
3. Redirected to secure crypto payment page
4. Pays with their crypto wallet
5. Order automatically confirmed when payment is received

= Requirements =

* WooCommerce 5.0 or higher
* A Coinsub merchant account (sign up at coinsub.io)
* SSL certificate (HTTPS) required

= External Services =

This plugin connects to the Coinsub payment processing service to handle cryptocurrency transactions.

**What data is sent to Coinsub:**
* Order amount and currency
* Order ID and customer email
* Customer wallet addresses (during payment)
* Transaction details for payment processing

**When data is sent:**
* When a customer selects Coinsub as their payment method at checkout
* When payment confirmations are received via webhook
* When refunds are processed

**User Consent:**
By installing and activating this plugin, merchants consent to using the Coinsub payment service. Customers provide consent by selecting Coinsub as their payment method at checkout.

**Coinsub Service:**
* Service URL: https://coinsub.io
* API Documentation: https://docs.coinsub.io/coinsub-ecosystem/for-developers
* Terms of Service: https://coinsub.io/tos
* Privacy Policy: https://coinsub.io/contact
* Contact: https://coinsub.io/contact

For more information about how Coinsub processes data, please review their Terms of Service and Privacy Policy.

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins → Add New
3. Search for "Coinsub"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click "Install Now"
4. Activate the plugin

= Configuration =

1. Go to WooCommerce → Settings → Payments
2. Click on "Coinsub" to configure
3. Enter your Merchant ID and API Key from your Coinsub dashboard
4. Copy the Webhook URL and add it to your Coinsub dashboard settings
5. Enable the payment method and save changes

== Frequently Asked Questions ==

= Do I need a Coinsub account? =

Yes, you need a Coinsub merchant account to accept crypto payments. Sign up at coinsub.io.

= What cryptocurrencies can I accept? =

You can accept USDC, USDT, ETH, BTC, and other major cryptocurrencies. The available options are configured in your Coinsub merchant dashboard.

= Are there any transaction fees? =

Transaction fees are determined by your Coinsub merchant agreement. Please refer to your Coinsub dashboard for fee details.

= How do refunds work? =

Refunds are processed in USDC on the Polygon network directly from your WooCommerce admin. You'll need USDC in your merchant wallet to process refunds.

= Is this compatible with WooCommerce Subscriptions? =

Yes! Coinsub has built-in support for recurring cryptocurrency payments. Simply enable subscription settings on your products.

= What happens if a payment fails? =

If a payment fails or is cancelled, the order status will be updated automatically and the customer will be notified.

= Do I need technical knowledge to set this up? =

No! The plugin is designed for easy setup. Just enter your API credentials and webhook URL - no coding required.

== Screenshots ==

1. Payment gateway settings page
2. Checkout page with Coinsub payment option
3. Crypto payment page
4. Order details showing crypto transaction
5. Subscription management page

== Changelog ==

= 1.0.0 =
* Initial release
* Cryptocurrency payment processing
* Automatic order updates via webhook
* Subscription support for recurring payments
* Refund support
* Admin payment and subscription management pages
* Debug logging for troubleshooting

== Upgrade Notice ==

= 1.0.0 =
Initial release of Coinsub for WooCommerce.

== Privacy ==

This plugin connects to the Coinsub external service to process cryptocurrency payments. When you use this plugin:

**Data Sent to Coinsub:**
* Order amount and currency
* Order ID and customer email
* Product details
* Merchant ID and API credentials

**Data Received from Coinsub:**
* Payment confirmation status
* Transaction hash
* Payment timestamp
* Subscription agreement IDs (for recurring payments)

**Data Storage:**
* Transaction hashes are stored in order meta data
* API credentials are stored in WordPress options table
* Subscription IDs are stored in order meta data

No customer payment information (wallet addresses, private keys) is ever stored or handled by this plugin.

For more information, please review:
* Coinsub Terms of Service: https://coinsub.io/tos
* Contact Coinsub: https://coinsub.io/contact

== Support ==

For plugin support, please visit:
* Documentation: https://docs.coinsub.io/coinsub-ecosystem/for-developers
* Contact: https://coinsub.io/contact
* GitHub: https://github.com/demetrigeras/Coinsub-Woocommerce-Plugin
