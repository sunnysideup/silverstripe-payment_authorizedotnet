<?php

/**
 *@author nicolaas[at]sunnysideup.co.nz
 * visit https://developer.authorize.net/ and sign up for account to start testing
 * see: https://developer.authorize.net/guides/DPM/wwhelp/wwhimpl/js/html/wwhelp.htm
 *
 **/

class AuthorizeDotNetPayment extends Payment {

	/**
	 * Standard SS variable
	 * @var Array
	 **/
	static $db = array(
		'ValuesSubmitted' => 'Text'
	);


	/**
	 * must be set - check for live vs test values
	 * @var String
	 **/
	protected static $api_login_id = 'YOUR_API_LOGIN_ID';
		static function set_api_login_id($s) {self::$api_login_id = $s;}

	/**
	 * must be set - check for live vs test values
	 * @var String
	 **/
	protected static $transaction_key = 'YOUR_API_LOGIN_ID';
		static function set_transaction_key($s) {self::$transaction_key = $s;}

	/**
	 * not used right now
	 * @var String
	 **/
	protected static $md5_setting = 'bla';
		static function set_md5_setting($s) {self::$md5_setting = $s;}

	/**
	 * we are not using any special variables here
	 * @var String
	 **/
	protected static $show_form_type = 'PAYMENT_FORM';
		static function set_show_form_type($s) {self::$show_form_type = $s;}

	/**
	 * Test URL that form is submitted to
	 * @var String
	 **/
	protected static $test_url = 'https://test.authorize.net/gateway/transact.dll';
		static function set_test_url($s) {self::$test_url = $s;}

	/**
	 * Test URL that form is submitted to
	 * @var String
	 **/
	protected static $live_url = 'https://secure.authorize.net/gateway/transact.dll';
		static function set_live_url($s) {self::$live_url = $s;}

	/**
	 * Not used right now
	 * @var String
	 **/
	protected $currency = "";
		function setCurrency($s) {$this->currency = $s;}

	protected static $privacy_link = '';

