<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Payment\State;

use Magento\Sales\Model\Order\StatusResolver;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;

class OrderCommand
{
    /**
     * @var StatusResolver
     */
    private $statusResolver;

    public function __construct(StatusResolver $statusResolver = null)
    {
        $this->statusResolver = $statusResolver
            ? : ObjectManager::getInstance()->get(StatusResolver::class);
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
        if ($payment->getIsTransactionPending())
        {
            if ($payment->getMethod() == "stripe_payments_bank_transfers")
            {
                $state = Order::STATE_PENDING_PAYMENT;
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                $message = __("The order is pending a bank transfer of %1 from the customer.");

                $order->setState($state);
                $order->setStatus($status);
                return __($message, $order->getBaseCurrency()->formatTxt($amount));
            }

            if ($payment->getMethod() == 'stripe_payments_checkout') {
                $state = Order::STATE_PENDING_PAYMENT;
                $status = $this->statusResolver->getOrderStatusByState($order, $state);
                $order->setState($state);
                $order->setStatus($status);
            }
        }

        return $result;
    }
}
