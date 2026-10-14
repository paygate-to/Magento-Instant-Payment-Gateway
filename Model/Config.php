<?php
/**
 * Settings of the PayGate.to card payment methods.
 */
namespace PayGate\InstantPayment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    const METHOD_HOSTED = 'paygate_hosted';
    const METHOD_PROVIDER = 'paygate_provider';

    /** Order status "Awaiting card payment" (state: Pending Payment), see Setup/Patch/Data/AddPendingStatus. */
    const PENDING_STATUS = 'paygate_pending';

    const DEFAULT_API_DOMAIN = 'api.paygate.to';
    const DEFAULT_CHECKOUT_DOMAIN = 'checkout.paygate.sbs';

    /** USDC contract on Polygon: a common mistake when pasting the payout wallet. */
    const USDC_POLYGON_CONTRACT = '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';

    /** Tolerance: the minimum share of the order total that must arrive (0 = amount check disabled). */
    const TOLERANCES = [
        '0.90' => '10%',
        '0.80' => '20%',
        '0.70' => '30%',
        '0.60' => '40%',
        '0.50' => '50%',
        '0.40' => '60%',
        '0.30' => '70%',
        '0.20' => '80%',
        '0.10' => '90%',
        '0' => 'Disabled amount detection',
    ];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param string $method
     * @return bool
     */
    public static function isOwnMethod($method)
    {
        return in_array((string) $method, [self::METHOD_HOSTED, self::METHOD_PROVIDER], true);
    }

    /**
     * @param string $path
     * @param int|null $storeId
     * @return string
     */
    private function value($path, $storeId)
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * @param string $method
     * @param string $field
     * @param int|null $storeId
     * @return string
     */
    public function getMethodValue($method, $field, $storeId = null)
    {
        return $this->value('payment/' . $method . '/' . $field, $storeId);
    }

    /**
     * Payout wallet, or '' when it is missing or not a Polygon address.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWallet($storeId = null)
    {
        $wallet = $this->value('payment/paygate_card/wallet', $storeId);

        return self::isWallet($wallet) ? $wallet : '';
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getApiDomain($storeId = null)
    {
        return self::normaliseHost($this->value('payment/paygate_card/api_domain', $storeId), self::DEFAULT_API_DOMAIN);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getCheckoutDomain($storeId = null)
    {
        return self::normaliseHost($this->value('payment/paygate_card/checkout_domain', $storeId), self::DEFAULT_CHECKOUT_DOMAIN);
    }

    /**
     * @param int|null $storeId
     * @return string one of the TOLERANCES keys
     */
    public function getTolerance($storeId = null)
    {
        $tolerance = $this->value('payment/paygate_card/tolerance', $storeId);

        return isset(self::TOLERANCES[$tolerance]) ? $tolerance : '0.60';
    }

    /**
     * A Polygon (EVM) wallet address that is not the USDC token contract.
     *
     * @param string $wallet
     * @return bool
     */
    public static function isWallet($wallet)
    {
        $wallet = (string) $wallet;

        return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet) && strtolower($wallet) !== self::USDC_POLYGON_CONTRACT;
    }

    /**
     * Normalise a domain setting to a bare host name: strips the scheme, any path, a trailing dot
     * and the case. Anything that is not a plain host name gives $default.
     *
     * @param string $value
     * @param string $default
     * @return string
     */
    public static function normaliseHost($value, $default)
    {
        $host = strtolower(trim((string) $value));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $parts = explode('/', $host, 2);
        $host = rtrim($parts[0], '.');

        if ($host === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return $default;
        }

        return $host;
    }
}
