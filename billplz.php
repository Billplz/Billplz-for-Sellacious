<?php
/**
 * @version     3.0.0
 * @package     Sellacious Payment - Billplz
 *
 * @copyright   Copyright (C) 2018 Billplz Sdn. Bhd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * @author      Wan @ Billplz <wan@billplz.com> - http://www.billplz.com
 */
// No direct access.
defined('_JEXEC') or die;

// require the Sellacious SDK Auto-loader
JLoader::import('sellacious.loader');

if (!class_exists('Billplz\API') && !class_exists('Billplz\Connect')) {
    require_once(__DIR__ .'/api.php');
    require_once(__DIR__ .'/connect.php');
}

/**
 * Plugin to manage payment via 'Billplz' for sellacious shops checkout process
 *
 * @subpackage Billplz - Sellacious Payments
 *
 * @since  3.0.0
 */
class plgSellaciousPaymentBillplz extends SellaciousPluginPayment
{
    /**
     * Returns handlers to the payment methods that will be managed by this plugin
     *
     * @param   string  $context    The calling context, must be 'com_sellacious.payment' to effect
     * @param   array   &$handlers  ByRef, associative array of handlers
     *
     * @return  bool
     *
     * @since   1.2.0
     */
    public function onCollectHandlers($context, array &$handlers)
    {
        // Billplz library must exist, if not then skip
        if ($context == 'com_sellacious.payment') {
            $handlers['billplz'] = JText::_('PLG_SELLACIOUSPAYMENT_BILLPLZ_API');
        }

        return true;
    }

    /**
     * Triggers payment method to make a payment for the given transaction
     * The order must have been created in the database prior calling this method.
     * Since we need a redirection mechanism in between, we need to alter the calling sequence.
     *
     * @param   string  $context  The calling context, must be 'com_sellacious.payment' to effect
     *
     * @return  bool
     * @throws  Exception
     *
     * @since   1.2.0
     */
    public function onRequestPayment($context)
    {
        //$app = JFactory::getApplication();

        $payment_id = $this->getState('id');
        $handler    = $this->helper->payment->getFieldValue($payment_id, 'handler');

        $result   = true;
        $handlers = array();
        $this->onCollectHandlers($context, $handlers);

        if (array_key_exists($handler, $handlers)) {
            $invoice = $this->getInvoice();
            $this->initPayment($invoice);
        }

        return $result;
    }

    /**
     *
     * @param string $context The calling context, must be 'com_sellacious.payment' to effect
     * @return void
     * @throws Exception
     * @since 3.0.0
     */
    public function onPaymentFeedback($context)
    {
        // You should set the payment property at the very beginning of a call to avoid any collision.
        // This is a stateless call, therefore we cannot use getState()
        $app     = JFactory::getApplication();
        $paymentId = $app->input->getInt('payment_id');
        $this->payment = $this->helper->payment->getItem($paymentId);

        // IMPORTANT: Set state value for stateless calls, else 'saveResponse' call would fail
        $this->setState('id', $paymentId);

        if ($context == 'com_sellacious.payment' && $this->payment->handler == 'billplz') {
            $response = $this->executePayment();
            $result   = $this->handleResponse($response);

            echo '<pre>'.print_r($response, true).'</pre>';
        }

        exit;
    }


    /**
     * Triggers payment execution on callback return after payment on Billplz
     *
     * @param   string  $context  The calling context, must be 'com_sellacious.payment' to effect
     *
     * @return  bool
     * @throws  Exception
     *
     * @since   1.2.0
     */
    public function onPaymentCallback($context)
    {
        $app = JFactory::getApplication();

        $payment_id = $this->getState('id');
        $handler    = $this->helper->payment->getFieldValue($payment_id, 'handler');

        $result = true;

        if ($context == 'com_sellacious.payment' && $handler == 'billplz') {
            $response = $this->executePayment();
            $result   = $this->handleResponse($response);
        }

        return $result;
    }

    /**
     * Sellacious do not bother about the details a plugin might need.
     * Plugins are set free to fetch what they want. However basic data is directly accessible.
     *
     * @return  array  All the required details for the transaction execution with the Payment Gateway
     * @throws  Exception
     *
     * @since   1.2.0
     */
    protected function getInvoice()
    {
        $app = JFactory::getApplication();

        $payment_id = $this->getState('id');
        $payment    = $this->helper->payment->getItem($payment_id);

        $array = array(
            'order_id'    => $payment->order_id,
            'payment_id'  => $payment_id,
            'currency'    => $payment->currency,
            'amount'      => $payment->amount_payable,
            'description' => $app->get('sitename'),
        );

        return $array;
    }

    /**
     * Initialize SDK configurations using client key and secret or email with additional connection settings
     *
     * @return  stdClass  config for Billplz
     * @throws  Exception
     *
     * @since   1.2.0
     */
    protected function getApiContext()
    {
        $config = $this->getParams();

        $api       = new stdClass();
        $api->api_key = $config->get('billplz_api_key');
        $api->x_signature_key = $config->get('billplz_x_signature_key');
        $api->collection_id = $config->get('billplz_collection_id');
        $api->notification = $config->get('billplz_notification', '0');

        if (empty($api->api_key)) {
            throw new Exception(JText::_('PLG_SELLACIOUSPAYMENT_BILLPLZ_API_KEY_NOT_SET'));
        }

        if (empty($api->x_signature_key)) {
            throw new Exception(JText::_('PLG_SELLACIOUSPAYMENT_BILLPLZ_X_SIGNATURE_KEY_NOT_SET'));
        }

        return $api;
    }

