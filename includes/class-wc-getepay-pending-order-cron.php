<?php
defined( 'ABSPATH' ) || exit;

// Add a new interval of 300 seconds
// See http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
add_filter('cron_schedules', 'getepay_chk_pmt_every_five_minutes');
function getepay_chk_pmt_every_five_minutes($schedules)
{
	$schedules['every_five_minutes'] = array(
		'interval' => 300, // 300 seconds (5 minutes)
		'display' => __('Every 5 Minutes', 'textdomain')
	);
	return $schedules;
}

// Schedule an action if it's not already scheduled
if (!wp_next_scheduled('getepay_chk_pmt_every_five_minutes')) {
	wp_schedule_event(time(), 'every_five_minutes', 'getepay_chk_pmt_every_five_minutes');
}

function get_order_ids_to_check()
{
	global $wpdb;
	$poststblprefix = $wpdb->prefix . 'posts';
	return $wpdb->get_col("
        SELECT p.ID
        FROM $poststblprefix as p
        WHERE p.post_type LIKE 'shop_order'
        AND p.post_status IN ('wc-pending')
        AND UNIX_TIMESTAMP(p.post_date) >= (UNIX_TIMESTAMP(NOW()) - 172800)
    ");

}

// Hook into that action that'll fire every three minutes
add_action('getepay_chk_pmt_every_five_minutes', 'every_five_minutes_event_func');
function every_five_minutes_event_func()
{
	// Get the serialized GetePay data from the options table
	$getepay_data = get_option('woocommerce_getepay_gateway_settings'); // Replace 'your_option_name' with the actual option name where the data is stored

	//$url = $getepay_data['req_url'];
	$mid = $getepay_data['mid'];
	$terminalId = $getepay_data['terminalId'];
	$keyy = $getepay_data['key'];
	$ivv = $getepay_data['iv'];
	$url = $getepay_data['pmt_chk_url'];
	$key = base64_decode($keyy);
	$iv = base64_decode($ivv);
	// Loop through each order Ids
	foreach (get_order_ids_to_check() as $order_id) {
		// Get an instance of the WC_Order object
		$order = wc_get_order($order_id);
		$merchantOrderNo = $order->get_id();
		echo $paymentId = get_post_meta($order_id, 'GetepaypPaymentId', true);

		//GetePay Callback
		$requestt = array(
			"mid" => $mid,
			"paymentId" => $paymentId,
			"referenceNo" => "",
			"status" => "",
			"terminalId" => $terminalId,
		);
		$json_requset = json_encode($requestt);
		$ciphertext_raw = openssl_encrypt($json_requset, "AES-256-CBC", $key, $options = OPENSSL_RAW_DATA, $iv);
		$ciphertext = bin2hex($ciphertext_raw);
		$newCipher = strtoupper($ciphertext);
		$request = array(
			"mid" => $mid,
			"terminalId" => $terminalId,
			"req" => $newCipher
		);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLINFO_HEADER_OUT, true);
		curl_setopt(
			$curl,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type:application/json',
			)
		);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($request));
		$result = curl_exec($curl);
		curl_close($curl);
		$jsonDecode = json_decode($result);
		$jsonResult = $jsonDecode->response;
		$ciphertext_raw = hex2bin($jsonResult);
		$original_plaintext = openssl_decrypt($ciphertext_raw, "AES-256-CBC", $key, $options = OPENSSL_RAW_DATA, $iv);
		$json = json_decode($original_plaintext);

		// Update order status
		if ($json->txnStatus == "SUCCESS") {
			//$order->update_status('processing');
			$order->update_status('processing', sprintf(__('Payment status %s via Getepay after Pending Status by Getepay Cron".<br/>', 'woocommerce-getepay-payment'), strtolower(sanitize_text_field($json->txnStatus))));

		} elseif ($json->txnStatus == "FAILED") {
			//$order->update_status('failed');
			$order->update_status('failed', sprintf(__('Payment status %s via Getepay after Pending Status by Getepay Cron.<br/>', 'woocommerce-getepay-payment'), strtolower(sanitize_text_field($json->txnStatus))));
		}
	}
}

