<?php
/**
 * Provider tag (id from control/provider-status), e.g. "moonpay".
 */
namespace PayGate\InstantPayment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class ProviderTag extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $tag = strtolower(trim((string) $this->getValue()));
        $this->setValue($tag);

        if ($tag !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/', $tag)) {
            throw new LocalizedException(__('Provider Tag: enter a provider id from the provider tag list, for example moonpay.'));
        }

        return parent::beforeSave();
    }
}
