# WordPress Coding Standards Guide

This plugin uses **PHP_CodeSniffer (PHPCS)** with **WordPress Coding Standards** to ensure code quality and WordPress.org compliance.

## 🚀 Quick Start

### 1. Install Dependencies

```bash
composer install
```

### 2. Check Coding Standards

```bash
# Quick check
composer test

# Detailed report
composer phpcs-report

# Standard check
composer phpcs
```

### 3. Auto-Fix Issues

```bash
# Fix all auto-fixable violations
composer phpcs-fix
```

---

## 📋 What Gets Checked

**Standards:** WordPress Coding Standards (WordPress, WordPress-Core, WordPress-Extra)

**Checked:**
- ✅ Code formatting (indentation, spacing, braces)
- ✅ Naming conventions (functions, variables, classes)
- ✅ Security (escaping, sanitization, nonces)
- ✅ Text domain usage (`coinsub`)
- ✅ Documentation (PHPDoc blocks)
- ✅ WordPress best practices

**Excluded:**
- ❌ `vendor/` - Third-party code
- ❌ `node_modules/` - JavaScript dependencies
- ❌ `*.min.js`, `*.min.css` - Minified files
- ❌ `*backup*.php` - Backup files
- ❌ `.github/` - GitHub workflows

---

## 🔧 Commands Reference

### Local Commands

```bash
# Check all files
composer phpcs

# Auto-fix violations
composer phpcs-fix

# Detailed report with source codes
composer phpcs-report

# Quick summary
composer test

# Check specific file
./vendor/bin/phpcs includes/class-coinsub-payment-gateway.php

# Fix specific file
./vendor/bin/phpcbf includes/class-coinsub-payment-gateway.php
```

### CI/CD

The GitHub Actions workflow (`.github/workflows/phpcs.yml`) automatically:
- Runs on every push/PR to `main`, `master`, `develop`
- Provides detailed reports
- Counts fixable violations
- **Does NOT block merges** (report-only mode)

---

## 📊 Understanding Reports

### Summary Report
```
FILE                                  ERRORS  WARNINGS
includes/class-coinsub-payment-gateway.php   45      12
Total: 57 errors, 12 warnings
```

### Source Report
Shows which sniff rules are triggered most:
```
WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase  125
WordPress.WP.I18n.MissingTranslatorsComment                          45
```

---

## ✅ WordPress.org Compliance

**Critical for WordPress.org submission:**

1. **Security**
   - ✅ All user input sanitized
   - ✅ All output escaped
   - ✅ Nonces verified
   - ✅ Capability checks in place

2. **Text Domain**
   - ✅ All strings use `coinsub` text domain
   - ✅ No hardcoded text

3. **Prefixes**
   - ✅ Functions: `coinsub_*`
   - ✅ Classes: `CoinSub_*`, `WC_CoinSub_*`
   - ✅ Options: `coinsub_*`

4. **Code Quality**
   - ✅ Follows WordPress coding standards
   - ✅ Proper documentation
   - ✅ No PHP errors or warnings

---

## 🎯 Common Fixes

### 1. Variable Naming
```php
// ❌ Bad (camelCase)
$myVariable = 'value';

// ✅ Good (snake_case)
$my_variable = 'value';
```

### 2. Escaping Output
```php
// ❌ Bad
echo $variable;

// ✅ Good
echo esc_html( $variable );
echo esc_attr( $attribute );
echo esc_url( $url );
```

### 3. Sanitizing Input
```php
// ❌ Bad
$value = $_POST['field'];

// ✅ Good
$value = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
```

### 4. Text Domain
```php
// ❌ Bad
__( 'Text' );

// ✅ Good
__( 'Text', 'coinsub' );
```

---

## 🔄 Workflow

### Before Commit
```bash
# 1. Check for violations
composer phpcs

# 2. Auto-fix what's possible
composer phpcs-fix

# 3. Manually fix remaining issues

# 4. Verify everything is clean
composer test
```

### Before WordPress.org Submission
```bash
# Run full check
composer phpcs-report

# Aim for 0 errors, minimal warnings
# WordPress.org reviewers will check coding standards!
```

---

## 📚 Resources

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [PHPCS Documentation](https://github.com/squizlabs/PHP_CodeSniffer/wiki)
- [WPCS GitHub](https://github.com/WordPress/WordPress-Coding-Standards)

---

## 💡 Tips

1. **Run PHPCS often** - Easier to fix small batches than accumulate violations
2. **Use auto-fix first** - `composer phpcs-fix` handles ~70% of issues automatically
3. **IDE Integration** - Configure your IDE to show PHPCS errors in real-time
4. **Focus on errors** - Fix errors first, warnings second
5. **WordPress.org will check** - Clean code = faster approval

---

## 🆘 Getting Help

If PHPCS reports confusing violations:

1. Check the sniff documentation (Google the sniff name)
2. Look at WordPress core code for examples
3. Ask in WordPress.org plugin review forums

**Remember:** These standards exist to ensure security, compatibility, and maintainability! 🛡️
