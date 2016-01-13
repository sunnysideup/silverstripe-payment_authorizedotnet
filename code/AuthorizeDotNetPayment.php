<?php

/**
 * This class provides an e-commerce payment gateway to the Authorize.net DPM method
 * (note that there also seems to be SIM and AIM)
 *
 * @author nicolaas[at]sunnysideup.co.nz
 * visit https://developer.authorize.net/ and sign up for account to start testing
 * @see:
 * https://developer.authorize.net/guides/DPM/wwhelp/wwhimpl/js/html/wwhelp.htm
 * https://developer.authorize.net/tools/responsecode99/
 * http://www.authorize.net/support/merchant/wwhelp/wwhimpl/js/html/wwhelp.htm
 * https://developer.authorize.net/tools/responsecode97/
 * https://developer.authorize.net/tools/
 *
 */

class AuthorizeDotNetPayment extends EcommercePayment {

	/**
	 * Standard SS variable
	 * @var Array
	 **/
	private static $db = array(
		'ValuesSubmitted' => 'Text',
		'Hash' => 'Varchar(255)',
		'ValuesReceived' => 'Text'
	);

	/**
	 * must be set - check for live vs test values
	 * @var String
	 **/
	private static $api_login_id = 'YOUR_API_LOGIN_ID';

	/**
	 * must be set - check for live vs test values
	 * @var String
	 **/
	private static $transaction_key = 'YOUR_TRANSACTION_KEY';

	/**
	 * Not sure if this is needed....
	 * @var String
	 **/
	private static $md5_setting = '';

	/**
	 * we are not using any special variables here
	 * @var String
	 **/
	private static $show_form_type = 'PAYMENT_FORM';

	/**
	 * Test URL that form is submitted to
	 * @var String
	 **/
	private static $debug_url = 'https://developer.authorize.net/tools/paramdump/index.php';

	/**
	 * Test URL that form is submitted to
	 * @var String
	 **/
	private static $test_url = 'https://test.authorize.net/gateway/transact.dll';

	/**
	 * Test URL that form is submitted to
	 * @var String
	 **/
	private static $live_url = 'https://secure.authorize.net/gateway/transact.dll';


	/**
	 * Link to information about privacy
	 * @var String
	 **/
	private static $privacy_link = '';


	/**
	 * Link to Authorize.net logo
	 * @var String
	 **/
	private static $logo_link = '';

	/**
	 *
	 * @param Int $timeStamp
	 * @param String $amount - needs to be formatted at 11.00 (two digits)
	 *
	 * @return String
	 */
	public static function create_fingerprint($myID, $timeStamp, $amount){
		$string = Config::inst()->get("AuthorizeDotNetPayment", "api_login_id") . "^" . $myID . "^" . $timeStamp . "^" . $amount . "^";
		$key = Config::inst()->get("AuthorizeDotNetPayment", "transaction_key");
		$fingerprint = hash_hmac("md5",$string, $key);
		//debug::log($string."---".$key."---".$fingerprint);
		return $fingerprint;
	}

