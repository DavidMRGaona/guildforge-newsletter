<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

/**
 * Whether the newsletter can be offered: the module is enabled, a key and a list are saved and
 * the last connection check went well. Every surface (form, block, profile, registration) asks
 * here and hides itself otherwise.
 *
 * It never calls the provider and never throws: anything unreadable means not available.
 */
interface NewsletterAvailabilityInterface
{
    public function isAvailable(): bool;
}
