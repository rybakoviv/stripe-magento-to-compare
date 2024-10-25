<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\GenericException;

class Subscription extends \Magento\Framework\Model\AbstractModel
{
    private $config;
    private $quoteRepository;
    private $stripeCustomer;
    private $helper;
    private $subscriptionsHelper;
    private $subscriptionProductFactory;
    private $stripeSubscriptionFactory;
    private $stripeSubscriptionModel;
    private $dataHelper;
    private $subscriptionFactory;
    private $session;
    private $subscriptionReactivationFactory;
    private $resourceModel;
    private $quoteHelper;
    private $orderHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\SubscriptionReactivationFactory $subscriptionReactivationFactory,
        \Magento\Customer\Model\Session $session,
        \StripeIntegration\Payments\Model\ResourceModel\Subscription $resourceModel,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->config = $config;
        $this->quoteRepository = $quoteRepository;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);

        $this->helper = $helper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->dataHelper = $dataHelper;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->session = $session;
        $this->subscriptionReactivationFactory = $subscriptionReactivationFactory;
        $this->resourceModel = $resourceModel;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Subscription');
    }

    public function fromSubscriptionId($subscriptionId)
    {
        $this->resourceModel->load($this, $subscriptionId, "subscription_id");

        if (empty($this->getId()) || empty($this->getOrderIncrementId()))
        {
            $this->stripeSubscriptionModel = $this->stripeSubscriptionFactory->create();
            $this->stripeSubscriptionModel->setExpandParams(['plan.product']);
            $this->stripeSubscriptionModel->fromSubscriptionId($subscriptionId);
            $subscription = $this->stripeSubscriptionModel->getStripeObject();
            $this->initFrom($subscription);
            $this->resourceModel->save($this);
        }

        return $this;
    }

    protected function getStripeSubscriptionModel()
    {
        if (!$this->getSubscriptionId())
            throw new GenericException(__("The subscription could not be loaded."));

        if (!$this->stripeSubscriptionModel)
            $this->stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($this->getSubscriptionId());

        return $this->stripeSubscriptionModel;
    }

    public function initFrom($subscription, $order = null)
    {
        if (isset($subscription->plan->currency))
            $currency = $subscription->plan->currency;
        else if (isset($subscription->items->data[0]->plan->currency))
            $currency = $subscription->items->data[0]->plan->currency;
        else if ($order)
            $currency = strtolower($order->getOrderCurrencyCode());
        else
            $currency = "usd";

        if (!$order && !empty($subscription->metadata->{'Order #'}))
        {
            $order = $this->orderHelper->loadOrderByIncrementId($subscription->metadata->{'Order #'});
        }

        $data = [
            "created_at" => $subscription->created,
            "livemode" => $subscription->livemode,
            "subscription_id" => $subscription->id,
            "stripe_customer_id" => $subscription->customer,
            "payment_method_id" => $subscription->default_payment_method,
            "quantity" => $subscription->quantity,
            "currency" => $currency,
            "status" => $subscription->status,
            "name" => $this->subscriptionsHelper->generateSubscriptionName($subscription),
        ];

        $productIds = $this->subscriptionsHelper->getSubscriptionProductIDs($subscription);
        if (!empty($productIds))
            $data["product_id"] = array_shift($productIds);

        if ($order && $order->getId())
        {
            $data["store_id"] = $order->getStoreId();
            $data["order_increment_id"] = $order->getIncrementId();
            $data["magento_customer_id"] = $order->getCustomerId();
            $data["grand_total"] = $order->getGrandTotal();
        }

        $this->addData($data);

        return $this;
    }

    public function cancel($subscriptionId)
    {
        $this->config->getStripeClient()->subscriptions->cancel($subscriptionId, []);

        $this->resourceModel->load($this, $subscriptionId, "subscription_id");

        if ($this->getReorderFromQuoteId())
        {
            try
            {
                $quote = $this->quoteRepository->get($this->getReorderFromQuoteId());
                $quote->setIsUsedForRecurringOrders(false);
                $this->quoteRepository->save($quote);
            }
            catch (\Exception $e)
            {

            }
        }
    }

    public function reactivate()
    {
        try {
            if (!$this->getId())
                throw new GenericException(__("The subscription could not be loaded."));

            $subscription = $this->getStripeSubscriptionModel()->getStripeObject();

            $params['customer'] = $subscription->customer;
            $params['items'] = [];

            if (isset($subscription->items) && isset($subscription->items->data)) {
                foreach ($subscription->items->data as $subItems) {

                    $subItemData = [];
                    $subItemData['price'] = $subItems->price->id;
                    $subItemData['quantity'] = $subItems->quantity;
                    $subItemData['metadata'] = json_decode(json_encode($subItems->metadata), true);
                    $params['items'][] = $subItemData;
                }
            }

            $params['metadata'] = json_decode(json_encode($subscription->metadata), true);
            $params['description'] = $subscription->description?: "Subscription";
            $params['currency'] = $subscription->currency;
            $params['collection_method'] = $subscription->collection_method;

            if (is_numeric($subscription->trial_end) && $subscription->trial_end > time())
            {
                $params['trial_end'] = $subscription->trial_end;
            }

            $nextStartDate = $this->getNextStartDate($subscription->current_period_end);

            if ($nextStartDate && empty($params['trial_end'])) {
                $params['billing_cycle_anchor'] = $nextStartDate;
                $params['proration_behavior'] = 'none';
            }

            if (isset($subscription->payment_settings) && isset($subscription->payment_settings->save_default_payment_method)) {
                $params['payment_settings']['save_default_payment_method'] = $subscription->payment_settings->save_default_payment_method;
            }

            $reactivationModel = $this->subscriptionReactivationFactory->create();
            $reactivationModel->load($this->getOrderIncrementId(), 'order_increment_id');
            $reactivationModel->setOrderIncrementId($this->getOrderIncrementId());
            $reactivationModel->setReactivatedAt(date('Y-m-d H:i:s'));
            $reactivationModel->save();

            if ($this->paymentMethodDeleted())
            {
                return $this->reactivateWithNewPaymentMethod($subscription, $params);
            }
            else
            {
                $params['default_payment_method'] = $this->getPaymentMethodId();
            }

            $reactivatedSubscription = $this->config->getStripeClient()->subscriptions->create($params);
            $this->setStatus('reactivated');
            $this->resourceModel->save($this);

            $subscriptionData = [
                'store_id' => ($this->getStoreId() ?? $this->helper->getStoreId()),
                'livemode' => $reactivatedSubscription->livemode,
                'subscription_id' => $reactivatedSubscription->id,
                'order_increment_id' => ($this->getOrderIncrementId() ?? $reactivatedSubscription->metadata->{'Order #'}),
                'magento_customer_id' => ($this->getMagentoCustomerId() ?? $this->session->getId()),
                'stripe_customer_id' => ($this->getStripeCustomerId() ?? $reactivatedSubscription->customer),
                'payment_method_id' => ($this->getPaymentMethodId() ?? $reactivatedSubscription->default_payment_method),
                'quantity' => $reactivatedSubscription->quantity,
                'currency' => $reactivatedSubscription->currency,
                'status' => $reactivatedSubscription->status
            ];
            $this->subscriptionFactory->create($subscriptionData)->save();

            $this->helper->addSuccess(__("The subscription has been reactivated."));

            return 'stripe/customer/subscriptions';
        } catch (\Exception $e) {
            $this->helper->logError("Unable to reactivate the subscription: " . $e->getMessage(), $e->getTraceAsString());
            throw new GenericException(__("Sorry, unable to reactivate the subscription."));
        }
    }

    protected function getNextStartDate($currentPeriodEnd)
    {
        $activationTime = time();
        $nextBillingDate = '';

        if ($activationTime <= $currentPeriodEnd) {
            $nextBillingDate = $currentPeriodEnd;
        }

        return $nextBillingDate;
    }

    protected function setSubscriptionReactivateDetails($subscription, $createSubParams)
    {
        $checkoutSession = $this->helper->getCheckoutSession();
        $checkoutSession->setSubscriptionReactivateDetails([
            "update_subscription_id" => $subscription->id,
            "success_url" => $this->helper->getUrl("stripe/customer/subscriptions", ["updateSuccess" => 1]),
            "subscription_data" => $createSubParams
        ]);
    }

    protected function reactivateWithNewPaymentMethod($subscription, $createSubParams)
    {
        try
        {
            $orderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);
            if (!$orderIncrementId)
                throw new LocalizedException(__("This subscription is not associated with an order."));

            $order = $this->orderHelper->loadOrderByIncrementId($orderIncrementId);

            if (!$order)
                throw new LocalizedException(__("Could not load order for this subscription."));

            $quote = $this->quoteHelper->getQuote();
            $quote->removeAllItems();
            $quote->removeAllAddresses();
            $extensionAttributes = $quote->getExtensionAttributes();
            $extensionAttributes->setShippingAssignments([]);

            $productIds = $this->subscriptionsHelper->getSubscriptionProductIDs($subscription);
            $items = $order->getItemsCollection();
            foreach ($items as $item)
            {
                $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);

                if ($subscriptionProductModel->isSubscriptionProduct() &&
                    $subscriptionProductModel->getProduct() &&
                    $subscriptionProductModel->getProduct()->isSaleable() &&
                    in_array($subscriptionProductModel->getProduct()->getId(), $productIds)
                )
                {
                    $product = $subscriptionProductModel->getProduct();

                    if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
                    {
                        $item = $item->getParentItem();
                        $product = $this->helper->loadProductById($item->getProductId());

                        if (!$product || !$product->isSaleable())
                            continue;
                    }

                    $request = $this->dataHelper->getBuyRequest($item);
                    $result = $quote->addProduct($product, $request);
                    if (is_string($result))
                        throw new LocalizedException(__($result));
                }
            }

            if (!$quote->hasItems())
                throw new LocalizedException(__("Sorry, this subscription product is currently unavailable."));

            $this->setSubscriptionReactivateDetails($subscription, $createSubParams);

            $quote->getShippingAddress()->setCollectShippingRates(false);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->quoteHelper->saveQuote($quote);
            try
            {
                if (!$order->getIsVirtual() && !$quote->getIsVirtual() && $order->getShippingMethod())
                {
                    $shippingAddress = $quote->getShippingAddress();
                    $shippingAddress->addData($order->getShippingAddress()->getData());
                    $shippingAddress->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod($order->getShippingMethod())
                        ->save();
                }
            }
            catch (\Exception $e)
            {
                // The shipping address or method may not be available, ignore in this case
            }

            return 'checkout';
        }
        catch (LocalizedException $e)
        {
            throw new LocalizedException(__("Sorry, unable to reactivate the subscription."));
        }
        catch (\Exception $e)
        {
            throw new GenericException(__("Sorry, the subscription could not be reactivated. Please contact us for more help."));
        }
    }

    public function paymentMethodDeleted()
    {
        $savedPaymentMethods = $this->stripeCustomer->getSavedPaymentMethods(\StripeIntegration\Payments\Helper\PaymentMethod::SUPPORTS_SUBSCRIPTIONS, true);
        $savedPaymentMethodsArray = [];
        foreach ($savedPaymentMethods as $savedPaymentMethod)
        {
            $savedPaymentMethodsArray[$savedPaymentMethod['id']] = $savedPaymentMethod['id'];
        }

        return !in_array($this->getPaymentMethodId(), $savedPaymentMethodsArray);
    }
}
