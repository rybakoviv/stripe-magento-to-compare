<?php

namespace StripeIntegration\Payments\Test\Integration\Mock\StripeIntegration\Payments\Model\Stripe\Event;

class InvoiceUpcoming extends \StripeIntegration\Payments\Model\Stripe\Event\InvoiceUpcoming
{
    public static $newTaxPercent = null;

    protected function getNewTaxPercent($quote, $originalOrderItem)
    {
        if (self::$newTaxPercent !== null)
            return self::$newTaxPercent;

        return parent::getNewTaxPercent($quote, $originalOrderItem);
    }
}