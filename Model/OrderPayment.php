<?php
/**
 * Order changes made when PayGate confirms a payment.
 *
 *  - paid:      the order is invoiced (captured offline, the money is already in your wallet), moved to
 *               Processing, and the order and invoice e-mails are sent;
 *  - underpaid: the order is put On Hold for you to review (nothing is cancelled automatically);
 *  - otherwise: a private note is added and nothing else changes (e.g. the order was cancelled).
 */
namespace PayGate\InstantPayment\Model;

use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class OrderPayment
{
    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderSender
     */
    private $orderSender;

    /**
     * @var InvoiceSender
     */
    private $invoiceSender;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        LoggerInterface $logger
    ) {
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->logger = $logger;
    }

    /**
     * Invoice the order and move it to Processing.
     *
     * @param Order $order
     * @param string $transactionId
     * @param string $comment
     * @return bool false when the order could not be invoiced (a note is added instead)
     */
    public function markPaid(Order $order, $transactionId, $comment)
    {
        if ($order->getState() === Order::STATE_HOLDED && $order->canUnhold()) {
            $order->unhold();
        }

        if (!$order->canInvoice()) {
            $this->addNote($order, $comment . ' ' . __('[Notice] The order could not be invoiced automatically (state: %1); please review it.', $order->getState()));

            return false;
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);

        if ($transactionId !== '') {
            $invoice->setTransactionId($transactionId);
            $order->getPayment()->setLastTransId($transactionId);
        }

        $invoice->register();

        $order->setState(Order::STATE_PROCESSING)
            ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        $order->addCommentToStatusHistory($comment, false, false);

        $this->transactionFactory->create()
            ->addObject($invoice)
            ->addObject($order)
            ->save();

        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }

            $this->invoiceSender->send($invoice);
        } catch (\Exception $e) {
            $this->logger->warning('Order ' . $order->getIncrementId() . ': payment recorded but an e-mail could not be sent: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Put the order On Hold with an explanation.
     *
     * @param Order $order
     * @param string $comment
     * @return void
     */
    public function holdForReview(Order $order, $comment)
    {
        if ($order->canHold()) {
            $order->hold();
        }

        $this->addNote($order, $comment);
    }

    /**
     * Add a private note to the order history and save the order.
     *
     * @param Order $order
     * @param string $comment
     * @return void
     */
    public function addNote(Order $order, $comment)
    {
        $order->addCommentToStatusHistory($comment, false, false);
        $this->orderRepository->save($order);
    }
}
