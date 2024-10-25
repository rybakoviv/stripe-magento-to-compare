<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Payment\Observer\AbstractDataAssignObserver;

class QuoteObserver extends AbstractDataAssignObserver
{
    public $hasSubscriptions = null;

    private $config;
    private $taxCalculation;
    private $quoteHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Tax\Calculation $taxCalculation
    )
    {
        $this->quoteHelper = $quoteHelper;
        $this->config = $config;
        $this->taxCalculation = $taxCalculation;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();

        if (empty($quote) || !$this->config->isEnabled() || !$this->config->isSubscriptionsEnabled())
            return;

        if ($this->config->priceIncludesTax())
            return;

        $this->taxCalculation->method = null;

        if ($this->hasSubscriptions === null)
            $this->hasSubscriptions = $this->quoteHelper->hasSubscriptionsIn($quote->getAllItems());

        if ($this->hasSubscriptions)
        {
            $this->taxCalculation->method = \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
            return;
        }

        if ($quote->getPayment() && $quote->getPayment()->getMethod() == "stripe_payments_invoice")
        {
            $this->taxCalculation->method = \Magento\Tax\Model\Calculation::CALC_ROW_BASE;
            return;
        }
    }
}
