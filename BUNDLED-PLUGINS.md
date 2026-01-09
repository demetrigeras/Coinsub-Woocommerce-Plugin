# 📦 Bundled Required Plugins

Coinsub includes required plugins that are automatically installed and activated when you install the main plugin.

## Why Bundle Plugins?

For **security and functionality**, Coinsub requires certain plugins to work correctly. Rather than asking merchants to manually install dependencies, we bundle them for a seamless experience.

## Included Plugins:

### 1. Email Verification for WooCommerce (by WPFactory)
- **Version**: 2.9.9
- **Purpose**: Verifies customer email addresses are real and owned by the user
- **Security**: Prevents fraudulent accounts and ensures email ownership
- **Auto-Install**: Yes - installs automatically on plugin activation
- **WordPress.org**: https://wordpress.org/plugins/emails-verification-for-woocommerce/

## How It Works:

1. **You install Coinsub** → Upload and activate the plugin
2. **Auto-Installation** → Email Verification plugin installs automatically
3. **Silent Activation** → Plugin activates in the background
4. **Ready to Use** → Email verification is configured and working

## What Gets Verified:

✅ **Email is real** - User must verify via email link/OTP  
✅ **Email is owned by user** - User receives verification code to their inbox  
✅ **No fake accounts** - Prevents bots and fraudulent registrations  
✅ **Faster checkout** - Verified users skip email verification in buy app  

## Troubleshooting:

If auto-installation fails, you'll see an admin notice with instructions to manually install the plugin.

**Manual Installation:**
1. Go to **Plugins → Add New**
2. Search for "Email Verification for WooCommerce"
3. Click **Install Now**, then **Activate**

## Technical Details:

- **Location**: `bundled-plugins/emails-verification-for-woocommerce.zip`
- **Installer**: `includes/class-coinsub-plugin-installer.php`
- **Activation Hook**: Runs on Coinsub activation
- **Check Method**: Verifies plugin is active before payment processing

## Security:

The Email Verification plugin is:
- ✅ Downloaded from official WordPress.org repository
- ✅ Verified and approved by WordPress
- ✅ Regularly updated for security
- ✅ Used by 10,000+ active installations

## Future Plugins:

As we add more features, additional required plugins may be bundled here. All bundled plugins will:
- Be from trusted sources (WordPress.org)
- Auto-install on activation
- Be clearly documented
- Enhance security or functionality

---

**Note**: You're not required to use these plugins separately - they work seamlessly with Coinsub and are configured automatically.

