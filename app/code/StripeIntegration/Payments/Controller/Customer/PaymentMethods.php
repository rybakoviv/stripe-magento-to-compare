<?php

namespace StripeIntegration\Payments\Controller\Customer;

use Magento\Customer\Model\Session;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;
use StripeIntegration\Payments\Helper\Generic;

class PaymentMethods implements ActionInterface
{
    private $orderCollectionFactory;
    private $resultFactory;
    private $resultPageFactory;
    private $helper;
    private $stripeCustomer;
    private $customerSession;
    private $request;
    private $resource;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Framework\App\ResourceConnection $resource,
        PageFactory $resultPageFactory,
        Session $session,
        Generic $helper,
        ResultFactory $resultFactory,
        RequestInterface $request
    )
    {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->resource = $resource;
        $this->resultPageFactory = $resultPageFactory;
        $this->helper = $helper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->customerSession = $session;
        $this->resultFactory = $resultFactory;
        $this->request = $request;
    }

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->redirect('customer/account/login');
        }

        $params = $this->request->getParams();

        if (isset($params['delete']))
            return $this->delete($params['delete'], $this->request->getParam("fingerprint", null));
        else if (isset($params['redirect_status']))
            return $this->outcome($params['redirect_status'], $params);

        return $this->resultPageFactory->create();
    }

    public function outcome($code, $params)
    {
        if ($code == "succeeded")
            $this->helper->addSuccess(__("The payment method has been successfully added."));

        return $this->redirect('stripe/customer/paymentmethods');
    }

    private function getCustomerOrders($customerId, $statuses = [], $paymentMethodId = null)
    {
        $collection = $this->orderCollectionFactory->create($customerId)
            ->addAttributeToSelect('*')
            ->join(
                ['pi' => $this->resource->getTableName('stripe_payment_intents')],
                'main_table.customer_id = pi.customer_id and main_table.increment_id = pi.order_increment_id',
                []
            )
            ->setOrder(
                'created_at',
                'desc'
            );

        if (!empty($statuses))
            $collection->addFieldToFilter('main_table.status', ['in' => $statuses]);

        if (!empty($paymentMethodId))
            $collection->addFieldToFilter('pi.pm_id', ['eq' => $paymentMethodId]);

        return $collection;
    }

    public function delete($token, $fingerprint = null)
    {
        try
        {
            $customerId = $this->customerSession->getCustomer()->getId();
            $statuses = ['processing', 'fraud', 'pending_payment', 'payment_review', 'pending', 'holded'];
            $orders = $this->getCustomerOrders($customerId, $statuses, $token);
            foreach ($orders as $order)
            {
                $message = __("Sorry, it is not possible to delete this payment method because order #%1 which was placed using it is still being processed.", $order->getIncrementId());
                $this->helper->addError($message);

                return $this->redirect('stripe/customer/paymentmethods');
            }

            $card = $this->stripeCustomer->deletePaymentMethod($token, $fingerprint);

            // In case we deleted a source
            if (isset($card->card))
                $card = $card->card;

            if (!empty($card->last4))
                $this->helper->addSuccess(__("Card •••• %1 has been deleted.", $card->last4));
            else
                $this->helper->addSuccess(__("The payment method has been deleted."));
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $this->helper->addError($e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
        }

        return $this->redirect('stripe/customer/paymentmethods');
    }

    public function redirect($url)
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath($url);

        return $redirect;
    }
}
