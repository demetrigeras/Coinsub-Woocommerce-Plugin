# WordPress.org Plugin Submission Compliance Checklist

## 🚨 CRITICAL ISSUES (Must Fix Before Submission)

### ✅ 1. **BUNDLED PLUGINS - FIXED**
**Status:** RESOLVED ✅

**Problem:** Plugin was bundling "Email Verification for WooCommerce" plugin

**Solution Applied:**
1. ✅ Removed `bundled-plugins/` directory entirely
2. ✅ Removed `class-coinsub-plugin-installer.php`
3. ✅ Removed all auto-installation logic
4. ✅ Removed all references from package script

**Status:** Ready for submission ✅

---

### ❌ 2. **MISSING readme.txt**
**Status:** REQUIRED - Will be rejected

**Problem:** No `readme.txt` file for WordPress.org

**WordPress.org Rule:**
> All plugins must include a readme.txt file using the WordPress plugin readme standard.
> Source: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/

**Solution:**
- ✅ Create proper `readme.txt` with all required sections

---

### ❌ 3. **MISSING uninstall.php**
**Status:** RECOMMENDED (but important for user experience)

**Problem:** No proper uninstall cleanup

**WordPress.org Rule:**
> Plugins should clean up after themselves when uninstalled.

**Solution:**
- ✅ Create `uninstall.php` to remove all plugin data, options, and database entries

---

## ⚠️ IMPORTANT ISSUES (Should Fix)

### 4. **External API Calls Need Disclosure**
**Status:** MUST DISCLOSE

**Problem:** Plugin makes API calls to external service (Coinsub API)

**WordPress.org Rule:**
> Plugins that communicate with external services must clearly disclose this in the readme.

**Solution:**
- ✅ Add "External Services" section to readme.txt
- ✅ Explain what data is sent to Coinsub API
- ✅ Link to Coinsub Terms of Service and Privacy Policy
- ✅ Make it clear that users need a Coinsub merchant account

---

### 5. **Plugin Slug Must Match Text Domain**
**Status:** CHECK

**Current:**
- Plugin folder will be: `coinsub`
- Text domain: `coinsub` ✅

**Status:** PASS ✅

---

### 6. **Security Review Items**

#### a. Input Sanitization
**Status:** NEEDS REVIEW

Check all instances of:
- `$_POST` - Must use `sanitize_text_field()`, `sanitize_email()`, etc.
- `$_GET` - Must be sanitized
- `$_REQUEST` - Must be sanitized

#### b. Output Escaping
**Status:** NEEDS REVIEW

Check all instances of:
- `echo` - Must use `esc_html()`, `esc_attr()`, `esc_url()`, etc.
- Output in HTML - Must be escaped

#### c. Nonce Verification
**Status:** NEEDS REVIEW

Check all AJAX handlers and form submissions have:
- `wp_verify_nonce()` or `check_ajax_referer()`

#### d. Capability Checks
**Status:** NEEDS REVIEW

Check all admin functions have:
- `current_user_can('manage_options')` or appropriate capability

---

## ✅ PASSING ITEMS

### 1. **License**
- GPL v2 or later ✅
- License declared in plugin header ✅
- License URI included ✅

### 2. **Plugin Headers**
- Plugin Name ✅
- Description ✅
- Version ✅
- Author ✅
- License ✅
- Text Domain ✅
- Requires PHP ✅
- Requires WP ✅

### 3. **No Minified Code**
- No .min.js or .min.css files without source ✅

### 4. **Direct Access Prevention**
```php
if (!defined('ABSPATH')) {
    exit;
}
```
✅ Present in all PHP files

### 5. **No Hidden/Obfuscated Code**
- All code is readable and unobfuscated ✅

---

## 📋 WORDPRESS.ORG SUBMISSION CHECKLIST

Before submitting to WordPress.org, verify:

- [ ] Remove all bundled plugins
- [ ] Create readme.txt with all required sections
- [ ] Create uninstall.php
- [ ] Add external service disclosure
- [ ] Review all sanitization/escaping
- [ ] Test plugin activation/deactivation
- [ ] Test plugin uninstall
- [ ] Verify no PHP errors/warnings
- [ ] Test with WordPress Debug enabled
- [ ] Test with latest WordPress version
- [ ] Test with latest WooCommerce version
- [ ] Verify all links work
- [ ] Ensure plugin doesn't modify WP core files
- [ ] Ensure plugin doesn't call external files without user consent
- [ ] Follow WordPress Coding Standards (WPCS)

---

## 📚 KEY WORDPRESS.ORG RESOURCES

1. **Plugin Guidelines:** https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
2. **readme.txt Standard:** https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
3. **Common Rejection Reasons:** https://developer.wordpress.org/plugins/wordpress-org/common-issues/
4. **Security Best Practices:** https://developer.wordpress.org/plugins/security/
5. **Coding Standards:** https://developer.wordpress.org/coding-standards/wordpress-coding-standards/

---

## 🎯 PRIORITY ORDER

1. **CRITICAL (Do First):**
   - Remove bundled plugins
   - Create readme.txt
   - Add external service disclosure

2. **HIGH PRIORITY:**
   - Create uninstall.php
   - Review security (sanitization/escaping)

3. **MEDIUM PRIORITY:**
   - Test thoroughly
   - Fix any PHP warnings/notices

4. **SUBMIT:**
   - Create SVN account
   - Submit to WordPress.org
   - Respond to review team feedback

---

**Estimated Time to Compliance:** 4-6 hours
**Review Time (WordPress.org Team):** 2-14 days typically

