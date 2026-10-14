<?php
namespace PayGate\InstantPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PayGate\InstantPayment\Model\Config;

class Tolerance implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];

        foreach (Config::TOLERANCES as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
