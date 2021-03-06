<?php

namespace JMS\Payment\PaypalBundle\Plugin;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Util\Number;
use JMS\Payment\PaypalBundle\Client\Client;
use JMS\Payment\PaypalBundle\Client\Response;

/*
 * Copyright 2010 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class ExpressCheckoutPlugin extends AbstractPlugin
{
    /**
     * The payment is pending because it has been authorized but not settled. You must capture the funds first.
     */
    const REASON_CODE_PAYPAL_AUTHORIZATION = 'authorization';
    
    /**
     * @var string
     */
    protected $returnUrl;

    /**
     * @var string
     */
    protected $cancelUrl;

    /**
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    protected $client;

    /**
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param \JMS\Payment\PaypalBundle\Client\Client $client
     */
    public function __construct($returnUrl, $cancelUrl, Client $client)
    {
        $this->client = $client;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
    }

    public function approve(FinancialTransactionInterface $transaction, $retry)
    {
        $this->createCheckoutBillingAgreement($transaction, 'Authorization');
    }

    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $this->createCheckoutBillingAgreement($transaction, 'Sale');
    }

    public function credit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $approveTransaction = $transaction->getCredit()->getPayment()->getApproveTransaction();

        $parameters = array();
        if (Number::compare($transaction->getRequestedAmount(), $approveTransaction->getProcessedAmount()) !== 0) {
            $parameters['REFUNDTYPE'] = 'Partial';
            $parameters['AMT'] = $this->client->convertAmountToPaypalFormat($transaction->getRequestedAmount());
            $parameters['CURRENCYCODE'] = $transaction->getCredit()->getPaymentInstruction()->getCurrency();
        }

        $response = $this->client->requestRefundTransaction($data->get('authorization_id'), $parameters);

        $this->throwUnlessSuccessResponse($response, $transaction);

        $transaction->setReferenceNumber($response->body->get('REFUNDTRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('NETREFUNDAMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
    }

    /**
     * Deposit
     *
     * @param FinancialTransactionInterface $transaction
     * @param bool $retry
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException
     */
    public function deposit(FinancialTransactionInterface $transaction, $retry)
    {
        $transactionId = $this->doCapture($transaction);

        $details = $this->client->requestGetTransactionDetails($transactionId);
        $this->throwUnlessSuccessResponse($details, $transaction);

        switch ($details->body->get('PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                throw new PaymentPendingException('Payment is still pending: '.$details->body->get('PENDINGREASON'));

            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$details->body->get('PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($details->body->get('PAYMENTSTATUS'));

                throw $ex;
        }

        $transaction->setReferenceNumber($details->body->get('TRANSACTIONID'));
        $transaction->setProcessedAmount($details->body->get('AMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * Reverse approval
     *
     * @param FinancialTransactionInterface $transaction
     * @param bool $retry
     */
    public function reverseApproval(FinancialTransactionInterface $transaction, $retry)
    {
        $authorizationId = $transaction->getPayment()->getApproveTransaction()->getReferenceNumber();

        $response = $this->client->requestDoVoid($authorizationId);
        $this->throwUnlessSuccessResponse($response, $transaction);

        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
        $transaction->setReferenceNumber($response->body->get('AUTHORIZATIONID'));
        $transaction->setProcessedAmount($transaction->getRequestedAmount());
    }

    /**
     * Reverse deposit
     *
     * @param FinancialTransactionInterface $transaction
     * @param bool $retry
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException
     */
    public function reverseDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $transactionId = $transaction->getPayment()->getDepositTransactions()->first()->getReferenceNumber();

        $response = $this->client->requestRefundTransaction($transactionId);
        $this->throwUnlessSuccessResponse($response, $transaction);

        switch ($response->body->get('REFUNDSTATUS')) {
            case 'instant':
                break;
            case 'delayed':
                throw new PaymentPendingException('The refund status is delayed: ' . $response->body->get('PENDINGREASON'));
        }

        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
        $transaction->setReferenceNumber($response->body->get('REFUNDTRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('GROSSREFUNDAMT'));
    }

    public function processes($paymentSystemName)
    {
        return 'paypal_express_checkout' === $paymentSystemName;
    }

    public function isIndependentCreditSupported()
    {
        return false;
    }

    protected function createCheckoutBillingAgreement(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();

        $token = $this->obtainExpressCheckoutToken($transaction, $paymentAction);

        $details = $this->client->requestGetExpressCheckoutDetails($token);
        $this->throwUnlessSuccessResponse($details, $transaction);

        // verify checkout status
        switch ($details->body->get('CHECKOUTSTATUS')) {
            case 'PaymentActionFailed':
                $ex = new FinancialException('PaymentAction failed.');
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode('PaymentActionFailed');
                $ex->setFinancialTransaction($transaction);

                throw $ex;

            case 'PaymentActionCompleted':
                break;

            case 'PaymentActionNotInitiated':
                if ('verified' == $details->body->get('PAYERSTATUS')) {
                    // If payment is verified - going on
                    break;
                }
                
            default:
                $actionRequest = new ActionRequiredException('User has not yet authorized the transaction.');
                $actionRequest->setFinancialTransaction($transaction);
                $actionRequest->setAction(new VisitUrl($this->client->getAuthenticateExpressCheckoutTokenUrl($token)));

                throw $actionRequest;
        }

        // complete the transaction
        $data->set('paypal_payer_id', $details->body->get('PAYERID'));

        $response = $this->client->requestDoExpressCheckoutPayment(
            $data->get('express_checkout_token'),
            $transaction->getRequestedAmount(),
            $paymentAction,
            $details->body->get('PAYERID'),
            array('PAYMENTREQUEST_0_CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency())
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        switch($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
                
                $ex = new PaymentPendingException('Payment is still pending: ' . $response->body->get('PAYMENTINFO_0_PENDINGREASON'));
                $ex->setPendingReason($response->body->get('PAYMENTINFO_0_PENDINGREASON'));
                if (self::REASON_CODE_PAYPAL_AUTHORIZATION != $response->body->get('PAYMENTINFO_0_PENDINGREASON')) {
                    // Throw every pending exception other authorization
                    throw $ex;
                }
                break;
                
            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));

                throw $ex;
        }

        $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('PAYMENTINFO_0_AMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param string $paymentAction
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException if user has to authenticate the token
     *
     * @return string
     */
    protected function obtainExpressCheckoutToken(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();
        if ($data->has('express_checkout_token')) {
            return $data->get('express_checkout_token');
        }

        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();
        $opts['PAYMENTREQUEST_0_PAYMENTACTION'] = $paymentAction;
        $opts['PAYMENTREQUEST_0_CURRENCYCODE'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();

        $response = $this->client->requestSetExpressCheckout(
            $transaction->getRequestedAmount(),
            $this->getReturnUrl($data),
            $this->getCancelUrl($data),
            $opts
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        $data->set('express_checkout_token', $response->body->get('TOKEN'));

        $authenticateTokenUrl = $this->client->getAuthenticateExpressCheckoutTokenUrl($response->body->get('TOKEN'));

        $actionRequest = new ActionRequiredException('User must authorize the transaction.');
        $actionRequest->setFinancialTransaction($transaction);
        $actionRequest->setAction(new VisitUrl($authenticateTokenUrl));

        throw $actionRequest;
    }
    
    /**
     * Do capture - returns transaction id
     *
     * @param FinancialTransactionInterface $transaction
     * @return string $transactionId
     */
    protected function doCapture(FinancialTransactionInterface $transaction)
    {
        $authorizationId = $transaction->getPayment()->getApproveTransaction()->getReferenceNumber();

        if (Number::compare($transaction->getPayment()->getApprovedAmount(), $transaction->getRequestedAmount()) === 0) {
            $completeType = 'Complete';
        }
        else {
            $completeType = 'NotComplete';
        }

        $capture = $this->client->requestDoCapture($authorizationId, $transaction->getRequestedAmount(), $completeType, array(
            'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
        ));
        $this->throwUnlessSuccessResponse($capture, $transaction);

        // In case authorization is expired after 3 day honor period, try to reauthorize
        if ('Expired' == $capture->body->get('PAYMENTSTATUS')) {
            $reauthorization = $this->client->requestDoReauthorization($authorizationId, $transaction->getRequestedAmount(), $completeType, array(
                'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
            ));
            $this->throwUnlessSuccessResponse($reauthorization, $transaction);
            
            if ('Completed' == $reauthorization->body->get('PAYMENTSTATUS')) {
                // Set new authorization id and capture again
                $authorizationId = $reauthorization->body->get('AUTHORIZATIONID');
                $capture = $this->client->requestDoCapture($authorizationId, $transaction->getRequestedAmount(), $completeType, array(
                    'CURRENCYCODE' => $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
                ));
                $this->throwUnlessSuccessResponse($capture, $transaction);
            }
        }
        
        // Fetch new transaction id from captured transaction
        return $capture->body->get('TRANSACTIONID');
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param \JMS\Payment\PaypalBundle\Client\Response $response
     * @return null
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    protected function throwUnlessSuccessResponse(Response $response, FinancialTransactionInterface $transaction)
    {
        if ($response->isSuccess()) {
            return;
        }

        $transaction->setResponseCode($response->body->get('ACK'));
        $transaction->setReasonCode($response->body->get('L_ERRORCODE0'));

        $ex = new FinancialException('PayPal-Response was not successful: '.$response);
        $ex->setFinancialTransaction($transaction);

        throw $ex;
    }

    protected function getReturnUrl(ExtendedDataInterface $data)
    {
        if ($data->has('return_url')) {
            return $data->get('return_url');
        }
        else if (0 !== strlen($this->returnUrl)) {
            return $this->returnUrl;
        }

        throw new \RuntimeException('You must configure a return url.');
    }

    protected function getCancelUrl(ExtendedDataInterface $data)
    {
        if ($data->has('cancel_url')) {
            return $data->get('cancel_url');
        }
        else if (0 !== strlen($this->cancelUrl)) {
            return $this->cancelUrl;
        }

        throw new \RuntimeException('You must configure a cancel url.');
    }
}
