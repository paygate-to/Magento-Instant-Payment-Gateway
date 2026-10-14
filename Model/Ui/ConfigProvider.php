<?php
/**
 * Checkout data for the card payment methods: description and the redirect URL used after
 * "Place Order".
 */
namespace PayGate\InstantPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use PayGate\InstantPayment\Model\Config;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    public function __construct(Config $config, UrlInterface $urlBuilder)
    {
        $this->config = $config;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        $redirectUrl = $this->urlBuilder->getUrl('paygate/redirect/index', ['_secure' => true]);
        $payment = [];

        foreach ([Config::METHOD_HOSTED, Config::METHOD_PROVIDER] as $method) {
            $payment[$method] = [
                'description' => $this->config->getMethodValue($method, 'description'),
                'redirectUrl' => $redirectUrl,
            ];
        }

        return ['payment' => $payment];
    }
}
