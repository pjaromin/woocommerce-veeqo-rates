<?php
/**
 * Plugin Name: Veeqo Rates for WooCommerce (Custom)
 * Plugin URI:  https://github.com/zdlpro/woocommerce-veeqo-rates
 * Description: Live shipping rates from Veeqo Rate Shopping API. Creates a Veeqo order + allocation, updates package, and returns quotes at checkout. Includes diagnostics & WP-CLI.
 * Author:      ZDL Pro Audio Group
 * Author URI:  https://zdlpro.com
 * Version:     0.5.3
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: veeqo-rates
 * Requires PHP: 7.2
 * Requires at least: 5.6
 * WC requires at least: 4.0
 * WC tested up to: 9.2
 * Woo: 4.0.0
 */

defined('ABSPATH') || exit;

if ( ! defined('VEEQO_RATES_VERSION') ) {
    define('VEEQO_RATES_VERSION', '0.5.3');
}
if ( ! defined('VEEQO_RATES_FILE') ) {
    define('VEEQO_RATES_FILE', __FILE__);
}
if ( ! defined('VEEQO_RATES_PATH') ) {
    define('VEEQO_RATES_PATH', plugin_dir_path(__FILE__));
}
if ( ! defined('VEEQO_RATES_URL') ) {
    define('VEEQO_RATES_URL', plugin_dir_url(__FILE__));
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if ( ! class_exists('WooCommerce') ) {
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>Veeqo Rates</strong> requires WooCommerce to be active.</p></div>';
        });
        return;
    }
});

add_action('woocommerce_shipping_init', function(){
    require_once VEEQO_RATES_PATH . 'includes/class-wc-shipping-veeqo.php';
});

add_filter('woocommerce_shipping_methods', function($methods){
    $methods['veeqo_rates'] = 'WC_Shipping_Veeqo_Rates';
    return $methods;
});

// Force a visible title in the Shipping methods table
add_filter('woocommerce_shipping_zone_method_title', function($title, $method){
    if ( isset($method->id) && 'veeqo_rates' === $method->id ) {
        return __('Veeqo Rates', 'veeqo-rates');
    }
    return $title;
}, 10, 2);

// Settings link on Plugins screen
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $url = admin_url('admin.php?page=wc-settings&tab=shipping');
    $links[] = '<a href="'.esc_url($url).'">'.esc_html__('Settings','veeqo-rates').'</a>';
    return $links;
});

// Admin styles
add_action('admin_enqueue_scripts', function($hook){
    if (strpos($hook, 'wc-settings') !== false) {
        wp_enqueue_style('veeqo-rates-admin', VEEQO_RATES_URL . 'assets/admin.css', [], VEEQO_RATES_VERSION);
    }
});

// Load WP-CLI commands if available
if ( defined('WP_CLI') && WP_CLI ) {
    require_once VEEQO_RATES_PATH . 'includes/cli/class-veeqo-cli.php';
}
