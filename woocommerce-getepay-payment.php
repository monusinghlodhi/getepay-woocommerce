<?php
/**
 * Plugin Name: WooCommerce Getepay Payment
 * Plugin URI: http://getepay.in/
 * Description: Allows to integrate Getepay Payment Gateway with woocommerce store
 * Author: Getepay
 * Text Domain: woocommerce-getepay-payment
 * Author URI: http://getepay.in/
 * Version: 1.0.0
 * Requires at least: 6.2
 * Tested up to: 6.4
 * WC tested up to: 8.3
 * WC requires at least: 8.1
 * Requires PHP: 7.3
 */
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

defined( 'ABSPATH' ) || exit;

define('WC_GATEWAY_GETEPAY_VERSION', '1.0.0'); // WRCS: DEFINED_VERSION.
define('WC_GATEWAY_GETEPAY_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WC_GATEWAY_GETEPAY_PATH', untrailingslashit(plugin_dir_path(__FILE__)));


if (!defined('WP_PLUGIN_URL')) {
	define('WP_PLUGIN_URL', plugins_url());
}

define('IMGDIR', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets/images/');

/**
 * Initialize the gateway.
 * @since 1.0.0
 */
function woocommerce_getepay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once( plugin_basename( 'includes/class-wc-gateway-getepay.php' ) );
	require_once( plugin_basename( 'includes/class-wc-getepay-pending-order-cron.php' ) );
	load_plugin_textdomain( 'woocommerce-getepay-payment', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_getepay_add_gateway' );
}
add_action( 'plugins_loaded', 'woocommerce_getepay_init', 0 );
function woocommerce_getepay_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'getepay_gateway',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure', 'woocommerce-getepay-payment' ) . '</a>',
		'<a href="https://www.woocommerce.com/my-account/tickets/">' . esc_html__( 'Support', 'woocommerce-getepay-payment' ) . '</a>',
		'<a href="https://docs.woocommerce.com/document/getepay-payment-gateway/">' . esc_html__( 'Docs', 'woocommerce-getepay-payment' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_getepay_plugin_links' );

/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + getepay gateway
 */
function woocommerce_getepay_add_gateway($gateways)
{
	$gateways[] = 'WC_Gateway_Getepay';
	return $gateways;
}

add_action('woocommerce_blocks_loaded', 'woocommerce_getepay_woocommerce_blocks_support');
function woocommerce_getepay_woocommerce_blocks_support()
{
	if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
		require_once dirname(__FILE__) . '/includes/class-wc-gateway-getepay-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
				$payment_method_registry->register(new WC_Getepay_Blocks_Support);
			}
		);
	}
}

/**
 * Display notice if WooCommerce is not installed.
 *
 * @since 1.5.8
 */
function woocommerce_getepay_missing_wc_notice()
{
	if (class_exists('WooCommerce')) {
		// Display nothing if WooCommerce is installed and activated.
		return;
	}

	echo '<div class="error"><p><strong>';
	echo sprintf(
		/* translators: %s WooCommerce download URL link. */
		esc_html__('WooCommerce Getepay Payment Plugin requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-getepay-payment'),
		'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
	);
	echo '</strong></p></div>';
}
add_action('admin_notices', 'woocommerce_getepay_missing_wc_notice');