	/**
	 *
	 * @var boolean
	 */
	protected $debug = false;

	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->replaceField("DebugMessage", new ReadonlyField("DebugMessage", "Debug info"));
		return $fields;
	}

	/**
	 * fields for the final step of the checkout out process...
	 * @return FieldList
	 */
	function getPaymentFormFields() {
		$logoLink = $this->Config()->get("logo_link");
		$logo = "";
		if($logoLink) {
			$logo = '<img src="' . $logoLink . '" alt="Credit card payments powered by Authorize.Net"/>';
		}
		$privacyLink = $this->Config()->get("privacy_link");
		$privacy = "";
		if($privacyLink) {
			if($logo) {
				$privacy = '<a href="' . $privacyLink . '" target="_blank" title="Read AuthorizeDotNet\'s privacy policy">' . $logo . '</a>';
			}
			else {
				$privacy = '<a href="' . $privacyLink . '" target="_blank" title="Read AuthorizeDotNet\'s privacy policy">Powered by Authorize . net</a>';
			}
		}
		$fields = new FieldList(
			new LiteralField('AuthorizeDotNetInfo', $privacy)
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
			$amount = number_format($this->getAmountValue(), 2, '.', '');
			$currency = $this->getAmountCurrency();
			if($member = $order->Member()) {
				$email = $member->Email;
			}
		}
		$this->write();
		$timeStamp = time();
		$fingerprint = self::create_fingerprint($this->ID, $timeStamp, $amount);
		$this->Hash = $fingerprint;
		$this->write();
		//start creating object and end with
		$obj = new stdClass();
		$obj->fields = array();
		$obj->label = _t("AuthorizeDotNet.PAYNOW", "Pay now");
		$obj->fingerprint = $fingerprint;
		//IMPORTANT!
		$obj->fields["x_invoice_num"] = $this->ID;
		//all the other stuff...
		$obj->fields["x_login"] = $this->Config()->get("api_login_id");
		$obj->fields["x_amount"] = $amount;
		//$obj->fields["x_currency_code"] = $currency;
		$obj->fields["x_fp_sequence"] = $this->ID;
		$obj->fields["x_fp_timestamp"] = $timeStamp;
		$obj->fields["x_fp_hash"] = $fingerprint;
		$obj->fields["x_test_request"] = ($this->isLiveMode() ? "false" : "true");
		$obj->fields["x_show_form"] = $this->Config()->get("show_form_type");
		$obj->fields["x_recurring_billing"] = "false";
		$obj->fields["x_description"] =  $order->Title();
		$obj->fields["x_first_name"] = $billingAddress->FirstName;
		$obj->fields["x_last_name"] = $billingAddress->Surname;
		$obj->fields["x_company"] = "";
		$obj->fields["x_address"] = $billingAddress->Address." ".$billingAddress->Address2;
		$obj->fields["x_city"] = $billingAddress->City;
		$region =  EcommerceRegion::get()->byID($billingAddress->RegionID);
		if($region) {
			$obj->fields["x_state"] = $region->Code;
		}
		$obj->fields["x_zip"] = $billingAddress->PostalCode;
		$obj->fields["x_country"] = $billingAddress->Country;
		$obj->fields["x_phone"] = $billingAddress->Phone;
		$obj->fields["x_fax"] = "";
		$obj->fields["x_email"] = $member->Email;
		$obj->fields["x_cust_id"] = $member->ID;
		$obj->fields["x_ship_to_first_name"] = $shippingAddress->ShippingFirstName;
		$obj->fields["x_ship_to_last_name"] = $shippingAddress->ShippingSurname;
		$obj->fields["x_ship_to_company"] = "";
		$obj->fields["x_ship_to_address"] = $shippingAddress->ShippingAddress." ".$shippingAddress->ShippingAddress2;
		$obj->fields["x_ship_to_city"] = $shippingAddress->ShippingCity;
		$region =  EcommerceRegion::get()->byID($shippingAddress->ShippingRegionID);
		if($region) {
			$obj->fields["x_ship_to_state"] = $region->Code;
		}
		$obj->fields["x_ship_to_zip"] = $shippingAddress->ShippingPostalCode;
		$obj->fields["x_ship_to_country"] = $shippingAddress->ShippingCountry;
		$obj->fields["x_receipt_link_method"] = "POST";
		$obj->fields["x_receipt_link_text"] = _t("AuthorizeDotNet.FINALISE", "Finalise now");
		$obj->fields["x_receipt_link_url"] = AuthorizeDotNetPxPayPayment_Handler::complete_link($timeStamp, true);

		$this->ValuesSubmitted = serialize($obj);
		$this->write();
		return $this->executeURL($obj);
	}


	/**
	 * executes payment: redirects to Authorize.net
	 *
	 * @param Object $obj
	 *
	 */
	protected function executeURL($obj) {
		Requirements::clear();
		Requirements::javascript(THIRDPARTY_DIR."/jquery/jquery.js");
		$page = new Page();
		if($obj->fingerprint) {
			$page->Title = 'Redirection to Authorize.Net...';
			$logoLink = $this->Config()->get("logo_link");
			$page->Logo = "";
			if($logoLink) {
				$page->Logo = '<img src="' . $logoLink . '" alt="Payments powered by Authorize.Net" />';
			}
			$page->Form = $this->AuthorizeDotNetForm($obj);
			$controller = new ContentController($page);
			//Requirements::block(THIRDPARTY_DIR."/jquery/jquery.js");
			//Requirements::javascript(Director::protocol()."ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js");
			return EcommercePayment_Processing::create($controller->renderWith('PaymentProcessingPage'));
		}
		else {
			$page->Title = 'Sorry, Authorize.Net can not be contacted at the moment ...';
			$page->Logo = '';
			$page->Form = 'Sorry, an error has occurred in contacting the Payment Processing Provider (Authorize.Net), please try again in a few minutes or contact the website provider...';
			$controller = new ContentController($page);
			//Requirements::block(THIRDPARTY_DIR."/jquery/jquery.js");
			//Requirements::javascript(Director::protocol()."ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js");
			return new Payment_Failure($controller->renderWith('PaymentProcessingPage'));
		}
	}

	/**
	 * turns an object into HTML.
	 *
	 * @param Object $obj
	 *
	 * @return String (html)
	 */
	protected function AuthorizeDotNetForm($obj) {
		$obj->ActionURL = $this->isLiveMode() ? self::$live_url : self::$test_url;
		if($this->debug) {
			$obj->ActionURL = self::$debug_url;
		}
		$html = '
			<form id="PaymentFormAuthorizeDotNet" method="post" action="'.$obj->ActionURL.'">';
		foreach($obj->fields as $field => $value) {
			$html .= '
				<input type="hidden" name="'.$field.'" value="'.Convert::raw2att($value).'" />';
		}
		if($this->debug) {
			$obj->fields["transaction_key"] = $this->Config()->get("transaction_key");
			foreach($obj->fields as $field => $value) {
				$html .= '
					'.$field.' = '.$value.'<br />';
			}
		}
		$html .='
				<input type="submit" value="'.$obj->label.'" />
			</form>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					if(!jQuery.browser.msie) {
						//jQuery("#PaymentFormAuthorizeDotNet").submit();
					}
				});
			</script>';
		return $html;
	}

	protected function isLiveMode(){
		return Director::isLive();
	}

}

