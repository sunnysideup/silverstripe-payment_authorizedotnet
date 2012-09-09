<?php

//*** must have!
Director::addRules(50, array(
	AuthorizeDotNetPxPayPayment_Handler::get_url_segment() . '/$Action/$ID' => 'AuthorizeDotNetPxPayPayment_Handler'
));


//===================---------------- START payment_authorizedotnet MODULE ----------------===================
//AuthorizeDotNetPayment::set_api_login_id("");
//AuthorizeDotNetPayment::set_transaction_key("");
//===================---------------- END payment_authorizedotnet MODULE ----------------===================
