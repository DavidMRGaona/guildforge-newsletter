<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\Services;

use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;

/**
 * Builds a provider for credentials that may not be saved yet (the settings page tests what is
 * typed). Everything else asks for NewsletterProviderInterface, which uses the saved settings.
 */
interface NewsletterProviderFactoryInterface
{
    /**
     * A malformed list id counts as no list.
     */
    public function mailchimp(MailchimpApiKey $apiKey, ?string $listId): NewsletterProviderInterface;
}
