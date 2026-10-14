<?php
/**
 * Domain setting: saved as a bare host name (scheme and path removed). Empty means the default.
 */
namespace PayGate\InstantPayment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use PayGate\InstantPayment\Model\Config;

abstract class Domain extends Value
{
    /**
     * @return string
     */
    abstract protected function defaultHost();

    /**
     * @return \Magento\Framework\Phrase
     */
    abstract protected function errorMessage();

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        if ($value === '') {
            $this->setValue($this->defaultHost());

            return parent::beforeSave();
        }

        $host = Config::normaliseHost($value, '');

        if ($host === '') {
            throw new LocalizedException($this->errorMessage());
        }

        $this->setValue($host);

        return parent::beforeSave();
    }
}
