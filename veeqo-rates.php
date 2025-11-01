<?php
/**
 * Plugin Name: Veeqo Rates for WooCommerce (Custom)
 * Plugin URI:  https://github.com/your-org/woocommerce-veeqo-rates
 * Description: Adds live shipping options from Veeqo at WooCommerce checkout using Veeqo's Rate Shopping API.
 * Author:      ZDL Pro Audio Group
 * Author URI:  https://zdlpro.com
 * Version:     0.4.0
 * License:     MIT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package woocommerce-veeqo-rates
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WCVEEQO_RATES_VERSION', '0.4.0' );
define( 'WCVEEQO_RATES_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCVEEQO_RATES_URL',  plugin_dir_url( __FILE__ ) );

// Load the shipping method class.
add_action( 'woocommerce_shipping_init', function () {
    require_once WCVEEQO_RATES_PATH . 'includes/class-wc-shipping-veeqo.php';
} );

// Register the method with WooCommerce.
add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['veeqo_rates'] = 'WC_Shipping_Veeqo';
    return $methods;
} );

// Optional: add a settings link on the Plugins page.
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), function( $links ) {
    $url = admin_url( 'admin.php?page=wc-settings&tab=shipping' );
    array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
    return $links;
} );
