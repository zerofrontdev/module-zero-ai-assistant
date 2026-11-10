<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\QuoteRequest;

use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\QuoteRequest\Model\Requests;

/**
 * list_quote_requests: B2B price requests, the unanswered ones by default.
 */
class ListQuoteRequests extends ReadTool
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function requests(): Requests
    {
        return $this->objectManager->get(Requests::class);
    }

    public function name(): string
    {
        return 'list_quote_requests';
    }

    public function description(): string
    {
        return 'Quote requests (customers asking for prices): status new (unanswered, the default), quoted, ordered, declined or all; search by email, name or company. Returns id, status, customer, company, lines, asked and quoted totals, currency, created_at. Use get_quote_request for the lines.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['new', 'quoted', 'ordered', 'declined', 'all'], 'default' => 'new'],
            'search' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'default' => 20],
        ]];
    }

    public function resource(): string
    {
        return 'Zero_QuoteRequest::requests';
    }

    public function area(): string
    {
        return 'quotes';
    }

    public function run(array $input, Context $context): array
    {
        $status = (string) ($input['status'] ?? 'new');
        $rows = $this->requests()->all($status === 'all' ? '' : $status, trim((string) ($input['search'] ?? '')), null, max(1, min(50, (int) ($input['limit'] ?? 20))));

        return ['requests' => array_map(static fn (array $r) => [
            'id' => (int) $r['request_id'],
            'status' => $r['status'],
            'name' => $r['name'],
            'email' => $r['email'],
            'company' => $r['company'],
            'phone' => $r['phone'],
            'comment' => mb_substr((string) $r['comment'], 0, 300),
            'lines' => (int) $r['lines'],
            'asked_total' => (float) $r['asked_total'],
            'quoted_total' => $r['quoted_total'] !== null ? (float) $r['quoted_total'] : null,
            'currency' => $r['currency'],
            'expires_at' => $r['expires_at'],
            'order' => $r['order_increment_id'],
            'created_at' => $r['created_at'],
        ], $rows)];
    }
}
