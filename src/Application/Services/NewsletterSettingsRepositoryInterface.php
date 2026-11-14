<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use Modules\Newsletter\Application\DTOs\NewsletterSettings;

interface NewsletterSettingsRepositoryInterface
{
    /**
     * What is saved; missing or unreadable values come back as null (a key that cannot be
     * decrypted any more included).
     */
    public function get(): NewsletterSettings;

    /**
     * Replace every saved value (the key is encrypted).
     */
    public function save(NewsletterSettings $settings): void;
}
