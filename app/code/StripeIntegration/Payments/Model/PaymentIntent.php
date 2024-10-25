<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Exception\SCANeededException;
use StripeIntegration\Payments\Exception\GenericException;

class PaymentIntent extends \Magento\Framework\Model\AbstractModel
{
    private $paymentIntent = null;

    public const SUCCEEDED = "succeeded";
    public const AUTHORIZED = "requires_capture";
    public const CAPTURE_METHOD_MANUAL = "manual";
    public const CAPTURE_METHOD_AUTOMATIC = "automatic";
    public const REQUIRES_ACTION = "requires_action";
    public const CANCELED = "canceled";
    public const AUTHENTICATION_FAILURE = "payment_intent_authentication_failure";

    private $compare;
    private $addressHelper;
    private $cache;
    private $addressFactory;
    private $customer;
    private $subscriptionsHelper;
    private $paymentIntentHelper;
    private $dataHelper;
    private $helper;
    private $config;
    private $stripePaymentMethod;
    private $stripePaymentIntent;
    private $paymentIntentCollection;
    private $resourceModel;
    private $checkoutFlow;
    private $quoteHelper;
    private $orderHelper;
    private $convert;
    private $paymentMethodTypesHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Convert $convert,
        \StripeIntegration\Payments\Helper\PaymentMethodTypes $paymentMethodTypesHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethod $stripePaymentMethod,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntent $stripePaymentIntent,
        \StripeIntegration\Payments\Model\Checkout\Flow $checkoutFlow,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $paymentIntentCollection,
        \Magento\Customer\Model\AddressFactory $addressFactory,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent $resourceModel,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
        )
    {
        $this->dataHelper = $dataHelper;
        $this->helper = $helper;
        $this->compare = $compare;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->convert = $convert;
        $this->cache = $context->getCacheManager();
        $this->config = $config;
        $this->paymentMethodTypesHelper = $paymentMethodTypesHelper;
        $this->customer = $helper->getCustomerModel();
        $this->addressFactory = $addressFactory;
        $this->stripePaymentMethod = $stripePaymentMethod;
        $this->stripePaymentIntent = $stripePaymentIntent;
        $this->checkoutFlow = $checkoutFlow;
        $this->paymentIntentCollection = $paymentIntentCollection;
        $this->resourceModel = $resourceModel;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\PaymentIntent');
    }

    // If we already created any payment intents for this quote, load them
    private function invalidateFrom($params, $quote, $order)
    {
        if (!$quote || !$quote->getId() || !$this->getPiId())
            return null;

        $quoteId = $quote->getId();

        $paymentIntent = null;

        try
        {
            $paymentIntent = $this->loadPaymentIntent($this->getPiId(), $order);
        }
        catch (\Exception $e)
        {
            // If the Stripe API keys or the Mode was changed mid-checkout-session, we may get here
            $this->destroy();
            return null;
        }

        if ($this->isInvalid($params, $quote, $order, $paymentIntent))
        {
            $this->destroy($paymentIntent);
            return null;
        }

        if ($this->isDifferentFrom($paymentIntent, $params, $quote, $order))
        {
            $paymentIntent = $this->updateFrom($paymentIntent, $params, $quote, $order);
        }

        if ($paymentIntent)
        {
            $this->updateModelFrom($quote, $paymentIntent, $order);
        }
        else
        {
            $this->destroy();
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function canCancel($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        if (empty($paymentIntent))
        {
            return false;
        }

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent))
        {
            return false;
        }

        if ($this->paymentIntentHelper->isAsyncProcessing($paymentIntent))
        {
            return false;
        }

        if ($paymentIntent->status == $this::CANCELED)
        {
            return false;
        }

        return true;
    }

    public function canUpdate($paymentIntent)
    {
        return $this->canCancel($paymentIntent);
    }

    private function loadPaymentIntent($paymentIntentId, $order = null)
    {
        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId);

        // If the PI has a customer attached, load the customer locally as well
        if (!empty($paymentIntent->customer))
        {
            $customer = $this->helper->getCustomerModelByStripeId($paymentIntent->customer);
            if ($customer)
                $this->customer = $customer;

            if (!$this->customer->getStripeId())
            {
                $this->customer->createStripeCustomer($order, ["id" => $paymentIntent->customer]);
            }
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function createPaymentIntentFrom($params, $quote, $order = null)
    {
        if (empty($params['amount']) || $params['amount'] <= 0)
            return null;

        $paymentIntent = $this->invalidateFrom($params, $quote, $order);

        if (!$paymentIntent)
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->create($params);
            $this->updateModelFrom($quote, $paymentIntent, $order);

            if ($order)
            {
                $payment = $order->getPayment();
                $payment->setAdditionalInformation("payment_intent_id", $paymentIntent->id);
            }
        }

        return $this->paymentIntent = $paymentIntent;
    }

    private function updateModelFrom($quote, $paymentIntent, $order = null)
    {
        if ($order)
        {
            $quoteId = $order->getQuoteId();
            $customerEmail = $order->getCustomerEmail();
        }
        else
        {
            $quoteId = $quote->getId();
            $customerEmail = $quote->getCustomerEmail();
        }

        if (!$this->getQuoteId())
        {
            $this->resourceModel->load($this, $quoteId, 'quote_id');
        }

        $oldPiId = $this->getPiId();

        $this->setPiId($paymentIntent->id);
        $this->setQuoteId($quoteId);
        $this->setCustomerEmail($customerEmail);

        if ($order)
        {
            if ($order->getIncrementId())
                $this->setOrderIncrementId($order->getIncrementId());

            if ($order->getId())
                $this->setOrderId($order->getId());

            $customerId = $order->getCustomerId();
            if (!empty($customerId))
                $this->setCustomerId($customerId);
            else
                $this->setCustomerId(null);

            if ($order->getPayment()->getAdditionalInformation("confirmation_token"))
            {
                $this->setPmId($order->getPayment()->getAdditionalInformation("confirmation_token"));
            }
            else if ($order->getPayment()->getAdditionalInformation("token"))
            {
                $this->setPmId($order->getPayment()->getAdditionalInformation("token"));
            }
            else
            {
                $this->setPmId(null);
            }
        }
        else
        {
            $this->setOrderId(null);
            $this->setOrderIncrementId(null);
            $this->setCustomerId(null);
            $this->setPmId(null);
        }

        $this->resourceModel->save($this);

        // For some reason, saving the model creates a new entry instead of replacing the old one
        // so we manually remove the old one
        if ($oldPiId && $oldPiId != $this->getPiId() && $quoteId)
        {
            $this->paymentIntentCollection->deleteForQuoteIdAndPiId($quoteId, $oldPiId);
        }
    }

    public function getMultishippingParamsFrom($quote, $orders, $paymentMethodId)
    {
        $amount = 0;
        $currency = null;
        $orderIncrementIds = [];

        foreach ($orders as $order)
        {
            $amount += round(floatval($order->getGrandTotal()), 2);
            $currency = $order->getOrderCurrencyCode();
            $orderIncrementIds[] = $order->getIncrementId();
        }

        $params['amount'] = $this->convert->magentoAmountToStripeAmount($amount, $currency);
        $params['currency'] = strtolower($currency);
        $params['capture_method'] = $this->config->getCaptureMethod();

        if ($usage = $this->config->getSetupFutureUsage($quote))
            $params['setup_future_usage'] = $usage;

        $params['payment_method'] = $paymentMethodId;

        $this->setCustomerFromPaymentMethodId($paymentMethodId);

        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomerIfNotExists();
        }

        if ($this->customer->getStripeId())
            $params["customer"] = $this->customer->getStripeId();

        $params["description"] = $this->helper->getMultishippingOrdersDescription($quote, $orders);
        $params["metadata"] = $this->config->getMultishippingMetadata($quote, $orders);

        $customerEmail = $quote->getCustomerEmail();
        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        $params['automatic_payment_methods'] = [ "enabled" => true ];

        $pmc = $this->config->getPaymentMethodConfiguration();
        if ($pmc)
        {
            $params['payment_method_configuration'] = $pmc;
        }

        return $params;
    }

    public function setCustomerFromPaymentMethodId($paymentMethodId, $order = null)
    {
        $paymentMethod = $this->stripePaymentMethod->fromPaymentMethodId($paymentMethodId)->getStripeObject();
        if (!empty($paymentMethod->customer))
        {
            $customer = $this->helper->getCustomerModelByStripeId($paymentMethod->customer);
            if (!$customer)
            {

                $this->customer->createStripeCustomer($order, ["id" => $paymentMethod->customer]);
            }
            else
            {
                $this->customer = $customer;
            }
        }
    }

    public function getParamsFrom($quote, $order, $paymentMethodId = null)
    {
        if (empty($order))
            throw new GenericException("An order is required for PaymentIntent parameters.");

        $amount = $order->getGrandTotal();
        $currency = $order->getOrderCurrencyCode();
        $payment = $order->getPayment();
        $savePaymentMethod = (bool)$payment->getAdditionalInformation("save_payment_method");

        if (empty($paymentMethodId) && $payment->getAdditionalInformation("token"))
        {
            $paymentMethodId = $payment->getAdditionalInformation("token");
        }

        $isExpressCheckout = $this->checkoutFlow->isExpressCheckout && (bool)$payment->getAdditionalInformation("confirmation_token");
        $paymentMethodTypes = $this->paymentMethodTypesHelper->getPaymentMethodTypes($isExpressCheckout);
        if ($paymentMethodTypes)
        {
            // The ECE uses payment method types to filter the available payment methods. It needs to be consistent on the server side.
            $params['payment_method_types'] = $paymentMethodTypes;
        }
        else
        {
            $params['automatic_payment_methods'] = [ 'enabled' => 'true' ];

            $pmc = $this->config->getPaymentMethodConfiguration();
            if ($pmc)
            {
                $params['payment_method_configuration'] = $pmc;
            }
        }

        $params['amount'] = $this->convert->magentoAmountToStripeAmount($amount, $currency);
        $params['currency'] = strtolower($currency);

        $statementDescriptor = $this->config->getStatementDescriptor();
        if (!empty($statementDescriptor))
            $params["statement_descriptor_suffix"] = $statementDescriptor;

        if ($paymentMethodId)
        {
            $params['payment_method'] = $paymentMethodId;
            $this->setCustomerFromPaymentMethodId($paymentMethodId, $order);
        }

        if (!$this->customer->getStripeId())
        {
            if ($this->helper->isCustomerLoggedIn() || $this->config->alwaysSaveCards())
            {
                $this->customer->createStripeCustomerIfNotExists(false, $order);
            }
        }

        if ($this->customer->getStripeId())
            $params["customer"] = $this->customer->getStripeId();

        if ($order)
        {
            $params["description"] = $this->orderHelper->getOrderDescription($order);
            $params["metadata"] = $this->config->getMetadata($order);
        }
        else
        {
            $params["description"] = $this->quoteHelper->getQuoteDescription($quote);
        }

        // Add subscription initial fees to the amount, or remove any trial subscription amounts
        $subscriptionsTotal = $this->getSubscriptionsAmount($quote, $order);
        $stripeSubscriptionsTotal = $this->convert->magentoAmountToStripeAmount($subscriptionsTotal, $currency);
        $params['amount'] -= $stripeSubscriptionsTotal;

        $shipping = $this->getShippingAddressFrom($quote, $order);
        if ($shipping)
            $params['shipping'] = $shipping;
        else if (isset($params['shipping']))
            unset($params['shipping']);

        if ($order)
            $customerEmail = $order->getCustomerEmail();
        else
            $customerEmail = $quote->getCustomerEmail();

        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        if ($this->config->isLevel3DataEnabled())
        {
            $level3Data = $this->helper->getLevel3DataFrom($order);
            if ($level3Data)
                $params["level3"] = $level3Data;
        }

        return $params;
    }

    protected function getSubscriptionsAmount($quote, $order = null)
    {
        if ($order)
        {
            $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);
        }
        else
        {
            $subscription = $this->subscriptionsHelper->getSubscriptionFromQuote($quote);
        }

        $subscriptionsTotal = 0;
        if (!empty($subscription['profile']))
        {
            $subscriptionsTotal += $this->subscriptionsHelper->getSubscriptionTotalFromProfile($subscription['profile']);
        }

        return max(0, $subscriptionsTotal);
    }

    public function getClientSecret($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        if (empty($paymentIntent))
            return null;

        return $paymentIntent->client_secret;
    }

    public function getStatus()
    {
        if (empty($this->paymentIntent))
            return null;

        return $this->paymentIntent->status;
    }

    public function getPaymentIntentID()
    {
        if (empty($this->paymentIntent))
            return null;

        return $this->paymentIntent->id;
    }

    // Returns true if the payment intent:
    // a) is in a state that cannot be used for a purchase
    // b) a parameter that cannot be updated has changed
    public function isInvalid($params, $quote, $order, $paymentIntent)
    {
        if ($params['amount'] <= 0)
        {
            return true;
        }

        if (empty($paymentIntent))
        {
            return true;
        }

        if ($paymentIntent->status == $this::CANCELED)
        {
            return true;
        }

        // You cannot modify `customer` on a PaymentIntent once it already has been set. To fulfill a payment with a different Customer,
        // cancel this PaymentIntent and create a new one.
        if (!empty($paymentIntent->customer))
        {
            if (empty($params["customer"]) || $paymentIntent->customer != $params["customer"])
            {
                return true;
            }
        }

        // You passed an empty string for 'shipping'. We assume empty values are an attempt to unset a parameter; however 'shipping'
        // cannot be unset. You should remove 'shipping' from your request or supply a non-empty value.
        if (!empty($paymentIntent->shipping))
        {
            if (isset($params["shipping"]) && empty($params["shipping"]))
            {
                return true;
            }
        }

        // Case where the user navigates to the standard checkout, the PI is created,
        // and then the customer switches to multishipping checkout.
        if ($this->helper->isMultiShipping() || $this->checkoutFlow->isExpressCheckout)
        {
            if (!empty($paymentIntent->automatic_payment_methods))
            {
                return true;
            }
        }
        // ...and vice versa
        else
        {
            if (empty($paymentIntent->automatic_payment_methods))
            {
                return true;
            }
        }

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent) ||
            $this->paymentIntentHelper->isAsyncProcessing($paymentIntent) ||
            $this->paymentIntentHelper->requiresOfflineAction($paymentIntent)
            )
        {
            $expectedValues = [
                'amount' => $params['amount'],
                'currency' => $params['currency']
            ];

            if ($this->compare->isDifferent($paymentIntent, $expectedValues))
            {
                $this->helper->logError("PaymentIntent " . $paymentIntent->id . " was successful, but is in an invalid state: " . $this->compare->lastReason);
                return true;
            }
        }

        return false;
    }

    public function updateFrom($paymentIntent, $params, $quote, $order, $cache = true)
    {
        if (empty($quote))
            return null;

        if ($this->isDifferentFrom($paymentIntent, $params, $quote, $order))
        {
            $paymentIntent = $this->updateStripeObject($paymentIntent, $params);

            if ($cache)
                $this->updateModelFrom($quote, $paymentIntent, $order);
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function updateStripeObject($paymentIntent, $params)
    {
        $updateParams = $this->paymentIntentHelper->getFilteredParamsForUpdate($params, $paymentIntent);

        return $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);
    }

    public function destroy($paymentIntentToCancel = null)
    {
        if ($paymentIntentToCancel && $this->canCancel($paymentIntentToCancel))
        {
            $description = "The customer switched to a different payment flow.";
            $metadata = null;
            $this->config->getStripeClient()->paymentIntents->update($paymentIntentToCancel->id, [
                "description" => $description,
                "metadata" => $metadata
            ]);
            $paymentIntentToCancel->cancel();
        }

        $this->paymentIntent = null;
    }

    public function isDifferentFrom($paymentIntent, $params, $quote, $order = null)
    {
        $expectedValues = [];

        foreach ($this->paymentIntentHelper->getUpdateableParams($params, $paymentIntent) as $key)
        {
            if (empty($params[$key]))
                $expectedValues[$key] = "unset";
            else
                $expectedValues[$key] = $params[$key];
        }

        return $this->compare->isDifferent($paymentIntent, $expectedValues);
    }

    public function getShippingAddressFrom($quote, $order = null)
    {
        if ($order)
            $obj = $order;
        else if ($quote)
            $obj = $quote;
        else
            throw new GenericException("No quote or order specified");

        if (!$obj || $obj->getIsVirtual())
            return null;

        $address = $obj->getShippingAddress();

        if (empty($address))
            return null;

        // This is the case where we only have the quote
        if (empty($address->getFirstname()))
            $address = $this->addressFactory->create()->load($address->getAddressId());

        if (empty($address->getFirstname()))
            return null;

        return $this->addressHelper->getStripeShippingAddressFromMagentoAddress($address);
    }

    public function requiresAction($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        return (
            !empty($paymentIntent->status) &&
            $paymentIntent->status == $this::REQUIRES_ACTION
        );
    }

    public function confirm($paymentIntent, $confirmParams)
    {
        try
        {
            $this->paymentIntent = $paymentIntent;

            try
            {
                $result = $this->config->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $confirmParams);
                $this->stripePaymentIntent->fromObject($result);
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->dataHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $confirmParams);
                $this->stripePaymentIntent->fromObject($result);
            }

            if ($this->requiresAction($result))
                throw new SCANeededException("Authentication Required: " . $paymentIntent->client_secret);

            return $this->paymentIntent = $result;
        }
        catch (SCANeededException $e)
        {
            if ($this->helper->isAdmin())
                $this->helper->throwError(__("This payment method cannot be used because it requires a customer authentication. To avoid authentication in the admin area, please contact Stripe support to request access to the MOTO gate for your Stripe account."));

            if ($this->helper->isMultiShipping())
                throw $e;

            // Front-end case (Express Checkout API, REST API, GraphQL API), this will trigger the 3DS modal.
            $this->helper->throwError($e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->throwError($e->getMessage(), $e);
        }
    }

    public function setTransactionDetails(\Magento\Payment\Model\InfoInterface $payment, $intent)
    {
        $payment->setTransactionId($intent->id);
        $payment->setLastTransId($intent->id);
        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);

        if (!empty($intent->charges->data[0]))
        {
            $charge = $intent->charges->data[0];

            if ($this->config->isStripeRadarEnabled() &&
                isset($charge->outcome->type) &&
                $charge->outcome->type == 'manual_review')
            {
                $payment->setAdditionalInformation("stripe_outcome_type", $charge->outcome->type);
            }

            $payment->setIsTransactionPending(false);
            $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted

            if ($intent->charges->data[0]->captured == false)
                $payment->setIsTransactionClosed(false);
            else
                $payment->setIsTransactionClosed(true);
        }
        else
        {
            $payment->setIsTransactionPending(true);
            $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        }

        // Let's save the Stripe customer ID on the order's payment in case the customer registers after placing the order
        if (!empty($intent->customer))
            $payment->setAdditionalInformation("customer_stripe_id", $intent->customer);
    }

    public function processSuccessfulOrder($order, $intent)
    {
        $this->setTransactionDetails($order->getPayment(), $intent);

        $shouldCreateInvoice = $order->canInvoice() && $this->config->isAuthorizeOnly() && $this->config->isAutomaticInvoicingEnabled();

        if ($shouldCreateInvoice)
        {
            $invoice = $order->prepareInvoice();
            $invoice->setTransactionId($intent->id);
            $invoice->register();
            $order->addRelatedObject($invoice);
        }
    }

    public function processPendingOrder($order, $intent)
    {
        $payment = $order->getPayment();

        if (!empty($intent->customer))
            $payment->setAdditionalInformation("customer_stripe_id", $intent->customer);

        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true); // not authorized yet
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted

        if ($this->paymentIntentHelper->requiresOfflineAction($intent))
            $order->setCanSendNewEmailFlag(true);
        else
            $order->setCanSendNewEmailFlag(false);

        if (strpos($intent->id, "seti_") === 0 && in_array($intent->status, ['processing', 'succeeded']))
        {
            $payment->setTransactionId("cannot_capture_subscriptions");
        }
        else if (strpos($intent->id, "pi_") === 0)
        {
            $payment->setTransactionId($intent->id);
        }
    }

    public function processTrialSubscriptionOrder($order, $subscription)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation("customer_stripe_id", $subscription->customer);
        $payment->setAdditionalInformation("is_trial_subscription_setup", true);
        $payment->setTransactionId(null);
        $payment->setIsTransactionPending(false);
        $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted
        $payment->setIsTransactionClosed(true);
        $payment->setIsFraudDetected(false);
    }

    public function processFutureSubscriptionOrder($order, $customerId, $subscriptionId = null)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation("customer_stripe_id", $customerId);
        $payment->setAdditionalInformation("is_future_subscription_setup", true);
        if ($subscriptionId)
            $payment->setAdditionalInformation("subscription_id", $subscriptionId);
        $payment->setTransactionId(null);
        $payment->setIsTransactionPending(true);
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        $payment->setIsTransactionClosed(false);
        $payment->setIsFraudDetected(false);
    }

    public function updateData($paymentIntentId, $order)
    {
        $this->resourceModel->load($this, $paymentIntentId, 'pi_id');

        $this->setPiId($paymentIntentId);
        $this->setQuoteId($order->getQuoteId());
        $this->setOrderIncrementId($order->getIncrementId());
        $customerId = $order->getCustomerId();
        if (!empty($customerId))
            $this->setCustomerId($customerId);
        $this->setPmId($order->getPayment()->getAdditionalInformation("token"));
        $this->resourceModel->save($this);
    }
}
