<?php
/**
 * Order status "Awaiting card payment" (state: Pending Payment).
 *
 * A status of its own keeps these orders out of Magento's generic "Pending Payment Order Lifetime"
 * cleanup (8 hours by default), which would cancel orders whose card payment is still being
 * verified by the provider. Unpaid orders are cancelled by this module's own cron instead, after
 * the "Cancel Unpaid Orders After" setting. Customers can see these orders in their account.
 */
namespace PayGate\InstantPayment\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;
use PayGate\InstantPayment\Model\Config;

class AddPendingStatus implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $setup;

    public function __construct(ModuleDataSetupInterface $setup)
    {
        $this->setup = $setup;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->setup->getConnection();

        $connection->insertOnDuplicate(
            $this->setup->getTable('sales_order_status'),
            ['status' => Config::PENDING_STATUS, 'label' => 'Awaiting card payment'],
            ['label']
        );

        $connection->insertOnDuplicate(
            $this->setup->getTable('sales_order_status_state'),
            ['status' => Config::PENDING_STATUS, 'state' => Order::STATE_PENDING_PAYMENT, 'is_default' => 0, 'visible_on_front' => 1],
            ['visible_on_front']
        );

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases()
    {
        return [];
    }
}
