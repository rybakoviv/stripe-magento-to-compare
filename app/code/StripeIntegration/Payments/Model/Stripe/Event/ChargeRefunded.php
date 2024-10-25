<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Model\Stripe\StripeObjectTrait;

class ChargeRefunded
{
    use StripeObjectTrait;

    private $creditmemoHelper;
    private $webhooksHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService('events');
        $this->setData($stripeObjectService);

        $this->creditmemoHelper = $creditmemoHelper;
        $this->webhooksHelper = $webhooksHelper;
    }
    public function process($arrEvent, $object)
    {
        if ($this->webhooksHelper->wasRefundedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $result = $this->creditmemoHelper->refundFromStripeDashboard($order, $object);
    }
}