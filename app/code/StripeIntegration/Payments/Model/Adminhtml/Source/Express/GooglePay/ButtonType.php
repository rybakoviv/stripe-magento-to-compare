<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Express\GooglePay;

class ButtonType
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'book',
                'label' => __('Book')
            ],
            [
                'value' => 'buy',
                'label' => __('Buy')
            ],
            [
                'value' => 'checkout',
                'label' => __('Checkout')
            ],
            [
                'value' => 'continue',
                'label' => __('Continue')
            ],
            [
                'value' => 'donate',
                'label' => __('Donate')
            ],
            [
                'value' => 'order',
                'label' => __('Order')
            ],
            [
                'value' => 'pay',
                'label' => __('Pay')
            ],
            [
                'value' => 'plain',
                'label' => __('Plain')
            ],
            [
                'value' => 'subscribe',
                'label' => __('Subscribe')
            ]
        ];
    }
}