// //Callback URL: http://localhost/woocommerce/wc-api/getepay-payment-callback
// Add a custom WooCommerce endpoint with nonce verification
function getepay_payment_callback_endpoint()
{
	add_rewrite_endpoint('getepay-payment-callback', EP_ROOT | EP_PAGES);
}
add_action('init', 'getepay_payment_callback_endpoint');

// Callback Handler Function
function getepay_payment_callback_handler()
{
	// Verify the nonce to ensure the request is legitimate
	$nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';

	// if (!wp_verify_nonce($nonce, 'getepay-payment-callback-nonce')) {
	//     // Nonce verification failed, do not proceed
	//     wp_send_json_error('Nonce verification failed.', 403);
	// }

	// Your payment status update logic here
	// This function will be called when the callback is received
	// You can access data sent by the third-party Payment API here

	// Get the serialized GetePay data from the options table
	$getepay_data = get_option('woocommerce_getepay_gateway_settings');

	//$url = $getepay_data['req_url'];
	$mid = $getepay_data['mid'];
	$terminalId = $getepay_data['terminalId'];
	$keyy = $getepay_data['key'];
	$ivv = $getepay_data['iv'];
	$url = $getepay_data['pmt_chk_url'];

	$key = base64_decode($keyy);
	$iv = base64_decode($ivv);
	// Loop through each order Ids
	foreach (get_order_ids_to_check() as $order_id) {
		// Get an instance of the WC_Order object
		$order = wc_get_order($order_id);

		$merchantOrderNo = $order->get_id();
		echo $paymentId = get_post_meta($order_id, 'GetepaypPaymentId', true);

		//GetePay Callback For order Payment Status Update Start
		$requestt = array(
			"mid" => $mid,
			"paymentId" => $paymentId,
			"referenceNo" => "",
			"status" => "",
			"terminalId" => $terminalId,
		);

		$json_requset = json_encode($requestt);
		$ciphertext_raw = openssl_encrypt($json_requset, "AES-256-CBC", $key, $options = OPENSSL_RAW_DATA, $iv);
		$ciphertext = bin2hex($ciphertext_raw);
		$newCipher = strtoupper($ciphertext);
		$request = array(
			"mid" => $mid,
			"terminalId" => $terminalId,
			"req" => $newCipher
		);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLINFO_HEADER_OUT, true);
		curl_setopt(
			$curl,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type:application/json',
			)
		);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($request));
		$result = curl_exec($curl);
		curl_close($curl);
		$jsonDecode = json_decode($result);
		$jsonResult = $jsonDecode->response;
		$ciphertext_raw = hex2bin($jsonResult);
		$original_plaintext = openssl_decrypt($ciphertext_raw, "AES-256-CBC", $key, $options = OPENSSL_RAW_DATA, $iv);
		$json = json_decode($original_plaintext);

		// Update order status
		if ($json->txnStatus == "SUCCESS") {
			//$order->update_status('processing');
			$order->update_status('processing', sprintf(__('Payment status %s via Getepay after Pending Status.<br/>', 'woocommerce-getepay-payment'), strtolower(sanitize_text_field($json->txnStatus))));

		} elseif ($json->txnStatus == "FAILED") {
			//$order->update_status('failed');
			$order->update_status('failed', sprintf(__('Payment status %s via Getepay after Pending Status.<br/>', 'woocommerce-getepay-payment'), strtolower(sanitize_text_field($json->txnStatus))));
		}
	}

	// Send a success response
	wp_send_json_success('Payment status updated successfully.');
}
add_action('woocommerce_api_getepay-payment-callback', 'getepay_payment_callback_handler');

// Add a nonce to the callback URL for security
function add_getepay_payment_callback_nonce($url)
{
	return wp_nonce_url($url, 'getepay-payment-callback-nonce');
}
add_filter('woocommerce_api_getepay-payment-callback', 'add_getepay_payment_callback_nonce');

// Prevent caching of the callback endpoint
function disable_getepay_callback_caching()
{
	if (is_wc_endpoint_url('getepay-payment-callback')) {
		nocache_headers();
	}
}
add_action('template_redirect', 'disable_getepay_callback_caching');