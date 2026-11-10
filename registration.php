<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_AiAssistant: a chat with Claude in the admin that can also act on
 * the catalog, CMS content and orders, within the admin user's own role.
 * See README.md.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_AiAssistant', __DIR__);
