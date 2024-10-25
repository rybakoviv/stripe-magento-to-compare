<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\CronJob;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\StoresConfig;
use Magento\Sales\Model\Order;

class CleanExpiredOrders
{
    private $storesConfig;
    private $orderCollectionFactory;
    private $helper;
    private $paymentIntentHelper;
    private $config;
    private $tokenHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Model\Config $config,
        StoresConfig $storesConfig,
        CollectionFactory $collectionFactory
    ) {
        $this->helper = $helper;
        $this->tokenHelper = $tokenHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->config = $config;
        $this->storesConfig = $storesConfig;
        $this->orderCollectionFactory = $collectionFactory;
    }

    public function afterExecute($subject)
    {
        $lifetimes = $this->storesConfig->getStoresConfigByPath('sales/orders/delete_pending_after');
        foreach ($lifetimes as $storeId => $lifetime) {
            $orders = $this->orderCollectionFactory->create();
            $orders->addFieldToFilter('store_id', $storeId);
            $orders->addFieldToFilter('status', Order::STATE_PENDING_PAYMENT);

            $orders->getSelect()->where(
                new \Zend_Db_Expr('TIME_TO_SEC(TIMEDIFF(CURRENT_TIMESTAMP, `updated_at`)) >= ' . $lifetime * 60)
            );

            foreach ($orders as $order) {
                if (in_array($order->getPayment()->getMethod(), ['stripe_payments', 'stripe_payments_express']))
                {
                    try
                    {
                        if (!$this->isSuccessful($order))
                        {
                            $this->helper->cancelOrCloseOrder($order);
                        }
                    }
                    catch (\Exception $e)
                    {
                        // We could potentially hit the API rate limit here, so we'll process this order on the next run
                        continue;
                    }
                }
            }
        }
    }

    protected function isSuccessful($order)
    {
        if (!$order->getPayment() || !$order->getPayment()->getLastTransId())
        {
            return false;
        }

        $token = $order->getPayment()->getLastTransId();
        $token = $this->tokenHelper->cleanToken($token);
        if (strpos($token, "pi_") !== 0)
        {
            return false;
        }

        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), null);

        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($token);

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent))
        {
            return true;
        }

        if ($this->paymentIntentHelper->isAsyncProcessing($paymentIntent))
        {
            return true;
        }

        if ($this->paymentIntentHelper->requiresOfflineAction($paymentIntent))
        {
            return true;
        }

        return false;
    }
}
