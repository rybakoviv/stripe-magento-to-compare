<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Checkout;

class Flow
{
    public $isExpressCheckout = false;
    public $isFutureSubscriptionSetup = false;
    public $isPendingMicrodepositsVerification = false;
    public $creatingOrderFromCharge = null;
}