	protected static $logo = '';

	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->replaceField("DebugMessage", new ReadonlyField("DebugMessage", "Debug info"));
		return $fields;
	}

	function getPaymentFormFields() {
		$logo = '<img src="' . self::$logo . '" alt="Credit card payments powered by AuthorizeDotNet"/>';
		$privacyLink = '<a href="' . self::$privacy_link . '" target="_blank" title="Read AuthorizeDotNet\'s privacy policy">' . $logo . '</a><br/>';
		$fields = new FieldSet(
			new LiteralField('AuthorizeDotNetInfo', $privacyLink)
		);
		return $fields;
	}

	function getPaymentFormRequirements() {
		return array();
	}

	function processPayment($data, $form) {
		$amount = 0;
		$member = null;
		$billingAddress = null;
		$shippingAddress = null;
		$order = $this->Order();
		if($order) {
			$billingAddress = $order->BillingAddress();
			$shippingAddress = $order->ShippingAddress();
			$orderID = $order->ID;
			$amount = $order->TotalOutstanding();
			if($member = $order->Member()) {
				$email = $member->Email;
			}
		}
		else {
			$order = new DataObject();
			$billingAddress = new DataObject();
			$shippingAddress = new DataObject();
			$order->ID = time();
			$amount = floatval($data["Amount"]);
			if($member = Member::CurrentMember()) {
				$member->Email;
			}
			else {
				$member = new DataObject();
				if(isset($data["Email"])) {$member->Email = $data["Email"];}
			}
		}
		$timeStamp = time();
		$fingerprint = hash_hmac("md5", self::$api_login_id  . "^" . $order->ID . "^" . $timeStamp . "^" . $amount . "^", self::$transaction_key);
		$dataObject = new DataObject();
		$dataObject->url = $this->isLiveMode() ? self::$live_url : self::$test_url;
		$dataObject->fingerprint = $fingerprint;
		$dataObject->login = self::$api_login_id;;
		$dataObject->amount = $amount;
		$dataObject->fp_sequence = $order->ID;
		$dataObject->fp_timeStamp = $timeStamp;
		$dataObject->fp_hash = $fingerprint;
		$dataObject->test_request = ($this->isLiveMode() ? "false" : "true");
		$dataObject->show_form = self::$show_form_type;
		$dataObject->recurring_billing = "false";
		$dataObject->invoice_num = $order->ID;
		$dataObject->description =  $order->Title();
		$dataObject->first_name = $billingAddress->FirstName;
		$dataObject->last_name = $billingAddress->Surname;
		$dataObject->company = "";
		$dataObject->address = $billingAddress->Address." ".$billingAddress->Address2;
		$dataObject->city = $billingAddress->City;
		$dataObject->state = $billingAddress->Region;
		$dataObject->zip = $billingAddress->PostalCode;
		$dataObject->country = $billingAddress->Country;
		$dataObject->phone = $billingAddress->Phone;
		$dataObject->fax = "";
		$dataObject->email = $member->Email;
		$dataObject->cust_id = $member->ID;
		$dataObject->ship_to_first_name = $shippingAddress->ShippingFirstName;
		$dataObject->ship_to_last_name = $shippingAddress->ShippingSurname;
		$dataObject->ship_to_company = "";
		$dataObject->ship_to_address = $shippingAddress->ShippingAddress." ".$shippingAddress->ShippingAddress2;
		$dataObject->ship_to_city = $shippingAddress->ShippingCity;
		$dataObject->ship_to_state = $shippingAddress->ShippingRegion();
		$dataObject->ship_to_zip = $shippingAddress->ShippingPostalCode;
		$dataObject->ship_to_country = $shippingAddress->ShippingCountry;
		$dataObject->label = _t("AuthorizeDotNet.PAYNOW", "Pay now");
		return $this->executeURL($dataObject);
	}

	protected function executeURL($dataObject) {
		Requirements::clear();
		Requirements::javascript(THIRDPARTY_DIR."/jquery/jquery.js");
		$page = new Page();
		if($dataObject->fingerprint) {
			$page->Title = 'Redirection to Authorize.Net...';
			$page->Logo = '<img src="' . self::$logo . '" alt="Payments powered by AuthorizeDotNet"/>';
			$page->Form = $this->AuthorizeDotNetForm($dataObject);
			$controller = new ContentController($page);
			//Requirements::block(THIRDPARTY_DIR."/jquery/jquery.js");
			//Requirements::javascript(Director::protocol()."ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js");
			return new Payment_Processing($controller->renderWith('PaymentProcessingPage'));
		}
		else {
			$page->Title = 'Sorry, AuthorizeDotNet can not be contacted at the moment ...';
			$page->Logo = '';
			$page->Form = 'Sorry, an error has occured in contacting the Payment Processing Provider, please try again in a few minutes...';
			$controller = new ContentController($page);
			//Requirements::block(THIRDPARTY_DIR."/jquery/jquery.js");
			//Requirements::javascript(Director::protocol()."ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js");
			return new Payment_Failure($controller->renderWith('PaymentProcessingPage'));
		}
	}

	protected function AuthorizeDotNetForm($dataObject) {
		return <<<HTML
			<form id="PaymentFormAuthorizeDotNet" method="post" action="$dataObject->url">
				<input type='hidden' name='x_login' value='$dataObject->login' />
				<input type='hidden' name='x_amount' value='$dataObject->amount' />
				<input type='hidden' name='x_description' value='$dataObject->description' />
				<input type='hidden' name='x_invoice_num' value='$dataObject->invoice_num' />
				<input type='hidden' name='x_fp_sequence' value='$dataObject->fp_sequence' />
				<input type='hidden' name='x_fp_timestamp' value='$dataObject->fp_timeStamp' />
				<input type='hidden' name='x_fp_hash' value='$dataObject->fp_hash' />
				<input type='hidden' name='x_test_request' value='$dataObject->test_request' />
				<input type='hidden' name='x_show_form' value='PAYMENT_FORM' />
				<input type='hidden' name='x_recurring_billing' value='$dataObject->recurring_billing' />
				<input type='hidden' name='x_first_name' value='$dataObject->first_name' />
				<input type='hidden' name='x_last_name' value='$dataObject->last_name' />
				<input type='hidden' name='x_company' value='$dataObject->company' />
				<input type='hidden' name='x_address' value='$dataObject->address' />
				<input type='hidden' name='x_city' value='$dataObject->city' />
				<input type='hidden' name='x_state' value='$dataObject->state' />
				<input type='hidden' name='x_zip' value='$dataObject->zip' />
				<input type='hidden' name='x_country' value='$dataObject->country' />
				<input type='hidden' name='x_phone' value='$dataObject->phone' />
				<input type='hidden' name='x_fax' value='$dataObject->fax' />
				<input type='hidden' name='x_email' value='$dataObject->email' />
				<input type='hidden' name='x_cust_id' value='$dataObject->cust_id' />
				<input type='hidden' name='x_ship_to_first_name' value='$dataObject->ship_to_first_name' />
				<input type='hidden' name='x_ship_to_last_name' value='$dataObject->ship_to_last_name' />
				<input type='hidden' name='x_ship_to_company' value='$dataObject->ship_to_company' />
				<input type='hidden' name='x_ship_to_address' value='$dataObject->ship_to_address' />
				<input type='hidden' name='x_ship_to_city' value='$dataObject->ship_to_city' />
				<input type='hidden' name='x_ship_to_state' value='$dataObject->ship_to_state' />
				<input type='hidden' name='x_ship_to_zip' value='$dataObject->ship_to_zip' />
				<input type='hidden' name='x_ship_to_country' value='$dataObject->ship_to_country' />
				<input type='submit' value='$dataObject->label' />
			</form>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					if(!jQuery.browser.msie) {
						//jQuery("#PaymentFormAuthorizeDotNet").submit();
					}
				});
			</script>
HTML;
	}

	protected function isLiveMode(){
		return Director::isLive();
	}

}

class AuthorizeDotNetPxPayPayment_Handler extends Controller {

	protected static $url_segment = 'authorizedotnetpxpaypayment';
		static function set_url_segment($v) { self::$url_segment = $v;}
		static function get_url_segment() { return self::$url_segment;}

	static function complete_link() {
		return self::$url_segment . '/paid/';
	}

	static function absolute_complete_link() {
		return Director::AbsoluteURL(self::complete_link());
	}

	function paid() {
		$commsObject = new AuthorizeDotNetPxPayComs();
		$response = $commsObject->processRequestAndReturnResultsAsObject();
		if($payment = DataObject::get_by_id('AuthorizeDotNetPxPayPayment', $response->getMerchantReference())) {
			if(1 == $response->getSuccess()) {
				$payment->Status = 'Success';
			}
			else {
				$payment->Status = 'Failure';
			}
			if($AuthorizeDotNetTxnRef = $response->getAuthorizeDotNetTxnRef()) $payment->TxnRef = $AuthorizeDotNetTxnRef;
			if($ResponseText = $response->getResponseText()) $payment->Message = $ResponseText;
			$payment->write();
			$payment->redirectToOrder();
		}
		else {
			USER_ERROR("could not find payment with matching ID", E_USER_WARNING);
		}
		return;
	}


}
