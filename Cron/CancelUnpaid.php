<?php
/**
 * Cancel card orders that are still awaiting payment after the "Cancel Unpaid Orders After" setting
 * (hours, 0 = never). Only orders no payment callback has arrived for are cancelled.
 */
namespace PayGate\InstantPayment\Cron;

use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\StoresConfig;
use PayGate\InstantPayment\Model\Config;
use Psr\Log\LoggerInterface;

class CancelUnpaid
{
    /**
     * @var StoresConfig
     */
    private $storesConfig;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        StoresConfig $storesConfig,
        CollectionFactory $collectionFactory,
        OrderManagementInterface $orderManagement,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->storesConfig = $storesConfig;
        $this->collectionFactory = $collectionFactory;
        $this->orderManagement = $orderManagement;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @return void
     */
    public function execute()
    {
        foreach ($this->storesConfig->getStoresConfigByPath('payment/paygate_card/cancel_after') as $storeId => $hours) {
            $hours = (int) $hours;

            if ($hours <= 0) {
                continue;
            }

            $orders = $this->collectionFactory->create();
            $orders->addFieldToFilter('store_id', (int) $storeId);
            $orders->addFieldToFilter('status', Config::PENDING_STATUS);
            $orders->addFieldToFilter('created_at', ['lt' => gmdate('Y-m-d H:i:s', time() - $hours * 3600)]);
            $orders->setPageSize(200);

            foreach ($orders as $order) {
                $payment = $order->getPayment();

                if (!$payment || !Config::isOwnMethod($payment->getMethod()) || $payment->getAdditionalInformation('paygate_seen_txids')) {
                    continue;
                }

                try {
                    if ($this->orderManagement->cancel((int) $order->getId())) {
                        $order = $this->orderRepository->get((int) $order->getId());
                        $order->addCommentToStatusHistory((string) __('Cancelled automatically: no card payment was received within %1 hours.', $hours), false, false);
                        $this->orderRepository->save($order);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Order ' . $order->getIncrementId() . ': could not cancel the unpaid order: ' . $e->getMessage());
                }
            }
        }
    }
}
