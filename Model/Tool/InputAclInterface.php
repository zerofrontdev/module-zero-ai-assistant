<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool;

use Magento\Framework\Exception\LocalizedException;

/**
 * A tool whose admin resource depends on the records it is given, as the
 * stock grid's own check does (moderate_reviews: All Reviews unless every
 * review is pending). resource() stays what makes the tool visible;
 * Tools::authorize() checks this one before a preview or a run.
 */
interface InputAclInterface
{
    /**
     * The admin resource this call needs for these records.
     *
     * @throws LocalizedException when the input names records that don't exist
     */
    public function resourceFor(array $input): string;
}
