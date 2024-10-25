<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Payment\State;

use Magento\Sales\Model\Order\StatusResolver;
use Magento\Framework\App\ObjectManager;
use StripeIntegration\Payments\Model\Checkout\Flow;

class AuthorizeCaptureCommand
{
    private $statusResolver;
    private $checkoutFlow;

    public function __construct(
        Flow $checkoutFlow,
        StatusResolver $statusResolver = null
    )
    {
        $this->statusResolver = $statusResolver ? : ObjectManager::getInstance()->get(StatusResolver::class);
        $this->checkoutFlow = $checkoutFlow;
    }

    /**
     * After execute method of OrderCommand
     *
     * @param mixed $subject
     * @param string $result
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @param $amount
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return string
     */
    public function afterExecute(
        $subject,
        $result,
        $payment,
        $amount,
        $order
    ) {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        if ($payment->getMethod() == "stripe_payments")
        {
            if ($payment->getIsTransactionPending())
            {
                $state = 'pending_payment';
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                if ($this->checkoutFlow->isPendingMicrodepositsVerification)
                {
                    $message = __("The payment method is pending microdeposits verification by the customer.");
                }
                else if ($payment->getAdditionalInformation("is_future_subscription_setup"))
                {
                    $message = __("A subscription with a future start date has been created.");
                }
                else if ($payment->getAdditionalInformation("is_migrated_subscription"))
                {
                    $message = __("Order created via subscriptions CLI migration tool.");
                }
                else if ($payment->getAdditionalInformation("is_subscription_update"))
                {
                    $originalOrderIncrementId = $payment->getAdditionalInformation("original_order_increment_id");
                    $message = __("This order was created as part of a requested subscription change by the customer. No payment has been collected. Original order number #%1", $originalOrderIncrementId);
                }
                else
                {
                    $message = __("The customer's bank requested customer authentication. Beginning the authentication process.");
                }

                $order->setState($state);
                $order->setStatus($status);
                return __($message, $order->getBaseCurrency()->formatTxt($amount));
            }

            /** @var \Magento\Sales\Model\Order\Payment $payment */
            if ($payment->getAdditionalInformation("is_trial_subscription_setup"))
            {
                $state = 'processing';
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                $message = __("A trialing subscription has been set up.");
                $order->setState($state);
                $order->setStatus($status);
                return __($message, $order->getBaseCurrency()->formatTxt($amount));
            }
        }

        return $result;
    }
}
