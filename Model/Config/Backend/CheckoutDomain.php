<?php
namespace PayGate\InstantPayment\Model\Config\Backend;

use PayGate\InstantPayment\Model\Config;

class CheckoutDomain extends Domain
{
    /**
     * @return string
     */
    protected function defaultHost()
    {
        return Config::DEFAULT_CHECKOUT_DOMAIN;
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    protected function errorMessage()
    {
        return __('Custom Domain: enter a domain name only, for example checkout.yourdomain.com');
    }
}
