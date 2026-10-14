<?php
/**
 * Hosted checkout color: a HEX code (#1d4ed8) or a CSS color name (green). Empty keeps the default.
 */
namespace PayGate\InstantPayment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class Color extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $color = trim((string) $this->getValue());
        $this->setValue($color);

        if ($color !== '' && !preg_match('/^(#[0-9A-Fa-f]{3,8}|[A-Za-z]{3,20})$/', $color)) {
            throw new LocalizedException(__('Colors: enter a HEX color such as #1d4ed8 or a CSS color name such as green.'));
        }

        return parent::beforeSave();
    }
}
