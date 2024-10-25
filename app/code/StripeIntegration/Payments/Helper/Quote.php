<?php

namespace StripeIntegration\Payments\Helper;

class Quote
{
    // $quoteId is set right before the order is placed from inside Plugin/Sales/Model/Service/OrderService,
    // as the GraphQL flow may place an order without a loaded quote. Used for loading the quote later.
    public $quoteId = null;

    private $quotesCache = [];

    private $backendSessionQuote;
    private $checkoutSession;
    private $quoteRepository;
    private $areaCodeHelper;
    private $productHelper;
    private $subscriptionProductFactory;
    private $quoteFactory;

    public function __construct(
        \Magento\Backend\Model\Session\Quote $backendSessionQuote,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Product $productHelper,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory
    )
    {
        $this->backendSessionQuote = $backendSessionQuote;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->productHelper = $productHelper;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->quoteFactory = $quoteFactory;
    }

    // This method is not inside the subscriptions helper to avoid circular dependencies between Model/Config and other classes.
    public function hasSubscriptions(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $quoteId = $quote->getId();

        if ($quoteId)
        {
            if (isset($this->quotesCache[$quoteId]))
            {
                if ($this->quotesCache[$quoteId]->getHasSubscriptions() !== null)
                {
                    return $this->quotesCache[$quoteId]->getHasSubscriptions();
                }
            }
            else
            {
                $this->quotesCache[$quoteId] = $quote;
            }
        }

        $items = $quote->getAllItems();
        $hasSubscriptions = $this->hasSubscriptionsIn($items);
        $quote->setHasSubscriptions($hasSubscriptions);

        return $hasSubscriptions;
    }

    public function hasSubscriptionsIn($quoteItems)
    {
        foreach ($quoteItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct())
            {
                return true;
            }
        }

        return false;
    }

    public function getQuote($quoteId = null): \Magento\Quote\Api\Data\CartInterface
    {
        // Admin area new order page
        if ($this->areaCodeHelper->isAdmin())
            return $this->getBackendSessionQuote();

        // Front end checkout
        $quote = $this->getSessionQuote();

        // API Request
        if (empty($quote) || !is_numeric($quote->getGrandTotal()))
        {
            try
            {
                if ($quoteId)
                    $quote = $this->quoteRepository->get($quoteId);
                else if ($this->quoteId) {
                    $quote = $this->quoteRepository->get($this->quoteId);
                }
            }
            catch (\Exception $e)
            {

            }
        }

        return $quote;
    }

    public function getQuoteDescription($quote)
    {
        if ($quote->getCustomerIsGuest())
            $customerName = $quote->getBillingAddress()->getName();
        else
            $customerName = $quote->getCustomerName();

        if (!empty($customerName))
            $description = __("Cart %1 by %2", $quote->getId(), $customerName);
        else
            $description = __("Cart %1", $quote->getId());

        return $description;
    }

    public function loadQuoteById($quoteId)
    {
        if (!is_numeric($quoteId))
            return null;

        if (!empty($this->quotesCache[$quoteId]))
            return $this->quotesCache[$quoteId];

        $this->quotesCache[$quoteId] = $this->quoteFactory->create()->load($quoteId);

        return $this->quotesCache[$quoteId];
    }

    private function getBackendSessionQuote()
    {
        return $this->backendSessionQuote->getQuote();
    }

    private function getSessionQuote()
    {
        return $this->checkoutSession->getQuote();
    }

    public function saveQuote($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $this->quoteRepository->save($quote);

        return $quote;
    }

    /**
     * Add product to shopping cart (quote)
     */
    public function addProduct($productId, array $requestInfo = null)
    {
        if (!$productId)
            throw new \Magento\Framework\Exception\LocalizedException(__('The product does not exist.'));

        $request = new \Magento\Framework\DataObject($requestInfo);
        try
        {
            $product = $this->productHelper->getProduct($productId);
            $result = $this->getQuote()->addProduct($product, $request);
        }
        catch (\Magento\Framework\Exception\LocalizedException $e)
        {
            $this->checkoutSession->setUseNotice(false);
            $result = $e->getMessage();
        }
        /**
         * String we can get if prepare process has error
         */
        if (is_string($result)) {
            throw new \Magento\Framework\Exception\LocalizedException(__($result));
        }

        $this->checkoutSession->setLastAddedProductId($productId);
        return $result;
    }

    public function removeItem($itemId)
    {
        $item = $this->getQuote()->removeItem($itemId);

        if ($item->getHasError()) {
            throw new \Magento\Framework\Exception\LocalizedException(__($item->getMessage()));
        }

        return $this;
    }

    public function isProductInCart($productId)
    {
        $quote = $this->getQuote();
        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            if ($item->getProductId() == $productId)
                return true;
        }

        return false;
    }

    /**
     * Adding products to cart by ids
     */
    public function addProductsByIds(array $productIds)
    {
        foreach ($productIds as $productId) {
            $this->addProduct($productId);
        }

        return $this;
    }

    public function isMultiShipping($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        return $quote->getIsMultiShipping();
    }

    public function clearCache()
    {
        $this->quotesCache = [];
    }

    public function reloadQuote($quote)
    {
        $quote = $this->quoteRepository->get($quote->getId());
        $this->quotesCache[$quote->getId()] = $quote;
        return $quote;
    }

    public function reCalculateQuoteTotals($quote)
    {
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->saveQuote($quote);
        return $quote;
    }

    public function hasSubscriptionsWithStartDate($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() &&
                $subscriptionProductModel->hasStartDate()
            )
            {
                return true;
            }
        }

        return false;
    }

    public function hasTrialSubscriptionsIn($quoteItems)
    {
        foreach ($quoteItems as $item)
        {
            $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromQuoteItem($item);
            if ($subscriptionProductModel->isSubscriptionProduct() && $subscriptionProductModel->hasTrialPeriod())
            {
                return true;
            }
        }

        return false;
    }
}
