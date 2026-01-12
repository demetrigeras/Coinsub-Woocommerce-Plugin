<?php
/**
 * Uninstall Coinsub for WooCommerce
 *
 * Removes all plugin data from the database when the plugin is uninstalled.
 *
 * @package Coinsub
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('woocommerce_coinsub_settings');
delete_option('coinsub_webhook_secret');
delete_option('coinsub_recommendations_dismissed');

// Delete transients  
delete_transient('coinsub_refresh_branding_on_load');

// Clean up order meta data (optional - comment out if you want to preserve order history)
global $wpdb;

// Remove Coinsub-specific order meta
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_coinsub_%'");

// Flush rewrite rules
flush_rewrite_rules();

// Optional: Log uninstall (for debugging)
if (defined('WP_DEBUG') && WP_DEBUG === true) {
    error_log('Coinsub: Plugin uninstalled and all data removed');
}
