<?php

class WC_Arrow_Payments_SDK {
	/*
		REST Post Client Helper
	*/
	public static function restcall($url,$vars,$ssl) {
		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
		);
		$data = json_encode( $vars );
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $ssl);
		curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $ssl);
		curl_setopt($handle, CURLOPT_POST, 1);
		curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
		curl_setopt($handle, CURLOPT_HEADER, true);
		$response = curl_exec($handle);
		$code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);
		$full_response = array($body, $header);
		// Check for errors
		if($response === FALSE){
			die(curl_error($handle));
		}
		return $full_response;
	}	
	
	/*
		String Helper for error message
	*/
	public static function get_string_between($string, $start, $end){
		$string = " ".$string;
		$ini = strpos($string,$start);
		if ($ini == 0) return "";
		$ini += strlen($start);
		$len = strpos($string,$end,$ini) - $ini;
		return substr($string,$ini,$len);
	}
	/**
     * A snippet to send to ArrowPayments to redirect the user back to the
     * merchant's server. Use this on your relay response page.
     *
     * @param string $redirect_url Where to redirect the user.
     *
     * @return string
     */
    public static function getRelayResponseSnippet($redirect_url)
    {
        return "<html><head><script language=\"javascript\">
                <!--
                window.location=\"{$redirect_url}\";
                //-->
                </script>
                </head><body><noscript><meta http-equiv=\"refresh\" content=\"1;url={$redirect_url}\"></noscript></body></html>";
    }
	
	/*
		Post the transaction form for processing by the Arrow API
	*/
	public static function Step1($amount, $order, $api_key, $mid, $relay_response_url, $testmode = true, $test_customer_id = '96722', $test_payment_method_id = '46356')
	{		
		// Handle gateway negotiation
		if($testmode == true){
			$gateway_url = 'http://demo.arrowpayments.com/api/transaction/start';
			$ssl = 0;
		}else{
			$gateway_url = 'https://gateway.arrowpayments.com/api/transaction/start';
			//Temporarily changed to 0 from 2 so we're not validating the SSL on Arrow's Gateway
			$ssl = 0;
		}		
		// Compose request to start transaction (Level 2 Transaction)
		$data = array(
					"ApiKey" => $api_key, 
					"MID" => $mid,
					"ReturnUrl" => $relay_response_url,
					"TransactionType" => 'sale',
					"Amount" => $amount,
					"TaxAmount" => $order->get_total_tax(),
					"ShippingAmount" => $order->get_total_shipping(),
					"CustomerPONumber" => $order->order_key,
					"Description" => $order->get_order_number(),
					"CustomerId" => 0,
					"Customer" => array(
											"Name" => $order->billing_first_name . ' ' . $order->billing_last_name . '-' . date('yMd-Hms'),
											"Code" => date('yMd-Hms') . rand()
										),
					"SaveNewPaymentMethod" => false,
					"BillingAddress" => array(
												"Address1" => $order->billing_address_1,
												"Address2" => $order->billing_address_2,
												"City" => $order->billing_city,
												"State" => $order->billing_state,
												"Postal" => $order->billing_postcode,
												"Phone" => $order->billing_phone,
											)					
					);				
		// Perform REST call to Arrow Payments
		try {
			$result = self::restcall($gateway_url, $data, $ssl);
		}
		catch (Exception $e) {
			echo 'Caught exception: ', $e->getMessage(), '\n';
		}		
		// Return response
		return $result[0];
	}		
	/*
		Prepare and present payment form to customer
	*/
	public static function Step2($step1JSON, $testmode)
    {
		// Carry session data through
		if($testmode == true){
			$prefill = true;
		}else {
			$prefill = false;
		}		
		// Decode JSON response from step 1
		$json = json_decode($step1JSON);
		
		// Arrow Payment POST Url
        $post_url = ( $json->FormPostUrl );	 
		
		$hidden_fields = '';
		
		ob_start();

        woocommerce_get_template( 'cc-form.php', array(
			'prefill' => $prefill
		), 'arrowpayments/', plugin_dir_path( __DIR__ ) . 'templates/' );

		$form = '<form method="post" action="' . $post_url . '"> ' . $hidden_fields;
        $form .= ob_get_clean();
        $form .= '</form>';
        return $form;
    }
	/*
		Complete Transaction and handle WooCommerce Order Updates
	*/
	public static function Step3($api_key, $testmode)
	{
		global $woocommerce;
		// Get the token id from return url from Step2
		$token_id = $_GET['token-id'];

		// Handle gateway negotiation
		if($testmode == true){
			$gateway_url = 'http://demo.arrowpayments.com/api/transaction/complete';
			$ssl = 0;
		}else{
			$gateway_url = 'https://gateway.arrowpayments.com/api/transaction/complete';
			//Temporarily changed to 0 from 2 so we're not validating the SSL on Arrow's Gateway
			$ssl = 0;
		}
		// Compose request to complete transaction
		$data = array(
						"ApiKey" => $api_key,
						"TokenId" => $token_id
					);
		// Perform REST call to Arrow Payments
		try {
			$result = self::restcall($gateway_url, $data, $ssl);
		}
		catch (Exception $e) {
			echo 'Caught exception: ', $e->getMessage(), '\n';
		}			
		if($result[0] == 'Bad Request')
		{
			$errorMsg = self::get_string_between($result[1], "Error: ", " REFID");
		}
		else
		{
			// Decode response
			$json = json_decode($result[0]);
		}	
		// Get the order number - DONE
		$orderNum = str_replace('#','',$json->Description);
		
		// Get the order object - DONE
		$order = new WC_Order( (int) $orderNum);
		
		// Get redirect url - DONE
		$redirect_url = WC_Gateway_Arrow_Payments::get_return_url( $order );
		
		if ( $json->Success == 1 ) {
			if ( $order->key_is_valid( $json->CustomerPONumber )) {

				// Payment complete
				$order->payment_complete();

				// Redirect URL
				$redirect_url = add_query_arg( 'response_code', 1, $redirect_url );
				$redirect_url = add_query_arg( 'transaction_id', $json->ID, $redirect_url );

				// Remove cart
				$woocommerce->cart->empty_cart();
			}
			else 
			{
				// Key did not match order id
				$order->add_order_note( sprintf(__('Payment received, but order ID did not match key: Success: %s - %s.', 'wc-arrowpayments'), $json->Success, $json->Message ) );

				// Put on hold if pending
				if ($order->status == 'pending' || $order->status == 'failed') {
					$order->update_status( 'on-hold' );
				}
			}
		} 
		else 
		{
			// Mark failed
			$order->update_status( 'failed', sprintf(__('Payment failure: Success: %s - %s.', 'wc-arrowpayments'), $json->Success, $json->Message ) );

			$redirect_url = $order->get_checkout_payment_url( true );
			$cleanError = str_replace(array("\r\n", "\r", "\n"), "",preg_replace('/[^a-zA-Z0-9\s]/', '', strip_tags(html_entity_decode($errorMsg))));
			$redirect_url = add_query_arg( 'wc_error', __( 'Error: ', 'wc-arrowpayments' ) . ' '.$cleanError, $redirect_url );

			if ( is_ssl() || get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) 
				$redirect_url = str_replace( 'http:', 'https:', $redirect_url );
		}
		echo self::getRelayResponseSnippet($redirect_url);
	}	
}
