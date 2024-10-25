<?php

namespace StripeIntegration\Payments\Model\Stripe;

class PaymentIntent
{
    use StripeObjectTrait;

    private $objectSpace = 'paymentIntents';
    private $tokenHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Service\StripeObjectServicePool $stripeObjectServicePool,
        \StripeIntegration\Payments\Helper\Token $tokenHelper
    )
    {
        $stripeObjectService = $stripeObjectServicePool->getStripeObjectService($this->objectSpace);
        $this->setData($stripeObjectService);

        $this->tokenHelper = $tokenHelper;
    }

    public function fromPaymentIntentId($id, $expandParams = [])
    {
        $id = $this->tokenHelper->cleanToken($id);

        if (!empty($this->getStripeObject()->id) && $this->getStripeObject()->id == $id)
        {
            return $this;
        }

        $this->setExpandParams($expandParams);
        $this->load($id);
        return $this;
    }

    public function fromObject(\Stripe\PaymentIntent $paymentIntent)
    {
        $this->setObject($paymentIntent);
        return $this;
    }

    public function setExpandParams($params)
    {
        $this->stripeObjectService->setExpandParams($params);

        return $this;
    }
}