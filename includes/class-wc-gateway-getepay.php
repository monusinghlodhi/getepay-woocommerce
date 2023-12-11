<?php
/**
 * Getepay Payment Gateway
 *
 * Provides a Getepay Payment Gateway.
 *
 * @class  woocommerce_getepay
 * @package WooCommerce
 * @category Payment Gateways
 * @author WooCommerce
 */
class WC_Gateway_Getepay extends WC_Payment_Gateway
{
	/**
	 * Version
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->version = WC_GATEWAY_GETEPAY_VERSION;
		$this->id = 'getepay_gateway';
		$this->has_fields = true;
		$this->method_title = __('Getepay', 'woocommerce-getepay-payment');
		/* translators: 1: a href link 2: closing href */
		$this->method_description = sprintf(__('This Plugin utilizes %1$sGetepay%2$s API and provides seamless integration with Woocommerce, allowing payments for Indian merchants via Credit Cards, Debit Cards, Net Banking, without redirecting away from the Woocommerce site.', 'woocommerce-getepay-payment'), '<a href="https://getepay.in/" target="_blank">', '</a>');
		$this->icon = WP_PLUGIN_URL . '/' . plugin_basename(dirname(dirname(__FILE__))) . '/assets/images/logo.png';
		$this->debug_email = get_option('admin_email');
		$this->available_countries = array('IN');
		$this->available_currencies = (array) apply_filters('woocommerce_gateway_getepay_available_currencies', array('INR'));

		// Check if port 8443 is open on the specified domain
		$domain = parse_url(get_site_url(), PHP_URL_HOST); // Extract the host from the URL
		$this->domain_ip = gethostbyname($domain);

		// Supported functionality
        $this->supports   = array(
            'products',
        );

		$this->init_form_fields();
		$this->init_settings();

		if (!is_admin()) {
			$this->setup_constants();
		}

		// Setup default merchant data.
		$this->req_url = $this->get_option("req_url");
		$this->pmt_chk_url = $this->get_option("pmt_chk_url");
		$this->mid = $this->get_option("mid");
		$this->terminalId = $this->get_option("terminalId");
		$this->key = $this->get_option("key");
		$this->iv = $this->get_option("iv");

		$this->title = $this->get_option('title');
		$this->response_url = add_query_arg('wc-api', 'WC_Gateway_Getepay', home_url('/'));
		$this->send_debug_email = 'yes' === $this->get_option('send_debug_email');
		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions', $this->description);
		$this->enabled = 'yes' === $this->get_option('enabled') ? 'yes' : 'no';
		$this->enable_logging = 'yes' === $this->get_option('enable_logging');

		// Setup the test data, if in test mode.
		if ('yes' === $this->get_option('testmode')) {
			$this->req_url = "https://pay1.getepay.in:8443/getepayPortal/pg/generateInvoice";
			$this->pmt_chk_url = "https://pay1.getepay.in:8443/getepayPortal/pg/invoiceStatus";
			$this->mid = "108";
			$this->terminalId = "Getepay.merchant61062@icici";
			$this->key = "JoYPd+qso9s7T+Ebj8pi4Wl8i+AHLv+5UNJxA3JkDgY=";
			$this->iv = "hlnuyA9b4YxDq6oJSZFl8g==";
			$this->add_testmode_admin_settings_notice();
		} else {
			$this->send_debug_email = false;
		}

		$components = parse_url($this->req_url);
		if (isset($components['port'])) {
			$this->getepay_req_url_port = $components['port'];
			//$this->getepay_req_url_port = '8025';
		} else {
			//echo "No specific port specified; defaulting to standard HTTPS port (443)";
			//$this->getepay_req_url_port = "8025";
			$this->getepay_req_url_port = '80';
		}

