<?php
/**
 * Plugin Name: CryptoGate for WooCommerce
 * Plugin URI:  https://docs.cryptogate.live
 * Description: Accept Bitcoin, Litecoin, Dogecoin and Dash payments via CryptoGate.
 * Version:     1.0.0
 * Author:      CryptoGate
 * Author URI:  https://cryptogate.live
 * License:     MIT
 * Text Domain: cryptogate-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.9
 */

defined( 'ABSPATH' ) || exit;

define( 'CRYPTOGATE_VERSION',    '1.0.0' );
define( 'CRYPTOGATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRYPTOGATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CRYPTOGATE_API_BASE',   'https://api.cryptogate.live' );

// Declare HPOS compatibility.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>CryptoGate:</strong> WooCommerce must be active.</p></div>';
        } );
        return;
    }

    require_once CRYPTOGATE_PLUGIN_DIR . 'includes/class-cryptogate-gateway.php';
    require_once CRYPTOGATE_PLUGIN_DIR . 'includes/class-cryptogate-webhook.php';

    // Register the payment gateway.
    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'CryptoGate_Gateway';
        return $gateways;
    } );

    // Register the webhook listener on wc-api=cryptogate.
    add_action( 'woocommerce_api_cryptogate', [ 'CryptoGate_Webhook', 'handle' ] );
} );
