<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Checkbox;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\Checkbox\Model\Api;
use Zero\Checkbox\Model\Config;
use Zero\Checkbox\Model\Receipts;

/**
 * cash_register: the Checkbox cash register of each store view that has
 * one: test or real, the shift, the receipts not done yet.
 */
class CashRegister extends ReadTool
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function config(): Config
    {
        return $this->objectManager->get(Config::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function api(): Api
    {
        return $this->objectManager->get(Api::class);
    }

    public function name(): string
    {
        return 'cash_register';
    }

    public function description(): string
    {
        return 'The Checkbox cash register (ПРРО) of each store view that has one: enabled, its fiscal number, test (receipts don\'t reach the tax service) or real, whether the shift is open, and how many receipts wait or failed. The shift opens with the first receipt and closes at night by itself.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function resource(): string
    {
        return 'Magento_Sales::sales_order';
    }

    public function area(): string
    {
        return 'sales';
    }

    public function run(array $input, Context $context): array
    {
        $registers = [];
        $seen = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $credentials = $this->config()->credentials($storeId);
            if ($credentials === null || isset($seen[$credentials->licenseKey])) {
                continue;
            }
            $seen[$credentials->licenseKey] = true;
            $row = ['store' => $store->getName(), 'enabled' => $this->config()->isActive($storeId)];
            try {
                $register = $this->api()->cashRegister($credentials);
                $shift = $this->api()->currentShift($credentials);
                $row += [
                    'fiscal_number' => $register['fiscal_number'] ?? null,
                    'test' => !empty($register['is_test']),
                    'shift' => $shift ? ['status' => $shift['status'] ?? '', 'opened_at' => $shift['opened_at'] ?? null] : 'closed',
                ];
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
            }
            $registers[] = $row;
        }
        $connection = $this->resource->getConnection();
        $counts = $connection->fetchPairs($connection->select()
            ->from($this->resource->getTableName('zero_checkbox_receipt'), ['status', 'COUNT(*)'])
            ->where('status <> ?', Receipts::DONE)
            ->group('status'));

        return ['registers' => $registers, 'receipts_not_done' => array_map('intval', $counts), 'note' => $registers ? null : 'No store view has a Checkbox key and PIN.'];
    }
}
