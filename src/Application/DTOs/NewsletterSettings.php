<?php

declare(strict_types=1);

namespace Modules\Newsletter\Application\DTOs;

use Modules\Newsletter\Domain\Enums\NewsletterProviderName;
use Modules\Newsletter\Domain\ValueObjects\ConnectionCheck;
use Modules\Newsletter\Domain\ValueObjects\MailchimpApiKey;

/**
 * The newsletter settings saved in the panel. The key is decrypted here and only here: it is
 * stored encrypted and never sent back to the browser.
 */
final readonly class NewsletterSettings
{
    public function __construct(
        public NewsletterProviderName $provider = NewsletterProviderName::Mailchimp,
        public ?MailchimpApiKey $apiKey = null,
        public ?string $listId = null,
        public ?string $listName = null,
        public ?string $consentText = null,
        public ?string $privacyUrl = null,
        public ?ConnectionCheck $lastCheck = null,
    ) {}
}
