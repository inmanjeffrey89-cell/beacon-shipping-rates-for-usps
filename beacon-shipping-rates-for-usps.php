<?php
/**
 * Plugin Name:       Beacon Shipping Rates for USPS
 * Plugin URI:        https://wordpress.org/plugins/beacon-shipping-rates-for-usps/
 * Description:       Live USPS shipping rates at checkout that keep working: guided USPS API setup, cached rates that respect the USPS hourly quota, and a fallback so checkout never breaks. Domestic and international, all free.
 * Version:           0.1.4
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Beacon Events
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beacon-shipping-rates-for-usps
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

define( 'BUSR_VERSION', '0.1.4' );
define( 'BUSR_FILE', __FILE__ );
define( 'BUSR_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUSR_URL', plugin_dir_url( __FILE__ ) );

require_once BUSR_DIR . 'includes/class-settings.php';
require_once BUSR_DIR . 'includes/class-cache.php';
require_once BUSR_DIR . 'includes/class-api.php';
require_once BUSR_DIR . 'includes/class-packer.php';
require_once BUSR_DIR . 'includes/class-admin.php';

// Declare compatibility with HPOS and the block-based cart/checkout before WooCommerce inits.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					$screen = get_current_screen();
					if ( $screen && 'plugins' === $screen->id ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Beacon Shipping Rates for USPS needs WooCommerce to be installed and active.', 'beacon-shipping-rates-for-usps' ) . '</p></div>';
					}
				}
			);
			return;
		}
		Beacon\USPS\Admin::init();
	}
);

// The shipping method class extends WC_Shipping_Method, so it can only be loaded once WooCommerce has.
add_action(
	'woocommerce_shipping_init',
	function () {
		require_once BUSR_DIR . 'includes/class-shipping-method.php';
	}
);
add_filter(
	'woocommerce_shipping_methods',
	function ( $methods ) {
		$methods['beacon_usps'] = 'Beacon\USPS\Method';
		return $methods;
	}
);
