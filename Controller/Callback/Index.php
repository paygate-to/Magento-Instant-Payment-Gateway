<?php
/**
 * Payment confirmation from PayGate: GET {store}/paygate/callback/index?order=...&nonce=...&value_coin=...
 *
 * Order of checks: the order's nonce, then PayGate's RSA signature over the exact callback URL, and
 * only then any payment value. Every parameter is read from the signed query string itself.
 * value_coin is the USD value received (USDC/USDT); payouts sent in POL, ETH or BNB are valued at
 * the current USD price. Answers plain "ok" once handled so PayGate stops retrying.
 */
namespace PayGate\InstantPayment\Controller\Callback;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PayGate\InstantPayment\Model\Api;
use PayGate\InstantPayment\Model\Config;
use PayGate\InstantPayment\Model\OrderPayment;
use PayGate\InstantPayment\Model\Signature;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    /** Payout coins that are not USD stablecoins and need a USD price. */
    const PRICED_COINS = ['polygon_pol', 'eth', 'bep20_bnb'];

    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @var RawFactory
     */
    private $rawFactory;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @var Signature
     */
    private $signature;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var OrderPayment
     */
    private $orderPayment;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        HttpRequest $request,
        RawFactory $rawFactory,
        OrderFactory $orderFactory,
        OrderCollectionFactory $orderCollectionFactory,
        LockManagerInterface $lockManager,
        Signature $signature,
        Api $api,
        OrderPayment $orderPayment,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->rawFactory = $rawFactory;
        $this->orderFactory = $orderFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->lockManager = $lockManager;
        $this->signature = $signature;
        $this->api = $api;
        $this->orderPayment = $orderPayment;
        $this->logger = $logger;
    }

    /**
     * @return Raw
     */
    public function execute()
    {
        try {
            return $this->handle();
        } catch (\Exception $e) {
            $this->logger->error('Callback error: ' . $e->getMessage());

            return $this->reply(500, 'Error, please retry');
        }
    }

    /**
     * @return Raw
     */
    private function handle()
    {
        $params = Signature::callbackParams($this->request);
        $incrementId = $this->param($params, 'order');
        $nonce = $this->param($params, 'nonce');

        if ($incrementId === '' || !preg_match('/^[\x21-\x7E]{1,50}$/', $incrementId)) {
            return $this->reply(400, 'Missing order');
        }

        $order = $this->findOrder($incrementId, $nonce);

        if ($order === null) {
            return $this->reply(404, 'Unknown order');
        }

        if ($order === false) {
            return $this->reply(403, 'Invalid nonce');
        }

        // PayGate's RSA signature over the exact URL, before any payment value is trusted.
        $check = $this->signature->verify($this->request, (string) $order->getPayment()->getAdditionalInformation('paygate_callback_url'));

        if (!$check['verified']) {
            $this->orderPayment->addNote($order, (string) __('[Security] Rejected a payment callback whose PayGate signature did not verify (%1). No order status was changed.', $check['reason']));

            return $this->reply(403, 'Invalid callback signature');
        }

        $valueCoin = $this->param($params, 'value_coin');
        $coin = strtolower($this->param($params, 'coin'));

        if (!preg_match('/^\d{1,20}(\.\d{1,18})?$/', $valueCoin) || (float) $valueCoin <= 0 || ($coin !== '' && !preg_match('/^[a-z0-9_.-]{1,64}$/', $coin))) {
            return $this->reply(400, 'Invalid payment data');
        }

        // One callback at a time per order: a retry arriving while the first is still running waits.
        $lock = 'paygate_card_order_' . $order->getId();

        if (!$this->lockManager->lock($lock, 20)) {
            return $this->reply(409, 'Busy, please retry');
        }

        try {
            $order = $this->orderFactory->create()->load($order->getId());

            return $this->process($order, $params, $valueCoin, $coin);
        } finally {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * The order of this callback: null when no card order has this number, false when the nonce
     * matches none of them. Order numbers are only unique per store, so every match is checked.
     *
     * @param string $incrementId
     * @param string $nonce
     * @return Order|null|false
     */
    private function findOrder($incrementId, $nonce)
    {
        $orders = $this->orderCollectionFactory->create()->addFieldToFilter('increment_id', $incrementId);
        $found = null;

        foreach ($orders as $order) {
            $payment = $order->getPayment();

            if (!$payment || !Config::isOwnMethod($payment->getMethod())) {
                continue;
            }

            $storedNonce = (string) $payment->getAdditionalInformation('paygate_nonce');

            if ($nonce !== '' && $storedNonce !== '' && hash_equals($storedNonce, $nonce)) {
                return $order;
            }

            $found = false;
        }

        return $found;
    }

    /**
     * @param Order $order reloaded under the order lock
     * @param array $params
     * @param string $valueCoin
     * @param string $coin
     * @return Raw
     */
    private function process(Order $order, array $params, $valueCoin, $coin)
    {
        $payment = $order->getPayment();
        $txidOut = $this->txid($this->param($params, 'txid_out'));
        $txidIn = $this->txid($this->param($params, 'txid_in'));
        $txid = $txidOut !== '' ? $txidOut : $txidIn;
        $txKey = $txidIn !== '' ? $txidIn : $txidOut;
        $seen = array_values(array_filter(explode(',', (string) $payment->getAdditionalInformation('paygate_seen_txids'))));
        $isNew = $txKey !== '' && !in_array($txKey, $seen, true);

        if ($txKey !== '') {
            $seen[] = $txKey;
            $payment->setAdditionalInformation('paygate_seen_txids', implode(',', array_slice(array_unique($seen), -20)));
        }

        // Already settled: a repeated callback changes nothing; a new payment is noted for review.
        if ($payment->getAdditionalInformation('paygate_paid')
            || in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true)
        ) {
            if ($isNew) {
                $this->orderPayment->addNote($order, (string) __('[Notice] Another payment of %1 %2 arrived after this order was paid. TXID: %3. Please review; a refund may be due.', $valueCoin, strtoupper($coin !== '' ? $coin : 'usdc'), $txid));
            }

            return $this->reply(200, 'ok');
        }

        if ($txKey !== '' && !$isNew) {
            return $this->reply(200, 'ok');
        }

        // Value of what arrived, in USD.
        $receivedUsd = (float) $valueCoin;

        if (in_array($coin, self::PRICED_COINS, true)) {
            $apiHost = Config::normaliseHost((string) $payment->getAdditionalInformation('paygate_api_host'), Config::DEFAULT_API_DOMAIN);
            $info = $this->api->get($apiHost, 'crypto/' . str_replace('_', '/', $coin) . '/info.php');

            if (!isset($info['prices']['USD']) || !is_numeric($info['prices']['USD'])) {
                return $this->reply(502, 'Could not value the payment, please retry');
            }

            $receivedUsd = (float) $valueCoin * (float) $info['prices']['USD'];
        }

        $expectedUsd = (float) $payment->getAdditionalInformation('paygate_expected_usd');
        $threshold = (float) $payment->getAdditionalInformation('paygate_tolerance') * $expectedUsd;
        $received = $valueCoin . ' ' . strtoupper($coin !== '' ? $coin : 'usdc') . ' (~' . number_format($receivedUsd, 2, '.', '') . ' USD)';

        $payment->setAdditionalInformation('paygate_received', $received);
        $payment->setAdditionalInformation('paygate_txid_in', $txidIn);
        $payment->setAdditionalInformation('paygate_txid_out', $txidOut);

        if ($order->getState() === Order::STATE_CANCELED) {
            $this->orderPayment->addNote($order, (string) __('[Attention] PayGate confirmed a payment of %1 for this cancelled order. TXID: %2. Please review the order.', $received, $txid));

            return $this->reply(200, 'ok');
        }

        if ($receivedUsd < $threshold) {
            $this->orderPayment->holdForReview($order, (string) __('[Payment below tolerance] Received %1 USD, which is less than the tolerance percentage of the order total (%2 USD). The customer may have changed the payment values on the checkout page. The order is on hold for your review. TXID: %3', number_format($receivedUsd, 2, '.', ''), number_format($expectedUsd, 2, '.', ''), $txid));

            return $this->reply(200, 'ok');
        }

        $payment->setAdditionalInformation('paygate_paid', 1);

        $this->orderPayment->markPaid($order, $txid, (string) __('Payment completed by the provider. Received %1 USD. TXID: %2', number_format($receivedUsd, 2, '.', ''), $txid));

        return $this->reply(200, 'ok');
    }

    /**
     * @param array $params
     * @param string $key
     * @return string
     */
    private function param(array $params, $key)
    {
        return isset($params[$key]) && is_string($params[$key]) ? trim($params[$key]) : '';
    }

    /**
     * A transaction id that is safe to store and show, or ''.
     *
     * @param string $txid
     * @return string
     */
    private function txid($txid)
    {
        return preg_match('#^[A-Za-z0-9:._+/=-]{1,255}$#', $txid) ? $txid : '';
    }

    /**
     * @param int $code
     * @param string $text
     * @return Raw
     */
    private function reply($code, $text)
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($code);
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setContents($text);

        return $result;
    }
}
