<?php

class WC_Zapster extends WC_Payment_Gateway {

	function __construct() {
		$this->id = "zapster";
		$this->method_title = __( "Zapster XRP", 'zapster' );
		$this->method_description = __( "Pay via Ripple's XRP using Zapster XRP Payment Gateway", 'zapster' );
		$this->title = __( "Zapster XRP", 'zapster' );
		$this->icon = "https://zapster.io/Assets/images/logo.png";
		$this->has_fields = false;
		$this->init_form_fields();
		$this->init_settings();

		foreach ( $this->settings as $setting_key => $value ) {
			$this->$setting_key = $value;
		}
		
		add_action( 'admin_notices', array( $this,	'do_ssl_check' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'zapster_payment_process' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'zapster_order_processed' ) );		
		
		// Save settings
		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}		
	} // Here is the  End __construct()

	// administration fields for specific Gateway
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'		=> __( 'Enable / Disable', 'zapster' ),
				'label'		=> __( 'Enable Zapster XRP Payment Gateway', 'zapster' ),
				'type'		=> 'checkbox',
				'default'	=> 'no',
			),
			'title' => array(
				'title'		=> __( 'Title', 'zapster' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This controls the title which the user sees during checkout.', 'zapster' ),
				'default'	=> __( 'XRP', 'zapster' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'zapster' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __( 'This controls the description which the user sees during checkout.', 'zapster' ),
				'default'     => __( "Pay using XRP by sending a payment direct from your Ripple Wallet.", 'zapster' ),
			),
			'account' => array(
				'title'		=> __( 'Zapster Account Id', 'zapster' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This is the Account ID provided by Zapster when you signed up for an account.', 'zapster' ),
			),
			'secret' => array(
				'title'		=> __( 'Zapster Secret Password', 'zapster' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This is the secret password used to encrypt / decrypt data sent back to you.', 'zapster' ),
			),
			'frameWidth' => array(
				'title'		=> __( 'Zapster IFRAME Width', 'zapster' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This is the width of the embedded IFRAME.', 'zapster' ),
				'default'	=> __( '100%', 'zapster' ),
			),
			'frameHeight' => array(
				'title'		=> __( 'Zapster IFRAME Height', 'zapster' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This is the height of the embedded IFRAME.', 'zapster' ),
				'default'	=> __( '700px', 'zapster' ),
			),
			'test' => array(
				'title'		=> __( 'Zapster Test Mode', 'zapster' ),
				'label'		=> __( 'Enable Test Mode', 'zapster' ),
				'type'		=> 'checkbox',
				'description' => __( 'This is the test mode of gateway - not yet supported', 'zapster' ),
				'default'	=> 'no',
			)
		);		
	}
	
	// Response handled for payment gateway
	public function process_payment( $order_id ) {
		global $woocommerce;

		$customer_order = new WC_Order( $order_id );
		$order_currency = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $customer_order->order_currency : $customer_order->get_currency();

		$payload = array(
			"account"  		=> $this->account,
			"amount"   		=> $customer_order->order_total,
			"currency"		=> $order_currency,
			"test"			=> ( $this->test == "yes" ) ? true : false,
			"callbackUrl" 	=> $customer_order->get_checkout_order_received_url()
		);

		// Send this payload to Zapster to get a transaction id back
		$response = wp_remote_post( 'https://zapster.io/api/transactions', array(
			'headers'   => array('Content-Type' => 'application/json; charset=utf-8'),
			'body'      => json_encode($payload),
			'method'    => 'POST',
			'timeout'   => 90,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) ) 
			throw new Exception( __( 'There is an issue connecting to the Zapster payment gateway. Sorry for the inconvenience.', 'zapster' ) );

		if ( empty( $response['body'] ) )
			throw new Exception( __( 'Zapster.io\'s response did not contain any data.', 'zapster' ) );

		$response_body = wp_remote_retrieve_body( $response );
		$zapsterResponse = json_decode($response_body);

		// Get payment page url
		$paymentpage = $customer_order->get_checkout_payment_url(true);
		
		// Update order properties and save changes
		$customer_order->set_transaction_id($zapsterResponse->transaction->id);
		$customer_order->add_meta_data('ZapsterTransactionId', $zapsterResponse->transaction->id, true);
		$customer_order->add_meta_data('ZapsterPinCode', $zapsterResponse->transaction->pinCode, true);
		$customer_order->update_status('pending', 'Awaiting payment notification from Zapster');
		$customer_order->add_order_note('Awaiting XRP <a href="' . $paymentpage . '">Payment</a>');			
		$customer_order->save();
		$customer_order->save_meta_data();

		// Redirect to payment page
		return array(
			'result' => 'success',
			'redirect' => $paymentpage
		);
	}

	public function zapster_payment_process( $order_id )
	{
		global $woocommerce;
		$order = new WC_Order( $order_id );
		
		$order_id       = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->id             : $order->get_id();
		$order_status   = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->status         : $order->get_status();
		$post_status    = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->post_status    : get_post_status( $order_id );
		$order_currency = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->order_currency : $order->get_currency();
		$transaction_id = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->transaction_id : $order->get_transaction_id();

		if ($order === false)
		{
			echo '<br><h2>Information</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not retrieve the order details for order %s. Cannot continue!'), $order_id)."</div>";
		}
		elseif ($order_status == "cancelled" || $post_status == "wc-cancelled")
		{
			echo '<br><h2>' . __( 'Information') . '</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>". __( "This order's status is 'Cancelled' - it cannot be paid for. Please contact us if you need assistance.")."</div>";
		}
		elseif ($transaction_id == "")
		{
			echo '<br><h2>' . __( 'Information') . '</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not retrieve the transaction details for order %s. Cannot continue!'), $order_id)."</div>";
		}
		elseif (!class_exists('WC_Zapster'))
		{
			echo '<br><h2>' . __( 'Information') . '</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>".sprintf(__( "Please try a different payment method. Admin needs to configure <a href='%s'>Zapster XRP Payment Gateway</a> to accept XRP Payments online."), "https://zapster.io")."</div>";
		}
		else 
		{ 
			// Load the iframe using the transaction id given by the API call
			echo '<iframe src="https://zapster.io/checkout/' . $transaction_id . '" style="width: ' . $this->frameWidth .'; height: ' . $this->frameHeight . '; overflow: hidden; border: none"></iframe>';
		} 

	    return true;
	}

	public function zapster_order_processed($order_id)
	{
		global $woocommerce;
		$order = new WC_Order( $order_id );
		
		$order_id       = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->id             : $order->get_id();
		$order_status   = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->status         : $order->get_status();
		$post_status    = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->post_status    : get_post_status( $order_id );
		$order_currency = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->order_currency : $order->get_currency();
		$order_total    = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->order_total    : $order->get_total();
		$transaction_id = (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '<')) ? $order->transaction_id : $order->get_transaction_id();

		if ($order === false)
		{
			echo '<br><h2>Information</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not retrieve the order details for order %s. Cannot continue!'), $order_id)."</div>";
		}
		elseif ($order_status == "cancelled" || $post_status == "wc-cancelled")
		{
			echo '<br><h2>' . __( 'Information') . '</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>". __( "This order's status is 'Cancelled' - it cannot be paid for. Please contact us if you need assistance.")."</div>";
		}
		elseif ($transaction_id == "")
		{
			echo '<br><h2>' . __( 'Information') . '</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not retrieve the transaction details for order %s. Cannot continue!'), $order_id)."</div>";
		}
		elseif (!class_exists('WC_Zapster'))
		{
			echo '<br><h2>' . __( 'Information') . '</h2>' . PHP_EOL;
			echo "<div class='woocommerce-error'>".sprintf(__( "Please try a different payment method. Admin needs to configure <a href='%s'>Zapster XRP Payment Gateway</a> to accept XRP Payments online."), "https://zapster.io")."</div>";
		}
		else 
		{ 
			// Decrypt 
			$returned_id = $_GET["id"];
			$returned_transaction = $_GET["transaction"];
			$decrypted_transaction = $this->decrypt($returned_transaction, $this->secret, mb_convert_encoding($returned_id, 'UTF-16LE'));

			if ($decrypted_transaction == "") {
				echo '<br><h2>Information</h2>' . PHP_EOL;
				echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not decrypt the transaction details for order %s. Cannot continue!'), $order_id)."</div>";
			} else {
				// Parse JSON
				$zapster_transaction = json_decode($decrypted_transaction);

				if ($zapster_transaction === false) {
					echo '<br><h2>Information</h2>' . PHP_EOL;
					echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not decode the transaction details for order %s. Cannot continue!'), $order_id)."</div>";
				} else {
					// Get pin from meta
					$zapster_pinCode = $order->get_meta('ZapsterPinCode', true);

					// The must be confirmed, transaction id needs to match, along with the pin, account, and amount
					if (strtoupper($zapster_transaction->status) != 'CONFIRMED' || strtoupper($zapster_transaction->id) != strtoupper($transaction_id) || $zapster_transaction->pinCode != $zapster_pinCode || strtoupper($zapster_transaction->account) != strtoupper($this->account) || $zapster_transaction->originalAmount != $order_total)
					{				
						if (strtoupper($zapster_transaction->status) == 'EXPIRED')		
						{
							// Add meta and notes
							$order->add_meta_data('CallbackUrl', $zapster_transaction->callbackUrl, true);
							$order->add_meta_data('SourceWallet', $zapster_transaction->source, true);
							$order->add_meta_data('ZapsterTransactionDecrypted', $decrypted_transaction, true);
							$order->add_meta_data('ZapsterTransactionEncrypted', $returned_transaction, true);
							$order->add_order_note('XRP Payment Expired');	
							// Update the status
							$order->update_status('failed', 'Zapster payment notification expired');
							// Save changes
							$order->save();
							$order->save_meta_data();
							
							echo "<style>.woocommerce-thankyou-order-received, .woocommerce-thankyou-order-details {display:none;}</style>";
							echo "<p class='woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed'>Unfortunately your order cannot be processed as the time limit for your transaction has elapsed.<br>Please attempt your purchase again.</p>";
							echo "<p class='woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions'><a href='" . esc_url( $order->get_checkout_payment_url() ) . "' class='button pay'>Pay</a></p>";

						} else {
							echo '<br><h2>Information</h2>' . PHP_EOL;
							echo "<div class='woocommerce-error'>". sprintf(__( 'The Zapster payment plugin was called to process a payment but could not verify the transaction details for order %s. Cannot continue!'), $order_id)."</div>";
						}
					} else {
						// Add meta and notes
						$order->add_meta_data('CallbackUrl', $zapster_transaction->callbackUrl, true);
						$order->add_meta_data('SourceWallet', $zapster_transaction->source, true);
						$order->add_meta_data('ZapsterTransactionDecrypted', $decrypted_transaction, true);
						$order->add_meta_data('ZapsterTransactionEncrypted', $returned_transaction, true);
						$order->add_order_note('XRP Payment Confirmed - <a href="https://ripple.com/build/ripple-info-tool/#' . $zapster_transaction->blockchainId . '">XRP Ledger</a>');	

						// Update the status
						$order->update_status('completed', 'Zapster payment notification confirmed');

						// Reduce stock levels
						if (true === version_compare(WOOCOMMERCE_VERSION, '3.0', '>')) wc_reduce_stock_levels( $order_id ); else $order->reduce_order_stock();	 

						// paid order marked
						$order->payment_complete();
 
						// Empty cart
						$woocommerce->cart->empty_cart();

						// Save changes
						$order->save();
						$order->save_meta_data();

						echo '<br><h2>' . __( 'Payment Confirmed') . '</h2>' . PHP_EOL;
						echo "<div class='woocommerce-info'>". sprintf(__( 'XRP Payment has been confirmed for orderID %s.!<br>Payment was made from the wallet %s<br>This can be seen on the <a href="https://ripple.com/build/ripple-info-tool/#%s">XRP Ledger</a>'), $order_id, $zapster_transaction->source, $zapster_transaction->blockchainId)."</div>";

						return array(
							'result' => 'success',
							'redirect' => $this->get_return_url( $order )
						);							
					}
				}
			}
		} 

		return false;
	}
	
	// Validate fields
	public function validate_fields() {
		return true;
	}

	public function do_ssl_check() {
		if( $this->enabled == "yes" ) {
			if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
				echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";	
			}
		}		
	}
	
    private function derived($password, $salt) {
		$AESKeyLength = 32;
		$AESIVLength = 16;
		$pbkdf2 = $this->hash_pbkdf2_local("sha1", $password, $salt, 32768, $AESKeyLength + $AESIVLength, true);
		$derived = new stdClass();
		$derived->key = substr($pbkdf2, 0, $AESKeyLength);
		$derived->iv = substr($pbkdf2, $AESKeyLength, $AESIVLength);
        return $derived;
	}
	
	private function hash_pbkdf2_local($algo, $password, $salt, $count, $length = 0, $raw_output = false) {
		if (!function_exists('hash_pbkdf2'))
		{
			if (!in_array(strtolower($algo), hash_algos())) trigger_error(__FUNCTION__ . '(): Unknown hashing algorithm: ' . $algo, E_USER_WARNING);
			if (!is_numeric($count)) trigger_error(__FUNCTION__ . '(): expects parameter 4 to be long, ' . gettype($count) . ' given', E_USER_WARNING);
			if (!is_numeric($length)) trigger_error(__FUNCTION__ . '(): expects parameter 5 to be long, ' . gettype($length) . ' given', E_USER_WARNING);
			if ($count <= 0) trigger_error(__FUNCTION__ . '(): Iterations must be a positive integer: ' . $count, E_USER_WARNING);
			if ($length < 0) trigger_error(__FUNCTION__ . '(): Length must be greater than or equal to 0: ' . $length, E_USER_WARNING);

			$output = '';
			$block_count = $length ? ceil($length / strlen(hash($algo, '', $raw_output))) : 1;
			for ($i = 1; $i <= $block_count; $i++)
			{
				$last = $xorsum = hash_hmac($algo, $salt . pack('N', $i), $password, true);
				for ($j = 1; $j < $count; $j++)
				{
					$xorsum ^= ($last = hash_hmac($algo, $last, $password, true));
				}
				$output .= $xorsum;
			}

			if (!$raw_output) {
				$output = bin2hex($output);
			}
			
			return $length ? substr($output, 0, $length) : $output;
		} else {			
			return hash_pbkdf2($algo, $password, $salt, $count, $length, $raw_output);
		}
	}
	
    private function encrypt($message, $password, $salt) {
		$derived = $this->derived($password, $salt);
        return openssl_encrypt($message, 'AES-256-CBC', $derived->key, null, $derived->iv);
	}
	
    private function decrypt($message, $password, $salt) {
		$derived = $this->derived($password, $salt);
		return mb_convert_encoding(openssl_decrypt($message, 'AES-256-CBC', $derived->key, null, $derived->iv), 'UTF-8', 'UTF-16LE');
	}
}