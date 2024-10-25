<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Payment\Observer\AbstractDataAssignObserver;

class WebhooksConfigurationObserver extends AbstractDataAssignObserver
{
    private $webhooksSetup;

    public function __construct(
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup
    )
    {
        $this->webhooksSetup = $webhooksSetup;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $event = $observer->getEvent();
        $this->webhooksSetup->onWebhookCreated($event);
    }
}
