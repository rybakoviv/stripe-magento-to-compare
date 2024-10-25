<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;

class SubscriptionProduct
{
    private $product = null;
    private $subscriptionDetails = null;

    private $helper;
    protected $subscriptionHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionHelper
    )
    {
        $this->helper = $helper;
        $this->subscriptionHelper = $subscriptionHelper;
    }

    public function fromQuoteItem($item)
    {
        if (empty($item) || !$item->getProduct())
            throw new InvalidSubscriptionProduct("Invalid quote item.");
        $product = $this->helper->loadProductById($item->getProduct()->getId());
        if ($this->_isSubscriptionProduct($product))
        {
            $this->product = $product;
            $this->subscriptionDetails = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());
        }

        return $this;
    }

    public function fromOrderItem($orderItem)
    {
        if (empty($orderItem) || !$orderItem->getProductId())
            throw new InvalidSubscriptionProduct("Invalid order item.");
        $product = $this->helper->loadProductById($orderItem->getProductId());
        if ($this->_isSubscriptionProduct($product))
        {
            $this->product = $product;
            $this->subscriptionDetails = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());
        }

        return $this;
    }

    public function fromProductId($productId)
    {
        if (empty($productId))
            throw new InvalidSubscriptionProduct("Invalid product ID.");

        $product = $this->helper->loadProductById($productId);
        if ($this->_isSubscriptionProduct($product))
        {
            $this->product = $product;
            $this->subscriptionDetails = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());
        }

        return $this;
    }

    public function getIsSaleable()
    {
        return $this->product && $this->product->getIsSalable();
    }

    public function hasStartDate()
    {
        if (!$this->product)
            return false;

        $subscriptionOptions = $this->subscriptionDetails;

        if (!$subscriptionOptions ||
            empty($subscriptionOptions->getStartOnSpecificDate()) ||
            empty($subscriptionOptions->getStartDate()) ||
            !is_string($subscriptionOptions->getStartDate()) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $subscriptionOptions->getStartDate()))
        {
            return false;
        }

        return true;
    }

    public function startsOnOrderDate()
    {
        return $this->hasStartDate() && $this->subscriptionDetails->getFirstPayment() == "on_order_date";
    }

    public function getProduct()
    {
        return $this->product;
    }

    public function getProductId()
    {
        if (!$this->product)
            return null;

        return $this->product->getId();
    }

    public function getTrialDays()
    {
        $product = $this->product;

        if (!$product)
            return null;

        if (!$this->subscriptionDetails)
            return null;

        if ($this->hasStartDate())
            return null;

        $trialDays = $this->subscriptionDetails->getSubTrial();
        if (!$trialDays || !is_numeric($trialDays) || $trialDays < 1)
            return null;

        return $trialDays;
    }

    public function hasTrialPeriod()
    {
        $trialDays = $this->getTrialDays();
        if (!is_numeric($trialDays))
            return false;

        return true;
    }

    public function canChangeShipping()
    {
        if ($this->product && $this->product->getTypeId() == "simple")
        {
            return true;
        }

        return false;
    }

    public function isSubscriptionProduct()
    {
        return !empty($this->product);
    }

    private function _isSubscriptionProduct(
        \Magento\Catalog\Api\Data\ProductInterface $product
    )
    {
        if (!$product || !$product->getId())
            return false;

        if (!in_array($product->getTypeId(), ["simple", "virtual"]))
            return false;

        $subscriptionOptionDetails = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());

        if (!$subscriptionOptionDetails || !$subscriptionOptionDetails->getSubEnabled()) {
            return false;
        }

        $interval = $subscriptionOptionDetails->getSubInterval();
        $intervalCount = (int)$subscriptionOptionDetails->getSubIntervalCount();

        if (!$interval)
            return false;

        if ($intervalCount && !is_numeric($intervalCount))
            return false;

        if ($intervalCount < 0)
            return false;

        return true;
    }

    public function isSimpleProduct()
    {
        $product = $this->product;

        if (!$product || !$product->getId())
        {
            return false;
        }

        if ($product->getTypeId() != "simple")
        {
            return false;
        }

        return true;
    }

    public function isVirtualProduct()
    {
        $product = $this->product;

        if (!$product || !$product->getId())
        {
            return false;
        }

        if ($product->getTypeId() != "virtual")
        {
            return false;
        }

        return true;
    }

    public function getSubscriptionDetails()
    {
        return $this->subscriptionDetails;
    }

    public function canChangeSubscription()
    {
        return ($this->subscriptionDetails && $this->subscriptionDetails->getUpgradesDowngrades());
    }

    public function useProrationsForUpgrades()
    {
        if (!$this->canChangeSubscription())
            return false;

        return ($this->subscriptionDetails && $this->subscriptionDetails->getProrateUpgrades());
    }

    public function useProrationsForDowngrades()
    {
        if (!$this->canChangeSubscription())
            return false;

        return ($this->subscriptionDetails && $this->subscriptionDetails->getProrateDowngrades());
    }
}