    /**
     * Create a payment using the buyer's billplz account as the funding instrument.
     * The app will have to redirect the buyer to the Billplz Payment Page, and make payment.
     *
     * @param   mixed  $invoice  The data required by the payment gateway to execute the transaction
     *
     * @return  void
     * @throws  Exception
     *
     * @since   1.2.0
     */
    protected function initPayment($invoice)
    {
        if (empty($invoice['amount'])) {
            throw new Exception(JText::_('PLG_SELLACIOUSPAYMENT_BILLPLZ_AMOUNT_IS_NOT_VALID'));
        }

        /*
        if (strtoupper($invoice['currency']) !== 'MYR') {
            throw new Exception('Currency must set to MYR for Billplz to work');
        }
        */

        $callBackUrl = $this->getCallbackUrl(array('payment_id'=> $invoice['payment_id']), true);
        $redirectUrl   = $this->getCallbackUrl(array('payment_id'=> $invoice['payment_id']), false);

        $context    = $this->helper->payment->getFieldValue($invoice['payment_id'], 'context');
        $userDetail = $this->getUserDetail($invoice['order_id'], $context);

        $api = $this->getApiContext();

        $api_key = $api->api_key;
        $deliver = $api->notification;

        $parameter = array(
                'collection_id' => $api->collection_id,
                'email'=>isset($userDetail->email) ? $userDetail->email : '',
                'mobile'=>isset($userDetail->phone) ? $userDetail->phone : '',
                'name'=>isset($userDetail->firstname) ? $userDetail->firstname : 'No Name',
                'amount'=>intval($invoice['amount'] * 100),
                'callback_url'=>$callBackUrl,
                'description'=> substr($invoice['description'], 0, 199)
        );

        $optional = array(
                'redirect_url' => $redirectUrl,
                'reference_1_label' => 'Payment ID',
                'reference_1' => $invoice['payment_id'],
                'reference_2_label' => 'Order ID',
                'reference_2' => $invoice['order_id']
        );

        $connnect = (new \Billplz\Connect($api_key))->detectMode();
        $billplz = new \Billplz\API($connnect);
        list($rheader, $rbody) = $billplz->toArray($billplz->createBill($parameter, $optional, $deliver));

        //$layout = new JLayoutFile('billplz', $basePath = __DIR__ . '/layout/');

        if ($rheader !== 200) {
            throw new Exception(print_r($rbody, true));
        } else {
            $this->app->redirect($rbody['url']);
        }
        //jexit();
    }

    /**
     * Gives User payment details
     *
     * @param   $order_id
     * @param   $context
     *
     * @return  bool|mixed
     *
     * @since   1.2.0
     */
    protected function getUserDetail($order_id, $context)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);

        if ($context == 'transaction') {
            $transactionDetail = $this->helper->transaction->getItem($order_id);

            if ($transactionDetail->context == 'user.id') {
                $query->select('a.name AS  firstname, a.mobile AS phone, a.address, a.city, a.zip, a.country, a.state_loc')
                    ->from('#__sellacious_addresses AS a')
                    ->select('u.email')
                    ->join('left', '#__users AS u ON u.id = a.user_id')
                    ->where('a.user_id = ' . (int) $transactionDetail->context_id)
                    ->order('a.is_primary DESC');
            }
        } else {
            $query->select('customer_email AS email, bt_name AS firstname, bt_mobile AS phone, bt_address AS address, order_number')
                ->from('#__sellacious_orders')
                ->where('id = ' . (int) $order_id);
        }

        try {
            return $db->setQuery($query)->loadObject();
        } catch (Exception $e) {
        }

        return true;
    }

    /**
     * Capture the authorized payment with the Billplz API.
     *
     * @return  array  Transaction's response received from the gateway
     * @throws  Exception
     *
     * @since   1.2.0
     */
    protected function executePayment()
    {
        //$app     = JFactory::getApplication();
        $api = $this->getApiContext();
        $payment = \Billplz\Connect::getXSignature($api->x_signature_key);

        return $payment;
    }

    /**
     * Generate response data to be stored in the transaction log and save
     *
     * @param   array  $payment  The Payment object as the Billplz response
     *
     * @return  bool
     * @throws  Exception
     *
     * @since   1.2.0
     */
    protected function handleResponse($payment)
    {
        $api = $this->getApiContext();

        $connnect = (new \Billplz\Connect($api->api_key))->detectMode();
        $billplz = new \Billplz\API($connnect);

        list($rheader, $rbody) = $billplz->toArray($billplz->getBill($payment['id']));

        if ($rbody['paid']) {
            $old = $this->helper->order->getStatus($rbody['reference_2']);
            $status  = static::STATUS_APPROVED;
            $message = 'Payment Successfull';
            $data = $payment;
        } else {
            $status  = static::STATUS_PENDING;
            $message = 'Payment Unsuccessfull';
        }

        if ($rbody['paid']) {
            if ($old->status === '3') {
                echo 'Payment status updated...';
            } else {
                $this->saveResponse($rheader, $rbody['state'], $message, json_encode($payment), $rbody['id'], $status, $payment['type']);
                if ($payment['type'] === 'callback' && strval($this->getState('id')) === $rbody['reference_1']) {
                    $this->helper->order->setStatusByType('order', 'approved', $rbody['reference_2'], '', true, true, 'Payment Approved by '.$payment['type']);
                }
            }
        } else {
            $this->saveResponse($rheader, $rbody['state'], $message, json_encode($payment), $rbody['id'], $status, $payment['type']);
        }

        /*
        if (!$rbody['paid'] && $payment['type']==='redirect') {
            $this->app->redirect(JRoute::_('index.php?option=com_sellacious&view=order&id=' . $rbody['reference_2'] . '&layout=cancelled', false));
            exit;
        }
        $config = $this->getParams();
        */
        
        return $status == static::STATUS_APPROVED;
    }
}