class AuthorizeDotNetPxPayPayment_Handler extends Controller {

	private static $response_approved = 1;
	private static $response_declined = 2;
	private static $response_error = 3;
	private static $response_held = 4;

	private static $allowed_actions = array(
		"paid"
	);

	/**
	 * make sure that this value is the same as the one set in the route
	 * yml config file!
	 *
	 * @var String
	 *
	 */
	private static $url_segment = 'authorizedotnetpxpaypayment';

	/**
	 * returns relative or absolute link to payment handler
	 * @param Boolean $absolute
	 * @return String
	 */
	public static function complete_link($timeStamp, $absolute = false) {
		$link = Config::inst()->get("AuthorizeDotNetPxPayPayment_Handler", "url_segment") . '/paid/'.$timeStamp."/";
		if($absolute) {
			$link = Director::AbsoluteURL($link);
		}
		return $link;
	}

	/**
	 * confirm payment...
	 * @param HTTP_Request (SS_HTTPRequest)
	 */
	public function paid($request) {
		$timeStamp = $request->param("ID");
		if($timeStamp) {
			//find payment
			$payment = AuthorizeDotNetPayment::get()->byID(intval($_POST["x_invoice_num"]));
			if($payment){
				$payment->ValuesReceived = Convert::raw2sql(serialize($_POST));
				//already completed?
				if($payment->Status != "Incomplete") {
					return $payment->redirectToOrder();
				}
				//get amount ...
				if(isset($_REQUEST["x_amount"])) {
					$amount = $_REQUEST["x_amount"];
				}
				else {
					$amount = "0.00";
				}
				//check if it is authorize.net response
				$checkHash = AuthorizeDotNetPayment::create_fingerprint($payment->ID, trim($timeStamp), $amount);
				if($payment->Hash == $checkHash) {
					$payment->Hash = "";
					//now we know it is legit, lets see the response...
					$responseCode = $_POST["x_response_code"];
					if($responseCode == self::$response_approved) {
						$payment->Status = 'Success';
					}
					elseif($responseCode == self::$response_held) {
						$payment->Status = 'Pending';
					}
					else {
						$payment->Status = 'Failure';
					}
				}
				else {
					$payment->Status = 'Failure';
				}
				$payment->write();
				return $payment->redirectToOrder();
			}
			else {
				USER_ERROR("PAYMENT COULD NOT BE FOUND ", E_USER_WARNING);
			}
		}
		else {
			USER_ERROR("URL IS MISSING TIMESTAMP OF TRANSACTION", E_USER_WARNING);
		}
	}


}
