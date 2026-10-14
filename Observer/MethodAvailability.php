<?php
/**
 * Hide the card payment methods until a valid payout wallet is set (and, for the single provider
 * method, a provider tag), so customers never pick a method that cannot work.
 */
namespace PayGate\InstantPayment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PayGate\InstantPayment\Model\Config;

class MethodAvailability implements ObserverInterface
{
    /**
     * @var Config
     */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $method = $observer->getEvent()->getData('method_instance');
        $result = $observer->getEvent()->getData('result');

        if (!$method || !$result || !Config::isOwnMethod($method->getCode()) || !$result->getData('is_available')) {
            return;
        }

        $quote = $observer->getEvent()->getData('quote');
        $storeId = $quote ? (int) $quote->getStoreId() : null;
        $available = $this->config->getWallet($storeId) !== '';

        if ($available && $method->getCode() === Config::METHOD_PROVIDER) {
            $available = (bool) preg_match('/^[a-z0-9_-]{1,64}$/i', $this->config->getMethodValue(Config::METHOD_PROVIDER, 'provider', $storeId));
        }

        if (!$available) {
            $result->setData('is_available', false);
        }
    }
}
