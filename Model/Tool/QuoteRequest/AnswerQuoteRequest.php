<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\QuoteRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\QuoteRequest\Model\Config;
use Zero\QuoteRequest\Model\Mailer;
use Zero\QuoteRequest\Model\Requests;

/**
 * answer_quote_request: the store's answer, as the admin's request page
 * sends it: a price per line and a note (the customer is emailed, the
 * quote is valid for valid_days), or a decline with a note.
 */
class AnswerQuoteRequest implements ToolInterface
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
    private function config(): Config
    {
        return $this->objectManager->get(Config::class);
    }

    public function name(): string
    {
        return 'answer_quote_request';
    }

    public function description(): string
    {
        return 'Answer a quote request: prices is an object of item_id => unit price in the request\'s currency (lines without a price keep none) plus a note to the customer; the customer is emailed and can order at those prices for valid_days. Or decline: true with a note. Shown to the admin to confirm first. Not for a request already ordered.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'prices' => ['type' => 'object', 'additionalProperties' => ['type' => 'number'], 'description' => 'item_id => unit price'],
            'answer' => ['type' => 'string', 'description' => 'Note to the customer'],
            'valid_days' => ['type' => 'integer'],
            'decline' => ['type' => 'boolean'],
        ], 'required' => ['id']];
    }

    public function resource(): string
    {
        return 'Zero_QuoteRequest::requests';
    }

    public function area(): string
    {
        return 'quotes';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        [$request, $items, $prices, $decline] = $this->parse($input);
        $lines = ['Customer' => $request['name'] . ' <' . $request['email'] . '>' . ($request['company'] ? ', ' . $request['company'] : '')];
        if ($decline) {
            $lines['Answer'] = mb_substr(trim((string) ($input['answer'] ?? '')), 0, 300) ?: '(none)';

            return ['title' => (string) __('Decline quote request #%1', $request['request_id']), 'lines' => $lines];
        }
        $total = 0.0;
        foreach ($items as $item) {
            if (isset($prices[(int) $item['item_id']])) {
                $price = $prices[(int) $item['item_id']];
                $total += $price * (float) $item['qty'];
                $lines[$item['sku'] . ' × ' . (float) $item['qty']] = number_format((float) $item['price'], 2) . ' → ' . number_format($price, 2) . ' ' . $request['currency'];
            }
        }
        $lines['Total'] = number_format($total, 2) . ' ' . $request['currency'];
        $lines['Valid for'] = $this->days($input, $request) . ' days';
        $lines['Note'] = mb_substr(trim((string) ($input['answer'] ?? '')), 0, 300) ?: '(none)';

        return ['title' => (string) __('Quote request #%1: send prices', $request['request_id']), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        [$request, , $prices, $decline] = $this->parse($input);
        $id = (int) $request['request_id'];
        $answer = trim((string) ($input['answer'] ?? ''));
        if ($decline) {
            $this->requests()->decline($id, $answer);

            return ['id' => $id, 'status' => Requests::DECLINED];
        }
        $days = $this->days($input, $request);
        $this->requests()->quote($id, $prices, $answer, gmdate('Y-m-d 23:59:59', time() + $days * 86400));
        $this->mailer()->quoted($this->requests()->get($id));

        return ['id' => $id, 'status' => Requests::QUOTED, 'emailed' => $request['email'], 'valid_days' => $days];
    }

    private function days(array $input, array $request): int
    {
        return (int) ($input['valid_days'] ?? 0) ?: $this->config()->validDays((int) $request['store_id']);
    }

    /**
     * @return array{0: array, 1: array, 2: array<int, float>, 3: bool}
     * @throws LocalizedException
     */
    private function parse(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $request = $this->requests()->get($id);
        if (!$request) {
            throw new LocalizedException(__('No quote request #%1.', $id));
        }
        if ($request['status'] === Requests::ORDERED) {
            throw new LocalizedException(__('Quote request #%1 is already ordered.', $id));
        }
        $items = $this->requests()->items($id);
        $decline = !empty($input['decline']);
        $prices = [];
        if (!$decline) {
            $known = array_map(static fn (array $i) => (int) $i['item_id'], $items);
            foreach ((array) ($input['prices'] ?? []) as $itemId => $price) {
                if (!in_array((int) $itemId, $known, true)) {
                    throw new LocalizedException(__('Line %1 is not in request #%2.', $itemId, $id));
                }
                if (!is_numeric($price) || (float) $price < 0) {
                    throw new LocalizedException(__('The price for line %1 is not a number.', $itemId));
                }
                $prices[(int) $itemId] = (float) $price;
            }
            if (!$prices) {
                throw new LocalizedException(__('Give a price for at least one line, or decline.'));
            }
        }

        return [$request, $items, $prices, $decline];
    }
}
