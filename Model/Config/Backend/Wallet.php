<?php
/**
 * Payout wallet setting: a USDC (Polygon) address, never the USDC token contract.
 */
namespace PayGate\InstantPayment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use PayGate\InstantPayment\Model\Config;

class Wallet extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $wallet = trim((string) $this->getValue());
        $this->setValue($wallet);

        if ($wallet !== '' && !Config::isWallet($wallet)) {
            throw new LocalizedException(__('Invalid Wallet Address: please insert your USDC (Polygon) wallet address. It starts with 0x followed by 40 characters and is not the USDC token contract.'));
        }

        return parent::beforeSave();
    }
}
