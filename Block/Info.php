<?php
/**
 * Payment details on the order, invoice and credit memo pages. Customers and e-mails only see the
 * payment method title; the admin also sees the PayGate payment details.
 */
namespace PayGate\InstantPayment\Block;

use Magento\Framework\App\Area;
use Magento\Framework\DataObject;
use Magento\Payment\Block\Info as BaseInfo;

class Info extends BaseInfo
{
    /**
     * @param DataObject|array|null $transport
     * @return DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);

        if ($this->getIsSecureMode() || $this->_appState->getAreaCode() !== Area::AREA_ADMINHTML) {
            return $transport;
        }

        $info = $this->getInfo();
        $rows = [
            'paygate_amount' => __('Amount charged'),
            'paygate_expected_usd' => __('Expected (USD)'),
            'paygate_received' => __('Received'),
            'paygate_polygon_address' => __('Order wallet (Polygon)'),
            'paygate_txid_in' => __('TXID in'),
            'paygate_txid_out' => __('TXID out (payout)'),
            'paygate_ipn_token' => __('IPN token (for PayGate support)'),
        ];
        $data = [];

        foreach ($rows as $key => $label) {
            $value = (string) $info->getAdditionalInformation($key);

            if ($value !== '') {
                $data[(string) $label] = $value;
            }
        }

        return $transport->addData($data);
    }
}