		add_action('woocommerce_api_wc_gateway_getepay', array($this, 'getepay_check_response'));
		//add_action('getepay_check_response', array($this, 'getepay_check_response'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		//add_action('woocommerce_receipt_' . $this->id, array($this, 'pay_for_order'));
		add_action('woocommerce_receipt_' .$this->id, array($this, 'receipt_page'));
		add_action('admin_notices', array($this, 'admin_notices'));
		add_action('admin_footer', array($this, 'add_text_to_payment_gateway_settings'));
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'woocommerce-getepay-payment'),
				'label' => __('Enable Getepay', 'woocommerce-getepay-payment'),
				'type' => 'checkbox',
				'description' => __('This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-getepay-payment'),
				'default' => 'no',		// User should enter the required information before enabling the gateway.
				'desc_tip' => true,
			),
			'title' => array(
				'title' => __('Title', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-getepay-payment'),
				'default' => __('Getepay', 'woocommerce-getepay-payment'),
				'desc_tip' => true,
			),
			'description' => array(
				'title' => __('Description', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-getepay-payment'),
				'default' => 'Pay With Getepay',
				'desc_tip' => true,
			),
			'testmode' => array(
				'title' => __('Getepay Sandbox', 'woocommerce-getepay-payment'),
				'type' => 'checkbox',
				'description' => __('Place the payment gateway in development mode.', 'woocommerce-getepay-payment'),
				'default' => 'yes',
			),

			'req_url' => array(
				'title' => __('Request Url', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This is the Getepay Payment Url, received from Getepay', 'woocommerce-getepay-payment'),
				'default' => __('', 'woocommerce-getepay-payment'),
				//'desc_tip' => true,
			),

			'pmt_chk_url' => array(
				'title' => __('Payment Re-Query Url', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This is the Getepay Payment Re-Query Url, received from Getepay', 'woocommerce-getepay-payment'),
				'default' => __('', 'woocommerce-getepay-payment'),
				//'desc_tip' => true,
			),

			'mid' => array(
				'title' => __('Mid', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This is the MID, received from Getepay', 'woocommerce-getepay-payment'),
				'default' => __('', 'woocommerce-getepay-payment'),
				//'desc_tip' => true,
			),

			'terminalId' => array(
				'title' => __('Terminal Id', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This is the Terminal ID, received from Getepay', 'woocommerce-getepay-payment'),
				'default' => __('', 'woocommerce-getepay-payment'),
				//'desc_tip' => true,
			),
			'key' => array(
				'title' => __('Getepay Key', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This is the Getepay Key, received from Getepay', 'woocommerce-getepay-payment'),
				'default' => __('', 'woocommerce-getepay-payment'),
				//'desc_tip' => true,
			),
			'iv' => array(
				'title' => __('Getepay IV', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('This is the Getepay IV, received from Getepay', 'woocommerce-getepay-payment'),
				'default' => __('', 'woocommerce-getepay-payment'),
				//'desc_tip' => true,
			),

			'send_debug_email' => array(
				'title' => __('Send Debug Emails', 'woocommerce-getepay-payment'),
				'type' => 'checkbox',
				'label' => __('Send debug e-mails for transactions through the Getepay gateway (sends on successful transaction as well).', 'woocommerce-getepay-payment'),
				'default' => 'yes',
			),
			'debug_email' => array(
				'title' => __('Who Receives Debug E-mails?', 'woocommerce-getepay-payment'),
				'type' => 'text',
				'description' => __('The e-mail address to which debugging error e-mails are sent when in test mode.', 'woocommerce-getepay-payment'),
				'default' => get_option('admin_email'),
			),
			'enable_logging' => array(
				'title' => __('Enable Logging', 'woocommerce-getepay-payment'),
				'type' => 'checkbox',
				'label' => __('Enable transaction logging for gateway.', 'woocommerce-getepay-payment'),
				'default' => 'no',
			),
		);
	}

	/**
	 * Get the required form field keys for setup.
	 *
	 * @return array
	 */
	public function get_required_settings_keys()
	{
		return array(
			'req_url',
			'pmt_chk_url',
			'mid',
			'terminalId',
			'key',
			'iv'
		);
	}

	/**
	 * Determine if the gateway still requires setup.
	 *
	 * @return bool
	 */
	public function needs_setup()
	{
		return !$this->get_option('req_url') || !$this->get_option('pmt_chk_url') || !$this->get_option('mid') || !$this->get_option('terminalId') || !$this->get_option('key') || !$this->get_option('iv');
	}

	/**
	 * add_testmode_admin_settings_notice()
	 * Add a notice to the merchant_key and merchant_id fields when in test mode.
	 *
	 * @since 1.0.0
	 */
	public function add_testmode_admin_settings_notice()
	{
		$this->form_fields['req_url']['description'] .= ' <strong>' . esc_html__('Sandbox Req URL currently in use', 'woocommerce-getepay-payment') . ' ( ' . esc_html($this->req_url) . ' ).</strong>';
		$this->form_fields['pmt_chk_url']['description'] .= ' <strong>' . esc_html__('Sandbox Payment Check URL currently in use', 'woocommerce-getepay-payment') . ' ( ' . esc_html($this->pmt_chk_url) . ' ).</strong>';
		$this->form_fields['mid']['description'] .= ' <strong>' . esc_html__('Sandbox MID currently in use', 'woocommerce-getepay-payment') . ' ( ' . esc_html($this->mid) . ' ).</strong>';
		$this->form_fields['terminalId']['description'] .= ' <strong>' . esc_html__('Sandbox Terminal ID currently in use', 'woocommerce-getepay-payment') . ' ( ' . esc_html($this->terminalId) . ' ).</strong>';
		$this->form_fields['key']['description'] .= ' <strong>' . esc_html__('Sandbox Key currently in use', 'woocommerce-getepay-payment') . ' ( ' . esc_html($this->key) . ' ).</strong>';
		$this->form_fields['iv']['description'] .= ' <strong>' . esc_html__('Sandbox IV currently in use', 'woocommerce-getepay-payment') . ' ( ' . esc_html($this->iv) . ' ).</strong>';
	}

	/**
	 * check_requirements()
	 *
	 * Check if this gateway is enabled and available in the base currency being traded with.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function check_requirements()
	{

		$errors = [
			// Check if the store currency is supported by Getepay
			!in_array(get_woocommerce_currency(), $this->available_currencies) ? 'wc-gateway-getepay-error-invalid-currency' : null,
			// Check if the HOST 8443 PORT is open or not
			!$this->is_port_open($this->domain_ip, $this->getepay_req_url_port) ? 'wc-gateway-getepay-error-port' : null,
			// Check if user entered the merchant ID
			'yes' !== $this->get_option('testmode') && empty($this->get_option('req_url')) ? 'wc-gateway-getepay-error-missing-request-url' : null,
			// Check if user entered the merchant ID
			'yes' !== $this->get_option('testmode') && empty($this->get_option('pmt_chk_url')) ? 'wc-gateway-getepay-error-missing-payment-chk-url' : null,
			// Check if user entered the merchant ID
			'yes' !== $this->get_option('testmode') && empty($this->get_option('mid')) ? 'wc-gateway-getepay-error-missing-mid' : null,
			// Check if user entered the merchant ID
			'yes' !== $this->get_option('testmode') && empty($this->get_option('terminalId')) ? 'wc-gateway-getepay-error-missing-terminal-id' : null,
			// Check if user entered the merchant key
			'yes' !== $this->get_option('testmode') && empty($this->get_option('key')) ? 'wc-gateway-getepay-error-missing-key' : null,
			// Check if user entered a pass phrase
			'yes' !== $this->get_option('testmode') && empty($this->get_option('iv')) ? 'wc-gateway-getepay-error-missing-iv' : null
		];

		return array_filter($errors);
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		if ('yes' === $this->enabled) {
			$errors = $this->check_requirements();
			// Prevent using this gateway on frontend if there are any configuration errors.
			return 0 === count($errors);
		}

		return parent::is_available();
	}

	/**
     * Check if a specific port is open on the given IP.
     *
     * @param string $ip IP address.
     * @param int $port Port number.
     * @param int $timeout Timeout value.
     * @return bool
     */
    private function is_port_open($ip, $port, $timeout = 1)
    {
		// Skip localhost IP
		$local_ips = ['127.0.0.1', '::1']; // Add more loopback IPs if needed

		if (in_array($ip, $local_ips)) {
			return true;
		}

        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if ($socket) {
            fclose($socket);
            return true;
        } else {
            return false;
        }
    }

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options()
	{
		if (in_array(get_woocommerce_currency(), $this->available_currencies, true) && $this->is_port_open($this->domain_ip, $this->getepay_req_url_port)) {
			parent::admin_options();
		} else {
			?>
			<h3>
				<?php esc_html_e('Getepay', 'woocommerce-getepay-payment'); ?>
			</h3>
			<?php if (!in_array(get_woocommerce_currency(), $this->available_currencies, true)) { ?>
			<div class="inline error">
				<p>
					<strong>
						<?php esc_html_e('Gateway Disabled', 'woocommerce-getepay-payment'); ?>
					</strong>
					<?php
					/* translators: 1: a href link 2: closing href */
					echo wp_kses_post(sprintf(__('Choose Indian rupee (₹) as your store currency in %1$sGeneral Settings%2$s to enable the Getepay Gateway.', 'woocommerce-getepay-payment'), '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">', '</a>'));
					?>
				</p>
			</div>
			<?php } ?>
			<?php if (!$this->is_port_open($this->domain_ip, $this->getepay_req_url_port)) { ?>
			<div class="inline error">
				<p>
					<strong>
						<?php esc_html_e('Gateway Disabled', 'woocommerce-getepay-payment'); ?>
					</strong>
					<?php
					/* translators: 1: a href link 2: closing href */
					echo wp_kses_post(sprintf(__("Port 8443 is closed on the WordPress site HOST: $this->domain_ip", 'woocommerce-getepay-payment'), '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">', '</a>'));
					?>
				</p>
			</div>
			<?php } ?>
			<?php
		}
	}

	/**
	 * Generate the Getepay button link.
	 *
	 * @since 1.0.0
	 */
	public function pay_for_order($order_id)
	{
			$order = wc_get_order($order_id);
			$order->update_status('pending-payment', __('Awaiting payment.', 'woocommerce-getepay-payment'));
			$url = $this->req_url;
			$mid = $this->mid;
			$terminalId = $this->terminalId;
			$key = $this->key;
			$iv = $this->iv;
			//$ru = esc_url_raw( add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ) );
			$ru = $this->response_url;
			$amt = $order->get_total();
			$udf1 = self::get_order_prop($order, 'billing_first_name') . " " . self::get_order_prop($order, 'billing_last_name');
			$udf2 = $order->get_billing_phone();
			$udf3 = self::get_order_prop($order, 'billing_email');
			$request = array(
				"mid" => $mid,
				"amount" => $amt,
				"merchantTransactionId" => $order_id,
				"transactionDate" => date("Y-m-d H:i:s"),
				"terminalId" => $terminalId,
				"udf1" => $udf1,
				"udf2" => $udf2,
				"udf3" => $udf3,
				"udf4" => self::get_order_prop($order, 'order_key'),
				"udf5" => "",
				"udf6" => "",
				"udf7" => "",
				"udf8" => "",
				"udf9" => "",
				"udf10" => "",
				"ru" => $ru,
				"callbackUrl" => "",
				"currency" => "INR",
				"paymentMode" => "ALL",
				"bankId" => "",
				"txnType" => "single",
				"productType" => "IPG",
				"txnNote" => sprintf(esc_html__('New order from %s', 'woocommerce-getepay-payment'), get_bloginfo('name')),
				"vpa" => $terminalId,
			);
			$json_requset = json_encode($request);
			$key = base64_decode($key);
			$iv = base64_decode($iv);
			// Encryption Code //
			$ciphertext_raw = openssl_encrypt($json_requset, "AES-256-CBC", $key, $options = OPENSSL_RAW_DATA, $iv);
			$ciphertext = bin2hex($ciphertext_raw);
			$newCipher = strtoupper($ciphertext);
			//print_r($newCipher);exit;
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
			if($json){
			$paymentId = $json->paymentId;
			$pgUrl = $json->paymentUrl;
			// Update the custom field "Getepay PaymentId" for the order
			//update_post_meta($order_id, 'GetepaypPaymentId', $paymentId);
			$order->update_meta_data('GetepaypPaymentId', $paymentId);
			$order->save();
			wp_redirect($pgUrl);
			}else{
				$settings_url = add_query_arg(
					array(
						'page' => 'wc-settings',
						'tab' => 'checkout',
						'section' => 'getepay_gateway',
					),
					admin_url( 'admin.php' )
				);
				
				echo '<p>' . esc_html__('Check Your Getepay Config. Data is correct or not.', 'woocommerce-getepay-payment') . '</p>';
				echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure here', 'woocommerce-getepay-payment' ) . '</a>';
			}
	}


	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);

		return array(
			'result' => 'success',
			'redirect' => $order->get_checkout_payment_url(true),
		);
		
	}

	/**
	 * Reciept page.
	 *
	 * Display text and a button to direct the user to Getepay.
	 *
	 * @param WC_Order $order Order object.
	 * @since 1.0.0
	 */
	public function receipt_page($order)
	{
		echo '<p>' . esc_html__('Redirect to Getepay for payment.', 'woocommerce-getepay-payment') . '</p>';
		$this->pay_for_order($order);
	}


/**
 * Add text at the bottom of WooCommerce Getepay payment gateway settings page.
 */
public function add_text_to_payment_gateway_settings()
{
    // Check if the current tab is the Getepay payment tab
    $current_tab = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';

    // Use a flag to track if the text has been added
    static $text_added = false;

    if ($current_tab === 'getepay_gateway' && !$text_added) {
        echo '<div class="getepay-logo" style="text-align: right;">';
        echo '
        <footer class="footer">
            <div class="row">
                <div class="col-sm-12 text-right mb-2 footer-logo">
                    <p style="display: inline-block; margin-right: 10px; font-weight: bold;">&copy;' . date('Y') . ' Powered By</p>
                    <img src="' . esc_url($this->icon) . '" width="50" height="50" style="vertical-align: middle;">
                </div>
            </div>
        </footer>';
        echo '</div>';

        // Set the flag to true to indicate that the text has been added
        $text_added = true;
    }
}
	
	/**
	 * Check Getepay response.
	 *
	 * @since 1.0.0
	 */
	public function getepay_check_response()
	{
		// phpcs:ignore.WordPress.Security.NonceVerification.Missing
		$this->handle_getepay_request(stripslashes_deep($_POST));
		// header( 'HTTP/1.0 200 OK' );
		header( 'HTTP/1.1 400 OK' );
		//flush();
	}

	/**
	 * Check Getepay Payment Response.
	 *
	 * @param array $data Data.
	 * @since 1.0.0
	 */
	public function handle_getepay_request($data)
	{
			global $woocommerce;
			$key = base64_decode($this->get_option("key"));
			$iv = base64_decode($this->get_option("iv"));
			$ciphertext_raw = $ciphertext_raw = hex2bin($data['response']);
			$original_plaintext = openssl_decrypt($ciphertext_raw, "AES-256-CBC", $key, $options = OPENSSL_RAW_DATA, $iv);
			$json = json_decode(json_decode($original_plaintext, true), true);
			$txnAmount = $json["txnAmount"];

		$this->log(
			PHP_EOL
			. '----------'
			. PHP_EOL . 'Getepay Payment call received'
			. PHP_EOL . '----------'
		);
		$this->log('Get posted data');
		$this->log('Getepay Data: ' . print_r($json, true));

		$getepay_error = false;
		$getepay_done = false;
		$debug_email = $this->get_option('debug_email', get_option('admin_email'));
		$session_id = $json['udf4'];		
		$vendor_name = get_bloginfo('name', 'display');
		$vendor_url = home_url('/');
		$order_id = absint($json["merchantOrderNo"]);
		$order_key = wc_clean($session_id);
		$order = wc_get_order($order_id);
		$original_order = $order;

		if (false === $data) {
			$getepay_error = true;
			$getepay_error_message = GE_ERR_BAD_ACCESS;
		}

		// Check data against internal order.
		if (!$getepay_error && !$getepay_done) {
			$this->log('Check data against internal order');

			// Check order amount.
			if (
				!$this->amounts_equal($json["txnAmount"], self::get_order_prop($order, 'order_total'))
			) { // if changing payment method.
				$getepay_error = true;
				$getepay_error_message = GE_ERR_AMOUNT_MISMATCH;
			} elseif (strcasecmp($json['udf4'], self::get_order_prop($order, 'order_key')) != 0) {
				// Check session ID.
				$getepay_error = true;
				$getepay_error_message = GE_ERR_SESSIONID_MISMATCH;
			}
		}

		// Get internal order and verify it hasn't already been processed.
		if ( ! $getepay_error && ! $getepay_done ) {
			$this->log_order_details( $order );

			// Check if order has already been processed.
			if ( 'completed' === self::get_order_prop( $order, 'status' ) ) {
				$this->log( 'Order has already been processed' );
				$getepay_done = true;
			}
		}
		// If an error occurred.
		if ($getepay_error) {
			$this->log('Error occurred: ' . $getepay_error_message);

			if ($this->send_debug_email) {
				$this->log('Sending email notification');

				// Send an email.
				$subject = 'Getepay ITN error: ' . $getepay_error_message;
				$body =
					"Hi,\n\n" .
					"An invalid Getepay transaction on your website requires attention\n" .
					"------------------------------------------------------------\n" .
					'Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")\n" .
					'Remote IP Address: ' . sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) . "\n" .
					'Remote host name: ' . gethostbyaddr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) . "\n" .
					'Order ID: ' . self::get_order_prop($order, 'id') . "\n" .
					'User ID: ' . self::get_order_prop($order, 'user_id') . "\n";
				if (isset($json['getepayTxnId'])) {
					$body .= 'Getepay Transaction ID: ' . esc_html($json['getepayTxnId']) . "\n";
				}
				if (isset($json['paymentStatus'])) {
					$body .= 'Getepay Payment Status: ' . esc_html($json['paymentStatus']) . "\n";
				}

				$body .= "\nError: " . $getepay_error_message . "\n";

				switch ($getepay_error_message) {
					case GE_ERR_AMOUNT_MISMATCH:
						$body .=
							'Value received : ' . esc_html($json['txnAmount']) . "\n"
							. 'Value should be: ' . self::get_order_prop($order, 'order_total');
						break;

					case GE_ERR_ORDER_ID_MISMATCH:
						$body .=
							'Value received : ' . esc_html($json['merchantOrderNo']) . "\n"
							. 'Value should be: ' . self::get_order_prop($order, 'id');
						break;

					case GE_ERR_SESSIONID_MISMATCH:
						$body .=
							'Value received : ' . esc_html($json['udf4']) . "\n"
							. 'Value should be: ' . self::get_order_prop($order, 'id');
						break;

					// For all other errors there is no need to add additional information.
					default:
						break;
				}

				wp_mail($debug_email, $subject, $body);
			} // End if().
		} elseif (!$getepay_done) {

			$this->log('Check status and update order');

			if (self::get_order_prop($original_order, 'order_key') !== $order_key) {
				$this->log('Order key does not match');
				exit;
			}

			$status = strtolower($json["txnStatus"]);

			if ('success' === $status) {
				$this->handle_getepay_payment_complete($json, $order);
			} elseif ('failed' === $status) {
				$this->handle_getepay_payment_failed($json, $order);
			} elseif ('pending' === $status) {
				$this->handle_getepay_payment_pending($json, $order);
			}
		} // End if().

		$this->log(
			PHP_EOL
			. '----------'
			. PHP_EOL . 'End Getepay call'
			. PHP_EOL . '----------'
		);

	}

	/**
	 * Handle logging the order details.
	 *
	 * @since 1.4.5
	 */
	public function log_order_details($order)
	{
		$customer_id = $order->get_user_id();

		$details = "Order Details:"
			. PHP_EOL . 'customer id:' . $customer_id
			. PHP_EOL . 'order id:   ' . $order->get_id()
			. PHP_EOL . 'parent id:  ' . $order->get_parent_id()
			. PHP_EOL . 'status:     ' . $order->get_status()
			. PHP_EOL . 'total:      ' . $order->get_total()
			. PHP_EOL . 'currency:   ' . $order->get_currency()
			. PHP_EOL . 'key:        ' . $order->get_order_key()
			. "";

		$this->log($details);
	}


	/**
	 * This function handles payment complete request by Getepay.
	 * @param array $json should be from the Gatewy ITN callback.
	 * @param WC_Order $order
	 */
	public function handle_getepay_payment_complete($json, $order)
	{
		global $woocommerce;
		$user_id = $order->get_user_id();
		wp_set_current_user($user_id);
		$getepayTxnId = $json['getepayTxnId'];
		$this->log("- Getepay payment successful, Getepay Txn Id: $getepayTxnId");
		$order->add_order_note("Getepay payment successful <br/>Getepay Txn Id: $getepayTxnId");
		$order_id = self::get_order_prop($order, 'id');
		$order->payment_complete($getepayTxnId);
		$debug_email = $this->get_option('debug_email', get_option('admin_email'));
		$vendor_name = get_bloginfo('name', 'display');
		$vendor_url = home_url('/');
		if ($this->send_debug_email) {
			$subject = 'Getepay Payment on your site';
			$body =
				"Hi,\n\n"
				. "A Getepay transaction has been completed on your website\n"
				. "------------------------------------------------------------\n"
				. 'Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")\n"
				. 'Order ID: ' . esc_html($order_id) . "\n"
				. 'Getepay Transaction ID: ' . esc_html($json['getepayTxnId']) . "\n"
				. 'Getepay Payment Status: ' . esc_html($json['paymentStatus']) . "\n"
				. 'Order Status Code: ' . self::get_order_prop($order, 'status');
			wp_mail($debug_email, $subject, $body);
		}
		wp_redirect($this->get_return_url( $order ));
		exit;
	}

	/**
	 * @param $json
	 * @param $order
	 */
	public function handle_getepay_payment_failed($json, $order)
	{
		$getepayTxnId = $json['getepayTxnId'];
		$user_id = $order->get_user_id();
		wp_set_current_user($user_id);
		$this->log("- Getepay payment failed, Getepay Txn Id: $getepayTxnId");
		$order->update_status('failed', sprintf(__("Getepay payment %s <br/>Getepay Txn Id: $getepayTxnId.<br/>", "woocommerce-getepay-payment"), strtolower(sanitize_text_field($json['paymentStatus']))));
		$debug_email = $this->get_option('debug_email', get_option('admin_email'));
		$vendor_name = get_bloginfo('name', 'display');
		$vendor_url = home_url('/');

		if ($this->send_debug_email) {
			$subject = 'Getepay Payment Transaction on your site';
			$body =
				"Hi,\n\n" .
				"A failed Getepay transaction on your website requires attention\n" .
				"------------------------------------------------------------\n" .
				'Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")\n" .
				'Order ID: ' . self::get_order_prop($order, 'id') . "\n" .
				'User ID: ' . self::get_order_prop($order, 'user_id') . "\n" .
				'Getepay Transaction ID: ' . esc_html($json['getepayTxnId']) . "\n" .
				'Getepay Payment Status: ' . esc_html($json['paymentStatus']);
			wp_mail($debug_email, $subject, $body);
		}
		wp_redirect($this->get_return_url( $order ));
		exit;
	}

	/**
	 * @since 1.4.0 introduced
	 * @param $json
	 * @param $order
	 */
	public function handle_getepay_payment_pending($json, $order)
	{
		$getepayTxnId = $json['getepayTxnId'];
		$user_id = $order->get_user_id();
		wp_set_current_user($user_id);
		$this->log("- Getepay payment pending, Getepay Txn Id: $getepayTxnId");
		// Need to wait for "Completed" before processing
		/* translators: 1: payment status */
		$order->update_status('pending-payment', sprintf(esc_html__("Getepay payment %s <br/>Getepay Txn Id: $getepayTxnId.<br/>", "woocommerce-getepay-payment"), strtolower(sanitize_text_field($json['paymentStatus']))));
		$debug_email = $this->get_option('debug_email', get_option('admin_email'));
		$vendor_name = get_bloginfo('name', 'display');
		$vendor_url = home_url('/');

		if ($this->send_debug_email) {
			$subject = 'Getepay Payment Transaction on your site';
			$body =
				"Hi,\n\n" .
				"A pending Getepay transaction on your website requires attention\n" .
				"------------------------------------------------------------\n" .
				'Site: ' . esc_html($vendor_name) . ' (' . esc_url($vendor_url) . ")\n" .
				'Order ID: ' . self::get_order_prop($order, 'id') . "\n" .
				'User ID: ' . self::get_order_prop($order, 'user_id') . "\n" .
				'Getepay Transaction ID: ' . esc_html($json['getepayTxnId']) . "\n" .
				'Getepay Payment Status: ' . esc_html($json['paymentStatus']);
			wp_mail($debug_email, $subject, $body);
		}
		wp_redirect($this->get_return_url( $order ));
		exit;
	}

	/**
	 * Setup constants.
	 *
	 * Setup common values and messages used by the Getepay gateway.
	 *
	 * @since 1.0.0
	 */
	public function setup_constants()
	{
		// Create user agent string.
		// define('PF_SOFTWARE_NAME', 'WooCommerce');
		// define('PF_SOFTWARE_VER', WC_VERSION);
		// define('PF_MODULE_NAME', 'WooCommerce-getepay-Free');
		// define('PF_MODULE_VER', $this->version);

		// Features
		// - PHP
		$pf_features = 'PHP ' . phpversion() . ';';

		// - cURL
		if (in_array('curl', get_loaded_extensions())) {
			define('GE_CURL', '');
			$pf_version = curl_version();
			$pf_features .= ' curl ' . $pf_version['version'] . ';';
		} else {
			$pf_features .= ' nocurl;';
		}

		// Create user agrent
		//define('PF_USER_AGENT', PF_SOFTWARE_NAME . '/' . PF_SOFTWARE_VER . ' (' . trim($pf_features) . ') ' . PF_MODULE_NAME . '/' . PF_MODULE_VER);

		// General Defines
		define('GE_TIMEOUT', 15);
		define('GE_EPSILON', 0.01);

		// Messages
		// Error
		define('GE_ERR_AMOUNT_MISMATCH', esc_html__('Amount mismatch', 'woocommerce-getepay-payment'));
		define('GE_ERR_BAD_ACCESS', esc_html__('Bad access of page', 'woocommerce-getepay-payment'));
		define('GE_ERR_BAD_SOURCE_IP', esc_html__('Bad source IP address', 'woocommerce-getepay-payment'));
		define('GE_ERR_CONNECT_FAILED', esc_html__('Failed to connect to Getepay', 'woocommerce-getepay-payment'));
		define('GE_ERR_INVALID_SIGNATURE', esc_html__('Security signature mismatch', 'woocommerce-getepay-payment'));
		define('GE_ERR_MERCHANT_ID_MISMATCH', esc_html__('Merchant ID mismatch', 'woocommerce-getepay-payment'));
		define('GE_ERR_NO_SESSION', esc_html__('No saved session found for Getepay transaction', 'woocommerce-getepay-payment'));
		define('GE_ERR_ORDER_ID_MISSING_URL', esc_html__('Order ID not present in URL', 'woocommerce-getepay-payment'));
		define('GE_ERR_ORDER_ID_MISMATCH', esc_html__('Order ID mismatch', 'woocommerce-getepay-payment'));
		define('GE_ERR_ORDER_INVALID', esc_html__('This order ID is invalid', 'woocommerce-getepay-payment'));
		define('GE_ERR_ORDER_NUMBER_MISMATCH', esc_html__('Order Number mismatch', 'woocommerce-getepay-payment'));
		define('GE_ERR_ORDER_PROCESSED', esc_html__('This order has already been processed', 'woocommerce-getepay-payment'));
		define('GE_ERR_PDT_FAIL', esc_html__('PDT query failed', 'woocommerce-getepay-payment'));
		define('GE_ERR_PDT_TOKEN_MISSING', esc_html__('PDT token not present in URL', 'woocommerce-getepay-payment'));
		define('GE_ERR_SESSIONID_MISMATCH', esc_html__('Session ID mismatch', 'woocommerce-getepay-payment'));
		define('GE_ERR_UNKNOWN', esc_html__('Unkown error occurred', 'woocommerce-getepay-payment'));

		// General
		define('GE_MSG_OK', esc_html__('Payment was successful', 'woocommerce-getepay-payment'));
		define('GE_MSG_FAILED', esc_html__('Payment has failed', 'woocommerce-getepay-payment'));
		define('GE_MSG_PENDING', esc_html__('The payment is pending. Please note, you will receive another Instant Transaction Notification when the payment status changes to "Completed", or "Failed"', 'woocommerce-getepay-payment'));

		do_action('woocommerce_gateway_getepay_setup_constants');
	}

	/**
	 * Log system processes.
	 * @since 1.0.0
	 */
	public function log($message)
	{
		if ('yes' === $this->get_option('testmode') || $this->enable_logging) {
			if (empty($this->logger)) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add('getepay', $message);
		}
	}

	/**
	 * amounts_equal()
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @author Jonathan Smit
	 * @param $amount1 Float 1st amount for comparison
	 * @param $amount2 Float 2nd amount for comparison
	 * @since 1.0.0
	 * @return bool
	 */
	public function amounts_equal($amount1, $amount2)
	{
		return !(abs(floatval($amount1) - floatval($amount2)) > GE_EPSILON);
	}

	/**
	 * Get order property with compatibility check on order getter introduced
	 * in WC 3.0.
	 *
	 * @since 1.4.1
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $prop  Property name.
	 *
	 * @return mixed Property value
	 */
	public static function get_order_prop($order, $prop)
	{
		switch ($prop) {
			case 'order_total':
				$getter = array($order, 'get_total');
				break;
			default:
				$getter = array($order, 'get_' . $prop);
				break;
		}

		return is_callable($getter) ? call_user_func($getter) : $order->{$prop};
	}

	/**
	 * Gets user-friendly error message strings from keys
	 *
	 * @param   string  $key  The key representing an error
	 *
	 * @return  string        The user-friendly error message for display
	 */
	public function get_error_message($key)
	{
		switch ($key) {
			case 'wc-gateway-getepay-error-invalid-currency':
				return esc_html__('Your store uses a currency that Getepay doesn\'t support yet.', 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-port':
				return esc_html__("PORT 8443 is closed on your site HOST: $this->domain_ip, Please Enable 8443 PORT.", 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-missing-request-url':
				return esc_html__('Getepay requires a Request URL.', 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-missing-payment-chk-url':
				return esc_html__('Getepay requires a Payment Check URL.', 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-missing-mid':
				return esc_html__('Getepay requires a MID to work.', 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-missing-terminal-id':
				return esc_html__('Getepay requires a Terminal ID to work.', 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-missing-key':
				return esc_html__('Getepay requires a KEY to work.', 'woocommerce-getepay-payment');
			case 'wc-gateway-getepay-error-missing-iv':
				return esc_html__('Getepay requires a IV to work.', 'woocommerce-getepay-payment');
			default:
				return '';
		}
	}

	/**
	 * Show possible admin notices
	 */
	public function admin_notices()
	{

		// Get requirement errors.
		$errors_to_show = $this->check_requirements();

		// If everything is in place, don't display it.
		if (!count($errors_to_show)) {
			return;
		}

		// If the gateway isn't enabled, don't show it.
		if ('no' === $this->enabled) {
			return;
		}

		// Use transients to display the admin notice once after saving values.
		if (!get_transient('wc-gateway-getepay-admin-notice-transient')) {
			set_transient('wc-gateway-getepay-admin-notice-transient', 1, 1);

			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__('To use Getepay as a payment provider, you need to fix the problems below:', 'woocommerce-getepay-payment') . '</p>'
				. '<ul style="list-style-type: disc; list-style-position: inside; padding-left: 2em;">'
				. wp_kses_post(
					array_reduce(
						$errors_to_show,
						function ($errors_list, $error_item) {
							$errors_list = $errors_list . PHP_EOL . ('<li>' . $this->get_error_message($error_item) . '</li>');
							return $errors_list;
						},
						''
					)
				)
				. '</ul></p></div>';
		}
	}
}