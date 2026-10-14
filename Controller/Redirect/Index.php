<?php
/**
 * Right after "Place Order": send the customer to the PayGate checkout page created for the order.
 */
namespace PayGate\InstantPayment\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use PayGate\InstantPayment\Model\Config;

class Index implements HttpGetActionInterface
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    public function __construct(CheckoutSession $checkoutSession, RedirectFactory $redirectFactory, ManagerInterface $messageManager)
    {
        $this->checkoutSession = $checkoutSession;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $result = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();
        $payment = $order && $order->getId() ? $order->getPayment() : null;
        $url = $payment ? (string) $payment->getAdditionalInformation('paygate_redirect_url') : '';

        // Already paid (e.g. the customer came back with the browser's back button).
        if ($payment && Config::isOwnMethod($payment->getMethod()) && in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            return $result->setPath('checkout/onepage/success');
        }

        if (!$payment || !Config::isOwnMethod($payment->getMethod()) || $order->getState() !== Order::STATE_PENDING_PAYMENT || !preg_match('#^https://[^\s"\'<>]+$#', $url)) {
            $this->messageManager->addErrorMessage(__('We could not find a card payment waiting for this order. Please contact us if you were charged.'));

            return $result->setPath('checkout/cart');
        }

        return $result->setUrl($url);
    }
}
