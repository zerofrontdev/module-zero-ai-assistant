<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Returns;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Returns\Model\Mailer;
use Zero\Returns\Model\Returns;
use Zero\Returns\ViewModel\Admin;

/**
 * update_return: a new status and/or a message to the customer, as the
 * admin's return page does it (the customer is emailed).
 */
class UpdateReturn implements ToolInterface
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function returns(): Returns
    {
        return $this->objectManager->get(Returns::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function mailer(): Mailer
    {
        return $this->objectManager->get(Mailer::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function admin(): Admin
    {
        return $this->objectManager->get(Admin::class);
    }

    public function name(): string
    {
        return 'update_return';
    }

    public function description(): string
    {
        return 'Move a return to its next status (get_return lists next_statuses: approved, rejected, received, refunded, closed) and/or send the customer a message; the customer is emailed either way. Shown to the admin to confirm first. A refund itself is done on the order (credit memo), "refunded" records it.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => Returns::STATUSES],
            'message' => ['type' => 'string', 'description' => 'To the customer'],
        ], 'required' => ['id']];
    }

    public function resource(): string
    {
        return 'Zero_Returns::returns';
    }

    public function area(): string
    {
        return 'returns';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        [$return, $status, $message] = $this->parse($input);
        $lines = ['Customer' => $return['customer_name'] . ' <' . $return['customer_email'] . '>', 'Order' => (string) $return['order_increment_id']];
        if ($status !== '') {
            $lines['Status'] = $return['status'] . ' → ' . $status;
        }
        if ($message !== '') {
            $lines['Message'] = mb_substr($message, 0, 300);
        }

        return ['title' => (string) __('Return %1: %2', Returns::number($return), $status !== '' ? $this->admin()->statusLabel($status) : __('message to the customer')), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        [$return, $status, $message] = $this->parse($input);
        $id = (int) $return['return_id'];
        $this->returns()->comment($id, $message, true, $status !== '' ? $status : null);
        $this->mailer()->updated($this->returns()->get($id), $message);

        return ['id' => $id, 'number' => Returns::number($return), 'status' => $status !== '' ? $status : $return['status'], 'emailed' => $return['customer_email']];
    }

    /**
     * @return array{0: array, 1: string, 2: string}
     * @throws LocalizedException
     */
    private function parse(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $return = $this->returns()->get($id);
        if (!$return) {
            throw new LocalizedException(__('No return #%1.', $id));
        }
        $status = trim((string) ($input['status'] ?? ''));
        $message = mb_substr(trim((string) ($input['message'] ?? '')), 0, 4000);
        if ($status !== '' && !isset($this->admin()->nextStatuses($return)[$status])) {
            throw new LocalizedException(__('A return can\'t go from %1 to %2; it can go to: %3.', $return['status'], $status, implode(', ', array_keys($this->admin()->nextStatuses($return))) ?: 'nothing'));
        }
        if ($status === '' && $message === '') {
            throw new LocalizedException(__('Give a new status or a message.'));
        }

        return [$return, $status, $message];
    }
}
