<?php

use PayplusGateway\PayplusApi\ChargeMethods;
use PayplusGateway\PayplusApi\PaymentPage;
use PayplusGateway\PayplusApi\PayplusBase;
use PayplusGateway\PayplusApi\RefundByTransactionUID;
use PayplusGateway\PayplusApi\TokenPay;
use PayplusGateway\PayplusApi\Tokens\Remove;
use PayplusGateway\PayplusApi\Tokens\Update;

class PayplusInstance
{
    public static $DisplayName;
    public static $GatewayName;
    public static $GatewayNameMeta;
    public static function MetaData() {
        return array(
            'DisplayName' => self::$GatewayNameMeta,
            'APIVersion' => '1.1'
        );
    }
    
    public static function Config()
    {
        return [
            'version' => [
                'FriendlyName' => 'Module version',
                'Description' => PAYLUS_GATEWAY_MODULE_VERSION,
                'Value' => PAYLUS_GATEWAY_MODULE_VERSION,
            ],
            'FriendlyName' => array(
                'Type' => 'System',
                'Value' => self::$DisplayName,
            ),
            'devMode' => array(
                'FriendlyName' => 'Dev Mode',
                'Type' => 'yesno',
                'Description' => 'Tick to enable dev mode',
            ),
            'enable_payments' => array(
                'FriendlyName' => 'Enable payments',
                'Type' => 'yesno',
                'Description' => 'Tick to enable payments',
            ),
            'apiKey' => array(
                'FriendlyName' => 'API Key',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Enter API Key here',
            ),
            'secretKey' => array(
                'FriendlyName' => 'Secret Key',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Enter secret key here',
            ),
            'paymentPageUID' => array(
                'FriendlyName' => 'Payment page UID',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Enter payment page UID here',
            ),
            'terminalUID' => array(
                'FriendlyName' => 'Terminal UID',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Enter terminal UID here',
            ),
            'vat_id_field_name' => array(
                'FriendlyName' => 'Vat ID field name',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Enter custom field name for vat ID if applicable',
            ),
            'devurl' => array(
                'FriendlyName' => 'Dev API address',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'API address (only applicable for dev mode. !!Leave blank!!)',
            ),
            'move_token' => array(
                'FriendlyName' => 'Move token',
                'Type' => 'yesno',
                'Description' => 'Tick to send the move_token parameter with transactions',
            )
        ];
    }

