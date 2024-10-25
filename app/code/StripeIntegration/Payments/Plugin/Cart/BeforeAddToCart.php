<?php

namespace StripeIntegration\Payments\Plugin\Cart;

class BeforeAddToCart
{
    private $messageManager;
    private $config;
    private $subscriptions;
    private $configurableProductFactory;

    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\ConfigurableProduct\Model\Product\Type\ConfigurableFactory $configurableProductFactory
    )
    {
        $this->messageManager = $messageManager;
        $this->config = $config;
        $this->subscriptions = $subscriptions;
        $this->configurableProductFactory = $configurableProductFactory;
    }

    public function beforeAddProduct(
        \Magento\Quote\Model\Quote $quote,
        \Magento\Catalog\Model\Product $product,
        $request = null,
        $processMode = \Magento\Catalog\Model\Product\Type\AbstractType::PROCESS_MODE_FULL
    )
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        $product = $this->getProductFromRequest($product, $request);


        if (!$this->subscriptions->isSubscriptionProduct($product))
            return;

        $itemsRemoved = false;
        foreach($quote->getAllItems() as $item)
        {
            if ($this->subscriptions->isSubscriptionProduct($item->getProduct()))
            {
                $quote->removeItem($item->getId());
                $itemsRemoved = true;
            }
        }

        if ($itemsRemoved)
        {
            $this->messageManager->addNoticeMessage(__('You can only purchase one subscription at a time.'));
        }

        return null;
    }

    protected function getProductFromRequest($addProduct, $request)
    {
        if (empty($request) || is_numeric($request))
        {
            return $addProduct;
        }

        if ($addProduct->getTypeId() != 'configurable')
        {
            return $addProduct;
        }

        $attributes = $request->getSuperAttribute();
        if (empty($attributes))
        {
            return $addProduct;
        }

        $product = $this->configurableProductFactory->create()->getProductByAttributes($attributes, $addProduct);
        if ($product)
        {
            return $product;
        }

        return $addProduct;
    }
}
