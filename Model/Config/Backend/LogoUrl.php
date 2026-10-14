<?php
/**
 * Hosted checkout logo: a full https:// image URL. Empty keeps the default.
 */
namespace PayGate\InstantPayment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class LogoUrl extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $url = trim((string) $this->getValue());
        $this->setValue($url);

        if ($url !== '' && !preg_match('#^https://[^\s"\'<>]+$#i', $url)) {
            throw new LocalizedException(__('Custom Logo URL: enter a full https:// image URL.'));
        }

        return parent::beforeSave();
    }
}