    public static function Capture($params){
        global $_LANG;
        $translations = self::getTranslation(substr($_LANG['locale'],0,2));
        PayplusBase::$apiKey = $params['apiKey'];
        PayplusBase::$secretKey = $params['secretKey'];
        PayplusBase::$devMode = ($params['devMode'] == 'on');
        if ($params['devMode'] === 'on' && $params['devurl']) {
            PayplusBase::$DEV_ADDRESS = $params['devurl'];
        }
        $params['gatewayid'] = explode(TOKEN_TERMINAL_SEPARATOR, $params['gatewayid']);
        $params['gatewayid'] = $params['gatewayid'][0];
        $paymentPage = new TokenPay;
        $paymentPage->Init([
            'payment_page_uid' =>  $params['paymentPageUID'],
            'currency_code' => $params['currency'],
            'amount' => $params['amount'],
            'token' => $params['gatewayid']
        ]);
    
        $numPayments = $_REQUEST['payments'] ?? null;
        if (ADMINAREA && $numPayments && $numPayments > 1) {
            $paymentPage->payments = $numPayments;
        }
        $total = 0;
        $taxCalculator = $params['cart']->getTaxCalculator($params['cart']->client);
        foreach($params['cart']->getInvoiceModel()->lineItems as $item) {
            $itemLine = [
                'price' => $item->amount,
                'name' => $item->description,
                'quantity' => 1
            ];
            
            if (
                WHMCS\Config\Setting::getValue("TaxEnabled") 
                && $item->taxed
                && !$params['cart']->client->taxExempt
                ) {
                $itemLine['price'] = $taxCalculator->setTaxBase($item->amount)->getTotalAfterTaxes();
            }
    
            $paymentPage->AddItem($itemLine);
            $total+=$itemLine['price'];
        }
    
        $credit = (float)$params['cart']->getInvoiceModel()->getAttribute('credit');
        if ($credit > 0) {
            $itemLine = [
                'price' => $credit * -1,
                'name' => 'Credit',
                'quantity' => 1
            ];
            $paymentPage->AddItem($itemLine);
            $total-=$credit;
        }
        
        $paramsAmount =  $params['amount'] * 100;
        $totalC =   $total * 100;
        $diff = $paramsAmount - $totalC;
        if (abs($diff) == 1) {
            $paymentPage->AddItem([
                'price'=>$diff / 100,
                'quantity'=>1,
                'name'=> $translations['rounding-difference']
            ]);
        }
    
        $customer = [
            'customer_name' => ($params['clientdetails']['companyname']) ? $params['clientdetails']['companyname']:$params['clientdetails']['fullname'],
            'email' => $params['clientdetails']['email'],
            'phone' => $params['clientdetails']['phonenumber'],
            'country' => $params['clientdetails']['countrycode'],
            'city' => $params['clientdetails']['city'],
            'address' => $params['clientdetails']['address1'],
        ];
        if ($params['vat_id_field_name']) {
            $customer['vat_number'] = $params['clientdetails'][$params['vat_id_field_name']];
        }
        $paymentPage->SetCustomer($customer);
        if ($params['move_token'] === 'on') {
            $paymentPage->move_token = true;
        }
        $paymentPage->more_info = $params['invoiceid'];
        $paymentPage->charge_method = ChargeMethods::CHARGE;
        $paymentPage->Go();
        if ($paymentPage->IsSuccess()) {
            return [
                'status' => 'success',
                'transid' => $paymentPage->Response->result->number
            ];
        }
        logModuleCall('payplus', CURRENT_DEBUG_ACTION, [
            'error'=>$paymentPage->GetErrors(),
            'payload'=>$paymentPage->GetPayload()        
        ], 'Req user ID...');
        $errors = $paymentPage->GetErrors();
        $html = '';
        if (in_array('vat-id-not-valid',$errors)) {
            $html .= '<div>'.$translations['invalid_tax_id'].'</div>';
        }

        WHMCS\Session::set("credit-card-error", $html);
        return [
            'status' => 'declined',
            'rawdata'=>$paymentPage->GetErrors(),
            'declinereason'=>$paymentPage->GetErrors()
        ];
    }

