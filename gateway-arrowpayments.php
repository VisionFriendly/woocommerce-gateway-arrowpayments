<?php
/*
Plugin Name: WooCommerce Arrow Payments Gateway
Plugin URI: http://www.visionfriendly.com
Description: Extends WooCommerce with an <a href="https://www.arrowpayments.com" target="_blank">Arrow Payments</a> gateway. An Arrow Payments merchant account is required.
Version: 1.0
Author: Sean Herbert
Author URI: http://www.visionfriendly.com/
Copyright: © 2015 VisionFriendly.com.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
API Docs: http://gateway.arrowpayments.com/apidocumentation?/api
*/
add_action('plugins_loaded', 'woocommerce_arrowpayments_init', 0);
function woocommerce_arrowpayments_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
    /**
     * Localisation
     */
    load_plugin_textdomain('wc-arrowpayments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
	/**
 	 * Gateway class
 	 */
	class WC_Gateway_Arrow_Payments extends WC_Payment_Gateway {
		public function __construct() {
			global $woocommerce;
			$this->id					= 'arrowpayments';
			$this->method_title 		= __('Arrow Payments', 'wc-arrowpayments');
			$this->method_description 	= __('Arrow Payments handles all the steps in the secure transaction while remaining virtually transparent. Payment data is passed from the checkout to Arrow Payments for processing thus simplifying PCI compliance.', 'wc-arrowpayments');
			$this->icon 				= WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/cards.png';
			// Load the form fields
			$this->init_form_fields();
			// Load the settings.
			$this->init_settings();
			// Get setting values
			$this->title           				= $this->settings['title'];
			$this->description     				= $this->settings['description'];
			$this->enabled         				= $this->settings['enabled'];
			$this->api_key         				= $this->settings['api_key'];
			$this->mid 			   				= $this->settings['mid'];
			$this->testmode        				= isset( $this->settings['testmode'] ) && $this->settings['testmode'] == 'no' ? false : true;
			$this->test_customer_id     		= $this->settings['test_customer_id'];
			$this->test_payment_method_id       = $this->settings['test_payment_method_id'];
			// Payment form hook
			add_action( 'woocommerce_receipt_arrowpayments', array( $this, 'receipt_page' ) );
			// Payment listener/API hook
			$this->relay_response_url = $woocommerce->api_request_url( get_class() );
			add_action( 'woocommerce_api_wc_gateway_arrow_payments', array( $this, 'relay_response' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
		/**
	     * Initialise Gateway Settings Form Fields
	     */
	    public function init_form_fields() {
	    	$this->form_fields = array(
				'enabled' => array(
					'title'       => __( 'Enable/Disable', 'wc-arrowpayments' ),
					'label'       => __( 'Enable Arrow Payments', 'wc-arrowpayments' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => __( 'Title', 'wc-arrowpayments' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'wc-arrowpayments' ),
					'default'     => __( 'Credit card', 'wc-arrowpayments' )
				),
				'description' => array(
					'title'       => __( 'Description', 'wc-arrowpayments' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'wc-arrowpayments' ),
					'default'     => 'Pay securely using your credit card.'
				),
				'api_key' => array(
					'title'       => __( 'API Key', 'wc-arrowpayments' ),
					'type'        => 'text',
					'description' => __( 'Look this up by logging into your Arrow Payments account.', 'wc-arrowpayments' ),
					'default'     => '5cbb15c1d87d480f8b82ee1a3abba353'
				),
				'mid' => array(
					'title'       => __( 'Merchant ID', 'wc-arrowpayments' ),
					'type'        => 'text',
					'description' => __( 'Look this up by logging into your Arrow Payments account.', 'wc-arrowpayments' ),
					'default'     => '1231616282'
				),
				'testmode' => array(
					'title'       => __( 'Use Test Environment', 'wc-arrowpayments' ),
					'label'       => __( 'Yes, I am using a the test environment.', 'wc-arrowpayments' ),
					'type'        => 'checkbox',
					'description' => __( 'Enable this if the above keys are for a developer/test account used for testing. If you are using your live account, do not enable this setting.', 'wc-arrowpayments' ),
					'default'     => 'yes'
				),
				'test_customer_id' => array(
					'title'       => __( 'Test Customer ID', 'wc-arrowpayments' ),
					'type'        => 'text',
					'description' => __( '', 'wc-arrowpayments' ),
					'default'     => '96722'
				),				
				'test_payment_method_id' => array(
					'title'       => __( 'Test Payment Method ID', 'wc-arrowpayments' ),
					'type'        => 'text',
					'description' => __( '', 'wc-arrowpayments' ),
					'default'     => '46356'
				),
			);
	    }
		/**
	     * Check if this gateway is enabled
	     */
		public function is_available() {
			if ( ! $this->api_key || ! $this->mid ) 
				return false;

			return parent::is_available();
		}
		/**
		 * Process the payment and return the result - this will redirect the customer to the pay page
		 */
		public function process_payment( $order_id ) {

			$order = new WC_Order( $order_id );

			return array(
				'result' 	=> 'success',
				'redirect'	=> $order->get_checkout_payment_url( true )
			);
		}
		/**
		 * Receipt_page for showing the payment form which sends data to Arrow Payments
		 */
		public function receipt_page( $order_id ) {
			global $woocommerce;

			// Include the SDK
			if ( ! class_exists( 'WC_Arrow_Payments_SDK' ) )
				require_once( dirname(__FILE__) . '/arrowsdk/class-arrowpayments-sdk.php');

			// Get the order
			$order = new WC_Order( $order_id );

			// Get the amount
			$amount = $order->get_total();
			
			// Test Print out $order object
			//echo '<pre>';
			//print_r($order);
			//echo '</pre>';

			echo wpautop(__('Enter your payment details below and click "Confirm and pay" to securely pay for your order.', 'wc-arrowpayments'));
			
			// Set up new WC_Arrow_Payments_SDK object
			$sdk = new WC_Arrow_Payments_SDK();
			
			// Step 1 - Get Form URL from Arrow Payments
			$step1Result = $sdk->Step1($amount, $order, $this->api_key, $this->mid, $this->relay_response_url, $this->testmode, $this->test_customer_id, $this->test_payment_method_id);

			// Step 2 - Show the payment form
			if($step1Result != 'Bad Request'){
				$step2Result = $sdk->Step2($step1Result, $this->testmode);
				echo $step2Result;
			}
			else
			{
				$error = new WP_Error( 'arrowpayments_error', __( 'Sorry, there was a problem communicating with the gateway.  Please try again.', 'wc-arrowpayments' ) );
				echo $error->get_error_message();
			}
		}
		/**
		 * Relay response - handles return data from Arrow Payments and does redirects
		 */
		public function relay_response() {
			global $woocommerce;
			
			// Clean
			@ob_clean();
			
			// Header
			header( 'HTTP/1.1 200 OK' );
			
			// Include the SDK
			if ( ! class_exists( 'WC_Arrow_Payments_SDK' ) )
				require_once( dirname(__FILE__) . '/arrowsdk/class-arrowpayments-sdk.php');	
				
			// Set up new WC_Arrow_Payments_SDK object
			$sdk = new WC_Arrow_Payments_SDK();
			
			// Process Response
			echo $sdk->Step3($this->api_key, $this->testmode);
			
			exit;
		}
	} // end WC_Gateway_Arrow_Payments
	/**
 	* Add the Gateway to WooCommerce
 	**/
	function woocommerce_add_arrowpayments_gateway($methods) {
		$methods[] = 'WC_Gateway_Arrow_Payments';
		return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_arrowpayments_gateway' );
}