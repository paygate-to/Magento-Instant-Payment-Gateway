<?php
namespace PayGate\InstantPayment\Model\Config\Backend;

use PayGate\InstantPayment\Model\Config;

class ApiDomain extends Domain
{
    /**
     * @return string
     */
    protected function defaultHost()
    {
        return Config::DEFAULT_API_DOMAIN;
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    protected function errorMessage()
    {
        return __('Custom API Domain: enter a domain name only, for example api-yourshop-1a2b3c4d5e6f7a8b9c0d1e2f.paygate.to');
    }
}