    public static function RemoteInput($params){
        global $_LANG;
        $translations = self::getTranslation(substr($_LANG['locale'],0,2));
        PayplusBase::$apiKey = $params['apiKey'];
        PayplusBase::$secretKey = $params['secretKey'];
        PayplusBase::$devMode = ($params['devMode'] == 'on');
        if ($params['devMode'] === 'on' && $params['devurl']) {
            PayplusBase::$DEV_ADDRESS = $params['devurl'];
        }
        $clientDetails = $params['clientdetails'];
        $paymentPage = new PaymentPage;
        $currencyCode = 'ILS';
        if ($params['clientdetails']['model'] && method_exists($params['clientdetails']['model'],'getCurrencyCodeAttribute')) {
            $currencyCode = $params['clientdetails']['model']->getCurrencyCodeAttribute();
        }
        $paymentPage->Init([
            'payment_page_uid' =>  $params['paymentPageUID'],
            'currency_code' => $currencyCode,
            'amount' => 0
        ]);
        $customer = [
            'customer_name' => ($params['clientdetails']['companyname']) ? $params['clientdetails']['companyname']:$params['clientdetails']['fullname'],
            'email' => $params['clientdetails']['email'],
            'phone' => $params['clientdetails']['phonenumber'],
            'country_iso' => $params['clientdetails']['countrycode'],
            'city' => $params['clientdetails']['city'],
            'address' => $params['clientdetails']['address1'],
        ];
        if ($params['vat_id_field_name']) {
            $customer['vat_number'] = $params['clientdetails'][$params['vat_id_field_name']];
        }
        $paymentPage->SetCustomer($customer);
        $userID = openssl_encrypt($clientDetails['userid'], ENCRYPTION_ALGORITHM, PASSPHRASE);
        $paymentPage->more_info = base64_encode($userID);
        $get = [];
        $paymentPage->refURL_success = $params['systemurl'] . 'modules/gateways/payplus/return.php';
        $get['g'] = md5(self::$GatewayName);
        if ($params['invoiceid']) {
            $get['invoiceid'] = $params['invoiceid'];
        }
        if (ADMINAREA === true) {
            $get['adminarea'] = 1;
        }
        if (!empty($get)) {
            $paymentPage->refURL_success .= '?' . http_build_query($get);
        }
        $paymentPage->charge_method = ChargeMethods::TOKEN;
        $paymentPage->create_token = true;
    
        if ($paymentPage->Go()->IsSuccess()) {
            return '
            <script>
                var noAutoSubmit = true;
                jQuery(()=>{
                    jQuery("[name=ccframe]").attr("src","' . $paymentPage->payment_page_link . '")
                })
            </script>';
        } else {
            $errors = $paymentPage->GetErrors();
            $html = '<div style="color:red;">'. $translations['Operation encountered the following error/s'];
            $html .= '<ul>';
            if (in_array('vat-id-not-valid',$errors)) {
                $html .= '<li>'.$translations['invalid_tax_id'].'</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';

            return $html;
        }
    }
    public static function Refund($params){
        PayplusBase::$apiKey = $params['apiKey'];
        PayplusBase::$secretKey = $params['secretKey'];
        PayplusBase::$devMode = ($params['devMode'] == 'on');
    
        $transactionIdToRefund = $params['transid'];
        $refundAmount = $params['amount'];
        $refund = new RefundByTransactionUID;
        $refund->Init([
            'transaction_uid' => $transactionIdToRefund,
            'amount' => $refundAmount,
    
        ]);
        $result  = [];
        if ($refund->Go()->IsSuccess()) {
            $result['status'] = 'success';
            $result['rawdata'] = '';
            $result['transid'] = $refund->details->uid;
        } else {
            $result['status'] = 'error';
            $result['rawdata'] = '';
            $result['transid'] = 0;
        }
        return $result;
    }
    public static function RemoteStore($params){
        PayplusBase::$apiKey = $params['apiKey'];
        PayplusBase::$secretKey = $params['secretKey'];
        PayplusBase::$devMode = ($params['devMode'] == 'on');
    
        switch ($params['action']) {
            case REMOTE_STORE_ACTION_DELETE:
                $removeToken = new Remove;
                $removeToken->Init([
                    'uid' => $params['remoteStorageToken']
                ]);
                $removeToken->Go();
                return [
                    'status' => 'success'
                ];
                break;
    
            case REMOTE_STORE_ACTION_UPDATE:
                $tokenData = explode(TOKEN_TERMINAL_SEPARATOR, $params['gatewayid']);
                $tokenUID = $tokenData[0];
                $updateToken = new Update;
                $payment = $params['payMethod']->payment;
    
                $updateToken->Init([
                    'uid' => $tokenUID,
                    'terminal_uid' => $params['terminalUID'],
                    'credit_card_number' => $params['cardlastfour'],
                    'card_date_mmyy' => $params['cardexp'],
                ]);
                $exp = \WHMCS\Carbon::createFromDate($params['cardExpiryYear'], $params['cardExpiryMonth'], 1);
                if ($updateToken->Go()->IsSuccess()) {
                    $payment->setExpiryDate($exp);
                    $payment->save();
                    return [
                        'status' => 'success',
                        'gatewayid'=>$tokenUID
                    ];
                }
                break;
        }
    
        return [
            'status' => 'failed'
        ];
    }

    public static function getTranslation($lang) {
        $translations = [];
        $translations['coupon-discount'] = 'Coupon discount';
        $translations['rounding-difference'] = 'Rounding difference';
        $translations['Operation encountered the following error/s'] = 'Operation encountered the following error/s';
        $translations['invalid_tax_id'] = 'Invalid vat number/ID';
        if ($lang == 'he') {
            $translations['coupon-discount'] = 'הנחת קופון';
            $translations['rounding-difference'] = 'הפרש עיגול';
            $translations['Operation encountered the following error/s'] = 'שגיאה';
            $translations['invalid_tax_id'] = 'ת"ז/ח"פ שגויים';
        }
        return $translations;
    }
}
