<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\System;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * The store settings the assistant may read and change: a fixed list of
 * plain, basic ones (store information, sender emails, contact, shipping
 * origin, locale, SEO defaults), never keys, payment or server settings. A
 * value is checked by its kind before it is saved.
 */
class Settings
{
    /**
     * The admin resource of each configuration section the paths are in, as Stores > Configuration checks it:
     * a role may change only the sections it may open there.
     */
    public const SECTION_RESOURCES = [
        'general' => 'Magento_Config::config_general',
        'trans_email' => 'Magento_Config::trans_email',
        'design' => 'Magento_Config::config_design',
        'contact' => 'Magento_Contact::contact',
        'shipping' => 'Magento_Shipping::config_shipping',
    ];

    /** path => [label, kind, group] */
    public const PATHS = [
        'general/store_information/name' => ['Store name', 'text', 'Store information'],
        'general/store_information/phone' => ['Store phone', 'phone', 'Store information'],
        'general/store_information/hours' => ['Store hours', 'text', 'Store information'],
        'general/store_information/country_id' => ['Country', 'country', 'Store information'],
        'general/store_information/region_id' => ['Region id', 'region', 'Store information'],
        'general/store_information/postcode' => ['Postcode', 'text', 'Store information'],
        'general/store_information/city' => ['City', 'text', 'Store information'],
        'general/store_information/street_line1' => ['Street address', 'text', 'Store information'],
        'general/store_information/street_line2' => ['Street address line 2', 'text', 'Store information'],
        'general/store_information/merchant_vat_number' => ['VAT number', 'text', 'Store information'],
        'general/locale/timezone' => ['Timezone', 'timezone', 'Locale'],
        'general/locale/code' => ['Locale', 'locale', 'Locale'],
        'general/country/default' => ['Default country', 'country', 'Locale'],
        'trans_email/ident_general/name' => ['General contact: name', 'text', 'Store email addresses'],
        'trans_email/ident_general/email' => ['General contact: email', 'email', 'Store email addresses'],
        'trans_email/ident_sales/name' => ['Sales representative: name', 'text', 'Store email addresses'],
        'trans_email/ident_sales/email' => ['Sales representative: email', 'email', 'Store email addresses'],
        'trans_email/ident_support/name' => ['Customer support: name', 'text', 'Store email addresses'],
        'trans_email/ident_support/email' => ['Customer support: email', 'email', 'Store email addresses'],
        'trans_email/ident_custom1/name' => ['Custom email 1: name', 'text', 'Store email addresses'],
        'trans_email/ident_custom1/email' => ['Custom email 1: email', 'email', 'Store email addresses'],
        'trans_email/ident_custom2/name' => ['Custom email 2: name', 'text', 'Store email addresses'],
        'trans_email/ident_custom2/email' => ['Custom email 2: email', 'email', 'Store email addresses'],
        'contact/contact/enabled' => ['Contact form enabled', 'bool', 'Contact'],
        'contact/email/recipient_email' => ['Contact form goes to', 'email', 'Contact'],
        'shipping/origin/country_id' => ['Country', 'country', 'Shipping origin'],
        'shipping/origin/region_id' => ['Region id', 'region', 'Shipping origin'],
        'shipping/origin/postcode' => ['Postcode', 'text', 'Shipping origin'],
        'shipping/origin/city' => ['City', 'text', 'Shipping origin'],
        'shipping/origin/street_line1' => ['Street address', 'text', 'Shipping origin'],
        'shipping/origin/street_line2' => ['Street address line 2', 'text', 'Shipping origin'],
        'design/head/default_title' => ['Default page title', 'text', 'SEO defaults'],
        'design/head/default_description' => ['Default meta description', 'textarea', 'SEO defaults'],
        'design/head/default_keywords' => ['Default meta keywords', 'textarea', 'SEO defaults'],
    ];

    /** The settings a store needs before it opens: get_settings marks these when empty. */
    public const REQUIRED = [
        'general/store_information/name', 'general/store_information/phone', 'general/store_information/country_id',
        'general/locale/timezone', 'trans_email/ident_general/name', 'trans_email/ident_general/email',
        'trans_email/ident_sales/email', 'trans_email/ident_support/email', 'contact/email/recipient_email',
        'shipping/origin/country_id', 'shipping/origin/postcode', 'shipping/origin/city', 'design/head/default_title',
    ];

    /**
     * The value as it will be stored, or what is wrong with it.
     *
     * @throws LocalizedException
     */
    /**
     * Whether the role may read and change the path's section.
     */
    public static function mayUse(string $path, AuthorizationInterface $authorization): bool
    {
        $resource = self::SECTION_RESOURCES[strtok($path, '/')] ?? null;

        return $resource !== null && $authorization->isAllowed($resource);
    }

    public static function clean(string $path, mixed $value): string
    {
        if (!isset(self::PATHS[$path])) {
            throw new LocalizedException(__('%1 is not a setting the assistant may change.', $path));
        }
        [$label, $kind] = self::PATHS[$path];
        if ($kind === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $ok = match ($kind) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => (bool) preg_match('/^\+?[0-9 ()\-]{5,30}$/', $value),
            'country' => (bool) preg_match('/^[A-Z]{2}$/', $value = strtoupper($value)),
            'region' => (bool) preg_match('/^\d{1,6}$/', $value),
            'timezone' => in_array($value, \DateTimeZone::listIdentifiers(), true),
            'locale' => (bool) preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $value),
            'textarea' => mb_strlen($value) <= 2000,
            default => mb_strlen($value) <= 255,
        };
        if (!$ok) {
            throw new LocalizedException(__('"%1" is not a valid %2 (%3).', $value, $label, $kind));
        }

        return $value;
    }
}
