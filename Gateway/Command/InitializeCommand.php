<?php
/**
 * Creates the PayGate payment while the order is being placed.
 *
 * 1. Order total in USD (control/convert.php), kept as the reference amount for the callback check.
 * 2. A unique encrypted pay-in address bound to this order's callback URL (control/wallet.php).
 * 3. The hosted checkout URL the customer is sent to right after "Place Order".
 *
 * Any failure throws, so the order is not placed and the customer stays on the checkout with a
 * clear message. The order is created as "Pending Payment" and its confirmation e-mail is only
 * sent once the payment is confirmed.
 */
namespace PayGate\InstantPayment\Gateway\Command;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PayGate\InstantPayment\Model\Api;
use PayGate\InstantPayment\Model\Config;
use Psr\Log\LoggerInterface;

class InitializeCommand implements CommandInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var Url
     */
    private $urlBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Config $config, Api $api, Url $urlBuilder, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->api = $api;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    /**
     * @param array $commandSubject
     * @return null
     * @throws LocalizedException
     */
    public function execute(array $commandSubject)
    {
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        /** @var Order $order */
        $order = $payment->getOrder();
        $method = (string) $payment->getMethod();
        $storeId = (int) $order->getStoreId();

        $wallet = $this->config->getWallet($storeId);

        if ($wallet === '') {
            $this->logger->error('Order ' . $order->getIncrementId() . ': no valid USDC (Polygon) payout wallet is configured.');
            throw new LocalizedException(__('This payment method is not available right now. Please choose another payment method or contact us.'));
        }

        $apiHost = $this->config->getApiDomain($storeId);
        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        $total = round((float) $order->getGrandTotal(), 2);

        if ($total <= 0) {
            throw new LocalizedException(__('The order total must be greater than zero to pay by card.'));
        }

        // 1) Reference total in USD for the amount check when PayGate confirms the payment.
        if ($currency === 'USD') {
            $referenceUsd = $total;
        } else {
            $convert = $this->api->get($apiHost, 'control/convert.php', [
                'value' => $this->amount($total),
                'from' => strtolower($currency),
            ]);

            if (!isset($convert['value_coin']) || !is_numeric($convert['value_coin']) || (float) $convert['value_coin'] <= 0) {
                $this->logger->error('Order ' . $order->getIncrementId() . ': currency conversion failed for ' . $currency . ' on ' . $apiHost . '.');
                throw new LocalizedException(__('Payment could not be processed due to a failed currency conversion, please try again.'));
            }

            $referenceUsd = (float) $convert['value_coin'];
        }

        if ($method === Config::METHOD_PROVIDER) {
            $minimum = (float) $this->config->getMethodValue($method, 'minimum_amount', $storeId);

            if ($minimum > 0 && $referenceUsd < $minimum) {
                throw new LocalizedException(__('Payment error: the order total for this payment method must be at least $%1 USD.', $this->amount($minimum)));
            }
        }

        // 2) Unique pay-in address. The nonce makes the callback URL unique and unguessable.
        $nonce = bin2hex(random_bytes(16));
        $callbackUrl = $this->urlBuilder->setScope($storeId)->getUrl('paygate/callback/index', [
            '_secure' => true,
            '_nosid' => true,
            '_query' => ['order' => (string) $order->getIncrementId(), 'nonce' => $nonce],
        ]);

        $wallet = $this->api->get($apiHost, 'control/wallet.php', ['address' => $wallet, 'callback' => $callbackUrl]);
        $addressIn = isset($wallet['address_in']) && is_string($wallet['address_in']) ? $wallet['address_in'] : '';

        // address_in is returned URL-encoded and is passed on exactly as received.
        if (!preg_match('#^[A-Za-z0-9%._~+/=-]{8,1024}$#', $addressIn)) {
            $this->logger->error('Order ' . $order->getIncrementId() . ': control/wallet.php gave no usable address on ' . $apiHost . '.');
            throw new LocalizedException(__('Payment could not be processed, please try again (wallet address error).'));
        }

        $polygonAddress = isset($wallet['polygon_address_in']) && is_string($wallet['polygon_address_in']) ? $wallet['polygon_address_in'] : '';
        $ipnToken = isset($wallet['ipn_token']) && is_string($wallet['ipn_token']) ? $wallet['ipn_token'] : '';

        // 3) Hosted checkout URL.
        $redirectUrl = $this->checkoutUrl($method, $storeId, $addressIn, $total, (string) $order->getCustomerEmail(), $currency);

        $payment->setAdditionalInformation('paygate_nonce', $nonce);
        $payment->setAdditionalInformation('paygate_callback_url', $callbackUrl);
        $payment->setAdditionalInformation('paygate_api_host', $apiHost);
        $payment->setAdditionalInformation('paygate_address_in', $addressIn);
        $payment->setAdditionalInformation('paygate_polygon_address', $polygonAddress);
        $payment->setAdditionalInformation('paygate_ipn_token', $ipnToken);
        $payment->setAdditionalInformation('paygate_amount', $this->amount($total) . ' ' . $currency);
        $payment->setAdditionalInformation('paygate_expected_usd', $this->amount($referenceUsd));
        $payment->setAdditionalInformation('paygate_tolerance', $this->config->getTolerance($storeId));
        $payment->setAdditionalInformation('paygate_redirect_url', $redirectUrl);

        /** @var DataObject $stateObject */
        $stateObject = SubjectReader::readStateObject($commandSubject);
        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', $this->pendingStatus($method, $storeId, $order));
        $stateObject->setData('is_notified', false);

        // The confirmation e-mail goes out once the payment is confirmed.
        $order->setCanSendNewEmailFlag(false);

        return null;
    }

    /**
     * @param string $method
     * @param int $storeId
     * @param string $addressIn
     * @param float $total
     * @param string $email
     * @param string $currency
     * @return string
     */
    private function checkoutUrl($method, $storeId, $addressIn, $total, $email, $currency)
    {
        $base = 'https://' . $this->config->getCheckoutDomain($storeId);

        if ($method === Config::METHOD_PROVIDER) {
            return $base . '/process-payment.php?address=' . $addressIn
                . '&amount=' . $this->amount($total)
                . '&provider=' . rawurlencode($this->config->getMethodValue($method, 'provider', $storeId))
                . '&email=' . rawurlencode($email)
                . '&currency=' . rawurlencode($currency);
        }

        $url = $base . '/pay.php?address=' . $addressIn
            . '&amount=' . $this->amount($total)
            . '&email=' . rawurlencode($email)
            . '&currency=' . rawurlencode($currency);

        foreach (['logo' => 'logo_url', 'background' => 'background_color', 'theme' => 'theme_color', 'button' => 'button_color'] as $param => $field) {
            $value = $this->config->getMethodValue($method, $field, $storeId);

            if ($value !== '') {
                $url .= '&' . $param . '=' . rawurlencode($value);
            }
        }

        return $url;
    }

    /**
     * The configured "pending" status if it belongs to the Pending Payment state.
     *
     * @param string $method
     * @param int $storeId
     * @param Order $order
     * @return string
     */
    private function pendingStatus($method, $storeId, Order $order)
    {
        $status = $this->config->getMethodValue($method, 'order_status', $storeId);
        $allowed = $order->getConfig()->getStateStatuses(Order::STATE_PENDING_PAYMENT);

        return ($status !== '' && isset($allowed[$status])) ? $status : Order::STATE_PENDING_PAYMENT;
    }

    /**
     * Amount as a plain decimal string, e.g. 103.78 or 25.5.
     *
     * @param float $value
     * @return string
     */
    private function amount($value)
    {
        $text = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }
}
