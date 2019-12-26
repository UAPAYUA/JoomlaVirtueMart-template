<?php

defined('_JEXEC') or die('Restricted access');

/**
 * @author UAPAY
 * @version v 1.0
 * @link: https://uapay.com
 */
if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentUapay extends vmPSPlugin
{
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     */
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment UaPay Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return string SQL Fileds
     */
    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'virtuemart_order_number' => 'char(64) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'int(1) UNSIGNED',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',

            'virtuemart_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'uapay_invoice_id' => 'char(64) DEFAULT NULL',
            'uapay_payment_id' => 'char(64) DEFAULT NULL',
            'uapay_method_payment' => 'char(64) DEFAULT NULL',
        );
        return $SQLfields;
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        require_once(__DIR__ . '/UaPayApi.php');
        UaPayApi::writeLog('ConfirmedOrder', '');

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
        $currency_id = $method->currency_id ? $method->currency_id : $method->payment_currency;
        $currency_code_3 = shopFunctions::getCurrencyByID($currency_id, 'currency_code_3');
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $currency_id);
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['payment_currency'] = $currency_code_3;
        $dbValues['virtuemart_order_id'] = $this->_getOrderId($order);
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];

        UaPayApi::writeLog(array($this->_getOrderId($order)), '_getOrderId');
        UaPayApi::writeLog(array($this->_getOrderNumber($order)), '_getOrderNumber');
        UaPayApi::writeLog(array($totalInPaymentCurrency['value']), 'total');

        UaPayApi::writeLog(array($order['details']['BT']), 'details-BT');

        $uapay = new UapayApi($method->CLIENT_ID, $method->SECRET_KEY, $method->TEST_MODE, $method->TYPE_PAYMENT, $method->REDIRECT_URL);
        $uapay->testMode();
        $uapay->setDataCallbackUrl(JROUTE::_(JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&on={$this->_getOrderNumber($order)}&pm={$this->_getPaymentMethodId($order)}"));
        $uapay->setDataRedirectUrl(JRoute::_(JURI::root() . "index.php?option=com_virtuemart&view=orders&layout=details&order_number={$this->_getOrderNumber($order)}&order_pass={$this->_getOrderPass($order)}", false));

        $uapay->setDataOrderId($this->_getOrderId($order));
        $uapay->setDataAmount($totalInPaymentCurrency['value']);
        $uapay->setDataDescription("Order #{$this->_getOrderId($order)}");
//        $uapay->setDataEmail(!empty($order_info['email']) ? $order_info['email'] : '');
        $uapay->setDataReusability(0);


        $result = $uapay->createInvoice();
        UaPayApi::writeLog(array($result), '$result', '');

        $html = $this->renderByLayout('post_payment', array(
            'order_number' => $this->_getOrderNumber($order),
            'order_pass' => $this->_getOrderPass($order),
            'payment_name' => $method->payment_name,
            'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display']
        ));

        if (!empty($result['paymentPageUrl'])) {
            $this->storePSPluginInternalData($dbValues);
            $html .= '<p>' . vmText::_('VMPAYMENT_UAPAY_CHECKOUT_TEXT_PAY') . '</p><br>';
            $html .= '<a class="uapay button" href="' . $result['paymentPageUrl'] . '" target="_blank">' . vmText::_('VMPAYMENT_UAPAY_CHECKOUT_TEXT_BUTTON') . '</a>';

            $q = 'UPDATE `' . $this->_tablename .
                '` SET uapay_method_payment = "' . $uapay->getTypeOperation() . //'PAY' or 'HOLD'
                '", uapay_invoice_id = "' . $result['id'] .
                '", payment_order_total = "' . $totalInPaymentCurrency['value'] .
                '", virtuemart_order_number = "' . $this->_getOrderNumber($order) .
                '" WHERE `virtuemart_order_id`="' . $this->_getOrderId($order) . '"';

            UaPayApi::writeLog($uapay->getTypeOperation() . ' $q', $q);
            $db3 = JFactory::getDBO();
            $db3->setQuery($q);
            $db3->execute();

        } else {
            $html .= !empty($uapay->messageError) ? $uapay->messageError : '';
        }
        vRequest::setVar('html', $html);

        VirtueMartCart::emptyCartValues($cart, true); //work

        return true;
    }

    /**
     * This event is fired when the  method notifies you when an event occurs that affects the order.
     * Typically,  the events  represents for payment authorizations, Fraud Management Filter actions and other actions,
     * such as refunds, disputes, and chargebacks.
     */
    function plgVmOnPaymentNotification()
    {
        require_once(__DIR__ . '/UaPayApi.php');
        UaPayApi::writeLog('Notification', '');

        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        UaPayApi::writeLog($virtuemart_paymentmethod_id, '$virtuemart_paymentmethod_id');

        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        if (!empty($_REQUEST) && !empty($_REQUEST['on'])) {
            $order_number = $_REQUEST['on'];
            UaPayApi::writeLog('$order_number', $order_number);

            $q = 'SELECT * FROM `' . $this->_tablename .
                '` WHERE `virtuemart_order_number`="' . $order_number . '"';

            $db = JFactory::getDBO();
            $db->setQuery($q);
            $uapay_payment_info = $db->loadAssoc();
            UaPayApi::writeLog(array($uapay_payment_info), '$uapay_payment_info');

            if (empty($uapay_payment_info)) {
                return null;
            }

            $method = $this->getVmPluginMethod($virtuemart_paymentmethod_id);

            $invoiceId = $uapay_payment_info['uapay_invoice_id'];

            $uapay = new UapayApi($method->CLIENT_ID, $method->SECRET_KEY, $method->TEST_MODE, $method->TYPE_PAYMENT, $method->REDIRECT_URL);
            $uapay->testMode();
            $invoice = $uapay->getDataInvoice($invoiceId);
            $payment = $invoice['payments'][0];

            UaPayApi::writeLog($uapay_payment_info['uapay_payment_id'], 'uapay_payment_id');
            UaPayApi::writeLog($payment['paymentId'], 'paymentId');
            UaPayApi::writeLog($payment['paymentStatus'], 'paymentStatus');
            UaPayApi::writeLog($payment, '$payment');

            $modelOrder = VmModel::getModel('orders');
            $data['customer_notified'] = 1;

            switch ($payment['paymentStatus']) {
                case UaPayApi::STATUS_FINISHED:
                    if ($uapay_payment_info['uapay_method_payment'] != UaPayApi::STATUS_FINISHED) {
                        UaPayApi::writeLog('STATUS_FINISHED', '', '');
                        $data['order_status'] = $method->status_success;
                        $data['comments'] = vmText::_('VMPAYMENT_UAPAY_PAYMENT_SUCCESS');
                        $data['paid']=$uapay_payment_info['payment_order_total'];
                        UaPayApi::writeLog(array($data), '$data');
                        $modelOrder->updateStatusForOneOrder($uapay_payment_info['virtuemart_order_id'], $data, false);

                        $q = 'UPDATE `' . $this->_tablename .
                            '` SET uapay_method_payment = "' . UaPayApi::STATUS_FINISHED .
                            '", uapay_payment_id = "' . $payment['paymentId'] .
                            '" WHERE `virtuemart_order_number`="' . $order_number . '"';

                        UaPayApi::writeLog('STATUS_FINISHED', $q);

                        $db = JFactory::getDBO();
                        $db->setQuery($q);
                        $db->execute();

                        $modelOrder->toggle('paid');
                    }
                    break;
                case UaPayApi::STATUS_HOLDED:
                        UaPayApi::writeLog('STATUS_HOLDED', '', '');
                    if ($payment['status'] == 'PAID' && empty($uapay_payment_info['uapay_payment_id']) && $uapay_payment_info['uapay_method_payment'] != UaPayApi::STATUS_HOLDED) {
                        UaPayApi::writeLog('STATUS_HOLDED', '', '');
                        $data['order_status'] = $method->status_auth;
                        $data['comments'] = vmText::_('VMPAYMENT_UAPAY_PAYMENT_AUTH');
                        $data['paymentName']=$method->payment_name;
                        $data['paid']=$uapay_payment_info['payment_order_total'];
                        $modelOrder->updateStatusForOneOrder($uapay_payment_info['virtuemart_order_id'], $data, TRUE);

                        UaPayApi::writeLog(array($data), '$order');

                        $q = 'UPDATE `' . $this->_tablename .
                            '` SET uapay_method_payment = "' . UaPayApi::STATUS_HOLDED .
                            '", uapay_payment_id = "' . $payment['paymentId'] .
                            '" WHERE `virtuemart_order_number`="' . $order_number . '"';

                        UaPayApi::writeLog('STATUS_HOLDED', $q);

                        $db = JFactory::getDBO();
                        $db->setQuery($q);
                        $db->execute();
                    }
                    break;
                case UaPayApi::STATUS_CANCELED:
                    if ($uapay_payment_info['uapay_method_payment'] != UaPayApi::STATUS_CANCELED) {
                        UaPayApi::writeLog('STATUS_CANCELED', '', '');
                        $data['order_status'] = 'X';
                        $data['comments'] = vmText::_('VMPAYMENT_UAPAY_PAYMENT_CANCELED') . $uapay_payment_info['payment_order_total'];

                        UaPayApi::writeLog(array($data), '$order');
                        $modelOrder->updateStatusForOneOrder($uapay_payment_info['virtuemart_order_id'], $data, false);

                        $q = 'UPDATE `' . $this->_tablename .
                            '` SET uapay_method_payment = "' . UaPayApi::STATUS_CANCELED .
                            '" WHERE `virtuemart_order_number`="' . $order_number . '"';

                        UaPayApi::writeLog('STATUS_CANCELED', $q);

                        $db = JFactory::getDBO();
                        $db->setQuery($q);
                        $db->execute();
                    }
                    break;
                case UaPayApi::STATUS_REVERSED:
                    if ($uapay_payment_info['uapay_method_payment'] != UaPayApi::STATUS_REVERSED) {
                        UaPayApi::writeLog('STATUS_REVERSED', '', '');
                        $data['order_status'] = $method->status_refund;
                        $data['comments'] = vmText::_('VMPAYMENT_UAPAY_PAYMENT_REVERSED') . $uapay_payment_info['payment_order_total'];

                        UaPayApi::writeLog(array($data), '$order');
                        $modelOrder->updateStatusForOneOrder($uapay_payment_info['virtuemart_order_id'], $data, false);

                        $q = 'UPDATE `' . $this->_tablename .
                            '` SET uapay_method_payment = "' . UaPayApi::STATUS_REVERSED .
                            '" WHERE `virtuemart_order_number`="' . $order_number . '"';

                        UaPayApi::writeLog('STATUS_REVERSED', $q);

                        $db = JFactory::getDBO();
                        $db->setQuery($q);
                        $db->execute();
                    }
                    break;
                default:
                    UaPayApi::writeLog($payment['paymentStatus'], 'default status');
                    break;
            }
        } else {
            UaPayApi::writeLog('no request');
            return false;
        }
        return null;
    }

    /** refund and capture
     *  Order status changed
     * @param $order
     * @param $old_order_status
     * @return bool|null
     */
    public function plgVmOnUpdateOrderPayment(&$order, $old_order_status)
    {
        require_once(__DIR__ . '/UaPayApi.php');
        UaPayApi::writeLog('UpdateOrderPayment');
        //Load the method
        if (!($method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $order_id = $order->virtuemart_order_id;

        UaPayApi::writeLog($order_id, '$order_id');
        UaPayApi::writeLog($order->order_status, '$order->order_status');
        UaPayApi::writeLog($method->status_success, '$method->status_success');
        UaPayApi::writeLog($old_order_status, '$old_order_status');

        $q = 'SELECT * FROM `' . $this->_tablename .
            '` WHERE `virtuemart_order_id`="' . $order_id . '"';

        $db = JFactory::getDBO();
        $db->setQuery($q);
        $uapay_payment_info = $db->loadAssoc();
        UaPayApi::writeLog(array($uapay_payment_info), '$uapay_payment_info');

        if ($order->order_status == $method->status_refund) {
            UaPayApi::writeLog('refund');

            if (!empty($uapay_payment_info) && !empty($uapay_payment_info['uapay_payment_id'])) {
                $uapay = new UaPayApi($method->CLIENT_ID, $method->SECRET_KEY, $method->TEST_MODE, $method->TYPE_PAYMENT, $method->REDIRECT_URL);
                $uapay->testMode();
                $invoice = $uapay->getDataInvoice($uapay_payment_info['uapay_invoice_id']);
                $payment = $invoice['payments'][0];

                $uapay->setInvoiceId($uapay_payment_info['uapay_invoice_id']);
                $uapay->setPaymentId($uapay_payment_info['uapay_payment_id']);

//                $amount = $amount_order = $order->order_total;
//                $amount_payment = $uapay_payment_info['payment_order_total'];

                UaPayApi::writeLog($payment['paymentStatus'], 'paymentStatus');

                switch ($payment['paymentStatus']) {
                    case UaPayApi::STATUS_FINISHED:
                    case UaPayApi::STATUS_REVERSED:
                        $result = $uapay->reverseInvoice();
                        $flag = false;
                        break;
                    case UaPayApi::STATUS_HOLDED:
                    case UaPayApi::STATUS_CANCELED:
                        $result = $uapay->cancelInvoice();
                        $flag = true;
                        break;
                }
                UaPayApi::writeLog(array($result), '$result', '');

                if (!empty($result['status'])) {
                    UaPayApi::writeLog('ok', '', '');
                    if ($flag) {
                        $mes = vmText::_('VMPAYMENT_UAPAY_PAYMENT_CANCELED');
                    } else {
                        $mes = vmText::_('VMPAYMENT_UAPAY_PAYMENT_REVERSED');
                    }
                    JFactory::getApplication()->enqueueMessage($mes, 'message');
                    return false;
                } else {
                    UaPayApi::writeLog($uapay->messageError, 'Error! ', '');
                    UaPayApi::writeLog($uapay->messageErrorCode, 'messageErrorCode! ', '');
                    JFactory::getApplication()->enqueueMessage($uapay->messageError, 'error');
                    return false;
                }
            } else {
                $mess = 'UaPay payment was not successful with payment id. The payment id has not found in order ' . $order_id;
                JFactory::getApplication()->enqueueMessage($mess, 'error');
                return false;
            }
        }
        elseif ($order->order_status == $method->status_success) {
            UaPayApi::writeLog('capture');

            if (!empty($uapay_payment_info) && !empty($uapay_payment_info['uapay_payment_id'])) {
                $uapay = new UaPayApi($method->CLIENT_ID, $method->SECRET_KEY, $method->TEST_MODE, $method->TYPE_PAYMENT, $method->REDIRECT_URL);
                $uapay->testMode();
                $invoice = $uapay->getDataInvoice($uapay_payment_info['uapay_invoice_id']);
                $payment = $invoice['payments'][0];

                $uapay->setInvoiceId($uapay_payment_info['uapay_invoice_id']);
                $uapay->setPaymentId($uapay_payment_info['uapay_payment_id']);

                UaPayApi::writeLog($payment['paymentStatus'], 'paymentStatus');

                $result = $uapay->completeInvoice();

                UaPayApi::writeLog(array($result), '$result', '');

                if (!empty($result['status'])) {
                    UaPayApi::writeLog('ok', '', '');
                    JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_UAPAY_PAYMENT_SUCCESS'), 'message');
                    return false;
                } else {
                    UaPayApi::writeLog($uapay->messageError, 'Error! ', '');
                    UaPayApi::writeLog($uapay->messageErrorCode, 'messageErrorCode! ', '');
                    JFactory::getApplication()->enqueueMessage($uapay->messageError, 'error');
                    return false;
                }
            } else {
                $mess = 'UaPay payment was not successful with payment id. The payment id has not found in order ' . $order_id;
                JFactory::getApplication()->enqueueMessage($mess, 'error');
                return false;
            }

        }
        return true;
    }

    private function _getOrderNumber($order)
    {
        return $order['details']['BT']->order_number;
    }

    private function _getOrderPass($order)
    {
        return $order['details']['BT']->order_pass;
    }

    private function _getOrderId($order)
    {
        return $order['details']['BT']->virtuemart_order_id;
    }

    private function _getPaymentMethodId($order)
    {
        return $order['details']['BT']->virtuemart_paymentmethod_id;
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart_prices : cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     * @author: Valerie Isaksen
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $currency_id = $method->currency_id ? $method->currency_id : $method->payment_currency;
        $currency_code_3 = shopFunctions::getCurrencyByID($currency_id, 'currency_code_3');
        if (!in_array($currency_code_3, array('USD', 'EUR', 'UAH', 'RUB'))) {
            return false;
        }
        $this->convert_condition_amount($method);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        if ($this->_toConvert) {
            $this->convertToVendorCurrency($method);
        }
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }
        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            return TRUE;
        }
        return FALSE;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;

        return;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmOnUserInvoice($orderDetails, &$data)
    {
        require_once(__DIR__ . '/UaPayApi.php');
        UaPayApi::writeLog('plgVmOnUserInvoice', '');

        UaPayApi::writeLog(array($orderDetails), '$orderDetails');
        UaPayApi::writeLog(array($data), '$data');

        if (!($method = $this->getVmPluginMethod($orderDetails['virtuemart_paymentmethod_id']))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }
        if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null == 1 or $orderDetails['order_total'] > 0.00) {
            return NULL;
        }
        if ($orderDetails['order_salesPrice'] == 0.00) {
            $data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
        }
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}