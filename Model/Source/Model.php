<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Model implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-haiku-4-5-20251001', 'label' => __('Claude Haiku 4.5 (fastest, cheapest; recommended)')],
            ['value' => 'claude-sonnet-5-5', 'label' => __('Claude Sonnet 5.5 (more capable, dearer)')],
            ['value' => 'claude-opus-5-5', 'label' => __('Claude Opus 5.5 (most capable, slowest and dearest)')],
        ];
    }
}
